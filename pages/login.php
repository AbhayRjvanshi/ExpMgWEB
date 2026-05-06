<?php
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
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Sign In — Expense Manager</title>
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet" />
  <link rel="stylesheet" href="../public/assets/css/styles.css" />
  <script src="../public/assets/js/helpers.js?v=<?= time() ?>"></script>
</head>
<body>
  <div class="auth-wrapper">
    <div class="auth-card">
      <h1>Welcome Back</h1>
      <p class="subtitle">Sign in to your expense dashboard</p>

      <?php if (isset($_SESSION['auth_error'])): ?>
        <div class="alert alert-error"><?= htmlspecialchars($_SESSION['auth_error']); unset($_SESSION['auth_error']); ?></div>
      <?php endif; ?>
      <?php if (isset($_SESSION['auth_success'])): ?>
        <div class="alert alert-success"><?= htmlspecialchars($_SESSION['auth_success']); unset($_SESSION['auth_success']); ?></div>
      <?php endif; ?>

      <form action="../api/login.php" method="POST">
        <div class="form-group">
          <label for="email">Email</label>
          <input type="email" id="email" name="email" class="form-input"
                 placeholder="you@example.com" required maxlength="100"
                 value="<?= htmlspecialchars($_SESSION['old_email'] ?? ''); unset($_SESSION['old_email']); ?>" />
        </div>

        <div class="form-group">
          <label for="password">Password</label>
          <input type="password" id="password" name="password" class="form-input"
                 placeholder="Your password" required />
        </div>

        <button type="submit" class="btn w-full mt-1">Sign In</button>
      </form>

      <p class="text-center mt-2" style="font-size:0.9rem; color:var(--celadon);">
        Don't have an account? <a href="signup.php">Sign Up</a>
      </p>
    </div>
  </div>

  <script>
    (function () {
      var retryAfter = <?= (int) $rateLimitRetryAfter ?>;
      if (retryAfter > 0 && window.ExpMgStatus && typeof window.ExpMgStatus.showCooldown === 'function') {
        window.ExpMgStatus.showCooldown(retryAfter, 'Too many login attempts. Please wait before trying again.');
      }
    })();
  </script>
</body>
</html>
