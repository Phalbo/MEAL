<?php
session_start();
require_once __DIR__ . '/config.php';
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

// già autenticato con famiglia → planner
if (!empty($_SESSION['user_id']) && !empty($_SESSION['family_id'])) {
    header('Location: index.php'); exit;
}
$step  = !empty($_SESSION['user_id']) ? 'family' : 'auth';
$csrf  = $_SESSION['csrf_token'];
$appName = APP_NAME;
?>
<!DOCTYPE html>
<html lang="it">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <meta name="csrf-token" content="<?= htmlspecialchars($csrf) ?>">
  <title><?= $appName ?> — Accedi</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@600;700&family=DM+Sans:wght@300;400;500&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="style.css">
</head>
<body class="auth-body">

<div class="auth-wrap">
  <div class="auth-brand">
    <span class="brand-emoji">🍽️</span>
    <span class="brand-name"><?= $appName ?></span>
  </div>

  <!-- STEP 1: login / registrazione -->
  <div id="step-auth" class="auth-card" <?= $step==='family'?'style="display:none"':'' ?>>
    <div class="auth-tabs">
      <button class="auth-tab active" data-tab="login">Accedi</button>
      <button class="auth-tab" data-tab="register">Registrati</button>
    </div>

    <div id="tab-login" class="auth-form">
      <div class="form-group"><label>Email</label>
        <input type="email" id="l-email" placeholder="mario@rossi.it"></div>
      <div class="form-group"><label>Password</label>
        <input type="password" id="l-pw" placeholder="••••••"></div>
      <button class="btn-primary w100" id="btn-login">Accedi</button>
    </div>

    <div id="tab-register" class="auth-form" style="display:none">
      <div class="form-group"><label>Nome</label>
        <input type="text" id="r-name" placeholder="Mario"></div>
      <div class="form-group"><label>Email</label>
        <input type="email" id="r-email" placeholder="mario@rossi.it"></div>
      <div class="form-group"><label>Password</label>
        <input type="password" id="r-pw" placeholder="min. 6 caratteri"></div>
      <button class="btn-primary w100" id="btn-register">Crea account</button>
    </div>
  </div>

  <!-- STEP 2: crea o unisciti a una famiglia -->
  <div id="step-family" class="auth-card" <?= $step==='auth'?'style="display:none"':'' ?>>
    <h2 class="auth-subtitle">Configura la tua famiglia</h2>
    <div class="auth-tabs">
      <button class="auth-tab active" data-tab="create">Crea famiglia</button>
      <button class="auth-tab" data-tab="join">Unisciti</button>
    </div>

    <div id="tab-create" class="auth-form">
      <div class="form-group"><label>Nome famiglia</label>
        <input type="text" id="f-fname" placeholder="Es. Famiglia Rossi"></div>
      <button class="btn-primary w100" id="btn-create-family">Crea famiglia</button>
    </div>

    <div id="tab-join" class="auth-form" style="display:none">
      <div class="form-group"><label>Codice invito</label>
        <input type="text" id="f-code" placeholder="Es. A3KW9P" maxlength="6"
               style="text-transform:uppercase;letter-spacing:.15em;font-size:1.1rem"></div>
      <button class="btn-primary w100" id="btn-join-family">Unisciti</button>
    </div>
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

function showFamily() {
  document.getElementById('step-auth').style.display   = 'none';
  document.getElementById('step-family').style.display = '';
}

// tab switching
document.querySelectorAll('.auth-tab').forEach(btn => {
  btn.addEventListener('click', () => {
    const parent = btn.closest('.auth-card');
    parent.querySelectorAll('.auth-tab').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    parent.querySelectorAll('.auth-form').forEach(f => f.style.display='none');
    parent.querySelector('#tab-' + btn.dataset.tab).style.display = '';
  });
});

document.getElementById('btn-login').addEventListener('click', async () => {
  const d = await post('login', {email: document.getElementById('l-email').value,
                                  password: document.getElementById('l-pw').value});
  if (d.error) return msg(d.error);
  d.family ? location.href='index.php' : showFamily();
});

document.getElementById('btn-register').addEventListener('click', async () => {
  const d = await post('register', {name: document.getElementById('r-name').value,
    email: document.getElementById('r-email').value, password: document.getElementById('r-pw').value});
  if (d.error) return msg(d.error);
  showFamily();
});

document.getElementById('btn-create-family').addEventListener('click', async () => {
  const d = await post('family_create', {name: document.getElementById('f-fname').value});
  if (d.error) return msg(d.error);
  location.href = 'index.php';
});

document.getElementById('btn-join-family').addEventListener('click', async () => {
  const d = await post('family_join', {invite_code: document.getElementById('f-code').value.toUpperCase()});
  if (d.error) return msg(d.error);
  location.href = 'index.php';
});

<?php if($step==='family'): ?>showFamily();<?php endif; ?>
</script>
</body>
</html>
