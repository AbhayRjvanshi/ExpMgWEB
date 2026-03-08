// ============================================================
// app.js — Expense Manager Frontend
// ============================================================

(function () {
  'use strict';

  // ------ Globals ------
  const API = '../api';
  let currentYear, currentMonth; // 0-indexed month
  let selectedDate = null;       // 'YYYY-MM-DD'
  let categories = [];
  let userGroups = [];
  let monthExpenseDates = new Set(); // dates that have expenses (for dots)

  // ------ Helpers ------
  function $(sel) { return document.querySelector(sel); }
  function $$(sel) { return document.querySelectorAll(sel); }
  function show(el) { el && el.classList.remove('hidden'); }
  function hide(el) { el && el.classList.add('hidden'); }
  function pad(n) { return String(n).padStart(2, '0'); }

  async function post(url, data) {
    const fd = new FormData();
    for (const [k, v] of Object.entries(data)) {
      if (v !== null && v !== undefined) fd.append(k, v);
    }
    const res = await fetch(url, { method: 'POST', body: fd });
    return res.json();
  }
  async function get(url) {
    const res = await fetch(url);
    return res.json();
  }

  // ------ Init ------
  document.addEventListener('DOMContentLoaded', async () => {
    // Only run on the home page (check if calendar exists)
    if (!$('#calendarGrid')) return;

    const now = new Date();
    currentYear = now.getFullYear();
    currentMonth = now.getMonth();

    // Load categories + user groups in parallel
    const [catRes, grpRes] = await Promise.all([
      get(`${API}/expenses/categories.php`),
      get(`${API}/groups/user_groups.php`)
    ]);
    if (catRes.ok) categories = catRes.categories;
    if (grpRes.ok) userGroups = grpRes.groups;

    populateCategoryDropdown();
    populateGroupDropdown();

    renderCalendar();
    bindEvents();
  });

  // ------ Calendar Rendering ------
  async function renderCalendar() {
    const title = $('#calendarTitle');
    const grid = $('#calendarGrid');
    if (!title || !grid) return;

    const monthNames = ['January','February','March','April','May','June',
                        'July','August','September','October','November','December'];
    title.textContent = `${monthNames[currentMonth]} ${currentYear}`;

    // Get first day of month and total days
    const firstDay = new Date(currentYear, currentMonth, 1).getDay(); // 0=Sun
    const daysInMonth = new Date(currentYear, currentMonth + 1, 0).getDate();

    const today = new Date();
    const todayStr = `${today.getFullYear()}-${pad(today.getMonth() + 1)}-${pad(today.getDate())}`;

    // Fetch month expenses to show dots
    const monthStr = `${currentYear}-${pad(currentMonth + 1)}`;
    monthExpenseDates = new Set();
    let dateDotMap = {}; // dateStr -> { personal:bool, groupUnsettled:bool, groupSettled:bool }
    try {
      const mRes = await get(`${API}/expenses/list.php?month=${monthStr}`);
      if (mRes.ok) {
        mRes.expenses.forEach(e => {
          monthExpenseDates.add(e.expense_date);
          if (!dateDotMap[e.expense_date]) dateDotMap[e.expense_date] = { personal:false, groupUnsettled:false, groupSettled:false };
          if (e.type === 'personal') {
            dateDotMap[e.expense_date].personal = true;
          } else if (e.type === 'group') {
            if (e.settled) dateDotMap[e.expense_date].groupSettled = true;
            else dateDotMap[e.expense_date].groupUnsettled = true;
          }
        });
      }
    } catch (_) {}

    // Build grid HTML
    let html = '';
    // Empty cells before first day
    for (let i = 0; i < firstDay; i++) {
      html += '<div class="cal-day empty"></div>';
    }
    for (let d = 1; d <= daysInMonth; d++) {
      const dateStr = `${currentYear}-${pad(currentMonth + 1)}-${pad(d)}`;
      const isToday = dateStr === todayStr;
      const isSelected = dateStr === selectedDate;
      const dots = dateDotMap[dateStr];

      let cls = 'cal-day';
      if (isToday) cls += ' today';
      if (isSelected) cls += ' selected';

      let dotsHtml = '';
      if (dots) {
        dotsHtml = '<span class="dot-row">';
        if (dots.personal) dotsHtml += '<span class="dot dot-personal"></span>';
        if (dots.groupUnsettled) dotsHtml += '<span class="dot dot-group"></span>';
        if (dots.groupSettled) dotsHtml += '<span class="dot dot-settled"></span>';
        dotsHtml += '</span>';
      }

      html += `<div class="${cls}" data-date="${dateStr}">
        ${d}
        ${dotsHtml}
      </div>`;
    }
    grid.innerHTML = html;
  }

  // ------ Day Panel ------
  async function loadDayExpenses(date) {
    const list = $('#dayExpenseList');
    const empty = $('#dayEmpty');
    const panel = $('#dayPanel');
    const dateLabel = $('#dayPanelDate');
    if (!list) return;

    selectedDate = date;
    dateLabel.textContent = formatDateNice(date);
    show(panel);
    list.innerHTML = '<p style="text-align:center;color:var(--celadon);padding:1rem;">Loading…</p>';
    hide(empty);

    try {
      const res = await get(`${API}/expenses/list.php?date=${date}`);
      if (!res.ok) { list.innerHTML = ''; show(empty); return; }

      const expenses = res.expenses;
      if (expenses.length === 0) {
        list.innerHTML = '';
        show(empty);
        return;
      }

      hide(empty);
      list.innerHTML = expenses.map(e => expenseCardHTML(e)).join('');

      // Bind edit/delete buttons
      list.querySelectorAll('.btn-edit').forEach(btn => {
        btn.addEventListener('click', () => openEditModal(btn.dataset.id, expenses));
      });
      list.querySelectorAll('.btn-del').forEach(btn => {
        btn.addEventListener('click', () => deleteExpense(btn.dataset.id));
      });

    } catch (err) {
      list.innerHTML = '';
      show(empty);
    }
  }

  function expenseCardHTML(e) {
    const badgeCls = e.type === 'group' ? 'badge-group' : 'badge-personal';
    const badgeLabel = e.type === 'group' ? (e.group_name || 'Group') : 'Personal';

    // Status dot: blue=personal, green=unsettled group, gray=settled group
    let dotCls = 'exp-status-dot exp-dot-personal'; // blue
    if (e.type === 'group') {
      dotCls = e.settled ? 'exp-status-dot exp-dot-settled' : 'exp-status-dot exp-dot-group';
    }

    const actions = (e.can_edit && !e.settled)
      ? `<div class="exp-actions">
           <button class="btn-edit" data-id="${e.id}">Edit</button>
           <button class="btn-del" data-id="${e.id}">Delete</button>
         </div>`
      : '';

    return `
      <div class="expense-item">
        <span class="${dotCls}"></span>
        <div class="exp-left">
          <div class="flex items-center gap-1">
            <span class="exp-amount">${parseFloat(e.amount).toFixed(2)}</span>
            <span class="exp-badge ${badgeCls}">${badgeLabel}</span>
          </div>
          <div class="exp-cat">${e.category_name} · by ${e.added_by}</div>
          ${e.note ? `<div class="exp-note">${escapeHTML(e.note)}</div>` : ''}
          ${actions}
        </div>
      </div>`;
  }

  // ------ Modal ------
  function populateCategoryDropdown() {
    const sel = $('#expCategory');
    if (!sel) return;
    sel.innerHTML = '<option value="">Select category…</option>';
    categories.forEach(c => {
      sel.innerHTML += `<option value="${c.id}">${escapeHTML(c.name)}</option>`;
    });
  }

  function populateGroupDropdown() {
    const sel = $('#expGroup');
    if (!sel) return;
    sel.innerHTML = '<option value="">Select group…</option>';
    userGroups.forEach(g => {
      sel.innerHTML += `<option value="${g.id}">${escapeHTML(g.name)}</option>`;
    });
  }

  function openAddModal() {
    const modal = $('#expenseModal');
    $('#modalTitle').textContent = 'Add Expense';
    $('#expId').value = '';
    $('#expDate').value = selectedDate;
    $('#expAmount').value = '';
    $('#expCategory').value = '';
    $('#expNote').value = '';
    document.querySelector('input[name="type"][value="personal"]').checked = true;
    hide($('#groupSelectWrap'));
    hide($('#expError'));
    show(modal);
    $('#expAmount').focus();
  }

  function openEditModal(id, expenses) {
    const e = expenses.find(x => x.id == id);
    if (!e) return;
    const modal = $('#expenseModal');
    $('#modalTitle').textContent = 'Edit Expense';
    $('#expId').value = e.id;
    $('#expDate').value = e.expense_date;
    $('#expAmount').value = parseFloat(e.amount);
    $('#expCategory').value = e.category_id;
    $('#expNote').value = e.note || '';
    document.querySelector(`input[name="type"][value="${e.type}"]`).checked = true;

    if (e.type === 'group') {
      show($('#groupSelectWrap'));
      $('#expGroup').value = e.group_id || '';
    } else {
      hide($('#groupSelectWrap'));
    }
    hide($('#expError'));
    show(modal);
  }

  function closeModal() {
    hide($('#expenseModal'));
  }

  // ------ CRUD Actions ------
  async function saveExpense(e) {
    e.preventDefault();
    const errEl = $('#expError');
    hide(errEl);

    const id = $('#expId').value;
    const data = {
      amount:      $('#expAmount').value,
      category_id: $('#expCategory').value,
      note:        $('#expNote').value,
      expense_date:$('#expDate').value,
      type:        document.querySelector('input[name="type"]:checked').value,
      group_id:    document.querySelector('input[name="type"]:checked').value === 'group' ? $('#expGroup').value : ''
    };

    if (id) data.id = id;

    const url = id ? `${API}/expenses/update.php` : `${API}/expenses/create.php`;

    try {
      const res = await post(url, data);
      if (res.ok) {
        closeModal();
        await renderCalendar();
        if (selectedDate) await loadDayExpenses(selectedDate);
      } else {
        errEl.textContent = res.error || 'Failed to save.';
        show(errEl);
      }
    } catch (err) {
      errEl.textContent = 'Network error.';
      show(errEl);
    }
  }

  async function deleteExpense(id) {
    if (!confirm('Delete this expense?')) return;
    try {
      const res = await post(`${API}/expenses/delete.php`, { id });
      if (res.ok) {
        await renderCalendar();
        if (selectedDate) await loadDayExpenses(selectedDate);
      } else {
        alert(res.error || 'Failed to delete.');
      }
    } catch (_) {
      alert('Network error.');
    }
  }

  // ------ Event Binding ------
  function bindEvents() {
    // Calendar day click
    const grid = $('#calendarGrid');
    if (grid) {
      grid.addEventListener('click', (e) => {
        const day = e.target.closest('.cal-day:not(.empty)');
        if (!day) return;
        // Deselect previous
        grid.querySelectorAll('.cal-day.selected').forEach(d => d.classList.remove('selected'));
        day.classList.add('selected');
        loadDayExpenses(day.dataset.date);
      });
    }

    // Prev / Next month
    const prev = $('#prevMonth');
    const next = $('#nextMonth');
    if (prev) prev.addEventListener('click', () => {
      currentMonth--;
      if (currentMonth < 0) { currentMonth = 11; currentYear--; }
      selectedDate = null;
      hide($('#dayPanel'));
      renderCalendar();
    });
    if (next) next.addEventListener('click', () => {
      currentMonth++;
      if (currentMonth > 11) { currentMonth = 0; currentYear++; }
      selectedDate = null;
      hide($('#dayPanel'));
      renderCalendar();
    });

    // Add Expense button
    const addBtn = $('#btnAddExpense');
    if (addBtn) addBtn.addEventListener('click', openAddModal);

    // Modal close / cancel
    const closeBtn = $('#modalClose');
    const cancelBtn = $('#btnCancelExpense');
    if (closeBtn) closeBtn.addEventListener('click', closeModal);
    if (cancelBtn) cancelBtn.addEventListener('click', closeModal);

    // Click outside modal
    const overlay = $('#expenseModal');
    if (overlay) overlay.addEventListener('click', (e) => {
      if (e.target === overlay) closeModal();
    });

    // Expense type toggle (show/hide group dropdown)
    $$('input[name="type"]').forEach(radio => {
      radio.addEventListener('change', () => {
        const wrap = $('#groupSelectWrap');
        if (radio.value === 'group' && radio.checked) show(wrap);
        else hide(wrap);
      });
    });

    // Form submit
    const form = $('#expenseForm');
    if (form) form.addEventListener('submit', saveExpense);
  }

  // ------ Utilities ------
  function formatDateNice(dateStr) {
    const d = new Date(dateStr + 'T00:00:00');
    return d.toLocaleDateString('en-US', { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' });
  }

  function escapeHTML(str) {
    const div = document.createElement('div');
    div.textContent = str;
    return div.innerHTML;
  }

})();

// ============================================================
// Notifications Module — bell dropdown + polling + real-time popups
// ============================================================
(function () {
  'use strict';
  const API = '../api';
  const POLL_INTERVAL = 10000; // 10 seconds

  function $(s) { return document.querySelector(s); }
  function show(el) { el && el.classList.remove('hidden'); }
  function hide(el) { el && el.classList.add('hidden'); }
  function esc(s) { const d = document.createElement('div'); d.textContent = s; return d.innerHTML; }

  async function get(url) { return (await fetch(url)).json(); }
  async function post(url, data) {
    const fd = new FormData();
    for (const [k,v] of Object.entries(data)) fd.append(k, v);
    return (await fetch(url, { method:'POST', body:fd })).json();
  }

  const bell     = $('#notifBell');
  const badge    = $('#notifBadge');
  const panel    = $('#notifPanel');
  const list     = $('#notifList');
  const empty    = $('#notifEmpty');
  const markAll  = $('#notifMarkAll');
  const wrapper  = $('#notifWrapper');
  const toast      = $('#notifToast');
  const toastIcon  = toast ? toast.querySelector('.notif-toast-icon') : null;
  const toastMsg   = toast ? toast.querySelector('.notif-toast-msg') : null;
  const toastTime  = toast ? toast.querySelector('.notif-toast-time') : null;

  if (!bell) return; // not on the logged-in shell

  let panelOpen = false;
  let lastSeenNotifId = 0;
  let firstPoll = true;
  let toastTimer = null;

  const bellLined = bell.querySelector('.bell-lined');
  const bellSolid = bell.querySelector('.bell-solid');

  function setBellIcon(solid) {
    if (solid) {
      bellLined && bellLined.classList.add('hidden');
      bellSolid && bellSolid.classList.remove('hidden');
    } else {
      bellSolid && bellSolid.classList.add('hidden');
      bellLined && bellLined.classList.remove('hidden');
    }
  }

  // ---- Toggle panel ----
  bell.addEventListener('click', (e) => {
    e.stopPropagation();
    panelOpen = !panelOpen;
    setBellIcon(panelOpen);
    if (panelOpen) { show(panel); loadNotifications(); }
    else { hide(panel); }
  });

  // Close when clicking outside
  document.addEventListener('click', (e) => {
    if (panelOpen && wrapper && !wrapper.contains(e.target)) {
      panelOpen = false;
      setBellIcon(false);
      hide(panel);
    }
  });

  // ---- Load notifications ----
  async function loadNotifications() {
    list.innerHTML = '<p style="text-align:center;color:var(--celadon);padding:1rem;font-size:0.85rem;">Loading…</p>';
    hide(empty);

    try {
      const res = await get(`${API}/notifications/list.php?limit=30`);
      if (!res.ok) { list.innerHTML = ''; show(empty); return; }

      updateBadge(res.unread_count);

      if (res.notifications.length === 0) {
        list.innerHTML = '';
        show(empty);
        return;
      }

      hide(empty);
      list.innerHTML = res.notifications.map(n => notifHTML(n)).join('');

      // Click a notification to mark as read
      list.querySelectorAll('.notif-item[data-id]').forEach(item => {
        item.addEventListener('click', async () => {
          if (item.classList.contains('notif-read')) return;
          await post(`${API}/notifications/read.php`, { id: item.dataset.id });
          item.classList.add('notif-read');
          const dot = item.querySelector('.notif-dot');
          if (dot) dot.remove();
          pollUnreadCount();
        });
      });
    } catch (_) {
      list.innerHTML = '';
      show(empty);
    }
  }

  function notifHTML(n) {
    const time = timeAgo(n.created_at);
    const readCls = n.is_read ? 'notif-read' : '';
    const icon = notifIcon(n.type);
    return `
      <div class="notif-item ${readCls}" data-id="${n.id}">
        <div class="notif-icon">${icon}</div>
        <div class="notif-body">
          <div class="notif-msg">${esc(n.message)}</div>
          <div class="notif-time">${time}</div>
        </div>
        ${!n.is_read ? '<span class="notif-dot"></span>' : ''}
      </div>`;
  }

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

  function timeAgo(dateStr) {
    const now = new Date();
    const then = new Date(dateStr.replace(' ', 'T'));
    const diff = Math.floor((now - then) / 1000);
    if (diff < 60)    return 'just now';
    if (diff < 3600)  return Math.floor(diff / 60) + 'm ago';
    if (diff < 86400) return Math.floor(diff / 3600) + 'h ago';
    return Math.floor(diff / 86400) + 'd ago';
  }

  // ---- Mark all read ----
  markAll.addEventListener('click', async (e) => {
    e.stopPropagation();
    await post(`${API}/notifications/read.php`, { all: '1' });
    list.querySelectorAll('.notif-item').forEach(el => el.classList.add('notif-read'));
    list.querySelectorAll('.notif-dot').forEach(el => el.remove());
    updateBadge(0);
  });

  // ---- Badge update ----
  function updateBadge(count) {
    if (count > 0) {
      badge.textContent = count > 99 ? '99+' : count;
      show(badge);
    } else {
      hide(badge);
    }
  }

  // ---- Real-time Toast Popup ----
  function showNotifPopup(notif) {
    if (!toast) return;
    if (toastIcon) toastIcon.textContent = notifIcon(notif.type);
    if (toastMsg)  toastMsg.textContent  = notif.message;
    if (toastTime) toastTime.textContent = timeAgo(notif.created_at);

    // Clear any pending hide
    if (toastTimer) { clearTimeout(toastTimer); toastTimer = null; }

    toast.classList.add('show');
    playNotifSound();

    toastTimer = setTimeout(() => {
      toast.classList.remove('show');
      toastTimer = null;
    }, 4000);
  }

  // ---- Notification Sound (Web Audio API) ----
  function playNotifSound() {
    try {
      const ctx = new (window.AudioContext || window.webkitAudioContext)();
      const osc = ctx.createOscillator();
      const gain = ctx.createGain();
      osc.connect(gain);
      gain.connect(ctx.destination);
      osc.type = 'sine';
      osc.frequency.setValueAtTime(880, ctx.currentTime);
      osc.frequency.setValueAtTime(660, ctx.currentTime + 0.15);
      gain.gain.setValueAtTime(0.2, ctx.currentTime);
      gain.gain.exponentialRampToValueAtTime(0.001, ctx.currentTime + 0.4);
      osc.start(ctx.currentTime);
      osc.stop(ctx.currentTime + 0.4);
    } catch (_) {}
  }

  // ---- Polling ----
  async function pollUnreadCount() {
    try {
      const res = await get(`${API}/notifications/count.php`);
      if (res.ok) {
        updateBadge(res.count);
        // Detect new notification for popup
        if (res.latest && res.latest.id > lastSeenNotifId) {
          if (!firstPoll) {
            showNotifPopup(res.latest);
          }
          lastSeenNotifId = res.latest.id;
        }
        firstPoll = false;
      }
    } catch (_) {}
  }

  // Initial poll + interval
  pollUnreadCount();
  setInterval(pollUnreadCount, POLL_INTERVAL);

})();

// ============================================================
// Profile Dropdown Module
// ============================================================
(function () {
  'use strict';

  function $(s) { return document.querySelector(s); }

  const btn     = $('#profileBtn');
  const panel   = $('#profilePanel');
  const wrapper = $('#profileWrapper');
  if (!btn) return;

  const lined = btn.querySelector('.profile-lined');
  const solid = btn.querySelector('.profile-solid');
  let open = false;

  function setIcon(active) {
    if (active) {
      lined && lined.classList.add('hidden');
      solid && solid.classList.remove('hidden');
    } else {
      solid && solid.classList.add('hidden');
      lined && lined.classList.remove('hidden');
    }
  }

  btn.addEventListener('click', (e) => {
    e.stopPropagation();
    open = !open;
    setIcon(open);
    panel.classList.toggle('hidden', !open);
  });

  document.addEventListener('click', (e) => {
    if (open && wrapper && !wrapper.contains(e.target)) {
      open = false;
      setIcon(false);
      panel.classList.add('hidden');
    }
  });
})();
