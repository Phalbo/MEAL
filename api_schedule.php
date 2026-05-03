<?php

// Ritorna set di meal_id da escludere per intolleranze familiari
function getConflictingMealIds(PDO $pdo, int $familyId): array {
    // tutte le etichette intolleranza della famiglia
    $st = $pdo->prepare("
        SELECT DISTINCT LOWER(i.label) as label
        FROM intolerances i
        JOIN family_profiles fp ON fp.id = i.profile_id
        WHERE fp.family_id = ?
    ");
    $st->execute([$familyId]);
    $labels = array_column($st->fetchAll(), 'label');
    if (!$labels) return [];

    // ingredienti con flag non vuoti
    $rows = $pdo->query("SELECT meal_id, intolerance_flags FROM meal_ingredients
        WHERE intolerance_flags IS NOT NULL AND intolerance_flags != ''")->fetchAll();

    $conflicting = [];
    foreach ($rows as $row) {
        $flags = array_map('trim', array_map('mb_strtolower', explode(',', $row['intolerance_flags'])));
        if (array_intersect($flags, $labels)) {
            $conflicting[$row['meal_id']] = true;
        }
    }
    return array_keys($conflicting);
}

function apiScheduleAutofill(PDO $pdo, array $in): never {
    $familyId  = $_SESSION['family_id'];
    $weekStart = $in['week_start'] ?? '';
    if (!$weekStart) respondError('week_start obbligatorio');

    $slotRules = [
        'colazione' => ['Colazione'],
        'pranzo'    => ['Primo', 'Secondo', 'Altro'],
        'cena'      => ['Secondo', 'Altro'],   // no Primo a cena
    ];

    $conflicting = getConflictingMealIds($pdo, $familyId);

    // piatti disponibili (famiglia + sistema), esclusi quelli con conflitti
    $ms = $pdo->prepare("SELECT m.id, m.name, m.emoji, mc.name as category
        FROM meals m LEFT JOIN meal_categories mc ON mc.id = m.category_id
        WHERE (m.family_id = ? OR m.is_system = 1)");
    $ms->execute([$familyId]);
    $byCategory = [];
    foreach ($ms->fetchAll() as $m) {
        if (in_array($m['id'], $conflicting)) continue;
        $byCategory[$m['category']][] = $m;
    }

    // slot già occupati
    $ex = $pdo->prepare("SELECT day_index, slot FROM schedule
        WHERE family_id=? AND week_start=? AND meal_id IS NOT NULL");
    $ex->execute([$familyId, $weekStart]);
    $occupied = [];
    foreach ($ex->fetchAll() as $r) $occupied[$r['day_index'].'_'.$r['slot']] = true;

    $ins = $pdo->prepare("INSERT OR IGNORE INTO schedule
        (family_id, week_start, day_index, slot, meal_id, created_by)
        VALUES (?,?,?,?,?,?)
        ON CONFLICT(family_id,week_start,day_index,slot) DO NOTHING");

    $filled = 0;
    $usedYesterday = [];

    for ($day = 0; $day < 7; $day++) {
        $todayCategories = [];
        foreach (['colazione','pranzo','cena'] as $slot) {
            if (isset($occupied[$day.'_'.$slot])) continue;
            $candidates = [];
            foreach ($slotRules[$slot] as $cat)
                if (!empty($byCategory[$cat])) $candidates = array_merge($candidates, $byCategory[$cat]);
            if (!$candidates) continue;
            $yesterdayCat = $usedYesterday[$slot] ?? null;
            $filtered = array_values(array_filter($candidates, fn($m) => $m['category'] !== $yesterdayCat));
            $pick = ($filtered ?: $candidates)[array_rand($filtered ?: $candidates)];
            $ins->execute([$familyId, $weekStart, $day, $slot, $pick['id'], $_SESSION['user_id']]);
            $todayCategories[$slot] = $pick['category'];
            $filled++;
        }
        $usedYesterday = $todayCategories;
    }
    respond(['success' => true, 'filled' => $filled]);
}

function apiScheduleGet(PDO $pdo): never {
    $familyId  = $_SESSION['family_id'];
    $weekStart = $_GET['week_start'] ?? '';
    if (!$weekStart) respondError('week_start obbligatorio');

    $st = $pdo->prepare("
        SELECT s.id, s.day_index, s.slot, s.meal_id,
               s.is_exception, s.exception_note,
               s.side_dish, s.extra_note,
               s.slot_kids, s.portions_override,
               m.name, m.emoji, m.cal_per_adult,
               mc.name as category
        FROM schedule s
        LEFT JOIN meals m  ON m.id  = s.meal_id
        LEFT JOIN meal_categories mc ON mc.id = m.category_id
        WHERE s.family_id = ? AND s.week_start = ?
        ORDER BY s.day_index, s.slot
    ");
    $st->execute([$familyId, $weekStart]);
    respond($st->fetchAll());
}

function apiScheduleCopyPrev(PDO $pdo, array $in): never {
    $familyId  = $_SESSION['family_id'];
    $weekStart = $in['week_start'] ?? '';
    if (!$weekStart) respondError('week_start obbligatorio');

    $prevWeek = date('Y-m-d', strtotime($weekStart . ' -7 days'));

    $src = $pdo->prepare("SELECT day_index, slot, meal_id, slot_kids FROM schedule
        WHERE family_id=? AND week_start=? AND meal_id IS NOT NULL");
    $src->execute([$familyId, $prevWeek]);
    $rows = $src->fetchAll();
    if (!$rows) respond(['copied' => 0]);

    // INSERT OR IGNORE — non sovrascrive celle già occupate
    $ins = $pdo->prepare("INSERT OR IGNORE INTO schedule
        (family_id, week_start, day_index, slot, meal_id, slot_kids, created_by)
        VALUES (?,?,?,?,?,?,?)");
    $copied = 0;
    foreach ($rows as $row) {
        $ins->execute([$familyId, $weekStart, $row['day_index'], $row['slot'],
                       $row['meal_id'], $row['slot_kids'], $_SESSION['user_id']]);
        if ($pdo->lastInsertId()) $copied++;
    }
    respond(['copied' => $copied]);
}

function apiScheduleSetKids(PDO $pdo, array $in): never {
    $familyId  = $_SESSION['family_id'];
    $weekStart = $in['week_start'] ?? '';
    $dayIndex  = isset($in['day_index']) ? (int)$in['day_index'] : null;
    $slot      = $in['slot'] ?? '';
    $slotKids  = isset($in['slot_kids']) && $in['slot_kids'] !== '' ? trim($in['slot_kids']) : null;
    if (!$weekStart || $dayIndex === null || !$slot) respondError('week_start, day_index, slot obbligatori');

    $pdo->prepare("
        INSERT INTO schedule (family_id, week_start, day_index, slot, slot_kids, created_by)
        VALUES (?,?,?,?,?,?)
        ON CONFLICT(family_id, week_start, day_index, slot)
        DO UPDATE SET slot_kids=excluded.slot_kids
    ")->execute([$familyId, $weekStart, $dayIndex, $slot, $slotKids, $_SESSION['user_id']]);

    respond(['success' => true]);
}

function apiScheduleSet(PDO $pdo, array $in): never {
    $familyId  = $_SESSION['family_id'];
    $userId    = $_SESSION['user_id'];
    $weekStart = $in['week_start'] ?? '';
    $dayIndex  = isset($in['day_index']) ? (int)$in['day_index'] : null;
    $slot      = $in['slot'] ?? '';
    $mealId    = isset($in['meal_id']) && $in['meal_id'] !== null ? (int)$in['meal_id'] : null;

    if (!$weekStart)          respondError('week_start obbligatorio');
    if ($dayIndex === null)   respondError('day_index obbligatorio');
    if (!in_array($slot, ['colazione','pranzo','cena'])) respondError('slot non valido');

    if ($mealId === null) {
        // rimozione cella
        $pdo->prepare("DELETE FROM schedule WHERE family_id=? AND week_start=? AND day_index=? AND slot=?")
            ->execute([$familyId, $weekStart, $dayIndex, $slot]);
        respond(['success' => true]);
    }

    // verifica che il piatto appartenga alla famiglia o sia un piatto di sistema
    $own = $pdo->prepare("SELECT id FROM meals WHERE id=? AND (family_id=? OR is_system=1)");
    $own->execute([$mealId, $familyId]);
    if (!$own->fetch()) respondError('Piatto non trovato', 404);

    $pdo->prepare("
        INSERT INTO schedule (family_id, week_start, day_index, slot, meal_id, created_by)
        VALUES (?,?,?,?,?,?)
        ON CONFLICT (family_id, week_start, day_index, slot)
        DO UPDATE SET meal_id=excluded.meal_id, created_by=excluded.created_by,
                      is_exception=0, exception_note=NULL
    ")->execute([$familyId, $weekStart, $dayIndex, $slot, $mealId, $userId]);

    respond(['success' => true]);
}

function apiScheduleClear(PDO $pdo, array $in): never {
    $familyId  = $_SESSION['family_id'];
    $weekStart = $in['week_start'] ?? '';
    if (!$weekStart) respondError('week_start obbligatorio');

    $pdo->prepare("DELETE FROM schedule WHERE family_id=? AND week_start=?")
        ->execute([$familyId, $weekStart]);
    respond(['success' => true]);
}

function apiScheduleCopy(PDO $pdo, array $in): never {
    $familyId = $_SESSION['family_id'];
    $fromWeek = $in['from_week'] ?? '';
    $toWeek   = $in['to_week']   ?? '';
    if (!$fromWeek || !$toWeek) respondError('from_week e to_week obbligatori');
    if ($fromWeek === $toWeek) respondError('Le settimane devono essere diverse');

    $src = $pdo->prepare("SELECT day_index, slot, meal_id FROM schedule
        WHERE family_id=? AND week_start=? AND meal_id IS NOT NULL");
    $src->execute([$familyId, $fromWeek]);
    $rows = $src->fetchAll();
    if (!$rows) respond(['copied' => 0]);

    $pdo->prepare("DELETE FROM schedule WHERE family_id=? AND week_start=?")
        ->execute([$familyId, $toWeek]);

    $ins = $pdo->prepare("INSERT INTO schedule (family_id, week_start, day_index, slot, meal_id, created_by)
        VALUES (?,?,?,?,?,?)");
    foreach ($rows as $row)
        $ins->execute([$familyId, $toWeek, $row['day_index'], $row['slot'], $row['meal_id'], $_SESSION['user_id']]);

    respond(['copied' => count($rows)]);
}

function apiScheduleException(PDO $pdo, array $in): never {
    $scheduleId    = (int)($in['schedule_id']    ?? 0);
    $exceptionNote = trim($in['exception_note']  ?? '');
    $isException   = (int)($in['is_exception']   ?? 1);
    if (!$scheduleId) respondError('schedule_id obbligatorio');

    $pdo->prepare("
        UPDATE schedule SET is_exception=?, exception_note=?
        WHERE id=? AND family_id=?
    ")->execute([$isException, $exceptionNote ?: null, $scheduleId, $_SESSION['family_id']]);

    respond(['success' => true]);
}

function apiScheduleUpdateExtras(PDO $pdo, array $in): never {
    $familyId  = $_SESSION['family_id'];
    $weekStart = $in['week_start'] ?? '';
    $dayIndex  = isset($in['day_index']) ? (int)$in['day_index'] : null;
    $slot      = $in['slot'] ?? '';
    if (!$weekStart || $dayIndex === null || !$slot) respondError('week_start, day_index, slot obbligatori');

    $sideDish        = isset($in['side_dish'])        && $in['side_dish']        !== '' ? $in['side_dish']        : null;
    $extraNote       = isset($in['extra_note'])       && $in['extra_note']       !== '' ? $in['extra_note']       : null;
    $portionsOverride= array_key_exists('portions_override', $in)
                       ? (($in['portions_override'] !== '' && $in['portions_override'] !== null) ? (float)$in['portions_override'] : null)
                       : false; // false = non modificare

    // Upsert: se la cella non esiste ancora (nessun pasto) la creiamo vuota
    if ($portionsOverride !== false) {
        $pdo->prepare("
            INSERT INTO schedule (family_id, week_start, day_index, slot, side_dish, extra_note, portions_override, created_by)
            VALUES (?,?,?,?,?,?,?,?)
            ON CONFLICT(family_id, week_start, day_index, slot)
            DO UPDATE SET side_dish=excluded.side_dish, extra_note=excluded.extra_note, portions_override=excluded.portions_override
        ")->execute([$familyId, $weekStart, $dayIndex, $slot, $sideDish, $extraNote, $portionsOverride, $_SESSION['user_id']]);
    } else {
        $pdo->prepare("
            INSERT INTO schedule (family_id, week_start, day_index, slot, side_dish, extra_note, created_by)
            VALUES (?,?,?,?,?,?,?)
            ON CONFLICT(family_id, week_start, day_index, slot)
            DO UPDATE SET side_dish=excluded.side_dish, extra_note=excluded.extra_note
        ")->execute([$familyId, $weekStart, $dayIndex, $slot, $sideDish, $extraNote, $_SESSION['user_id']]);
    }

    respond(['success' => true]);
}

function apiScheduleRandomReplace(PDO $pdo, array $in): never {
    $familyId  = $_SESSION['family_id'];
    $weekStart = $in['week_start'] ?? '';
    $dayIndex  = isset($in['day_index']) ? (int)$in['day_index'] : null;
    $slot      = $in['slot'] ?? '';
    if (!$weekStart || $dayIndex === null || !$slot) respondError('week_start, day_index, slot obbligatori');

    $allowedCats = match($slot) {
        'colazione' => ['Colazione'],
        'pranzo'    => ['Primo', 'Secondo', 'Altro'],
        'cena'      => ['Secondo', 'Altro'],
        default     => ['Secondo', 'Altro'],
    };

    // piatto corrente
    $curr = $pdo->prepare("SELECT meal_id FROM schedule
        WHERE family_id=? AND week_start=? AND day_index=? AND slot=?");
    $curr->execute([$familyId, $weekStart, $dayIndex, $slot]);
    $currentMealId = (int)($curr->fetchColumn() ?: 0);

    // piatti già in uso questa settimana
    $usedSt = $pdo->prepare("SELECT DISTINCT meal_id FROM schedule
        WHERE family_id=? AND week_start=? AND meal_id IS NOT NULL");
    $usedSt->execute([$familyId, $weekStart]);
    $usedIds = array_column($usedSt->fetchAll(), 'meal_id');

    $conflicting = getConflictingMealIds($pdo, $familyId);

    $ph   = implode(',', array_fill(0, count($allowedCats), '?'));
    $args = array_merge([$familyId], $allowedCats);
    $ms   = $pdo->prepare("SELECT m.id FROM meals m
        LEFT JOIN meal_categories mc ON mc.id = m.category_id
        WHERE (m.family_id=? OR m.is_system=1) AND mc.name IN ($ph)");
    $ms->execute($args);
    $candidates = array_values(array_diff(
        array_column($ms->fetchAll(), 'id'),
        $conflicting
    ));

    // preferisce piatti nuovi, poi esclude solo il corrente
    $fresh = array_values(array_diff($candidates, $usedIds));
    $pool  = $fresh ?: array_values(array_diff($candidates, [$currentMealId]));
    if (!$pool) $pool = $candidates;
    if (!$pool) respondError('Nessun piatto disponibile');

    $newId = $pool[array_rand($pool)];

    $pdo->prepare("INSERT INTO schedule (family_id,week_start,day_index,slot,meal_id,created_by)
        VALUES (?,?,?,?,?,?)
        ON CONFLICT(family_id,week_start,day_index,slot)
        DO UPDATE SET meal_id=excluded.meal_id, created_by=excluded.created_by
    ")->execute([$familyId, $weekStart, $dayIndex, $slot, $newId, $_SESSION['user_id']]);

    $row = $pdo->prepare("SELECT m.id, m.name, m.emoji, m.cal_per_adult,
               mc.name as category, s.id as schedule_id,
               s.side_dish, s.extra_note, s.slot_kids, s.portions_override
        FROM meals m
        LEFT JOIN meal_categories mc ON mc.id = m.category_id
        LEFT JOIN schedule s ON s.meal_id = m.id
            AND s.family_id=? AND s.week_start=? AND s.day_index=? AND s.slot=?
        WHERE m.id=?");
    $row->execute([$familyId, $weekStart, $dayIndex, $slot, $newId]);
    respond($row->fetch());
}
