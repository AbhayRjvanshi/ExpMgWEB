<?php
/**
 * pages/lists.php — Shopping / to-buy lists with priority ordering.
 * Included by public/index.php when page=lists.
 */
?>

<!-- Top bar -->
<div class="flex justify-between items-center mb-2">
  <h2 style="font-size:1.2rem; font-weight:700;">My Lists</h2>
  <button class="btn" id="btnMakeList" style="padding:0.45rem 1rem; font-size:0.85rem;">+ Make a List</button>
</div>

<!-- List cards container -->
<div id="listContainer" style="display:flex; flex-direction:column; gap:0.75rem;"></div>
<p id="listsEmpty" class="hidden" style="text-align:center; color:#666; font-size:0.9rem; padding:2rem 0;">
  No lists yet. Create one to start tracking what you need to buy!
</p>

<!-- ===== List Detail Panel ===== -->
<div id="listDetail" class="hidden mt-2">
  <div class="card">
    <div class="flex justify-between items-center mb-1">
      <h3 id="ldName" style="font-size:1.1rem; font-weight:700;"></h3>
      <div class="flex gap-1">
        <button class="btn" id="ldAddItem" style="padding:0.3rem 0.7rem; font-size:0.8rem;">+ Add Item</button>
        <button class="btn-outline" id="ldClose" style="padding:0.25rem 0.6rem; font-size:0.8rem; border-radius:0.4rem;">✕</button>
      </div>
    </div>
    <div class="flex gap-1 items-center mb-2" style="flex-wrap:wrap;">
      <span id="ldType" class="exp-badge"></span>
      <span id="ldCreator" style="font-size:0.8rem; color:#666;"></span>
    </div>

    <!-- Priority sections -->
    <div id="ldItems"></div>
    <p id="ldItemsEmpty" class="hidden" style="text-align:center; color:#666; font-size:0.85rem; padding:1.5rem 0;">
      This list is empty. Add items to get started.
    </p>

    <!-- Delete list -->
    <div class="mt-2">
      <button class="btn-danger btn" id="ldDeleteList" style="padding:0.4rem 0.9rem; font-size:0.83rem;">Delete List</button>
    </div>
  </div>
</div>

<!-- ===== Create List Modal ===== -->
<div id="createListModal" class="modal-overlay hidden">
  <div class="auth-card" style="max-width:420px; position:relative;">
    <button class="modal-x" onclick="document.getElementById('createListModal').classList.add('hidden')">✕</button>
    <h2 style="font-size:1.2rem; font-weight:700; margin-bottom:1rem;">Make a List</h2>
    <form id="createListForm">
      <div class="form-group">
        <label for="clName">List Name</label>
        <input type="text" id="clName" name="name" class="form-input" placeholder="e.g. Grocery Shopping" required maxlength="100" />
      </div>
      <div class="form-group">
        <label>List Type</label>
        <div class="flex gap-1 mt-1">
          <label style="display:flex;align-items:center;gap:0.35rem;cursor:pointer;">
            <input type="radio" name="list_type" value="personal" checked /> Personal
          </label>
          <label style="display:flex;align-items:center;gap:0.35rem;cursor:pointer;">
            <input type="radio" name="list_type" value="group" /> Group
          </label>
        </div>
      </div>
      <div class="form-group hidden" id="clGroupWrap">
        <label for="clGroup">Group</label>
        <select id="clGroup" name="group_id" class="form-input">
          <option value="">Select group…</option>
        </select>
      </div>
      <div id="clError" class="alert alert-error hidden"></div>
      <button type="submit" class="btn w-full">Create List</button>
    </form>
  </div>
</div>

