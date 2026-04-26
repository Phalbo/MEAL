<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit(0); }

$dataFile = __DIR__ . '/data/meals.json';

function loadMeals(string $file): array {
    if (!file_exists($file)) return [];
    $content = file_get_contents($file);
    return json_decode($content, true) ?? [];
}

function saveMeals(string $file, array $meals): void {
    file_put_contents($file, json_encode(array_values($meals), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
}

function respond(mixed $data): never {
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

// Input
$action = $_GET['action'] ?? '';
$input  = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $raw = file_get_contents('php://input');
    $input = json_decode($raw, true) ?? [];
    if (!$action) $action = $input['action'] ?? '';
}

// ─── GET list ──────────────────────────────────────────────────────────────
if ($action === 'list') {
    respond(loadMeals($dataFile));
}

// ─── POST add ──────────────────────────────────────────────────────────────
if ($action === 'add') {
    $meals = loadMeals($dataFile);
    $ids   = array_column($meals, 'id');
    $newId = $ids ? max($ids) + 1 : 1;

    $meal = [
        'id'          => $newId,
        'name'        => trim($input['name'] ?? ''),
        'cal'         => (int)($input['cal'] ?? 0),
        'emoji'       => trim($input['emoji'] ?? '🍽️'),
        'category'    => trim($input['category'] ?? 'Altro'),
        'ingredients' => array_filter(array_map('trim', $input['ingredients'] ?? []))
    ];

    if (!$meal['name']) respond(['error' => 'Nome mancante']);

    $meals[] = $meal;
    saveMeals($dataFile, $meals);
    respond(['success' => true, 'meal' => $meal]);
}

// ─── POST update ───────────────────────────────────────────────────────────
if ($action === 'update') {
    $id    = (int)($input['id'] ?? 0);
    $meals = loadMeals($dataFile);
    $found = false;

    foreach ($meals as &$meal) {
        if ($meal['id'] === $id) {
            $meal['name']        = trim($input['name']     ?? $meal['name']);
            $meal['cal']         = (int)($input['cal']     ?? $meal['cal']);
            $meal['emoji']       = trim($input['emoji']    ?? $meal['emoji']);
            $meal['category']    = trim($input['category'] ?? $meal['category']);
            $meal['ingredients'] = array_filter(array_map('trim', $input['ingredients'] ?? $meal['ingredients']));
            $found = true;
            break;
        }
    }
    unset($meal);

    if (!$found) respond(['error' => 'Piatto non trovato']);
    saveMeals($dataFile, $meals);
    respond(['success' => true]);
}

// ─── POST delete ───────────────────────────────────────────────────────────
if ($action === 'delete') {
    $id    = (int)($input['id'] ?? 0);
    $meals = loadMeals($dataFile);
    $meals = array_values(array_filter($meals, fn($m) => $m['id'] !== $id));
    saveMeals($dataFile, $meals);
    respond(['success' => true]);
}

respond(['error' => 'Azione non riconosciuta']);
