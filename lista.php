<?php
session_start();
require_once __DIR__ . '/config.php';
if (empty($_SESSION['user_id']))   { header('Location: login.php'); exit; }
if (empty($_SESSION['family_id'])) { header('Location: login.php'); exit; }
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
  <meta name="theme-color" content="#1C1C1A">
  <title>🛒 Lista spesa</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="style.css">
  <link rel="stylesheet" href="lista.css">
</head>
<body class="lista-body">

<header class="lista-header">
  <div class="lista-header-top">
    <a href="index.php" class="lista-back">← Torna al planner</a>
    <span id="live-badge" class="live-badge">🔴 LIVE</span>
  </div>
  <div class="lista-header-info">
    <h1 class="lista-title">🛒 Lista spesa</h1>
    <div class="lista-week" id="lista-week-label"></div>
  </div>
  <div class="lista-header-actions">
    <span id="lista-total" class="lista-total"></span>
    <span id="last-update" class="last-update">—</span>
    <button id="btn-refresh" class="lista-btn-primary">🔄 Aggiorna</button>
    <button id="btn-reset" class="lista-btn-ghost">↺ Deseleziona tutto</button>
  </div>
</header>

<main id="lista-main" class="lista-main">
  <div class="lista-loading">Caricamento lista…</div>
</main>

<script>
const API       = 'api.php';
const CSRF      = () => document.querySelector('meta[name="csrf-token"]').content;
const WEEK      = '<?= htmlspecialchars($weekStart) ?>';
const ZONE_EMOJI = { ortofrutta:'🥦',pane:'🥖',macelleria:'🥩',pesce:'🐟',
                     latticini:'🧀',scaffali:'🛒',bevande:'🍾',surgelati:'❄️',altro:'📦' };

let items     = [];
let lastSince = null;
let lastLoadAt = null;   // timestamp Date dell'ultimo caricamento
let ticker    = null;    // intervallo per "X secondi fa"

async function apiFetch(action, params={}) {
  const qs = new URLSearchParams({action, ...params});
  return (await fetch(`${API}?${qs}`)).json();
}
async function apiPost(action, data={}) {
  return (await fetch(API,{method:'POST',headers:{'Content-Type':'application/json'},
    body:JSON.stringify({action,csrf_token:CSRF(),...data})})).json();
}

function formatWeek(w) {
  const d   = new Date(w+'T00:00:00');
  const end = new Date(d); end.setDate(end.getDate()+6);
  return `${d.toLocaleDateString('it-IT',{day:'numeric',month:'short'})} – ${end.toLocaleDateString('it-IT',{day:'numeric',month:'short'})}`;
}

function updateLastUpdateLabel() {
  if (!lastLoadAt) return;
  const sec = Math.floor((Date.now() - lastLoadAt) / 1000);
  const el  = document.getElementById('last-update');
  if (sec < 60)  el.textContent = `Aggiornato ${sec}s fa`;
  else           el.textContent = `Aggiornato ${Math.floor(sec/60)}m fa`;
}

async function loadFull() {
  const data = await apiFetch('shopping_list', {week_start: WEEK});
  items      = Array.isArray(data) ? data : [];
  lastSince  = new Date().toISOString().replace('T',' ').slice(0,19);
  lastLoadAt = Date.now();
  setBadge(true);
  render();
  updateLastUpdateLabel();
}

async function refresh() {
  const btn = document.getElementById('btn-refresh');
  btn.disabled = true;
  btn.textContent = '⏳';
  try {
    const delta = await apiFetch('shopping_list', {week_start: WEEK, since: lastSince});
    const now   = new Date().toISOString().replace('T',' ').slice(0,19);
    if (Array.isArray(delta) && delta.length) {
      delta.forEach(upd => {
        const idx = items.findIndex(i => i.id === upd.id);
        if (idx >= 0) items[idx] = {...items[idx], ...upd};
      });
    }
    lastSince  = now;
    lastLoadAt = Date.now();
    setBadge(true);
    render();
    updateLastUpdateLabel();
  } catch { setBadge(false); }
  finally {
    btn.disabled = false;
    btn.textContent = '🔄 Aggiorna';
  }
}

function setBadge(online) {
  const b = document.getElementById('live-badge');
  b.textContent = online ? '🔴 LIVE' : '⚪ OFFLINE';
  b.className   = 'live-badge' + (online ? ' live-on' : ' live-off');
}

function render() {
  const main = document.getElementById('lista-main');
  if (!items.length) { main.innerHTML='<p class="lista-empty">Lista vuota — genera la lista dal planner.</p>'; return; }

  document.getElementById('lista-week-label').textContent = formatWeek(WEEK);
  const total = items.reduce((s,i) => s+(parseFloat(i.price_actual)||parseFloat(i.price_est)||0), 0);
  document.getElementById('lista-total').textContent = total > 0 ? `💰 €${total.toFixed(2)}` : '';

  // raggruppa per zona (zone_order già ordinato dal server)
  const zones = {};
  items.forEach(it => { const z=it.zone||'scaffali'; (zones[z]??=[]).push(it); });

  main.innerHTML = '';
  Object.entries(zones).forEach(([zone, zItems]) => {
    const section = document.createElement('section');
    section.className = 'lista-zone';
    section.innerHTML = `<h2 class="lista-zone-title">${ZONE_EMOJI[zone]||'📦'} ${zone}</h2>`;
    zItems.forEach(it => {
      const row = document.createElement('label');
      row.className = 'lista-item' + (it.checked ? ' lista-checked' : '');
      row.dataset.id = it.id;
      row.innerHTML = `
        <input type="checkbox" class="lista-check" ${it.checked?'checked':''}>
        <span class="lista-name">${it.ingredient_name}</span>
        ${it.quantity?`<span class="lista-qty">${it.quantity}${it.unit||''}</span>`:''}
        ${it.checked&&it.checked_by?`<span class="lista-author" title="Spuntato da">✓</span>`:''}`;
      row.querySelector('.lista-check').addEventListener('change', async function() {
        const checked = this.checked ? 1 : 0;
        row.classList.toggle('lista-checked', !!checked);
        it.checked = checked;
        render(); // sposta in fondo
        await apiPost('shopping_check', {id: it.id, checked});
      });
      section.appendChild(row);
    });
    main.appendChild(section);
  });
}

document.getElementById('btn-refresh').addEventListener('click', refresh);

document.getElementById('btn-reset').addEventListener('click', async () => {
  if (!confirm('Deselezionare tutti gli articoli?')) return;
  await apiPost('shopping_reset_checks', {week_start: WEEK});
  await loadFull();
});

// avvio + ticker "X secondi fa"
loadFull().then(() => {
  ticker = setInterval(updateLastUpdateLabel, 1000);
});
window.addEventListener('beforeunload', () => clearInterval(ticker));
</script>
</body>
</html>
