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
