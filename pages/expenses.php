<?php
// M1 (PENTEST/moderately sever/M1.md): include-only fragment. Direct hits
// leak absolute paths via PHP warnings -- reject them at the door.
if (!defined('EXPMG_PAGE')) { http_response_code(404); exit; }
/**
 * pages/expenses.php - Spending history, charts, budget tracking & group settlement.
 * Included by public/index.php when page=expenses.
 * Uses Chart.js (loaded in index.php head).
 */
$currentUsername = htmlspecialchars($_SESSION['username']);
$currentUserId  = (int) $_SESSION['user_id'];
$firstName = explode(' ', $currentUsername)[0];
?>

<!-- ========== Tab Switcher ========== -->
<div class="exp-tabs-container">
  <button id="tabMyExpenses" class="exp-tab active" data-exp-tab="my">My Expenses</button>
  <button id="tabSettlement" class="exp-tab" data-exp-tab="settlement">Settlement</button>
</div>

<!-- ==================== MY EXPENSES TAB ==================== -->
<div id="panelMyExpenses">

<!-- Inline Date Filter Row -->
<div class="exp-date-filter-row">
  <div class="exp-date-input-wrap">
    <input type="date" id="expStartDate" class="exp-date-input" />
  </div>
  <span class="exp-date-to">to</span>
  <div class="exp-date-input-wrap">
    <input type="date" id="expEndDate" class="exp-date-input" />
  </div>
  <button id="expDateFilterBtn" class="exp-date-apply" title="Apply">&#10003;</button>
  <button id="expDateClearBtn" class="exp-date-clear hidden" title="Clear">&#10005;</button>
</div>

<!-- Custom Range Active Banner (shown only while a custom date range is applied) -->
<div id="expCustomRangeBanner" class="hidden" style="display:flex;align-items:center;justify-content:space-between;gap:0.75rem;background:var(--accent-soft);border:1px solid var(--accent-border);border-radius:0.55rem;padding:0.55rem 0.85rem;margin-bottom:1rem;">
  <span id="expCustomRangeLabel" style="font-size:0.85rem;font-weight:600;color:var(--accent-primary);"></span>
  <button id="expCustomRangeReset" style="border:none;background:var(--danger-600);color:#fff;border-radius:0.4rem;padding:0.3rem 0.75rem;font-size:0.78rem;font-weight:600;cursor:pointer;">Reset &times;</button>
</div>

<!-- Month Selector (primary period control — governs budget, breakdown, charts, and lists) -->
<div id="expMonthNav" style="display:flex;align-items:center;justify-content:center;gap:1rem;margin-bottom:1.25rem;">
  <button id="expPrevMonth" class="btn btn-icon" aria-label="Previous month">&#8592;</button>
  <h2 id="expMonthLabel" style="font-size:1.15rem;font-weight:700;min-width:160px;text-align:center;"></h2>
  <button id="expNextMonth" class="btn btn-icon" aria-label="Next month">&#8594;</button>
</div>

<!-- Monthly Budget Card -->
<div class="card" id="budgetCard" style="padding:1.25rem;margin-bottom:1rem;">
  <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:0.6rem;">
    <div>
      <div style="font-size:0.72rem;text-transform:uppercase;letter-spacing:0.04em;color:var(--text-muted);">Monthly Budget</div>
      <div id="budgetAmountDisplay" class="money-lg" style="color:var(--text-primary);">&mdash;</div>
    </div>
    <div style="text-align:right;">
      <div style="font-size:0.72rem;text-transform:uppercase;letter-spacing:0.04em;color:var(--text-muted);">Spent</div>
      <div id="budgetSpentDisplay" class="money-lg" style="color:var(--text-primary);">&mdash;</div>
    </div>
  </div>
  <div id="budgetProgressWrap" style="margin-bottom:0.5rem;">
    <div style="height:10px;background:var(--bg-subtle);border-radius:999px;overflow:hidden;">
      <div id="budgetProgressFill" style="height:100%;border-radius:999px;background:var(--accent-primary);width:0%;transition:width 0.8s ease;"></div>
    </div>
    <div style="display:flex;justify-content:space-between;font-size:0.72rem;color:var(--text-muted);margin-top:0.3rem;">
      <span id="budgetPctLabel">0% Used</span>
      <span id="budgetRemainingLabel">&#8377;0.00 Remaining</span>
    </div>
  </div>
  <div style="display:flex;justify-content:flex-end;">
    <button id="expSetBudgetBtn" class="btn btn-compact">Set Budget &gt;</button>
  </div>
