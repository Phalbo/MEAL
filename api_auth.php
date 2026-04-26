<?php
function apiLogin(PDO $pdo, array $in): never {
    $email = trim($in['email'] ?? '');
    $pw    = $in['password'] ?? '';
    if (!$email || !$pw) respondError('Email e password obbligatorie');

    $st = $pdo->prepare("SELECT * FROM users WHERE email = ?");
    $st->execute([mb_strtolower($email)]);
    $user = $st->fetch();

    if (!$user || !password_verify($pw, $user['password']))
        respondError('Credenziali non valide', 401);

    $_SESSION['user_id'] = $user['id'];

    // recupera famiglia
    $fs = $pdo->prepare("
        SELECT f.* FROM families f
        JOIN family_members fm ON fm.family_id = f.id
        WHERE fm.user_id = ? LIMIT 1
    ");
    $fs->execute([$user['id']]);
    $family = $fs->fetch() ?: null;
    if ($family) $_SESSION['family_id'] = $family['id'];

    unset($user['password']);
    respond(['success' => true, 'user' => $user, 'family' => $family]);
}

function apiRegister(PDO $pdo, array $in): never {
    $name  = trim($in['name']  ?? '');
    $email = trim($in['email'] ?? '');
    $pw    = $in['password']   ?? '';

    if (!$name)              respondError('Nome obbligatorio');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) respondError('Email non valida');
    if (strlen($pw) < 6)    respondError('Password minimo 6 caratteri');

    $check = $pdo->prepare("SELECT id FROM users WHERE email = ?");
    $check->execute([mb_strtolower($email)]);
    if ($check->fetch()) respondError('Email già registrata', 409);

    $hash = password_hash($pw, PASSWORD_BCRYPT);
    $st   = $pdo->prepare("INSERT INTO users (email, password, name) VALUES (?,?,?)");
    $st->execute([mb_strtolower($email), $hash, $name]);
    $userId = (int)$pdo->lastInsertId();

    $_SESSION['user_id'] = $userId;

    respond(['success' => true, 'user' => [
        'id' => $userId, 'name' => $name,
        'email' => mb_strtolower($email), 'role' => 'user',
    ], 'family' => null]);
}

function apiLogout(): never {
    session_destroy();
    respond(['success' => true]);
}

function apiMe(PDO $pdo): never {
    $userId = $_SESSION['user_id'];
    $st = $pdo->prepare("SELECT id,name,email,role,avatar_emoji,created_at FROM users WHERE id=?");
    $st->execute([$userId]);
    $user = $st->fetch();
    if (!$user) respondError('Utente non trovato', 404);

    $family = null;
    if (!empty($_SESSION['family_id'])) {
        $fs = $pdo->prepare("SELECT * FROM families WHERE id=?");
        $fs->execute([$_SESSION['family_id']]);
        $family = $fs->fetch() ?: null;
    }
    respond(['user' => $user, 'family' => $family]);
}

// ── Seed piatti v1.0 quando si crea una nuova famiglia ───────────────────────
function seedMeals(PDO $pdo, int $familyId, int $userId): void {
    $jsonPath = __DIR__ . '/data/meals.json';
    if (!file_exists($jsonPath)) return;

    $meals = json_decode(file_get_contents($jsonPath), true) ?? [];

    // mappa categoria nome → id
    $catMap = ['Colazione'=>1,'Primo'=>2,'Secondo'=>3,'Contorno'=>4,'Altro'=>5];

    $mealSt = $pdo->prepare("
        INSERT INTO meals (family_id, name, emoji, category_id, cal_per_adult, created_by)
        VALUES (?,?,?,?,?,?)
    ");
    $ingSt = $pdo->prepare("
        INSERT INTO meal_ingredients (meal_id, name, quantity, unit, zone)
        VALUES (?,?,?,?,?)
    ");

    foreach ($meals as $m) {
        $catId = $catMap[$m['category']] ?? 5;
        $mealSt->execute([$familyId, $m['name'], $m['emoji'], $catId, $m['cal'], $userId]);
        $mealId = (int)$pdo->lastInsertId();

        foreach ($m['ingredients'] as $raw) {
            // parse "Spaghetti 320g" → name + qty + unit
            if (preg_match('/^(.+?)\s+([\d.,]+)\s*(g|kg|ml|l|pz|fette?)$/i', trim($raw), $p)) {
                $iname = trim($p[1]);
                $qty   = (float)str_replace(',', '.', $p[2]);
                $unit  = strtolower($p[3]);
            } else {
                $iname = trim($raw);
                $qty   = null;
                $unit  = null;
            }
            $zone = detectZone($iname);
            $ingSt->execute([$mealId, $iname, $qty, $unit, $zone]);
        }
    }
}
