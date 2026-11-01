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

// M2/L-4: CSP-safe delegated modal close (replaces inline onclick).
document.addEventListener('click', function (e) {
  var btn = e.target.closest('[data-close-modal]');
  if (!btn) return;
  var modal = document.getElementById(btn.dataset.closeModal);
  if (modal) modal.classList.add('hidden');
});