</div>

<!-- Summary Cards Row -->
<div id="expSummaryRow" class="exp-summary-grid">
  <div class="card is-hidden-monthly" id="expTotalSpentCard" style="padding:1.25rem;">
    <div style="font-size:0.78rem;text-transform:uppercase;letter-spacing:0.04em;color:var(--text-muted);margin-bottom:0.35rem;">Total Spent</div>
    <div id="expTotalSpent" class="money-lg" style="color:var(--text-primary);">&mdash;</div>
  </div>
  <div class="card" style="padding:1.25rem;">
    <div style="font-size:0.78rem;text-transform:uppercase;letter-spacing:0.04em;color:var(--text-muted);margin-bottom:0.35rem;">Breakdown</div>
    <div style="display:flex;gap:1.5rem;margin-top:0.35rem;flex-wrap:wrap;">
      <div>
        <div style="font-size:0.72rem;color:var(--text-muted);">Personal</div>
        <div id="expPersonal" class="money-md" style="color:var(--text-primary);">&mdash;</div>
      </div>
      <div>
        <div style="font-size:0.72rem;color:var(--text-muted);">Group</div>
        <div id="expGroup" class="money-md" style="color:var(--text-primary);">&mdash;</div>
      </div>
      <div id="expShareWrap" class="hidden">
        <div style="font-size:0.72rem;color:var(--text-muted);">Your Share</div>
        <div id="expShare" class="money-md" style="color:var(--text-primary);">&mdash;</div>
      </div>
    </div>
  </div>
</div>

<!-- Charts Row -->
<div id="expChartsRow" class="exp-charts-grid">
  <div class="card" style="padding:1.25rem;">
    <div style="font-size:0.82rem;font-weight:600;margin-bottom:0.75rem;">By Category</div>
    <div style="position:relative;max-width:220px;margin:0 auto;">
      <canvas id="chartCategory"></canvas>
    </div>
    <p id="chartCatEmpty" class="hidden" style="text-align:center;color:var(--text-muted);font-size:0.82rem;padding:2rem 0;">No data this month.</p>
  </div>
  <div class="card" style="padding:1.25rem;">
    <div style="font-size:0.82rem;font-weight:600;margin-bottom:0.75rem;">Daily Spending</div>
    <canvas id="chartDaily"></canvas>
    <p id="chartDayEmpty" class="hidden" style="text-align:center;color:var(--text-muted);font-size:0.82rem;padding:2rem 0;">No data this month.</p>
  </div>
</div>

<!-- Month's Expenses - Sort Controls + Two Column Layout -->
<div class="card" style="padding:1.25rem;">
  <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:0.75rem;flex-wrap:wrap;gap:0.5rem;">
    <span style="font-size:0.82rem;font-weight:600;">Month's Expenses</span>
    <div class="exp-sort-controls">
      <select id="expSortField" class="exp-sort-select">
        <option value="date">Date</option>
        <option value="name">Name</option>
        <option value="amount">Amount</option>
        <option value="category">Category</option>
      </select>
      <select id="expSortOrder" class="exp-sort-select">
        <option value="desc">Descending</option>
        <option value="asc">Ascending</option>
      </select>
    </div>
  </div>
  <div class="exp-two-col">
    <div class="exp-col">
      <div class="exp-col-header">
        <span class="exp-col-dot exp-col-dot--personal"></span> Personal Expenses
        <button id="expPersonalPdf" class="exp-pdf-btn" title="Download PDF"><svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:-2px;"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/><path d="M12 18v-6"/><path d="m9 15 3 3 3-3"/></svg> PDF</button>
      </div>
      <div id="expPersonalList" class="exp-col-body"></div>
      <p id="expPersonalEmpty" class="hidden exp-col-empty">No personal expenses.</p>
    </div>
    <div class="exp-col">
      <div class="exp-col-header">
        <span class="exp-col-dot exp-col-dot--group"></span> Group Expenses
        <button id="expGroupPdf" class="exp-pdf-btn" title="Download PDF"><svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:-2px;"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/><path d="M12 18v-6"/><path d="m9 15 3 3 3-3"/></svg> PDF</button>
      </div>
      <div id="expGroupList" class="exp-col-body"></div>
      <p id="expGroupEmpty" class="hidden exp-col-empty">No group expenses.</p>
    </div>
  </div>
