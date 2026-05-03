<?php
// ── nutrition_search ──────────────────────────────────────────────────────────
function apiNutritionSearch(PDO $pdo): never {
    $q    = trim($_GET['q'] ?? '');
    $zone = trim($_GET['zone'] ?? '');
    $sql  = "SELECT id, name, zone, kcal_100g, price_est, unit_weights
             FROM nutrition_db WHERE 1=1";
    $params = [];
    if ($q) {
        $sql    .= " AND name LIKE ?";
        $params[] = '%' . $q . '%';
    }
    if ($zone) {
        $sql    .= " AND zone = ?";
        $params[] = $zone;
    }
    $sql .= " ORDER BY name LIMIT 20";
    $st = $pdo->prepare($sql);
    $st->execute($params);
    respond($st->fetchAll());
}

// ── nutrition_list (paginata per ingredienti.php) ─────────────────────────────
function apiNutritionList(PDO $pdo): never {
    $q      = trim($_GET['q'] ?? '');
    $zone   = trim($_GET['zone'] ?? '');
    $offset = max(0, (int)($_GET['offset'] ?? 0));
    $limit  = min(200, max(1, (int)($_GET['limit'] ?? 100)));

    $sql    = "SELECT id, name, kcal_100g, price_est, zone, aliases, unit_weights FROM nutrition_db WHERE 1=1";
    $params = [];
    if ($q) {
        $sql    .= " AND name LIKE ?";
        $params[] = '%' . $q . '%';
    }
    if ($zone) {
        $sql    .= " AND zone = ?";
        $params[] = $zone;
    }
    $sql .= " ORDER BY name LIMIT ? OFFSET ?";
    $params[] = $limit;
    $params[] = $offset;

    $st = $pdo->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll();

    $countSql = "SELECT COUNT(*) FROM nutrition_db WHERE 1=1";
    $countParams = [];
    if ($q)    { $countSql .= " AND name LIKE ?";  $countParams[] = '%'.$q.'%'; }
    if ($zone) { $countSql .= " AND zone = ?";     $countParams[] = $zone; }
    $total = (int)$pdo->prepare($countSql)->execute($countParams) ? $pdo->prepare($countSql) : 0;
    $cst = $pdo->prepare($countSql);
    $cst->execute($countParams);
    $total = (int)$cst->fetchColumn();

    respond(['items' => $rows, 'total' => $total]);
}

// ── nutrition_update ──────────────────────────────────────────────────────────
function apiNutritionUpdate(PDO $pdo, array $in): never {
    $id    = (int)($in['id'] ?? 0);
    if (!$id) respondError('id obbligatorio');

    $fields = [];
    $params = [];
    if (array_key_exists('name', $in) && trim($in['name'])) {
        $fields[] = 'name=?';       $params[] = trim($in['name']);
    }
    if (array_key_exists('kcal_100g', $in)) {
        $fields[] = 'kcal_100g=?';  $params[] = max(0, (float)$in['kcal_100g']);
    }
    if (array_key_exists('price_est', $in)) {
        $fields[] = 'price_est=?';  $params[] = max(0, (float)$in['price_est']);
    }
    if (array_key_exists('zone', $in) && $in['zone']) {
        $fields[] = 'zone=?';       $params[] = $in['zone'];
    }
    if (!$fields) respondError('Nessun campo da aggiornare');

    $params[] = $id;
    $pdo->prepare("UPDATE nutrition_db SET " . implode(',', $fields) . " WHERE id=?")
        ->execute($params);
    $row = $pdo->prepare("SELECT id,name,kcal_100g,price_est,zone FROM nutrition_db WHERE id=?");
    $row->execute([$id]);
    respond($row->fetch());
}

// ── nutrition_add ─────────────────────────────────────────────────────────────
function apiNutritionAdd(PDO $pdo, array $in): never {
    $name = trim($in['name'] ?? '');
    if (!$name) respondError('nome obbligatorio');
    $kcal  = max(0, (float)($in['kcal_100g'] ?? 0));
    $price = max(0, (float)($in['price_est'] ?? 0));
    $zone  = $in['zone'] ?? 'scaffali';
    $uw    = $in['unit_weights'] ? json_encode($in['unit_weights'], JSON_UNESCAPED_UNICODE) : '{}';

    $check = $pdo->prepare("SELECT id FROM nutrition_db WHERE LOWER(name)=LOWER(?)");
    $check->execute([$name]);
    if ($check->fetch()) respondError('Ingrediente già presente');

    $st = $pdo->prepare("INSERT INTO nutrition_db (name,kcal_100g,price_est,zone,aliases,unit_weights)
                         VALUES (?,?,?,?,'[]',?)");
    $st->execute([$name, $kcal, $price, $zone, $uw]);
    $newId = (int)$pdo->lastInsertId();
    $row = $pdo->prepare("SELECT id,name,kcal_100g,price_est,zone FROM nutrition_db WHERE id=?");
    $row->execute([$newId]);
    respond($row->fetch());
}

// ── nutrition_delete ──────────────────────────────────────────────────────────
function apiNutritionDelete(PDO $pdo, array $in): never {
    $id = (int)($in['id'] ?? 0);
    if (!$id) respondError('id obbligatorio');
    $pdo->prepare("DELETE FROM nutrition_db WHERE id=?")->execute([$id]);
    respond(['success' => true]);
}
