<?php
session_start();
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/nutrition_functions.php';
require_once __DIR__ . '/api_schema.php';
require_once __DIR__ . '/api_auth.php';
require_once __DIR__ . '/api_family.php';
require_once __DIR__ . '/api_meals.php';
require_once __DIR__ . '/api_schedule.php';
require_once __DIR__ . '/api_shopping.php';
require_once __DIR__ . '/api_pantry.php';

header('Content-Type: application/json; charset=utf-8');

// ── Helpers ──────────────────────────────────────────────────────────────────
function respond(mixed $data, int $status = 200): never {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}
function respondError(string $msg, int $status = 400): never {
    respond(['error' => $msg], $status);
}
function requireAuth(): void {
    if (empty($_SESSION['user_id'])) respondError('Non autenticato', 401);
}
function requireFamily(): void {
    requireAuth();
    if (empty($_SESSION['family_id'])) respondError('Nessuna famiglia', 403);
}
function getCsrfToken(): string {
    if (empty($_SESSION['csrf_token']))
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf_token'];
}
function verifyCsrf(string $token): void {
    if (!hash_equals(getCsrfToken(), $token))
        respondError('CSRF token non valido', 403);
}

// ── DB + Schema ───────────────────────────────────────────────────────────────
function getDB(): PDO {
    static $pdo = null;
    if ($pdo) return $pdo;
    $pdo = new PDO('sqlite:' . DB_PATH);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec('PRAGMA journal_mode = WAL');
    initSchema($pdo);
    return $pdo;
}

function detectZone(string $name): string {
    $n = mb_strtolower(trim($name));
    $map = [
        'ortofrutta' => ['aglio','cipolla','carota','sedano','zucchine','pomodor','basilico',
                         'prezzemolo','menta','rosmarino','limone','funghi',
                         'melanzane','patate','insalata','spinaci','rucola'],
        'pane'       => ['pane','cornetti','crackers','grissini','fette biscottate'],
        'macelleria' => ['carne','macinata','bistecca','pollo','guanciale',
                         'pancetta','salsiccia','prosciutto','salame'],
        'pesce'      => ['salmone','merluzzo','gamberi','vongole','acciughe','tonno'],
        'latticini'  => ['mozzarella','parmigiano','pecorino','burro',
                         'yogurt','latte','uova','panna','ricotta'],
        'scaffali'   => ['spaghetti','penne','pasta','riso','olio',
                         'passata','pelati','fagioli','lenticchie',
                         'sale','pepe','brodo','dado'],
        'bevande'    => ['acqua','vino','birra','succo','aranciata'],
        'surgelati'  => ['surgelat','gelato','piselli surgelati'],
    ];
    foreach ($map as $zone => $keywords)
        foreach ($keywords as $kw)
            if (str_contains($n, $kw)) return $zone;
    return 'scaffali';
}

// ── Input ────────────────────────────────────────────────────────────────────
$action = $_GET['action'] ?? '';
$input  = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $raw   = file_get_contents('php://input');
    $input = json_decode($raw, true) ?? [];
    if (!$action) $action = $input['action'] ?? '';
}

$pdo = getDB();

// Public (no auth, no CSRF)
$public = ['login', 'register', 'shopping_list_pub', 'shopping_check_pub'];
// Auth required but no family needed
$noFamily = ['logout', 'me', 'csrf_token', 'family_create', 'family_join'];

if (!in_array($action, $public)) requireAuth();
if (!in_array($action, array_merge($public, $noFamily))) requireFamily();
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !in_array($action, $public))
    verifyCsrf($input['csrf_token'] ?? '');

// ── Router ────────────────────────────────────────────────────────────────────
match ($action) {
    'csrf_token'           => respond(['token' => getCsrfToken()]),
    'login'                => apiLogin($pdo, $input),
    'register'             => apiRegister($pdo, $input),
    'logout'               => apiLogout(),
    'me'                   => apiMe($pdo),
    'family_create'        => apiFamilyCreate($pdo, $input),
    'family_join'          => apiFamilyJoin($pdo, $input),
    'family_members'       => apiFamilyMembers($pdo),
    'profiles_list'        => apiProfilesList($pdo),
    'profiles_add'         => apiProfilesAdd($pdo, $input),
    'profiles_update'      => apiProfilesUpdate($pdo, $input),
    'profiles_delete'      => apiProfilesDelete($pdo, $input),
    'intolerance_add'             => apiIntoleranceAdd($pdo, $input),
    'intolerance_delete'          => apiIntoleranceDelete($pdo, $input),
    'intolerance_list_by_profile' => apiIntoleranceListByProfile($pdo),
    'family_share_token'          => apiGetShareToken($pdo),
    'categories_list'      => apiCategoriesList($pdo),
    'meals_list'           => apiMealsList($pdo),
    'meals_add'            => apiMealsAdd($pdo, $input),
    'meals_update'         => apiMealsUpdate($pdo, $input),
    'meals_delete'         => apiMealsDelete($pdo, $input),
    'nutrition_lookup'     => apiNutritionLookup($pdo),
    'schedule_get'         => apiScheduleGet($pdo),
    'schedule_set'         => apiScheduleSet($pdo, $input),
    'schedule_clear'       => apiScheduleClear($pdo, $input),
    'schedule_exception'   => apiScheduleException($pdo, $input),
    'schedule_copy'        => apiScheduleCopy($pdo, $input),
    'schedule_autofill'    => apiScheduleAutofill($pdo, $input),
    'shopping_generate'    => apiShoppingGenerate($pdo, $input),
    'shopping_list'        => apiShoppingList($pdo),
    'shopping_check'       => apiShoppingCheck($pdo, $input),
    'shopping_price_update'=> apiShoppingPriceUpdate($pdo, $input),
    'shopping_reset_checks' => apiShoppingResetChecks($pdo, $input),
    'shopping_export_text'  => apiShoppingExportText($pdo),
    'shopping_list_pub'     => apiShoppingListPub($pdo),
    'shopping_check_pub'    => apiShoppingCheckPub($pdo, $input),
    'pantry_list'           => apiPantryList($pdo),
    'pantry_update'         => apiPantryUpdate($pdo, $input),
    'pantry_delete'         => apiPantryDelete($pdo, $input),
    'pantry_from_shopping'  => apiPantryFromShopping($pdo, $input),
    'pantry_consume'        => apiPantryConsume($pdo, $input),
    default                => respondError('Azione non riconosciuta', 404),
};