</div>

<!-- Unpriced Items Section -->
<div class="card unpriced-card" style="padding:1.25rem;margin-top:1rem;">
  <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:0.75rem;">
    <span style="font-size:0.82rem;font-weight:600;">Unpriced Items</span>
    <span class="unpriced-badge" id="unpricedCount" style="display:none;">0</span>
  </div>
  <p style="font-size:0.72rem;color:var(--text-muted);margin-bottom:0.75rem;">
    Items checked from lists without a price. Add a price to convert them into expenses.
  </p>
  <div id="unpricedListContainer"></div>
  <p id="unpricedListEmpty" class="hidden" style="text-align:center;color:var(--text-muted);font-size:0.82rem;padding:1.5rem 0;">No unpriced items pending.</p>
</div>

</div><!-- /panelMyExpenses -->

<!-- ==================== SETTLEMENT TAB ==================== -->
<div id="panelSettlement" class="hidden">

<!-- Group Selection Card -->
<div class="card" style="padding:1.25rem;margin-bottom:1rem;">
  <label style="font-size:0.82rem;font-weight:600;display:block;margin-bottom:0.5rem;">Select Group</label>
  <select id="settlGroupSelect" class="form-input" style="width:100%;">
    <option value="">&mdash; Choose a group &mdash;</option>
  </select>
</div>

<!-- Settlement Loading -->
<div id="settlLoading" class="hidden" style="text-align:center;padding:2rem;color:var(--text-muted);font-size:0.85rem;">Loading settlement data&#8230;</div>

