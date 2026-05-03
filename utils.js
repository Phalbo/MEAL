/* ── utils.js — funzioni globali condivise ── */

const ZONE_EMOJI_MAP = {
  ortofrutta:'🥦', pane:'🥖', macelleria:'🥩', pesce:'🐟', latticini:'🧀',
  scaffali:'🛒', bevande:'🍾', surgelati:'❄️', casalinghi:'🏠', altro:'📦',
};

/**
 * initAutocomplete(inputEl, onSelect, options?)
 *
 * Attacca un dropdown di autocompletamento a `inputEl` basato su nutrition_db.
 * onSelect(item) viene chiamata con l'oggetto { id, name, zone, kcal_100g, price_est }
 * quando l'utente sceglie un risultato.
 *
 * options:
 *   apiBase    {string}   percorso a api.php (default 'api.php')
 *   limit      {number}   max risultati (default 8)
 *   zone       {string}   filtra per zona (default '')
 *   showPrice  {boolean}  mostra prezzo nel dropdown (default true)
 *   showZone   {boolean}  mostra zona nel dropdown (default true)
 */
function initAutocomplete(inputEl, onSelect, options = {}) {
  const apiBase   = options.apiBase   ?? 'api.php';
  const limit     = options.limit     ?? 8;
  const zoneFilter= options.zone      ?? '';
  const showPrice = options.showPrice !== false;
  const showZone  = options.showZone  !== false;

  // Crea dropdown
  const wrap = document.createElement('div');
  wrap.style.cssText = 'position:relative;display:contents';
  inputEl.insertAdjacentElement('afterend', wrap);

  const dropdown = document.createElement('div');
  dropdown.className = 'ac-dropdown';
  dropdown.style.cssText = `
    display:none; position:absolute; top:calc(100% + 4px); left:0; right:0;
    background:var(--card,#fff); border:1.5px solid var(--border-soft,#E5E7EB);
    border-radius:12px; box-shadow:0 4px 20px rgba(0,0,0,.14);
    z-index:9999; max-height:260px; overflow-y:auto; min-width:200px;
  `;
  // Posiziona il dropdown relativo all'input, non al wrapper
  inputEl.style.position = 'relative';
  inputEl.insertAdjacentElement('afterend', dropdown);

  let debounceTimer = null;
  let activeIdx     = -1;
  let lastResults   = [];

  function close() {
    dropdown.style.display = 'none';
    activeIdx = -1;
  }

  function open() {
    dropdown.style.display = 'block';
  }

  function highlight(idx) {
    activeIdx = idx;
    dropdown.querySelectorAll('.ac-item').forEach((el, i) => {
      el.classList.toggle('ac-active', i === idx);
    });
  }

  function renderResults(items) {
    lastResults = items;
    dropdown.innerHTML = '';
    if (!items.length) { close(); return; }

    items.forEach((it, i) => {
      const div = document.createElement('div');
      div.className = 'ac-item';
      div.style.cssText = 'padding:.45rem .75rem;cursor:pointer;font-size:.85rem;display:flex;align-items:center;gap:.5rem;';

      const emoji = ZONE_EMOJI_MAP[it.zone] || '📦';
      let meta = '';
      if (showZone)  meta += `<span style="font-size:.72rem;color:var(--text-secondary,#6B7280)">${emoji} ${it.zone||''}</span>`;
      if (showPrice && parseFloat(it.price_est) > 0)
        meta += `<span style="font-size:.72rem;color:var(--green,#4CAF50);margin-left:auto">€${parseFloat(it.price_est).toFixed(2)}/kg</span>`;

      div.innerHTML = `<span style="flex:1;color:var(--text-primary,#1F2937)">${escHtmlAc(it.name)}</span>${meta}`;

      div.addEventListener('mousedown', e => {
        e.preventDefault();
        choose(it);
      });
      dropdown.appendChild(div);
    });

    open();
    highlight(-1);
  }

  function choose(it) {
    inputEl.value = it.name;
    close();
    onSelect(it);
  }

  async function fetchSuggestions(q) {
    if (!q.trim()) { close(); return; }
    const params = new URLSearchParams({ action: 'nutrition_search', q, limit });
    if (zoneFilter) params.set('zone', zoneFilter);
    try {
      const res = await fetch(`${apiBase}?${params}`);
      const data = await res.json();
      renderResults(Array.isArray(data) ? data : []);
    } catch { close(); }
  }

  inputEl.addEventListener('input', e => {
    clearTimeout(debounceTimer);
    debounceTimer = setTimeout(() => fetchSuggestions(e.target.value), 300);
  });

  inputEl.addEventListener('keydown', e => {
    const items = dropdown.querySelectorAll('.ac-item');
    if (!items.length) return;
    if (e.key === 'ArrowDown') {
      e.preventDefault();
      highlight(Math.min(activeIdx + 1, items.length - 1));
      items[activeIdx]?.scrollIntoView({ block: 'nearest' });
    } else if (e.key === 'ArrowUp') {
      e.preventDefault();
      highlight(Math.max(activeIdx - 1, 0));
      items[activeIdx]?.scrollIntoView({ block: 'nearest' });
    } else if (e.key === 'Enter' && activeIdx >= 0) {
      e.preventDefault();
      choose(lastResults[activeIdx]);
    } else if (e.key === 'Escape') {
      close();
    }
  });

  inputEl.addEventListener('blur', () => {
    setTimeout(close, 150);
  });

  // Stili CSS inseriti una sola volta
  if (!document.getElementById('ac-styles')) {
    const style = document.createElement('style');
    style.id = 'ac-styles';
    style.textContent = `
      .ac-item:hover, .ac-active { background: var(--primary-soft, #FFE8E2); }
      .ac-item { transition: background .1s; border-radius: 8px; margin: 2px 4px; }
    `;
    document.head.appendChild(style);
  }

  return { close };
}

function escHtmlAc(s) {
  return String(s ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}
