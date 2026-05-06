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

<!-- Group list container -->
<div id="groupList" style="display:flex; flex-direction:column; gap:0.75rem;">
  <p style="text-align:center; color:var(--celadon); padding:2rem 0;">Loading groups…</p>
</div>
<p id="groupsEmpty" class="hidden" style="text-align:center; color:#666; font-size:0.9rem; padding:2rem 0;">
  You don't belong to any groups yet. Create one or join using a code!
</p>

<!-- ===== Group Detail Panel (shown when clicking a group) ===== -->
<div id="groupDetail" class="hidden mt-2">
  <div class="card">
    <div class="flex justify-between items-center mb-1">
      <h3 id="gdName" style="font-size:1.1rem; font-weight:700;"></h3>
      <button class="btn btn-icon" id="gdClose">✕</button>
    </div>
    <div class="flex gap-1 items-center mb-2" style="flex-wrap:wrap;">
      <span id="gdCode" style="background:#f5f5f5; padding:0.3rem 0.7rem; border-radius:0.4rem; font-family:monospace; font-size:0.9rem; letter-spacing:1px; color:#000;"></span>
      <button class="btn btn-compact" id="gdCopyCode">Copy Code</button>
      <span id="gdRole" class="exp-badge" style="margin-left:auto;"></span>
    </div>

    <!-- Members -->
    <h4 style="font-size:0.9rem; font-weight:600; color:#333; margin-bottom:0.5rem;">
      Members <span id="gdMemberCount" style="opacity:0.7;"></span>
    </h4>
    <div id="gdMembers" style="display:flex; flex-direction:column; gap:0.4rem; margin-bottom:1rem;"></div>

    <!-- Past Expenses -->
    <div class="flex justify-between items-center" style="margin-bottom:0.5rem;">
      <h4 style="font-size:0.9rem; font-weight:600; color:#333;">Past Group Expenses</h4>
      <button class="exp-pdf-btn" id="gdExpPdf" title="Download PDF" style="display:none;">⬇ PDF</button>
    </div>
    <div id="gdExpenses" style="display:flex; flex-direction:column; gap:0.5rem;"></div>
    <p id="gdExpEmpty" class="hidden" style="text-align:center; color:#666; font-size:0.85rem; padding:1rem 0;">No expenses yet.</p>

    <!-- Actions -->
    <div class="flex gap-1 mt-2" id="gdActions"></div>
  </div>
