<?php
/**
 * pages/groups.php — Group management.
 * Included by public/index.php when page=groups.
 */
?>

<!-- Top action buttons -->
<div class="flex justify-between items-center mb-2">
  <h2 style="font-size:1.2rem; font-weight:700;">My Groups</h2>
  <div class="flex gap-1">
    <button class="btn" id="btnCreateGroup" style="padding:0.45rem 1rem; font-size:0.85rem;">+ Create Group</button>
    <button class="btn btn-compact" id="btnJoinGroup">Join Group</button>
  </div>
</div>

<!-- Master-detail wrapper (desktop >=768px: split view while a detail panel is open) -->
<div id="groupsMasterDetail">

<!-- Group list container -->
<div id="groupList" style="display:flex; flex-direction:column; gap:0.75rem;">
  <p style="text-align:center; color:var(--text-muted); padding:2rem 0;">Loading groups…</p>
</div>
<p id="groupsEmpty" class="hidden" style="text-align:center; color:var(--text-muted); font-size:0.9rem; padding:2rem 0;">
  You don't belong to any groups yet. Create one or join using a code!
</p>

<!-- ===== Group Detail Panel (shown when clicking a group) ===== -->
<div id="groupDetail" class="hidden mt-2">
  <div class="card">
    <div class="flex justify-between items-center mb-1">
      <h3 id="gdName" style="font-size:1.1rem; font-weight:700;"></h3>
      <div class="flex gap-1">
        <button class="btn btn-icon" id="gdDeleteGroup" title="Delete this group" style="display:none;background:transparent;border:1px solid var(--danger-600);color:var(--danger-600);">
          <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/><line x1="10" y1="11" x2="10" y2="17"/><line x1="14" y1="11" x2="14" y2="17"/></svg>
        </button>
        <button class="btn btn-icon" id="gdClose">✕</button>
      </div>
    </div>
    <div class="flex gap-1 items-center mb-2" style="flex-wrap:wrap;">
      <span id="gdCode" class="code-pill"></span>
      <button class="btn btn-compact" id="gdCopyCode">Copy Code</button>
      <span id="gdRole" class="exp-badge" style="margin-left:auto;"></span>
    </div>

    <!-- Members -->
    <h4 style="font-size:0.9rem; font-weight:600; color:var(--text-secondary); margin-bottom:0.5rem;">
      Members <span id="gdMemberCount" style="opacity:0.7;"></span>
    </h4>
    <div id="gdMembers" style="display:flex; flex-direction:column; gap:0.4rem; margin-bottom:1rem;"></div>

    <!-- Past Expenses -->
    <div class="flex justify-between items-center" style="margin-bottom:0.5rem;">
      <h4 style="font-size:0.9rem; font-weight:600; color:var(--text-secondary);">Past Group Expenses</h4>
      <button class="exp-pdf-btn" id="gdExpPdf" title="Download PDF" style="display:none;"><svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:-2px;"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/><path d="M12 18v-6"/><path d="m9 15 3 3 3-3"/></svg> PDF</button>
    </div>
    <div id="gdExpenses" style="display:flex; flex-direction:column; gap:0.5rem;"></div>
    <p id="gdExpEmpty" class="hidden" style="text-align:center; color:var(--text-muted); font-size:0.85rem; padding:1rem 0;">No expenses yet.</p>

    <!-- Actions -->
    <div class="flex gap-1 mt-2" id="gdActions"></div>
  </div>
</div>
</div><!-- /groupsMasterDetail -->

<!-- ===== Create Group Modal ===== -->
<div id="createGroupModal" class="modal-overlay hidden">
  <div class="auth-card" style="max-width:400px; position:relative;">
    <button class="modal-x" onclick="document.getElementById('createGroupModal').classList.add('hidden')">✕</button>
    <h2 style="font-size:1.2rem; font-weight:700; margin-bottom:1rem;">Create a Group</h2>
    <form id="createGroupForm">
      <div class="form-group">
        <label for="cgName">Group Name</label>
        <input type="text" id="cgName" name="name" class="form-input" placeholder="e.g. Roommates" required maxlength="100" />
      </div>
      <div id="cgError" class="alert alert-error hidden"></div>
      <button type="submit" class="btn w-full">Create Group</button>
    </form>
  </div>
