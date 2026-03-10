<?php
/**
 * pages/home.php — Calendar-based home view.
 * Included by public/index.php when page=home.
 */
?>

<!-- ===== Calendar Section ===== -->
<div class="card mb-2" id="calendarCard">
  <div class="flex justify-between items-center mb-1">
    <button class="btn-outline" style="padding:0.3rem 0.7rem; font-size:0.85rem; border-radius:0.4rem;" id="prevMonth">
      ← Prev
    </button>
    <h2 id="calendarTitle" style="font-size:1.15rem; font-weight:700;"></h2>
    <button class="btn-outline" style="padding:0.3rem 0.7rem; font-size:0.85rem; border-radius:0.4rem;" id="nextMonth">
      Next →
    </button>
  </div>

  <!-- Day-of-week headers -->
  <div class="cal-grid cal-header">
    <span>Sun</span><span>Mon</span><span>Tue</span><span>Wed</span><span>Thu</span><span>Fri</span><span>Sat</span>
  </div>
  <!-- Day cells rendered by JS -->
  <div class="cal-grid" id="calendarGrid"></div>
</div>

<!-- ===== Selected-date panel ===== -->
<div id="dayPanel" class="hidden">
  <div class="card" style="padding:1.25rem;">
  <div class="flex justify-between items-center mb-1">
    <h3 id="dayPanelDate" style="font-size:1.05rem; font-weight:600; color:#000;"></h3>
    <button class="btn" id="btnAddExpense" style="padding:0.45rem 1rem; font-size:0.85rem;">+ Add Expense</button>
  </div>

  <!-- Daily expense list -->
  <div id="dayExpenseList" style="display:flex; flex-direction:column; gap:0.65rem;"></div>
  <p id="dayEmpty" class="hidden" style="text-align:center; color:#666; font-size:0.9rem; padding:1.5rem 0;">
    No expenses for this date.
  </p>
  </div>
</div>

<!-- ===== Add / Edit Expense Modal ===== -->
<div id="expenseModal" class="modal-overlay hidden">
  <div class="auth-card" style="max-width:440px; position:relative;">
    <button id="modalClose" style="position:absolute;top:1rem;right:1rem;background:none;border:none;color:#666;font-size:1.4rem;cursor:pointer;">✕</button>
    <h2 id="modalTitle" style="font-size:1.25rem; font-weight:700; margin-bottom:1rem;">Add Expense</h2>

    <form id="expenseForm">
      <input type="hidden" id="expId" name="id" value="" />
      <input type="hidden" id="expDate" name="expense_date" value="" />

      <div class="form-group">
        <label for="expAmount">Amount</label>
        <input type="number" step="0.01" min="0.01" id="expAmount" name="amount" class="form-input" placeholder="0.00" required />
      </div>

      <div class="form-group">
        <label for="expCategory">Category</label>
        <select id="expCategory" name="category_id" class="form-input" required>
          <option value="">Select category…</option>
        </select>
      </div>

      <div class="form-group">
        <label for="expNote">Note</label>
        <input type="text" id="expNote" name="note" class="form-input" placeholder="Short description" maxlength="255" />
      </div>

      <div class="form-group">
        <label>Expense Type</label>
        <div class="flex gap-1 mt-1">
          <label style="display:flex;align-items:center;gap:0.35rem;cursor:pointer;">
            <input type="radio" name="type" value="personal" checked /> Personal
          </label>
          <label style="display:flex;align-items:center;gap:0.35rem;cursor:pointer;">
            <input type="radio" name="type" value="group" /> Group
          </label>
        </div>
      </div>

      <div class="form-group hidden" id="groupSelectWrap">
        <label for="expGroup">Group</label>
        <select id="expGroup" name="group_id" class="form-input">
          <option value="">Select group…</option>
        </select>
      </div>

      <div class="form-group hidden" id="paidByWrap">
        <label for="expPaidBy">Paid By</label>
        <select id="expPaidBy" name="paid_by" class="form-input">
          <option value="">Select who paid…</option>
        </select>
      </div>

      <div id="expError" class="alert alert-error hidden" style="margin-bottom:0.75rem;"></div>

      <div class="flex gap-1">
        <button type="submit" class="btn w-full">Save</button>
        <button type="button" class="btn-outline w-full" id="btnCancelExpense" style="padding:0.65rem;">Cancel</button>
      </div>
    </form>
  </div>
