<?php
session_start();

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
  <title>Sign Up — Expense Manager</title>
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet" />
  <link rel="stylesheet" href="../public/assets/css/styles.css" />
</head>
<body>
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
                 placeholder="Min 6 characters" required minlength="6" />
        </div>

        <div class="form-group">
          <label for="confirm_password">Confirm Password</label>
          <input type="password" id="confirm_password" name="confirm_password" class="form-input"
                 placeholder="Re-enter password" required minlength="6" />
        </div>

        <button type="submit" class="btn w-full mt-1">Sign Up</button>
      </form>

      <p class="text-center mt-2" style="font-size:0.9rem; color:var(--celadon);">
        Already have an account? <a href="login.php">Sign In</a>
      </p>
    </div>
  </div>

  <script>
    // Client-side password match check
    document.getElementById('signupForm').addEventListener('submit', function(e) {
      const pw  = document.getElementById('password').value;
      const cpw = document.getElementById('confirm_password').value;
      if (pw !== cpw) {
        e.preventDefault();
        alert('Passwords do not match.');
      }
    });
  </script>
</body>
</html>
