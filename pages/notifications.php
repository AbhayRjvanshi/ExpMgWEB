<?php
// M1 (PENTEST/moderately sever/M1.md): include-only fragment. Direct hits
// leak absolute paths via PHP warnings -- reject them at the door.
if (!defined('EXPMG_PAGE')) { http_response_code(404); exit; }
/**
 * pages/notifications.php — Notification history page (last 7 days).
 * Included by public/index.php when page=notifications.
 */
?>

<div class="card mb-2">
  <div class="flex justify-between items-center mb-1">
    <h2 style="font-size:1.15rem; font-weight:700; color:var(--text-primary);">Notification History</h2>
    <span style="font-size:0.78rem; color:var(--text-muted);">Last 7 days</span>
  </div>
  <div id="notifHistoryList">
    <p style="text-align:center;color:var(--text-muted);padding:1.5rem;font-size:0.85rem;">Loading…</p>
  </div>
  <p id="notifHistoryEmpty" class="hidden" style="text-align:center;color:var(--text-muted);font-size:0.9rem;padding:2rem 1rem;">
    No notifications in the last 7 days.
  </p>
</div>

<style>
  .nh-date-group {
    margin-top: 1rem;
  }
  .nh-date-group:first-child { margin-top: 0; }
  .nh-date-label {
    font-size: 0.78rem;
    font-weight: 600;
    color: var(--text-muted);
    text-transform: uppercase;
    letter-spacing: 0.03em;
    padding-bottom: 0.4rem;
    border-bottom: 1px solid var(--border-soft);
    margin-bottom: 0.5rem;
  }
  .nh-item {
    display: flex;
    align-items: flex-start;
    gap: 0.65rem;
    padding: 0.7rem 0.5rem;
    border-radius: 0.6rem;
    transition: background 0.15s;
  }
  .nh-item:hover { background: var(--bg-subtle); }
  .nh-icon {
    flex-shrink: 0;
    width: 34px; height: 34px;
    border-radius: 50%;
    background: var(--accent-soft);
    display: flex; align-items: center; justify-content: center;
    font-size: 1rem;
  }
  .nh-body { flex: 1; min-width: 0; }
  .nh-msg {
    font-size: 0.85rem;
    line-height: 1.4;
    color: var(--text-primary);
  }
  .nh-meta {
    font-size: 0.72rem;
    color: var(--text-muted);
    margin-top: 0.15rem;
    display: flex;
    align-items: center;
    gap: 0.5rem;
  }
  .nh-type-badge {
    font-size: 0.65rem;
    padding: 0.1rem 0.4rem;
    border-radius: 999px;
    font-weight: 600;
    background: var(--accent-soft);
    color: var(--accent-primary);
  }
  .nh-item.nh-read { opacity: 0.55; }
</style>

<script src="../public/assets/js/notifications.js"></script>
