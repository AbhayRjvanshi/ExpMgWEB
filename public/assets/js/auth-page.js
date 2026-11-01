// auth-page.js — shared by login.php and signup.php.
// Reads data-retry-after / data-retry-message from <body> (rate-limit
// cooldown feedback) and guards the signup password-match check.
(function () {
  var body = document.body;
  var retryAfter = parseInt(body.dataset.retryAfter || '0', 10);
  if (retryAfter > 0 && window.ExpMgStatus && typeof window.ExpMgStatus.showCooldown === 'function') {
    window.ExpMgStatus.showCooldown(retryAfter, body.dataset.retryMessage || 'Too many attempts. Please wait before trying again.');
  }

  var form = document.getElementById('signupForm');
  if (form) {
    form.addEventListener('submit', function (e) {
      var pw = document.getElementById('password').value;
      var cpw = document.getElementById('confirm_password').value;
      if (pw !== cpw) {
        e.preventDefault();
        alert('Passwords do not match.');
      }
    });
  }
})();
