// splash.js — 4-second splash sequence: fade-out after 3.4s, then redirect
// to the target carried on #splashScreen's data-redirect attribute.
(function () {
  var el = document.getElementById('splashScreen');
  var redirect = el ? el.dataset.redirect : 'index.php';
  setTimeout(function () {
    el.classList.add('fade-out');
    setTimeout(function () {
      window.location.href = redirect;
    }, 600);
  }, 3400);
})();