</div>

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
  .modal-x {
    position:absolute;top:1rem;right:1rem;background:none;border:none;
    color:#666;font-size:1.4rem;cursor:pointer;
  }
  .group-card {
    background: #fff;
    border: 1px solid #e0e0e0;
    border-radius: 0.75rem;
    padding: 0.85rem 1rem;
    cursor: pointer;
    transition: background var(--t-fast), transform var(--t-fast);
  }
  .group-card:hover { background: #f9f9f9; transform: translateY(-2px); }
  .group-card .gc-name { font-weight: 600; font-size: 1rem; color: #000; }
  .group-card .gc-meta { font-size: 0.8rem; color: #666; margin-top: 0.15rem; }

  .member-row {
    display: flex; justify-content: space-between; align-items: center;
    padding: 0.4rem 0.6rem; border-radius: 0.4rem;
    background: rgba(0,0,0,0.03);
  }
  .member-main {
    display: flex; align-items: center; gap: 0.5rem;
  }
  .member-actions {
    display: flex; align-items: center; gap: 0.5rem;
  }
  .member-row .mr-name { font-size: 0.9rem; color: #000; }
  .member-row .mr-role { font-size: 0.75rem; color: #666; }
  .btn-remove-member {
    border: 1px solid #d32f2f;
    background: #fff;
    color: #d32f2f;
    border-radius: 0.35rem;
    padding: 0.2rem 0.5rem;
    font-size: 0.72rem;
    font-weight: 600;
    cursor: pointer;
  }
  .btn-remove-member:hover {
    background: #fdecec;
  }
</style>

<!-- ===== Groups JS (inline — loaded only on this page) ===== -->
<script>
(function() {
  'use strict';
  const esc = escapeHTML; // alias for brevity

  // ---- Load groups ----
  async function loadGroups() {
    const container = $('#groupList');
    const empty     = $('#groupsEmpty');
    hide(empty);
    container.innerHTML = '<p style="text-align:center;color:var(--celadon);padding:1.5rem 0;">Loading…</p>';

    const res = await get(`${API}/groups/user_groups.php`);
    if (!res.ok || res.groups.length === 0) {
      container.innerHTML = '';
      show(empty);
      return;
    }

    container.innerHTML = res.groups.map(g => `
      <div class="group-card" data-id="${g.id}">
        <div class="gc-name">${esc(g.name)}</div>
        <div class="gc-meta">Role: ${g.role === 'admin' ? '👑 Admin' : 'Member'}</div>
      </div>
    `).join('');

    container.querySelectorAll('.group-card').forEach(card => {
      card.addEventListener('click', () => openGroupDetail(card.dataset.id));
    });
  }

  // ---- Group detail ----
  async function openGroupDetail(groupId) {
    const panel = $('#groupDetail');
    show(panel);
    $('#gdMembers').innerHTML = '<p style="color:var(--celadon);font-size:0.85rem;">Loading…</p>';
    $('#gdExpenses').innerHTML = '';
    hide($('#gdExpEmpty'));
    $('#gdActions').innerHTML = '';

    const res = await get(`${API}/groups/details.php?group_id=${groupId}`);
    if (!res.ok) { hide(panel); alert(res.error); return; }

    const g = res.group;
    $('#gdName').textContent = g.name;
    $('#gdCode').textContent = g.join_code;
    $('#gdRole').textContent = res.my_role === 'admin' ? '👑 Admin' : 'Member';
    $('#gdRole').className = 'exp-badge ' + (res.my_role === 'admin' ? 'badge-personal' : 'badge-group');
    $('#gdMemberCount').textContent = `(${res.members.length}/${g.max_members})`;

    // Members
    $('#gdMembers').innerHTML = res.members.map(m => `
      <div class="member-row">
        <div class="member-main">
          <span class="mr-name">${esc(m.username)}</span>
        </div>
        <div class="member-actions">
          <span class="mr-role">${m.role === 'admin' ? '👑 Admin' : 'Member'}</span>
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
      $('#gdExpenses').innerHTML = res.expenses.map(e => `
        <div class="expense-item">
          <div class="exp-left">
            <div class="flex items-center gap-1">
              <span class="exp-amount">${parseFloat(e.amount).toFixed(2)}</span>
              <span class="exp-cat">${esc(e.category_name)}</span>
            </div>
            ${e.note ? `<div class="exp-note">${esc(e.note)}</div>` : ''}
            <div style="font-size:0.75rem; color:#666; margin-top:0.15rem;">${e.expense_date} · paid by ${esc(e.payer_username || e.added_by)}</div>
          </div>
        </div>
      `).join('');
    }

    // Actions
    let actionsHtml = '';
    if (res.my_role === 'admin') {
      actionsHtml += `<button class="btn-danger btn" id="gdDeleteGroup" data-id="${g.id}" style="padding:0.4rem 0.9rem; font-size:0.85rem;">Delete Group</button>`;
    } else {
      actionsHtml += `<button class="btn btn-compact" id="gdLeaveGroup" data-id="${g.id}">Leave Group</button>`;
    }
    $('#gdActions').innerHTML = actionsHtml;

    // Bind action buttons
    const delBtn = $('#gdDeleteGroup');
    if (delBtn) delBtn.addEventListener('click', async () => {
      if (!confirm('Delete this group and all its data? This cannot be undone.')) return;
      const r = await post(`${API}/groups/delete.php`, { group_id: delBtn.dataset.id });
      if (r.ok) { hide(panel); loadGroups(); }
      else alert(r.error);
    });

    const leaveBtn = $('#gdLeaveGroup');
    if (leaveBtn) leaveBtn.addEventListener('click', async () => {
      if (!confirm('Leave this group?')) return;
      const r = await post(`${API}/groups/leave.php`, { group_id: leaveBtn.dataset.id });
      if (r.ok) { hide(panel); loadGroups(); }
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
      e.expense_date,
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
  $('#gdClose').addEventListener('click', () => hide($('#groupDetail')));

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

  // ---- Init ----
  loadGroups();
})();
</script>
