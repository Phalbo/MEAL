<?php
// Importa ricette.xlsx → SQLite (is_system=1, family_id=1)
// Uso: php data/import_ricette.php
require_once __DIR__ . '/../config.php';

$pdo = new PDO('sqlite:' . DB_PATH);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$pdo->exec('PRAGMA foreign_keys = OFF'); // import di sistema, family_id=1 placeholder
$pdo->exec('PRAGMA journal_mode = WAL');

// Assicura che la colonna is_system esista
try { $pdo->exec("ALTER TABLE meals ADD COLUMN is_system INTEGER DEFAULT 0"); } catch (Exception $e) {}

// Mappa categoria nome → id
$catMap = ['colazione' => 1, 'primo' => 2, 'secondo' => 3, 'contorno' => 4, 'altro' => 5];

function detectZone(string $name): string {
    $n = mb_strtolower(trim($name));
    $map = [
        'ortofrutta' => ['aglio','cipolla','carota','sedano','zucchine','pomodor','basilico',
                         'prezzemolo','menta','rosmarino','limone','funghi','melanzane','patate',
                         'insalata','spinaci','rucola'],
        'pane'       => ['pane','cornetti','crackers','grissini','fette biscottate'],
        'macelleria' => ['carne','macinata','bistecca','pollo','guanciale','pancetta',
                         'salsiccia','prosciutto','salame'],
        'pesce'      => ['salmone','merluzzo','gamberi','vongole','acciughe','tonno'],
        'latticini'  => ['mozzarella','parmigiano','pecorino','burro','yogurt','latte',
                         'uova','panna','ricotta'],
        'scaffali'   => ['spaghetti','penne','pasta','riso','olio','passata','pelati',
                         'fagioli','lenticchie','sale','pepe','brodo','dado'],
        'bevande'    => ['acqua','vino','birra','succo','aranciata'],
        'surgelati'  => ['surgelat','gelato'],
    ];
    foreach ($map as $zone => $keywords)
        foreach ($keywords as $kw)
            if (str_contains($n, $kw)) return $zone;
    return 'scaffali';
}

// Leggi xlsx come ZIP
$xlsx = __DIR__ . '/../ricette.xlsx';
if (!file_exists($xlsx)) { echo "Errore: ricette.xlsx non trovato in " . dirname($xlsx) . "\n"; exit(1); }

$z = new ZipArchive;
if ($z->open($xlsx) !== true) { echo "Errore: impossibile aprire ricette.xlsx\n"; exit(1); }
$xml = $z->getFromName('xl/worksheets/sheet1.xml');
$z->close();

$dom = new DOMDocument;
$dom->loadXML($xml);
$xp  = new DOMXPath($dom);
$ns  = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
$xp->registerNamespace('x', $ns);

// Helper: valore cella
function cellValue(DOMXPath $xp, DOMElement $cell): string {
    $t = $cell->getAttribute('t');
    if ($t === 'inlineStr') {
        $nodes = $xp->query('x:is/x:t', $cell);
        $val = '';
        foreach ($nodes as $n) $val .= $n->nodeValue;
        return $val;
    }
    $v = $xp->query('x:v', $cell);
    return $v->length ? $v->item(0)->nodeValue : '';
}

// Helper: mappa colonna lettera → valore
function rowCols(DOMXPath $xp, DOMElement $row): array {
    $cols = [];
    foreach ($xp->query('x:c', $row) as $cell) {
        $ref = $cell->getAttribute('r');
        $col = rtrim($ref, '0123456789');
        $cols[$col] = cellValue($xp, $cell);
    }
    return $cols;
}

// Pulizia: rimuove le system meals esistenti per reimportare idempotente
$pdo->exec("DELETE FROM meal_ingredients WHERE meal_id IN (SELECT id FROM meals WHERE is_system=1 AND family_id=1)");
$pdo->exec("DELETE FROM meals WHERE is_system=1 AND family_id=1");

$insMeal = $pdo->prepare(
    "INSERT INTO meals (family_id, name, emoji, category_id, cal_per_adult, notes, is_system, created_by)
     VALUES (1, ?, ?, ?, ?, ?, 1, NULL)"
);
$insIng = $pdo->prepare(
    "INSERT INTO meal_ingredients (meal_id, name, quantity, unit, zone)
     VALUES (?, ?, ?, ?, ?)"
);

$rows      = $xp->query('//x:sheetData/x:row');
$mealCount = 0;
$ingCount  = 0;

$pdo->beginTransaction();
foreach ($rows as $row) {
    if ((int)$row->getAttribute('r') === 1) continue; // skip header

    $cols = rowCols($xp, $row);
    $nome = trim($cols['B'] ?? '');
    if (!$nome) continue;

    $emoji  = trim($cols['C'] ?? '') ?: '🍽️';
    $catKey = mb_strtolower(trim($cols['D'] ?? ''));
    $catId  = $catMap[$catKey] ?? 5;
    $kcal   = (int)($cols['E'] ?? 0);
    $ingRaw = trim($cols['F'] ?? '');
    $note   = trim($cols['G'] ?? '') ?: null;

    $insMeal->execute([$nome, $emoji, $catId, $kcal, $note]);
    $mealId = (int)$pdo->lastInsertId();
    $mealCount++;

    if ($ingRaw) {
        foreach (explode("\n", $ingRaw) as $line) {
            $parts   = array_map('trim', explode('|', $line));
            $ingName = $parts[0] ?? '';
            if (!$ingName) continue;
            $qty  = isset($parts[1]) && $parts[1] !== '' ? (float)str_replace(',', '.', $parts[1]) : null;
            $unit = isset($parts[2]) && $parts[2] !== '' ? $parts[2] : null;
            $zone = detectZone($ingName);
            $insIng->execute([$mealId, $ingName, $qty, $unit, $zone]);
            $ingCount++;
        }
    }
}
$pdo->commit();

echo "✅ Importate $mealCount ricette, $ingCount ingredienti.\n";
