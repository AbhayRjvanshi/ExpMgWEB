<?php
session_start();
require_once __DIR__ . '/../api/helpers/csrf.php';

// Show splash screen on fresh visit
if (empty($_SESSION['splash_seen'])) {
    header('Location: splash.php');
    exit;
}

// If not logged in, redirect to login page
if (!isset($_SESSION['user_id'])) {
    header('Location: ../pages/login.php');
    exit;
}

$username = htmlspecialchars($_SESSION['username']);
$csrfToken = getCsrfToken();
$page     = $_GET['page'] ?? 'home';
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Expense Manager</title>
  <meta name="csrf-token" content="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>" />
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500;600;700&family=Plus+Jakarta+Sans:wght@400;600;700;800&display=swap" rel="stylesheet" />
  <?php if ($page === 'expenses'): ?>
  <!-- Chart.js (only on the Expenses tab — charts live there; synchronous so
       the page's inline chart scripts can use it immediately) -->
  <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1"></script>
  <?php endif; ?>
  <!-- jsPDF + autoTable (deferred — only needed when a PDF button is clicked;
       defer executes before DOMContentLoaded, so click handlers find them) -->
  <script defer src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
  <script defer src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.8.2/jspdf.plugin.autotable.min.js"></script>
  <link rel="stylesheet" href="assets/css/styles.css?v=<?= @filemtime(__DIR__ . '/assets/css/styles.css') ?: 1 ?>" />
  <script src="assets/js/helpers.js?v=<?= @filemtime(__DIR__ . '/assets/js/helpers.js') ?: 1 ?>"></script>
</head>
<body>
<script>
  /* Theme bootstrap — runs before paint (body exists here) so palette and
     dark/light mode never flash. localStorage keys: expmg_palette,
     expmg_theme_mode. Default palette is Botanical Emerald (green). */
  (function () {
    try {
      var p = localStorage.getItem('expmg_palette');
      if (p === 'indigo' || p === 'sand' || p === 'cobalt') {
        document.body.setAttribute('data-palette', p);
      }
      if (localStorage.getItem('expmg_theme_mode') === 'dark') {
        document.body.classList.add('theme-dark');
      }
    } catch (_) { /* localStorage unavailable */ }
  })();
</script>
  <div class="page-shell">

    <!-- Top header (sticky, frosted) -->
    <header class="app-header">
      <!-- Brand cluster -->
      <div class="brand-wrap">
        <span class="brand-icon">
          <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <rect x="2" y="4" width="20" height="16" rx="2"/><path d="M2 10h20"/><path d="M16 14h2"/>
          </svg>
        </span>
        <span class="brand-name">ExpMg</span>
      </div>

      <div class="header-actions">
        <!-- Dark / Light mode toggle (placed before the notifications bell) -->
        <button id="btnThemeModeToggle" title="Switch to dark mode" class="header-icon-btn" aria-label="Toggle dark / light mode">
          <svg class="icon-moon" xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/></svg>
          <svg class="icon-sun hidden" xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="4"/><path d="M12 2v2"/><path d="M12 20v2"/><path d="m4.93 4.93 1.41 1.41"/><path d="m17.66 17.66 1.41 1.41"/><path d="M2 12h2"/><path d="M20 12h2"/><path d="m6.34 17.66-1.41 1.41"/><path d="m19.07 4.93-1.41 1.41"/></svg>
        </button>
        <!-- Notification bell + popover -->
        <div id="notifWrapper" style="position:relative;">
          <button id="btnNotifToggle" title="Notifications" class="header-icon-btn">
            <!-- Lined bell (default) -->
            <svg class="bell-lined" xmlns="http://www.w3.org/2000/svg" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>
            <!-- Solid bell (active) -->
            <svg class="bell-solid hidden" xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="currentColor" stroke="currentColor" stroke-width="1" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0" fill="none" stroke="currentColor" stroke-width="2"/></svg>
            <span id="notifBadge" class="hidden"></span>
          </button>

          <!-- Notifications popover -->
          <div id="notifPanel" class="notif-popover hidden">
            <div class="popover-head">
              <span class="popover-title">Notifications</span>
              <button id="notifMarkAll" class="popover-link">Mark all read</button>
            </div>
            <div id="notifList" class="popover-body"></div>
            <p id="notifEmpty" class="hidden" style="text-align:center;color:var(--text-muted);font-size:0.85rem;padding:2rem 1rem;">
              No notifications yet.
            </p>
            <a id="notifHistoryLink" class="popover-foot" href="index.php?page=notifications">
              <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
              View Last 7 Days Notifications
            </a>
          </div>
        </div>

        <!-- Profile + Color/Theme popover -->
        <div id="profileWrapper" style="position:relative;">
          <button id="btnUserToggle" title="Profile" class="header-icon-btn">
            <!-- Lined profile (default) -->
            <svg class="profile-lined" xmlns="http://www.w3.org/2000/svg" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
            <!-- Solid profile (active) -->
            <svg class="profile-solid hidden" xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="currentColor" stroke="currentColor" stroke-width="1" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
          </button>

          <!-- User popover: name, Color / Theme expander, Logout -->
          <div id="profilePanel" class="user-popover hidden">
            <div class="user-head"><?= $username ?></div>
            <button type="button" id="btnThemePaletteToggle" class="theme-row">
              <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="13.5" cy="6.5" r=".5" fill="currentColor"/><circle cx="17.5" cy="10.5" r=".5" fill="currentColor"/><circle cx="8.5" cy="7.5" r=".5" fill="currentColor"/><circle cx="6.5" cy="12.5" r=".5" fill="currentColor"/><path d="M12 2C6.5 2 2 6.5 2 12s4.5 10 10 10c.926 0 1.648-.746 1.648-1.688 0-.437-.18-.835-.437-1.125-.29-.289-.438-.652-.438-1.125a1.64 1.64 0 0 1 1.668-1.668h1.996c3.051 0 5.555-2.503 5.555-5.554C21.965 6.012 17.461 2 12 2z"/></svg>
              <span>Color / Theme</span>
              <span id="themeSwatchPreview" class="swatch"></span>
              <svg class="chev" xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg>
            </button>
            <div id="profileThemeList" class="hidden">
              <button type="button" class="theme-opt" data-theme-palette="green"><span class="swatch" style="background:#1f6f4c;"></span>Botanical Emerald<span class="check">&#10003;</span></button>
              <button type="button" class="theme-opt" data-theme-palette="indigo"><span class="swatch" style="background:#4f46e5;"></span>Slate & Indigo<span class="check">&#10003;</span></button>
              <button type="button" class="theme-opt" data-theme-palette="sand"><span class="swatch" style="background:#c2410c;"></span>Warm Sand & Terracotta<span class="check">&#10003;</span></button>
              <button type="button" class="theme-opt" data-theme-palette="cobalt"><span class="swatch" style="background:#0284c7;"></span>Nordic Cobalt<span class="check">&#10003;</span></button>
            </div>
            <a href="../api/logout.php" class="user-logout-row">
              <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
              Logout
            </a>
          </div>
        </div>
      </div>
    </header>

    <!-- Main content area — will be swapped based on $page -->
    <main class="page-content">
      <?php
        // Simple page router
        switch ($page) {
            case 'expenses':
                include __DIR__ . '/../pages/expenses.php';
                break;
            case 'groups':
                include __DIR__ . '/../pages/groups.php';
                break;
            case 'lists':
                include __DIR__ . '/../pages/lists.php';
                break;
            case 'notifications':
                include __DIR__ . '/../pages/notifications.php';
                break;
            case 'home':
            default:
                include __DIR__ . '/../pages/home.php';
                break;
        }
      ?>
    </main>

    <!-- Bottom Navigation Bar -->
    <nav class="bottom-nav">
      <a href="index.php?page=home" class="<?= $page === 'home' ? 'active' : '' ?>">
        <svg xmlns="http://www.w3.org/2000/svg" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
        Home
      </a>
      <a href="index.php?page=expenses" class="<?= $page === 'expenses' ? 'active' : '' ?>">
        <svg xmlns="http://www.w3.org/2000/svg" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
        Expenses
      </a>
      <a href="index.php?page=groups" class="<?= $page === 'groups' ? 'active' : '' ?>">
        <svg xmlns="http://www.w3.org/2000/svg" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
        Groups
      </a>
      <a href="index.php?page=lists" class="<?= $page === 'lists' ? 'active' : '' ?>">
        <svg xmlns="http://www.w3.org/2000/svg" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/></svg>
        Lists
      </a>
    </nav>

  </div><!-- /.page-shell -->

  <!-- Notification Toast Popup -->
  <div id="notifToast" class="notif-toast">
    <div class="notif-toast-icon"></div>
    <div class="notif-toast-body">
      <div class="notif-toast-msg"></div>
      <div class="notif-toast-time"></div>
    </div>
  </div>

  <script src="assets/js/app.js?v=<?= @filemtime(__DIR__ . '/assets/js/app.js') ?: 1 ?>"></script>
</body>
</html>
