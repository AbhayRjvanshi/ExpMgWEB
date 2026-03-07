<?php
session_start();
$loggedIn = isset($_SESSION['user_id']);
$redirect = $loggedIn ? 'index.php' : '../pages/login.php';
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Expense Manager</title>
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet" />
  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

    body {
      font-family: 'Inter', 'Segoe UI', system-ui, -apple-system, sans-serif;
      background: linear-gradient(135deg, #081c15, #1b4332, #2d6a4f);
      color: #f0fdf4;
      min-height: 100vh;
      display: flex;
      align-items: center;
      justify-content: center;
      overflow: hidden;
    }

    /* ---------- Splash Container ---------- */
    .splash {
      display: flex;
      flex-direction: column;
      align-items: center;
      gap: 1.25rem;
      opacity: 0;
      transform: translateY(18px);
      animation: fadeInUp 0.8s ease forwards;
    }

    /* ---------- Logo ---------- */
    .splash-logo {
      width: 120px;
      height: 120px;
      border-radius: 50%;
      background: linear-gradient(135deg, #2d6a4f, #40916c);
      display: flex;
      align-items: center;
      justify-content: center;
      box-shadow: 0 15px 35px rgba(0, 0, 0, 0.30),
                  0 0 60px rgba(82, 183, 136, 0.15);
    }
    .splash-logo svg { width: 64px; height: 64px; }

    /* ---------- Typography ---------- */
    .splash-title {
      font-size: 1.75rem;
      font-weight: 700;
      letter-spacing: -0.02em;
      color: #f0fdf4;
    }
    .splash-tagline {
      font-size: 0.95rem;
      font-weight: 400;
      color: #95d5b2;
      margin-top: -0.5rem;
    }

    /* ---------- Fade-out before redirect ---------- */
    .splash.fade-out {
      animation: fadeOut 0.6s ease forwards;
    }

    /* ---------- Keyframes ---------- */
    @keyframes fadeInUp {
      to { opacity: 1; transform: translateY(0); }
    }
    @keyframes fadeOut {
      from { opacity: 1; transform: translateY(0); }
      to   { opacity: 0; transform: translateY(-12px); }
    }
  </style>
</head>
<body>

  <div class="splash" id="splashScreen">
    <!-- Logo: bar-chart with dollar coin — mirrors the reference image -->
    <div class="splash-logo">
      <svg viewBox="0 0 64 64" fill="none" xmlns="http://www.w3.org/2000/svg">
        <!-- Chart bars -->
        <rect x="8"  y="36" width="8" height="20" rx="2" fill="#b7e4c7"/>
        <rect x="20" y="26" width="8" height="30" rx="2" fill="#d8f3dc"/>
        <rect x="32" y="18" width="8" height="38" rx="2" fill="#b7e4c7"/>
        <rect x="44" y="28" width="8" height="28" rx="2" fill="#d8f3dc"/>
        <!-- Dollar coin -->
        <circle cx="48" cy="16" r="11" fill="#d8f3dc" stroke="#081c15" stroke-width="2"/>
        <text x="48" y="21" text-anchor="middle" font-size="14" font-weight="700"
              font-family="Inter, sans-serif" fill="#081c15">$</text>
      </svg>
    </div>

    <span class="splash-title">Expense Manager</span>
    <span class="splash-tagline">Track your expenses with us.</span>
  </div>

  <?php
    // Mark splash as seen so index.php / login.php won't loop back
    $_SESSION['splash_seen'] = true;
  ?>
  <script>
    (function () {
      var redirect = <?= json_encode($redirect) ?>;
      // After 3.4 s start fade-out (0.6 s), total ≈ 4 s then redirect
      setTimeout(function () {
        document.getElementById('splashScreen').classList.add('fade-out');
        setTimeout(function () {
          window.location.href = redirect;
        }, 600);
      }, 3400);
    })();
  </script>

</body>
</html>
