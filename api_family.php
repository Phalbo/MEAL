<?php
function apiFamilyCreate(PDO $pdo, array $in): never {
    $userId = $_SESSION['user_id'];
    $name   = trim($in['name'] ?? '');
    if (!$name) respondError('Nome famiglia obbligatorio');

    // un utente può avere una sola famiglia
    $check = $pdo->prepare("SELECT f.id FROM families f JOIN family_members fm ON fm.family_id=f.id WHERE fm.user_id=?");
    $check->execute([$userId]);
    if ($check->fetch()) respondError('Sei già in una famiglia', 409);

    $code = strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));

    $pdo->beginTransaction();
    $st = $pdo->prepare("INSERT INTO families (name, owner_id, invite_code) VALUES (?,?,?)");
    $st->execute([$name, $userId, $code]);
    $familyId = (int)$pdo->lastInsertId();

    $pdo->prepare("INSERT INTO family_members (family_id, user_id) VALUES (?,?)")
        ->execute([$familyId, $userId]);

    // profilo default per l'utente creatore
    $uSt = $pdo->prepare("SELECT name FROM users WHERE id=?");
    $uSt->execute([$userId]);
    $uName = $uSt->fetchColumn();
    $pdo->prepare("INSERT INTO family_profiles (family_id, name, type, portion_weight) VALUES (?,?,?,?)")
        ->execute([$familyId, $uName, 'adult', PORTION_ADULT]);

    // seed 18 piatti v1.0
    seedMeals($pdo, $familyId, $userId);

    $pdo->commit();

    $_SESSION['family_id'] = $familyId;

    $family = $pdo->prepare("SELECT * FROM families WHERE id=?");
    $family->execute([$familyId]);
    respond(['success' => true, 'family' => $family->fetch()]);
}

function apiFamilyJoin(PDO $pdo, array $in): never {
    $userId = $_SESSION['user_id'];
    $code   = strtoupper(trim($in['invite_code'] ?? ''));
    if (!$code) respondError('Codice invito obbligatorio');

    $fs = $pdo->prepare("SELECT * FROM families WHERE invite_code=?");
    $fs->execute([$code]);
    $family = $fs->fetch();
    if (!$family) respondError('Codice invito non valido', 404);

    // già membro?
    $chk = $pdo->prepare("SELECT id FROM family_members WHERE family_id=? AND user_id=?");
    $chk->execute([$family['id'], $userId]);
    if ($chk->fetch()) respondError('Sei già in questa famiglia', 409);

    $pdo->prepare("INSERT INTO family_members (family_id, user_id) VALUES (?,?)")
        ->execute([$family['id'], $userId]);

    // profilo default
    $uSt = $pdo->prepare("SELECT name FROM users WHERE id=?");
    $uSt->execute([$userId]);
    $uName = $uSt->fetchColumn();
    $pdo->prepare("INSERT INTO family_profiles (family_id, name, type, portion_weight) VALUES (?,?,?,?)")
        ->execute([$family['id'], $uName, 'adult', PORTION_ADULT]);

    $_SESSION['family_id'] = $family['id'];
    respond(['success' => true, 'family' => $family]);
}

