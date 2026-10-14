# Radwegpate — Design-System

**Stand:** 14.10.2026 · Version 3.5.0
**Gilt für:** `public/assets/style.css`, `public/index.php`, `public/assets/app.js`
**Vorbild:** FightReg (`DESIGN_SYSTEM.md`, Kapitel 4 und 16) — gleiche Begriffe, kleiner zugeschnitten.

Dieses Dokument beschreibt, was der Code tut. Wo beides auseinanderläuft, ist der Code die Wahrheit
und dieses Dokument der Fehler — dann wird es nachgezogen.

---

## 1. Zielgerät

| | |
|---|---|
| **Bezugsbreite** | 390 px (iPhone 13–16, gängige Android-Geräte). Kleinste geprüfte Breite 360 px. |
| **Spalte** | eine; `.page-shell` höchstens **720 px**, mittig. Breitere Bildschirme: nicht optimiert, nur nicht kaputt. |
| **Ab 560 px** | einzige Abweichung: Dialog wird mittige Karte statt Blatt, Formulare dürfen zwei/drei Felder je Zeile. |
| **Bedienung** | Daumen. Wichtiges unten (Tab-Leiste, Dialog-Fuß), nichts nur per Hover. |
| **Umgebung** | draußen, Sonne → Hochkontrast-Themes sind vollwertig, nicht Beiwerk. |

## 2. Typografie

**Regel 1: Jede Schriftgröße in `rem`.** `html { font-size: calc(100% * var(--fs)) }` —
damit wächst alles mit der Einstellung *Schriftgröße* und mit der Systemschrift des Browsers.

| Klasse am `<html>` | `--fs` | Fließtext |
|---|---|---|
| — (Normal) | 1 | 16 px |
| `.fs-lg` (Groß) | 1.125 | 18 px |
| `.fs-xl` (Sehr groß) | 1.25 | 20 px |

| Verwendung | Größe | |
|---|---|---|
| Fließtext, Knöpfe, **Eingabefelder** | 1rem (16 px) | Eingaben nie kleiner: iOS zoomt sonst hinein |
| Kleine Knöpfe, Labels, Listenzeilen | 0.9375rem (15 px) | |
| Hinweise (`.smallnote`), Chips, Status | 0.875 / 0.8125rem | **0.8125rem (13 px) ist die Untergrenze** |
| h1 / h2 / h3 | 1.625 / 1.375 / 1.125rem | |
| Kennzahl im Kopf | 1.25rem, 800 | |

Schrift: Systemschrift (`Inter` falls installiert, sonst `-apple-system`/`Segoe UI`/`Roboto`).
Keine Webfont — spart Ladezeit unterwegs.

**Ausnahmen von Regel 1** (fest in px, weil keine Schrift): Rahmen, Radien, Icon-Kästen mit festem
Maß, Mindesthöhen von Trefferflächen.

## 3. Farben und Themes

### Themes

| Wahl in *Einstellungen → Darstellung* | `data-theme` am `<html>` |
|---|---|
| Automatisch (Vorgabe) | `light` oder `dark`, je nach `prefers-color-scheme` — wechselt live mit |
| Hell | `light` |
| Dunkel | `dark` |
| Kontrast hell | `hc-light` |
| Kontrast dunkel | `hc-dark` |

**Die Wahl** steht je Gerät in `localStorage` (`radwegpate-theme`, `radwegpate-fs`), **das Ergebnis**
im Attribut. `auto` kommt im CSS nicht vor — `theme-init.js` (synchron im `<head>`, kein Aufblitzen)
und `applyAppearance()` in `app.js` lösen es auf. Wer wissen will, wie die Seite gerade aussieht,
liest `data-theme`.

### Tokens

Alle in `style.css`, ein Block je Theme (`:root` = hell).

