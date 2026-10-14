<?php
declare(strict_types=1);
// GPX-Download-Seite für die iPhone-Homescreen-App (TEST).
// Wird über https://dl.radweg-pate.de/gpx.php aufgerufen: anderer Host = außerhalb der App.
// iOS öffnet die Seite deshalb in einem eigenen Browserfenster mit „Fertig“-Knopf;
// von dort lässt sich die GPX an Naviki übergeben und danach in die App zurückkehren.
// Die Seite selbst liest keine Daten – der Download läuft über api.php mit dem GPX-Schlüssel.
require __DIR__ . '/version.php';
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('X-Frame-Options: DENY');
header("Content-Security-Policy: default-src 'self'; img-src 'self'; script-src 'self'; style-src 'self'; form-action 'none'; base-uri 'none'; object-src 'none'; frame-ancestors 'none'");

$token = (string)($_GET['dl'] ?? '');
$id = (string)($_GET['id'] ?? '');
$ids = (string)($_GET['ids'] ?? '');
$okToken = preg_match('/^[a-f0-9]{32}$/', $token) === 1;
$okId = preg_match('/^0[1-7]$/', $id) === 1;
$okIds = preg_match('/^0[1-7](,0[1-7]){1,6}$/', $ids) === 1;
$valid = $okToken && ($okId xor $okIds);
$query = $valid ? ($okIds ? 'ids=' . rawurlencode($ids) : 'id=' . rawurlencode($id)) . '&dl=' . $token : '';
$label = $okIds ? 'Touren ' . str_replace(',', ' + ', $ids) : 'Tour ' . $id;
$v = rawurlencode(APP_VERSION);
$e = fn(string $s) => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
?><!doctype html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex">
<title>GPX · Radwegpate</title>
<script src="assets/theme-init.js?v=<?= $v ?>"></script>
<link rel="stylesheet" href="assets/style.css?v=<?= $v ?>">
</head>
<body class="dl-page">
<main class="dl-card">
  <p class="eyebrow">Radwegpate</p>
<?php if ($valid): ?>
  <h1>GPX für <?= $e($label) ?></h1>
  <a class="btn primary full" href="api.php?action=gpx&amp;<?= $e($query) ?>&amp;save=1"><svg class="ic" aria-hidden="true"><use href="assets/icons.svg#download"/></svg> GPX herunterladen</a>
  <a class="btn outline full" href="api.php?action=gpx&amp;<?= $e($query) ?>"><svg class="ic" aria-hidden="true"><use href="assets/icons.svg#external-link"/></svg> GPX direkt öffnen</a>
  <ol class="smallnote dl-steps">
    <li>„GPX herunterladen“ tippen (oder „direkt öffnen“).</li>
    <li>In der Vorschau über <strong>Teilen / Öffnen in …</strong> Naviki wählen.</li>
    <li>Zurück: oben auf <strong>„Fertig“</strong> tippen.</li>
  </ol>
<?php else: ?>
  <h1>Link ungültig</h1>
  <p class="smallnote">Bitte die GPX erneut aus der App öffnen.</p>
<?php endif; ?>
</main>
</body>
</html>
