/* ── Lista spesa — lista.js ── */

const API      = 'api.php';
const CSRF     = () => document.querySelector('meta[name="csrf-token"]').content;
const ZONE_EMOJI = {
  ortofrutta:'🥦', pane:'🥖', macelleria:'🥩', pesce:'🐟',
  latticini:'🧀', scaffali:'🛒', bevande:'🍾', surgelati:'❄️',
  casalinghi:'🏠', altro:'📦',
};

let items      = [];
let lastSince  = null;
let lastLoadAt = null;
let ticker     = null;

async function apiFetch(action, params = {}) {
  const qs = new URLSearchParams({ action, ...params });
  return (await fetch(`${API}?${qs}`)).json();
}
async function apiPost(action, data = {}) {
  return (await fetch(API, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ action, csrf_token: CSRF(), ...data }),
  })).json();
}

function formatWeek(w) {
  const d   = new Date(w + 'T00:00:00');
  const end = new Date(d); end.setDate(end.getDate() + 6);
  return `${d.toLocaleDateString('it-IT', { day:'numeric', month:'short' })} – ${end.toLocaleDateString('it-IT', { day:'numeric', month:'short' })}`;
}

function updateLastUpdateLabel() {
  if (!lastLoadAt) return;
  const sec = Math.floor((Date.now() - lastLoadAt) / 1000);
  const el  = document.getElementById('last-update');
  el.textContent = sec < 60 ? `Aggiornato ${sec}s fa` : `Aggiornato ${Math.floor(sec / 60)}m fa`;
}

async function loadFull() {
  const data = await apiFetch('shopping_list', { week_start: WEEK });
  items      = Array.isArray(data) ? data : [];
  lastSince  = new Date().toISOString().replace('T', ' ').slice(0, 19);
  lastLoadAt = Date.now();
  setBadge(true);
  render();
  updateLastUpdateLabel();
}

