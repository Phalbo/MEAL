<?php
function apiScheduleAutofill(PDO $pdo, array $in): never {
    $familyId  = $_SESSION['family_id'];
    $weekStart = $in['week_start'] ?? '';
    if (!$weekStart) respondError('week_start obbligatorio');

    $slotRules = [
        'colazione' => ['Colazione'],
        'pranzo'    => ['Primo', 'Secondo', 'Altro'],
        'cena'      => ['Secondo', 'Altro'],   // no Primo a cena
    ];

    // piatti disponibili (famiglia + sistema)
    $ms = $pdo->prepare("SELECT m.id, m.name, m.emoji, mc.name as category
        FROM meals m LEFT JOIN meal_categories mc ON mc.id = m.category_id
        WHERE (m.family_id = ? OR m.is_system = 1)");
    $ms->execute([$familyId]);
    $byCategory = [];
    foreach ($ms->fetchAll() as $m) $byCategory[$m['category']][] = $m;

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

    $sideDish  = $in['side_dish']  !== '' ? ($in['side_dish']  ?? null) : null;
    $extraNote = $in['extra_note'] !== '' ? ($in['extra_note'] ?? null) : null;

    // Upsert: se la cella non esiste ancora (nessun pasto) la creiamo vuota
    $pdo->prepare("
        INSERT INTO schedule (family_id, week_start, day_index, slot, side_dish, extra_note, created_by)
        VALUES (?,?,?,?,?,?,?)
        ON CONFLICT(family_id, week_start, day_index, slot)
        DO UPDATE SET side_dish=excluded.side_dish, extra_note=excluded.extra_note
    ")->execute([$familyId, $weekStart, $dayIndex, $slot, $sideDish, $extraNote, $_SESSION['user_id']]);

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

    $ph   = implode(',', array_fill(0, count($allowedCats), '?'));
    $args = array_merge([$familyId], $allowedCats);
    $ms   = $pdo->prepare("SELECT m.id FROM meals m
        LEFT JOIN meal_categories mc ON mc.id = m.category_id
        WHERE (m.family_id=? OR m.is_system=1) AND mc.name IN ($ph)");
    $ms->execute($args);
    $candidates = array_column($ms->fetchAll(), 'id');

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
               mc.name as category, s.id as schedule_id
        FROM meals m
        LEFT JOIN meal_categories mc ON mc.id = m.category_id
        LEFT JOIN schedule s ON s.meal_id = m.id
            AND s.family_id=? AND s.week_start=? AND s.day_index=? AND s.slot=?
        WHERE m.id=?");
    $row->execute([$familyId, $weekStart, $dayIndex, $slot, $newId]);
    respond($row->fetch());
}
