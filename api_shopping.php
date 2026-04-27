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

    // alias da nutrition_db: alias_lower → nome canonico (per deduplicare "olio"/"olio evo"/...)
    $aliasMap = [];
    foreach ($pdo->query("SELECT name, aliases FROM nutrition_db")->fetchAll() as $nd) {
        $canon = $nd['name'];
        $aliasMap[mb_strtolower(trim($canon))] = $canon;
        foreach (json_decode($nd['aliases'] ?? '[]', true) ?: [] as $a)
            $aliasMap[mb_strtolower(trim($a))] = $canon;
    }

    // aggregazione: chiave = nome_canonico_lower|unit
    $agg = [];
    foreach ($ings->fetchAll() as $ing) {
        $factor    = $totalPortions * ($counts[(int)$ing['meal_id']] ?? 1);
        $nameLower = mb_strtolower(trim($ing['name']));
        $canonical = $aliasMap[$nameLower] ?? $ing['name'];
        $key       = mb_strtolower(trim($canonical)) . '|' . ($ing['unit'] ?? '');
        if (!isset($agg[$key])) {
            $agg[$key] = [
                'ingredient_name' => $canonical,
                'quantity'        => 0.0,
                'unit'            => $ing['unit'],
                'price_est'       => 0.0,
                'zone'            => $ing['zone'] ?? 'scaffali',
            ];
        }
        if ($ing['quantity'])  $agg[$key]['quantity']  += (float)$ing['quantity'] * $factor;
        if ($ing['price_est']) $agg[$key]['price_est'] += (float)$ing['price_est'] * $factor;
    }

    // ── Filtro ingredienti trascurabili ──────────────────────────────────────
    $IGNORE_ALWAYS = ['sale','pepe','pepe nero','pepe bianco',
                      'acqua','acqua di cottura','ghiaccio'];
    $MIN_QTY = [
        'cucchiaio'  => 4, 'cucchiai'   => 4,
        'cucchiaino' => 6, 'cucchiaini' => 6,
        'foglia'     => 8, 'foglie'     => 8,
        'rametto'    => 3, 'rametti'    => 3,
        'spruzzo'    => 999, 'pizzico'  => 999,
        'bustina'    => 2,
    ];
    foreach ($agg as $k => $item) {
        $nl   = mb_strtolower(trim($item['ingredient_name']));
        $unit = mb_strtolower(trim($item['unit'] ?? ''));
        if (in_array($nl, $IGNORE_ALWAYS)) { unset($agg[$k]); continue; }
        if (isset($MIN_QTY[$unit]) && ($item['quantity'] ?? 0) < $MIN_QTY[$unit]) { unset($agg[$k]); continue; }
        if (in_array($unit, ['q.b.','qb','a piacere']) && !$item['quantity'])     { unset($agg[$k]); continue; }
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
    $sql   = "SELECT si.*, u.avatar_emoji as checked_by_emoji
              FROM shopping_items si
              LEFT JOIN users u ON u.id = si.checked_by
              WHERE si.family_id=? AND si.week_start=?";
    $par   = [$familyId, $weekStart];
    if ($since) { $sql .= " AND si.checked_at >= ?"; $par[] = $since; }
    $sql .= " ORDER BY si.zone_order, si.ingredient_name";

    $st = $pdo->prepare($sql);
    $st->execute($par);
    respond($st->fetchAll());
}

function apiShoppingListPub(PDO $pdo): never {
    $token     = $_GET['token'] ?? '';
    $weekStart = $_GET['week_start'] ?? '';
    if (!$token || !$weekStart) respondError('token e week_start obbligatori');

    $fam = $pdo->prepare("SELECT id FROM families WHERE share_token=?");
    $fam->execute([$token]);
    $family = $fam->fetch();
    if (!$family) respondError('Token non valido', 403);

    $since = $_GET['since'] ?? null;
    $sql   = "SELECT si.*, u.avatar_emoji as checked_by_emoji
              FROM shopping_items si
              LEFT JOIN users u ON u.id = si.checked_by
              WHERE si.family_id=? AND si.week_start=?";
    $par   = [$family['id'], $weekStart];
    if ($since) { $sql .= " AND si.checked_at >= ?"; $par[] = $since; }
    $sql  .= " ORDER BY si.zone_order, si.ingredient_name";

    $st = $pdo->prepare($sql);
    $st->execute($par);
    respond($st->fetchAll());
}

