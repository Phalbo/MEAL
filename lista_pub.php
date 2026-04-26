<?php
$token     = htmlspecialchars($_GET['token'] ?? '', ENT_QUOTES);
$weekStart = htmlspecialchars($_GET['week']  ?? date('Y-m-d', strtotime('monday this week')), ENT_QUOTES);
if (!$token) { http_response_code(403); die('Link non valido.'); }
?>
<!DOCTYPE html>
<html lang="it">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1">
  <meta name="theme-color" content="#1C1C1A">
  <title>🛒 Lista spesa condivisa</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="style.css">
  <link rel="stylesheet" href="lista.css">
</head>
<body class="lista-body">

<header class="lista-header">
  <div class="lista-header-top">
    <span class="lista-back">🛒 Lista condivisa</span>
    <span id="live-badge" class="live-badge">🔴 LIVE</span>
  </div>
  <div class="lista-header-info">
    <h1 class="lista-title">Lista della spesa</h1>
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
const API        = 'api.php';
const TOKEN      = '<?= $token ?>';
const WEEK       = '<?= $weekStart ?>';
const ZONE_EMOJI = { ortofrutta:'🥦',pane:'🥖',macelleria:'🥩',pesce:'🐟',
                     latticini:'🧀',scaffali:'🛒',bevande:'🍾',surgelati:'❄️',altro:'📦' };

let items      = [];
let lastSince  = null;
let lastLoadAt = null;
let ticker     = null;

async function apiFetch(params = {}) {
  const qs = new URLSearchParams({action: 'shopping_list_pub', token: TOKEN, week_start: WEEK, ...params});
  return (await fetch(`${API}?${qs}`)).json();
}
async function apiCheck(id, checked) {
  return (await fetch(API, {
    method: 'POST',
    headers: {'Content-Type': 'application/json'},
    body: JSON.stringify({action: 'shopping_check_pub', token: TOKEN, id, checked}),
  })).json();
}

function formatWeek(w) {
  const d   = new Date(w + 'T00:00:00');
  const end = new Date(d); end.setDate(end.getDate() + 6);
  return `${d.toLocaleDateString('it-IT',{day:'numeric',month:'short'})} – ${end.toLocaleDateString('it-IT',{day:'numeric',month:'short'})}`;
}
function updateLastUpdateLabel() {
  if (!lastLoadAt) return;
  const sec = Math.floor((Date.now() - lastLoadAt) / 1000);
  document.getElementById('last-update').textContent =
    sec < 60 ? `Aggiornato ${sec}s fa` : `Aggiornato ${Math.floor(sec/60)}m fa`;
}

async function loadFull() {
  try {
    const data = await apiFetch();
    items = Array.isArray(data) ? data : [];
    lastSince  = new Date().toISOString().replace('T',' ').slice(0,19);
    lastLoadAt = Date.now();
    setBadge(true); render(); updateLastUpdateLabel();
  } catch { setBadge(false); }
}

async function refresh() {
  const btn = document.getElementById('btn-refresh');
  btn.disabled = true; btn.textContent = '⏳';
  try {
    const delta = await apiFetch({since: lastSince});
    const now   = new Date().toISOString().replace('T',' ').slice(0,19);
    if (Array.isArray(delta) && delta.length)
      delta.forEach(upd => { const idx = items.findIndex(i => i.id === upd.id); if (idx >= 0) items[idx] = {...items[idx], ...upd}; });
    lastSince = now; lastLoadAt = Date.now();
    setBadge(true); render(); updateLastUpdateLabel();
  } catch { setBadge(false); }
  finally { btn.disabled = false; btn.textContent = '🔄 Aggiorna'; }
}

function setBadge(online) {
  const b = document.getElementById('live-badge');
  b.textContent = online ? '🔴 LIVE' : '⚪ OFFLINE';
  b.className   = 'live-badge' + (online ? ' live-on' : ' live-off');
}

function render() {
  const main = document.getElementById('lista-main');
  if (!items.length) { main.innerHTML='<p class="lista-empty">Lista vuota.</p>'; return; }

  document.getElementById('lista-week-label').textContent = formatWeek(WEEK);
  const total = items.reduce((s,i) => s+(parseFloat(i.price_actual)||parseFloat(i.price_est)||0), 0);
  document.getElementById('lista-total').textContent = total > 0 ? `💰 €${total.toFixed(2)}` : '';

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
        ${it.checked&&it.checked_by?`<span class="lista-author">${it.checked_by_emoji||'✓'}</span>`:''}`;
      row.querySelector('.lista-check').addEventListener('change', async function() {
        const checked = this.checked ? 1 : 0;
        row.classList.toggle('lista-checked', !!checked);
        it.checked = checked;
        render();
        await apiCheck(it.id, checked);
      });
      section.appendChild(row);
    });
    main.appendChild(section);
  });
}

document.getElementById('btn-refresh').addEventListener('click', refresh);
document.getElementById('btn-reset').addEventListener('click', async () => {
  if (!confirm('Deselezionare tutti gli articoli?')) return;
  const toReset = items.filter(it => it.checked);
  items.forEach(it => { it.checked = 0; it.checked_by = null; });
  render();
  await Promise.all(toReset.map(it => apiCheck(it.id, 0)));
});

loadFull().then(() => { ticker = setInterval(updateLastUpdateLabel, 1000); });
window.addEventListener('beforeunload', () => clearInterval(ticker));
</script>
</body>
</html>
