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
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <meta name="csrf-token" content="<?= htmlspecialchars($csrf) ?>">
  <title>🧂 Ingredienti — Meal Planner</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="stylesheet" href="style.css">
  <style>
    .ing-layout { max-width: 960px; margin: 0 auto; padding: 1.5rem 1rem 5rem; }
    .ing-toolbar {
      display: flex; gap: .6rem; flex-wrap: wrap; align-items: center;
      margin-bottom: 1.25rem;
    }
    .ing-toolbar input, .ing-toolbar select {
      padding: .4rem .7rem; border: 1.5px solid var(--border-soft);
      border-radius: var(--radius-btn); font-family: var(--font-body);
      font-size: .85rem; background: var(--card); color: var(--text-primary); outline: none;
      transition: border-color .15s;
    }
    .ing-toolbar input:focus, .ing-toolbar select:focus { border-color: var(--primary); }
    .ing-search { flex: 1; min-width: 180px; }

    .ing-table-wrap { overflow-x: auto; border-radius: var(--radius-card); box-shadow: var(--shadow-card); }
    table.ing-table { width: 100%; border-collapse: collapse; background: var(--card); }
    .ing-table th {
      background: var(--primary-soft); color: var(--primary);
      font-family: var(--font-head); font-size: .78rem; font-weight: 700;
      text-transform: uppercase; letter-spacing: .06em;
      padding: .65rem .9rem; text-align: left; white-space: nowrap;
    }
    .ing-table td {
      padding: .5rem .9rem; font-size: .88rem; color: var(--text-primary);
      border-bottom: 1px solid var(--border-soft); vertical-align: middle;
    }
    .ing-table tr:last-child td { border-bottom: none; }
    .ing-table tr:hover td { background: #fafafa; }

    .ing-cell-edit {
      cursor: pointer; border-radius: 6px; padding: 2px 6px;
      transition: background .15s;
    }
    .ing-cell-edit:hover { background: var(--primary-soft); }
    .ing-cell-edit input, .ing-cell-edit select {
      width: 100%; border: 1.5px solid var(--primary); border-radius: 6px;
      padding: 2px 6px; font-family: var(--font-body); font-size: .88rem;
      outline: none; background: var(--card); color: var(--text-primary);
    }

    .ing-zone-badge {
      display: inline-flex; align-items: center; gap: 3px;
      font-size: .75rem; padding: .15rem .55rem;
      border-radius: var(--radius-pill); background: var(--primary-soft); color: var(--primary);
      font-weight: 600; white-space: nowrap;
    }
    .btn-ing-del {
      background: none; border: none; cursor: pointer; color: var(--text-secondary);
      font-size: 1.05rem; padding: .1rem .3rem; border-radius: 6px;
      transition: color .15s, background .15s;
    }
    .btn-ing-del:hover { color: #e53935; background: #fdecea; }

    /* Add form */
    .ing-add-form {
      display: flex; gap: .5rem; flex-wrap: wrap; align-items: flex-end;
      background: var(--card); border-radius: var(--radius-card);
      box-shadow: var(--shadow-card); padding: 1rem 1.25rem; margin-bottom: 1.25rem;
    }
    .ing-add-form .form-group { display: flex; flex-direction: column; gap: .2rem; }
    .ing-add-form label { font-size: .72rem; color: var(--text-secondary); font-weight: 600; }
    .ing-add-form input, .ing-add-form select {
      padding: .38rem .6rem; border: 1.5px solid var(--border-soft);
      border-radius: var(--radius-btn); font-family: var(--font-body);
      font-size: .85rem; background: var(--bg); color: var(--text-primary); outline: none;
      transition: border-color .15s;
    }
    .ing-add-form input:focus, .ing-add-form select:focus { border-color: var(--primary); }
    .ing-add-form input[type=text]   { min-width: 140px; }
    .ing-add-form input[type=number] { width: 80px; }

    .ing-count { font-size: .8rem; color: var(--text-secondary); margin-left: auto; }
    .ing-saving { opacity: .5; pointer-events: none; }
  </style>
</head>
<body>
<header class="topbar">
  <div class="topbar-brand">
    <span class="brand-emoji">🍽️</span>
    <span class="brand-name">Meal Planner</span>
  </div>
  <nav class="topbar-nav">
    <a href="index.php"       class="nav-link">📅 Pianifica</a>
    <a href="admin.php"       class="nav-link">🍝 Ricette</a>
    <a href="family.php"      class="nav-link">👥 Famiglia</a>
    <a href="lista.php"       class="nav-link">🛒 Spesa</a>
    <a href="pantry.php"      class="nav-link">🏪 Dispensa</a>
    <a href="ingredienti.php" class="nav-link active">🧂 Ingredienti</a>
    <a href="export_import.php" class="nav-link">📦 Import/Export</a>
  </nav>
  <div class="topbar-user">
    <button id="btn-logout" class="btn-ghost-sm">Esci</button>
  </div>
</header>

<div class="ing-layout">
  <h1 style="font-family:var(--font-head);margin-bottom:1.25rem">🧂 Database Ingredienti</h1>

  <!-- Form aggiungi -->
  <div class="ing-add-form" id="add-form">
    <div class="form-group">
      <label>Nome *</label>
      <input type="text" id="add-name" placeholder="es. zucchine">
    </div>
    <div class="form-group">
      <label>Kcal/100g</label>
      <input type="number" id="add-kcal" placeholder="0" min="0" step="1">
    </div>
    <div class="form-group">
      <label>Prezzo €/kg</label>
      <input type="number" id="add-price" placeholder="0.00" min="0" step="0.01">
    </div>
    <div class="form-group">
      <label>Zona</label>
      <select id="add-zone">
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
    </div>
    <button id="btn-add" class="btn-primary" style="align-self:flex-end">+ Aggiungi</button>
  </div>

  <!-- Toolbar ricerca/filtro -->
  <div class="ing-toolbar">
    <input type="text" id="search" class="ing-search" placeholder="🔍 Cerca ingrediente…">
    <select id="filter-zone">
      <option value="">Tutte le zone</option>
      <option value="ortofrutta">🥦 Ortofrutta</option>
      <option value="pane">🥖 Pane</option>
      <option value="macelleria">🥩 Macelleria</option>
      <option value="pesce">🐟 Pesce</option>
      <option value="latticini">🧀 Latticini</option>
      <option value="scaffali">🛒 Scaffali</option>
      <option value="bevande">🍾 Bevande</option>
      <option value="surgelati">❄️ Surgelati</option>
      <option value="casalinghi">🏠 Casalinghi</option>
      <option value="altro">📦 Altro</option>
    </select>
    <span class="ing-count" id="count-label"></span>
  </div>

  <!-- Tabella -->
  <div class="ing-table-wrap">
    <table class="ing-table">
      <thead>
        <tr>
          <th>Nome</th>
          <th>Kcal/100g</th>
          <th>Prezzo €/kg</th>
          <th>Zona</th>
          <th></th>
        </tr>
      </thead>
      <tbody id="ing-tbody">
        <tr><td colspan="5" style="text-align:center;padding:2rem;color:var(--text-secondary)">Caricamento…</td></tr>
      </tbody>
    </table>
  </div>
  <div id="load-more-wrap" style="text-align:center;margin-top:1rem;display:none">
    <button id="btn-load-more" class="btn-ghost">Carica altri…</button>
  </div>
</div>

<nav class="bottom-nav">
  <div class="bottom-nav-inner">
    <a href="index.php"       class="bottom-nav-item"><span class="bn-icon">📅</span>Pianifica</a>
    <a href="admin.php"       class="bottom-nav-item"><span class="bn-icon">🍝</span>Ricette</a>
    <button class="bottom-nav-fab" style="visibility:hidden;pointer-events:none"></button>
    <a href="lista.php"       class="bottom-nav-item"><span class="bn-icon">🛒</span>Spesa</a>
    <a href="ingredienti.php" class="bottom-nav-item active"><span class="bn-icon">🧂</span>Ingr.</a>
  </div>
</nav>

<div id="toast" class="toast"></div>
<script>
const API  = 'api.php';
const CSRF = () => document.querySelector('meta[name="csrf-token"]').content;

const ZONE_EMOJI = {
  ortofrutta:'🥦', pane:'🥖', macelleria:'🥩', pesce:'🐟', latticini:'🧀',
  scaffali:'🛒', bevande:'🍾', surgelati:'❄️', casalinghi:'🏠', altro:'📦',
};
const ZONES = Object.keys(ZONE_EMOJI);

async function get(action, p={}) {
  return (await fetch(`${API}?${new URLSearchParams({action,...p})}`)).json();
}
async function post(action, data={}) {
  return (await fetch(API, {
    method:'POST', headers:{'Content-Type':'application/json'},
    body: JSON.stringify({action, csrf_token:CSRF(), ...data}),
  })).json();
}

function showToast(msg) {
  const t = document.getElementById('toast');
  t.textContent = msg; t.classList.add('show');
  setTimeout(() => t.classList.remove('show'), 2800);
}

let allItems = [];
let offset   = 0;
const LIMIT  = 100;

async function loadItems(reset = true) {
  if (reset) { offset = 0; allItems = []; }
  const q    = document.getElementById('search').value.trim();
  const zone = document.getElementById('filter-zone').value;
  const data = await get('nutrition_list', { q, zone, limit: LIMIT, offset });
  if (reset) allItems = data.items || [];
  else       allItems = [...allItems, ...(data.items || [])];
  offset += (data.items || []).length;
  document.getElementById('count-label').textContent =
    `${allItems.length} di ${data.total} ingredienti`;
  const wrap = document.getElementById('load-more-wrap');
  wrap.style.display = offset < data.total ? 'block' : 'none';
  renderTable();
}

function renderTable() {
  const tbody = document.getElementById('ing-tbody');
  if (!allItems.length) {
    tbody.innerHTML = '<tr><td colspan="5" style="text-align:center;padding:2rem;color:var(--text-secondary)">Nessun ingrediente trovato.</td></tr>';
    return;
  }
  tbody.innerHTML = '';
  allItems.forEach(it => tbody.appendChild(makeRow(it)));
}

function makeRow(it) {
  const tr = document.createElement('tr');
  tr.dataset.id = it.id;
  tr.innerHTML = `
    <td><span class="ing-cell-edit" data-field="name">${escHtml(it.name)}</span></td>
    <td><span class="ing-cell-edit" data-field="kcal_100g">${it.kcal_100g ?? 0}</span></td>
    <td><span class="ing-cell-edit" data-field="price_est">${parseFloat(it.price_est||0).toFixed(2)}</span></td>
    <td><span class="ing-cell-edit" data-field="zone">
      <span class="ing-zone-badge">${ZONE_EMOJI[it.zone]||'📦'} ${it.zone||''}</span>
    </span></td>
    <td><button class="btn-ing-del" title="Elimina">🗑</button></td>`;

  // inline edit
  tr.querySelectorAll('.ing-cell-edit').forEach(cell => {
    cell.addEventListener('click', () => startEdit(cell, it));
  });

  // delete
  tr.querySelector('.btn-ing-del').addEventListener('click', async () => {
    if (!confirm(`Eliminare "${it.name}"?`)) return;
    const d = await post('nutrition_delete', { id: it.id });
    if (d.error) { showToast('❌ ' + d.error); return; }
    allItems = allItems.filter(x => x.id !== it.id);
    renderTable();
    showToast('🗑 Eliminato');
  });

  return tr;
}

function startEdit(cell, it) {
  const field = cell.dataset.field;
  const oldVal = it[field] ?? '';
  let input;

  if (field === 'zone') {
    input = document.createElement('select');
    ZONES.forEach(z => {
      const o = document.createElement('option');
      o.value = z; o.textContent = `${ZONE_EMOJI[z]} ${z}`;
      if (z === oldVal) o.selected = true;
      input.appendChild(o);
    });
  } else {
    input = document.createElement('input');
    input.type  = field === 'name' ? 'text' : 'number';
    input.value = field === 'price_est' ? parseFloat(oldVal||0).toFixed(2) : oldVal;
    if (field !== 'name') { input.min = '0'; input.step = field === 'price_est' ? '0.01' : '1'; }
  }

  cell.innerHTML = '';
  cell.appendChild(input);
  input.focus();
  if (input.select) input.select();

  const save = async () => {
    const newVal = input.value.trim();
    if (String(newVal) === String(field === 'price_est' ? parseFloat(oldVal||0).toFixed(2) : oldVal)) {
      revertCell(cell, it); return;
    }
    cell.classList.add('ing-saving');
    const payload = { id: it.id, [field]: newVal };
    const d = await post('nutrition_update', payload);
    cell.classList.remove('ing-saving');
    if (d.error) { showToast('❌ ' + d.error); revertCell(cell, it); return; }
    it[field] = d[field];
    revertCell(cell, it);
  };

  input.addEventListener('blur',   save);
  input.addEventListener('keydown', e => { if (e.key === 'Enter') input.blur(); if (e.key === 'Escape') revertCell(cell, it); });
  if (field === 'zone') input.addEventListener('change', save);
}

function revertCell(cell, it) {
  const field = cell.dataset.field;
  if (field === 'zone') {
    cell.innerHTML = `<span class="ing-zone-badge">${ZONE_EMOJI[it.zone]||'📦'} ${it.zone||''}</span>`;
  } else if (field === 'price_est') {
    cell.textContent = parseFloat(it.price_est||0).toFixed(2);
  } else {
    cell.textContent = it[field] ?? '';
  }
  // re-attach click
  cell.addEventListener('click', () => startEdit(cell, it));
}

function escHtml(s) {
  return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}

// Ricerca con debounce
let searchTimer = null;
document.getElementById('search').addEventListener('input', () => {
  clearTimeout(searchTimer);
  searchTimer = setTimeout(() => loadItems(true), 300);
});
document.getElementById('filter-zone').addEventListener('change', () => loadItems(true));

// Carica altri
document.getElementById('btn-load-more').addEventListener('click', () => loadItems(false));

// Aggiungi ingrediente
document.getElementById('btn-add').addEventListener('click', async () => {
  const name  = document.getElementById('add-name').value.trim();
  const kcal  = document.getElementById('add-kcal').value;
  const price = document.getElementById('add-price').value;
  const zone  = document.getElementById('add-zone').value;
  if (!name) { document.getElementById('add-name').focus(); return; }
  const d = await post('nutrition_add', { name, kcal_100g: kcal||0, price_est: price||0, zone });
  if (d.error) { showToast('❌ ' + d.error); return; }
  showToast('✅ Ingrediente aggiunto');
  document.getElementById('add-name').value  = '';
  document.getElementById('add-kcal').value  = '';
  document.getElementById('add-price').value = '';
  loadItems(true);
});
document.getElementById('add-name').addEventListener('keydown', e => {
  if (e.key === 'Enter') document.getElementById('btn-add').click();
});

document.getElementById('btn-logout')?.addEventListener('click', async () => {
  await post('logout'); location.reload();
});

loadItems();
</script>
</body>
</html>
