/* ── Meal Planner v2.0 — admin.js ── */

const API  = 'api.php';
const CSRF = () => document.querySelector('meta[name="csrf-token"]')?.content || '';

let allMeals = [];

async function get(action, params = {}) {
  const qs = new URLSearchParams({ action, ...params });
  return (await fetch(`${API}?${qs}`)).json();
}
async function post(action, data = {}) {
  return (await fetch(API, {
    method: 'POST', headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ action, csrf_token: CSRF(), ...data }),
  })).json();
}

// ── Init ──────────────────────────────────────────────────────────────────────
async function init() {
  await loadMeals();
  bindForm();
  addIngredientRow();
  document.getElementById('btn-logout').addEventListener('click', async () => {
    await post('logout'); location.href = 'login.php';
  });
}

async function loadMeals() {
  try { allMeals = await get('meals_list'); renderTable(allMeals); }
  catch { showToast('⚠️ Errore caricamento'); }
}

// ── Table ─────────────────────────────────────────────────────────────────────
function renderTable(meals) {
  const tbody = document.getElementById('admin-tbody');
  tbody.innerHTML = '';
  if (!meals.length) {
    tbody.innerHTML = '<tr><td colspan="6" class="no-results">Nessun piatto trovato.</td></tr>'; return;
  }
  meals.forEach(meal => {
    const tr = document.createElement('tr');
    tr.innerHTML = `
      <td style="font-size:1.3rem">${meal.emoji}</td>
      <td><strong>${meal.name}</strong></td>
      <td><span class="tag-cat">${meal.category || ''}</span></td>
      <td>${meal.cal_per_adult} kcal</td>
      <td style="font-size:.78rem;color:var(--ink-muted)">${(meal.ingredients||[]).length} ing.</td>
      <td><div class="tbl-actions">
        <button class="btn-edit"   data-id="${meal.id}">✏️ Modifica</button>
        <button class="btn-delete" data-id="${meal.id}">🗑 Elimina</button>
      </div></td>`;
    tr.querySelector('.btn-edit').addEventListener('click',   () => startEdit(meal));
    tr.querySelector('.btn-delete').addEventListener('click', () => deleteMeal(meal.id, meal.name));
    tbody.appendChild(tr);
  });
}

// ── Ingredient rows (strutturati: nome | qtà | unità) ─────────────────────────
function addIngredientRow(ing = {}) {
  const list = document.getElementById('ingredients-list');
  const row  = document.createElement('div');
  row.className = 'ingredient-row';
  row.innerHTML = `
    <input class="ing-name" type="text"   placeholder="Es. Spaghetti"  value="${ing.name     || ''}" style="flex:2">
    <input class="ing-qty"  type="number" placeholder="Qtà"            value="${ing.quantity || ''}" style="flex:.7;min-width:60px">
    <input class="ing-unit" type="text"   placeholder="g / pz / ml"    value="${ing.unit     || ''}" style="flex:.8;min-width:60px">
    <button type="button" class="btn-remove-ing" title="Rimuovi">×</button>`;
  row.querySelector('.btn-remove-ing').addEventListener('click', () => row.remove());

  // nutrition lookup su blur dal nome
  row.querySelector('.ing-name').addEventListener('blur', async function() {
    const name = this.value.trim();
    if (!name) return;
    const data = await get('nutrition_lookup', { name });
    if (data.kcal_100g) showToast(`💡 ${name}: ${data.kcal_100g} kcal/100g — zona: ${data.zone}`);
  });

  list.appendChild(row);
  row.querySelector('.ing-name').focus();
}

function getIngredients() {
  return [...document.querySelectorAll('.ingredient-row')].map(r => ({
    name:     r.querySelector('.ing-name').value.trim(),
    quantity: r.querySelector('.ing-qty').value  || null,
    unit:     r.querySelector('.ing-unit').value.trim() || null,
  })).filter(i => i.name);
}

// ── Form ──────────────────────────────────────────────────────────────────────
function bindForm() {
  document.getElementById('btn-add-ingredient').addEventListener('click', () => addIngredientRow());
  document.getElementById('btn-save').addEventListener('click', saveMeal);
  document.getElementById('btn-cancel').addEventListener('click', resetForm);
  document.getElementById('admin-search').addEventListener('input', function() {
    renderTable(allMeals.filter(m => m.name.toLowerCase().includes(this.value.toLowerCase())));
  });
}

function resetForm() {
  document.getElementById('edit-id').value    = '';
  document.getElementById('f-name').value     = '';
  document.getElementById('f-emoji').value    = '';
  document.getElementById('f-cal').value      = '0';
  document.getElementById('f-category').value = '2';
  document.getElementById('ingredients-list').innerHTML = '';
  document.getElementById('form-title').textContent   = 'Aggiungi nuovo piatto';
  document.getElementById('btn-cancel').style.display = 'none';
  addIngredientRow();
}

function startEdit(meal) {
  document.getElementById('edit-id').value    = meal.id;
  document.getElementById('f-name').value     = meal.name;
  document.getElementById('f-emoji').value    = meal.emoji;
  document.getElementById('f-cal').value      = meal.cal_per_adult;
  document.getElementById('f-category').value = meal.category_id || '2';
  document.getElementById('form-title').textContent   = `Modifica: ${meal.name}`;
  document.getElementById('btn-cancel').style.display = 'inline-block';
  const list = document.getElementById('ingredients-list');
  list.innerHTML = '';
  (meal.ingredients || []).forEach(i => addIngredientRow(i));
  if (!(meal.ingredients || []).length) addIngredientRow();
  document.querySelector('.form-card').scrollIntoView({ behavior: 'smooth' });
}

async function saveMeal() {
  const id   = document.getElementById('edit-id').value;
  const name = document.getElementById('f-name').value.trim();
  if (!name) { showToast('⚠️ Inserisci il nome'); return; }

  const payload = {
    name, emoji:       document.getElementById('f-emoji').value.trim() || '🍽️',
    category_id:       parseInt(document.getElementById('f-category').value),
    cal_per_adult:     parseInt(document.getElementById('f-cal').value) || 0,
    ingredients:       getIngredients(),
  };

  const action = id ? 'meals_update' : 'meals_add';
  if (id) payload.id = parseInt(id);
  const data = await post(action, payload);
  if (data.error) { showToast('❌ ' + data.error); return; }
  showToast(id ? '✅ Piatto aggiornato' : '✅ Piatto aggiunto');
  resetForm();
  await loadMeals();
}

async function deleteMeal(id, name) {
  if (!confirm(`Eliminare "${name}"?`)) return;
  const data = await post('meals_delete', { id });
  if (data.error) { showToast('❌ ' + data.error); return; }
  showToast('🗑 Eliminato'); await loadMeals();
}

function showToast(msg) {
  const t = document.getElementById('toast');
  t.textContent = msg; t.classList.add('show');
  setTimeout(() => t.classList.remove('show'), 2800);
}

document.addEventListener('DOMContentLoaded', init);
