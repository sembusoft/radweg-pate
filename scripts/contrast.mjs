// Kontrastprüfung der Farb-Tokens in public/assets/style.css – alle vier Themes.
// Aufruf: node scripts/contrast.mjs   (läuft auch in der CI über check.mjs)
// Schwellen: Text 4,5:1 (WCAG AA), in den Hochkontrast-Themes 7:1 (AAA);
// Bedienelement-Umrisse und Fokusring 3:1 (WCAG 1.4.11).
import { readFileSync } from 'node:fs';

const css = readFileSync(new URL('../public/assets/style.css', import.meta.url), 'utf8');

function block(selector) {
  const i = css.indexOf(selector + ' {');
  if (i < 0) throw new Error('Token-Block fehlt: ' + selector);
  const body = css.slice(css.indexOf('{', i) + 1, css.indexOf('}', i));
  return Object.fromEntries([...body.matchAll(/--([\w-]+):\s*(#[0-9a-fA-F]{6})\b/g)].map(m => [m[1], m[2]]));
}
const light = block(':root');
const themes = {
  light,
  dark: { ...light, ...block(':root[data-theme="dark"]') },
  'hc-dark': { ...light, ...block(':root[data-theme="hc-dark"]') },
  'hc-light': { ...light, ...block(':root[data-theme="hc-light"]') }
};

const lum = hex => {
  const c = [1, 3, 5].map(i => parseInt(hex.slice(i, i + 2), 16) / 255)
    .map(v => v <= 0.03928 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4);
  return 0.2126 * c[0] + 0.7152 * c[1] + 0.0722 * c[2];
};
const ratio = (a, b) => { const [x, y] = [lum(a), lum(b)].sort((p, q) => q - p); return (x + 0.05) / (y + 0.05); };

// [Vordergrund, Hintergrund, Art]  Art: 'text' | 'ui'
const PAIRS = [
  ['text', 'bg', 'text'], ['text', 'bg-card', 'text'], ['text', 'bg-subtle', 'text'], ['text', 'bg-input', 'text'],
  ['text-muted', 'bg', 'text'], ['text-muted', 'bg-card', 'text'], ['text-muted', 'bg-subtle', 'text'], ['text-muted', 'primary-soft', 'text'],
  ['primary-text', 'bg-card', 'text'], ['primary-text', 'bg', 'text'], ['primary-text', 'bg-subtle', 'text'], ['primary-text', 'primary-soft', 'text'],
  ['on-primary', 'primary', 'text'], ['on-primary', 'primary-hover', 'text'],
  ['success-text', 'success-bg', 'text'], ['warning-text', 'warning-bg', 'text'],
  ['danger-text', 'danger-bg', 'text'], ['info-text', 'info-bg', 'text'],
  ['on-deep', 'bg-deep', 'text'],  // Toast
  ['on-danger', 'danger', 'text'], // Toast Fehler, Zähler, .btn.danger
  ['text-label', 'bg-card', 'text'], ['text-label', 'bg-subtle', 'text'], // Labels, Sekundärknopf
  ['danger-text', 'bg-card', 'text'], // Foto entfernen ×
  ['border-strong', 'bg-card', 'ui'], ['border-strong', 'bg-input', 'ui'], ['border-strong', 'bg-subtle', 'ui'],
  ['primary', 'bg-card', 'ui'], ['primary', 'bg', 'ui'],
  ['focus', 'bg', 'ui'], ['focus', 'bg-card', 'ui']
];

let fails = 0, checks = 0;
for (const [name, t] of Object.entries(themes)) {
  const hc = name.startsWith('hc-');
  for (const [fg, bg, kind] of PAIRS) {
    if (!t[fg] || !t[bg]) { console.log(`FEHL ${name}: Token --${!t[fg] ? fg : bg} fehlt`); fails++; continue; }
    const need = kind === 'ui' ? 3 : hc ? 7 : 4.5;
    const r = ratio(t[fg], t[bg]);
    checks++;
    if (r < need) { fails++; console.log(`FEHL ${name.padEnd(8)} --${fg} auf --${bg}: ${r.toFixed(2)}:1 (nötig ${need}:1)  ${t[fg]} / ${t[bg]}`); }
  }
}
console.log(fails ? `\n${fails} von ${checks} Kontrastpaaren zu schwach.` : `ok   Kontrast: ${checks} Paare in 4 Themes`);
process.exit(fails ? 1 : 0);
