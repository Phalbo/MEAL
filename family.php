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
  <title>Famiglia — Meal Planner</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@600;700&family=DM+Sans:wght@300;400;500&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="style.css">
</head>
<body>
<header class="topbar">
  <div class="topbar-brand"><span class="brand-emoji">🍽️</span><span class="brand-name">Meal Planner</span></div>
  <nav class="topbar-nav">
    <a href="index.php"  class="nav-link">Pianifica</a>
    <a href="admin.php"  class="nav-link">Admin</a>
    <a href="family.php" class="nav-link active">Famiglia</a>
  </nav>
  <div class="topbar-user"><button id="btn-logout" class="btn-ghost-sm">Esci</button></div>
</header>

<div class="family-layout">
  <h1>Gestione famiglia</h1>

  <div class="form-card" id="card-info">
    <h2>Informazioni famiglia</h2>
    <p style="margin:.5rem 0 .25rem;font-size:.85rem;color:var(--ink-muted)">Codice invito — condividilo con i tuoi familiari:</p>
    <div id="invite-code" class="invite-code-box">…</div>
    <p style="margin-top:.75rem;font-size:.82rem;color:var(--ink-muted)">
      Nome famiglia: <strong id="family-name"></strong>
    </p>
  </div>

  <div class="form-card">
    <h2>Profili</h2>
    <div id="profiles-list" style="display:flex;flex-direction:column;gap:.5rem;margin-bottom:1rem"></div>
    <div style="display:flex;gap:.5rem;flex-wrap:wrap;align-items:flex-end">
      <div class="form-group" style="flex:1;min-width:130px">
        <label>Nome</label><input type="text" id="p-name" placeholder="Es. Bianca">
      </div>
      <div class="form-group">
        <label>Tipo</label>
        <select id="p-type">
          <option value="adult">Adulto (1.0)</option>
          <option value="child">Bambino (0.6)</option>
        </select>
      </div>
      <div class="form-group" style="width:70px">
        <label>Porzione</label><input type="number" id="p-weight" value="1.0" step="0.1" min="0.1" max="3">
      </div>
      <div class="form-group" style="width:60px">
        <label>Avatar</label><input type="text" id="p-emoji" value="👤" maxlength="4">
      </div>
      <button class="btn-primary" id="btn-add-profile">+ Aggiungi</button>
    </div>
  </div>

  <div class="form-card">
    <h2>Membri account</h2>
    <div id="members-list" style="display:flex;flex-direction:column;gap:.4rem"></div>
  </div>
</div>

<div id="toast" class="toast"></div>
<script>
const API  = 'api.php';
const CSRF = () => document.querySelector('meta[name="csrf-token"]').content;

async function get(action, p={}) { return (await fetch(`${API}?${new URLSearchParams({action,...p})}`)).json(); }
async function post(action, data={}) {
  return (await fetch(API,{method:'POST',headers:{'Content-Type':'application/json'},
    body:JSON.stringify({action,csrf_token:CSRF(),...data})})).json();
}

function showToast(msg){const t=document.getElementById('toast');t.textContent=msg;t.classList.add('show');setTimeout(()=>t.classList.remove('show'),2800);}

async function load() {
  const me = await get('me');
  document.getElementById('family-name').textContent  = me.family?.name || '';
  document.getElementById('invite-code').textContent  = me.family?.invite_code || '';

  const profiles = await get('profiles_list');
  const plist    = document.getElementById('profiles-list');
  const totalW   = profiles.reduce((s,p) => s + parseFloat(p.portion_weight), 0);
  plist.innerHTML = profiles.length ? '' : '<p style="font-size:.82rem;color:var(--ink-muted)">Nessun profilo.</p>';
  profiles.forEach(p => {
    const row = document.createElement('div');
    row.style = 'display:flex;align-items:center;gap:.75rem;padding:.4rem .6rem;background:var(--cream);border-radius:6px';
    row.innerHTML = `<span style="font-size:1.2rem">${p.avatar_emoji}</span>
      <span style="flex:1;font-weight:500">${p.name}</span>
      <span style="font-size:.78rem;color:var(--ink-muted)">${p.type} — ${p.portion_weight} porz.</span>
      <button class="btn-delete" data-id="${p.id}" style="padding:.2rem .5rem">🗑</button>`;
    row.querySelector('.btn-delete').addEventListener('click', async () => {
      await post('profiles_delete', {id: p.id}); load();
    });
    plist.appendChild(row);
  });
  document.getElementById('profiles-list').insertAdjacentHTML('beforeend',
    `<p style="font-size:.78rem;color:var(--ink-muted);margin-top:.25rem">Totale porzioni: <strong>${totalW.toFixed(1)}</strong></p>`);

  const members = await get('family_members');
  const mlist   = document.getElementById('members-list');
  mlist.innerHTML = members.map(m =>
    `<div style="display:flex;align-items:center;gap:.5rem;font-size:.85rem">
      <span>${m.avatar_emoji||'👤'}</span><strong>${m.name}</strong>
      <span style="color:var(--ink-muted);font-size:.78rem">${m.email}</span>
    </div>`).join('');
}

document.getElementById('btn-add-profile').addEventListener('click', async () => {
  const name = document.getElementById('p-name').value.trim();
  if (!name) return showToast('⚠️ Nome obbligatorio');
  const type   = document.getElementById('p-type').value;
  const weight = parseFloat(document.getElementById('p-weight').value) || (type==='child' ? 0.6 : 1.0);
  const emoji  = document.getElementById('p-emoji').value || '👤';
  const d = await post('profiles_add', {name, type, portion_weight: weight, avatar_emoji: emoji});
  if (d.error) return showToast('❌ ' + d.error);
  document.getElementById('p-name').value = '';
  showToast('✅ Profilo aggiunto'); load();
});

document.getElementById('btn-logout').addEventListener('click', async () => {
  await post('logout'); location.href = 'login.php';
});

document.addEventListener('DOMContentLoaded', load);
</script>
</body>
</html>
