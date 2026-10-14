// Läuft synchron im <head>, bevor CSS gezeichnet wird – verhindert ein
// Aufblitzen im falschen Farbschema. Die Wahl liegt je Gerät im localStorage.
// Wahl (auto|light|dark|hc-light|hc-dark) steht in 'radwegpate-theme',
// das Ergebnis in <html data-theme>. 'auto' fragt das Betriebssystem.
(function () {
  var root = document.documentElement, theme = 'auto', fs = '';
  try { theme = localStorage.getItem('radwegpate-theme') || 'auto'; fs = localStorage.getItem('radwegpate-fs') || ''; } catch (e) {}
  if (theme === 'auto') theme = window.matchMedia && matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
  if (['light', 'dark', 'hc-light', 'hc-dark'].indexOf(theme) < 0) theme = 'light';
  root.setAttribute('data-theme', theme);
  if (fs === 'fs-lg' || fs === 'fs-xl') root.classList.add(fs);
})();
