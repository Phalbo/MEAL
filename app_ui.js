/* ── Meal Planner v2.0 — app_ui.js (calendar, drag&drop, calories, shopping) ── */

// ── Calendar ─────────────────────────────────────────────────────────────────
function renderCalendar() {
  const grid = document.getElementById('calendar-grid');
  grid.innerHTML = '';

  grid.appendChild(el('div', 'cal-header slot-label', ''));
  DAYS.forEach(d => grid.appendChild(el('div', 'cal-header', d)));

  SLOT_LABELS.forEach((label, si) => {
    grid.appendChild(el('div', 'cal-header slot-label', label));
    DAYS.forEach((_, di) => grid.appendChild(makeCell(di, si)));
  });
}

function makeCell(dayIndex, slotIndex) {
  const key  = `${dayIndex}_${slotIndex}`;
  const meal = state.schedule[key] || null;

  const cell      = document.createElement('div');
  cell.className  = 'cal-cell' + (meal ? ' filled' : '');
  cell.dataset.key = key;

  if (meal) {
    const inner      = document.createElement('div');
    inner.className  = 'cell-meal';
    inner.draggable  = true;
    // cerca il piatto completo (con ingredienti) per il check conflitti
    const fullMeal = state.meals.find(m => m.id === meal.id) || meal;
    const conflict = mealHasConflict(fullMeal);
    if (conflict) cell.classList.add('cell-has-conflict');

    inner.innerHTML  = `
      <span class="cell-emoji">${meal.emoji}</span>
      <span class="cell-name">${meal.name}</span>
      <span class="cell-cal">${meal.cal_per_adult} kcal</span>
      ${conflict        ? `<span class="cell-alert" title="Contiene allergeni per un profilo">🚨</span>` : ''}
      ${meal.exception_note ? `<span class="cell-exception" title="${meal.exception_note}">📝</span>` : ''}
      <button class="cell-remove" title="Rimuovi">×</button>`;

    inner.addEventListener('dragstart', e => {
      e.dataTransfer.setData('mealId',  String(meal.id));
      e.dataTransfer.setData('fromKey', key);
      e.stopPropagation();
    });

    inner.querySelector('.cell-remove').addEventListener('click', async e => {
      e.stopPropagation();
      await post('schedule_set', {
        week_start: state.week, day_index: dayIndex,
        slot: SLOTS[slotIndex], meal_id: null,
      });
      delete state.schedule[key];
      renderCalendar(); updateBottom();
    });
    cell.appendChild(inner);
  } else {
    cell.appendChild(el('span', 'cell-placeholder', '+'));
  }

  // drop zone
  cell.addEventListener('dragover',  e => { e.preventDefault(); cell.classList.add('drag-over'); });
  cell.addEventListener('dragleave', () => cell.classList.remove('drag-over'));
  cell.addEventListener('drop', async e => {
    e.preventDefault();
    cell.classList.remove('drag-over');

    const mealId  = parseInt(e.dataTransfer.getData('mealId'));
    const fromKey = e.dataTransfer.getData('fromKey');
    const found   = state.meals.find(m => m.id === mealId);
    if (!found) return;

    // swap se trascinato da un'altra cella
    if (fromKey && fromKey !== key) {
      const occupant = state.schedule[key] || null;
      const [fd, fs] = fromKey.split('_').map(Number);
      if (occupant) {
        state.schedule[fromKey] = occupant;
        await post('schedule_set', { week_start: state.week, day_index: fd,
          slot: SLOTS[fs], meal_id: occupant.id });
      } else {
        delete state.schedule[fromKey];
        await post('schedule_set', { week_start: state.week, day_index: fd,
          slot: SLOTS[fs], meal_id: null });
      }
    }

    state.schedule[key] = found;
    await post('schedule_set', { week_start: state.week, day_index: dayIndex,
      slot: SLOTS[slotIndex], meal_id: found.id });

    renderCalendar(); updateBottom();
    showToast(`${found.emoji} ${found.name} aggiunto`);
  });

  return cell;
}

// ── Bottom ────────────────────────────────────────────────────────────────────
function updateBottom() { updateCalories(); }

function updateCalories() {
  const container   = document.getElementById('calories-bars');
  container.innerHTML = '';
  const portions    = getTotalPortions();       // Σ portion_weight famiglia
  const maxCal      = MAX_CAL * Math.max(1, portions); // soglie scalate
  const hasProfiles = state.profiles.length > 0;

  DAYS.forEach((dayShort, di) => {
    let baseKcal = 0;
    SLOTS.forEach((_, si) => { const m = state.schedule[`${di}_${si}`]; if (m) baseKcal += m.cal_per_adult || 0; });
    const total  = Math.round(baseKcal * portions);    // kcal famiglia
    const pct    = Math.min(100, Math.round((total / maxCal) * 100));
    const color  = total === 0 ? '#E0D8CC' : total < (1500 * portions) ? '#4A8060'
                 : total < (2200 * portions) ? '#E8A020' : '#C84B2D';
    const label  = total ? `${total} kcal${hasProfiles ? ` (×${portions.toFixed(1)})` : ''}` : '—';
    const row    = document.createElement('div');
    row.className = 'cal-bar-row';
    row.innerHTML = `
      <span class="cal-bar-label">${dayShort}</span>
      <div class="cal-bar-track"><div class="cal-bar-fill" style="width:${pct}%;background:${color}"></div></div>
      <span class="cal-bar-value">${label}</span>`;
    container.appendChild(row);
  });
}

