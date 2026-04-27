<?php
session_start();
require_once __DIR__ . '/config.php';
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
$csrf    = $_SESSION['csrf_token'];
$appName = APP_NAME;
?>
<!DOCTYPE html>
<html lang="it">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <meta name="csrf-token" content="<?= htmlspecialchars($csrf) ?>">
  <title><?= $appName ?> — Recupera password</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="stylesheet" href="style.css">
  <style>
    .recover-token {
      margin-top: 1rem;
      background: #f5f5f5;
      border-radius: var(--radius-btn);
      padding: .9rem 1rem;
      font-size: .85rem;
      color: var(--text-primary);
      word-break: break-all;
    }
    .recover-token strong { display: block; font-size: .75rem; color: var(--text-secondary); margin-bottom: .35rem; }
    .recover-token code {
      font-family: monospace;
      font-size: 1rem;
      letter-spacing: .05em;
      color: var(--primary);
    }
    .recover-token .token-note {
      margin-top: .5rem;
      font-size: .75rem;
      color: var(--text-secondary);
    }
    .pw-wrap { position: relative; }
    .pw-wrap input { width: 100%; padding-right: 2.4rem; box-sizing: border-box; }
    .pw-eye {
      position: absolute; right: .5rem; top: 50%; transform: translateY(-50%);
      background: none; border: none; cursor: pointer; font-size: 1.1rem;
      color: var(--text-secondary); padding: .15rem .2rem; line-height: 1;
    }
    .pw-eye:hover { color: var(--primary); }
  </style>
</head>
<body class="auth-body">

<div class="auth-wrap">
  <div class="auth-brand">
    <span class="brand-emoji">🍽️</span>
    <span class="brand-name"><?= $appName ?></span>
  </div>

  <!-- Step 1: richiesta reset -->
  <div id="step-request" class="auth-card">
    <h2 class="auth-subtitle">Recupera password</h2>
    <p style="font-size:.85rem;color:var(--text-secondary);margin:.25rem 0 1rem">
      Inserisci la tua email e riceverai un codice di reset.
    </p>
    <div class="form-group">
      <label>Email</label>
      <input type="email" id="req-email" placeholder="mario@rossi.it">
    </div>
    <button class="btn-primary w100" id="btn-request">Invia codice reset</button>
    <a href="login.php" style="display:block;text-align:center;margin-top:.75rem;font-size:.82rem;color:var(--text-secondary);text-decoration:none">← Torna al login</a>
    <div id="token-result"></div>
  </div>

  <!-- Step 2: inserisci token + nuova password -->
  <div id="step-reset" class="auth-card" style="display:none">
    <h2 class="auth-subtitle">Imposta nuova password</h2>
    <div class="form-group">
      <label>Token di reset</label>
      <input type="text" id="res-token" placeholder="Incolla il token ricevuto" style="font-family:monospace;letter-spacing:.05em">
    </div>
    <div class="form-group">
      <label>Nuova password</label>
      <div class="pw-wrap">
        <input type="password" id="res-pw" placeholder="min. 6 caratteri">
        <button type="button" class="pw-eye" data-for="res-pw" title="Mostra/nascondi">👁</button>
      </div>
    </div>
    <button class="btn-primary w100" id="btn-reset">Imposta password</button>
  </div>

  <div id="auth-msg" class="auth-msg"></div>
</div>

<script>
const CSRF = document.querySelector('meta[name="csrf-token"]').content;

async function post(action, data) {
  const r = await fetch('api.php', {
    method: 'POST',
    headers: {'Content-Type':'application/json'},
    body: JSON.stringify({action, csrf_token: CSRF, ...data})
  });
  return r.json();
}

function msg(text, ok=false) {
  const el = document.getElementById('auth-msg');
  el.textContent = text;
  el.className = 'auth-msg ' + (ok ? 'auth-msg-ok' : 'auth-msg-err');
}

// toggle password visibility
document.querySelectorAll('.pw-eye').forEach(btn => {
  btn.addEventListener('click', () => {
    const input = document.getElementById(btn.dataset.for);
    input.type  = input.type === 'password' ? 'text' : 'password';
    btn.textContent = input.type === 'password' ? '👁' : '🙈';
  });
});

document.getElementById('btn-request').addEventListener('click', async () => {
  const email = document.getElementById('req-email').value.trim();
  if (!email) return msg('Inserisci la tua email');

  const d = await post('password_reset_request', {email});
  if (d.error) return msg(d.error);

  if (d.token) {
    // mostra token in chiaro (niente SMTP per ora)
    document.getElementById('token-result').innerHTML = `
      <div class="recover-token">
        <strong>Token di reset (valido 1 ora)</strong>
        <code>${d.token}</code>
        <div class="token-note">⚠️ In produzione questo verrebbe inviato via email. Copialo e usalo nel modulo qui sotto.</div>
      </div>`;
    document.getElementById('res-token').value = d.token;
  }
  msg('Codice generato. Usalo qui sotto per impostare la nuova password.', true);
  document.getElementById('step-reset').style.display = '';
});

document.getElementById('btn-reset').addEventListener('click', async () => {
  const token    = document.getElementById('res-token').value.trim();
  const password = document.getElementById('res-pw').value;
  if (!token)           return msg('Inserisci il token di reset');
  if (password.length < 6) return msg('Password minimo 6 caratteri');

  const d = await post('password_reset_do', {token, password});
  if (d.error) return msg(d.error);
  msg('✅ Password aggiornata. Ora puoi accedere.', true);
  setTimeout(() => location.href = 'login.php', 2000);
});
</script>
</body>
</html>
