/* ── Import/Export — export_import.js ── */

const CSRF = () => document.querySelector('meta[name="csrf-token"]')?.content || '';

async function importCsv(table) {
  const input  = document.getElementById(`file-${table}`);
  const result = document.getElementById(`result-${table}`);

  if (!input.files.length) {
    showResult(result, 'Seleziona un file CSV prima di importare.', false);
    return;
  }

  const formData = new FormData();
  formData.append('action',     'import_csv');
  formData.append('csrf_token', CSRF());
  formData.append('table',      table);
  formData.append('file',       input.files[0]);

  result.style.display = 'none';
  const btn = input.closest('.ie-table-block').querySelector('button');
  btn.disabled = true; btn.textContent = '⏳';

  try {
    const res  = await fetch('api.php', { method: 'POST', body: formData });
    const data = await res.json();
    if (data.error) {
      showResult(result, '⚠️ ' + data.error, false);
    } else {
      showResult(result,
        `✅ Importate: ${data.imported} righe  •  Saltate: ${data.skipped} duplicate`, true);
      input.value = '';
    }
  } catch (e) {
    showResult(result, '⚠️ Errore di rete: ' + e.message, false);
  } finally {
    btn.disabled = false; btn.textContent = '⬆️ Importa';
  }
}

function showResult(el, text, ok) {
  el.textContent  = text;
  el.className    = 'ie-result ' + (ok ? 'ok' : 'err');
  el.style.display = 'block';
}

// logout
document.addEventListener('DOMContentLoaded', async () => {
  // mostra nome utente
  try {
    const r = await fetch('api.php?action=me');
    const d = await r.json();
    if (d.user) document.getElementById('user-label').textContent =
      `${d.user.avatar_emoji || '👤'} ${d.user.name}`;
  } catch {}

  document.getElementById('btn-logout')?.addEventListener('click', async () => {
    await fetch('api.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ action: 'logout', csrf_token: CSRF() }),
    });
    location.href = 'login.php';
  });

  document.getElementById('btn-menu')?.addEventListener('click', () => {});
});