<!-- ===== Add Item Modal ===== -->
<div id="addItemModal" class="modal-overlay hidden">
  <div class="auth-card" style="max-width:420px; position:relative;">
    <button class="modal-x" onclick="document.getElementById('addItemModal').classList.add('hidden')">✕</button>
    <h2 style="font-size:1.2rem; font-weight:700; margin-bottom:1rem;">Add Item</h2>
    <form id="addItemForm">
      <input type="hidden" id="aiListId" value="" />
      <div class="form-group">
        <label for="aiDesc">What to buy</label>
        <input type="text" id="aiDesc" name="description" class="form-input" placeholder="e.g. Milk 1L" required maxlength="255" />
      </div>
      <div class="form-group">
        <label for="aiCategory">Category</label>
        <select id="aiCategory" name="category_id" class="form-input">
          <option value="">None</option>
        </select>
      </div>
      <div class="form-group">
        <label>Priority</label>
        <div class="flex gap-1 mt-1">
          <label class="priority-radio pr-high">
            <input type="radio" name="priority" value="high" /> High
          </label>
          <label class="priority-radio pr-mod">
            <input type="radio" name="priority" value="moderate" /> Moderate
          </label>
          <label class="priority-radio pr-low">
            <input type="radio" name="priority" value="low" checked /> Low
          </label>
        </div>
      </div>
      <div class="form-group">
        <label for="aiPrice">Price <span style="font-weight:400;color:#888;">(Optional)</span></label>
        <input type="number" id="aiPrice" name="price" class="form-input" placeholder="e.g. 150.00" min="0" step="0.01" />
      </div>
      <div id="aiError" class="alert alert-error hidden"></div>
      <button type="submit" class="btn w-full">Add Item</button>
    </form>
  </div>
</div>

<!-- ===== Group Check Confirm Modal ===== -->
<div id="checkConfirmModal" class="modal-overlay hidden">
  <div class="auth-card" style="max-width:420px; position:relative;">
    <button class="modal-x" onclick="document.getElementById('checkConfirmModal').classList.add('hidden')">✕</button>
    <h2 style="font-size:1.2rem; font-weight:700; margin-bottom:1rem;">Confirm Purchase</h2>
    <form id="checkConfirmForm">
      <input type="hidden" id="ccItemId" value="" />
      <div class="form-group">
        <label>Item</label>
        <input type="text" id="ccItemName" class="form-input" readonly style="background:#f5f5f5;" />
      </div>
      <div class="form-group">
        <label for="ccAmount">Amount</label>
        <input type="number" step="0.01" min="0.01" id="ccAmount" name="price" class="form-input" placeholder="0.00" required />
      </div>
      <div class="form-group">
        <label for="ccPaidBy">Paid By</label>
        <select id="ccPaidBy" name="paid_by" class="form-input" required>
          <option value="">Select who paid…</option>
        </select>
      </div>
      <div class="form-group">
        <label>Date</label>
        <input type="text" id="ccDate" class="form-input" readonly style="background:#f5f5f5;" />
      </div>
      <div id="ccError" class="alert alert-error hidden"></div>
      <div class="flex gap-1">
        <button type="submit" class="btn w-full">Confirm</button>
        <button type="button" class="btn-outline w-full" id="ccCancel" style="padding:0.65rem;">Cancel</button>
      </div>
    </form>
  </div>
</div>

