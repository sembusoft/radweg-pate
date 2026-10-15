<?php
declare(strict_types=1);
require __DIR__ . '/version.php';
$v = rawurlencode(APP_VERSION);
header('Content-Type: text/html; charset=utf-8');
// Die Seite selbst nie aus dem HTTP-Cache: nach einem Deploy muss sofort die neue Fassung kommen.
header('Cache-Control: no-cache, must-revalidate');
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
          <label id="setup-code-wrap" hidden>Einrichtungscode<input id="setup-code" type="text" autocomplete="one-time-code" placeholder="INSTALL_TOKEN aus der .env"></label>
          <label>Name<input id="auth-name" type="text" autocomplete="username" autocapitalize="words" maxlength="60" required></label>
          <label>Passwort<input id="auth-password" type="password" minlength="12" autocomplete="current-password" required></label>
          <button type="submit" class="btn primary full" id="auth-submit">Anmelden</button>
        </form>
        <div id="auth-error" class="message error" role="alert" hidden></div>
      </div>
      <p class="smallnote">Version <?= htmlspecialchars(APP_VERSION) ?></p>
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
        <button type="button" class="trip-banner" id="trip-banner" hidden><svg class="ic" aria-hidden="true"><use href="assets/icons.svg#bike"/></svg><span id="trip-banner-text">Kontrollfahrt läuft</span><span class="trip-banner-go">Fortsetzen</span></button>
      </header>
      <main>
        <section id="map-view" class="view">
          <div class="section-heading"><div><h2>Meine Streckenkarte</h2><p class="muted">KML aus My Maps · Touren unten an- und abwählen.</p></div>
            <div class="heading-actions"><button type="button" class="btn outline small" id="map-zoom"><svg class="ic" aria-hidden="true"><use href="assets/icons.svg#arrows-maximize"/></svg> Alle zeigen</button><button type="button" class="btn outline small" id="selection-toggle"><svg class="ic" aria-hidden="true"><use href="assets/icons.svg#square-check"/></svg> Alle anwählen</button></div></div>
          <div class="map-stage" id="map-stage">
            <div class="map-slider">
              <section class="actions-pane" id="selection-actions" aria-labelledby="actions-title" inert>
                <h3 id="actions-title">Aktionen zur Auswahl</h3>
                <div class="combo-buttons"><a class="btn primary" id="selection-gpx" hidden href="#" target="_blank" rel="noopener"><svg class="ic" aria-hidden="true"><use href="assets/icons.svg#download"/></svg> GPX für Naviki</a><button type="button" class="btn primary" id="selection-start" disabled><svg class="ic" aria-hidden="true"><use href="assets/icons.svg#bike"/></svg> Tour starten · Mängel prüfen</button></div>
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
          <div class="section-heading"><div><h2>Deine Touren</h2></div></div>
          <div class="route-pick" id="route-pick" role="tablist" aria-label="Tour wählen"></div>
          <button type="button" class="btn outline small full route-demo-btn" id="route-demo" hidden><svg class="ic" aria-hidden="true"><use href="assets/icons.svg#player-play"/></svg> Demo-Tour zum Ausprobieren</button>
          <div id="route-list" class="route-grid"></div>
        </section>
        <section id="diary-view" class="view" hidden>
          <div class="section-heading"><div><h2>Tourentagebuch</h2><p class="muted">Kontrollfahrten mit mehreren Strecken und einzelnen Mängelpositionen.</p></div><button class="btn primary small" id="new-entry-diary"><svg class="ic" aria-hidden="true"><use href="assets/icons.svg#plus"/></svg> Eintrag</button></div>
          <div class="filter-bar"><label class="sr-only" for="filter-tour">Tour filtern</label><select id="filter-tour"><option value="">Alle Touren</option></select><label class="sr-only" for="filter-status">Zustand filtern</label><select id="filter-status"><option value="">Alle Zustände</option><option value="offen">Mit offenen Mängeln</option><option value="mangel">Mit Mängeln (auch erledigte)</option><option value="gut">Ohne Mängel</option></select><label class="sr-only" for="filter-user">Pate filtern</label><select id="filter-user" class="filter-user" hidden><option value="">Alle Paten</option></select></div>
          <div id="diary-list" class="diary-list"></div>
          <div class="actions-row"><button class="btn outline" id="export-csv"><svg class="ic" aria-hidden="true"><use href="assets/icons.svg#download"/></svg> Tagebuch als CSV</button></div>
        </section>
        <section id="settings-view" class="view" hidden>
          <div class="section-heading"><div><h2>Einstellungen</h2><p class="muted" id="settings-sub">Benutzer, Touren und KML-Ebenen, Import und Datensicherung.</p></div></div>
          <div class="settings-grid">
            <article class="surface" id="users-admin" data-admin-only hidden><h3><svg class="ic" aria-hidden="true"><use href="assets/icons.svg#users"/></svg> Benutzer</h3>
              <p class="muted">Paten sehen ihre eigenen Kontrollfahrten und die Mängel aller. Admins verwalten zusätzlich Benutzer, Touren, KML und Sicherung. Neue Benutzer ändern ihr Startpasswort beim ersten Anmelden.</p>
              <div id="user-list" class="user-list"></div>
              <button type="button" class="btn outline full" id="user-add"><svg class="ic" aria-hidden="true"><use href="assets/icons.svg#user-plus"/></svg> Benutzer anlegen</button>
            </article>
            <article class="surface" data-admin-only hidden><h3><svg class="ic" aria-hidden="true"><use href="assets/icons.svg#route"/></svg> Touren und KML-Ebenen</h3>
              <p class="muted">Fett steht der Name der Tour in der App – mit dem Stift änderst du Name, Bereich, Länge und Hinweis. Darunter die Ebene aus deiner My-Maps-KML und welcher Tour sie zugeordnet ist.</p>
              <div id="source-assignments"></div>
              <button type="button" class="btn outline full" id="save-assignments"><svg class="ic" aria-hidden="true"><use href="assets/icons.svg#check"/></svg> Zuordnung speichern</button>
              <div class="demo-admin" id="demo-admin"><span class="form-label">Demo-Tour</span><p class="smallnote">Zum Ausprobieren für neue Paten: nutzt die Strecke einer echten Tour, zählt nicht bei offenen Mängeln, Berichte tragen „DEMO“. Demo-Fahrten löschen sich nach 7 Tagen selbst.</p>
                <label>Strecke von Tour<select id="demo-source"></select></label>
                <button type="button" class="btn outline full" id="demo-reset"><svg class="ic" aria-hidden="true"><use href="assets/icons.svg#trash"/></svg> Alle Demo-Daten jetzt löschen</button></div>
            </article>
            <article class="surface" data-admin-only hidden><h3><svg class="ic" aria-hidden="true"><use href="assets/icons.svg#upload"/></svg> KML importieren</h3><p class="muted">In Google My Maps: ⋮ → In KML/KMZ exportieren → <b>KML</b> wählen und die vollständige Karte exportieren. Vorhandene Tagebucheinträge bleiben erhalten.</p>
              <form id="kml-form"><label>KML-Datei auswählen<input type="file" id="kml-file" accept=".kml,application/vnd.google-earth.kml+xml,application/xml,text/xml" required></label><button type="submit" class="btn primary full"><svg class="ic" aria-hidden="true"><use href="assets/icons.svg#upload"/></svg> KML importieren</button></form>
              <p id="map-import-info" class="smallnote">Noch kein Import.</p>
              <details class="reference-map"><summary>Originalkarte in Google My Maps ansehen</summary><p class="muted">Zum Vergleich mit dem Import: zeigt alle My-Maps-Ebenen, unabhängig von der Auswahl auf der Karte.</p><iframe src="https://www.google.com/maps/d/embed?mid=1fJAjiQpdqoh6MZ5s0dh1wRHCcR69G0o&amp;hl=de&amp;ehbc=2E312F" title="My Maps Originalkarte der Radwegpaten-Touren" loading="lazy" referrerpolicy="no-referrer" allowfullscreen></iframe></details>
            </article>
            <article class="surface" data-admin-only hidden><h3><svg class="ic" aria-hidden="true"><use href="assets/icons.svg#download"/></svg> Datensicherung</h3><p class="muted">Touren, Prüfungen und Einzelmängel als JSON-Datei. Fotos sind nicht enthalten – sie liegen im Datenordner auf dem Webspace und werden dort gesichert.</p><a href="api.php?action=export" class="btn outline full"><svg class="ic" aria-hidden="true"><use href="assets/icons.svg#download"/></svg> JSON-Sicherung herunterladen</a></article>
            <article class="surface" data-pate-only hidden><h3><svg class="ic" aria-hidden="true"><use href="assets/icons.svg#info-circle"/></svg> Touren und Benutzer</h3><p class="muted">Touren, KML-Karte, Benutzer und Datensicherung verwaltet der Admin. Dein Passwort, Farbschema und Schriftgröße findest du oben unter dem Zahnrad.</p></article>
          </div><p class="smallnote">Zum Homescreen: Safari „Teilen → Zum Home-Bildschirm“, Chrome „Installieren“. Für Änderungen und Foto-Uploads ist eine Internetverbindung nötig.</p>
          <p class="smallnote app-imprint">Radwegpate · Dokumentation von Streckenkontrollen · Startpunkt Flurweg 7, 89250 Senden</p>
        </section>
        <section id="trip-view" class="view" hidden aria-labelledby="trip-title">
          <div class="trip-head">
            <p class="eyebrow" id="trip-state">Kontrollfahrt läuft</p>
            <h2 id="trip-title">Kontrollfahrt</h2>
            <div class="trip-times">
              <label>Datum<input type="date" id="trip-date"></label>
              <label>Start<input type="time" id="trip-start"></label>
            </div>
          </div>
          <details class="surface trip-nav"><summary><svg class="ic" aria-hidden="true"><use href="assets/icons.svg#navigation"/></svg> Navigation &amp; GPX</summary><div id="trip-navigation"></div></details>
          <section class="trip-section" aria-labelledby="trip-new-title">
            <div class="trip-section-head"><h3 id="trip-new-title">Auf dieser Fahrt erfasst</h3><span id="trip-new-count" class="chip">0</span></div>
            <div id="trip-issue-list" class="start-issue-list" aria-live="polite"></div>
          </section>
          <section class="trip-section" aria-labelledby="trip-open-title">
            <div class="trip-section-head"><h3 id="trip-open-title">Noch offene Mängel dieser Touren</h3><span id="trip-open-count" class="issue-counter">0 offen</span></div>
            <div id="trip-open-list" class="start-issue-list" aria-live="polite"></div>
          </section>
          <div class="trip-bar">
            <button type="button" class="btn outline" id="trip-finish"><svg class="ic" aria-hidden="true"><use href="assets/icons.svg#flag"/></svg> Fahrt beenden</button>
            <button type="button" class="btn primary" id="trip-add-issue"><svg class="ic" aria-hidden="true"><use href="assets/icons.svg#plus"/></svg> Mangel erfassen</button>
          </div>
        </section>
      </main>
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
    <section class="prefs-section" id="password-section"><h3 class="prefs-title"><svg class="ic" aria-hidden="true"><use href="assets/icons.svg#lock"/></svg> Passwort ändern</h3>
      <p class="message warn" id="pw-required" hidden>Du bist mit einem Startpasswort angemeldet. Bitte lege jetzt dein eigenes Passwort fest – vorher kannst du nichts speichern.</p>
      <form id="password-form"><input type="text" name="username" id="password-username" autocomplete="username" hidden readonly tabindex="-1" aria-hidden="true"><label>Bisheriges Passwort<input type="password" name="old" required autocomplete="current-password"></label><label>Neues Passwort (mindestens 12 Zeichen)<input type="password" name="new" minlength="12" required autocomplete="new-password"></label><button type="submit" class="btn outline full"><svg class="ic" aria-hidden="true"><use href="assets/icons.svg#lock"/></svg> Passwort ändern</button></form>
    </section>
    <div class="rule"></div>
    <p class="smallnote" id="whoami"></p>
    <button type="button" class="btn subtle full" id="logout-button"><svg class="ic" aria-hidden="true"><use href="assets/icons.svg#logout"/></svg> Abmelden</button>
    <p class="smallnote prefs-version">Radwegpate · Version <?= htmlspecialchars(APP_VERSION) ?></p>
  </div></dialog>
  <dialog id="report-dialog" class="modal" aria-labelledby="report-heading"><div class="modal-content">
    <div class="modal-heading"><div><p class="eyebrow">Kontrollfahrt</p><h2 id="report-heading">Mängelbericht</h2></div><button type="button" class="close-dialog" data-close aria-label="Schließen"><svg class="ic" aria-hidden="true"><use href="assets/icons.svg#x"/></svg></button></div>
    <p id="report-summary" class="muted"></p>
    <p class="smallnote">PDF mit den Mängeln dieser Fahrt und den weiterhin offenen Mängeln ihrer Touren – mit Fotos und GPS-Links.</p>
    <p id="report-status" class="smallnote" aria-live="polite"></p>
    <button type="button" class="btn primary full" id="report-share" disabled><svg class="ic" aria-hidden="true"><use href="assets/icons.svg#mail"/></svg> Per Mail senden</button>
    <a class="btn outline full" id="report-open" href="#" target="_blank" rel="noopener"><svg class="ic" aria-hidden="true"><use href="assets/icons.svg#file-text"/></svg> PDF ansehen</a>
    <p class="smallnote" id="report-hint"></p>
  </div></dialog>
  <dialog id="user-dialog" class="modal" aria-labelledby="user-heading"><form id="user-form" class="modal-content" method="dialog">
    <div class="modal-heading"><div><p class="eyebrow">Benutzer</p><h2 id="user-heading">Benutzer anlegen</h2></div><button type="button" class="close-dialog" data-close aria-label="Schließen"><svg class="ic" aria-hidden="true"><use href="assets/icons.svg#x"/></svg></button></div>
    <input type="hidden" id="user-id">
    <label>Name (zum Anmelden)<input id="user-name" maxlength="60" autocomplete="off" required></label>
    <label>Rolle<select id="user-role"><option value="pate">Pate – eigene Fahrten, Mängel aller</option><option value="admin">Admin – zusätzlich Benutzer, Touren, KML, Sicherung</option></select></label>
    <label class="check-row"><input type="checkbox" id="user-active" checked> <span>Zugang aktiv</span></label>
    <div class="entry-routes-wrapper"><span class="form-label">Touren dieses Paten</span><p class="smallnote">Stehen bei ihm vorn und sind vorausgewählt; andere Touren kann er trotzdem fahren. Eine Tour kann mehrere Paten haben.</p><div id="user-routes" class="entry-route-options" role="group" aria-label="Touren dieses Paten"></div></div>
    <label><span id="user-pw-label">Startpasswort (mindestens 12 Zeichen)</span><input id="user-password" type="text" minlength="12" maxlength="256" autocomplete="off" spellcheck="false"></label>
    <div class="dialog-inline"><button type="button" class="btn outline small" id="user-pw-suggest"><svg class="ic" aria-hidden="true"><use href="assets/icons.svg#refresh"/></svg> Passwort vorschlagen</button></div>
    <p class="smallnote" id="user-pw-hint">Das Startpasswort gibst du der Person weiter; sie muss es beim ersten Anmelden ändern.</p>
    <div class="dialog-actions"><button type="button" class="btn subtle" data-close>Abbrechen</button><button type="submit" class="btn primary"><svg class="ic" aria-hidden="true"><use href="assets/icons.svg#check"/></svg> Speichern</button></div>
  </form></dialog>
  <dialog id="trip-start-dialog" class="modal" aria-labelledby="trip-start-heading"><form id="trip-start-form" class="modal-content" method="dialog">
    <div class="modal-heading"><div><p class="eyebrow">Kontrollfahrt</p><h2 id="trip-start-heading">Fahrt beginnen</h2></div><button type="button" class="close-dialog" data-close aria-label="Schließen"><svg class="ic" aria-hidden="true"><use href="assets/icons.svg#x"/></svg></button></div>
    <div class="entry-routes-wrapper"><span class="form-label">Touren dieser Fahrt</span><div id="trip-start-routes" class="entry-route-options" role="group" aria-label="Touren dieser Fahrt"></div></div>
    <div class="time-row"><label>Datum<input type="date" id="trip-start-date" required></label><label>Startzeit<input type="time" id="trip-start-time" required></label></div>
    <div class="dialog-actions"><button type="button" class="btn subtle" data-close>Abbrechen</button><button type="submit" class="btn primary"><svg class="ic" aria-hidden="true"><use href="assets/icons.svg#bike"/></svg> Fahrt beginnen</button></div>
  </form></dialog>
  <dialog id="issue-dialog" class="modal" aria-labelledby="issue-heading"><form id="issue-form" class="modal-content" method="dialog">
    <div class="modal-heading"><div><p class="eyebrow" id="issue-eyebrow">Neuer Mangel</p><h2 id="issue-heading">Mangel erfassen</h2></div><button type="button" class="close-dialog" data-close aria-label="Schließen"><svg class="ic" aria-hidden="true"><use href="assets/icons.svg#x"/></svg></button></div>
    <input type="hidden" id="issue-id">
    <div class="issue-sheet-grid">
      <label id="issue-tour-wrap">Tour<select id="issue-tour"></select></label>
      <label>Art<select id="issue-kind"></select></label>
      <label>Uhrzeit<input type="time" id="issue-time" required></label>
      <label>Status<select id="issue-status"></select></label>
    </div>
    <label>Ort / Abschnitt<input id="issue-place" maxlength="180" placeholder="z. B. Brücke, Straßenname, Kreuzung"></label>
    <label>Beschreibung<textarea id="issue-description" maxlength="3000" rows="3" placeholder="Was ist defekt oder aufgefallen?"></textarea></label>
    <div class="gps-row"><span id="issue-gps-label" class="muted">GPS: nicht erfasst</span><button type="button" class="btn outline small" id="issue-gps"><svg class="ic" aria-hidden="true"><use href="assets/icons.svg#current-location"/></svg> Neu erfassen</button><button type="button" class="link-btn" id="issue-gps-clear" hidden>Entfernen</button></div>
    <label class="upload-field"><span><svg class="ic" aria-hidden="true"><use href="assets/icons.svg#camera"/></svg> Fotos <small>(max. 5 je Mangel)</small></span><input type="file" id="issue-photos" accept="image/*" multiple></label>
    <div id="issue-existing-photos" class="photos-row"></div>
    <button type="button" class="text-btn danger-link" id="issue-delete" hidden><svg class="ic" aria-hidden="true"><use href="assets/icons.svg#trash"/></svg> Mangel löschen</button>
    <div class="dialog-actions"><button type="button" class="btn subtle" data-close>Abbrechen</button><button type="submit" class="btn primary" id="issue-save"><svg class="ic" aria-hidden="true"><use href="assets/icons.svg#check"/></svg> Speichern</button></div>
  </form></dialog>
  <dialog id="trip-finish-dialog" class="modal" aria-labelledby="trip-finish-heading"><form id="trip-finish-form" class="modal-content" method="dialog">
    <div class="modal-heading"><div><p class="eyebrow">Kontrollfahrt</p><h2 id="trip-finish-heading">Fahrt beenden</h2></div><button type="button" class="close-dialog" data-close aria-label="Schließen"><svg class="ic" aria-hidden="true"><use href="assets/icons.svg#x"/></svg></button></div>
    <p id="trip-finish-summary" class="muted"></p>
    <label>Endzeit<input type="time" id="trip-end-time" required></label>
    <label>Allgemeine Notiz<textarea id="trip-notes" maxlength="5000" rows="3" placeholder="Wetter, allgemeiner Zustand, besondere Beobachtungen …"></textarea></label>
    <label class="upload-field"><span><svg class="ic" aria-hidden="true"><use href="assets/icons.svg#camera"/></svg> Allgemeine Fotos zur Fahrt <small>(optional, max. 8)</small></span><input type="file" id="trip-photos" accept="image/*" multiple></label>
    <button type="button" class="text-btn danger-link" id="trip-discard" hidden><svg class="ic" aria-hidden="true"><use href="assets/icons.svg#trash"/></svg> Fahrt verwerfen (noch nichts erfasst)</button>
    <div class="dialog-actions"><button type="button" class="btn subtle" data-close>Weiterfahren</button><button type="submit" class="btn primary" id="trip-finish-save"><svg class="ic" aria-hidden="true"><use href="assets/icons.svg#flag"/></svg> Fahrt abschließen</button></div>
  </form></dialog>
  <dialog id="entry-dialog" class="modal"><form id="entry-form" class="modal-content" method="dialog">
    <div class="modal-heading"><div><p class="eyebrow">RADWEGPATE</p><h2 id="entry-heading">Neue Streckenprüfung</h2></div><button type="button" class="close-dialog" data-close aria-label="Schließen"><svg class="ic" aria-hidden="true"><use href="assets/icons.svg#x"/></svg></button></div>
    <input type="hidden" name="id" id="entry-id">
    <div class="time-row time-row-3"><label for="entry-date">Datum<input type="date" id="entry-date" name="date" required></label><label>Start<input type="time" id="entry-start" name="startTime"></label><label>Ende<input type="time" id="entry-end" name="endTime"></label></div>
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
  <div role="status" id="toast" class="toast" aria-live="polite" popover="manual" hidden></div>
  <script src="assets/vendor/leaflet-1.9.4/leaflet.js" defer></script>
  <script src="assets/app.js?v=<?= $v ?>" defer></script>
</body>
</html>
