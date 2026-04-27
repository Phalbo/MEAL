/* ── Meal Planner v2.0 — app.js (state, API, init, sidebar) ── */

const API   = 'api.php';
const DAYS  = ['Lun','Mar','Mer','Gio','Ven','Sab','Dom'];
const SLOTS = ['colazione','pranzo','cena'];
const SLOT_LABELS = ['Colazione','Pranzo','Cena'];
const MAX_CAL = 2500;

const CSRF = () => document.querySelector('meta[name="csrf-token"]')?.content || '';

const state = {
  user:     null,
  family:   null,
  week:     getMondayStr(),   // 'YYYY-MM-DD'
  schedule: {},               // key: `${dayIdx}_${slotIdx}` → meal obj
  meals:    [],
  profiles: [],               // family_profiles con intolerances[]
};

// ── API helpers ──────────────────────────────────────────────────────────────
async function get(action, params = {}) {
  const qs  = new URLSearchParams({ action, ...params });
  const res = await fetch(`${API}?${qs}`);
  if (!res.ok && res.status !== 200) throw new Error(`HTTP ${res.status}`);
  const text = await res.text();
  try { return JSON.parse(text); }
  catch { throw new Error('Risposta non JSON: ' + text.slice(0, 120)); }
}

async function post(action, data = {}) {
  const res = await fetch(API, {
    method:  'POST',
    headers: { 'Content-Type': 'application/json' },
    body:    JSON.stringify({ action, csrf_token: CSRF(), ...data }),
  });
  const text = await res.text();
  try { return JSON.parse(text); }
  catch { return { error: 'Risposta non JSON' }; }
}

// ── Week helpers ─────────────────────────────────────────────────────────────
function getMondayStr(date = new Date()) {
  const d   = new Date(date);
  const day = d.getDay();
  const diff = day === 0 ? -6 : 1 - day;
  d.setDate(d.getDate() + diff);
  return d.toISOString().slice(0, 10);
}

function addWeeks(base, n) {
  const d = new Date(base + 'T00:00:00');
  d.setDate(d.getDate() + n * 7);
  return d.toISOString().slice(0, 10);
}

function formatWeekLabel(monday) {
  const d   = new Date(monday + 'T00:00:00');
  const end = new Date(d); end.setDate(end.getDate() + 6);
  const fmt = dt => dt.toLocaleDateString('it-IT', { day: 'numeric', month: 'short' });
  return `${fmt(d)} – ${fmt(end)}`;
}

// ── Init ─────────────────────────────────────────────────────────────────────
async function init() {
  let me;
  try {
    me = await get('me');
  } catch (e) {
    // errore di rete o risposta non JSON — non fare redirect, mostra avviso
    document.body.innerHTML = `<div style="display:flex;align-items:center;justify-content:center;height:100vh;font-family:sans-serif;flex-direction:column;gap:1rem">
      <p style="font-size:1.1rem;color:#c84b2d">⚠️ Impossibile contattare il server.</p>
      <pre style="font-size:.75rem;color:#666;background:#f5f5f5;padding:.75rem;border-radius:8px;max-width:500px;overflow:auto">${e.message}</pre>
      <a href="login.php" style="color:#FF6B4A">→ Torna al login</a>
    </div>`;
    return;
  }
  if (me.error) { location.href = 'login.php'; return; }

  state.user   = me.user;
  state.family = me.family;
  document.getElementById('user-label').textContent =
    `${me.user.avatar_emoji || '👤'} ${me.user.name}`;

  await Promise.all([loadMeals(), loadProfiles()]);
  await loadSchedule();
  renderSidebar(state.meals);
  renderCalendar();
  updateBottom();
  bindControls();
  updateWeekLabel();
}

async function loadMeals() {
  try {
    const data = await get('meals_list');
    state.meals = Array.isArray(data) ? data : [];
  } catch { state.meals = []; }
}

async function loadProfiles() {
  try {
    const data = await get('profiles_list');
    state.profiles = Array.isArray(data) ? data : [];
  } catch { state.profiles = []; }
}