| Token | Rolle |
|---|---|
| `--bg` | Seitengrund |
| `--surface` / `--surface-2` / `--surface-3` | Karte / abgesetzte Fläche in der Karte / Hover |
| `--border` / `--border-strong` | Trennlinie / **Umriss von Bedienelementen** (≥ 3:1) |
| `--text` / `--text-muted` | Schrift / Nebenschrift (≥ 4,5:1 auf allen Flächen) |
| `--primary` / `--primary-hover` / `--on-primary` | Hauptfarbe als **Fläche** / Hover / Schrift darauf |
| `--primary-text` | Hauptfarbe als **Schrift** (Links, Textknöpfe, aktiver Tab) |
| `--primary-soft` | zarter Hintergrund für Gewähltes (aktiver Tab, gewählte Tour) |
| `--success-*`, `--warning-*`, `--danger-*`, `--info-*` | je `-bg` (Fläche) und `-text` (Schrift) |
| `--focus` | Fokusring (3 px) |
| `--input-bg`, `--overlay`, `--shadow`, `--bw` | Eingabefeld, Dialog-Abdunklung, Schatten, **Rahmenstärke** |

**Regel: `background: var(--x)` → `color: var(--on-x)` bzw. `var(--x-text)`.** Nie `#fff` daneben
schreiben — in `hc-dark` ist `--primary` Gelb, und Weiß darauf ist unlesbar.

### Hauptfarbe je Theme

| | light | dark | hc-light | hc-dark |
|---|---|---|---|---|
| `--primary` | `#14654a` | `#4fbf8b` | `#00472b` | `#ffe500` |
| `--on-primary` | weiß | `#03160d` | weiß | schwarz |
| `--bw` | 1 px | 1 px | **2 px** | **2 px** |

### Hochkontrast

Eigene Paletten, keine Feinjustierung: reines Schwarz/Weiß, 2-px-Rahmen um jedes Bedienelement
(Umrisse erkennbar unabhängig von der Füllung, WCAG 1.4.11), Status-Pillen mit Rahmen in
Schriftfarbe, kein Schatten. Schwellen **7:1** für Text. Dazu ein `forced-colors`-Block für den
Windows-Kontrastmodus.

### Prüfung

`scripts/contrast.mjs` rechnet **28 Farbpaare × 4 Themes** nach WCAG und bricht die CI ab, wenn
eines unter der Schwelle liegt (Text 4,5:1, Hochkontrast 7:1, Umrisse/Fokus 3:1).
**Wer ein Token ändert oder eine neue Paarung einführt, ergänzt dort das Paar.**

### Tourfarben

`.tour-c01` … `.tour-c07` (Punkte in der Tourliste) = `MAP_COLORS` in `app.js` (Linien auf der
Karte). Beide Listen gemeinsam ändern. Als Klassen, weil die CSP keine Inline-Styles erlaubt.
Die Farbe ist nie der einzige Hinweis: Tournummer und Name stehen immer daneben.

## 4. Bedienelemente

| Baustein | Maß | Regel |
|---|---|---|
| `.btn` | **48 px** hoch, 1rem, Radius 12 | `.primary` (Fläche) · `.outline` (Rahmen `--border-strong`) · `.subtle` |
| `.btn.small` | 44 px, 0.9375rem | nie kleiner |
| `.btn.full` | volle Breite | Standard in Einstellungen und Formular-Fuß |
| `.text-btn`, `.link-btn` | 44 px Trefferfläche | für Nebenaktionen (Bearbeiten, Entfernen) |
| `.icon-button` | 48 × 48 | nur Icon → **immer `aria-label` + `title`** |
| Eingabefelder | 48 px, 1rem | Rahmen `--border-strong` (≥ 3:1) |
| Checkbox/Radio | 22 px | in einer ≥ 48 px hohen Zeile mit Label |
| `.seg-group` | Pillen 44 px | Einzelwahl als Radios (Darstellung) |

Gesperrte Knöpfe: `disabled` + `opacity: .55`; der **Grund** steht als Text daneben
(z. B. `#selection-warning`).

## 5. Seitenaufbau

```
┌ .topbar (sticky) ───────────────────────┐
│ [Logo] Radwegpate             [⚙ 48px]  │
│ [7 Strecken][1 Prüfung][1 offene Mängel]│  ← .kpis: 3 gleich breite Badges
├─────────────────────────────────────────┤
│ Inhalt der aktiven Ansicht (.view)      │
│ …                                       │
├ .bottom-nav (fixed) ────────────────────┤
│ Karte  Touren  ( + )  Tagebuch  Einst.  │  ← 56 px, Plus 56 px rund
└─────────────────────────────────────────┘
```