</div>

<!-- ===== Join Group Modal ===== -->
<div id="joinGroupModal" class="modal-overlay hidden">
  <div class="auth-card" style="max-width:400px; position:relative;">
    <button class="modal-x" onclick="document.getElementById('joinGroupModal').classList.add('hidden')">✕</button>
    <h2 style="font-size:1.2rem; font-weight:700; margin-bottom:1rem;">Join a Group</h2>
    <form id="joinGroupForm">
      <div class="form-group">
        <label for="jgCode">Group Code</label>
        <input type="text" id="jgCode" name="join_code" class="form-input" placeholder="e.g. ABCD1234"
               required maxlength="20" style="text-transform:uppercase; letter-spacing:2px; font-family:monospace;" />
      </div>
      <div id="jgError" class="alert alert-error hidden"></div>
      <button type="submit" class="btn w-full">Join Group</button>
    </form>
  </div>
</div>

<!-- ===== Extra CSS for groups ===== -->
<style>
  /* .modal-x moved to public/assets/css/styles.css (shared components) */
  .group-card {
    background: var(--bg-surface);
    border: 1px solid var(--border-soft);
    border-radius: var(--radius-lg);
    padding: 0.85rem 1rem;
    cursor: pointer;
    transition: background var(--t-fast), border-color var(--t-fast);
  }
  .group-card:hover { background: var(--bg-subtle); }
  .group-card .gc-name { font-weight: 600; font-size: 1rem; color: var(--text-primary); }
  .group-card .gc-meta { font-size: 0.8rem; color: var(--text-muted); margin-top: 0.15rem; }

  .member-row {
    display: flex; justify-content: space-between; align-items: center;
    padding: 0.4rem 0.6rem; border-radius: var(--radius-sm);
    background: var(--bg-subtle);
  }
  .member-main {
    display: flex; align-items: center; gap: 0.5rem;
  }
  .member-actions {
    display: flex; align-items: center; gap: 0.5rem;
  }
  .member-row .mr-name { font-size: 0.9rem; color: var(--text-primary); }
  .member-row .mr-role { font-size: 0.75rem; color: var(--text-muted); }
  .btn-remove-member {
    border: 1px solid var(--danger-600);
    background: var(--bg-surface);
    color: var(--danger-600);
    border-radius: var(--radius-sm);
    padding: 0.2rem 0.5rem;
    font-size: 0.72rem;
    font-weight: 600;
    cursor: pointer;
  }
  .btn-remove-member:hover {
    background: var(--danger-50);
  }
</style>

