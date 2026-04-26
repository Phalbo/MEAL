<?php
/**
 * data/import_nutrition.php
 * Esegui UNA SOLA VOLTA: php data/import_nutrition.php
 * Popola la tabella nutrition_db nel DB SQLite dell'app.
 */

require __DIR__ . '/../config.php';

$json  = file_get_contents(__DIR__ . '/nutrition.json');
$items = json_decode($json, true);

if (!$items) {
    echo "Errore: nutrition.json non valido o non trovato.\n";
    exit(1);
}

$pdo = new PDO('sqlite:' . DB_PATH);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('PRAGMA foreign_keys = ON');

$pdo->exec("
    CREATE TABLE IF NOT EXISTS nutrition_db (
        id            INTEGER PRIMARY KEY AUTOINCREMENT,
        name          TEXT NOT NULL UNIQUE,
        kcal_100g     REAL NOT NULL,
        zone          TEXT DEFAULT 'scaffali',
        aliases       TEXT DEFAULT '[]',
        unit_weights  TEXT DEFAULT '{}'
    )
");

$stmt = $pdo->prepare("
    INSERT OR REPLACE INTO nutrition_db (name, kcal_100g, zone, aliases, unit_weights)
    VALUES (:name, :kcal, :zone, :aliases, :weights)
");

$ok = 0;
foreach ($items as $item) {
    $stmt->execute([
        ':name'    => $item['name'],
        ':kcal'    => $item['kcal'],
        ':zone'    => $item['zone']         ?? 'scaffali',
        ':aliases' => json_encode($item['aliases']       ?? [], JSON_UNESCAPED_UNICODE),
        ':weights' => json_encode($item['unit_weights']  ?? [], JSON_UNESCAPED_UNICODE),
    ]);
    $ok++;
}

echo "Importati $ok ingredienti in " . DB_PATH . "\n";
