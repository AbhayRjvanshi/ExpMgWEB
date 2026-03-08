<?php
/**
 * pages/notifications.php — Notification history page (last 7 days).
 * Included by public/index.php when page=notifications.
 */
?>

<div class="card mb-2">
  <div class="flex justify-between items-center mb-1">
    <h2 style="font-size:1.15rem; font-weight:700; color:#000;">Notification History</h2>
    <span style="font-size:0.78rem; color:#888;">Last 7 days</span>
  </div>
  <div id="notifHistoryList">
    <p style="text-align:center;color:var(--celadon);padding:1.5rem;font-size:0.85rem;">Loading…</p>
  </div>
  <p id="notifHistoryEmpty" class="hidden" style="text-align:center;color:#666;font-size:0.9rem;padding:2rem 1rem;">
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
    color: #888;
    text-transform: uppercase;
    letter-spacing: 0.03em;
    padding-bottom: 0.4rem;
    border-bottom: 1px solid #eee;
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
  .nh-item:hover { background: rgba(0,0,0,0.03); }
  .nh-icon {
    flex-shrink: 0;
    width: 34px; height: 34px;
    border-radius: 50%;
    background: rgba(82,183,136,0.12);
    display: flex; align-items: center; justify-content: center;
    font-size: 1rem;
  }
  .nh-body { flex: 1; min-width: 0; }
  .nh-msg {
    font-size: 0.85rem;
    line-height: 1.4;
    color: #111;
  }
  .nh-meta {
    font-size: 0.72rem;
    color: #888;
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
    background: rgba(82,183,136,0.12);
    color: var(--sea-green);
  }
  .nh-item.nh-read { opacity: 0.55; }
</style>

<script>
(function() {
  'use strict';
  const API = '../api';

  function esc(s) { const d = document.createElement('div'); d.textContent = s; return d.innerHTML; }

  function notifIcon(type) {
    switch (type) {
      case 'group_join':           return '👤';
      case 'group_leave':          return '🚪';
      case 'group_delete':         return '🗑️';
      case 'group_expense_add':    return '💰';
      case 'group_expense_update': return '✏️';
      case 'group_expense_delete': return '🗑️';
      case 'list_item_add':        return '📝';
      case 'list_item_remove':     return '❌';
      case 'list_item_check':      return '✅';
      case 'settlement':           return '🤝';
      default:                     return '🔔';
    }
  }

  function typeLabel(type) {
    switch (type) {
      case 'group_join':           return 'Group';
      case 'group_leave':          return 'Group';
      case 'group_delete':         return 'Group';
      case 'group_expense_add':    return 'Expense';
      case 'group_expense_update': return 'Expense';
      case 'group_expense_delete': return 'Expense';
      case 'list_item_add':        return 'List';
      case 'list_item_remove':     return 'List';
      case 'list_item_check':      return 'List';
      case 'settlement':           return 'Settlement';
      default:                     return 'Notification';
    }
  }

  function formatDate(dateStr) {
    const d = new Date(dateStr.replace(' ', 'T'));
    const today = new Date();
    const yesterday = new Date();
    yesterday.setDate(today.getDate() - 1);

    const dStr = d.toISOString().slice(0,10);
    const tStr = today.toISOString().slice(0,10);
    const yStr = yesterday.toISOString().slice(0,10);

    if (dStr === tStr) return 'Today';
    if (dStr === yStr) return 'Yesterday';
    return d.toLocaleDateString('en-US', { weekday: 'short', month: 'short', day: 'numeric' });
  }

  function formatTime(dateStr) {
    const d = new Date(dateStr.replace(' ', 'T'));
    return d.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit', hour12: true });
  }

  async function loadHistory() {
    const listEl = document.getElementById('notifHistoryList');
    const emptyEl = document.getElementById('notifHistoryEmpty');

    try {
      const res = await (await fetch(`${API}/notifications/history.php?limit=100`)).json();
      if (!res.ok || res.notifications.length === 0) {
        listEl.innerHTML = '';
        emptyEl.classList.remove('hidden');
        return;
      }

      emptyEl.classList.add('hidden');

      // Group by date
      const groups = {};
      res.notifications.forEach(n => {
        const dateKey = n.created_at.split(' ')[0];
        if (!groups[dateKey]) groups[dateKey] = [];
        groups[dateKey].push(n);
      });

      let html = '';
      for (const [dateKey, notifs] of Object.entries(groups)) {
        const label = formatDate(notifs[0].created_at);
        html += `<div class="nh-date-group">
          <div class="nh-date-label">${esc(label)} &mdash; ${esc(dateKey)}</div>`;
        notifs.forEach(n => {
          const readCls = n.is_read ? 'nh-read' : '';
          html += `
            <div class="nh-item ${readCls}">
              <div class="nh-icon">${notifIcon(n.type)}</div>
              <div class="nh-body">
                <div class="nh-msg">${esc(n.message)}</div>
                <div class="nh-meta">
                  <span>${formatTime(n.created_at)}</span>
                  <span class="nh-type-badge">${typeLabel(n.type)}</span>
                </div>
              </div>
            </div>`;
        });
        html += '</div>';
      }

      listEl.innerHTML = html;

    } catch (_) {
      listEl.innerHTML = '';
      emptyEl.classList.remove('hidden');
    }
  }

  loadHistory();
})();
</script>
