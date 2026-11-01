<?php
// M1 (PENTEST/moderately sever/M1.md): include-only fragment. Direct hits
// leak absolute paths via PHP warnings -- reject them at the door.
if (!defined('EXPMG_PAGE')) { http_response_code(404); exit; }
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

<!-- Master-detail wrapper (desktop >=768px: split view while a detail panel is open) -->
<div id="listsMasterDetail">

<!-- List cards container -->
<div id="listContainer" style="display:flex; flex-direction:column; gap:0.75rem;"></div>
<p id="listsEmpty" class="hidden" style="text-align:center; color:var(--text-muted); font-size:0.9rem; padding:2rem 0;">
  No lists yet. Create one to start tracking what you need to buy!
</p>

<!-- ===== List Detail Panel ===== -->
<div id="listDetail" class="hidden mt-2">
  <div class="card">
    <div class="flex justify-between items-center mb-1">
      <h3 id="ldName" style="font-size:1.1rem; font-weight:700;"></h3>
      <div class="flex gap-1">
        <button class="btn" id="ldAddItem" style="padding:0.3rem 0.7rem; font-size:0.8rem;">+ Add Item</button>
        <button class="btn btn-icon" id="ldDeleteList" title="Delete this list" style="background:transparent;border:1px solid var(--danger-600);color:var(--danger-600);">
          <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/><line x1="10" y1="11" x2="10" y2="17"/><line x1="14" y1="11" x2="14" y2="17"/></svg>
        </button>
        <button class="btn btn-icon" id="ldClose">✕</button>
      </div>
    </div>
    <div class="flex gap-1 items-center mb-2" style="flex-wrap:wrap;">
      <span id="ldType" class="exp-badge"></span>
      <span id="ldCreator" style="font-size:0.8rem; color:var(--text-muted);"></span>
    </div>

    <!-- Priority sections -->
    <div id="ldItems"></div>
    <p id="ldItemsEmpty" class="hidden" style="text-align:center; color:var(--text-muted); font-size:0.85rem; padding:1.5rem 0;">
      This list is empty. Add items to get started.
    </p>

    <!-- Delete List moved to the detail header as a subtle outlined danger icon
         (with modal confirmation) so it no longer sits under the item checkboxes. -->
  </div>
</div>
</div><!-- /listsMasterDetail -->

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
          <label class="radio-pill">
            <input type="radio" name="list_type" value="personal" checked /> Personal
          </label>
          <label class="radio-pill">
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
        <label for="aiPrice">Price <span style="font-weight:400;color:var(--text-muted);">(Optional)</span></label>
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
    <button class="modal-x" id="ccCloseX" title="Close">✕</button>
    <h2 style="font-size:1.2rem; font-weight:700; margin-bottom:1rem;">Confirm Purchase</h2>
    <form id="checkConfirmForm">
      <input type="hidden" id="ccItemId" value="" />
      <div class="form-group">
        <label>Item</label>
        <input type="text" id="ccItemName" class="form-input" readonly style="background:var(--bg-subtle);" />
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
        <input type="text" id="ccDate" class="form-input" readonly style="background:var(--bg-subtle);" />
      </div>
      <div id="ccError" class="alert alert-error hidden"></div>
      <div class="flex gap-1">
        <button type="submit" class="btn w-full">Confirm</button>
        <button type="button" class="btn w-full" id="ccCancel">Cancel</button>
      </div>
    </form>
  </div>
</div>

