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
  <title>Admin — Meal Planner</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@600;700&family=DM+Sans:wght@300;400;500&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="style.css">
</head>
<body>
<header class="topbar">
  <div class="topbar-brand"><span class="brand-emoji">🍽️</span><span class="brand-name">Meal Planner</span></div>
  <nav class="topbar-nav">
    <a href="index.php"  class="nav-link">Pianifica</a>
    <a href="admin.php"  class="nav-link active">Admin</a>
    <a href="family.php" class="nav-link">Famiglia</a>
    <a href="lista.php"  class="nav-link">🛒 Lista spesa</a>
    <a href="pantry.php" class="nav-link">🏪 Dispensa</a>
  </nav>
  <div class="topbar-user">
    <button id="btn-logout" class="btn-ghost-sm">Esci</button>
  </div>
</header>

<div class="admin-layout">
  <h1>Gestione piatti</h1>

  <!-- FORM ADD/EDIT -->
  <div class="form-card">
    <h2 id="form-title">Aggiungi nuovo piatto</h2>
    <input type="hidden" id="edit-id">

    <div class="form-row">
      <div class="form-group">
        <label>Nome piatto *</label>
        <input type="text" id="f-name" placeholder="Es. Pasta al pesto">
      </div>
      <div class="form-group">
        <label>Emoji</label>
        <input type="text" id="f-emoji" placeholder="🍝" maxlength="4">
      </div>
    </div>

    <div class="form-row">
      <div class="form-group">
        <label>Categoria</label>
        <select id="f-category">
          <option value="1">☀️ Colazione</option>
          <option value="2" selected>🍝 Primo</option>
          <option value="3">🥩 Secondo</option>
          <option value="4">🥗 Contorno</option>
          <option value="5">🍽️ Altro</option>
        </select>
      </div>
      <div class="form-group">
        <label>Calorie (kcal) — lascia 0 per calcolo auto</label>
        <input type="number" id="f-cal" placeholder="0" min="0" max="9999">
      </div>
    </div>

    <div class="form-row full">
      <div class="form-group">
        <label>Ingredienti</label>
        <div id="ingredients-list" class="ingredients-list"></div>
        <button type="button" id="btn-add-ingredient" class="btn-add-ing">+ Aggiungi ingrediente</button>
      </div>
    </div>

    <div class="form-actions">
      <button type="button" id="btn-save"   class="btn-primary">💾 Salva piatto</button>
      <button type="button" id="btn-cancel" class="btn-secondary" style="display:none">Annulla</button>
    </div>
  </div>

  <!-- TABLE -->
  <div class="table-card">
    <div style="padding:1rem 1rem .5rem;display:flex;align-items:center;justify-content:space-between">
      <strong style="font-family:var(--font-head);font-size:1.1rem">Piatti nel database</strong>
      <input type="text" class="admin-search" id="admin-search" placeholder="🔍 Filtra…">
    </div>
    <table class="admin-table">
      <thead><tr>
        <th>Emoji</th><th>Nome</th><th>Categoria</th>
        <th>Kcal</th><th>Ingredienti</th><th>Azioni</th>
      </tr></thead>
      <tbody id="admin-tbody"></tbody>
    </table>
  </div>
</div>

<div id="toast" class="toast"></div>
<script src="admin.js"></script>
</body>
</html>
