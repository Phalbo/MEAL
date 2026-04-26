<?php
function apiScheduleGet(PDO $pdo): never {
    $familyId  = $_SESSION['family_id'];
    $weekStart = $_GET['week_start'] ?? '';
    if (!$weekStart) respondError('week_start obbligatorio');

    $st = $pdo->prepare("
        SELECT s.id, s.day_index, s.slot, s.meal_id,
               s.is_exception, s.exception_note,
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

    // verifica che il piatto appartenga alla famiglia
    $own = $pdo->prepare("SELECT id FROM meals WHERE id=? AND family_id=?");
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
