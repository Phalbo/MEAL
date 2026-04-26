/* ================================================
   MEAL PLANNER — app.js
   ================================================ */

const API      = 'api.php';
const DAYS     = ['Lun','Mar','Mer','Gio','Ven','Sab','Dom'];
const DAY_FULL = ['Lunedì','Martedì','Mercoledì','Giovedì','Venerdì','Sabato','Domenica'];
const SLOTS    = ['Colazione','Pranzo','Cena'];
const MAX_CAL  = 2500; // reference daily calories for bar chart

let allMeals = [];
// schedule[day_index][slot] = meal object | null
let schedule = loadSchedule();

// ── INIT ────────────────────────────────────────────
async function init() {
  try {
    const res  = await fetch(`${API}?action=list`);
    allMeals   = await res.json();
  } catch(e) {
    showToast('⚠️ Impossibile caricare i piatti. PHP attivo?');
    allMeals = [];
  }
  renderSidebar(allMeals);
  renderCalendar();
  updateBottom();
  bindControls();
}

// ── SIDEBAR ─────────────────────────────────────────
function renderSidebar(meals) {
  const list = document.getElementById('meal-list');
  list.innerHTML = '';

  if (!meals.length) {
    list.innerHTML = '<p style="font-size:.8rem;color:var(--ink-muted);padding:.5rem">Nessun piatto trovato.</p>';
    return;
  }

  meals.forEach(meal => {
    const card = document.createElement('div');
    card.className  = 'meal-card';
    card.draggable  = true;
    card.dataset.id = meal.id;
    card.innerHTML  = `
      <span class="mc-emoji">${meal.emoji}</span>
      <div class="mc-info">
        <div class="mc-name">${meal.name}</div>
        <div class="mc-cal">${meal.cal} kcal</div>
        <div class="mc-cat">${meal.category}</div>
      </div>`;

    card.addEventListener('dragstart', e => {
      e.dataTransfer.setData('mealId',  String(meal.id));
      e.dataTransfer.setData('fromKey', '');
      card.classList.add('dragging');
    });
    card.addEventListener('dragend', () => card.classList.remove('dragging'));
    list.appendChild(card);
  });
}

function filterSidebar() {
  const q   = document.getElementById('search').value.toLowerCase();
  const cat = document.getElementById('filter-cat').value;
  const filtered = allMeals.filter(m => {
    const matchQ   = !q   || m.name.toLowerCase().includes(q);
    const matchCat = !cat || m.category === cat;
    return matchQ && matchCat;
  });
  renderSidebar(filtered);
}

// ── CALENDAR ─────────────────────────────────────────
function renderCalendar() {
  const grid = document.getElementById('calendar-grid');
  grid.innerHTML = '';

  // Top-left corner
  const corner = el('div', 'cal-header slot-label', '');
  grid.appendChild(corner);

  // Day headers
  DAYS.forEach(d => grid.appendChild(el('div', 'cal-header', d)));

  // Rows per slot
  SLOTS.forEach((slot, si) => {
    // Row label
    grid.appendChild(el('div', 'cal-header slot-label', slot));

    // 7 cells
    DAYS.forEach((_, di) => {
      grid.appendChild(makeCell(di, si));
    });
  });
}

function makeCell(dayIndex, slotIndex) {
  const key  = `${dayIndex}_${slotIndex}`;
  const meal = schedule[key] || null;

  const cell = document.createElement('div');
  cell.className = 'cal-cell' + (meal ? ' filled' : '');
  cell.dataset.key = key;

  if (meal) {
    const inner = document.createElement('div');
    inner.className = 'cell-meal';
    inner.draggable = true;
    inner.innerHTML = `
      <span class="cell-emoji">${meal.emoji}</span>
      <span class="cell-name">${meal.name}</span>
      <span class="cell-cal">${meal.cal} kcal</span>
      <button class="cell-remove" title="Rimuovi">×</button>`;

    inner.addEventListener('dragstart', e => {
      e.dataTransfer.setData('mealId',  String(meal.id));
      e.dataTransfer.setData('fromKey', key);
      e.stopPropagation();
    });
    inner.querySelector('.cell-remove').addEventListener('click', e => {
      e.stopPropagation();
      delete schedule[key];
      saveSchedule();
      renderCalendar();
      updateBottom();
    });
    cell.appendChild(inner);
  } else {
    cell.appendChild(el('span', 'cell-placeholder', '+'));
  }

  // Drop targets
  cell.addEventListener('dragover', e => {
    e.preventDefault();
    cell.classList.add('drag-over');
  });
  cell.addEventListener('dragleave', () => cell.classList.remove('drag-over'));
  cell.addEventListener('drop', e => {
    e.preventDefault();
    cell.classList.remove('drag-over');

    const mealId  = parseInt(e.dataTransfer.getData('mealId'));
    const fromKey = e.dataTransfer.getData('fromKey');
    const found   = allMeals.find(m => m.id === mealId);
    if (!found) return;

    // Swap if dropping onto a filled cell from another cell
    if (fromKey && fromKey !== key) {
      const occupant = schedule[key] || null;
      if (occupant) {
        schedule[fromKey] = occupant;   // swap
      } else {
        delete schedule[fromKey];
      }
    }

    schedule[key] = found;
    saveSchedule();
    renderCalendar();
    updateBottom();
    showToast(`${found.emoji} ${found.name} aggiunto`);
  });

  return cell;
}

// ── BOTTOM: CALORIES + SHOPPING ─────────────────────
function updateBottom() {
  updateCalories();
  updateShoppingList();
}