<!-- Settlement Content (shown after group selection) -->
<div id="settlContent" class="hidden">

  <!-- Summary Cards Row (3 columns) -->
  <div class="settlement-kpi-grid">
    <div class="card" style="padding:1.1rem;text-align:center;">
      <div style="font-size:0.72rem;text-transform:uppercase;letter-spacing:0.04em;color:var(--text-muted);margin-bottom:0.3rem;">Total Group Spend</div>
      <div id="settlTotalSpend" class="money-md" style="color:var(--text-primary);">&mdash;</div>
    </div>
    <div class="card" style="padding:1.1rem;text-align:center;">
      <div style="font-size:0.72rem;text-transform:uppercase;letter-spacing:0.04em;color:var(--text-muted);margin-bottom:0.3rem;">Per Person Share</div>
      <div id="settlPerPerson" class="money-md" style="color:var(--text-primary);">&mdash;</div>
    </div>
    <div class="card" style="padding:1.1rem;text-align:center;">
      <div style="font-size:0.72rem;text-transform:uppercase;letter-spacing:0.04em;color:var(--text-muted);margin-bottom:0.3rem;" id="settlMyContribLabel"><?= $firstName ?>'s Contribution</div>
      <div id="settlMyContrib" class="money-md" style="color:var(--text-primary);">&mdash;</div>
    </div>
  </div>

  <!-- Settlement Breakdown Card -->
  <div class="card" style="padding:1.25rem;margin-bottom:1rem;">
    <div style="font-size:0.9rem;font-weight:600;margin-bottom:0.75rem;">Settlement Breakdown</div>
    <div id="settlBreakdownList"></div>
    <p id="settlBreakdownEmpty" class="hidden" style="text-align:center;color:var(--text-muted);font-size:0.82rem;padding:1rem 0;">All members are settled up!</p>
  </div>

  <!-- User Settlement Status Card -->
  <div class="card settl-status-card" style="padding:1.25rem;margin-bottom:1rem;">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:0.5rem;">
      <div style="font-size:0.9rem;font-weight:600;">Your Settlement Status</div>
      <button id="settlSettleBtn" class="hidden" style="padding:0.35rem 0.9rem;font-size:0.78rem;font-weight:600;border:none;border-radius:0.4rem;background:var(--accent-primary);color:var(--text-on-accent);cursor:pointer;transition:opacity 0.3s,transform 0.3s;">Settle</button>
    </div>
    <div id="settlUserPeriod" style="font-size:0.78rem;color:var(--text-muted);margin-bottom:0.75rem;"></div>
    <div id="settlUserStatus"></div>
    <div id="settlConfirmedMsg" class="hidden" style="text-align:center;padding:1.25rem 0;transition:opacity 0.4s ease;">
      <div id="settlConfirmedIcon" class="hidden" style="margin-bottom:0.75rem;">
        <svg width="52" height="52" viewBox="0 0 52 52" fill="none" style="display:inline-block;color:var(--success-600);">
          <circle cx="26" cy="26" r="24" stroke="currentColor" stroke-width="2.5" fill="none"/>
          <path d="M16 27l7 7 13-14" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" fill="none"/>
        </svg>
      </div>
      <div id="settlConfirmedText" style="font-size:0.88rem;font-weight:600;color:var(--text-primary);"></div>
      <div id="settlWaitingText" style="font-size:0.78rem;color:var(--text-muted);margin-top:0.4rem;"></div>
    </div>
    <p id="settlUserNone" class="hidden" style="text-align:center;color:var(--text-muted);font-size:0.82rem;padding:1rem 0;">You are all settled up!</p>
  </div>

  <!-- Late Expenses Settlement Card (post-settlement adjustments) -->
  <div id="postSettlCard" class="card post-settl-card hidden" style="padding:1.25rem;margin-bottom:1rem;">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:0.5rem;">
      <div>
        <div style="font-size:0.9rem;font-weight:600;color:var(--amber-800);">Late Expenses Settlement</div>
        <div style="font-size:0.7rem;color:var(--text-muted);margin-top:0.15rem;">Expenses completed during a settled period but priced afterwards</div>
      </div>
      <button id="postSettlBtn" class="hidden" style="padding:0.35rem 0.9rem;font-size:0.78rem;font-weight:600;border:none;border-radius:0.4rem;background:var(--amber-500);color:#fff;cursor:pointer;transition:opacity 0.3s,transform 0.3s;">Settle</button>
    </div>

    <!-- Post-settlement summary -->
    <div id="postSettlSummary" style="display:grid;grid-template-columns:1fr 1fr;gap:0.5rem;margin-bottom:0.75rem;">
      <div style="background:var(--amber-50);border-radius:0.5rem;padding:0.6rem 0.75rem;">
        <div style="font-size:0.68rem;text-transform:uppercase;letter-spacing:0.04em;color:var(--amber-800);">Late Total</div>
        <div id="postSettlTotal" class="money-md" style="color:var(--text-primary);">&mdash;</div>
      </div>
      <div style="background:var(--amber-50);border-radius:0.5rem;padding:0.6rem 0.75rem;">
        <div style="font-size:0.68rem;text-transform:uppercase;letter-spacing:0.04em;color:var(--amber-800);">Per Person</div>
        <div id="postSettlPerPerson" class="money-md" style="color:var(--text-primary);">&mdash;</div>
      </div>
    </div>

    <!-- Late expenses list -->
    <div id="postSettlExpenseList" style="margin-bottom:0.75rem;"></div>

    <!-- Post-settlement breakdown -->
    <div id="postSettlBreakdown" style="margin-bottom:0.75rem;"></div>

    <!-- Post-settlement user status -->
    <div id="postSettlUserStatus"></div>
    <div id="postSettlConfirmedMsg" class="hidden" style="text-align:center;padding:1.25rem 0;transition:opacity 0.4s ease;">
      <div id="postSettlConfirmedIcon" class="hidden" style="margin-bottom:0.75rem;">
        <svg width="52" height="52" viewBox="0 0 52 52" fill="none" style="display:inline-block;color:var(--success-600);">
          <circle cx="26" cy="26" r="24" stroke="currentColor" stroke-width="2.5" fill="none"/>
          <path d="M16 27l7 7 13-14" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" fill="none"/>
        </svg>
      </div>
      <div id="postSettlConfirmedText" style="font-size:0.88rem;font-weight:600;color:var(--text-primary);"></div>
      <div id="postSettlWaitingText" style="font-size:0.78rem;color:var(--text-muted);margin-top:0.4rem;"></div>
    </div>
  </div>

  <!-- Past Settlements Card -->
  <div class="card" style="padding:1.25rem;">
    <div style="font-size:0.9rem;font-weight:600;margin-bottom:0.75rem;">Past Settlements</div>
    <div id="settlHistoryList"></div>
    <p id="settlHistoryEmpty" class="hidden" style="text-align:center;color:var(--text-muted);font-size:0.82rem;padding:1rem 0;">No past settlements.</p>
  </div>

