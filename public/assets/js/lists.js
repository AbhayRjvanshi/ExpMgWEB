(function() {
  'use strict';
  const esc = escapeHTML; // alias for brevity

  // Inline SVG icon constants (replace platform emoji in UI chrome)
  const ICON_LOCK_SVG = '<svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:-2px;"><rect x="4" y="11" width="16" height="9" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/></svg>';
  const ICON_GROUP_SVG = '<svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:-2px;"><rect x="8" y="2" width="8" height="4" rx="1"/><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/></svg>';

  let categories = [];
  let userGroups = [];
  let currentListId = null;
  let currentListGroupId = null;
  let timerInterval = null;
  let listsCache = [];
  const listDetailCache = new Map();
  let listsLoading = false;
  let listDetailLoading = false;
  let itemsModal = null;         // open "View all" modal handle (list items)
  let itemsModalListId = null;   // list id the open modal was rendered for
  let currentListMeta = null;     // list metadata for the open detail view
  let cancelConfirmInFlight = false; // single-flight lock for check-confirm dismissal

  function reportIssue(result, scope, retryAction) {
    if (window.ExpMgStatus && typeof window.ExpMgStatus.setRequestError === 'function') {
      window.ExpMgStatus.setRequestError(result || { _status: 0, error: 'Request failed.' }, { scope, retryAction });
    }
  }

  function clearIssue(scope) {
    if (window.ExpMgStatus && typeof window.ExpMgStatus.clearRequestError === 'function') {
      window.ExpMgStatus.clearRequestError(scope);
    }
  }

  function renderListsCoolingDown() {
    const container = $('#listContainer');
    const empty = $('#listsEmpty');
    if (!container) return;
    const remaining = window.ExpMgStatus && typeof window.ExpMgStatus.getRateLimitRemainingSeconds === 'function'
      ? window.ExpMgStatus.getRateLimitRemainingSeconds()
      : 0;
    if (listsCache.length > 0) {
      renderListCards(container, listsCache);
    } else {
      container.innerHTML = `<p style="text-align:center;color:var(--amber-800);padding:1.5rem 0;">Too many requests. Please wait ${remaining}s.</p>`;
      hide(empty);
    }
  }

  // ---- Master-detail state helper ----
  // Every show/hide of the list detail panel routes through here so that
  // background refreshes (timer expiry, add-item success, cooldown retry)
  // never desync visibility or cause jarring scroll jumps. On initial card
  // selection (opts.scroll === true) the active summary card is highlighted
  // and the detail panel is scrolled smoothly into view.
  function setDetailView(isOpen, opts) {
    const panel = $('#listDetail');
    const wrapper = document.getElementById('listsMasterDetail');
    if (wrapper) {
      // Desktop (>=768px) master-detail split view; no-op on mobile where
      // the class is media-scoped.
      if (isOpen) wrapper.classList.add('master-detail-grid');
      else wrapper.classList.remove('master-detail-grid');
    }
    document.querySelectorAll('#listContainer .list-card.is-selected')
      .forEach(el => el.classList.remove('is-selected'));
    if (isOpen) {
      show(panel);
      if (currentListId !== null && currentListId !== undefined) {
        const activeCard = document.querySelector('#listContainer .list-card[data-id="' + currentListId + '"]');
        if (activeCard) activeCard.classList.add('is-selected');
      }
      if (opts && opts.scroll) {
        panel.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
      }
    } else {
      hide(panel);
    }
  }

  function renderListDetailCoolingDown(listId) {
    const cached = listDetailCache.get(String(listId)) || null;
    if (cached) {
      setDetailView(true);
      renderListDetail(cached);
    } else {
      setDetailView(false);
    }
  }

  window.addEventListener('expmg:cooldown-start', () => {
    if (listsLoading) renderListsCoolingDown();
    if (listDetailLoading && currentListId) renderListDetailCoolingDown(currentListId);
  });

  function retryAfterCooldown(scope, isLoading) {
    if (isLoading) return;
    if (!window.ExpMgStatus || typeof window.ExpMgStatus.getRetryAction !== 'function') return;
    const retry = window.ExpMgStatus.getRetryAction(scope);
    if (typeof retry !== 'function') return;
    try {
      retry();
    } catch (_) {
      // Keep cooldown-end handler non-fatal even if a retry callback throws.
    }
  }

  window.addEventListener('expmg:cooldown-end', () => {
    retryAfterCooldown('lists-overview', listsLoading);
    retryAfterCooldown('list-detail', listDetailLoading);
  });

  // Format seconds as M:SS
  function fmtTimer(secs) {
    if (secs <= 0) return '0:00';
    const m = Math.floor(secs / 60);
    const s = secs % 60;
    return `${m}:${String(s).padStart(2, '0')}`;
  }

  // Start live countdown for all visible timers
  function startTimers() {
    if (timerInterval) clearInterval(timerInterval);
    timerInterval = setInterval(() => {
      const timers = document.querySelectorAll('.li-timer');
      if (timers.length === 0) { clearInterval(timerInterval); timerInterval = null; return; }
      const now = Date.now();
      let needRefresh = false;
      timers.forEach(el => {
        const expires = parseInt(el.dataset.expires, 10);
        const left = Math.ceil((expires - now) / 1000);
        if (left <= 0) {
          needRefresh = true;
        } else {
          el.textContent = fmtTimer(left);
        }
      });
      if (needRefresh && currentListId) {
        // Stop this interval BEFORE reloading so a slow re-render cannot
        // stack overlapping openListDetail() calls on subsequent ticks.
        clearInterval(timerInterval);
        timerInterval = null;
        openListDetail(currentListId);
        return;
      }
    }, 1000);
  }

  // ---- Init ----
  async function init() {
    const [catRes, grpRes] = await Promise.all([
      get(`${API}/expenses/categories.php`),
      get(`${API}/groups/user_groups.php`)
    ]);
    if (catRes.ok) categories = catRes.categories;
    if (grpRes.ok) userGroups = grpRes.groups;

    // Populate dropdowns
    const catSel = $('#aiCategory');
    catSel.innerHTML = '<option value="">None</option>';
    categories.forEach(c => { catSel.innerHTML += `<option value="${c.id}">${esc(c.name)}</option>`; });

    const grpSel = $('#clGroup');
    grpSel.innerHTML = '<option value="">Select group…</option>';
    userGroups.forEach(g => { grpSel.innerHTML += `<option value="${g.id}">${esc(g.name)}</option>`; });

    loadLists();
    bindEvents();
  }

  // ---- Load all lists ----
  async function loadLists() {
    const container = $('#listContainer');
    const empty = $('#listsEmpty');
    const cooldownRemaining = window.ExpMgStatus && typeof window.ExpMgStatus.getRateLimitRemainingSeconds === 'function'
      ? window.ExpMgStatus.getRateLimitRemainingSeconds()
      : 0;

    if (cooldownRemaining > 0) {
      if (listsCache.length > 0) {
        renderListCards(container, listsCache);
      } else {
        container.innerHTML = `<p style="text-align:center;color:var(--amber-800);padding:1.5rem 0;">Too many requests. Please wait ${cooldownRemaining}s.</p>`;
        hide(empty);
      }
      reportIssue({ _status: 429, _retry_after: cooldownRemaining, error: 'Too many requests. Please wait before retrying.' }, 'lists-overview', () => loadLists());
      return;
    }

    hide(empty);
    listsLoading = true;
    container.innerHTML = '<p style="text-align:center;color:var(--text-muted);padding:1.5rem 0;">Loading…</p>';

    try {
      const res = await get(`${API}/lists/user_lists.php`);
      if (!res.ok) {
        if (listsCache.length > 0) {
          renderListCards(container, listsCache);
        } else {
          container.innerHTML = '<p style="text-align:center;color:var(--amber-800);padding:1.5rem 0;">Unable to load lists right now.</p>';
          hide(empty);
        }
        reportIssue(res, 'lists-overview', () => loadLists());
        return;
      }

      listsCache = Array.isArray(res.lists) ? res.lists : [];
      if (listsCache.length === 0) {
        container.innerHTML = '';
        show(empty);
        clearIssue('lists-overview');
        return;
      }

      renderListCards(container, listsCache);
      clearIssue('lists-overview');
    } catch (_) {
      if (listsCache.length > 0) {
        renderListCards(container, listsCache);
      } else {
        container.innerHTML = '<p style="text-align:center;color:var(--amber-800);padding:1.5rem 0;">Connection issue. Retry.</p>';
        hide(empty);
      }
      reportIssue({ _status: 0, error: 'Connection issue. Retry.' }, 'lists-overview', () => loadLists());
    } finally {
      listsLoading = false;
    }
  }

  function renderListCards(container, lists) {
    container.innerHTML = lists.map(l => {
      const pct = l.item_count > 0 ? Math.round((l.checked_count / l.item_count) * 100) : 0;
      const typeLabel = l.group_id ? `${ICON_GROUP_SVG} ${esc(l.group_name)}` : `${ICON_LOCK_SVG} Personal`;
      return `
        <div class="list-card" data-id="${l.id}">
          <div class="flex justify-between items-center">
            <span class="lc-name">${esc(l.name)}</span>
            <span class="exp-badge ${l.group_id ? 'badge-group' : 'badge-personal'}" style="font-size:0.7rem;">${typeLabel}</span>
          </div>
          <div class="lc-meta">${l.checked_count}/${l.item_count} items done · by ${esc(l.created_by)}</div>
          <div class="lc-progress"><div class="lc-progress-bar" style="width:${pct}%"></div></div>
        </div>
      `;
    }).join('');

    container.querySelectorAll('.list-card').forEach(card => {
      card.addEventListener('click', () => openListDetail(card.dataset.id, { scroll: true }));
    });
  }

  // ---- List detail ----
  async function openListDetail(listId, opts) {
    currentListId = listId;
    const cooldownRemaining = window.ExpMgStatus && typeof window.ExpMgStatus.getRateLimitRemainingSeconds === 'function'
      ? window.ExpMgStatus.getRateLimitRemainingSeconds()
      : 0;
    if (cooldownRemaining > 0) {
      const cached = listDetailCache.get(String(listId)) || null;
      if (cached) {
        setDetailView(true);
        renderListDetail(cached);
      } else {
        setDetailView(false);
      }
      reportIssue({ _status: 429, _retry_after: cooldownRemaining, error: 'Too many requests. Please wait before retrying.' }, 'list-detail', () => openListDetail(listId));
      return;
    }

    setDetailView(true, opts);
    listDetailLoading = true;
    $('#ldItems').innerHTML = '<p style="text-align:center;color:var(--text-muted);padding:1rem;">Loading…</p>';
    hide($('#ldItemsEmpty'));

    try {
      const cached = listDetailCache.get(String(listId)) || null;
      const res = await get(`${API}/lists/details.php?list_id=${listId}`);
      if (!res.ok) {
        if (cached) {
          renderListDetail(cached);
          reportIssue(res, 'list-detail', () => openListDetail(listId));
          return;
        }
        setDetailView(false);
        reportIssue(res, 'list-detail', () => openListDetail(listId));
        alert(window.ExpMgStatus ? window.ExpMgStatus.getRequestErrorMessage(res, 'Failed to load list details.') : (res.error || 'Failed to load list details.'));
        return;
      }

      listDetailCache.set(String(listId), res);
      renderListDetail(res);
      clearIssue('list-detail');
    } finally {
      listDetailLoading = false;
      // Loading content is always replaced by success, stale, or error state above.
    }
  }

  function renderListDetail(res) {

    const l = res.list;
    currentListGroupId = l.group_id || null;
    $('#ldName').textContent = l.name;
    $('#ldType').innerHTML = l.group_id ? `${ICON_GROUP_SVG} ${esc(l.group_name)}` : `${ICON_LOCK_SVG} Personal`;
    $('#ldType').className = 'exp-badge ' + (l.group_id ? 'badge-group' : 'badge-personal');
    $('#ldCreator').textContent = `Created by ${l.created_by}`;

    const items = res.items;
    currentListMeta = l;
    if (items.length === 0) {
      $('#ldItems').innerHTML = '';
      show($('#ldItemsEmpty'));
      // Every item was removed while the modal was open — close it.
      if (itemsModal && !itemsModal.isClosed()) closeItemsModal();
    } else {
      hide($('#ldItemsEmpty'));
      renderItems(items);
      // Live sync: if the "View all" modal is open for this same list
      // (a check/uncheck/remove/add just re-rendered the inline card),
      // refresh its body so both views stay in sync.
      if (itemsModal && !itemsModal.isClosed()) {
        if (String(itemsModalListId) === String(currentListId)) {
          itemsModal.setBody(listItemsHtml(items, 0));
          bindItemRowEvents(itemsModal.body);
          startTimers();
        } else {
          closeItemsModal();
        }
      }
    }

    // Delete list button
    $('#ldDeleteList').onclick = async () => {
      const confirmed = await showConfirmModal({
        title: 'Delete this list?',
        message: 'This will permanently delete the list and all its items. This action cannot be undone.',
        confirmLabel: 'Delete List',
        danger: true
      });
      if (!confirmed) return;
      const r = await post(`${API}/lists/delete.php`, { list_id: currentListId });
      if (r.ok) { setDetailView(false); currentListId = null; closeItemsModal(); loadLists(); }
      else {
        reportIssue(r, 'lists-delete', () => $('#ldDeleteList').click());
        alert(r.error);
      }
    };
  }

  function listPriorityGroups(items) {
    const groups = { high: [], moderate: [], low: [] };
    items.forEach(i => groups[i.priority].push(i));
    return groups;
  }

  const priorityLabels = {
    high:     '<div class="priority-label pl-high"><span class="dot dot-high"></span> High Priority</div>',
    moderate: '<div class="priority-label pl-mod"><span class="dot dot-moderate"></span> Moderate Priority</div>',
    low:      '<div class="priority-label pl-low"><span class="dot dot-low"></span> Low Priority</div>'
  };

  function listItemRowHtml(i) {
    const checkedCls = i.is_checked ? 'checked' : '';
    const checkBtnCls = i.is_checked ? 'li-check is-checked' : 'li-check';

    // 10-minute lock logic
    let lockInfo = '';
    let isLocked = false;
    if (i.is_checked && i.checked_at) {
      const checkedMs = new Date(i.checked_at.replace(' ', 'T')).getTime();
      const elapsedMs = Date.now() - checkedMs;
      const tenMin = 10 * 60 * 1000;
      if (elapsedMs >= tenMin) {
        isLocked = true;
        lockInfo = `<span class="li-lock" title="Locked — cannot uncheck after 10 minutes">${ICON_LOCK_SVG}</span>`;
      } else {
        const secsLeft = Math.ceil((tenMin - elapsedMs) / 1000);
        lockInfo = `<span class="li-timer" data-expires="${checkedMs + tenMin}" title="Time remaining to uncheck">${fmtTimer(secsLeft)}</span>`;
      }
    }

    return `
      <div class="list-item-row ${checkedCls}">
        <button class="${checkBtnCls}${isLocked ? ' li-locked' : ''}" data-id="${i.id}" title="${isLocked ? 'Locked' : 'Toggle'}" ${isLocked ? 'disabled' : ''}>${i.is_checked ? '✓' : ''}</button>
        <span class="li-desc">${esc(i.description)}</span>
        ${lockInfo}
        ${i.price ? `<span class="li-price">₹${Number(i.price).toLocaleString('en-IN', {minimumFractionDigits:2, maximumFractionDigits:2})}</span>` : ''}
        ${i.category_name ? `<span class="li-cat">${esc(i.category_name)}</span>` : ''}
        <button class="li-remove" data-id="${i.id}" title="Remove">✕</button>
      </div>
    `;
  }

  /* Render list item rows grouped under priority headings. When `limit` is a
     positive number (the inline 3-item preview cap), at most `limit` rows are
     emitted, counted across High -> Moderate -> Low; a priority heading is
     only rendered when at least one of its items falls within the cap.
     Pass 0 (or null/undefined) to render every item. */
  function listItemsHtml(items, limit) {
    const groups = listPriorityGroups(items);
    let html = '';
    let rendered = 0;
    ['high', 'moderate', 'low'].forEach(p => {
      if (groups[p].length === 0) return;
      if (limit && rendered >= limit) return;
      html += priorityLabels[p];
      groups[p].forEach(i => {
        if (limit && rendered >= limit) return;
        rendered++;
        html += listItemRowHtml(i);
      });
    });
    return html;
  }

  function closeItemsModal() {
    if (itemsModal && !itemsModal.isClosed()) itemsModal.close();
    itemsModal = null;
    itemsModalListId = null;
  }

  function openItemsModal(l, items) {
    closeItemsModal();
    const doneCount = items.filter(i => i.is_checked).length;
    itemsModalListId = currentListId;
    itemsModal = openViewAllModal({
      title: l.name,
      badge: l.group_id
        ? { label: l.group_name || 'Group', className: 'badge-group' }
        : { label: 'Personal', className: 'badge-personal' },
      subtitle: `${doneCount}/${items.length} items completed`,
      bodyHtml: listItemsHtml(items, 0)
    });
    bindItemRowEvents(itemsModal.body);
    startTimers();
  }

  function renderItems(items) {
    const container = $('#ldItems');
    // Inline preview: at most 3 items total, counted across the priority
    // groups; items 4..N are only rendered inside the "View all" modal.
    container.innerHTML = listItemsHtml(items, 3);
    if (items.length > 3) {
      container.innerHTML += viewAllBtnHtml(items.length);
      container.querySelector('.btn-view-all').addEventListener('click', () => {
        if (!currentListMeta) return;
        openItemsModal(currentListMeta, items);
      });
    }
    bindItemRowEvents(container);
    startTimers();
  }

  // Bind check/remove handlers on every rendered item row (inline detail
  // card or "View all" modal body) — identical behavior in both views, and
  // every mutation re-renders both via openListDetail().
  function bindItemRowEvents(container) {
    container.querySelectorAll('.li-check').forEach(btn => {
      if (btn.disabled) return; // locked items
      btn.addEventListener('click', async () => {
        const isChecked = btn.classList.contains('is-checked');
        if (isChecked) {
          // Unchecking — direct call
          const r = await post(`${API}/lists/check_item.php`, { item_id: btn.dataset.id });
          if (r.ok) { openListDetail(currentListId); loadLists(); }
          else alert(r.error);
        } else {
          // Checking — POST to check the item
          const r = await post(`${API}/lists/check_item.php`, { item_id: btn.dataset.id });
          if (!r.ok) { alert(r.error); return; }

          if (r.needs_confirm) {
            // Group item — show confirmation popup
            showCheckConfirmModal(r);
          } else {
            // Personal item — already handled
            openListDetail(currentListId);
            loadLists();
          }
        }
      });
    });
    container.querySelectorAll('.li-remove').forEach(btn => {
      btn.addEventListener('click', async () => {
        if (!confirm('Remove this item?')) return;
        const r = await post(`${API}/lists/remove_item.php`, { item_id: btn.dataset.id });
        if (r.ok) { openListDetail(currentListId); loadLists(); }
        else alert(r.error);
      });
    });
  }

  /* Idempotent dismissal for the paid-by confirm popup. All four
     affordances route through here: Escape, Cancel, ✕, and the backdrop
     click. The popup hides SYNCHRONOUSLY (0ms perceived latency), then the
     uncheck POST runs in the background behind a single-flight lock so
     rapid repeated presses cannot fire a second toggle POST and silently
     re-check the item. The captured itemId is cleared immediately, making
     the dismissal idempotent even if a stale handler fires twice. */
  async function cancelCheckConfirm() {
    const popup = $('#checkConfirmModal');
    if (!popup || popup.classList.contains('hidden') || cancelConfirmInFlight) return;

    const itemId = $('#ccItemId').value;
    $('#ccItemId').value = '';
    // 1. Hide synchronously so Escape / Cancel / ✕ / backdrop feels instant.
    hide(popup);

    if (!itemId) return;
    cancelConfirmInFlight = true;
    try {
      // Optimistically revert the checkbox so there is zero visual lag
      // before the re-render lands.
      const checkBtn = document.querySelector(`[data-id="${itemId}"].li-check`);
      if (checkBtn) {
        checkBtn.classList.remove('is-checked');
        checkBtn.textContent = '';
        const row = checkBtn.closest('.list-item-row');
        if (row) row.classList.remove('checked');
      }
      await post(`${API}/lists/check_item.php`, { item_id: itemId });
      if (currentListId) openListDetail(currentListId);
      loadLists();
    } finally {
      cancelConfirmInFlight = false;
    }
  }

  // ---- Group Check Confirm Popup ----
  function showCheckConfirmModal(resp) {
    const item = resp.item;
    const members = resp.members;
    $('#ccItemId').value = item.id;
    $('#ccItemName').value = item.description;
    $('#ccAmount').value = item.price ? Number(item.price) : '';
    $('#ccAmount').readOnly = !!item.price;
    $('#ccAmount').style.background = item.price ? 'var(--bg-subtle)' : '';
    $('#ccDate').value = item.date;
    hide($('#ccError'));

    // Populate paid_by dropdown
    const sel = $('#ccPaidBy');
    sel.innerHTML = '<option value="">Select who paid…</option>';
    members.forEach(m => {
      sel.innerHTML += `<option value="${m.user_id}">${esc(m.username)}</option>`;
    });

    // Tier 5 (z-index 5500 via styles.css) always sits above the "View all"
    // modal (Tier 4) — no inline z-index needed.
    show($('#checkConfirmModal'));
    // Focus a non-<select> control so an open native dropdown cannot
    // swallow the Escape keypress: the amount input when editable, else
    // the Cancel button.
    if (!item.price) $('#ccAmount').focus();
    else $('#ccCancel').focus();
  }

  // ---- Events ----
  function bindEvents() {
    // Close detail
    $('#ldClose').addEventListener('click', () => { setDetailView(false); currentListId = null; closeItemsModal(); });

    // Add item button
    $('#ldAddItem').addEventListener('click', () => {
      $('#aiListId').value = currentListId;
      $('#aiDesc').value = '';
      $('#aiCategory').value = '';
      $('#aiPrice').value = '';
      document.querySelector('input[name="priority"][value="low"]').checked = true;
      hide($('#aiError'));
      show($('#addItemModal'));
      $('#aiDesc').focus();
    });

    // Add item form
    $('#addItemForm').addEventListener('submit', async (ev) => {
      ev.preventDefault();
      hide($('#aiError'));
      const data = {
        list_id:     $('#aiListId').value,
        description: $('#aiDesc').value.trim(),
        category_id: $('#aiCategory').value,
        priority:    document.querySelector('input[name="priority"]:checked').value,
        price:       $('#aiPrice').value.trim()
      };
      if (!data.description) return;
      const res = await post(`${API}/lists/add_item.php`, data);
      if (res.ok) {
        hide($('#addItemModal'));
        openListDetail(currentListId);
        loadLists();
      } else {
        $('#aiError').textContent = res.error;
        show($('#aiError'));
      }
    });

    // Create list button
    $('#btnMakeList').addEventListener('click', () => {
      $('#clName').value = '';
      document.querySelector('input[name="list_type"][value="personal"]').checked = true;
      hide($('#clGroupWrap'));
      hide($('#clError'));
      show($('#createListModal'));
      $('#clName').focus();
    });

    // List type toggle
    document.querySelectorAll('input[name="list_type"]').forEach(radio => {
      radio.addEventListener('change', () => {
        if (radio.value === 'group' && radio.checked) show($('#clGroupWrap'));
        else hide($('#clGroupWrap'));
      });
    });

    // Create list form
    $('#createListForm').addEventListener('submit', async (ev) => {
      ev.preventDefault();
      hide($('#clError'));
      const type = document.querySelector('input[name="list_type"]:checked').value;
      const data = { name: $('#clName').value.trim() };
      if (type === 'group') data.group_id = $('#clGroup').value;
      if (!data.name) return;
      const res = await post(`${API}/lists/create.php`, data);
      if (res.ok) {
        hide($('#createListModal'));
        loadLists();
      } else {
        $('#clError').textContent = res.error;
        show($('#clError'));
      }
    });

    // Check confirm form
    $('#checkConfirmForm').addEventListener('submit', async (ev) => {
      ev.preventDefault();
      hide($('#ccError'));
      const data = {
        item_id:  $('#ccItemId').value,
        paid_by:  $('#ccPaidBy').value,
        price:    $('#ccAmount').value,
        confirm:  '1'
      };
      if (!data.paid_by) {
        $('#ccError').textContent = 'Please select who paid.';
        show($('#ccError'));
        return;
      }
      const res = await post(`${API}/lists/check_item.php`, data);
      if (res.ok) {
        hide($('#checkConfirmModal'));
        openListDetail(currentListId);
        loadLists();
      } else {
        $('#ccError').textContent = res.error;
        show($('#ccError'));
      }
    });

    // Check confirm dismissal — Cancel and ✕ both route through the unified
    // idempotent cancelCheckConfirm() (synchronous hide, background uncheck).
    $('#ccCancel').addEventListener('click', () => cancelCheckConfirm());
    $('#ccCloseX').addEventListener('click', () => cancelCheckConfirm());

    // Escape peels one layer at a time. cancelCheckConfirm() hides the
    // popup SYNCHRONOUSLY, so this page-load handler (which runs before
    // openViewAllModal's later-registered keydown) MUST consume the
    // keystroke — otherwise the "View all" handler would see the popup
    // already hidden and close "View all" on the same press.
    document.addEventListener('keydown', (ev) => {
      if (ev.key !== 'Escape') return;
      const checkPopup = $('#checkConfirmModal');
      if (checkPopup && !checkPopup.classList.contains('hidden')) {
        ev.preventDefault();
        ev.stopImmediatePropagation();
        cancelCheckConfirm();
        return;
      }
      const addModal = $('#addItemModal');
      if (addModal && !addModal.classList.contains('hidden')) {
        ev.preventDefault();
        ev.stopImmediatePropagation();
        hide(addModal);
        return;
      }
      const createModal = $('#createListModal');
      if (createModal && !createModal.classList.contains('hidden')) {
        ev.preventDefault();
        ev.stopImmediatePropagation();
        hide(createModal);
      }
    });

    // Close modals on overlay click — the check-confirm backdrop runs the
    // unified dismissal (unchecks the item) instead of a bare hide, so DB
    // and UI state stay consistent.
    ['createListModal', 'addItemModal'].forEach(id => {
      document.getElementById(id).addEventListener('click', function(e) {
        if (e.target === this) this.classList.add('hidden');
      });
    });
    $('#checkConfirmModal').addEventListener('click', function(e) {
      if (e.target === this) cancelCheckConfirm();
    });
  }

  init();
})();