<!-- ===== Groups JS (inline — loaded only on this page) ===== -->
<script>
(function() {
  'use strict';
  const esc = escapeHTML; // alias for brevity
  let currentGroupId = null;

  // ---- Master-detail state helper ----
  // All show/hide of the group detail panel routes through here so refreshes
  // (remove-member) never desync visibility. On initial card selection
  // (opts.scroll === true) the active summary card is highlighted and the
  // detail panel is scrolled smoothly into view.
  function setGroupDetailView(isOpen, opts) {
    const panel = $('#groupDetail');
    const wrapper = document.getElementById('groupsMasterDetail');
    if (wrapper) {
      // Desktop (>=768px) master-detail split view; no-op on mobile where
      // the class is media-scoped.
      if (isOpen) wrapper.classList.add('master-detail-grid');
      else wrapper.classList.remove('master-detail-grid');
    }
    document.querySelectorAll('#groupList .group-card.is-selected')
      .forEach(el => el.classList.remove('is-selected'));
    if (isOpen) {
      show(panel);
      if (currentGroupId !== null) {
        const activeCard = document.querySelector('#groupList .group-card[data-id="' + currentGroupId + '"]');
        if (activeCard) activeCard.classList.add('is-selected');
      }
      if (opts && opts.scroll) {
        panel.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
      }
    } else {
      hide(panel);
    }
  }

  // Inline SVG icon constants (replace platform emoji in UI chrome)
  const ICON_CROWN_SVG = '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:-2px;"><path d="M3 17l1-10 5 5 3-7 3 7 5-5 1 10z"/></svg>';

  // ---- Load groups ----
  async function loadGroups() {
    const container = $('#groupList');
    const empty     = $('#groupsEmpty');
    hide(empty);
    container.innerHTML = '<p style="text-align:center;color:var(--text-muted);padding:1.5rem 0;">Loading…</p>';

    const res = await get(`${API}/groups/user_groups.php`);
    if (!res.ok || res.groups.length === 0) {
      container.innerHTML = '';
      show(empty);
      return;
    }

    container.innerHTML = res.groups.map(g => `
      <div class="group-card" data-id="${g.id}">
        <div class="gc-name">${esc(g.name)}</div>
        <div class="gc-meta">Role: ${g.role === 'admin' ? ICON_CROWN_SVG + ' Admin' : 'Member'}</div>
      </div>
    `).join('');

    container.querySelectorAll('.group-card').forEach(card => {
      card.addEventListener('click', () => openGroupDetail(card.dataset.id, { scroll: true }));
    });
  }

  // ---- Group detail ----
  async function openGroupDetail(groupId, opts) {
    currentGroupId = groupId;
    setGroupDetailView(true, opts);
    $('#gdMembers').innerHTML = '<p style="color:var(--text-muted);font-size:0.85rem;">Loading…</p>';
    $('#gdExpenses').innerHTML = '';
    hide($('#gdExpEmpty'));
    $('#gdActions').innerHTML = '';

    const res = await get(`${API}/groups/details.php?group_id=${groupId}`);
    if (!res.ok) { setGroupDetailView(false); alert(res.error); return; }

    const g = res.group;
    $('#gdName').textContent = g.name;
    $('#gdCode').textContent = g.join_code;
    $('#gdRole').innerHTML = res.my_role === 'admin' ? ICON_CROWN_SVG + ' Admin' : 'Member';
    $('#gdRole').className = 'exp-badge ' + (res.my_role === 'admin' ? 'badge-personal' : 'badge-group');
    $('#gdMemberCount').textContent = `(${res.members.length}/${g.max_members})`;

    // Members
    $('#gdMembers').innerHTML = res.members.map(m => `
      <div class="member-row">
        <div class="member-main">
          <span class="mr-name">${esc(m.username)}</span>
        </div>
        <div class="member-actions">
          <span class="mr-role">${m.role === 'admin' ? ICON_CROWN_SVG + ' Admin' : 'Member'}</span>
          ${res.my_role === 'admin' && m.role !== 'admin'
            ? `<button class="btn-remove-member" data-member-id="${m.user_id}" data-member-name="${esc(m.username)}">Remove</button>`
            : ''}
        </div>
      </div>
    `).join('');

    $('#gdMembers').querySelectorAll('.btn-remove-member').forEach(btn => {
      btn.addEventListener('click', async (ev) => {
        ev.stopPropagation();
        const memberId = Number(btn.dataset.memberId || 0);
        const memberName = btn.dataset.memberName || 'this member';
        if (!memberId) return;
        if (!confirm(`Remove ${memberName} from this group?`)) return;

        const r = await post(`${API}/groups/remove_member.php`, {
          group_id: g.id,
          user_id: memberId
        });

        if (!r.ok) {
          alert(r.error || 'Failed to remove member.');
          return;
        }

        openGroupDetail(g.id);
        loadGroups();
      });
    });

    // Expenses
    const pdfBtn = $('#gdExpPdf');
    if (res.expenses.length === 0) {
      show($('#gdExpEmpty'));
      $('#gdExpenses').innerHTML = '';
      pdfBtn.style.display = 'none';
    } else {
      hide($('#gdExpEmpty'));
      pdfBtn.style.display = '';
      pdfBtn.onclick = () => downloadGroupExpPdf(g.name, res.expenses);
      renderGroupExpenses(g.name, res.expenses);
    }

    // Actions — Leave Group (members) stays at the bottom; Delete Group moved
    // to the header icon (admin only, bound once at init to avoid stacking).
    let actionsHtml = '';
    if (res.my_role !== 'admin') {
      actionsHtml += `<button class="btn btn-compact" id="gdLeaveGroup" data-id="${g.id}">Leave Group</button>`;
    }
    $('#gdActions').innerHTML = actionsHtml;

    const delBtn = $('#gdDeleteGroup');
    if (delBtn) {
      delBtn.dataset.id = g.id;
      delBtn.style.display = res.my_role === 'admin' ? '' : 'none';
    }

    const leaveBtn = $('#gdLeaveGroup');
    if (leaveBtn) leaveBtn.addEventListener('click', async () => {
      if (!confirm('Leave this group?')) return;
      const r = await post(`${API}/groups/leave.php`, { group_id: leaveBtn.dataset.id });
      if (r.ok) { setGroupDetailView(false); currentGroupId = null; loadGroups(); }
      else alert(r.error);
    });

    // Copy code
    $('#gdCopyCode').onclick = () => {
      navigator.clipboard.writeText(g.join_code).then(() => {
        $('#gdCopyCode').textContent = 'Copied!';
        setTimeout(() => { $('#gdCopyCode').textContent = 'Copy Code'; }, 1500);
      });
    };
  }

  // ---- Past Group Expenses: 5-item inline preview cap + "View all ↓" modal ----
  function groupExpenseRowHtml(e) {
    return `
      <div class="expense-item">
        <div class="exp-left">
          <div class="exp-note" style="font-weight:600;">${esc(e.note || e.category_name || '—')}</div>
          <div class="exp-cat"><span class="dmy-date">${formatDateDMY(e.expense_date)}</span> · ${esc(e.category_name)} · paid by ${esc(e.payer_username || e.added_by)}</div>
        </div>
        <div class="exp-amount">₹${Number(e.amount).toLocaleString('en-IN', {minimumFractionDigits: 2, maximumFractionDigits: 2})}</div>
      </div>
    `;
  }

  function renderGroupExpenses(groupName, expenses) {
    const container = $('#gdExpenses');
    // Inline preview shows the first 3 entries only; items 4..N live in the
    // "View all" modal. Dates render as DD-MM-YYYY in both views, and the
    // PDF export stays wired to the full, untruncated expense array.
    const preview = expenses.slice(0, 3);
    container.innerHTML = preview.map(groupExpenseRowHtml).join('');
    if (expenses.length > 3) {
      container.innerHTML += viewAllBtnHtml(expenses.length);
      container.querySelector('.btn-view-all').addEventListener('click', () => {
        openViewAllModal({
          title: `${groupName} — Group Expenses`,
          subtitle: `${expenses.length} total expenses`,
          bodyHtml: expenses.map(groupExpenseRowHtml).join('')
        });
      });
    }
  }

  // ---- Download group expenses PDF ----
  function downloadGroupExpPdf(groupName, expenses) {
    const { jsPDF } = window.jspdf;
    const doc = new jsPDF();
    const title = `Past Group Expenses – ${groupName}`;
    doc.setFontSize(14);
    doc.setTextColor(46, 125, 50);
    doc.text(title, 14, 18);

    const rows = expenses.map((e, i) => [
      i + 1,
      e.note || '—',
      e.category_name,
      formatDateDMY(e.expense_date),
      e.payer_username || e.added_by,
      parseFloat(e.amount).toFixed(2)
    ]);
    const total = expenses.reduce((s, e) => s + parseFloat(e.amount), 0);
    rows.push(['', '', '', '', 'Total', total.toFixed(2)]);

    doc.autoTable({
      startY: 24,
      head: [['#', 'Description', 'Category', 'Date', 'Paid By', 'Amount']],
      body: rows,
      theme: 'grid',
      headStyles: { fillColor: [46, 125, 50] },
      styles: { fontSize: 9 },
      columnStyles: { 5: { halign: 'right' } },
      didParseCell(data) {
        if (data.row.index === rows.length - 1 && data.section === 'body') {
          data.cell.styles.fontStyle = 'bold';
        }
      }
    });

    doc.setFontSize(8);
    doc.setTextColor(120);
    doc.text(`Generated ${new Date().toLocaleString()}`, 14, doc.internal.pageSize.height - 10);

    const now = new Date();
    const mk = `${now.getFullYear()}-${String(now.getMonth()+1).padStart(2,'0')}`;
    doc.save(`group_expenses_${groupName.replace(/\s+/g,'_')}_${mk}.pdf`);
  }

  // ---- Close detail ----
  $('#gdClose').addEventListener('click', () => { setGroupDetailView(false); currentGroupId = null; });

  // ---- Delete group (admin) — header icon, bound once; operates on the
  // currently open group via data-id. Routes through the reusable
  // showConfirmModal() instead of a permanently visible red button. ----
  const gdDeleteBtn = $('#gdDeleteGroup');
  if (gdDeleteBtn) gdDeleteBtn.addEventListener('click', async () => {
    const groupId = gdDeleteBtn.dataset.id;
    if (!groupId) return;
    const confirmed = await showConfirmModal({
      title: 'Delete this group?',
      message: 'This will permanently delete the group and all its data (expenses, settlements, lists). This action cannot be undone.',
      confirmLabel: 'Delete Group',
      danger: true
    });
    if (!confirmed) return;
    const r = await post(`${API}/groups/delete.php`, { group_id: groupId });
    if (r.ok) { setGroupDetailView(false); currentGroupId = null; loadGroups(); }
    else alert(r.error);
  });

  // ---- Create group ----
  $('#btnCreateGroup').addEventListener('click', () => {
    $('#cgName').value = '';
    hide($('#cgError'));
    show($('#createGroupModal'));
    $('#cgName').focus();
  });

  $('#createGroupForm').addEventListener('submit', async (ev) => {
    ev.preventDefault();
    hide($('#cgError'));
    const name = $('#cgName').value.trim();
    if (!name) return;
    const res = await post(`${API}/groups/create.php`, { name });
    if (res.ok) {
      hide($('#createGroupModal'));
      loadGroups();
    } else {
      $('#cgError').textContent = res.error;
      show($('#cgError'));
    }
  });

  // ---- Join group ----
  $('#btnJoinGroup').addEventListener('click', () => {
    $('#jgCode').value = '';
    hide($('#jgError'));
    show($('#joinGroupModal'));
    $('#jgCode').focus();
  });

  $('#joinGroupForm').addEventListener('submit', async (ev) => {
    ev.preventDefault();
    hide($('#jgError'));
    const code = $('#jgCode').value.trim();
    if (!code) return;
    const res = await post(`${API}/groups/join.php`, { join_code: code });
    if (res.ok) {
      hide($('#joinGroupModal'));
      loadGroups();
    } else {
      $('#jgError').textContent = res.error;
      show($('#jgError'));
    }
  });

  // Close modals on overlay click
  ['createGroupModal','joinGroupModal'].forEach(id => {
    document.getElementById(id).addEventListener('click', function(e) {
      if (e.target === this) this.classList.add('hidden');
    });
  });

  // Escape closes the Create/Join Group modals (Tier 5, z-index 5500) and
  // consumes the keystroke so the "View all" modal underneath (Tier 4)
  // stays open — one layer per press.
  document.addEventListener('keydown', (ev) => {
    if (ev.key !== 'Escape') return;
    const createModal = document.getElementById('createGroupModal');
    const joinModal = document.getElementById('joinGroupModal');
    if (createModal && !createModal.classList.contains('hidden')) {
      ev.preventDefault();
      ev.stopImmediatePropagation();
      hide(createModal);
      return;
    }
    if (joinModal && !joinModal.classList.contains('hidden')) {
      ev.preventDefault();
      ev.stopImmediatePropagation();
      hide(joinModal);
    }
  });

  // ---- Init ----
  loadGroups();
})();
</script>
