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
  <title>Famiglia — Meal Planner</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="stylesheet" href="style.css">
</head>
<body>
<header class="topbar">
  <div class="topbar-brand"><span class="brand-emoji">🍽️</span><span class="brand-name">Meal Planner</span></div>
  <nav class="topbar-nav">
    <a href="index.php"  class="nav-link">📅 Pianifica</a>
    <a href="admin.php"  class="nav-link">🍝 Ricette</a>
    <a href="family.php" class="nav-link active">👥 Famiglia</a>
    <a href="lista.php"  class="nav-link">🛒 Spesa</a>
    <a href="pantry.php"         class="nav-link">🏪 Dispensa</a>
    <a href="ingredienti.php"   class="nav-link">🧂 Ingredienti</a>
    <a href="export_import.php" class="nav-link">📦 Import/Export</a>
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

const INTOL_PRESETS = [
  'lattosio','glutine','nichel','uova crude','peperoni',
  'arachidi','frutta secca','crostacei','soia','senape',
];

async function get(action, p={}) { return (await fetch(`${API}?${new URLSearchParams({action,...p})}`)).json(); }
async function post(action, data={}) {
  return (await fetch(API,{method:'POST',headers:{'Content-Type':'application/json'},
    body:JSON.stringify({action,csrf_token:CSRF(),...data})})).json();
}

function showToast(msg){const t=document.getElementById('toast');t.textContent=msg;t.classList.add('show');setTimeout(()=>t.classList.remove('show'),2800);}

function renderProfileCard(p, plist) {
  const card = document.createElement('div');
  card.className = 'form-card';
  card.style = 'margin-bottom:.75rem;padding:1rem';

  // header profilo
  const header = document.createElement('div');
  header.style = 'display:flex;align-items:center;gap:.75rem;margin-bottom:.6rem';
  header.innerHTML = `
    <span style="font-size:1.4rem">${p.avatar_emoji}</span>
    <div style="flex:1">
      <strong>${p.name}</strong>
      <span style="font-size:.78rem;color:var(--ink-muted);margin-left:.5rem">${p.type} — ${p.portion_weight} porz.</span>
    </div>
    <button class="btn-delete btn-del-profile" data-id="${p.id}" style="padding:.25rem .6rem">🗑 Rimuovi</button>`;
  card.appendChild(header);

  // tag intolleranze attive
  const tagsDiv = document.createElement('div');
  tagsDiv.style = 'display:flex;flex-wrap:wrap;gap:.3rem;min-height:1.5rem';
  (p.intolerances || []).forEach(label => {
    const tag = document.createElement('span');
    tag.className = 'intol-tag';
    tag.innerHTML = `${label} <button data-label="${label}" data-pid="${p.id}" title="Rimuovi">×</button>`;
    tag.querySelector('button').addEventListener('click', async function() {
      // trova id intolleranza dal server cercando per profilo
      const profiles = await get('profiles_list');
      const prof     = profiles.find(x => x.id == p.id);
      // non abbiamo l'id diretto — usiamo intolerance_delete via label
      // workaround: delete by label using a custom action
      const all = await get('intolerance_list_by_profile', {profile_id: p.id});
      const found = Array.isArray(all) ? all.find(i => i.label === label) : null;
      if (found) { await post('intolerance_delete', {id: found.id}); }
      load();
    });
    tagsDiv.appendChild(tag);
  });
  if (!(p.intolerances||[]).length) tagsDiv.innerHTML = '<span style="font-size:.78rem;color:var(--ink-muted)">Nessuna intolleranza</span>';
  card.appendChild(tagsDiv);

  // preset buttons per aggiungere
  const presetsDiv = document.createElement('div');
  presetsDiv.className = 'intol-presets';
  INTOL_PRESETS.forEach(preset => {
    if ((p.intolerances||[]).includes(preset)) return; // già attiva
    const btn = document.createElement('button');
    btn.className = 'preset-btn'; btn.textContent = '+ ' + preset;
    btn.addEventListener('click', async () => {
      const d = await post('intolerance_add', {profile_id: p.id, label: preset});
      if (d.error) return showToast('❌ ' + d.error);
      load();
    });
    presetsDiv.appendChild(btn);
  });
  card.appendChild(presetsDiv);

  card.querySelector('.btn-del-profile').addEventListener('click', async () => {
    if (!confirm(`Rimuovere il profilo "${p.name}"?`)) return;
    await post('profiles_delete', {id: p.id}); load();
  });
  plist.appendChild(card);
}

async function load() {
  const me = await get('me');
  document.getElementById('family-name').textContent = me.family?.name || '';
  document.getElementById('invite-code').textContent = me.family?.invite_code || '';

  const profiles = await get('profiles_list');
  const plist    = document.getElementById('profiles-list');
  const totalW   = profiles.reduce((s,p) => s + parseFloat(p.portion_weight), 0);
  plist.innerHTML = '';
  if (!profiles.length) plist.innerHTML = '<p style="font-size:.82rem;color:var(--ink-muted)">Nessun profilo.</p>';
  profiles.forEach(p => renderProfileCard(p, plist));
  plist.insertAdjacentHTML('beforeend',
    `<p style="font-size:.78rem;color:var(--ink-muted);margin-top:.25rem">Totale porzioni: <strong>${totalW.toFixed(1)}</strong></p>`);

  const members = await get('family_members');
  document.getElementById('members-list').innerHTML = members.map(m =>
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
  document.getElementById('p-name').value = ''; showToast('✅ Profilo aggiunto'); load();
});

document.getElementById('btn-logout').addEventListener('click', async () => {
  await post('logout'); location.href = 'login.php';
});

document.addEventListener('DOMContentLoaded', load);
</script>

<nav class="bottom-nav">
  <div class="bottom-nav-inner">
    <a href="index.php"  class="bottom-nav-item"><span class="bn-icon">📅</span>Pianifica</a>
    <a href="admin.php"  class="bottom-nav-item"><span class="bn-icon">🍝</span>Ricette</a>
    <button class="bottom-nav-fab" style="visibility:hidden;pointer-events:none"></button>
    <a href="lista.php"  class="bottom-nav-item"><span class="bn-icon">🛒</span>Spesa</a>
    <a href="family.php" class="bottom-nav-item active"><span class="bn-icon">👥</span>Famiglia</a>
  </div>
</nav>
</body>
</html>
