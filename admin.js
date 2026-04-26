/* ================================================
   MEAL PLANNER — admin.js
   ================================================ */

const API = 'api.php';
let allMeals = [];

// ── INIT ──────────────────────────────────────────
async function init() {
  await loadMeals();
  bindForm();
  bindSearch();
  addIngredientRow(); // start with one empty row
}

async function loadMeals() {
  try {
    const res = await fetch(`${API}?action=list`);
    allMeals  = await res.json();
    renderTable(allMeals);
  } catch(e) {
    showToast('⚠️ Errore caricamento. PHP attivo?');
  }
}

// ── TABLE ─────────────────────────────────────────
function renderTable(meals) {
  const tbody = document.getElementById('admin-tbody');
  tbody.innerHTML = '';

  if (!meals.length) {
    tbody.innerHTML = '<tr><td colspan="6" class="no-results">Nessun piatto trovato.</td></tr>';
    return;
  }

  meals.forEach(meal => {
    const tr = document.createElement('tr');
    tr.innerHTML = `
      <td style="font-size:1.3rem">${meal.emoji}</td>
      <td><strong>${meal.name}</strong></td>
      <td><span class="tag-cat">${meal.category}</span></td>
      <td>${meal.cal} kcal</td>
      <td style="font-size:.78rem;color:var(--ink-muted)">${(meal.ingredients || []).length} ingredienti</td>
      <td>
        <div class="tbl-actions">
          <button class="btn-edit"   data-id="${meal.id}">✏️ Modifica</button>
          <button class="btn-delete" data-id="${meal.id}">🗑 Elimina</button>
        </div>
      </td>`;

    tr.querySelector('.btn-edit').addEventListener('click', () => startEdit(meal));
    tr.querySelector('.btn-delete').addEventListener('click', () => deleteMeal(meal.id, meal.name));
    tbody.appendChild(tr);
  });
}

// ── FORM ──────────────────────────────────────────
function bindForm() {
  document.getElementById('btn-add-ingredient').addEventListener('click', addIngredientRow);

  document.getElementById('btn-save').addEventListener('click', saveMeal);

  document.getElementById('btn-cancel').addEventListener('click', () => {
    resetForm();
  });
}

function addIngredientRow(value = '') {
  const list = document.getElementById('ingredients-list');
  const row  = document.createElement('div');
  row.className = 'ingredient-row';
  row.innerHTML = `
    <input type="text" class="ing-input" placeholder="Es. Spaghetti 500g" value="${value}">
    <button type="button" class="btn-remove-ing" title="Rimuovi">×</button>`;
  row.querySelector('.btn-remove-ing').addEventListener('click', () => {
    row.remove();
  });
  list.appendChild(row);
  row.querySelector('input').focus();
}

function getIngredients() {
  return [...document.querySelectorAll('.ing-input')]
    .map(i => i.value.trim())
    .filter(Boolean);
}

function resetForm() {
  document.getElementById('edit-id').value    = '';
  document.getElementById('f-name').value     = '';
  document.getElementById('f-emoji').value    = '';
  document.getElementById('f-cal').value      = '';
  document.getElementById('f-category').value = 'Primo';
  document.getElementById('ingredients-list').innerHTML = '';
  document.getElementById('form-title').textContent     = 'Aggiungi nuovo piatto';
  document.getElementById('btn-cancel').style.display   = 'none';
  addIngredientRow();
}

function startEdit(meal) {
  document.getElementById('edit-id').value    = meal.id;
  document.getElementById('f-name').value     = meal.name;
  document.getElementById('f-emoji').value    = meal.emoji;
  document.getElementById('f-cal').value      = meal.cal;
  document.getElementById('f-category').value = meal.category;
  document.getElementById('form-title').textContent   = `Modifica: ${meal.name}`;
  document.getElementById('btn-cancel').style.display = 'inline-block';

  const list = document.getElementById('ingredients-list');
  list.innerHTML = '';
  (meal.ingredients || []).forEach(ing => addIngredientRow(ing));
  if (!meal.ingredients?.length) addIngredientRow();

  document.querySelector('.form-card').scrollIntoView({ behavior: 'smooth' });
}

async function saveMeal() {
  const id       = document.getElementById('edit-id').value;
  const name     = document.getElementById('f-name').value.trim();
  const emoji    = document.getElementById('f-emoji').value.trim() || '🍽️';
  const cal      = parseInt(document.getElementById('f-cal').value) || 0;
  const category = document.getElementById('f-category').value;
  const ingredients = getIngredients();

  if (!name)  { showToast('⚠️ Inserisci il nome del piatto'); return; }
  if (!cal)   { showToast('⚠️ Inserisci le calorie'); return; }

  const action  = id ? 'update' : 'add';
  const payload = { action, name, emoji, cal, category, ingredients };
  if (id) payload.id = parseInt(id);

  try {
    const res  = await fetch(API, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload)
    });
    const data = await res.json();

    if (data.error) { showToast('❌ ' + data.error); return; }

    showToast(id ? '✅ Piatto aggiornato' : '✅ Piatto aggiunto');
    resetForm();
    await loadMeals();
  } catch(e) {
    showToast('❌ Errore di rete');
  }
}

async function deleteMeal(id, name) {
  if (!confirm(`Eliminare "${name}"?`)) return;
  try {
    const res  = await fetch(API, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ action: 'delete', id })
    });
    const data = await res.json();
    if (data.error) { showToast('❌ ' + data.error); return; }
    showToast('🗑 Piatto eliminato');
    await loadMeals();
  } catch(e) {
    showToast('❌ Errore di rete');
  }
}

// ── SEARCH ────────────────────────────────────────
function bindSearch() {
  document.getElementById('admin-search').addEventListener('input', function() {
    const q = this.value.toLowerCase();
    renderTable(allMeals.filter(m => m.name.toLowerCase().includes(q)));
  });
}

// ── TOAST ─────────────────────────────────────────
function showToast(msg) {
  const t = document.getElementById('toast');
  t.textContent = msg;
  t.classList.add('show');
  setTimeout(() => t.classList.remove('show'), 2800);
}

// ── START ─────────────────────────────────────────
document.addEventListener('DOMContentLoaded', init);
