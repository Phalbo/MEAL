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
  <title>Meal Planner</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@600;700&family=DM+Sans:ital,wght@0,300;0,400;0,500;1,300&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="style.css">
</head>
<body>

<header class="topbar">
  <div class="topbar-brand">
    <span class="brand-emoji">🍽️</span>
    <span class="brand-name">Meal Planner</span>
  </div>
  <nav class="topbar-nav">
    <a href="index.php"  class="nav-link active">Pianifica</a>
    <a href="admin.php"  class="nav-link">Admin</a>
    <a href="family.php" class="nav-link">Famiglia</a>
  </nav>
  <div class="topbar-user">
    <span id="user-label" class="user-label"></span>
    <button id="btn-logout" class="btn-ghost-sm">Esci</button>
  </div>
</header>

<div class="app-layout">

  <!-- ░░ SIDEBAR ░░ -->
  <aside class="sidebar">
    <div class="sidebar-controls">
      <input type="text" id="search" class="ctrl-input" placeholder="🔍 Cerca piatto…">
      <select id="filter-cat" class="ctrl-select">
        <option value="">Tutte le categorie</option>
        <option value="1">☀️ Colazione</option>
        <option value="2">🍝 Primo</option>
        <option value="3">🥩 Secondo</option>
        <option value="4">🥗 Contorno</option>
        <option value="5">🍽️ Altro</option>
      </select>
    </div>
    <div id="meal-list" class="meal-list"></div>
    <button id="btn-random" class="btn-random">🎲 Piatto casuale</button>
  </aside>

  <!-- ░░ MAIN ░░ -->
  <main class="main-area">

    <section class="calendar-section">
      <div class="section-header">
        <div class="week-nav">
          <button id="btn-prev-week" class="btn-ghost">‹</button>
          <h2 id="week-label">Settimana</h2>
          <button id="btn-next-week" class="btn-ghost">›</button>
        </div>
        <div style="display:flex;gap:.5rem">
          <button id="btn-clear-all" class="btn-ghost">🗑 Svuota</button>
          <button id="btn-gen-shopping" class="btn-ghost">🛒 Genera lista</button>
        </div>
      </div>
      <div class="calendar-wrapper">
        <div id="calendar-grid" class="calendar-grid"></div>
      </div>
    </section>

    <div class="bottom-row">

      <section class="calories-section">
        <h2>Calorie per giorno</h2>
        <div id="calories-bars" class="calories-bars"></div>
      </section>

      <section class="shopping-section">
        <div class="section-header">
          <h2>🛒 Lista della spesa</h2>
          <button id="btn-copy-list" class="btn-ghost">📋 Copia</button>
        </div>
        <div id="shopping-list" class="shopping-list">
          <p class="shopping-empty">Clicca "🛒 Genera lista" per creare la lista della settimana.</p>
        </div>
      </section>

    </div>
  </main>
</div>

<div id="toast" class="toast"></div>
<script src="app.js"></script>
<script src="app_ui.js"></script>
</body>
</html>
