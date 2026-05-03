/* ── Dispensa (Pantry) — pantry.js ── */

const API  = 'api.php';
const CSRF = () => document.querySelector('meta[name="csrf-token"]')?.content || '';

async function get(action, params = {}) {
  const qs  = new URLSearchParams({ action, ...params });
  const res = await fetch(`${API}?${qs}`);
  return res.json();
}
async function post(action, data = {}) {
  const res = await fetch(API, {
    method:  'POST',
    headers: { 'Content-Type': 'application/json' },
    body:    JSON.stringify({ action, csrf_token: CSRF(), ...data }),
  });
  return res.json();
}

function showToast(html, duration = 2800) {
  const t = document.getElementById('toast');
  t.innerHTML = html; t.classList.add('show');
  setTimeout(() => t.classList.remove('show'), duration);
}

const ZONE_EMOJI = {
  ortofrutta:'🥦', pane:'🥖', macelleria:'🥩', pesce:'🐟',
  latticini:'🧀', scaffali:'🛒', bevande:'🍾', surgelati:'❄️',
  casalinghi:'🏠', altro:'📦',
};

let allItems = [];

// ── Render ────────────────────────────────────────────────────────────────────
function renderPantry(items) {
  const container = document.getElementById('pantry-list');
  container.innerHTML = '';
  if (!items.length) {
    container.innerHTML = '<p style="color:var(--ink-muted,#999);text-align:center;padding:2rem">Dispensa vuota. Aggiungi articoli o importa dalla lista spesa.</p>';
    return;
  }

  const zones = {};
  items.forEach(it => { (zones[it.zone || 'scaffali'] ??= []).push(it); });

  Object.entries(zones).forEach(([zone, zItems]) => {
    const group = document.createElement('div');
    group.style.cssText = 'margin-bottom:1.25rem';
    const title = document.createElement('div');
    title.style.cssText = 'font-weight:600;font-size:.8rem;text-transform:uppercase;letter-spacing:.05em;color:var(--ink-muted,#888);padding:.3rem 0;border-bottom:1px solid #e0d8cc;margin-bottom:.5rem';
    title.textContent = (ZONE_EMOJI[zone] || '📦') + ' ' + zone;
    group.appendChild(title);

    zItems.forEach(it => {
      const row = document.createElement('div');
      row.style.cssText = 'display:flex;align-items:center;gap:.6rem;padding:.45rem .25rem;border-bottom:1px solid #f0ebe0;font-size:.9rem';
      row.dataset.id = it.id;

      const expiring = it.expiry_date && new Date(it.expiry_date) < new Date(Date.now() + 3*24*3600*1000);
      const expired  = it.expiry_date && new Date(it.expiry_date) < new Date();

      const nameSpan = document.createElement('span');
      nameSpan.style.cssText = 'flex:1;font-weight:500' + (expired ? ';color:#c84b2d;text-decoration:line-through' : expiring ? ';color:#e8a020' : '');
      nameSpan.textContent = it.ingredient_name;

      const qtySpan = document.createElement('span');
      qtySpan.style.cssText = 'min-width:70px;text-align:right;color:var(--ink-muted,#888);font-size:.82rem';
      qtySpan.textContent = it.quantity != null ? `${it.quantity}${it.unit || ''}` : it.unit || '—';

      const expirySpan = document.createElement('span');
      expirySpan.style.cssText = 'min-width:75px;font-size:.78rem;' + (expired ? 'color:#c84b2d;font-weight:600' : expiring ? 'color:#e8a020' : 'color:#bbb');
      expirySpan.textContent = it.expiry_date ? (expired ? '❌ ' : expiring ? '⚠️ ' : '') + it.expiry_date : '';

      const btnEdit = document.createElement('button');
      btnEdit.textContent = '✏️';
      btnEdit.title = 'Modifica';
      btnEdit.style.cssText = 'background:none;border:none;cursor:pointer;font-size:.9rem;padding:0 .2rem';
      btnEdit.addEventListener('click', () => openForm(it));

      const btnDel = document.createElement('button');
      btnDel.textContent = '🗑';
      btnDel.title = 'Rimuovi';
      btnDel.style.cssText = 'background:none;border:none;cursor:pointer;font-size:.9rem;padding:0 .2rem';
      btnDel.addEventListener('click', async () => {
        if (!confirm(`Rimuovere "${it.ingredient_name}" dalla dispensa?`)) return;
        await post('pantry_delete', { id: it.id });
        await reload();
      });

      row.append(nameSpan, qtySpan, expirySpan, btnEdit, btnDel);
      group.appendChild(row);
    });
    container.appendChild(group);
  });
}

// ── Form ──────────────────────────────────────────────────────────────────────
function openForm(item = null) {
  const wrap = document.getElementById('pantry-form-wrap');
  wrap.style.display = 'block';
  document.getElementById('pf-editing-id').value = item ? item.id : '';
  document.getElementById('pf-name').value   = item ? item.ingredient_name : '';
  document.getElementById('pf-qty').value    = item && item.quantity != null ? item.quantity : '';
  document.getElementById('pf-unit').value   = item ? (item.unit || '') : '';
  document.getElementById('pf-zone').value   = item ? (item.zone || 'scaffali') : 'scaffali';
  document.getElementById('pf-expiry').value = item ? (item.expiry_date || '') : '';
  document.getElementById('pf-name').focus();
}

function closeForm() {
  document.getElementById('pantry-form-wrap').style.display = 'none';
}

