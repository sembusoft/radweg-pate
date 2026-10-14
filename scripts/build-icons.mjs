// Baut public/assets/icons.svg – einen SVG-Sprite mit genau den Tabler-Icons,
// die die App benutzt. Tabler Icons (MIT), https://tabler.io/icons
//
// Neues Icon: Namen unten in ICONS eintragen, dann
//   npm i --no-save @tabler/icons@3.31.0
//   node scripts/build-icons.mjs
// Im Code: ic('name') in app.js bzw. <svg class="ic"><use href="assets/icons.svg#name"/></svg>
// scripts/check.mjs bricht ab, wenn ein benutztes Icon im Sprite fehlt.
import { readFileSync, writeFileSync, existsSync } from 'node:fs';

export const ICONS = [
  // Navigation und Kopf
  'map', 'route', 'notebook', 'settings', 'plus', 'x',
  // Kennzahlen
  'clipboard-check', 'alert-triangle', 'circle-check',
  // Touren und Karte
  'bike', 'player-play', 'navigation', 'map-pin', 'arrows-maximize', 'external-link', 'download', 'upload', 'file-upload',
  // Tagebuch und Mängel
  'pencil', 'trash', 'check', 'arrow-back-up', 'camera', 'current-location', 'info-circle', 'list-check',
  // Karte: Auswahl umschalten
  'square-check', 'square-x', 'chevron-down',
  // Laufende Kontrollfahrt
  'clock', 'flag',
  // Einstellungen
  'palette', 'text-size', 'lock', 'logout', 'refresh'
];

const base = process.argv[2] || 'node_modules/@tabler/icons/icons/outline';
const out = new URL('../public/assets/icons.svg', import.meta.url).pathname;

const symbols = ICONS.map(name => {
  const file = `${base}/${name}.svg`;
  if (!existsSync(file)) throw new Error(`Tabler-Icon fehlt: ${name} (${file})`);
  const svg = readFileSync(file, 'utf8');
  const inner = svg
    .replace(/^[\s\S]*?<svg[^>]*>/, '')
    .replace(/<\/svg>\s*$/, '')
    .replace(/<path stroke="none" d="M0 0h24v24H0z" fill="none"\s*\/>/, '')
    .replace(/\s*\n\s*/g, '');
  return `<symbol id="${name}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">${inner}</symbol>`;
});

const sprite = `<svg xmlns="http://www.w3.org/2000/svg" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
<!-- Tabler Icons 3.31.0 (MIT) – erzeugt von scripts/build-icons.mjs, nicht von Hand bearbeiten -->
${symbols.join('\n')}
</svg>
`;
writeFileSync(out, sprite);
console.log(`${ICONS.length} Icons → ${out} (${sprite.length} Bytes)`);
