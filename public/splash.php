<?php
require_once __DIR__ . '/../config/session.php';
configureSecureSessionCookie();
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
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500;600;700&family=Plus+Jakarta+Sans:wght@400;600;700;800&display=swap" rel="stylesheet" />
  <!-- Full token system so the persisted palette/dark-mode resolve cleanly -->
  <link rel="stylesheet" href="assets/css/styles.css" />
  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

    body {
      font-family: var(--font-sans);
      background: var(--bg-canvas);
      color: var(--text-primary);
      min-height: 100vh;
      display: flex;
      align-items: center;
      justify-content: center;
      overflow: hidden;
      /* Architectural dot-matrix pattern over the canvas */
      background-image: radial-gradient(var(--border-medium) 1px, transparent 1px);
      background-size: 20px 20px;
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

    /* ---------- Center Emblem ---------- */
    .splash-logo {
      width: 82px;
      height: 82px;
      border-radius: 22px;
      background: var(--accent-soft);
      border: 1.5px solid var(--accent-border);
      color: var(--accent-primary);
      display: flex;
      align-items: center;
      justify-content: center;
      box-shadow: var(--shadow-card);
    }
    .splash-logo svg { width: 44px; height: 44px; }

    /* ---------- Typography ---------- */
    .splash-title {
      font-family: var(--font-display);
      font-size: 28px;
      font-weight: 800;
      letter-spacing: -0.03em;
      color: var(--text-primary);
    }
    .splash-tagline {
      font-size: 14px;
      font-weight: 400;
      color: var(--text-secondary);
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
<script src="assets/js/theme-init.js"></script>
  <div class="splash" id="splashScreen" data-redirect='<?= htmlspecialchars(json_encode($redirect), ENT_QUOTES, "UTF-8") ?>'>
    <!-- Logo: bar-chart with dollar coin — mirrors the reference image -->
    <div class="splash-logo">
      <svg viewBox="0 0 64 64" fill="none" xmlns="http://www.w3.org/2000/svg">
        <!-- Chart bars -->
        <rect x="8"  y="36" width="8" height="20" rx="2" fill="currentColor" opacity="0.45"/>
        <rect x="20" y="26" width="8" height="30" rx="2" fill="currentColor" opacity="0.7"/>
        <rect x="32" y="18" width="8" height="38" rx="2" fill="currentColor"/>
        <rect x="44" y="28" width="8" height="28" rx="2" fill="currentColor" opacity="0.7"/>
        <!-- Dollar coin -->
        <circle cx="48" cy="16" r="11" fill="none" stroke="currentColor" stroke-width="2"/>
        <text x="48" y="21" text-anchor="middle" font-size="14" font-weight="700"
              font-family="Inter, sans-serif" fill="currentColor">$</text>
      </svg>
    </div>

    <span class="splash-title">Expense Manager</span>
    <span class="splash-tagline">Track your expenses with us.</span>
  </div>

  <?php
    // Mark splash as seen so index.php / login.php won't loop back
    $_SESSION['splash_seen'] = true;
  ?>
  <script src="assets/js/splash.js"></script>

</body>
</html>