// ── Porzioni e conflitti ──────────────────────────────────────────────────────
function getTotalPortions() {
  if (!state.profiles.length) return 1;
  return state.profiles.reduce((s, p) => s + parseFloat(p.portion_weight || 1), 0);
}

function mealHasConflict(meal) {
  if (!state.profiles.length) return false;
  // tutti i flag degli ingredienti del piatto
  const flags = (meal.ingredients || [])
    .flatMap(i => (i.intolerance_flags || '').split(',').map(f => f.trim().toLowerCase()))
    .filter(Boolean);
  if (!flags.length) return false;
  // intolleranze di tutti i profili famiglia
  const familyIntol = state.profiles
    .flatMap(p => (p.intolerances || []).map(l => l.toLowerCase()));
  return flags.some(f => familyIntol.includes(f));
}

// ── Schedule ─────────────────────────────────────────────────────────────────
let _schedSeq = 0; // sequenza per evitare race condition su navigazione rapida

async function loadSchedule() {
  const seq = ++_schedSeq;
  // Mostra subito griglia vuota — il calendario non sparisce mai
  state.schedule = {};
  renderCalendar(); updateBottom();
  try {
    const rows = await get('schedule_get', { week_start: state.week });
    if (seq !== _schedSeq) return; // risposta superata da navigazione più recente
    if (!Array.isArray(rows)) return;
    rows.forEach(row => {
      const si = SLOTS.indexOf(row.slot);
      if (si < 0 || !row.meal_id) return;
      state.schedule[`${row.day_index}_${si}`] = {
        id: row.meal_id, name: row.name, emoji: row.emoji,
        cal_per_adult: row.cal_per_adult, category: row.category,
        schedule_id: row.id, exception_note: row.exception_note,
        is_exception: row.is_exception,
      };
    });
    renderCalendar(); updateBottom();
  } catch {
    if (seq !== _schedSeq) return;
    state.schedule = {};
    renderCalendar(); updateBottom();
  }
}

function updateWeekLabel() {
  document.getElementById('week-label').textContent = formatWeekLabel(state.week);
  const navLista = document.getElementById('nav-lista');
  if (navLista) navLista.href = `lista.php?week=${state.week}`;
}

// ── Sidebar ──────────────────────────────────────────────────────────────────
function renderSidebar(meals) {
  const list = document.getElementById('meal-list');
  list.innerHTML = '';
  if (!meals.length) {
    list.innerHTML = '<p style="font-size:.8rem;color:var(--ink-muted);padding:.5rem">Nessun piatto trovato.</p>';
    return;
  }
  meals.forEach(meal => {
    const conflict  = mealHasConflict(meal);
    const card      = document.createElement('div');
    card.className  = 'meal-card' + (conflict ? ' meal-conflict' : '');
    card.draggable  = true;
    card.dataset.id = meal.id;
    card.innerHTML  = `
      <span class="mc-emoji">${meal.emoji}</span>
      <div class="mc-info">
        <div class="mc-name">${meal.name}${conflict ? ' <span title="Contiene allergeni">⚠️</span>' : ''}</div>
        <div class="mc-cal">${meal.cal_per_adult} kcal</div>
        <div class="mc-cat">${meal.category || ''}</div>
      </div>`;
    card.addEventListener('dragstart', e => {
      e.dataTransfer.setData('mealId',  String(meal.id));
      e.dataTransfer.setData('fromKey', '');
      card.classList.add('dragging');
    });
    card.addEventListener('dragend', () => card.classList.remove('dragging'));
    addTouchDrag(card, meal);
    list.appendChild(card);
  });
}

function filterSidebar() {
  const q   = document.getElementById('search').value.toLowerCase();
  const cat = document.getElementById('filter-cat').value;
  renderSidebar(state.meals.filter(m =>
    (!q   || m.name.toLowerCase().includes(q)) &&
    (!cat || String(m.category_id) === cat)
  ));
}