function updateCalories() {
  const container = document.getElementById('calories-bars');
  container.innerHTML = '';

  DAYS.forEach((dayShort, di) => {
    let total = 0;
    SLOTS.forEach((_, si) => {
      const m = schedule[`${di}_${si}`];
      if (m) total += m.cal || 0;
    });

    const pct   = Math.min(100, Math.round((total / MAX_CAL) * 100));
    const color = total === 0 ? '#E0D8CC'
                : total < 1500 ? '#4A8060'
                : total < 2200 ? '#E8A020'
                : '#C84B2D';

    const row = document.createElement('div');
    row.className = 'cal-bar-row';
    row.innerHTML = `
      <span class="cal-bar-label">${dayShort}</span>
      <div class="cal-bar-track">
        <div class="cal-bar-fill" style="width:${pct}%;background:${color}"></div>
      </div>
      <span class="cal-bar-value">${total ? total + ' kcal' : '—'}</span>`;
    container.appendChild(row);
  });
}

function updateShoppingList() {
  const container = document.getElementById('shopping-list');
  container.innerHTML = '';

  // Collect all ingredients grouped by meal
  const byMeal = {};
  Object.values(schedule).forEach(meal => {
    if (!meal) return;
    const key = `${meal.id}_${meal.name}`;
    if (!byMeal[key]) byMeal[key] = { meal, ingredients: meal.ingredients || [] };
  });

  const entries = Object.values(byMeal);
  if (!entries.length) {
    container.innerHTML = '<p class="shopping-empty">Trascina i piatti nel calendario per generare la lista.</p>';
    return;
  }

  entries.forEach(({ meal, ingredients }) => {
    const group = document.createElement('div');
    group.className = 'shopping-group';
    group.innerHTML = `<div class="shopping-group-title">${meal.emoji} ${meal.name}</div>`;

    ingredients.forEach(ing => {
      // Try to split "Spaghetti 500g" → name + qty
      const parts = ing.trim().match(/^(.+?)\s+([\d.,]+\s*\S+|q\.b\.|q\.b|QB)$/i);
      const name  = parts ? parts[1] : ing;
      const qty   = parts ? parts[2] : '';

      const item = document.createElement('div');
      item.className = 'shopping-item';
      item.innerHTML = `
        <input type="checkbox" class="shopping-check">
        <span class="shopping-text">${name}</span>
        ${qty ? `<span class="shopping-qty">${qty}</span>` : ''}`;

      item.querySelector('.shopping-check').addEventListener('change', function() {
        item.classList.toggle('checked', this.checked);
      });

      group.appendChild(item);
    });
    container.appendChild(group);
  });
}

// ── PERSIST ──────────────────────────────────────────
function saveSchedule() {
  localStorage.setItem('mp_schedule', JSON.stringify(schedule));
}

function loadSchedule() {
  try {
    return JSON.parse(localStorage.getItem('mp_schedule') || '{}');
  } catch {
    return {};
  }
}

// ── CONTROLS ─────────────────────────────────────────
function bindControls() {
  document.getElementById('search').addEventListener('input', filterSidebar);
  document.getElementById('filter-cat').addEventListener('change', filterSidebar);

  document.getElementById('btn-clear-all').addEventListener('click', () => {
    if (!confirm('Svuotare tutta la settimana?')) return;
    schedule = {};
    saveSchedule();
    renderCalendar();
    updateBottom();
  });

  document.getElementById('btn-random').addEventListener('click', () => {
    if (!allMeals.length) return;
    const meal = allMeals[Math.floor(Math.random() * allMeals.length)];
    showToast(`🎲 Suggerisco: ${meal.emoji} ${meal.name} (${meal.cal} kcal)`);
    // Highlight the card if visible
    document.querySelectorAll('.meal-card').forEach(c => {
      if (parseInt(c.dataset.id) === meal.id) {
        c.scrollIntoView({ behavior: 'smooth', block: 'center' });
        c.style.transition = 'box-shadow .2s';
        c.style.boxShadow = '0 0 0 3px var(--terra)';
        setTimeout(() => c.style.boxShadow = '', 1800);
      }
    });
  });

  document.getElementById('btn-copy-list').addEventListener('click', () => {
    const items = [...document.querySelectorAll('.shopping-item .shopping-text')]
      .map(s => s.textContent.trim());
    const titles = [...document.querySelectorAll('.shopping-group-title')]
      .map(t => '\n' + t.textContent.trim());
    // Build text
    let text = '';
    document.querySelectorAll('.shopping-group').forEach(g => {
      const title = g.querySelector('.shopping-group-title').textContent.trim();
      const ings  = [...g.querySelectorAll('.shopping-item')].map(i => {
        const name = i.querySelector('.shopping-text').textContent;
        const qty  = i.querySelector('.shopping-qty')?.textContent || '';
        return `• ${name}${qty ? ' – ' + qty : ''}`;
      }).join('\n');
      text += `\n${title}\n${ings}\n`;
    });
    navigator.clipboard.writeText(text.trim())
      .then(() => showToast('📋 Lista copiata negli appunti'))
      .catch(() => showToast('⚠️ Copia non supportata'));
  });
}

// ── UTILS ─────────────────────────────────────────────
function el(tag, cls, text) {
  const e = document.createElement(tag);
  e.className = cls;
  e.textContent = text;
  return e;
}

function showToast(msg) {
  const t = document.getElementById('toast');
  t.textContent = msg;
  t.classList.add('show');
  setTimeout(() => t.classList.remove('show'), 2800);
}

// ── START ─────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', init);
