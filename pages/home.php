<?php
// M1 (PENTEST/moderately sever/M1.md): include-only fragment. Direct hits
// leak absolute paths via PHP warnings -- reject them at the door.
if (!defined('EXPMG_PAGE')) { http_response_code(404); exit; }
/**
 * pages/home.php — Calendar-based home view.
 * Included by public/index.php when page=home.
 */
?>

<!-- ===== Home two-column wrapper (desktop >=768px: calendar left, day panel right) ===== -->
<div class="home-two-col">

<!-- ===== Calendar Section ===== -->
<div class="card mb-2" id="calendarCard">
  <div class="flex justify-between items-center mb-1">
    <button class="btn btn-compact" id="prevMonth">
      ← Prev
    </button>
    <h2 id="calendarTitle" style="font-size:1.15rem; font-weight:700;"></h2>
    <button class="btn btn-compact" id="nextMonth">
      Next →
    </button>
  </div>

  <!-- Day-of-week headers -->
  <div class="cal-grid cal-header">
    <span>Sun</span><span>Mon</span><span>Tue</span><span>Wed</span><span>Thu</span><span>Fri</span><span>Sat</span>
  </div>
  <!-- Day cells rendered by JS -->
  <div class="cal-grid" id="calendarGrid"></div>

  <div class="cal-legend-wrap">
    <div class="cal-legend">
      <span class="cal-legend-item"><span class="dot dot-personal"></span> Personal</span>
      <span class="cal-legend-sep">|</span>
      <span class="cal-legend-item"><span class="dot dot-group"></span> Group (Active)</span>
      <span class="cal-legend-sep">|</span>
      <span class="cal-legend-item"><span class="dot dot-settled"></span> Group (Settled)</span>
    </div>
  </div>
</div>

<!-- ===== Selected-date panel ===== -->
<div id="dayPanel" class="hidden">
  <div class="card" style="padding:1.25rem;">
  <div class="flex justify-between items-center mb-1">
    <h3 id="dayPanelDate" style="font-size:1.05rem; font-weight:600; color:var(--text-primary);"></h3>
    <button class="btn" id="btnAddExpense" style="padding:0.45rem 1rem; font-size:0.85rem;">+ Add Expense</button>
  </div>

  <div id="daySummaryRow" class="day-summary-row hidden">
    <div class="day-summary-stat">
      <span class="day-summary-label">Total Spend</span>
      <strong id="daySummaryTotal">₹0.00</strong>
    </div>
    <div class="day-summary-stat" id="daySummaryShareWrap">
      <span class="day-summary-label">Your Share</span>
      <strong id="daySummaryShare">₹0.00</strong>
    </div>
    <div class="day-summary-stat">
      <span class="day-summary-label">Expenses</span>
      <strong id="daySummaryCount">0</strong>
    </div>
  </div>

  <!-- Daily expense list -->
  <div id="dayExpenseList" style="display:flex; flex-direction:column; gap:0.65rem;"></div>
  <p id="dayEmpty" class="hidden" style="text-align:center; color:var(--text-muted); font-size:0.9rem; padding:1.5rem 0;">
    No expenses for this date.
  </p>
  </div>
</div>

</div><!-- /home-two-col -->

