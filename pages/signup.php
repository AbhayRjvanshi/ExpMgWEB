<?php
require_once __DIR__ . '/../config/session.php';
configureSecureSessionCookie();
session_start();
$rateLimitRetryAfter = isset($_SESSION['rate_limit_retry_after']) ? (int) $_SESSION['rate_limit_retry_after'] : 0;
unset($_SESSION['rate_limit_retry_after']);

// Show splash screen on fresh visit
if (empty($_SESSION['splash_seen'])) {
    header('Location: ../public/splash.php');
    exit;
}

// Redirect if already logged in
if (isset($_SESSION['user_id'])) {
    header('Location: ../public/index.php');
    exit;
}

// L-3 (PENTEST/low severity/L-3.md): render the CSRF token the API will
// verify (see pages/login.php for the rationale).
require_once __DIR__ . '/../api/helpers/csrf.php';
$signupCsrfToken = getCsrfToken();
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Sign Up — Expense Manager</title>
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500;600;700&family=Plus+Jakarta+Sans:wght@400;600;700;800&display=swap" rel="stylesheet" />
  <link rel="stylesheet" href="../public/assets/css/styles.css?v=<?= @filemtime(__DIR__ . '/../public/assets/css/styles.css') ?: 1 ?>" />
  <script src="../public/assets/js/helpers.js?v=<?= @filemtime(__DIR__ . '/../public/assets/js/helpers.js') ?: 1 ?>"></script>
</head>
<body data-retry-after="<?= (int) $rateLimitRetryAfter ?>" data-retry-message="Too many signup attempts. Please wait before trying again.">
  <div class="auth-wrapper">
    <div class="auth-card">
      <h1>Create Account</h1>
      <p class="subtitle">Start tracking your expenses today</p>

      <?php if (isset($_SESSION['auth_error'])): ?>
        <div class="alert alert-error"><?= htmlspecialchars($_SESSION['auth_error']); unset($_SESSION['auth_error']); ?></div>
      <?php endif; ?>
      <?php if (isset($_SESSION['auth_success'])): ?>
        <div class="alert alert-success"><?= htmlspecialchars($_SESSION['auth_success']); unset($_SESSION['auth_success']); ?></div>
      <?php endif; ?>

      <form action="../api/signup.php" method="POST" id="signupForm">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($signupCsrfToken, ENT_QUOTES, 'UTF-8') ?>">
        <div class="form-group">
          <label for="username">Username</label>
          <input type="text" id="username" name="username" class="form-input"
                 placeholder="e.g. johndoe" required minlength="3" maxlength="50"
                 value="<?= htmlspecialchars($_SESSION['old_username'] ?? ''); unset($_SESSION['old_username']); ?>" />
        </div>

        <div class="form-group">
          <label for="email">Email</label>
          <input type="email" id="email" name="email" class="form-input"
                 placeholder="you@example.com" required maxlength="100"
                 value="<?= htmlspecialchars($_SESSION['old_email'] ?? ''); unset($_SESSION['old_email']); ?>" />
        </div>

        <div class="form-group">
          <label for="password">Password</label>
          <input type="password" id="password" name="password" class="form-input"
                 placeholder="Min 8 characters" required minlength="8" />
        </div>

        <div class="form-group">
          <label for="confirm_password">Confirm Password</label>
          <input type="password" id="confirm_password" name="confirm_password" class="form-input"
                 placeholder="Re-enter password" required minlength="8" />
        </div>

        <button type="submit" class="btn w-full mt-1">Sign Up</button>
      </form>

      <p class="text-center mt-2" style="font-size:0.9rem; color:var(--text-secondary);">
        Already have an account? <a href="login.php">Sign In</a>
      </p>
    </div>
  </div>

  <script src="../public/assets/js/auth-page.js"></script>
</body>
</html>