function apiShoppingCheckPub(PDO $pdo, array $in): never {
    $token   = $in['token']   ?? '';
    $id      = (int)($in['id'] ?? 0);
    $checked = (int)($in['checked'] ?? 0);
    if (!$token || !$id) respondError('token e id obbligatori');

    $fam = $pdo->prepare("SELECT id FROM families WHERE share_token=?");
    $fam->execute([$token]);
    $family = $fam->fetch();
    if (!$family) respondError('Token non valido', 403);

    $at = $checked ? date('Y-m-d H:i:s') : null;
    $pdo->prepare("UPDATE shopping_items SET checked=?, checked_by=NULL, checked_at=? WHERE id=? AND family_id=?")
        ->execute([$checked, $at, $id, $family['id']]);
    respond(['success' => true]);
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

function apiShoppingExportText(PDO $pdo): never {
    $familyId  = $_SESSION['family_id'];
    $weekStart = $_GET['week_start'] ?? '';
    if (!$weekStart) respondError('week_start obbligatorio');

    $st = $pdo->prepare("SELECT * FROM shopping_items WHERE family_id=? AND week_start=? ORDER BY zone_order, ingredient_name");
    $st->execute([$familyId, $weekStart]);
    $items = $st->fetchAll();

    header('Content-Type: text/plain; charset=utf-8');
    $text  = "🛒 Lista spesa — settimana del $weekStart\n";
    $text .= str_repeat('─', 40) . "\n\n";

    $currentZone = null;
    $total = 0;
    foreach ($items as $it) {
        if ($it['zone'] !== $currentZone) {
            $currentZone = $it['zone'];
            $text .= "\n▸ " . strtoupper($currentZone) . "\n";
        }
        $check = $it['checked'] ? '✓' : '○';
        $qty   = $it['quantity'] ? " {$it['quantity']}{$it['unit']}" : '';
        $price = $it['price_actual'] ? sprintf(' €%.2f', $it['price_actual'])
               : ($it['price_est'] ? sprintf(' ~€%.2f', $it['price_est']) : '');
        $text .= "  $check {$it['ingredient_name']}$qty$price\n";
        $total += (float)($it['price_actual'] ?: $it['price_est'] ?: 0);
    }
    $text .= "\n" . str_repeat('─', 40) . "\n";
    $text .= sprintf("TOTALE STIMATO: €%.2f\n", $total);

    echo $text;
    exit;
}

function apiShoppingResetChecks(PDO $pdo, array $in): never {
    $weekStart = $in['week_start'] ?? '';
    if (!$weekStart) respondError('week_start obbligatorio');
    $pdo->prepare("UPDATE shopping_items SET checked=0, checked_by=NULL, checked_at=NULL WHERE family_id=? AND week_start=?")
        ->execute([$_SESSION['family_id'], $weekStart]);
    respond(['success' => true]);
}

function apiShoppingAddManual(PDO $pdo, array $in): never {
    $familyId  = $_SESSION['family_id'];
    $name      = trim($in['name']       ?? '');
    $qty       = isset($in['quantity']) && $in['quantity'] !== '' ? (float)$in['quantity'] : null;
    $unit      = trim($in['unit']       ?? '') ?: null;
    $zone      = trim($in['zone']       ?? 'altro');
    $weekStart = trim($in['week_start'] ?? '');

    if (!$name)      respondError('name obbligatorio');
    if (!$weekStart) respondError('week_start obbligatorio');

    // arricchisce nutrition_db se l'ingrediente non esiste
    $check = $pdo->prepare("SELECT id FROM nutrition_db WHERE LOWER(name)=LOWER(?)");
    $check->execute([$name]);
    if (!$check->fetch()) {
        $pdo->prepare("INSERT OR IGNORE INTO nutrition_db (name, kcal_100g, zone) VALUES (?,0,?)")
            ->execute([$name, $zone]);
    }

    $zo        = ZONE_ORDER;
    $zoneOrder = $zo[$zone] ?? 9;

    $ins = $pdo->prepare("INSERT INTO shopping_items
        (family_id, week_start, ingredient_name, quantity, unit, zone, zone_order, is_manual, custom_zone)
        VALUES (?,?,?,?,?,?,?,1,?)");
    $ins->execute([$familyId, $weekStart, $name, $qty, $unit, $zone, $zoneOrder, $zone]);

    $id = (int)$pdo->lastInsertId();
    respond([
        'success' => true,
        'id'      => $id,
        'ingredient_name' => $name,
        'quantity'   => $qty,
        'unit'       => $unit,
        'zone'       => $zone,
        'zone_order' => $zoneOrder,
        'is_manual'  => 1,
        'checked'    => 0,
    ]);
}

function apiShoppingClear(PDO $pdo, array $in): never {
    $familyId  = $_SESSION['family_id'];
    $weekStart = trim($in['week_start'] ?? '');
    $mode      = trim($in['mode']       ?? 'all');

    if (!$weekStart) respondError('week_start obbligatorio');

    if ($mode === 'checked') {
        $pdo->prepare("DELETE FROM shopping_items WHERE family_id=? AND week_start=? AND checked=1")
            ->execute([$familyId, $weekStart]);
    } else {
        $pdo->prepare("DELETE FROM shopping_items WHERE family_id=? AND week_start=?")
            ->execute([$familyId, $weekStart]);
    }
    respond(['success' => true]);
}
