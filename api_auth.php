<?php
function apiLogin(PDO $pdo, array $in): never {
    $email    = trim($in['email'] ?? '');
    $pw       = $in['password'] ?? '';
    $remember = !empty($in['remember']);
    if (!$email || !$pw) respondError('Email e password obbligatorie');

    $st = $pdo->prepare("SELECT * FROM users WHERE email = ?");
    $st->execute([mb_strtolower($email)]);
    $user = $st->fetch();

    if (!$user || !password_verify($pw, $user['password']))
        respondError('Credenziali non valide', 401);

    $_SESSION['user_id'] = $user['id'];

    // sessione lunga 30 giorni se "rimani connesso"
    if ($remember) {
        $params = session_get_cookie_params();
        setcookie(session_name(), session_id(), [
            'expires'  => time() + 30 * 24 * 3600,
            'path'     => $params['path'],
            'domain'   => $params['domain'],
            'secure'   => $params['secure'],
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

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

function apiPasswordResetRequest(PDO $pdo, array $in): never {
    $email = mb_strtolower(trim($in['email'] ?? ''));
    if (!$email) respondError('Email obbligatoria');

    // crea tabella se non esiste
    $pdo->exec("CREATE TABLE IF NOT EXISTS password_reset_tokens (
        id         INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id    INTEGER NOT NULL,
        token      TEXT NOT NULL,
        expires_at TEXT NOT NULL,
        used       INTEGER DEFAULT 0,
        created_at TEXT DEFAULT (datetime('now')),
        FOREIGN KEY (user_id) REFERENCES users(id)
    )");

    $st = $pdo->prepare("SELECT id FROM users WHERE email = ?");
    $st->execute([$email]);
    $user = $st->fetch();

    // risposta generica per non rivelare se l'email esiste
    if (!$user) respond(['success' => true, 'message' => 'Se l\'email esiste riceverai le istruzioni.']);

    $token     = bin2hex(random_bytes(20));
    $expiresAt = date('Y-m-d H:i:s', time() + 3600);

    // invalida token precedenti
    $pdo->prepare("UPDATE password_reset_tokens SET used=1 WHERE user_id=? AND used=0")
        ->execute([$user['id']]);

    $pdo->prepare("INSERT INTO password_reset_tokens (user_id, token, expires_at) VALUES (?,?,?)")
        ->execute([$user['id'], $token, $expiresAt]);

    // in produzione qui si invierebbe l'email; per ora restituiamo il token in chiaro
    respond(['success' => true, 'token' => $token, 'expires_at' => $expiresAt]);
}

function apiPasswordResetDo(PDO $pdo, array $in): never {
    $token = trim($in['token'] ?? '');
    $pw    = $in['password'] ?? '';

    if (!$token)         respondError('Token obbligatorio');
    if (strlen($pw) < 6) respondError('Password minimo 6 caratteri');

    $pdo->exec("CREATE TABLE IF NOT EXISTS password_reset_tokens (
        id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL,
        token TEXT NOT NULL, expires_at TEXT NOT NULL, used INTEGER DEFAULT 0,
        created_at TEXT DEFAULT (datetime('now'))
    )");

    $st = $pdo->prepare("
        SELECT * FROM password_reset_tokens
        WHERE token=? AND used=0 AND expires_at > datetime('now')
    ");
    $st->execute([$token]);
    $row = $st->fetch();
    if (!$row) respondError('Token non valido o scaduto', 400);

    $hash = password_hash($pw, PASSWORD_BCRYPT);
    $pdo->prepare("UPDATE users SET password=? WHERE id=?")->execute([$hash, $row['user_id']]);
    $pdo->prepare("UPDATE password_reset_tokens SET used=1 WHERE id=?")->execute([$row['id']]);

    respond(['success' => true]);
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