<!-- ===== Add / Edit Expense Modal ===== -->
<div id="expenseModal" class="modal-overlay hidden">
  <div class="auth-card" style="max-width:440px; position:relative;">
    <button id="modalClose" class="btn btn-icon" style="position:absolute;top:1rem;right:1rem;">✕</button>
    <h2 id="modalTitle" style="font-size:1.25rem; font-weight:700; margin-bottom:0.75rem;">Add Expense</h2>

    <!-- Expense Type Tab Switcher (pill style, no label) -->
    <div class="modal-type-tabs" id="expTypeTabs">
      <button type="button" class="modal-type-tab active" data-type="personal">Personal</button>
      <button type="button" class="modal-type-tab" data-type="group">Group</button>
    </div>

    <form id="expenseForm">
      <input type="hidden" id="expId" name="id" value="" />
      <input type="hidden" id="expDate" name="expense_date" value="" />
      <input type="hidden" id="expTypeValue" name="type" value="personal" />

      <!-- Group Select (Group tab only, shown first) -->
      <div class="form-group hidden" id="groupSelectWrap">
        <label for="expGroup">Group</label>
        <select id="expGroup" name="group_id" class="form-input">
          <option value="">Select group…</option>
        </select>
      </div>

      <!-- Description (was Note) -->
      <div class="form-group">
        <label for="expNote">Description</label>
        <input type="text" id="expNote" name="note" class="form-input" placeholder="Short description" maxlength="255" />
      </div>

      <!-- Amount -->
      <div class="form-group">
        <label for="expAmount">Amount</label>
        <input type="number" step="0.01" min="0.01" id="expAmount" name="amount" class="form-input" placeholder="0.00" required />
      </div>

      <!-- Category -->
      <div class="form-group">
        <label for="expCategory">Category</label>
        <select id="expCategory" name="category_id" class="form-input">
          <option value="">General</option>
        </select>
      </div>

      <!-- Paid By (Group tab only, shown last) -->
      <div class="form-group hidden" id="paidByWrap">
        <label for="expPaidBy">Paid By</label>
        <select id="expPaidBy" name="paid_by" class="form-input">
          <option value="">Select who paid…</option>
        </select>
      </div>

      <div id="expError" class="alert alert-error hidden" style="margin-bottom:0.75rem;"></div>

      <div class="flex gap-1">
        <button type="submit" class="btn w-full">Save</button>
        <button type="button" class="btn w-full" id="btnCancelExpense">Cancel</button>
      </div>
    </form>
  </div>
</div>

