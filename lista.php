<?php
session_start();
require_once __DIR__ . '/config.php';
if (empty($_SESSION['user_id']))   $_SESSION['user_id']   = 1;
if (empty($_SESSION['family_id'])) $_SESSION['family_id'] = 1;
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
$csrf = $_SESSION['csrf_token'];
$weekStart = $_GET['week'] ?? date('Y-m-d', strtotime('monday this week'));
?>
<!DOCTYPE html>
<html lang="it">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1">
  <meta name="csrf-token" content="<?= htmlspecialchars($csrf) ?>">
  <meta name="theme-color" content="#FF6B4A">
  <title>🛒 Lista spesa</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="stylesheet" href="style.css">
  <link rel="stylesheet" href="lista.css">
</head>
<body class="lista-body">

<header class="lista-header">
  <div class="lista-header-top">
    <a href="index.php" class="lista-back">← Torna al planner</a>
    <a href="pantry.php" class="lista-back" style="margin-left:.75rem">🏪 Dispensa</a>
    <span id="live-badge" class="live-badge">🔴 LIVE</span>
  </div>
  <div class="lista-header-info">
    <h1 class="lista-title">🛒 Lista spesa</h1>
    <div class="lista-week" id="lista-week-label"></div>
  </div>
  <div class="lista-header-actions">
    <span id="lista-total" class="lista-total"></span>
    <span id="last-update" class="last-update">—</span>
    <button id="btn-refresh"          class="lista-btn-primary">🔄 Aggiorna</button>
    <button id="btn-share"            class="lista-btn-ghost">🔗 Condividi</button>
    <button id="btn-reset"            class="lista-btn-ghost">↺ Deseleziona</button>
    <button id="btn-clear-checked"    class="lista-btn-ghost">🗑 Acquistati</button>
    <button id="btn-clear-all"        class="lista-btn-ghost" style="color:#c84b2d">🗑 Svuota tutto</button>
  </div>

  <!-- Barra "Aggiungi da ricetta" -->
  <div class="lista-recipe-bar">
    <span class="lista-recipe-label">🍽️</span>
    <div class="lista-recipe-search-wrap">
      <input type="text" id="recipe-search" class="lista-add-input" placeholder="Cerca ricetta e aggiungi ingredienti…" autocomplete="off">
      <div id="recipe-suggestions" class="recipe-suggestions"></div>
    </div>
  </div>

  <!-- Barra aggiunta rapida -->
  <div class="lista-add-bar">
    <input type="text"   id="add-name" class="lista-add-input" placeholder="Aggiungi articolo…">
    <input type="number" id="add-qty"  class="lista-add-qty"   placeholder="qtà" step="0.1" min="0">
    <input type="text"   id="add-unit" class="lista-add-unit"  placeholder="g/pz">
    <select id="add-zone" class="lista-add-zone">
      <option value="ortofrutta">🥦 Ortofrutta</option>
      <option value="pane">🥖 Pane</option>
      <option value="macelleria">🥩 Macelleria</option>
      <option value="pesce">🐟 Pesce</option>
      <option value="latticini">🧀 Latticini</option>
      <option value="scaffali" selected>🛒 Scaffali</option>
      <option value="bevande">🍾 Bevande</option>
      <option value="surgelati">❄️ Surgelati</option>
      <option value="casalinghi">🏠 Casalinghi</option>
      <option value="altro">📦 Altro</option>
    </select>
    <button id="btn-add-item" class="lista-btn-primary">+ Aggiungi</button>
  </div>
</header>

<main id="lista-main" class="lista-main">
  <div class="lista-loading">Caricamento lista…</div>
</main>

<!-- Modale preview ingredienti ricetta -->
<div id="recipe-modal" class="recipe-modal-overlay" style="display:none">
  <div class="recipe-modal">
    <div class="recipe-modal-head">
      <span id="recipe-modal-title">Ingredienti</span>
      <button id="recipe-modal-close" class="recipe-modal-close">✕</button>
    </div>
    <div id="recipe-modal-body" class="recipe-modal-body"></div>
    <div class="recipe-modal-foot">
      <button id="recipe-modal-add" class="lista-btn-primary">➕ Aggiungi selezionati</button>
      <button id="recipe-modal-cancel" class="lista-btn-ghost">Annulla</button>
    </div>
  </div>
</div>

<script>
const WEEK = '<?= htmlspecialchars($weekStart) ?>';
</script>
<script src="lista.js"></script>

<nav class="bottom-nav">
  <div class="bottom-nav-inner">
    <a href="index.php"  class="bottom-nav-item"><span class="bn-icon">📅</span>Pianifica</a>
    <a href="admin.php"  class="bottom-nav-item"><span class="bn-icon">🍝</span>Ricette</a>
    <button class="bottom-nav-fab" style="visibility:hidden;pointer-events:none"></button>
    <a href="lista.php"  class="bottom-nav-item active"><span class="bn-icon">🛒</span>Spesa</a>
    <a href="family.php" class="bottom-nav-item"><span class="bn-icon">👥</span>Famiglia</a>
  </div>
</nav>
</body>
</html>