<!-- ===== Lists CSS ===== -->
<style>
  .list-card {
    background: #fff;
    border: 1px solid #e0e0e0;
    border-radius: 0.75rem;
    padding: 0.85rem 1rem;
    cursor: pointer;
    transition: background var(--t-fast), transform var(--t-fast);
  }
  .list-card:hover { background: #f9f9f9; transform: translateY(-2px); }
  .list-card .lc-name { font-weight: 600; font-size: 1rem; color: #000; }
  .list-card .lc-meta { font-size: 0.8rem; color: #666; margin-top: 0.15rem; }
  .list-card .lc-progress {
    height: 4px; border-radius: 2px; background: rgba(0,0,0,0.08);
    margin-top: 0.5rem; overflow: hidden;
  }
  .list-card .lc-progress-bar { height: 100%; background: var(--mint-leaf); border-radius: 2px; transition: width 0.3s ease; }

  .priority-label {
    font-size: 0.75rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px;
    padding: 0.6rem 0 0.3rem; border-bottom: 1px solid rgba(0,0,0,0.08);
    margin-bottom: 0.4rem;
  }
  .priority-label.pl-high    { color: #d97706; }
  .priority-label.pl-mod     { color: var(--sea-green); }
  .priority-label.pl-low     { color: #666; }

  .list-item-row {
    display: flex; align-items: center; gap: 0.6rem;
    padding: 0.5rem 0.6rem; border-radius: 0.4rem;
    transition: background var(--t-fast);
  }
  .list-item-row:hover { background: rgba(0,0,0,0.03); }
  .list-item-row.checked .li-desc { text-decoration: line-through; opacity: 0.5; }
  .li-check {
    width: 20px; height: 20px; border-radius: 4px;
    border: 2px solid #aaa; background: none;
    cursor: pointer; flex-shrink: 0;
    display: flex; align-items: center; justify-content: center;
    transition: all var(--t-fast); color: transparent; font-size: 0.8rem;
  }
  .li-check.is-checked { background: var(--mint-leaf); border-color: var(--mint-leaf); color: #fff; }
  .li-desc { flex: 1; font-size: 0.9rem; color: #000; }
  .li-cat  { font-size: 0.75rem; color: #666; }
  .li-price { font-size: 0.78rem; font-weight: 600; color: var(--sea-green); white-space: nowrap; }
  .li-remove {
    background: none; border: 1px solid #ccc;
    color: #666; font-size: 0.7rem; padding: 0.15rem 0.4rem;
    border-radius: 0.3rem; cursor: pointer; transition: all var(--t-fast);
  }
  .li-remove:hover { border-color: #ef4444; color: #ef4444; }

  .li-lock {
    font-size: 0.75rem; opacity: 0.6;
  }
  .li-timer {
    font-size: 0.72rem; font-weight: 600; color: #d97706;
    background: #fef3c7; padding: 0.1rem 0.4rem;
    border-radius: 0.3rem; white-space: nowrap;
    font-variant-numeric: tabular-nums;
  }
  .li-check.li-locked {
    opacity: 0.4; cursor: not-allowed;
  }

  .priority-radio {
    display: flex; align-items: center; gap: 0.35rem; cursor: pointer;
    padding: 0.3rem 0.6rem; border-radius: 0.4rem; font-size: 0.85rem;
    border: 1px solid #ccc; transition: all var(--t-fast); color: #000;
  }
  .priority-radio:hover { border-color: var(--mint-leaf); }
  .pr-high  { color: #d97706; }
  .pr-mod   { color: var(--sea-green); }
  .pr-low   { color: #666; }

  .modal-x {
    position:absolute;top:1rem;right:1rem;background:none;border:none;
    color:#666;font-size:1.4rem;cursor:pointer;
  }
</style>

<!-- ===== Lists JS ===== -->
<script>
(function() {
  'use strict';
  const esc = escapeHTML; // alias for brevity

  let categories = [];
  let userGroups = [];
  let currentListId = null;
  let currentListGroupId = null;
  let timerInterval = null;

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
    hide(empty);
    container.innerHTML = '<p style="text-align:center;color:var(--celadon);padding:1.5rem 0;">Loading…</p>';

    const res = await get(`${API}/lists/user_lists.php`);
    if (!res.ok || res.lists.length === 0) {
      container.innerHTML = '';
      show(empty);
      return;
    }

    container.innerHTML = res.lists.map(l => {
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
    show(panel);
    $('#ldItems').innerHTML = '<p style="text-align:center;color:var(--celadon);padding:1rem;">Loading…</p>';
    hide($('#ldItemsEmpty'));

    const res = await get(`${API}/lists/details.php?list_id=${listId}`);
    if (!res.ok) { hide(panel); alert(res.error); return; }

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
      const r = await post(`${API}/lists/delete.php`, { list_id: listId });
      if (r.ok) { hide(panel); currentListId = null; loadLists(); }
      else alert(r.error);
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
