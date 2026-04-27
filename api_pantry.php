<?php
function apiPantryList(PDO $pdo): never {
    $st = $pdo->prepare("SELECT * FROM pantry_items WHERE family_id=? ORDER BY zone, ingredient_name");
    $st->execute([$_SESSION['family_id']]);
    respond($st->fetchAll());
}

function apiPantryUpdate(PDO $pdo, array $in): never {
    $familyId = $_SESSION['family_id'];
    $name     = trim($in['ingredient_name'] ?? '');
    $qty      = isset($in['quantity'])    && $in['quantity']    !== '' ? (float)$in['quantity']    : null;
    $unit     = trim($in['unit']          ?? '');
    $zone     = trim($in['zone']          ?? '') ?: 'scaffali';
    $expiry   = trim($in['expiry_date']   ?? '') ?: null;
    if (!$name) respondError('ingredient_name obbligatorio');

    $pdo->prepare("
        INSERT INTO pantry_items (family_id, ingredient_name, quantity, unit, zone, expiry_date, updated_at)
        VALUES (?,?,?,?,?,?,datetime('now'))
        ON CONFLICT(family_id, ingredient_name, unit)
        DO UPDATE SET quantity=excluded.quantity, zone=excluded.zone,
                      expiry_date=excluded.expiry_date, updated_at=excluded.updated_at
    ")->execute([$familyId, $name, $qty, $unit, $zone, $expiry]);

    respond(['success' => true, 'id' => (int)$pdo->lastInsertId()]);
}

function apiPantryDelete(PDO $pdo, array $in): never {
    $id = (int)($in['id'] ?? 0);
    if (!$id) respondError('id obbligatorio');
    $pdo->prepare("DELETE FROM pantry_items WHERE id=? AND family_id=?")
        ->execute([$id, $_SESSION['family_id']]);
    respond(['success' => true]);
}

// Copia gli articoli spuntati della lista spesa nella dispensa
function apiPantryFromShopping(PDO $pdo, array $in): never {
    $familyId  = $_SESSION['family_id'];
    $weekStart = $in['week_start'] ?? '';
    if (!$weekStart) respondError('week_start obbligatorio');

    $st = $pdo->prepare("SELECT ingredient_name, quantity, unit, zone FROM shopping_items
        WHERE family_id=? AND week_start=? AND checked=1");
    $st->execute([$familyId, $weekStart]);
    $items = $st->fetchAll();
    if (!$items) respond(['imported' => 0]);

    $ins = $pdo->prepare("
        INSERT INTO pantry_items (family_id, ingredient_name, quantity, unit, zone, updated_at)
        VALUES (?,?,?,?,?,datetime('now'))
        ON CONFLICT(family_id, ingredient_name, unit)
        DO UPDATE SET quantity = COALESCE(pantry_items.quantity,0) + COALESCE(excluded.quantity,0),
                      zone=excluded.zone, updated_at=excluded.updated_at
    ");
    foreach ($items as $it)
        $ins->execute([$familyId, $it['ingredient_name'], $it['quantity'], $it['unit'], $it['zone']]);

    respond(['imported' => count($items)]);
}

function apiPantryAddManual(PDO $pdo, array $in): never {
    $familyId = $_SESSION['family_id'];
    $name     = trim($in['ingredient_name'] ?? '');
    $qty      = isset($in['quantity'])     && $in['quantity']     !== '' ? (float)$in['quantity']     : null;
    $unit     = trim($in['unit']           ?? '') ?: null;
    $minQty   = isset($in['min_quantity']) && $in['min_quantity'] !== '' ? (float)$in['min_quantity'] : null;
    $zone     = trim($in['zone']          ?? '') ?: 'scaffali';

    if (!$name) respondError('ingredient_name obbligatorio');

    // arricchisce nutrition_db se l'ingrediente non esiste
    $check = $pdo->prepare("SELECT id FROM nutrition_db WHERE LOWER(name)=LOWER(?)");
    $check->execute([$name]);
    if (!$check->fetch()) {
        $pdo->prepare("INSERT OR IGNORE INTO nutrition_db (name, kcal_100g, zone) VALUES (?,0,?)")
            ->execute([$name, $zone]);
    }

    // UPSERT: se esiste già (family_id, ingredient_name, unit) aggiorna, altrimenti inserisce
    $pdo->prepare("
        INSERT INTO pantry_items (family_id, ingredient_name, quantity, unit, zone, is_manual, updated_at)
        VALUES (?,?,?,?,?,1,datetime('now'))
        ON CONFLICT(family_id, ingredient_name, unit)
        DO UPDATE SET quantity    = excluded.quantity,
                      zone        = excluded.zone,
                      is_manual   = 1,
                      updated_at  = excluded.updated_at
    ")->execute([$familyId, $name, $qty, $unit, $zone]);

    respond(['success' => true, 'id' => (int)$pdo->lastInsertId()]);
}

function apiPantryClear(PDO $pdo): never {
    $pdo->prepare("DELETE FROM pantry_items WHERE family_id=?")
        ->execute([$_SESSION['family_id']]);
    respond(['success' => true]);
}

// Scala quantità in dispensa (es. dopo aver cucinato)
function apiPantryConsume(PDO $pdo, array $in): never {
    $familyId = $_SESSION['family_id'];
    $id       = (int)($in['id']       ?? 0);
    $amount   = (float)($in['amount'] ?? 0);
    if (!$id || $amount <= 0) respondError('id e amount obbligatori');

    $pdo->prepare("
        UPDATE pantry_items
        SET quantity   = CASE WHEN quantity IS NULL THEN NULL
                              WHEN quantity - ? <= 0 THEN 0
                              ELSE quantity - ? END,
            updated_at = datetime('now')
        WHERE id=? AND family_id=?
    ")->execute([$amount, $amount, $id, $familyId]);

    respond(['success' => true]);
}
