<?php
session_start();
require_once __DIR__ . '/config.php';
if (empty($_SESSION['user_id']))   { header('Location: login.php'); exit; }
if (empty($_SESSION['family_id'])) { header('Location: login.php?step=family'); exit; }
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
  <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@600;700&family=DM+Sans:ital,wght@0,300;0,400;0,500;1,300&display=swap" rel="stylesheet">
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
    <a href="index.php"  class="nav-link">Pianifica</a>
    <a href="admin.php"  class="nav-link">Admin</a>
    <a href="family.php" class="nav-link">Famiglia</a>
    <a href="lista.php"  class="nav-link">🛒 Lista spesa</a>
    <a href="pantry.php" class="nav-link active">🏪 Dispensa</a>
  </nav>
  <div class="topbar-user">
    <span id="user-label" class="user-label"></span>
    <button id="btn-logout" class="btn-ghost-sm">Esci</button>
  </div>
</header>

<main class="page-main" style="max-width:860px;margin:0 auto;padding:1.5rem 1rem">

  <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:.75rem;margin-bottom:1.25rem">
    <h1 style="font-family:'Playfair Display',serif;font-size:1.6rem;margin:0">🏪 Dispensa</h1>
    <div style="display:flex;gap:.5rem;flex-wrap:wrap">
      <button id="btn-from-shopping" class="btn-ghost">🛒 Importa da lista</button>
      <button id="btn-add-item"      class="btn-ghost">+ Aggiungi</button>
    </div>
  </div>

  <!-- Form aggiunta/modifica -->
  <div id="pantry-form-wrap" style="display:none;background:var(--cream-mid,#f5f0e8);border-radius:10px;padding:1rem;margin-bottom:1.25rem">
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

  <!-- Import da lista: seleziona settimana -->
  <div id="import-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:500;display:none;align-items:center;justify-content:center">
    <div style="background:#fff;border-radius:12px;padding:1.5rem;min-width:280px;text-align:center">
      <h3 style="margin:0 0 1rem">Importa da lista spesa</h3>
      <p style="font-size:.85rem;color:#666;margin:0 0 .75rem">Seleziona la settimana di riferimento (saranno importati solo gli articoli spuntati).</p>
      <input type="week" id="import-week-input" class="ctrl-input" style="width:100%;margin-bottom:1rem">
      <div style="display:flex;gap:.5rem;justify-content:center">
        <button id="import-confirm" class="btn-primary">Importa</button>
        <button id="import-cancel"  class="btn-ghost">Annulla</button>
      </div>
    </div>
  </div>

</main>

<div id="toast" class="toast"></div>
<script src="pantry.js"></script>
</body>
</html>
