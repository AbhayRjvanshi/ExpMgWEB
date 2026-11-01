<?php
// M1 (PENTEST/moderately sever/M1.md): include-only fragment. Direct hits
// leak absolute paths via PHP warnings -- reject them at the door.
if (!defined('EXPMG_PAGE')) { http_response_code(404); exit; }
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
    <button class="modal-x" data-close-modal="createGroupModal">✕</button>
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
    <button class="modal-x" data-close-modal="joinGroupModal">✕</button>
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
<script src="../public/assets/js/groups.js"></script>