// ── Shopping list ─────────────────────────────────────────────────────────────
let shoppingView = 'list'; // 'list' | 'grid'

function renderShoppingList(items) {
  const container = document.getElementById('shopping-list');
  container.innerHTML = '';
  if (!items.length) {
    container.innerHTML = '<p class="shopping-empty">Nessun ingrediente in calendario.</p>'; return;
  }

  // toggle bar + totale
  const totalEst  = items.reduce((s, it) => s + (parseFloat(it.price_est) || 0), 0);
  const totalReal = items.reduce((s, it) => s + (parseFloat(it.price_actual) || 0), 0);
  const bar = document.createElement('div');
  bar.className = 'shopping-toolbar';
  bar.innerHTML = `
    <div class="shop-total">
      💰 Stimato: <strong>€${totalEst.toFixed(2)}</strong>
      ${totalReal > 0 ? ` &nbsp;|&nbsp; Reale: <strong>€${totalReal.toFixed(2)}</strong>` : ''}
    </div>
    <div class="shop-toggle">
      <button class="toggle-btn ${shoppingView==='list'?'active':''}" data-view="list">☰ Lista</button>
      <button class="toggle-btn ${shoppingView==='grid'?'active':''}" data-view="grid">⊞ Riquadri</button>
    </div>`;
  bar.querySelectorAll('.toggle-btn').forEach(btn =>
    btn.addEventListener('click', () => {
      shoppingView = btn.dataset.view;
      renderShoppingList(items);
    })
  );
  container.appendChild(bar);

  if (shoppingView === 'grid') {
    renderShoppingGrid(items, container);
  } else {
    renderShoppingListView(items, container);
  }
}

function renderShoppingListView(items, container) {
  const zones = {};
  items.forEach(it => { const z = it.zone||'scaffali'; (zones[z]??=[]).push(it); });
  const zoneEmoji = { ortofrutta:'🥦',pane:'🥖',macelleria:'🥩',pesce:'🐟',
                      latticini:'🧀',scaffali:'🛒',bevande:'🍾',surgelati:'❄️',altro:'📦' };
  Object.entries(zones).forEach(([zone, zItems]) => {
    const group = document.createElement('div');
    group.className = 'shopping-group';
    group.innerHTML = `<div class="shopping-group-title">${zoneEmoji[zone]||'📦'} ${zone}</div>`;
    zItems.forEach(it => {
      const item = document.createElement('div');
      item.className = 'shopping-item' + (it.checked ? ' checked' : '');
      item.dataset.id = it.id;
      const price = it.price_actual ? `€${parseFloat(it.price_actual).toFixed(2)}`
                  : it.price_est   ? `~€${parseFloat(it.price_est).toFixed(2)}` : '';
      item.innerHTML = `
        <input type="checkbox" class="shopping-check" ${it.checked?'checked':''}>
        <span class="shopping-text">${it.ingredient_name}</span>
        ${it.quantity ? `<span class="shopping-qty">${it.quantity}${it.unit||''}</span>` : ''}
        ${price ? `<span class="shopping-price">${price}</span>` : ''}
        <input type="number" class="price-input" placeholder="€ reale" step="0.01" min="0"
               value="${it.price_actual||''}" title="Inserisci prezzo reale">`;
      item.querySelector('.shopping-check').addEventListener('change', async function() {
        item.classList.toggle('checked', this.checked);
        await post('shopping_check', {id: it.id, checked: this.checked?1:0});
        it.checked = this.checked ? 1 : 0;
      });
      item.querySelector('.price-input').addEventListener('change', async function() {
        const price = parseFloat(this.value) || 0;
        await post('shopping_price_update', {id: it.id, price_actual: price});
        it.price_actual = price;
        renderShoppingList(items); // refresh totale
      });
      group.appendChild(item);
    });
    container.appendChild(group);
  });
}

function renderShoppingGrid(items, container) {
  const grid = document.createElement('div');
  grid.className = 'shopping-grid';
  items.forEach(it => {
    const card = document.createElement('div');
    card.className = 'shop-grid-card' + (it.checked ? ' checked' : '');
    card.innerHTML = `
      <span class="shop-grid-name">${it.ingredient_name}</span>
      ${it.quantity ? `<span class="shop-grid-qty">${it.quantity}${it.unit||''}</span>` : ''}`;
    card.addEventListener('click', async () => {
      it.checked = it.checked ? 0 : 1;
      await post('shopping_check', {id: it.id, checked: it.checked});
      card.classList.toggle('checked', !!it.checked);
    });
    grid.appendChild(card);
  });
  container.appendChild(grid);
}

function copyShoppingList() {
  let text = '';
  document.querySelectorAll('.shopping-group').forEach(g => {
    text += '\n' + g.querySelector('.shopping-group-title').textContent + '\n';
    g.querySelectorAll('.shopping-item').forEach(i => {
      const name = i.querySelector('.shopping-text').textContent;
      const qty  = i.querySelector('.shopping-qty')?.textContent || '';
      text += `• ${name}${qty ? ' ' + qty : ''}\n`;
    });
  });
  navigator.clipboard.writeText(text.trim())
    .then(() => showToast('📋 Lista copiata'))
    .catch(() => showToast('⚠️ Copia non supportata'));
}

// ── Utils ─────────────────────────────────────────────────────────────────────
function el(tag, cls, text) {
  const e = document.createElement(tag);
  e.className = cls; e.textContent = text; return e;
}
function showToast(msg) {
  const t = document.getElementById('toast');
  t.textContent = msg; t.classList.add('show');
  setTimeout(() => t.classList.remove('show'), 2800);
}
