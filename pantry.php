<?php
session_start();
require_once __DIR__ . '/config.php';
if (empty($_SESSION['user_id']))   $_SESSION['user_id']   = 1;
if (empty($_SESSION['family_id'])) $_SESSION['family_id'] = 1;
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
$csrf = $_SESSION['csrf_token'];
?>
<!DOCTYPE html>
<html lang="it">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1.0">
  <meta name="csrf-token" content="<?= htmlspecialchars($csrf) ?>">
  <title>Dispensa — Meal Planner</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="stylesheet" href="style.css">
</head>
<body>

<header class="topbar">
  <button id="btn-menu" class="btn-hamburger" aria-label="Apri menu">☰</button>
  <div class="topbar-brand">
    <span class="brand-emoji">🍽️</span>
    <span class="brand-name">Meal Planner</span>
  </div>
  <nav class="topbar-nav">
    <a href="index.php"  class="nav-link">📅 Pianifica</a>
    <a href="admin.php"  class="nav-link">🍝 Ricette</a>
    <a href="family.php" class="nav-link">👥 Famiglia</a>
    <a href="lista.php"  class="nav-link">🛒 Spesa</a>
    <a href="pantry.php"         class="nav-link active">🏪 Dispensa</a>
    <a href="ingredienti.php"   class="nav-link">🧂 Ingredienti</a>
    <a href="export_import.php" class="nav-link">📦 Import/Export</a>
  </nav>
  <div class="topbar-user">
    <span id="user-label" class="user-label"></span>
    <button id="btn-logout" class="btn-ghost-sm">Esci</button>
  </div>
</header>

<main class="page-main">

  <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:.75rem;margin-bottom:1.25rem">
    <h1 style="font-family:var(--font-head);font-size:1.6rem;font-weight:700;margin:0">🏪 Dispensa</h1>
    <div style="display:flex;gap:.5rem;flex-wrap:wrap">
      <button id="btn-from-shopping" class="btn-ghost">🛒 Importa da lista</button>
      <button id="btn-clear-pantry"  class="btn-ghost" style="color:#c84b2d">🗑 Svuota dispensa</button>
      <button id="btn-add-item"      class="btn-primary">+ Aggiungi</button>
    </div>
  </div>

  <!-- Form aggiunta/modifica -->
  <div id="pantry-form-wrap" style="display:none;background:var(--primary-soft);border-radius:var(--radius-card);padding:1.1rem;margin-bottom:1.25rem">
    <div style="display:grid;grid-template-columns:1fr 80px 80px 110px 130px auto;gap:.5rem;align-items:end;flex-wrap:wrap">
      <div>
        <label class="form-label">Ingrediente</label>
        <input id="pf-name" class="ctrl-input" placeholder="es. pasta" style="width:100%">
      </div>
      <div>
        <label class="form-label">Quantità</label>
        <input id="pf-qty" type="number" min="0" step="0.1" class="ctrl-input" placeholder="—" style="width:100%">
      </div>
      <div>
        <label class="form-label">Unità</label>
        <input id="pf-unit" class="ctrl-input" placeholder="g / pz" style="width:100%">
      </div>
      <div>
        <label class="form-label">Zona</label>
        <select id="pf-zone" class="ctrl-select" style="width:100%">
          <option value="scaffali">🛒 Scaffali</option>
          <option value="ortofrutta">🥦 Ortofrutta</option>
          <option value="pane">🥖 Pane</option>
          <option value="macelleria">🥩 Macelleria</option>
          <option value="pesce">🐟 Pesce</option>
          <option value="latticini">🧀 Latticini</option>
          <option value="bevande">🍾 Bevande</option>
          <option value="surgelati">❄️ Surgelati</option>
          <option value="casalinghi">🏠 Casalinghi</option>
          <option value="altro">📦 Altro</option>
        </select>
      </div>
      <div>
        <label class="form-label">Scadenza</label>
        <input id="pf-expiry" type="date" class="ctrl-input" style="width:100%">
      </div>
      <div style="display:flex;gap:.4rem;padding-bottom:2px">
        <button id="pf-save"   class="btn-primary" style="white-space:nowrap">Salva</button>
        <button id="pf-cancel" class="btn-ghost">✕</button>
      </div>
    </div>
    <input type="hidden" id="pf-editing-id" value="">
  </div>

  <!-- Search bar -->
  <input type="text" id="pantry-search" class="ctrl-input" placeholder="🔍 Cerca nella dispensa…" style="width:100%;margin-bottom:1rem">

  <!-- Lista dispensa -->
  <div id="pantry-list"></div>

  <!-- Import modal -->
  <div id="import-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:500;align-items:center;justify-content:center">
    <div style="background:var(--card);border-radius:var(--radius-card);padding:1.75rem;min-width:300px;text-align:center;box-shadow:0 8px 32px rgba(0,0,0,.18)">
      <h3 style="font-family:var(--font-head);font-weight:700;margin:0 0 .5rem">Importa da lista spesa</h3>
      <p style="font-size:.85rem;color:var(--text-secondary);margin:0 0 1rem">Verranno importati solo gli articoli spuntati.</p>
      <input type="week" id="import-week-input" class="ctrl-input" style="width:100%;margin-bottom:1rem">
      <div style="display:flex;gap:.5rem;justify-content:center">
        <button id="import-confirm" class="btn-primary">Importa</button>
        <button id="import-cancel"  class="btn-ghost">Annulla</button>
      </div>
    </div>
  </div>

</main>

<div id="toast" class="toast"></div>
<script src="utils.js"></script>
<script src="pantry.js"></script>

<nav class="bottom-nav">
  <div class="bottom-nav-inner">
    <a href="index.php"  class="bottom-nav-item"><span class="bn-icon">📅</span>Pianifica</a>
    <a href="admin.php"  class="bottom-nav-item"><span class="bn-icon">🍝</span>Ricette</a>
    <button class="bottom-nav-fab" style="visibility:hidden;pointer-events:none"></button>
    <a href="lista.php"  class="bottom-nav-item"><span class="bn-icon">🛒</span>Spesa</a>
    <a href="family.php" class="bottom-nav-item"><span class="bn-icon">👥</span>Famiglia</a>
  </div>
</nav>
</body>
</html>