</div><!-- /settlContent -->
</div><!-- /panelSettlement -->

<!-- ========== MODALS ========== -->

<!-- Date Filter Modal removed — now inline -->

<!-- Budget Modal -->
<div id="budgetModal" class="hidden" style="
  position:fixed;inset:0;display:flex;align-items:center;justify-content:center;
  background:rgba(15, 23, 42, 0.55);padding:1rem;
">
  <div class="card" style="width:100%;max-width:380px;padding:1.75rem;">
    <h3 style="font-size:1.05rem;font-weight:700;margin-bottom:1rem;">Set Monthly Budget</h3>
    <div class="form-group">
      <label for="budgetInput">Budget Amount</label>
      <input type="number" id="budgetInput" class="form-input" placeholder="e.g. 5000" min="1" step="0.01" />
    </div>
    <p style="font-size:0.8rem;color:var(--text-muted);margin-bottom:1rem;">This budget will be applied to the current month.</p>
    <button id="budgetSaveBtn" class="btn" style="width:100%;">Save Budget</button>
  </div>
</div>

<!-- Settlement Detail Modal -->
<div id="settlDetailModal" class="hidden" style="
  position:fixed;inset:0;display:flex;align-items:center;justify-content:center;
  background:rgba(15, 23, 42, 0.55);padding:1rem;
">
  <div class="card" style="width:100%;max-width:480px;padding:1.75rem;max-height:80vh;overflow-y:auto;">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem;">
      <h3 style="font-size:1.05rem;font-weight:700;">Settlement Details</h3>
      <button id="settlDetailClose" class="btn btn-icon">&times;</button>
    </div>
    <div id="settlDetailContent"></div>
    <div id="settlDetailTotal" class="money-md" style="margin-top:1rem;padding-top:0.75rem;border-top:2px solid var(--border-soft);text-align:right;"></div>
    <button id="settlDownloadPdf" class="hidden" style="display:none;margin-top:1rem;width:100%;padding:0.6rem;font-size:0.85rem;font-weight:600;border:none;border-radius:0.5rem;background:var(--accent-primary);color:var(--text-on-accent);cursor:pointer;">Download as PDF</button>
  </div>
</div>

<script type="application/json" id="expmgPageConfig"><?php
echo json_encode(['currentUserId' => (int) $currentUserId, 'firstName' => $firstName]);
?></script>
<script src="../public/assets/js/expenses.js"></script>

<style>
/* Tab Switcher — segmented pill style (global: .segmented-tabs /
   .exp-tabs-container in styles.css; margin kept locally) */
.exp-tabs-container { margin-bottom: 1rem; }

