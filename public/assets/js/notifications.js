(function() {
  'use strict';
  const esc = escapeHTML; // alias for brevity

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
      const res = await get(`${API}/notifications/history.php?limit=100`);
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
