<?php
session_start();

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
$page     = $_GET['page'] ?? 'home';
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Expense Manager</title>
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet" />
  <!-- Chart.js (loaded early; used on expenses page) -->
  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
  <link rel="stylesheet" href="assets/css/styles.css" />
</head>
<body>
  <div class="page-shell">

    <!-- Top header -->
    <header class="page-header">
      <h1>Expense Manager</h1>
      <div class="flex items-center gap-1">
        <span style="font-size:0.85rem; color:var(--celadon);">Hi, <?= $username ?></span>

        <!-- Notification bell + dropdown -->
        <div id="notifWrapper" style="position:relative; margin-left:0.75rem;">
          <button id="notifBell" title="Notifications" style="background:none;border:none;color:inherit;cursor:pointer;position:relative;padding:0.25rem;">
            <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>
            <span id="notifBadge" class="hidden" style="position:absolute;top:0;right:0;min-width:16px;height:16px;border-radius:999px;background:#ef4444;color:#fff;font-size:0.65rem;font-weight:700;display:flex;align-items:center;justify-content:center;padding:0 4px;"></span>
          </button>

          <!-- Dropdown panel -->
          <div id="notifPanel" class="hidden" style="
            position:absolute; right:0; top:calc(100% + 8px); width:340px; max-height:420px;
            background:linear-gradient(135deg,#1b4332,#2d6a4f); border-radius:0.75rem;
            box-shadow:0 15px 35px rgba(0,0,0,0.35); overflow:hidden; z-index:3000;
          ">
            <div style="display:flex;justify-content:space-between;align-items:center;padding:0.75rem 1rem;border-bottom:1px solid rgba(255,255,255,0.08);">
              <span style="font-weight:600;font-size:0.95rem;">Notifications</span>
              <button id="notifMarkAll" style="background:none;border:none;color:var(--mint-leaf);font-size:0.78rem;cursor:pointer;font-weight:500;">Mark all read</button>
            </div>
            <div id="notifList" style="overflow-y:auto;max-height:360px;"></div>
            <p id="notifEmpty" class="hidden" style="text-align:center;color:var(--celadon);font-size:0.85rem;padding:2rem 1rem;">
              No notifications yet.
            </p>
          </div>
        </div>

        <a href="../api/logout.php" class="btn-outline" style="margin-left:0.5rem; padding:0.35rem 0.75rem; font-size:0.8rem; border-radius:0.4rem;">
          Logout
        </a>
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
  <script src="assets/js/app.js"></script>
</body>
</html>
