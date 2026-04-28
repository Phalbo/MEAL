<?php
// ── CSV helpers ───────────────────────────────────────────────────────────────
function csvRow(array $row): string {
    return implode(',', array_map(fn($v) =>
        '"' . str_replace('"', '""', (string)($v ?? '')) . '"', $row)) . "\n";
}

function parseCsvFile(string $path): array {
    $rows = [];
    if (($fh = fopen($path, 'r')) === false) return $rows;
    $headers = null;
    while (($line = fgetcsv($fh)) !== false) {
        if ($headers === null) { $headers = $line; continue; }
        if (count($line) === count($headers))
            $rows[] = array_combine($headers, $line);
    }
    fclose($fh);
    return $rows;
}

// ── Export ────────────────────────────────────────────────────────────────────
function apiExportCsv(PDO $pdo): never {
    $familyId = $_SESSION['family_id'];
    $table    = $_GET['table'] ?? '';

    $allowed = ['nutrition_db','meals','meal_ingredients','pantry_items','shopping_items'];
    if (!in_array($table, $allowed)) respondError('Tabella non valida');

    switch ($table) {
        case 'nutrition_db':
            $rows = $pdo->query("SELECT name,kcal_100g,zone,aliases,unit_weights FROM nutrition_db ORDER BY name")->fetchAll();
            $cols = ['name','kcal_100g','zone','aliases','unit_weights'];
            break;
        case 'meals':
            $rows = $pdo->prepare("SELECT name,emoji,category_id,cal_per_adult,notes,is_system FROM meals WHERE family_id=? ORDER BY name");
            $rows->execute([$familyId]);
            $rows = $rows->fetchAll();
            $cols = ['name','emoji','category_id','cal_per_adult','notes','is_system'];
            break;
        case 'meal_ingredients':
            $st = $pdo->prepare("
                SELECT m.name AS meal_name, mi.name, mi.quantity, mi.unit,
                       mi.price_est, mi.intolerance_flags, mi.zone
                FROM meal_ingredients mi
                JOIN meals m ON m.id = mi.meal_id
                WHERE m.family_id=? OR m.is_system=1
                ORDER BY m.name, mi.name
            ");
            $st->execute([$familyId]);
            $rows = $st->fetchAll();
            $cols = ['meal_name','name','quantity','unit','price_est','intolerance_flags','zone'];
            break;
        case 'pantry_items':
            $st = $pdo->prepare("SELECT ingredient_name,quantity,unit,zone,expiry_date FROM pantry_items WHERE family_id=? ORDER BY ingredient_name");
            $st->execute([$familyId]);
            $rows = $st->fetchAll();
            $cols = ['ingredient_name','quantity','unit','zone','expiry_date'];
            break;
        case 'shopping_items':
            $st = $pdo->prepare("SELECT week_start,ingredient_name,quantity,unit,zone,checked FROM shopping_items WHERE family_id=? ORDER BY week_start,ingredient_name");
            $st->execute([$familyId]);
            $rows = $st->fetchAll();
            $cols = ['week_start','ingredient_name','quantity','unit','zone','checked'];
            break;
    }

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $table . '_' . date('Y-m-d') . '.csv"');
    echo "\xEF\xBB\xBF"; // BOM UTF-8
    echo csvRow($cols);
    foreach ($rows as $row) echo csvRow(array_map(fn($c) => $row[$c] ?? '', $cols));
    exit;
}