/* Inline Date Filter */
.exp-date-filter-row {
  display:flex;
  align-items:center;
  gap:0.5rem;
  background:var(--bg-subtle);
  border:1px solid var(--border-soft);
  border-radius:var(--radius-md);
  padding:0.3rem 0.4rem;
  margin-bottom:1rem;
}
.exp-date-input-wrap {
  flex:1;
  min-width:0;
  background:var(--bg-surface);
  border-radius:var(--radius-sm);
  overflow:hidden;
}
.exp-date-input {
  width:100%;
  border:none;
  background:transparent;
  padding:0.55rem 0.6rem;
  font-size:0.88rem;
  font-weight:600;
  font-family:var(--font-mono);
  font-variant-numeric:tabular-nums;
  color:var(--text-primary);
  outline:none;
  box-sizing:border-box;
}
.exp-date-input::-webkit-calendar-picker-indicator { opacity:0.5; cursor:pointer; color:var(--text-secondary); }
.exp-date-to {
  font-size:0.82rem;
  color:var(--text-muted);
  font-weight:500;
  flex-shrink:0;
}
.exp-date-apply,
.exp-date-clear {
  flex-shrink:0;
  width:32px; height:32px;
  border-radius:50%;
  border:none;
  cursor:pointer;
  font-size:0.85rem;
  display:flex;align-items:center;justify-content:center;
}
.exp-date-apply { background:var(--accent-primary); color:var(--text-on-accent); }
.exp-date-apply:hover { opacity:0.85; }
.exp-date-clear { background:var(--danger-600); color:#fff; }
.exp-date-clear:hover { opacity:0.85; }

/* Settlement status card animation */
.settl-status-card { transition: box-shadow 0.3s ease; }
#settlConfirmedMsg { transition: opacity 0.4s ease; }
#settlConfirmedIcon svg { animation: settlCheckPop 0.5s ease both; }
@keyframes settlCheckPop {
  0%   { transform: scale(0.5); opacity:0; }
  60%  { transform: scale(1.15); opacity:1; }
  100% { transform: scale(1); opacity:1; }
}

@media (max-width: 640px) {
  #expSummaryRow,
  #expChartsRow { grid-template-columns: 1fr !important; }
  /* Keep the 3 settlement KPI metrics in one compact row on mobile instead of
     stacking them into 3 full-width cards that push Settlement Breakdown
     below the fold. (.settlement-kpi-grid is the class-based form.) */
  #panelSettlement [style*="grid-template-columns:repeat(3"],
  #panelSettlement .settlement-kpi-grid {
    grid-template-columns: repeat(3, minmax(0, 1fr)) !important;
    gap: 0.4rem !important;
  }
  #panelSettlement [style*="grid-template-columns:repeat(3"] > .card,
  #panelSettlement .settlement-kpi-grid > .card {
    padding: 0.75rem 0.5rem !important;
  }
  #panelSettlement [style*="grid-template-columns:repeat(3"] > .card > div:first-child,
  #panelSettlement .settlement-kpi-grid > .card > div:first-child {
    font-size: 0.62rem !important;
  }
  #settlTotalSpend, #settlPerPerson, #settlMyContrib {
    font-size: 1rem !important;
    word-break: break-word;
  }
  .unpriced-row { flex-direction: column; align-items: flex-start; }
  .unpriced-action { width: 100%; }
  .exp-two-col { flex-direction: column; }
  .exp-col { max-height: none; }
}

/* Sort Controls */
.exp-sort-controls {
  display: flex;
  gap: 0.4rem;
  align-items: center;
}
.exp-sort-select {
  background: var(--bg-subtle);
  color: var(--text-primary);
  border: 1px solid var(--border-medium);
  border-radius: var(--radius-sm);
  padding: 0.25rem 0.5rem;
  font-size: 0.75rem;
  font-family: inherit;
  cursor: pointer;
  outline: none;
  transition: border-color 0.2s;
}
.exp-sort-select:focus { border-color: var(--accent-primary); }

/* Two-Column Expense Layout (subcards) */
.exp-two-col {
  display: flex;
  gap: 0.75rem;
}
.exp-col {
  flex: 1;
  min-width: 0;
  background: var(--bg-subtle);
  border-radius: var(--radius-md);
  border: 1px solid var(--border-soft);
  display: flex;
  flex-direction: column;
}
.exp-col-header {
  font-size: 0.78rem;
  font-weight: 600;
  padding: 0.6rem 0.75rem;
  border-bottom: 1px solid var(--border-soft);
  display: flex;
  align-items: center;
  gap: 0.4rem;
  color: var(--text-secondary);
}
/* .exp-pdf-btn (and :hover) moved to public/assets/css/styles.css (shared components) */
.exp-col-dot {
  width: 8px;
  height: 8px;
  border-radius: 50%;
  flex-shrink: 0;
}
.exp-col-dot--personal { background: var(--accent-primary); }
.exp-col-dot--group    { background: var(--accent-secondary); }
.exp-col-body {
  max-height: 360px;
  overflow-y: auto;
  padding: 0 0.5rem;
}
.exp-col-empty {
  text-align: center;
  color: var(--text-muted);
  font-size: 0.8rem;
  padding: 1.5rem 0.5rem;
  margin: 0;
}

