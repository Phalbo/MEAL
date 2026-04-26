<?php
// ⚠️  CANCELLA QUESTO FILE DAL SERVER DOPO L'USO
// URL: https://tuosito.com/run_import.php
ob_start();
require_once __DIR__ . '/data/import_ricette.php';
$output = ob_get_clean();
?>
<!DOCTYPE html>
<html lang="it">
<head>
  <meta charset="UTF-8">
  <title>Import ricette</title>
  <style>
    body { font-family: monospace; background:#1C1C1A; color:#8BC87A;
           display:flex; align-items:center; justify-content:center; min-height:100vh; margin:0; }
    .box { background:#2a2a28; padding:2rem 3rem; border-radius:10px; font-size:1.2rem; }
    .warn { color:#E8A020; font-size:.85rem; margin-top:1rem; }
    a { color:#C84B2D; }
  </style>
</head>
<body>
  <div class="box">
    <?= nl2br(htmlspecialchars($output)) ?>
    <p class="warn">⚠️ Cancella <code>run_import.php</code> dal server ora che hai finito.<br>
    Poi <a href="index.php">torna al planner →</a></p>
  </div>
</body>
</html>
