<?php
declare(strict_types=1);
require __DIR__ . '/version.php';
$v = rawurlencode(APP_VERSION);
header('Content-Type: text/html; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('X-Frame-Options: DENY');
// Leaflet liegt lokal unter assets/vendor/ – keine fremden Skript- oder Stilquellen.
header("Content-Security-Policy: default-src 'self'; img-src 'self' data: blob: https://tile.openstreetmap.org; script-src 'self'; style-src 'self'; connect-src 'self'; frame-src https://www.google.com; form-action 'self'; base-uri 'self'; object-src 'none'; frame-ancestors 'none'; manifest-src 'self'; worker-src 'self'");
?><!DOCTYPE html>
<html lang="de">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <meta name="theme-color" content="#f3f7f4">
  <meta name="apple-mobile-web-app-capable" content="yes">
  <meta name="apple-mobile-web-app-status-bar-style" content="default">
  <meta name="apple-mobile-web-app-title" content="Radwegpate">
  <meta name="description" content="Meine Radwegpaten-Touren und Streckenkontrollen dokumentieren. Ohne Datenbank.">
  <link rel="manifest" href="manifest.webmanifest">
  <link rel="icon" href="assets/icon.svg" type="image/svg+xml">
  <link rel="apple-touch-icon" href="assets/icon-180.png">
  <script src="assets/theme-init.js?v=<?= $v ?>"></script>
  <link rel="stylesheet" href="assets/vendor/leaflet-1.9.4/leaflet.css">
  <link rel="stylesheet" href="assets/style.css?v=<?= $v ?>">
  <title>Radwegpate · Tourentagebuch</title>
</head>
<body>
  <div class="page-shell" id="page-shell">
    <section id="auth" class="auth-panel" hidden>
      <div class="brand brand-big"><span class="brand-mark" aria-hidden="true"><svg class="ic" aria-hidden="true"><use href="assets/icons.svg#route"/></svg></span><span>Radwegpate<small>Tourentagebuch Senden</small></span></div>
      <div class="auth-card">
        <h1 id="auth-title">Anmelden</h1>
        <p class="muted" id="auth-desc">Deine Dokumentation ist durch ein Passwort geschützt.</p>
        <form id="auth-form">
          <label id="setup-code-wrap" hidden>Einrichtungscode<input id="setup-code" type="text" autocomplete="one-time-code" placeholder="APP_INSTALL_TOKEN aus config.php"></label>
          <label>Passwort<input id="auth-password" type="password" minlength="12" autocomplete="current-password" required></label>
          <button type="submit" class="btn primary full" id="auth-submit">Anmelden</button>
        </form>
        <div id="auth-error" class="message error" role="alert" hidden></div>
      </div>
      <p class="smallnote">Keine Datenbank · private JSON-Dateien auf deinem Webspace · Version <?= htmlspecialchars(APP_VERSION) ?></p>
    </section>

    <section id="main" hidden>
      <header class="topbar">
        <div class="topbar-row">
          <div class="brand"><span class="brand-mark" aria-hidden="true"><svg class="ic" aria-hidden="true"><use href="assets/icons.svg#route"/></svg></span><span>Radwegpate<small>Senden &amp; Umgebung</small></span></div>
          <button class="icon-button" id="settings-button" type="button" aria-label="Darstellung und Konto" title="Darstellung und Konto" aria-haspopup="dialog"><svg class="ic" aria-hidden="true"><use href="assets/icons.svg#settings"/></svg></button>
        </div>
        <div class="kpis" role="group" aria-label="Übersicht">
          <button type="button" class="kpi" data-goto="routes" aria-label="Strecken anzeigen"><svg class="ic" aria-hidden="true"><use href="assets/icons.svg#route"/></svg><strong id="stat-routes">7</strong><span>Strecken</span></button>
          <button type="button" class="kpi" data-goto="diary" aria-label="Tagebuch anzeigen"><svg class="ic" aria-hidden="true"><use href="assets/icons.svg#clipboard-check"/></svg><strong id="stat-entries">0</strong><span id="stat-entries-label">Prüfungen</span></button>
          <button type="button" class="kpi" id="kpi-issues" data-goto="diary" data-filter="offen" aria-label="Offene Mängel anzeigen"><svg class="ic" aria-hidden="true"><use href="assets/icons.svg#alert-triangle"/></svg><strong id="stat-issues">0</strong><span>offene Mängel</span></button>
        </div>
      </header>
      <main>
        <section id="map-view" class="view">
          <div class="section-heading"><div><h2>Meine Streckenkarte</h2><p class="muted">KML aus My Maps · Touren unten an- und abwählen.</p></div>
            <div class="heading-actions"><button type="button" class="btn outline small" id="map-zoom"><svg class="ic" aria-hidden="true"><use href="assets/icons.svg#arrows-maximize"/></svg> Alle zeigen</button><button type="button" class="btn outline small" id="selection-toggle"><svg class="ic" aria-hidden="true"><use href="assets/icons.svg#square-check"/></svg> Alle anwählen</button></div></div>
          <div class="map-stage" id="map-stage">
            <div class="map-slider">
              <section class="actions-pane" id="selection-actions" aria-labelledby="actions-title" inert>
                <h3 id="actions-title">Aktionen zur Auswahl</h3>
                <div class="combo-buttons"><a class="btn primary" id="selection-gpx" hidden href="#"><svg class="ic" aria-hidden="true"><use href="assets/icons.svg#download"/></svg> GPX für Komoot</a><button type="button" class="btn primary" id="selection-start" disabled><svg class="ic" aria-hidden="true"><use href="assets/icons.svg#bike"/></svg> Tour starten · Mängel prüfen</button></div>
                <div id="selection-navigation" class="selection-navigation"></div>
                <p class="map-hint">Die Linien kommen ausschließlich aus deiner KML – keine erfundenen Wege.</p>
              </section>
              <div class="map-pane map-container">
                <div id="pate-map" class="pate-map" role="region" aria-label="Streckenkarte Senden"></div>
                <div id="map-placeholder" class="map-placeholder" hidden>Bitte zuerst unter Einstellungen deine KML-Datei importieren.</div>
                <div class="map-overlay" id="map-overlay" aria-live="polite">
                  <button type="button" class="overlay-toggle" id="overlay-toggle" aria-expanded="false" aria-controls="overlay-body"><strong id="selection-title">Ausgewählte Touren</strong><span id="selection-count" class="smallnote">0 gewählt</span><svg class="ic" aria-hidden="true"><use href="assets/icons.svg#chevron-down"/></svg></button>
                  <div id="overlay-body" hidden><p id="selection-summary" class="combo-summary">Noch keine Tour ausgewählt.</p></div>
                  <p id="selection-warning" class="smallnote error-note" hidden></p>
                </div>
              </div>
            </div>
            <button type="button" class="map-fab" id="map-fab" hidden aria-expanded="false" aria-controls="selection-actions" aria-label="Aktionen zur Auswahl zeigen" title="Aktionen zur Auswahl"><svg class="ic" aria-hidden="true"><use href="assets/icons.svg#list-check"/></svg></button>
          </div>
          <aside class="map-sidebar">
            <div class="map-sidebar-head"><strong>Alle Touren</strong><span id="map-count" class="muted">0 / 7</span></div>
            <p class="smallnote selection-help">Touren anklicken – jede Auswahl erscheint auf der Karte. Mit dem runden Knopf auf der Karte geht es zu GPX, Tour starten und Kontrollfahrt.</p>
            <div id="map-route-picker" class="map-picker"></div>
          </aside>
        </section>
        <section id="routes-view" class="view">
          <div class="section-heading"><div><h2>Deine Touren</h2><p class="muted">Die Kartendaten werden zentral per KML eingespielt.</p></div></div>
          <div id="route-list" class="route-grid"></div>
        </section>
        <section id="diary-view" class="view" hidden>
          <div class="section-heading"><div><h2>Tourentagebuch</h2><p class="muted">Kontrollfahrten mit mehreren Strecken und einzelnen Mängelpositionen.</p></div><button class="btn primary small" id="new-entry-diary"><svg class="ic" aria-hidden="true"><use href="assets/icons.svg#plus"/></svg> Eintrag</button></div>
          <div class="filter-bar"><label class="sr-only" for="filter-tour">Tour filtern</label><select id="filter-tour"><option value="">Alle Touren</option></select><label class="sr-only" for="filter-status">Zustand filtern</label><select id="filter-status"><option value="">Alle Zustände</option><option value="offen">Mit offenen Mängeln</option><option value="mangel">Mit Mängeln (auch erledigte)</option><option value="gut">Ohne Mängel</option></select></div>
          <div id="diary-list" class="diary-list"></div>
          <div class="actions-row"><button class="btn outline" id="export-csv"><svg class="ic" aria-hidden="true"><use href="assets/icons.svg#download"/></svg> Tagebuch als CSV</button></div>
        </section>
        <section id="settings-view" class="view" hidden>
          <div class="section-heading"><div><h2>Einstellungen</h2><p class="muted">Touren und KML-Ebenen, Import und Datensicherung.</p></div></div>
          <div class="settings-grid">
            <article class="surface"><h3><svg class="ic" aria-hidden="true"><use href="assets/icons.svg#route"/></svg> Touren und KML-Ebenen</h3>
              <p class="muted">Fett steht der Name der Tour in der App – mit dem Stift änderst du Name, Bereich, Länge und Hinweis. Darunter die Ebene aus deiner My-Maps-KML und welcher Tour sie zugeordnet ist.</p>
              <div id="source-assignments"></div>
              <button type="button" class="btn outline full" id="save-assignments"><svg class="ic" aria-hidden="true"><use href="assets/icons.svg#check"/></svg> Zuordnung speichern</button>
            </article>
            <article class="surface"><h3><svg class="ic" aria-hidden="true"><use href="assets/icons.svg#upload"/></svg> KML importieren</h3><p class="muted">In Google My Maps: ⋮ → In KML/KMZ exportieren → <b>KML</b> wählen und die vollständige Karte exportieren. Vorhandene Tagebucheinträge bleiben erhalten.</p>
              <form id="kml-form"><label>KML-Datei auswählen<input type="file" id="kml-file" accept=".kml,application/vnd.google-earth.kml+xml,application/xml,text/xml" required></label><button type="submit" class="btn primary full"><svg class="ic" aria-hidden="true"><use href="assets/icons.svg#upload"/></svg> KML importieren</button></form>
              <p id="map-import-info" class="smallnote">Noch kein Import.</p>
              <details class="reference-map"><summary>Originalkarte in Google My Maps ansehen</summary><p class="muted">Zum Vergleich mit dem Import: zeigt alle My-Maps-Ebenen, unabhängig von der Auswahl auf der Karte.</p><iframe src="https://www.google.com/maps/d/embed?mid=1fJAjiQpdqoh6MZ5s0dh1wRHCcR69G0o&amp;hl=de&amp;ehbc=2E312F" title="My Maps Originalkarte der Radwegpaten-Touren" loading="lazy" referrerpolicy="no-referrer" allowfullscreen></iframe></details>
            </article>
            <article class="surface"><h3><svg class="ic" aria-hidden="true"><use href="assets/icons.svg#download"/></svg> Datensicherung</h3><p class="muted">Touren, Prüfungen und Einzelmängel als JSON-Datei. Fotos sind nicht enthalten – sie liegen im Datenordner auf dem Webspace und werden dort gesichert.</p><a href="api.php?action=export" class="btn outline full"><svg class="ic" aria-hidden="true"><use href="assets/icons.svg#download"/></svg> JSON-Sicherung herunterladen</a></article>
          </div><p class="smallnote">Zum Homescreen: Safari „Teilen → Zum Home-Bildschirm“, Chrome „Installieren“. Für Änderungen und Foto-Uploads ist eine Internetverbindung nötig.</p>
        </section>
      </main>
      <footer>Radwegpate · Dokumentation von Streckenkontrollen · Startpunkt Flurweg 7, 89250 Senden</footer>
      <nav class="bottom-nav" aria-label="Hauptbereiche">
        <button type="button" class="tab active" data-tab="map"><svg class="ic" aria-hidden="true"><use href="assets/icons.svg#map"/></svg><span>Karte</span></button>
        <button type="button" class="tab" data-tab="routes"><svg class="ic" aria-hidden="true"><use href="assets/icons.svg#route"/></svg><span>Touren</span></button>
        <button type="button" class="nav-add" id="mobile-add" aria-label="Neue Streckenprüfung" title="Neue Streckenprüfung"><svg class="ic" aria-hidden="true"><use href="assets/icons.svg#plus"/></svg></button>
        <button type="button" class="tab" data-tab="diary"><svg class="ic" aria-hidden="true"><use href="assets/icons.svg#notebook"/></svg><span>Tagebuch</span></button>
        <button type="button" class="tab" data-tab="settings"><svg class="ic" aria-hidden="true"><use href="assets/icons.svg#settings"/></svg><span>Einstellungen</span></button>
      </nav>
    </section>
  </div>
  <dialog id="prefs-dialog" class="modal" aria-labelledby="prefs-heading"><div class="modal-content">
    <div class="modal-heading"><div><p class="eyebrow">Persönlich</p><h2 id="prefs-heading">Darstellung &amp; Konto</h2></div><button type="button" class="close-dialog" data-close aria-label="Schließen"><svg class="ic" aria-hidden="true"><use href="assets/icons.svg#x"/></svg></button></div>
    <section id="appearance" class="prefs-section"><h3 class="prefs-title"><svg class="ic" aria-hidden="true"><use href="assets/icons.svg#palette"/></svg> Darstellung</h3>
              <fieldset class="seg-group"><legend>Farbschema</legend><div class="seg-options">
                <label><input type="radio" name="theme" value="auto"><span>Automatisch</span></label>
                <label><input type="radio" name="theme" value="light"><span>Hell</span></label>
                <label><input type="radio" name="theme" value="dark"><span>Dunkel</span></label>
                <label><input type="radio" name="theme" value="hc-light"><span>Kontrast hell</span></label>
                <label><input type="radio" name="theme" value="hc-dark"><span>Kontrast dunkel</span></label>
              </div></fieldset>
              <fieldset class="seg-group"><legend>Schriftgröße</legend><div class="seg-options">
                <label><input type="radio" name="fontsize" value=""><span>Normal</span></label>
                <label><input type="radio" name="fontsize" value="fs-lg"><span>Groß</span></label>
                <label><input type="radio" name="fontsize" value="fs-xl"><span>Sehr groß</span></label>
              </div></fieldset>
              <p class="smallnote">Gilt für dieses Gerät. „Automatisch“ folgt der Einstellung des Handys.</p>
            </section>
    <div class="rule"></div>
    <section class="prefs-section"><h3 class="prefs-title"><svg class="ic" aria-hidden="true"><use href="assets/icons.svg#lock"/></svg> Passwort ändern</h3>
      <form id="password-form"><label>Bisheriges Passwort<input type="password" name="old" required autocomplete="current-password"></label><label>Neues Passwort (mindestens 12 Zeichen)<input type="password" name="new" minlength="12" required autocomplete="new-password"></label><button type="submit" class="btn outline full"><svg class="ic" aria-hidden="true"><use href="assets/icons.svg#lock"/></svg> Passwort ändern</button></form>
    </section>
    <div class="rule"></div>
    <button type="button" class="btn subtle full" id="logout-button"><svg class="ic" aria-hidden="true"><use href="assets/icons.svg#logout"/></svg> Abmelden</button>
    <p class="smallnote prefs-version">Radwegpate · Version <?= htmlspecialchars(APP_VERSION) ?></p>
  </div></dialog>
  <dialog id="start-dialog" class="modal start-modal" aria-labelledby="start-heading"><div class="modal-content">
    <div class="modal-heading"><div><p class="eyebrow">KONTROLLFAHRT</p><h2 id="start-heading">Tour starten</h2></div><button type="button" class="close-dialog" data-close aria-label="Schließen"><svg class="ic" aria-hidden="true"><use href="assets/icons.svg#x"/></svg></button></div>
    <p id="start-tour-label" class="muted"></p>
    <section class="start-defects" aria-label="Vorhandene offene Mängel"><div class="start-defects-heading"><h3>Noch offene Mängel</h3><span id="start-issue-count" class="issue-counter">0</span></div><div id="start-issue-list" class="start-issue-list" aria-live="polite"></div></section><div id="start-navigation" class="start-navigation" aria-label="Navigation"></div>
    <p class="smallnote">Die Liste enthält bestehende Mängel dieser Touren aus früheren Prüfungen. Ein erledigter Mangel bleibt im Tagebuch dokumentiert und verschwindet nur aus der offenen Liste.</p>
    <div class="dialog-actions"><button type="button" class="btn subtle" data-close>Schließen</button><button type="button" class="btn primary" id="start-diary"><svg class="ic" aria-hidden="true"><use href="assets/icons.svg#plus"/></svg> Kontrollfahrt dokumentieren</button></div>
  </div></dialog>
  <dialog id="entry-dialog" class="modal"><form id="entry-form" class="modal-content" method="dialog">
    <div class="modal-heading"><div><p class="eyebrow">RADWEGPATE</p><h2 id="entry-heading">Neue Streckenprüfung</h2></div><button type="button" class="close-dialog" data-close aria-label="Schließen"><svg class="ic" aria-hidden="true"><use href="assets/icons.svg#x"/></svg></button></div>
    <input type="hidden" name="id" id="entry-id">
    <div class="entry-date-row"><label for="entry-date">Gefahren / geprüft am<input type="date" id="entry-date" name="date" required></label></div>
    <div class="entry-routes-wrapper"><span class="form-label">Kontrollierte Touren (mehrere möglich)</span><div id="entry-routes" class="entry-route-options" role="group" aria-label="Kontrollierte Touren"></div><small class="smallnote">Es können alle sieben Touren in einer Kontrollfahrt dokumentiert werden. Jede Tour ist anschließend im Tagebuch filterbar.</small></div>
    <label>Allgemeine Notiz zur Kontrollfahrt<textarea name="notes" id="entry-notes" maxlength="5000" rows="3" placeholder="Wetter, allgemeiner Zustand, besondere Beobachtungen …"></textarea></label>
    <div class="gps-row"><span id="gps-label" class="muted">GPS-Position der Kontrollfahrt: nicht erfasst</span><button type="button" class="btn outline small" id="get-position"><svg class="ic" aria-hidden="true"><use href="assets/icons.svg#current-location"/></svg> Position erfassen</button><button type="button" class="link-btn" id="clear-position" hidden>Entfernen</button></div>
    <div class="issues-header"><div><h3>Einzelne Mängel / Beobachtungen</h3><p class="smallnote">Jeder Mangel ist eine eigene Zeile mit Tour, Art, Ort, Status, Notiz, Position und Fotos.</p></div><button type="button" class="btn outline small" id="add-issue"><svg class="ic" aria-hidden="true"><use href="assets/icons.svg#plus"/></svg> Mangelzeile</button></div>
    <div id="entry-issues" class="issue-rows"></div>
    <label class="upload-field"><span><svg class="ic" aria-hidden="true"><use href="assets/icons.svg#camera"/></svg> Allgemeine Fotos zur Kontrollfahrt <small>(optional, max. 8; JPG/PNG/WebP). Mängelfotos bitte direkt in die Mangelzeile.</small></span><input type="file" id="entry-photos" name="photos" accept="image/*" multiple></label>
    <div id="existing-photos" class="photos-row"></div><div class="dialog-actions"><button type="button" class="btn subtle" data-close>Abbrechen</button><button type="submit" class="btn primary" id="save-entry">Eintrag speichern</button></div>
  </form></dialog>
  <dialog id="route-dialog" class="modal"><form id="route-form" class="modal-content" method="dialog">
    <div class="modal-heading"><div><p class="eyebrow">TOURDETAILS</p><h2>Tour bearbeiten</h2></div><button type="button" class="close-dialog" data-close aria-label="Schließen"><svg class="ic" aria-hidden="true"><use href="assets/icons.svg#x"/></svg></button></div>
    <input type="hidden" name="id" id="route-id"><label>Name der Tour<input id="route-name" name="name" required maxlength="90"></label><label>Beschreibung / Bereich<input id="route-area" name="area" maxlength="180"></label><label>Streckenlänge in km (optional, nur wenn bekannt)<input type="number" id="route-km" min="0.1" max="999" step="0.1" inputmode="decimal" placeholder="noch ungeprüft"></label>
    <p class="muted">Routenverläufe stammen aus deiner KML. Änderungen an der Route bitte in My Maps vornehmen und anschließend neu importieren.</p>
    <label>Hinweis / Stand der Route<textarea id="route-note" rows="3" maxlength="1000"></textarea></label>
    <div class="dialog-actions"><button type="button" class="btn subtle" data-close>Abbrechen</button><button type="submit" class="btn primary">Speichern</button></div>
  </form></dialog>
  <div role="status" id="toast" class="toast" aria-live="polite" hidden></div>
  <script src="assets/vendor/leaflet-1.9.4/leaflet.js" defer></script>
  <script src="assets/app.js?v=<?= $v ?>" defer></script>
</body>
</html>