async function saveForm() {
  const name   = document.getElementById('pf-name').value.trim();
  if (!name) { showToast('⚠️ Inserisci il nome dell\'ingrediente'); return; }
  const editId = document.getElementById('pf-editing-id').value;
  const zone   = document.getElementById('pf-zone').value;
  const qty    = document.getElementById('pf-qty').value;
  const unit   = document.getElementById('pf-unit').value.trim() || null;
  const expiry = document.getElementById('pf-expiry').value || null;

  let res;
  if (editId) {
    // modifica esistente → pantry_update (UPSERT con scadenza)
    res = await post('pantry_update', {
      id: parseInt(editId),
      ingredient_name: name,
      quantity: qty || null,
      unit: unit || '',
      zone,
      expiry_date: expiry,
    });
  } else {
    // nuovo articolo → pantry_add_manual (arricchisce nutrition_db)
    res = await post('pantry_add_manual', {
      ingredient_name: name,
      quantity: qty || null,
      unit,
      zone,
    });
  }
  if (res.error) { showToast('⚠️ ' + res.error); return; }
  closeForm();
  await reload();
  showToast('✅ Salvato');
}

// ── Import modal ──────────────────────────────────────────────────────────────
function getMondayStr(date = new Date()) {
  const d = new Date(date), day = d.getDay();
  d.setDate(d.getDate() + (day === 0 ? -6 : 1 - day));
  return d.toISOString().slice(0, 10);
}

function openImportModal() {
  const modal = document.getElementById('import-modal');
  modal.style.display = 'flex';
  // pre-fill current week in ISO week format
  const monday = getMondayStr();
  const d = new Date(monday + 'T00:00:00');
  const week = `${d.getFullYear()}-W${String(getISOWeek(d)).padStart(2,'0')}`;
  document.getElementById('import-week-input').value = week;
}

function getISOWeek(d) {
  const date = new Date(Date.UTC(d.getFullYear(), d.getMonth(), d.getDate()));
  const dayNum = date.getUTCDay() || 7;
  date.setUTCDate(date.getUTCDate() + 4 - dayNum);
  const yearStart = new Date(Date.UTC(date.getUTCFullYear(), 0, 1));
  return Math.ceil((((date - yearStart) / 86400000) + 1) / 7);
}

function weekInputToMonday(val) {
  // val = "YYYY-Www"
  const [year, w] = val.split('-W').map(Number);
  const jan4 = new Date(Date.UTC(year, 0, 4));
  const dayOfWeek = jan4.getUTCDay() || 7;
  const monday = new Date(jan4);
  monday.setUTCDate(jan4.getUTCDate() - dayOfWeek + 1 + (w - 1) * 7);
  return monday.toISOString().slice(0, 10);
}

async function confirmImport() {
  const val = document.getElementById('import-week-input').value;
  if (!val) { showToast('⚠️ Seleziona una settimana'); return; }
  const weekStart = weekInputToMonday(val);
  const res = await post('pantry_from_shopping', { week_start: weekStart });
  document.getElementById('import-modal').style.display = 'none';
  if (res.error) { showToast('⚠️ ' + res.error); return; }
  showToast(res.imported ? `✅ Importati ${res.imported} articoli` : '⚠️ Nessun articolo spuntato trovato');
  await reload();
}

// ── Core ──────────────────────────────────────────────────────────────────────
async function reload() {
  const data = await get('pantry_list');
  allItems = Array.isArray(data) ? data : [];
  filterAndRender();
}

function filterAndRender() {
  const q = document.getElementById('pantry-search').value.toLowerCase();
  renderPantry(q ? allItems.filter(it => it.ingredient_name.toLowerCase().includes(q)) : allItems);
}

async function init() {
  try {
    const me = await get('me');
    if (!me.error) {
      document.getElementById('user-label').textContent =
        `${me.user.avatar_emoji || '👤'} ${me.user.name}`;
    }
  } catch { /* ignora errori di rete */ }

  await reload();

  // Autocomplete sul nome ingrediente nella form dispensa
  initAutocomplete(document.getElementById('pf-name'), it => {
    const zoneEl = document.getElementById('pf-zone');
    if (it.zone && zoneEl) zoneEl.value = it.zone;
  }, { showZone: true, showPrice: false });

  document.getElementById('btn-add-item').addEventListener('click', () => openForm());
  document.getElementById('pf-save').addEventListener('click', saveForm);
  document.getElementById('pf-cancel').addEventListener('click', closeForm);
  document.getElementById('pantry-search').addEventListener('input', filterAndRender);
  document.getElementById('btn-from-shopping').addEventListener('click', openImportModal);
  document.getElementById('import-confirm').addEventListener('click', confirmImport);
  document.getElementById('import-cancel').addEventListener('click', () => {
    document.getElementById('import-modal').style.display = 'none';
  });
  document.getElementById('btn-clear-pantry').addEventListener('click', async () => {
    if (!confirm('Svuotare tutta la dispensa? Tutti gli articoli verranno eliminati.')) return;
    const res = await post('pantry_clear');
    if (res.error) { showToast('⚠️ ' + res.error); return; }
    allItems = [];
    filterAndRender();
    showToast('🗑 Dispensa svuotata');
  });
  document.getElementById('btn-logout').addEventListener('click', async () => {
    await post('logout');
    location.reload();
  });

  // Mobile hamburger (no sidebar here, just prevent error)
  document.getElementById('btn-menu')?.addEventListener('click', () => {});
}

document.addEventListener('DOMContentLoaded', init);