function apiFamilyMembers(PDO $pdo): never {
    $familyId = $_SESSION['family_id'];
    $st = $pdo->prepare("
        SELECT u.id, u.name, u.email, u.avatar_emoji
        FROM users u
        JOIN family_members fm ON fm.user_id = u.id
        WHERE fm.family_id = ?
    ");
    $st->execute([$familyId]);
    respond($st->fetchAll());
}

// ── Profili ───────────────────────────────────────────────────────────────────
function apiProfilesList(PDO $pdo): never {
    $st = $pdo->prepare("
        SELECT fp.*, GROUP_CONCAT(i.label) as intolerance_labels
        FROM family_profiles fp
        LEFT JOIN intolerances i ON i.profile_id = fp.id
        WHERE fp.family_id = ?
        GROUP BY fp.id ORDER BY fp.id
    ");
    $st->execute([$_SESSION['family_id']]);
    $rows = $st->fetchAll();
    foreach ($rows as &$r)
        $r['intolerances'] = $r['intolerance_labels'] ? explode(',', $r['intolerance_labels']) : [];
    respond($rows);
}

function apiProfilesAdd(PDO $pdo, array $in): never {
    $familyId = $_SESSION['family_id'];
    $name     = trim($in['name'] ?? '');
    if (!$name) respondError('Nome obbligatorio');
    $type   = in_array($in['type'] ?? '', ['adult','child']) ? $in['type'] : 'adult';
    $weight = (float)($in['portion_weight'] ?? ($type === 'child' ? PORTION_CHILD : PORTION_ADULT));
    $emoji  = trim($in['avatar_emoji'] ?? '👤') ?: '👤';

    $st = $pdo->prepare("INSERT INTO family_profiles (family_id,name,type,portion_weight,avatar_emoji) VALUES (?,?,?,?,?)");
    $st->execute([$familyId, $name, $type, $weight, $emoji]);
    respond(['success' => true, 'id' => (int)$pdo->lastInsertId()]);
}

function apiProfilesUpdate(PDO $pdo, array $in): never {
    $id = (int)($in['id'] ?? 0);
    if (!$id) respondError('ID mancante');
    $fields = [];
    $params = [];
    foreach (['name','type','portion_weight','avatar_emoji'] as $f) {
        if (isset($in[$f])) { $fields[] = "$f=?"; $params[] = $in[$f]; }
    }
    if (!$fields) respondError('Nessun campo da aggiornare');
    $params[] = $id;
    $params[] = $_SESSION['family_id'];
    $pdo->prepare("UPDATE family_profiles SET " . implode(',', $fields) . " WHERE id=? AND family_id=?")
        ->execute($params);
    respond(['success' => true]);
}

function apiProfilesDelete(PDO $pdo, array $in): never {
    $id = (int)($in['id'] ?? 0);
    if (!$id) respondError('ID mancante');
    $pdo->prepare("DELETE FROM family_profiles WHERE id=? AND family_id=?")
        ->execute([$id, $_SESSION['family_id']]);
    respond(['success' => true]);
}

// ── Intolleranze ─────────────────────────────────────────────────────────────
function apiIntoleranceAdd(PDO $pdo, array $in): never {
    $profileId = (int)($in['profile_id'] ?? 0);
    $label     = trim($in['label'] ?? '');
    if (!$profileId || !$label) respondError('profile_id e label obbligatori');
    $pdo->prepare("INSERT INTO intolerances (profile_id, label) VALUES (?,?)")
        ->execute([$profileId, $label]);
    respond(['success' => true, 'id' => (int)$pdo->lastInsertId()]);
}

function apiIntoleranceDelete(PDO $pdo, array $in): never {
    $id = (int)($in['id'] ?? 0);
    if (!$id) respondError('ID mancante');
    $pdo->prepare("DELETE FROM intolerances WHERE id=?")->execute([$id]);
    respond(['success' => true]);
}

function apiGetShareToken(PDO $pdo): never {
    $familyId = $_SESSION['family_id'];
    $st = $pdo->prepare("SELECT share_token FROM families WHERE id=?");
    $st->execute([$familyId]);
    $token = $st->fetchColumn();
    if (!$token) {
        $token = bin2hex(random_bytes(16));
        $pdo->prepare("UPDATE families SET share_token=? WHERE id=?")->execute([$token, $familyId]);
    }
    respond(['token' => $token]);
}

function apiIntoleranceListByProfile(PDO $pdo): never {
    $profileId = (int)($_GET['profile_id'] ?? 0);
    if (!$profileId) respondError('profile_id obbligatorio');
    $st = $pdo->prepare("SELECT id, label FROM intolerances WHERE profile_id=? ORDER BY id");
    $st->execute([$profileId]);
    respond($st->fetchAll());
}