// ── Import ────────────────────────────────────────────────────────────────────
function apiImportCsv(PDO $pdo): never {
    $familyId = $_SESSION['family_id'];
    $table    = $_POST['table'] ?? '';

    $allowed = ['nutrition_db','meals','meal_ingredients','pantry_items','shopping_items'];
    if (!in_array($table, $allowed)) respondError('Tabella non valida');

    $upload = $_FILES['file'] ?? null;
    if (!$upload || $upload['error'] !== UPLOAD_ERR_OK) respondError('File non ricevuto o errore upload');

    $rows = parseCsvFile($upload['tmp_name']);
    if (!$rows) respond(['imported' => 0, 'skipped' => 0]);

    $imported = 0;
    $skipped  = 0;

    switch ($table) {
        case 'nutrition_db':
            $ins = $pdo->prepare("INSERT OR IGNORE INTO nutrition_db (name,kcal_100g,zone,aliases,unit_weights) VALUES (?,?,?,?,?)");
            foreach ($rows as $r) {
                $ins->execute([
                    trim($r['name'] ?? ''),
                    (float)($r['kcal_100g'] ?? 0),
                    $r['zone'] ?? 'scaffali',
                    $r['aliases'] ?? '[]',
                    $r['unit_weights'] ?? '{}',
                ]);
                $ins->rowCount() ? $imported++ : $skipped++;
            }
            break;

        case 'meals':
            $check = $pdo->prepare("SELECT id FROM meals WHERE family_id=? AND LOWER(name)=LOWER(?)");
            $ins   = $pdo->prepare("INSERT INTO meals (family_id,name,emoji,category_id,cal_per_adult,notes,created_by) VALUES (?,?,?,?,?,?,?)");
            foreach ($rows as $r) {
                $name = trim($r['name'] ?? '');
                if (!$name) { $skipped++; continue; }
                $check->execute([$familyId, $name]);
                if ($check->fetch()) { $skipped++; continue; }
                $ins->execute([
                    $familyId, $name,
                    $r['emoji'] ?? '🍽️',
                    (int)($r['category_id'] ?? 5),
                    (int)($r['cal_per_adult'] ?? 0),
                    $r['notes'] ?? null,
                    $_SESSION['user_id'],
                ]);
                $imported++;
            }
            break;

        case 'meal_ingredients':
            $mealSt = $pdo->prepare("SELECT id FROM meals WHERE (family_id=? OR is_system=1) AND LOWER(name)=LOWER(?) LIMIT 1");
            $check  = $pdo->prepare("SELECT id FROM meal_ingredients WHERE meal_id=? AND LOWER(name)=LOWER(?)");
            $ins    = $pdo->prepare("INSERT INTO meal_ingredients (meal_id,name,quantity,unit,price_est,intolerance_flags,zone) VALUES (?,?,?,?,?,?,?)");
            foreach ($rows as $r) {
                $mealName = trim($r['meal_name'] ?? '');
                $ingName  = trim($r['name']      ?? '');
                if (!$mealName || !$ingName) { $skipped++; continue; }
                $mealSt->execute([$familyId, $mealName]);
                $meal = $mealSt->fetch();
                if (!$meal) { $skipped++; continue; }
                $check->execute([$meal['id'], $ingName]);
                if ($check->fetch()) { $skipped++; continue; }
                $ins->execute([
                    $meal['id'], $ingName,
                    $r['quantity'] !== '' ? (float)$r['quantity'] : null,
                    $r['unit'] ?? null,
                    (float)($r['price_est'] ?? 0),
                    $r['intolerance_flags'] ?? '',
                    $r['zone'] ?? 'scaffali',
                ]);
                $imported++;
            }
            break;

        case 'pantry_items':
            $ins = $pdo->prepare("
                INSERT OR IGNORE INTO pantry_items (family_id,ingredient_name,quantity,unit,zone,expiry_date)
                VALUES (?,?,?,?,?,?)
            ");
            foreach ($rows as $r) {
                $name = trim($r['ingredient_name'] ?? '');
                if (!$name) { $skipped++; continue; }
                $ins->execute([
                    $familyId, $name,
                    $r['quantity'] !== '' ? (float)$r['quantity'] : null,
                    $r['unit'] ?? null,
                    $r['zone'] ?? 'scaffali',
                    $r['expiry_date'] !== '' ? $r['expiry_date'] : null,
                ]);
                $ins->rowCount() ? $imported++ : $skipped++;
            }
            break;

        case 'shopping_items':
            $check = $pdo->prepare("SELECT id FROM shopping_items WHERE family_id=? AND week_start=? AND LOWER(ingredient_name)=LOWER(?) AND COALESCE(unit,'')=COALESCE(?,'')");
            $ins   = $pdo->prepare("INSERT INTO shopping_items (family_id,week_start,ingredient_name,quantity,unit,zone,checked) VALUES (?,?,?,?,?,?,?)");
            foreach ($rows as $r) {
                $name = trim($r['ingredient_name'] ?? '');
                $week = trim($r['week_start']      ?? '');
                if (!$name || !$week) { $skipped++; continue; }
                $check->execute([$familyId, $week, $name, $r['unit'] ?? null]);
                if ($check->fetch()) { $skipped++; continue; }
                $ins->execute([
                    $familyId, $week, $name,
                    $r['quantity'] !== '' ? (float)$r['quantity'] : null,
                    $r['unit'] ?? null,
                    $r['zone'] ?? 'scaffali',
                    (int)($r['checked'] ?? 0),
                ]);
                $imported++;
            }
            break;
    }

    respond(['imported' => $imported, 'skipped' => $skipped]);
}
