<?php
function apiCategoriesList(PDO $pdo): never {
    $st = $pdo->query("SELECT * FROM meal_categories ORDER BY id");
    respond($st->fetchAll());
}

function apiNutritionLookup(PDO $pdo): never {
    $name = trim($_GET['name'] ?? '');
    if (!$name) respondError('name obbligatorio');
    $kcal = lookupKcal($pdo, $name);
    $zone = detectZone($name);
    respond(['kcal_100g' => $kcal, 'zone' => $zone]);
}

function apiMealsList(PDO $pdo): never {
    $familyId   = $_SESSION['family_id'];
    $categoryId = isset($_GET['category_id']) ? (int)$_GET['category_id'] : null;
    $q          = $_GET['q'] ?? '';

    $sql    = "SELECT m.*, mc.name as category, mc.emoji as category_emoji
               FROM meals m
               LEFT JOIN meal_categories mc ON mc.id = m.category_id
               WHERE (m.family_id = ? OR m.is_system = 1)";
    $params = [$familyId];

    if ($categoryId) { $sql .= " AND m.category_id=?"; $params[] = $categoryId; }
    if ($q)          { $sql .= " AND LOWER(m.name) LIKE ?"; $params[] = '%'.mb_strtolower($q).'%'; }
    $sql .= " ORDER BY m.is_system ASC, mc.id, m.name";

    $st = $pdo->prepare($sql);
    $st->execute($params);
    $meals = $st->fetchAll();

    $ingSt = $pdo->prepare("SELECT * FROM meal_ingredients WHERE meal_id=? ORDER BY id");
    foreach ($meals as &$meal) {
        $ingSt->execute([$meal['id']]);
        $meal['ingredients'] = $ingSt->fetchAll();
    }
    respond($meals);
}

function apiMealsAdd(PDO $pdo, array $in): never {
    $familyId = $_SESSION['family_id'];
    $userId   = $_SESSION['user_id'];
    $name     = trim($in['name'] ?? '');
    if (!$name) respondError('Nome obbligatorio');

    $emoji      = trim($in['emoji']       ?? '🍽️') ?: '🍽️';
    $categoryId = (int)($in['category_id'] ?? 5);
    $notes      = trim($in['notes']        ?? '');
    $ingredients = $in['ingredients']     ?? [];

    // calcola cal_per_adult da ingredienti se non fornito
    $cal = (int)($in['cal_per_adult'] ?? 0);
    if (!$cal && $ingredients) {
        $res = calcMealKcal($pdo, $ingredients);
        $cal = $res['kcal_totali'];
    }

    $pdo->beginTransaction();
    $st = $pdo->prepare("INSERT INTO meals (family_id,name,emoji,category_id,cal_per_adult,notes,created_by) VALUES (?,?,?,?,?,?,?)");
    $st->execute([$familyId, $name, $emoji, $categoryId, $cal, $notes ?: null, $userId]);
    $mealId = (int)$pdo->lastInsertId();

    saveIngredients($pdo, $mealId, $ingredients);
    $pdo->commit();

    $meal = $pdo->prepare("SELECT m.*, mc.name as category FROM meals m LEFT JOIN meal_categories mc ON mc.id=m.category_id WHERE m.id=?");
    $meal->execute([$mealId]);
    respond(['success' => true, 'meal' => $meal->fetch()]);
}

function apiMealsUpdate(PDO $pdo, array $in): never {
    $familyId = $_SESSION['family_id'];
    $id       = (int)($in['id'] ?? 0);
    if (!$id) respondError('ID mancante');

    // verifica appartenenza
    $own = $pdo->prepare("SELECT id FROM meals WHERE id=? AND family_id=?");
    $own->execute([$id, $familyId]);
    if (!$own->fetch()) respondError('Piatto non trovato', 404);

    $fields = []; $params = [];
    foreach (['name','emoji','category_id','cal_per_adult','notes'] as $f) {
        if (array_key_exists($f, $in)) { $fields[] = "$f=?"; $params[] = $in[$f]; }
    }

    // ricalcola cal se ingredienti aggiornati
    if (isset($in['ingredients'])) {
        if (!isset($in['cal_per_adult']) || !$in['cal_per_adult']) {
            $res = calcMealKcal($pdo, $in['ingredients']);
            if ($res['kcal_totali']) { $fields[] = "cal_per_adult=?"; $params[] = $res['kcal_totali']; }
        }
        $pdo->beginTransaction();
        if ($fields) {
            $params[] = $id;
            $pdo->prepare("UPDATE meals SET ".implode(',', $fields)." WHERE id=?")->execute($params);
        }
        $pdo->prepare("DELETE FROM meal_ingredients WHERE meal_id=?")->execute([$id]);
        saveIngredients($pdo, $id, $in['ingredients']);
        $pdo->commit();
    } else {
        if ($fields) {
            $params[] = $id;
            $pdo->prepare("UPDATE meals SET ".implode(',', $fields)." WHERE id=?")->execute($params);
        }
    }
    respond(['success' => true]);
}

function apiMealsDelete(PDO $pdo, array $in): never {
    $id = (int)($in['id'] ?? 0);
    if (!$id) respondError('ID mancante');
    $pdo->prepare("DELETE FROM meals WHERE id=? AND family_id=?")
        ->execute([$id, $_SESSION['family_id']]);
    respond(['success' => true]);
}

// ── Helper: salva array ingredienti ──────────────────────────────────────────
function saveIngredients(PDO $pdo, int $mealId, array $ingredients): void {
    $st = $pdo->prepare("INSERT INTO meal_ingredients (meal_id,name,quantity,unit,price_est,intolerance_flags,zone) VALUES (?,?,?,?,?,?,?)");
    foreach ($ingredients as $ing) {
        $iname = trim($ing['name'] ?? '');
        if (!$iname) continue;
        $zone = $ing['zone'] ?? detectZone($iname);
        $st->execute([
            $mealId,
            $iname,
            isset($ing['quantity']) && $ing['quantity'] !== '' ? (float)$ing['quantity'] : null,
            $ing['unit']              ?? null,
            (float)($ing['price_est'] ?? 0),
            $ing['intolerance_flags'] ?? '',
            $zone,
        ]);
    }
}
