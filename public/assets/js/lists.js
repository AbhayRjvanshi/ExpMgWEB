(function() {
  'use strict';
  const esc = escapeHTML; // alias for brevity

  let categories = [];
  let userGroups = [];
  let currentListId = null;
  let currentListGroupId = null;
  let timerInterval = null;
  let listsCache = [];
  const listDetailCache = new Map();
  let listsLoading = false;
  let listDetailLoading = false;

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
      container.innerHTML = `<p style="text-align:center;color:#b45309;padding:1.5rem 0;">Too many requests. Please wait ${remaining}s.</p>`;
      hide(empty);
    }
  }

  function renderListDetailCoolingDown(listId) {
    const cached = listDetailCache.get(String(listId)) || null;
    const panel = $('#listDetail');
    if (cached) {
      show(panel);
      renderListDetail(cached);
    } else {
      hide(panel);
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
      if (needRefresh && currentListId) openListDetail(currentListId);
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
        container.innerHTML = `<p style="text-align:center;color:#b45309;padding:1.5rem 0;">Too many requests. Please wait ${cooldownRemaining}s.</p>`;
        hide(empty);
      }
      reportIssue({ _status: 429, _retry_after: cooldownRemaining, error: 'Too many requests. Please wait before retrying.' }, 'lists-overview', () => loadLists());
      return;
    }

    hide(empty);
    listsLoading = true;
    container.innerHTML = '<p style="text-align:center;color:var(--celadon);padding:1.5rem 0;">Loading…</p>';

    try {
      const res = await get(`${API}/lists/user_lists.php`);
      if (!res.ok) {
        if (listsCache.length > 0) {
          renderListCards(container, listsCache);
        } else {
          container.innerHTML = '<p style="text-align:center;color:#b45309;padding:1.5rem 0;">Unable to load lists right now.</p>';
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
        container.innerHTML = '<p style="text-align:center;color:#b45309;padding:1.5rem 0;">Connection issue. Retry.</p>';
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
      const typeLabel = l.group_id ? `📋 ${esc(l.group_name)}` : '🔒 Personal';
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
      card.addEventListener('click', () => openListDetail(card.dataset.id));
    });
  }

  // ---- List detail ----
  async function openListDetail(listId) {
    currentListId = listId;
    const panel = $('#listDetail');
    const cooldownRemaining = window.ExpMgStatus && typeof window.ExpMgStatus.getRateLimitRemainingSeconds === 'function'
      ? window.ExpMgStatus.getRateLimitRemainingSeconds()
      : 0;
    if (cooldownRemaining > 0) {
      const cached = listDetailCache.get(String(listId)) || null;
      if (cached) {
        show(panel);
        renderListDetail(cached);
      } else {
        hide(panel);
      }
      reportIssue({ _status: 429, _retry_after: cooldownRemaining, error: 'Too many requests. Please wait before retrying.' }, 'list-detail', () => openListDetail(listId));
      return;
    }

    show(panel);
    listDetailLoading = true;
    $('#ldItems').innerHTML = '<p style="text-align:center;color:var(--celadon);padding:1rem;">Loading…</p>';
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
        hide(panel);
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
    $('#ldType').textContent = l.group_id ? `📋 ${l.group_name}` : '🔒 Personal';
    $('#ldType').className = 'exp-badge ' + (l.group_id ? 'badge-group' : 'badge-personal');
    $('#ldCreator').textContent = `Created by ${l.created_by}`;

    const items = res.items;
    if (items.length === 0) {
      $('#ldItems').innerHTML = '';
      show($('#ldItemsEmpty'));
    } else {
      hide($('#ldItemsEmpty'));
      renderItems(items);
    }

    // Delete list button
    $('#ldDeleteList').onclick = async () => {
      if (!confirm('Delete this list and all its items?')) return;
      const r = await post(`${API}/lists/delete.php`, { list_id: currentListId });
      if (r.ok) { hide(panel); currentListId = null; loadLists(); }
      else {
        reportIssue(r, 'lists-delete', () => $('#ldDeleteList').click());
        alert(r.error);
      }
    };
  }

  function renderItems(items) {
    const container = $('#ldItems');
    // Group by priority
    const groups = { high: [], moderate: [], low: [] };
    items.forEach(i => groups[i.priority].push(i));

    let html = '';
    const labels = {
      high:     '<div class="priority-label pl-high">🔴 High Priority</div>',
      moderate: '<div class="priority-label pl-mod">🟡 Moderate Priority</div>',
      low:      '<div class="priority-label pl-low">🟢 Low Priority</div>'
    };

    ['high', 'moderate', 'low'].forEach(p => {
      if (groups[p].length === 0) return;
      html += labels[p];
      groups[p].forEach(i => {
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
            lockInfo = `<span class="li-lock" title="Locked — cannot uncheck after 10 minutes">🔒</span>`;
          } else {
            const secsLeft = Math.ceil((tenMin - elapsedMs) / 1000);
            lockInfo = `<span class="li-timer" data-expires="${checkedMs + tenMin}" title="Time remaining to uncheck">${fmtTimer(secsLeft)}</span>`;
          }
        }

        html += `
          <div class="list-item-row ${checkedCls}">
            <button class="${checkBtnCls}${isLocked ? ' li-locked' : ''}" data-id="${i.id}" title="${isLocked ? 'Locked' : 'Toggle'}" ${isLocked ? 'disabled' : ''}>${i.is_checked ? '✓' : ''}</button>
            <span class="li-desc">${esc(i.description)}</span>
            ${lockInfo}
            ${i.price ? `<span class="li-price">₹${Number(i.price).toLocaleString('en-IN', {minimumFractionDigits:2, maximumFractionDigits:2})}</span>` : ''}
            ${i.category_name ? `<span class="li-cat">${esc(i.category_name)}</span>` : ''}
            <button class="li-remove" data-id="${i.id}" title="Remove">✕</button>
          </div>
        `;
      });
    });

    container.innerHTML = html;

    // Bind check/remove
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

    // Start live countdown timers
    startTimers();
  }

  // ---- Group Check Confirm Popup ----
  function showCheckConfirmModal(resp) {
    const item = resp.item;
    const members = resp.members;
    $('#ccItemId').value = item.id;
    $('#ccItemName').value = item.description;
    $('#ccAmount').value = item.price ? Number(item.price) : '';
    $('#ccAmount').readOnly = !!item.price;
    $('#ccAmount').style.background = item.price ? '#f5f5f5' : '';
    $('#ccDate').value = item.date;
    hide($('#ccError'));

    // Populate paid_by dropdown
    const sel = $('#ccPaidBy');
    sel.innerHTML = '<option value="">Select who paid…</option>';
    members.forEach(m => {
      sel.innerHTML += `<option value="${m.user_id}">${esc(m.username)}</option>`;
    });

    show($('#checkConfirmModal'));
    sel.focus();
  }

  // ---- Events ----
  function bindEvents() {
    // Close detail
    $('#ldClose').addEventListener('click', () => { hide($('#listDetail')); currentListId = null; });

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

    // Check confirm cancel — uncheck the item
    $('#ccCancel').addEventListener('click', async () => {
      const itemId = $('#ccItemId').value;
      if (itemId) {
        // Uncheck the item since they cancelled
        await post(`${API}/lists/check_item.php`, { item_id: itemId });
        openListDetail(currentListId);
        loadLists();
      }
      hide($('#checkConfirmModal'));
    });

    // Close modals on overlay click
    ['createListModal', 'addItemModal', 'checkConfirmModal'].forEach(id => {
      document.getElementById(id).addEventListener('click', function(e) {
        if (e.target === this) this.classList.add('hidden');
      });
    });
  }

  init();
})();
</script>
