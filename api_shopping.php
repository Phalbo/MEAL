<?php
function apiShoppingGenerate(PDO $pdo, array $in): never {
    $familyId  = $_SESSION['family_id'];
    $weekStart = $in['week_start'] ?? '';
    if (!$weekStart) respondError('week_start obbligatorio');

    // porzioni totali famiglia
    $ps = $pdo->prepare("SELECT portion_weight FROM family_profiles WHERE family_id=?");
    $ps->execute([$familyId]);
    $totalPortions = array_sum(array_column($ps->fetchAll(), 'portion_weight')) ?: 1.0;

    // quante volte ogni piatto appare nella settimana
    $cs = $pdo->prepare("SELECT meal_id, COUNT(*) as cnt FROM schedule
        WHERE family_id=? AND week_start=? AND meal_id IS NOT NULL GROUP BY meal_id");
    $cs->execute([$familyId, $weekStart]);
    $counts = [];
    foreach ($cs->fetchAll() as $r) $counts[(int)$r['meal_id']] = (int)$r['cnt'];

    if (!$counts) {
        $pdo->prepare("DELETE FROM shopping_items WHERE family_id=? AND week_start=?")
            ->execute([$familyId, $weekStart]);
        respond([]);
    }

    // ingredienti di tutti i piatti in calendario
    $ids  = array_keys($counts);
    $ph   = implode(',', array_fill(0, count($ids), '?'));
    $ings = $pdo->prepare("SELECT * FROM meal_ingredients WHERE meal_id IN ($ph)");
    $ings->execute($ids);

    // aggregazione: chiave = nome_lower|unit
    $agg = [];
    foreach ($ings->fetchAll() as $ing) {
        $factor = $totalPortions * ($counts[(int)$ing['meal_id']] ?? 1);
        $key    = mb_strtolower(trim($ing['name'])) . '|' . ($ing['unit'] ?? '');
        if (!isset($agg[$key])) {
            $agg[$key] = [
                'ingredient_name' => $ing['name'],
                'quantity'        => 0.0,
                'unit'            => $ing['unit'],
                'price_est'       => 0.0,
                'zone'            => $ing['zone'] ?? 'scaffali',
            ];
        }
        if ($ing['quantity'])  $agg[$key]['quantity']  += (float)$ing['quantity'] * $factor;
        if ($ing['price_est']) $agg[$key]['price_est'] += (float)$ing['price_est'] * $factor;
    }

    // prezzi storici (media ultimi 3)
    $priceSt = $pdo->prepare("SELECT AVG(price) FROM
        (SELECT price FROM ingredient_prices
         WHERE family_id=? AND LOWER(ingredient_name)=LOWER(?) ORDER BY recorded_at DESC LIMIT 3)");

    // ricostruzione lista
    $pdo->prepare("DELETE FROM shopping_items WHERE family_id=? AND week_start=?")
        ->execute([$familyId, $weekStart]);

    $ins = $pdo->prepare("INSERT INTO shopping_items
        (family_id,week_start,ingredient_name,quantity,unit,price_est,zone,zone_order)
        VALUES (?,?,?,?,?,?,?,?)");

    $zo   = ZONE_ORDER;
    $rows = [];
    foreach ($agg as $item) {
        $priceSt->execute([$familyId, $item['ingredient_name']]);
        $hist = $priceSt->fetchColumn();
        if ($hist) $item['price_est'] = round((float)$hist, 2);

        $zoneOrder = $zo[$item['zone']] ?? 9;
        $qty = $item['quantity'] > 0 ? round($item['quantity'], 1) : null;

        $ins->execute([
            $familyId, $weekStart,
            $item['ingredient_name'], $qty, $item['unit'],
            round($item['price_est'], 2), $item['zone'], $zoneOrder,
        ]);
        $rows[] = array_merge($item, [
            'id' => (int)$pdo->lastInsertId(),
            'quantity'   => $qty,
            'zone_order' => $zoneOrder,
            'checked'    => 0,
        ]);
    }

    usort($rows, fn($a,$b) => $a['zone_order'] <=> $b['zone_order'] ?: strcmp($a['ingredient_name'], $b['ingredient_name']));
    respond($rows);
}

function apiShoppingList(PDO $pdo): never {
    $familyId  = $_SESSION['family_id'];
    $weekStart = $_GET['week_start'] ?? '';
    if (!$weekStart) respondError('week_start obbligatorio');

    $since = $_GET['since'] ?? null;
    $sql   = "SELECT * FROM shopping_items WHERE family_id=? AND week_start=?";
    $par   = [$familyId, $weekStart];
    if ($since) { $sql .= " AND checked_at >= ?"; $par[] = $since; }
    $sql .= " ORDER BY zone_order, ingredient_name";

    $st = $pdo->prepare($sql);
    $st->execute($par);
    respond($st->fetchAll());
}

function apiShoppingCheck(PDO $pdo, array $in): never {
    $id      = (int)($in['id']      ?? 0);
    $checked = (int)($in['checked'] ?? 0);
    if (!$id) respondError('id obbligatorio');

    $now = date('Y-m-d H:i:s');
    $by  = $checked ? $_SESSION['user_id'] : null;
    $at  = $checked ? $now : null;

    $pdo->prepare("UPDATE shopping_items SET checked=?, checked_by=?, checked_at=? WHERE id=? AND family_id=?")
        ->execute([$checked, $by, $at, $id, $_SESSION['family_id']]);
    respond(['success' => true, 'checked_by' => $by, 'checked_at' => $at]);
}

function apiShoppingPriceUpdate(PDO $pdo, array $in): never {
    $familyId = $_SESSION['family_id'];
    $id       = (int)($in['id']           ?? 0);
    $price    = (float)($in['price_actual'] ?? 0);
    if (!$id) respondError('id obbligatorio');

    $pdo->prepare("UPDATE shopping_items SET price_actual=? WHERE id=? AND family_id=?")
        ->execute([$price, $id, $familyId]);

    // salva nello storico
    $item = $pdo->prepare("SELECT ingredient_name, unit FROM shopping_items WHERE id=?");
    $item->execute([$id]);
    $row = $item->fetch();
    if ($row && $price > 0) {
        $pdo->prepare("INSERT INTO ingredient_prices (family_id,ingredient_name,price,unit) VALUES (?,?,?,?)")
            ->execute([$familyId, $row['ingredient_name'], $price, $row['unit']]);
    }
    respond(['success' => true]);
}

function apiShoppingResetChecks(PDO $pdo, array $in): never {
    $weekStart = $in['week_start'] ?? '';
    if (!$weekStart) respondError('week_start obbligatorio');
    $pdo->prepare("UPDATE shopping_items SET checked=0, checked_by=NULL, checked_at=NULL WHERE family_id=? AND week_start=?")
        ->execute([$_SESSION['family_id'], $weekStart]);
    respond(['success' => true]);
}
