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
  <div class="flex justify-between items-center mb-1">
    <h3 id="dayPanelDate" style="font-size:1.05rem; font-weight:600;"></h3>
    <button class="btn" id="btnAddExpense" style="padding:0.45rem 1rem; font-size:0.85rem;">+ Add Expense</button>
  </div>

  <!-- Daily expense list -->
  <div id="dayExpenseList" style="display:flex; flex-direction:column; gap:0.65rem;"></div>
  <p id="dayEmpty" class="hidden" style="text-align:center; color:var(--celadon); font-size:0.9rem; padding:1.5rem 0;">
    No expenses for this date.
  </p>
</div>

<!-- ===== Add / Edit Expense Modal ===== -->
<div id="expenseModal" class="modal-overlay hidden">
  <div class="auth-card" style="max-width:440px; position:relative;">
    <button id="modalClose" style="position:absolute;top:1rem;right:1rem;background:none;border:none;color:var(--celadon);font-size:1.4rem;cursor:pointer;">✕</button>
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
    gap: 4px;
    text-align: center;
  }
  .cal-header span {
    font-size: 0.75rem;
    font-weight: 600;
    color: var(--celadon);
    padding: 0.3rem 0;
  }
  .cal-day {
    aspect-ratio: 1;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    border-radius: 0.5rem;
    font-size: 0.85rem;
    cursor: pointer;
    transition: background var(--t-fast), transform var(--t-fast);
    position: relative;
  }
  .cal-day:hover { background: rgba(255,255,255,0.1); }
  .cal-day.today { border: 2px solid var(--mint-leaf); }
  .cal-day.selected { background: var(--grad-btn); color: #fff; font-weight: 700; }
  .cal-day.empty { cursor: default; }
  .cal-day .dot {
    width: 5px; height: 5px; border-radius: 50%; background: var(--light-mint);
    position: absolute; bottom: 4px;
  }

  /* Modal overlay */
  .modal-overlay {
    position: fixed; inset: 0;
    background: rgba(8,28,21,0.75);
    display: flex; align-items: center; justify-content: center;
    z-index: 2000;
    padding: 1rem;
  }

  /* Expense cards in day list */
  .expense-item {
    background: rgba(255,255,255,0.06);
    border-radius: 0.75rem;
    padding: 0.85rem 1rem;
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    transition: background var(--t-fast);
  }
  .expense-item:hover { background: rgba(255,255,255,0.1); }
  .expense-item .exp-left { flex: 1; }
  .expense-item .exp-amount { font-size: 1.1rem; font-weight: 700; color: var(--light-mint); }
  .expense-item .exp-cat { font-size: 0.78rem; color: var(--celadon); }
  .expense-item .exp-note { font-size: 0.85rem; margin-top: 0.15rem; }
  .expense-item .exp-badge {
    font-size: 0.7rem;
    padding: 0.15rem 0.45rem;
    border-radius: 999px;
    font-weight: 600;
  }
  .badge-personal { background: rgba(82,183,136,0.2); color: var(--mint-leaf); }
  .badge-group    { background: rgba(116,198,157,0.2); color: var(--light-mint); }
  .expense-item .exp-actions { display: flex; gap: 0.4rem; margin-top: 0.35rem; }
  .expense-item .exp-actions button {
    background: none; border: 1px solid rgba(255,255,255,0.15);
    color: var(--celadon); font-size: 0.75rem; padding: 0.2rem 0.5rem;
    border-radius: 0.35rem; cursor: pointer; transition: all var(--t-fast);
  }
  .expense-item .exp-actions button:hover { border-color: var(--mint-leaf); color: #fff; }
  .expense-item .exp-actions .btn-del:hover { border-color: #ef4444; color: #fca5a5; }
</style>