/* Expense Row */
.exp-row {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  padding: 0.5rem 0.25rem;
  border-bottom: 1px solid var(--border-soft);
  border-radius: 0.3rem;
  transition: background 0.15s;
}
.exp-row:last-child { border-bottom: none; }
.exp-row:hover { background: var(--accent-soft); }
.exp-row-dot {
  width: 7px;
  height: 7px;
  border-radius: 50%;
  flex-shrink: 0;
}
.exp-row-dot--personal  { background: var(--accent-primary); }
.exp-row-dot--unsettled { background: var(--accent-secondary); }
.exp-row-dot--settled   { background: #94a3b8; }
.exp-row-info {
  flex: 1;
  min-width: 0;
}
.exp-row-name {
  font-size: 0.8rem;
  font-weight: 500;
  color: var(--text-primary);
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.exp-row-grouptag {
  font-size: 0.62rem;
  background: var(--accent-soft);
  color: var(--accent-primary);
  padding: 0.05rem 0.35rem;
  border-radius: var(--radius-pill);
  vertical-align: middle;
}
.exp-row-meta {
  font-size: 0.68rem;
  color: var(--text-muted);
  margin-top: 0.1rem;
}
.exp-row-amt {
  font-family: var(--font-mono);
  font-variant-numeric: tabular-nums;
  font-weight: 700;
  font-size: 0.82rem;
  color: var(--text-primary);
  white-space: nowrap;
  flex-shrink: 0;
}

/* Unpriced Items Section — amber warning callout */
.unpriced-card {
  border: 1.5px dashed var(--amber-500);
  background: var(--amber-50);
}
.unpriced-badge {
  font-size: 0.7rem;
  font-weight: 700;
  background: var(--amber-500);
  color: #ffffff;
  padding: 0.1rem 0.5rem;
  border-radius: var(--radius-pill);
  min-width: 20px;
  text-align: center;
}
.unpriced-row {
  display: flex;
  align-items: center;
  gap: 0.75rem;
  padding: 0.65rem 0;
  border-bottom: 1px solid var(--border-soft);
}
.unpriced-row:last-child { border-bottom: none; }
.unpriced-info { flex: 1; min-width: 0; }
.unpriced-name {
  font-size: 0.85rem;
  font-weight: 500;
  color: var(--text-primary);
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.unpriced-meta { font-size: 0.72rem; color: var(--text-muted); margin-top: 0.15rem; }
.unpriced-src {
  font-size: 0.65rem;
  background: var(--accent-soft);
  color: var(--accent-primary);
  padding: 0.05rem 0.35rem;
  border-radius: var(--radius-pill);
}
.unpriced-action {
  display: flex;
  align-items: center;
  gap: 0.4rem;
  flex-shrink: 0;
}
.unpriced-price-input {
  width: 90px;
  padding: 0.35rem 0.5rem;
  border: 1px solid var(--border-medium);
  border-radius: var(--radius-sm);
  font-size: 0.82rem;
  font-family: var(--font-mono);
  font-variant-numeric: tabular-nums;
  background: var(--bg-surface);
  color: var(--text-primary);
  outline: none;
  transition: border-color 0.2s;
}
.unpriced-price-input:focus { border-color: var(--accent-primary); }
.unpriced-add-btn {
  width: 32px;
  height: 32px;
  border-radius: 50%;
  border: none;
  background: var(--accent-primary);
  color: var(--text-on-accent);
  font-size: 0.9rem;
  cursor: pointer;
  display: flex;
  align-items: center;
  justify-content: center;
  transition: opacity 0.2s;
  flex-shrink: 0;
}
.unpriced-add-btn:hover { opacity: 0.85; }
.unpriced-add-btn:disabled { opacity: 0.5; cursor: default; }

/* Post-Settlement (Late Expenses) Section — amber warning callout */
.post-settl-card {
  border: 1.5px dashed var(--amber-500);
  background: var(--amber-50);
}
.ps-expense-row {
  display: flex;
  align-items: center;
  gap: 0.75rem;
  padding: 0.55rem 0;
  border-bottom: 1px solid var(--border-soft);
}
.ps-expense-row:last-child { border-bottom: none; }
.ps-exp-info { flex: 1; min-width: 0; }
.ps-exp-desc {
  font-size: 0.85rem;
  font-weight: 500;
  color: var(--text-primary);
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.ps-exp-meta { font-size: 0.72rem; color: var(--text-muted); margin-top: 0.15rem; }
.ps-exp-amt {
  font-family: var(--font-mono);
  font-variant-numeric: tabular-nums;
  font-size: 0.85rem;
  font-weight: 600;
  color: var(--amber-800);
  flex-shrink: 0;
}
</style>