<!-- ===== Calendar + modal CSS ===== -->
<style>
  .cal-grid {
    display: grid;
    grid-template-columns: repeat(7, 1fr);
    gap: 5px;
    text-align: center;
  }
  .cal-header span {
    font-size: 0.7rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    color: var(--text-muted);
    padding: 0.3rem 0;
  }
  .cal-legend-wrap {
    margin-top: 0.85rem;
    padding-top: 0.75rem;
    border-top: 1px solid var(--border-soft);
  }
  .cal-legend {
    display: flex;
    flex-wrap: wrap;
    gap: 0.75rem 1rem;
    align-items: center;
    justify-content: center;
    font-size: 0.75rem;
    color: var(--text-secondary);
  }
  .cal-legend-item {
    display: inline-flex;
    align-items: center;
    gap: 0.35rem;
    white-space: nowrap;
  }
  .cal-legend-sep {
    color: var(--border-medium);
    font-weight: 600;
  }
  .cal-legend .dot {
    width: 4.5px;
    height: 4.5px;
    border-radius: 50%;
    display: inline-block;
  }
  .cal-legend .dot-personal { background: var(--accent-primary); }
  .cal-legend .dot-group { background: var(--accent-secondary); }
  .cal-legend .dot-settled { background: #94a3b8; }

  .day-summary-row {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 0.65rem;
    margin: 0.75rem 0 1rem;
  }
  .day-summary-stat {
    display: flex;
    flex-direction: column;
    gap: 0.15rem;
    padding: 0.75rem 0.85rem;
    border-radius: var(--radius-md);
    background: var(--bg-subtle);
  }
  .day-summary-label {
    font-size: 0.72rem;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    color: var(--text-muted);
  }
  .day-summary-stat strong {
    font-family: var(--font-mono);
    font-variant-numeric: tabular-nums;
    font-size: 0.95rem;
    color: var(--text-primary);
    line-height: 1.2;
  }
  #daySummaryShareWrap.hidden { display: none !important; }
  .cal-day {
    aspect-ratio: 1;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    border-radius: 9px;
    font-family: var(--font-mono);
    font-variant-numeric: tabular-nums;
    font-size: 13px;
    font-weight: 600;
    color: var(--text-primary);
    cursor: pointer;
    transition: background var(--t-fast), border-color var(--t-fast);
    position: relative;
    background: var(--bg-subtle);
    overflow: visible;
    --cal-day-text: var(--text-primary);
    --cal-day-subtext: var(--text-secondary);
  }
  .cal-day:hover { background: var(--accent-soft); }
  .cal-day.today {
    background: var(--accent-soft);
    color: var(--accent-primary);
    --cal-day-text: var(--accent-primary);
    --cal-day-subtext: var(--accent-primary);
  }
  .cal-day.selected {
    background: var(--accent-primary);
    color: var(--text-on-accent);
    font-weight: 700;
    --cal-day-text: var(--text-on-accent);
    --cal-day-subtext: var(--text-on-accent);
  }
  .cal-day.empty { background: transparent; cursor: default; }
  .cal-day.empty:hover { background: transparent; }
  .cal-day .cal-day-number {
    font-size: 0.92rem;
    line-height: 1;
  }
  .cal-day .cal-amount-stack {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 0.1rem;
    margin-top: 0.22rem;
    min-height: 1.7rem;
  }
  .cal-day .cal-amount-line {
    font-size: 0.62rem;
    line-height: 1.1;
    letter-spacing: 0.01em;
    white-space: nowrap;
    max-width: 100%;
    overflow: hidden;
    text-overflow: ellipsis;
  }
  .cal-day .cal-amount-personal {
    color: var(--cal-day-text);
    font-weight: 700;
  }
  .cal-day .cal-amount-group {
    color: var(--cal-day-subtext);
    font-weight: 700;
  }
  .cal-day .cal-amount-settled {
    text-decoration: line-through;
    text-decoration-thickness: 1.5px;
    text-decoration-color: currentColor;
    opacity: 0.9;
  }
  .cal-day .dot-row {
    display: flex;
    gap: 3px;
    position: absolute;
    bottom: 2px;
    left: 50%;
    transform: translateX(-50%);
  }
  .cal-day .dot {
    width: 4.5px; height: 4.5px; border-radius: 50%;
  }
  .cal-day .dot-personal { background: var(--accent-primary); }
  .cal-day .dot-group    { background: var(--accent-secondary); }
  .cal-day .dot-settled  { background: #94a3b8; }
  .cal-day.selected .dot-personal,
  .cal-day.selected .dot-group,
  .cal-day.selected .dot-settled { background: var(--text-on-accent); opacity: 0.9; }

  /* Modal expense type tab switcher (pill style) */
  .modal-type-tabs {
    display: flex;
    background: var(--bg-subtle);
    border: 1px solid var(--border-soft);
    border-radius: var(--radius-md);
    padding: 0.25rem;
    margin-bottom: 1rem;
    gap: 0.25rem;
  }
  .modal-type-tab {
    flex: 1;
    padding: 0.55rem 1rem;
    font-size: 0.88rem;
    font-weight: 600;
    border: none;
    cursor: pointer;
    border-radius: var(--radius-sm);
    transition: background 0.25s, color 0.25s, box-shadow 0.25s;
    background: transparent;
    color: var(--text-muted);
    font-family: inherit;
  }
  .modal-type-tab.active {
    background: var(--bg-surface);
    color: var(--text-primary);
    box-shadow: var(--shadow-card);
  }
  .modal-type-tab:not(.active):hover {
    color: var(--text-secondary);
  }

  /* .modal-overlay, .expense-item rows, .exp-badge, .badge-personal/.badge-group
     and .exp-pdf-btn now live in public/assets/css/styles.css (shared components)
     so Lists, Groups, and Expenses pages render them consistently. */

  /* Expense status dots */
  .exp-status-dot {
    flex-shrink: 0;
    width: 10px; height: 10px;
    border-radius: 50%;
    margin-top: 0.45rem;
  }
  .exp-dot-personal { background: var(--accent-primary); }
  .exp-dot-group    { background: var(--accent-secondary); }
  .exp-dot-settled  { background: #94a3b8; }
</style>