</div>

<!-- ===== Calendar + modal CSS ===== -->
<style>
  .cal-grid {
    display: grid;
    grid-template-columns: repeat(7, 1fr);
    gap: 6px;
    text-align: center;
  }
  .cal-header span {
    font-size: 0.75rem;
    font-weight: 600;
    color: #000;
    padding: 0.3rem 0;
  }
  .cal-day {
    aspect-ratio: 1;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    border-radius: 0.6rem;
    font-size: 0.85rem;
    font-weight: 600;
    color: #333;
    cursor: pointer;
    transition: background var(--t-fast), transform var(--t-fast);
    position: relative;
    background: #f0f0f0;
    overflow: visible;
  }
  .cal-day:hover { background: #e4e4e4; }
  .cal-day.today { background: rgba(82,183,136,0.45); color: #fff; }
  .cal-day.selected { background: var(--mint-leaf); color: #fff; font-weight: 700; }
  .cal-day.empty { background: transparent; cursor: default; }
  .cal-day.empty:hover { background: transparent; }
  .cal-day .dot-row {
    display: flex;
    gap: 3px;
    position: absolute;
    bottom: 2px;
    left: 50%;
    transform: translateX(-50%);
  }
  .cal-day .dot {
    width: 6px; height: 6px; border-radius: 50%;
  }
  .cal-day .dot-personal { background: #3b82f6; }
  .cal-day .dot-group    { background: #52b788; }
  .cal-day .dot-settled  { background: #aaa; }
  .cal-day.today .dot-personal,
  .cal-day.selected .dot-personal { background: #93bbfd; }
  .cal-day.today .dot-group,
  .cal-day.selected .dot-group    { background: #a8dfc4; }
  .cal-day.today .dot-settled,
  .cal-day.selected .dot-settled  { background: #ddd; }

  /* Modal overlay */
  .modal-overlay {
    position: fixed; inset: 0;
    background: rgba(0,0,0,0.55);
    display: flex; align-items: center; justify-content: center;
    z-index: 2000;
    padding: 1rem;
  }

  /* Expense cards in day list */
  .expense-item {
    background: rgba(0,0,0,0.03);
    border-radius: 0.75rem;
    padding: 0.85rem 1rem;
    display: flex;
    gap: 0.75rem;
    align-items: flex-start;
    transition: background var(--t-fast);
  }
  .expense-item:hover { background: rgba(0,0,0,0.06); }
  .expense-item .exp-left { flex: 1; }
  .expense-item .exp-amount { font-size: 1.1rem; font-weight: 700; color: #000; }
  .expense-item .exp-cat { font-size: 0.78rem; color: #666; }
  .expense-item .exp-note { font-size: 0.85rem; margin-top: 0.15rem; color: #333; }
  .expense-item .exp-badge {
    font-size: 0.7rem;
    padding: 0.15rem 0.45rem;
    border-radius: 999px;
    font-weight: 600;
  }
  .badge-personal { background: rgba(82,183,136,0.15); color: var(--sea-green); }
  .badge-group    { background: rgba(116,198,157,0.15); color: var(--dark-emerald); }
  .expense-item .exp-actions { display: flex; gap: 0.4rem; margin-top: 0.35rem; }
  .expense-item .exp-actions button {
    background: none; border: 1px solid #ccc;
    color: #555; font-size: 0.75rem; padding: 0.2rem 0.5rem;
    border-radius: 0.35rem; cursor: pointer; transition: all var(--t-fast);
  }
  .expense-item .exp-actions button:hover { border-color: var(--mint-leaf); color: #000; }
  .expense-item .exp-actions .btn-del:hover { border-color: #ef4444; color: #ef4444; }

  /* Expense status dots */
  .exp-status-dot {
    flex-shrink: 0;
    width: 10px; height: 10px;
    border-radius: 50%;
    margin-top: 0.45rem;
  }
  .exp-dot-personal { background: #3b82f6; box-shadow: 0 0 4px rgba(59,130,246,0.4); }
  .exp-dot-group    { background: #52b788; box-shadow: 0 0 4px rgba(82,183,136,0.4); }
  .exp-dot-settled  { background: #aaa;    box-shadow: 0 0 4px rgba(170,170,170,0.3); }
</style>