async function refresh() {
  const btn = document.getElementById('btn-refresh');
  btn.disabled = true; btn.textContent = '⏳';
  try {
    const delta = await apiFetch('shopping_list', { week_start: WEEK, since: lastSince });
    const now   = new Date().toISOString().replace('T', ' ').slice(0, 19);
    if (Array.isArray(delta) && delta.length) {
      delta.forEach(upd => {
        const idx = items.findIndex(i => i.id === upd.id);
        if (idx >= 0) items[idx] = { ...items[idx], ...upd };
        else items.push(upd);
      });
    }
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

// ── Costruisce una riga item con editing inline ──────────────────────────────
function makeRow(it) {
  const row = document.createElement('label');
  row.className = 'lista-item' + (it.checked ? ' lista-checked' : '');
  row.dataset.id = it.id;

  // Checkbox
  const chk = document.createElement('input');
  chk.type = 'checkbox';
  chk.className = 'lista-check';
  chk.checked = !!it.checked;
  chk.addEventListener('change', async () => {
    const checked = chk.checked ? 1 : 0;
    row.classList.toggle('lista-checked', !!checked);
    it.checked = checked;
    render();
    await apiPost('shopping_check', { id: it.id, checked });
  });

  // Nome
  const nameSpan = document.createElement('span');
  nameSpan.className = 'lista-name';
  nameSpan.textContent = it.ingredient_name;

  // Qty + unit editabile
  const qtySpan = document.createElement('span');
  qtySpan.className = 'lista-qty lista-editable';
  qtySpan.title = 'Clicca per modificare';
  qtySpan.textContent = it.quantity ? `${it.quantity}${it.unit ? ' ' + it.unit : ''}` : (it.unit || '');
  qtySpan.addEventListener('click', e => {
    e.preventDefault();
    const qInp = document.createElement('input');
    qInp.type = 'number'; qInp.step = '0.1';
    qInp.value = it.quantity != null ? it.quantity : '';
    qInp.style.cssText = 'width:4.5rem;font-size:.82rem;padding:2px 4px';
    qInp.placeholder = 'qtà';
    const uInp = document.createElement('input');
    uInp.type = 'text';
    uInp.value = it.unit || '';
    uInp.style.cssText = 'width:3rem;font-size:.82rem;padding:2px 4px;margin-left:2px';
    uInp.placeholder = 'un.';
    qtySpan.replaceWith(qInp);
    qInp.after(uInp);
    qInp.focus();
    let saving = false;
    const save = async () => {
      if (saving) return; saving = true;
      const qty  = qInp.value !== '' ? parseFloat(qInp.value) : null;
      const unit = uInp.value.trim() || null;
      it.quantity = qty; it.unit = unit;
      await apiPost('shopping_update_item', { id: it.id, quantity: qty, unit });
      render();
    };
    qInp.addEventListener('blur', () => setTimeout(save, 120));
    uInp.addEventListener('blur', () => setTimeout(save, 120));
    qInp.addEventListener('keydown', e => { if (e.key === 'Enter') { uInp.focus(); } if (e.key === 'Escape') render(); });
    uInp.addEventListener('keydown', e => { if (e.key === 'Enter') save(); if (e.key === 'Escape') render(); });
  });

  // Prezzo editabile (price_actual > price_est come fallback)
  const priceActual = parseFloat(it.price_actual) || 0;
  const priceEst    = parseFloat(it.price_est)    || 0;
  const priceShown  = priceActual || priceEst;
  const priceSpan   = document.createElement('span');
  priceSpan.className = 'lista-price lista-editable' + (priceActual ? '' : ' lista-price-est');
  priceSpan.title = priceActual ? 'Prezzo reale — clicca per modificare' : 'Prezzo stimato — clicca per inserire reale';
  priceSpan.textContent = priceShown > 0 ? `€${priceShown.toFixed(2)}` : '';
  priceSpan.addEventListener('click', e => {
    e.preventDefault();
    const inp = document.createElement('input');
    inp.type = 'number'; inp.step = '0.01'; inp.min = '0';
    inp.value = priceActual || '';
    inp.style.cssText = 'width:5.5rem;font-size:.82rem;padding:2px 4px';
    inp.placeholder = '€';
    priceSpan.replaceWith(inp);
    inp.focus();
    const save = async () => {
      const p = parseFloat(inp.value) || 0;
      it.price_actual = p || null;
      await apiPost('shopping_price_update', { id: it.id, price_actual: p });
      render();
    };
    inp.addEventListener('blur', save);
    inp.addEventListener('keydown', e => { if (e.key === 'Enter') save(); if (e.key === 'Escape') render(); });
  });

  row.append(chk, nameSpan, qtySpan, priceSpan);

  if (it.checked && it.checked_by_emoji) {
    const author = document.createElement('span');
    author.className = 'lista-author';
    author.title = 'Spuntato da';
    author.textContent = it.checked_by_emoji;
    row.appendChild(author);
  }

  return row;
}

function render() {
  const main = document.getElementById('lista-main');
  if (!items.length) {
    main.innerHTML = '<p class="lista-empty">Lista vuota — genera la lista dal planner o aggiungi articoli qui sopra.</p>';
    document.getElementById('lista-total').textContent = '💰 €0.00';
    return;
  }

  document.getElementById('lista-week-label').textContent = formatWeek(WEEK);
  const total = items.reduce((s, i) => s + (parseFloat(i.price_actual) || parseFloat(i.price_est) || 0), 0);
  document.getElementById('lista-total').textContent = `💰 €${total.toFixed(2)}`;

  // ordina: non spuntati prima, poi per zone_order
  const sorted = [...items].sort((a, b) => {
    if (!!a.checked !== !!b.checked) return a.checked ? 1 : -1;
    return (a.zone_order ?? 9) - (b.zone_order ?? 9) || a.ingredient_name.localeCompare(b.ingredient_name);
  });

  const zones = {};
  sorted.forEach(it => { const z = it.zone || 'scaffali'; (zones[z] ??= []).push(it); });

  main.innerHTML = '';
  Object.entries(zones).forEach(([zone, zItems]) => {
    const section = document.createElement('section');
    section.className = 'lista-zone';
    const h2 = document.createElement('h2');
    h2.className = 'lista-zone-title';
    h2.textContent = `${ZONE_EMOJI[zone] || '📦'} ${zone}`;
    section.appendChild(h2);
    zItems.forEach(it => section.appendChild(makeRow(it)));
    main.appendChild(section);
  });
}

// ── Aggiunta manuale ─────────────────────────────────────────────────────────
async function addManualItem() {
  const name = document.getElementById('add-name').value.trim();
  const qty  = document.getElementById('add-qty').value;
  const unit = document.getElementById('add-unit').value.trim();
  const zone = document.getElementById('add-zone').value;

  if (!name) { document.getElementById('add-name').focus(); return; }

  const btn = document.getElementById('btn-add-item');
  btn.disabled = true; btn.textContent = '⏳';
  try {
    const d = await apiPost('shopping_add_manual', {
      name, quantity: qty || null, unit: unit || null, zone, week_start: WEEK,
    });
    if (d.error) { alert('Errore: ' + d.error); return; }
    items.push(d);
    render();
    document.getElementById('add-name').value = '';
    document.getElementById('add-qty').value  = '';
    document.getElementById('add-unit').value = '';
    document.getElementById('add-name').focus();
  } finally {
    btn.disabled = false; btn.textContent = '+ Aggiungi';
  }
}

// ── Event listeners ──────────────────────────────────────────────────────────
document.getElementById('btn-refresh').addEventListener('click', refresh);

document.getElementById('btn-add-item').addEventListener('click', addManualItem);
document.getElementById('add-name').addEventListener('keydown', e => {
  if (e.key === 'Enter') addManualItem();
});

document.getElementById('btn-share').addEventListener('click', async () => {
  const data = await apiFetch('family_share_token');
  if (data.error) { alert('Errore: ' + data.error); return; }
  const base = location.href.replace(/[^/]*$/, '');
  const url  = `${base}lista_pub.php?token=${data.token}&week=${WEEK}`;
  if (navigator.clipboard) {
    navigator.clipboard.writeText(url).then(() => alert('🔗 Link copiato!'));
  } else {
    prompt('Copia questo link:', url);
  }
});

document.getElementById('btn-reset').addEventListener('click', async () => {
  if (!confirm('Deselezionare tutti gli articoli?')) return;
  await apiPost('shopping_reset_checks', { week_start: WEEK });
  await loadFull();
});

document.getElementById('btn-clear-checked').addEventListener('click', async () => {
  if (!confirm('Eliminare tutti gli articoli già acquistati?')) return;
  await apiPost('shopping_clear', { week_start: WEEK, mode: 'checked' });
  items = items.filter(i => !i.checked);
  render();
});

document.getElementById('btn-clear-all').addEventListener('click', async () => {
  if (!confirm('Svuotare tutta la lista? Verranno eliminati tutti gli articoli.')) return;
  await apiPost('shopping_clear', { week_start: WEEK, mode: 'all' });
  items = [];
  render();
});

// ── Autocomplete su "Aggiungi articolo" ──────────────────────────────────────
initAutocomplete(document.getElementById('add-name'), it => {
  if (it.zone) document.getElementById('add-zone').value = it.zone;
  document.getElementById('add-qty').focus();
}, { showZone: true, showPrice: true });

// ── Cerca ricetta + preview ingredienti ─────────────────────────────────────
let allMeals = [];

async function loadMeals() {
  const d = await apiFetch('meals_list');
  allMeals = Array.isArray(d) ? d : [];
}

function showRecipeSuggestions(query) {
  const box = document.getElementById('recipe-suggestions');
  const q   = query.trim().toLowerCase();
  if (!q) { box.style.display = 'none'; return; }

  const matches = allMeals.filter(m => m.name.toLowerCase().includes(q)).slice(0, 8);
  if (!matches.length) { box.style.display = 'none'; return; }

  box.innerHTML = '';
  matches.forEach(m => {
    const item = document.createElement('div');
    item.className = 'recipe-suggestion-item';
    item.textContent = `${m.emoji || '🍽️'} ${m.name}`;
    item.addEventListener('mousedown', e => {
      e.preventDefault();
      box.style.display = 'none';
      document.getElementById('recipe-search').value = '';
      openRecipeModal(m.id, m.name, m.emoji);
    });
    box.appendChild(item);
  });
  box.style.display = 'block';
}

async function openRecipeModal(mealId, mealName, mealEmoji) {
  const modal = document.getElementById('recipe-modal');
  const body  = document.getElementById('recipe-modal-body');
  const title = document.getElementById('recipe-modal-title');

  title.textContent = `${mealEmoji || '🍽️'} ${mealName}`;
  body.innerHTML = '<p style="padding:.75rem;color:var(--text-secondary)">Caricamento…</p>';
  modal.style.display = 'flex';

  const d = await apiFetch('meal_ingredients_preview', { meal_id: mealId });
  if (d.error) { body.innerHTML = `<p style="color:red;padding:.75rem">${d.error}</p>`; return; }

  const ings = d.ingredients || [];
  if (!ings.length) {
    body.innerHTML = '<p style="padding:.75rem;color:var(--text-secondary)">Nessun ingrediente registrato.</p>';
    return;
  }

  body.innerHTML = '';
  ings.forEach((ing, i) => {
    const label = document.createElement('label');
    label.className = 'recipe-ing-row';
    label.innerHTML = `
      <input type="checkbox" class="recipe-ing-check" data-idx="${i}" checked>
      <span class="recipe-ing-name">${ing.name}</span>
      ${ing.quantity ? `<span class="recipe-ing-qty">${ing.quantity}${ing.unit ? ' ' + ing.unit : ''}</span>` : ''}
      <span class="recipe-ing-zone">${ZONE_EMOJI[ing.zone] || '📦'}</span>`;
    label._ing = ing;
    body.appendChild(label);
  });

  document.getElementById('recipe-modal-add').onclick = async () => {
    const checked = [...body.querySelectorAll('.recipe-ing-check:checked')].map(cb => cb.closest('label')._ing);
    if (!checked.length) return;
    document.getElementById('recipe-modal-add').disabled = true;
    document.getElementById('recipe-modal-add').textContent = '⏳';
    for (const ing of checked) {
      const d = await apiPost('shopping_add_manual', {
        name: ing.name, quantity: ing.quantity || null,
        unit: ing.unit || null, zone: ing.zone || 'scaffali', week_start: WEEK,
      });
      if (!d.error) items.push(d);
    }
    modal.style.display = 'none';
    render();
    document.getElementById('recipe-modal-add').disabled = false;
    document.getElementById('recipe-modal-add').textContent = '➕ Aggiungi selezionati';
  };
}

document.getElementById('recipe-search').addEventListener('input', e => {
  showRecipeSuggestions(e.target.value);
});
document.getElementById('recipe-search').addEventListener('blur', () => {
  setTimeout(() => { document.getElementById('recipe-suggestions').style.display = 'none'; }, 150);
});
document.getElementById('recipe-modal-close').addEventListener('click',  () => { document.getElementById('recipe-modal').style.display = 'none'; });
document.getElementById('recipe-modal-cancel').addEventListener('click', () => { document.getElementById('recipe-modal').style.display = 'none'; });
document.getElementById('recipe-modal').addEventListener('click', e => {
  if (e.target === document.getElementById('recipe-modal'))
    document.getElementById('recipe-modal').style.display = 'none';
});

// ── Avvio ────────────────────────────────────────────────────────────────────
loadFull().then(() => {
  ticker = setInterval(updateLastUpdateLabel, 1000);
});
loadMeals();
window.addEventListener('beforeunload', () => clearInterval(ticker));