- **Kennzahlen-Badges (`.kpi`)** sind Knöpfe: *Strecken* → Touren, *Prüfungen* → Tagebuch,
  *offene Mängel* → Tagebuch mit Filter „Mit offenen Mängeln“. Bei offenen Mängeln trägt der Badge
  `.kpi-warn` (Warnfarbe + Rahmen), sonst `.kpi-ok`.
- **Tab-Leiste unten:** Icon 26 px + Text 13 px; aktiv = `--primary-text` auf `--primary-soft`
  **und** unterstrichen (nicht nur Farbe). `aria-current="page"` am aktiven Tab.
- **Plus-Taste** in der Mitte: neue Streckenprüfung (gleicher Weg wie „+ Eintrag“ im Tagebuch).
- **Dialoge** (`<dialog class="modal">`) sind unter 560 px ein **Blatt von unten**, höchstens 94 %
  der Höhe; der Fuß (`.dialog-actions`) klebt unten, damit *Speichern* nie weggescrollt ist.
- **Toast** sitzt über der Tab-Leiste.
- Platz für die Safe Area (Notch, Home-Indikator) über `env(safe-area-inset-*)`.

## 6. Icons

**Tabler Icons 3.31.0 (MIT), als SVG-Sprite** `public/assets/icons.svg` — nur die benutzten
(~30 Stück, ~10 KB), offline sofort verfügbar. Keine Webfont (820 KB).

```javascript
ic('settings')                          // app.js → '<svg class="ic" …><use href="assets/icons.svg#settings"/></svg>'
```
```html
<svg class="ic" aria-hidden="true"><use href="assets/icons.svg#settings"/></svg>
```

- `.ic` ist 1.25em groß und erbt die Schriftfarbe (`currentColor`) — wächst mit `--fs`.
- Icons sind **Schmuck** (`aria-hidden`); die Bedeutung trägt der Text bzw. das `aria-label`.
- **Neues Icon:** Namen in `ICONS` in `scripts/build-icons.mjs` eintragen →
  `npm i --no-save @tabler/icons@3.31.0` → `node scripts/build-icons.mjs` → Version erhöhen.
  `check.mjs` meldet jedes benutzte Icon, das im Sprite fehlt (sonst bliebe die Stelle still leer).
- **Keine Emojis oder Unicode-Pfeile** (🚲 ↗ ✓ ⚙) als Icons: unterschiedlich je Gerät, nicht
  einfärbbar, in Hochkontrast oft unsichtbar.

Feste Zuordnung:

| Bedeutung | Icon | Bedeutung | Icon |
|---|---|---|---|
| Einstellungen | `settings` | Karte | `map` |
| Touren / Strecken | `route` | Tagebuch | `notebook` |
| Prüfungen | `clipboard-check` | offene Mängel | `alert-triangle` |
| erledigt / in Ordnung | `circle-check`, `check` | wieder öffnen | `arrow-back-up` |
| Tour starten / Abfahrt | `bike` | Navigation zum Ziel | `navigation` |
| GPS-Punkt | `map-pin` | GPS erfassen | `current-location` |
| Download / Upload | `download` / `upload` | Foto | `camera` |
| bearbeiten / löschen | `pencil` / `trash` | Darstellung | `palette` |

## 7. Prüfungen

| Was | Wo | Wann |
|---|---|---|
| Kontrast 28 Paare × 4 Themes | `scripts/contrast.mjs` | `check.mjs`, CI bei jedem Push |
| Icons im Sprite, keine Inline-Styles/-Handler, Version, SW-Shell | `scripts/check.mjs` | CI |
| Funktion gegen echten PHP-Server | `scripts/smoke-test.mjs` | CI |
| Seitliches Überstehen, Trefferflächen < 44 px, kleinste Schrift | Browsertest (Playwright, 390 px, alle Ansichten × 3 Schriftgrößen) | bei Oberflächenänderungen, von Hand |

Von Hand vor jedem Release: Handy, **Kontrast dunkel** + **Sehr groß**, einmal jede Ansicht.
