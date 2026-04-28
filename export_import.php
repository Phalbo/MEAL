<?php
session_start();
require_once __DIR__ . '/config.php';
if (empty($_SESSION['user_id']))   { header('Location: login.php'); exit; }
if (empty($_SESSION['family_id'])) { header('Location: login.php'); exit; }
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
$csrf = $_SESSION['csrf_token'];
?>
<!DOCTYPE html>
<html lang="it">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <meta name="csrf-token" content="<?= htmlspecialchars($csrf) ?>">
  <title>Importa / Esporta — Meal Planner</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="stylesheet" href="style.css">
  <style>
    .ie-section {
      background: var(--card);
      border-radius: var(--radius-card);
      box-shadow: var(--shadow-card);
      padding: 1.5rem;
      margin-bottom: 1.5rem;
    }
    .ie-section h2 {
      font-family: var(--font-head);
      font-size: 1.1rem;
      font-weight: 700;
      margin: 0 0 1rem;
      color: var(--text-primary);
    }
    .ie-grid {
      display: grid;
      grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
      gap: .75rem;
    }
    .ie-table-block {
      border: 1.5px solid var(--border-soft);
      border-radius: var(--radius-btn);
      padding: .85rem 1rem;
      display: flex;
      flex-direction: column;
      gap: .5rem;
    }
    .ie-table-name {
      font-weight: 600;
      font-size: .9rem;
      color: var(--text-primary);
    }
    .ie-table-desc {
      font-size: .75rem;
      color: var(--text-secondary);
      margin-bottom: .25rem;
    }
    .ie-file-row {
      display: flex;
      gap: .4rem;
      align-items: center;
      flex-wrap: wrap;
    }
    .ie-file-input {
      font-size: .78rem;
      flex: 1;
      min-width: 0;
    }
    .ie-result {
      font-size: .78rem;
      padding: .3rem .5rem;
      border-radius: 6px;
      margin-top: .25rem;
      display: none;
    }
    .ie-result.ok  { background: #e8f5e9; color: #2e7d32; }
    .ie-result.err { background: #ffeaea; color: #c62828; }
  </style>
</head>
<body>

<header class="topbar">
  <button id="btn-menu" class="btn-hamburger" aria-label="Apri menu">☰</button>
  <div class="topbar-brand">
    <span class="brand-emoji">🍽️</span>
    <span class="brand-name">Meal Planner</span>
  </div>
  <nav class="topbar-nav">
    <a href="index.php"         class="nav-link">📅 Pianifica</a>
    <a href="admin.php"         class="nav-link">🍝 Ricette</a>
    <a href="family.php"        class="nav-link">👥 Famiglia</a>
    <a href="lista.php"         class="nav-link">🛒 Spesa</a>
    <a href="pantry.php"        class="nav-link">🏪 Dispensa</a>
    <a href="export_import.php" class="nav-link active">📦 Import/Export</a>
  </nav>
  <div class="topbar-user">
    <span id="user-label" class="user-label"></span>
    <button id="btn-logout" class="btn-ghost-sm">Esci</button>
  </div>
</header>

<main class="page-main">

  <h1 style="font-family:var(--font-head);font-size:1.6rem;font-weight:700;margin:0 0 1.5rem">📦 Importa / Esporta</h1>

  <!-- ── ESPORTA ── -->
  <div class="ie-section">
    <h2>⬇️ Esporta CSV</h2>
    <p style="font-size:.85rem;color:var(--text-secondary);margin:-.25rem 0 1rem">
      Scarica i dati in formato CSV per backup o migrazione.
    </p>
    <div class="ie-grid">
      <?php
      $exports = [
        ['table'=>'nutrition_db',     'label'=>'Nutrition DB',    'desc'=>'Valori nutrizionali e zone'],
        ['table'=>'meals',            'label'=>'Ricette',          'desc'=>'Piatti della tua famiglia'],
        ['table'=>'meal_ingredients', 'label'=>'Ingredienti',      'desc'=>'Ingredienti di ogni ricetta'],
        ['table'=>'pantry_items',     'label'=>'Dispensa',         'desc'=>'Articoli in dispensa'],
        ['table'=>'shopping_items',   'label'=>'Lista spesa',      'desc'=>'Tutti gli articoli della spesa'],
      ];
      foreach ($exports as $e): ?>
      <div class="ie-table-block">
        <div class="ie-table-name"><?= $e['label'] ?></div>
        <div class="ie-table-desc"><?= $e['desc'] ?></div>
        <a href="api.php?action=export_csv&table=<?= $e['table'] ?>"
           class="btn-primary" style="font-size:.8rem;text-align:center;text-decoration:none;padding:.35rem .7rem">
          ⬇️ Scarica CSV
        </a>
      </div>
      <?php endforeach; ?>
    </div>
  </div>

  <!-- ── IMPORTA ── -->
  <div class="ie-section">
    <h2>⬆️ Importa CSV</h2>
    <p style="font-size:.85rem;color:var(--text-secondary);margin:-.25rem 0 1rem">
      Carica un CSV esportato in precedenza. Le righe duplicate vengono saltate automaticamente.
    </p>
    <div class="ie-grid">
      <?php
      $imports = [
        ['table'=>'nutrition_db',     'label'=>'Nutrition DB',    'desc'=>'Deduplicato su: nome'],
        ['table'=>'meals',            'label'=>'Ricette',          'desc'=>'Deduplicato su: nome'],
        ['table'=>'meal_ingredients', 'label'=>'Ingredienti',      'desc'=>'Deduplicato su: ricetta+nome'],
        ['table'=>'pantry_items',     'label'=>'Dispensa',         'desc'=>'Deduplicato su: nome+unità'],
        ['table'=>'shopping_items',   'label'=>'Lista spesa',      'desc'=>'Deduplicato su: settimana+nome+unità'],
      ];
      foreach ($imports as $im): ?>
      <div class="ie-table-block" id="block-<?= $im['table'] ?>">
        <div class="ie-table-name"><?= $im['label'] ?></div>
        <div class="ie-table-desc"><?= $im['desc'] ?></div>
        <div class="ie-file-row">
          <input type="file" accept=".csv" class="ie-file-input" id="file-<?= $im['table'] ?>">
          <button class="btn-primary" style="font-size:.8rem;padding:.35rem .7rem;white-space:nowrap"
                  onclick="importCsv('<?= $im['table'] ?>')">⬆️ Importa</button>
        </div>
        <div class="ie-result" id="result-<?= $im['table'] ?>"></div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>

</main>

<div id="toast" class="toast"></div>

<nav class="bottom-nav">
  <div class="bottom-nav-inner">
    <a href="index.php"  class="bottom-nav-item"><span class="bn-icon">📅</span>Pianifica</a>
    <a href="admin.php"  class="bottom-nav-item"><span class="bn-icon">🍝</span>Ricette</a>
    <button class="bottom-nav-fab" style="visibility:hidden;pointer-events:none"></button>
    <a href="lista.php"  class="bottom-nav-item"><span class="bn-icon">🛒</span>Spesa</a>
    <a href="family.php" class="bottom-nav-item"><span class="bn-icon">👥</span>Famiglia</a>
  </div>
</nav>

<script src="export_import.js"></script>
</body>
</html>