// ── Controls ─────────────────────────────────────────────────────────────────
function bindControls() {
  document.getElementById('search').addEventListener('input', filterSidebar);
  document.getElementById('filter-cat').addEventListener('change', filterSidebar);

  document.getElementById('btn-prev-week').addEventListener('click', async () => {
    state.week = addWeeks(state.week, -1);
    updateWeekLabel();
    await loadSchedule(); renderCalendar(); updateBottom();
  });
  document.getElementById('btn-next-week').addEventListener('click', async () => {
    state.week = addWeeks(state.week, +1);
    updateWeekLabel();
    await loadSchedule(); renderCalendar(); updateBottom();
  });
  document.getElementById('btn-clear-all').addEventListener('click', async () => {
    if (!confirm('Svuotare tutta la settimana?')) return;
    await post('schedule_clear', { week_start: state.week });
    state.schedule = {}; renderCalendar(); updateBottom();
  });
  document.getElementById('btn-gen-shopping').addEventListener('click', async () => {
    showToast('⏳ Genero lista…');
    const items = await post('shopping_generate', { week_start: state.week });
    renderShoppingList(Array.isArray(items) ? items : []);
    showToast('✅ Lista generata');
  });
  document.getElementById('btn-logout').addEventListener('click', async () => {
    await post('logout');
    location.href = 'login.php';
  });
  document.getElementById('btn-random').addEventListener('click', () => {
    if (!state.meals.length) return;
    const m = state.meals[Math.floor(Math.random() * state.meals.length)];
    window._rndMeal = m;
    showToast(
      `🎲 ${m.emoji} ${m.name} (${m.cal_per_adult} kcal)` +
      ` <button onclick="addRandomToFirst(window._rndMeal)" style="margin-left:.5rem;background:var(--terra);color:#fff;border:none;border-radius:4px;padding:.18rem .5rem;font-size:.75rem;cursor:pointer">+ Slot vuoto</button>`,
      5000
    );
  });
  document.getElementById('btn-copy-list').addEventListener('click', copyShoppingList);

  document.getElementById('btn-autofill')?.addEventListener('click', async () => {
    if (!confirm('Popola automaticamente gli slot vuoti di questa settimana?')) return;
    const res = await post('schedule_autofill', { week_start: state.week });
    if (res.error) { showToast('⚠️ ' + res.error); return; }
    showToast(`✅ Aggiunti ${res.filled} piatti`);
    await loadSchedule(); renderCalendar(); updateBottom();
  });

  document.getElementById('btn-copy-week').addEventListener('click', async () => {
    const fromWeek = addWeeks(state.week, -1);
    if (!confirm(`Copiare il piano del ${formatWeekLabel(fromWeek)} in questa settimana?\nIl piano attuale verrà sovrascritto.`)) return;
    const res = await post('schedule_copy', { from_week: fromWeek, to_week: state.week });
    if (res.error) { showToast('⚠️ ' + res.error); return; }
    showToast(res.copied ? `✅ ${res.copied} piatti copiati` : '⚠️ Settimana precedente vuota');
    await loadSchedule(); renderCalendar(); updateBottom();
  });

  document.getElementById('btn-share-list').addEventListener('click', async () => {
    const data = await get('family_share_token');
    if (data.error) { showToast('⚠️ ' + data.error); return; }
    const base = location.href.replace(/[^/]*$/, '');
    const url  = `${base}lista_pub.php?token=${data.token}&week=${state.week}`;
    navigator.clipboard.writeText(url)
      .then(() => showToast('🔗 Link condivisione copiato'))
      .catch(() => showToast('⚠️ Copia non supportata'));
  });

  // Mobile sidebar drawer
  const sidebar  = document.querySelector('.sidebar');
  const overlay  = document.getElementById('sidebar-overlay');
  document.getElementById('btn-menu').addEventListener('click', () => {
    sidebar.classList.toggle('open');
    overlay.classList.toggle('active');
  });
  overlay.addEventListener('click', () => {
    sidebar.classList.remove('open');
    overlay.classList.remove('active');
  });
}

document.addEventListener('DOMContentLoaded', init);
