// theme-init.js — theme bootstrap, runs before paint so palette and
// dark/light mode never flash. Shared by index.php, splash.php, login.php.
(function () {
  try {
    var p = localStorage.getItem('expmg_palette');
    if (p === 'indigo' || p === 'sand' || p === 'cobalt') {
      document.body.setAttribute('data-palette', p);
    }
    if (localStorage.getItem('expmg_theme_mode') === 'dark') {
      document.body.classList.add('theme-dark');
    }
  } catch (_) { /* localStorage unavailable */
  }
})();