<!-- ===== Lists CSS ===== -->
<style>
  .list-card {
    background: var(--bg-surface);
    border: 1px solid var(--border-soft);
    border-radius: var(--radius-lg);
    padding: 0.85rem 1rem;
    cursor: pointer;
    transition: background var(--t-fast), border-color var(--t-fast);
  }
  .list-card:hover { background: var(--bg-subtle); }
  .list-card .lc-name { font-weight: 600; font-size: 1rem; color: var(--text-primary); }
  .list-card .lc-meta { font-size: 0.8rem; color: var(--text-muted); margin-top: 0.15rem; }
  .list-card .lc-progress {
    height: 6px; border-radius: var(--radius-pill); background: var(--bg-subtle);
    margin-top: 0.5rem; overflow: hidden;
  }
  .list-card .lc-progress-bar { height: 100%; background: var(--accent-primary); border-radius: var(--radius-pill); transition: width 0.3s ease; }

  .priority-label {
    font-size: 0.75rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px;
    padding: 0.6rem 0 0.3rem; border-bottom: 1px solid var(--border-soft);
    margin-bottom: 0.4rem;
  }
  .priority-label.pl-high    { color: var(--amber-800); }
  .priority-label.pl-mod     { color: var(--accent-primary); }
  .priority-label.pl-low     { color: var(--text-muted); }

  .list-item-row {
    display: flex; align-items: center; gap: 0.6rem;
    padding: 0.5rem 0.6rem; border-radius: var(--radius-sm);
    transition: background var(--t-fast);
  }
  .list-item-row:hover { background: var(--bg-subtle); }
  .list-item-row.checked .li-desc { text-decoration: line-through; opacity: 0.5; }
  .li-check {
    width: 20px; height: 20px; border-radius: var(--radius-sm);
    border: 2px solid var(--border-medium); background: none;
    cursor: pointer; flex-shrink: 0;
    display: flex; align-items: center; justify-content: center;
    transition: all var(--t-fast); color: transparent; font-size: 0.8rem;
  }
  .li-check.is-checked { background: var(--accent-primary); border-color: var(--accent-primary); color: var(--text-on-accent); }
  .li-desc { flex: 1; font-size: 0.9rem; color: var(--text-primary); }
  .li-cat  { font-size: 0.75rem; color: var(--text-muted); }
  .li-price {
    font-size: 0.78rem; font-weight: 600; color: var(--accent-primary); white-space: nowrap;
    font-family: var(--font-mono);
    font-variant-numeric: tabular-nums;
  }
  .li-remove {
    background: none; border: 1px solid var(--border-medium);
    color: var(--text-muted); font-size: 0.7rem; padding: 0.15rem 0.4rem;
    border-radius: var(--radius-sm); cursor: pointer; transition: all var(--t-fast);
  }
  .li-remove:hover { border-color: var(--danger-600); color: var(--danger-600); }

  .li-lock {
    font-size: 0.75rem; opacity: 0.6;
  }
  .li-timer {
    font-size: 0.72rem; font-weight: 600; color: var(--amber-800);
    background: var(--amber-50); padding: 0.1rem 0.4rem;
    border-radius: var(--radius-sm); white-space: nowrap;
    font-family: var(--font-mono);
    font-variant-numeric: tabular-nums;
  }
  .li-check.li-locked {
    opacity: 0.4; cursor: not-allowed;
  }

  /* Priority selector pills — highlighted when checked (see .radio-pill in
     the global stylesheet for the Personal/Group list-type pills). */
  .priority-radio {
    display: flex; align-items: center; gap: 0.35rem; cursor: pointer;
    padding: 0.45rem 0.8rem; border-radius: var(--radius-sm); font-size: 0.85rem;
    border: 1px solid var(--border-soft); transition: all var(--t-fast);
    color: var(--text-secondary); background: var(--bg-subtle);
  }
  .priority-radio:hover { border-color: var(--accent-border); }
  .priority-radio:has(input:checked) {
    background: var(--accent-soft);
    border-color: var(--accent-primary);
  }
  .pr-high  { color: var(--amber-800); }
  .pr-mod   { color: var(--accent-primary); }
  .pr-low   { color: var(--text-muted); }

  /* .modal-x moved to public/assets/css/styles.css (shared components) */
</style>

<!-- ===== Lists JS ===== -->
<script src="assets/js/lists.js?v=<?= @filemtime(__DIR__ . '/../public/assets/js/lists.js') ?: 1 ?>"></script>
