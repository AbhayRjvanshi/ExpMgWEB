<?php
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
  <button id="tabMyExpenses" class="exp-tab active" onclick="switchExpTab('my')">My Expenses</button>
  <button id="tabSettlement" class="exp-tab" onclick="switchExpTab('settlement')">Settlement</button>
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

<!-- Monthly Budget Card -->
<div class="card" id="budgetCard" style="padding:1.25rem;margin-bottom:1rem;">
  <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:0.6rem;">
    <div>
      <div style="font-size:0.72rem;text-transform:uppercase;letter-spacing:0.04em;color:#666;">Monthly Budget</div>
      <div id="budgetAmountDisplay" style="font-size:1.4rem;font-weight:700;color:#000;">&mdash;</div>
    </div>
    <div style="text-align:right;">
      <div style="font-size:0.72rem;text-transform:uppercase;letter-spacing:0.04em;color:#666;">Spent</div>
      <div id="budgetSpentDisplay" style="font-size:1.4rem;font-weight:700;color:#000;">&mdash;</div>
    </div>
  </div>
  <div id="budgetProgressWrap" style="margin-bottom:0.5rem;">
    <div style="height:10px;background:rgba(0,0,0,0.08);border-radius:999px;overflow:hidden;">
      <div id="budgetProgressFill" style="height:100%;border-radius:999px;background:var(--mint-leaf);width:0%;transition:width 0.8s ease;"></div>
    </div>
    <div style="display:flex;justify-content:space-between;font-size:0.72rem;color:#666;margin-top:0.3rem;">
      <span id="budgetPctLabel">0% Used</span>
      <span id="budgetRemainingLabel">&#8377;0.00 Remaining</span>
    </div>
  </div>
  <div style="display:flex;justify-content:flex-end;">
    <button id="expSetBudgetBtn" class="btn btn-compact">Set Budget &gt;</button>
  </div>
</div>

<!-- Month Selector -->
<div style="display:flex;align-items:center;justify-content:center;gap:1rem;margin-bottom:1.25rem;">
  <button id="expPrevMonth" class="btn btn-icon" aria-label="Previous month">&#8592;</button>
  <h2 id="expMonthLabel" style="font-size:1.15rem;font-weight:700;min-width:160px;text-align:center;"></h2>
  <button id="expNextMonth" class="btn btn-icon" aria-label="Next month">&#8594;</button>
</div>

<!-- Summary Cards Row -->
<div id="expSummaryRow" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:0.75rem;margin-bottom:1.25rem;">
  <div class="card" style="padding:1.25rem;">
    <div style="font-size:0.78rem;text-transform:uppercase;letter-spacing:0.04em;color:#666;margin-bottom:0.35rem;">Total Spent</div>
    <div id="expTotalSpent" style="font-size:1.6rem;font-weight:700;color:#000;">&mdash;</div>
  </div>
  <div class="card" style="padding:1.25rem;">
    <div style="font-size:0.78rem;text-transform:uppercase;letter-spacing:0.04em;color:#666;margin-bottom:0.35rem;">Breakdown</div>
    <div style="display:flex;gap:1.5rem;margin-top:0.35rem;flex-wrap:wrap;">
      <div>
        <div style="font-size:0.72rem;color:#666;">Personal</div>
        <div id="expPersonal" style="font-size:1.15rem;font-weight:600;color:#000;">&mdash;</div>
      </div>
      <div>
        <div style="font-size:0.72rem;color:#666;">Group</div>
        <div id="expGroup" style="font-size:1.15rem;font-weight:600;color:#000;">&mdash;</div>
      </div>
      <div id="expShareWrap" class="hidden">
        <div style="font-size:0.72rem;color:#666;">Your Share</div>
        <div id="expShare" style="font-size:1.15rem;font-weight:600;color:#000;">&mdash;</div>
      </div>
    </div>
  </div>
</div>

<!-- Charts Row -->
<div id="expChartsRow" style="display:grid;grid-template-columns:1fr 1fr;gap:0.75rem;margin-bottom:1.25rem;">
  <div class="card" style="padding:1.25rem;">
    <div style="font-size:0.82rem;font-weight:600;margin-bottom:0.75rem;">By Category</div>
    <div style="position:relative;max-width:220px;margin:0 auto;">
      <canvas id="chartCategory"></canvas>
    </div>
    <p id="chartCatEmpty" class="hidden" style="text-align:center;color:#666;font-size:0.82rem;padding:2rem 0;">No data this month.</p>
  </div>
  <div class="card" style="padding:1.25rem;">
    <div style="font-size:0.82rem;font-weight:600;margin-bottom:0.75rem;">Daily Spending</div>
    <canvas id="chartDaily"></canvas>
    <p id="chartDayEmpty" class="hidden" style="text-align:center;color:#666;font-size:0.82rem;padding:2rem 0;">No data this month.</p>
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
        <button id="expPersonalPdf" class="exp-pdf-btn" title="Download PDF">&#128196;</button>
      </div>
      <div id="expPersonalList" class="exp-col-body"></div>
      <p id="expPersonalEmpty" class="hidden exp-col-empty">No personal expenses.</p>
    </div>
    <div class="exp-col">
      <div class="exp-col-header">
        <span class="exp-col-dot exp-col-dot--group"></span> Group Expenses
        <button id="expGroupPdf" class="exp-pdf-btn" title="Download PDF">&#128196;</button>
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
  <p style="font-size:0.72rem;color:#888;margin-bottom:0.75rem;">
    Items checked from lists without a price. Add a price to convert them into expenses.
  </p>
  <div id="unpricedListContainer"></div>
  <p id="unpricedListEmpty" class="hidden" style="text-align:center;color:#666;font-size:0.82rem;padding:1.5rem 0;">No unpriced items pending.</p>
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
<div id="settlLoading" class="hidden" style="text-align:center;padding:2rem;color:#666;font-size:0.85rem;">Loading settlement data&#8230;</div>

<!-- Settlement Content (shown after group selection) -->
<div id="settlContent" class="hidden">

  <!-- Summary Cards Row (3 columns) -->
  <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:0.75rem;margin-bottom:1rem;">
    <div class="card" style="padding:1.1rem;text-align:center;">
      <div style="font-size:0.72rem;text-transform:uppercase;letter-spacing:0.04em;color:#666;margin-bottom:0.3rem;">Total Group Spend</div>
      <div id="settlTotalSpend" style="font-size:1.35rem;font-weight:700;color:#000;">&mdash;</div>
    </div>
    <div class="card" style="padding:1.1rem;text-align:center;">
      <div style="font-size:0.72rem;text-transform:uppercase;letter-spacing:0.04em;color:#666;margin-bottom:0.3rem;">Per Person Share</div>
      <div id="settlPerPerson" style="font-size:1.35rem;font-weight:700;color:#000;">&mdash;</div>
    </div>
    <div class="card" style="padding:1.1rem;text-align:center;">
      <div style="font-size:0.72rem;text-transform:uppercase;letter-spacing:0.04em;color:#666;margin-bottom:0.3rem;" id="settlMyContribLabel"><?= $firstName ?>'s Contribution</div>
      <div id="settlMyContrib" style="font-size:1.35rem;font-weight:700;color:#000;">&mdash;</div>
    </div>
  </div>

  <!-- Settlement Breakdown Card -->
  <div class="card" style="padding:1.25rem;margin-bottom:1rem;">
    <div style="font-size:0.9rem;font-weight:600;margin-bottom:0.75rem;">Settlement Breakdown</div>
    <div id="settlBreakdownList"></div>
    <p id="settlBreakdownEmpty" class="hidden" style="text-align:center;color:#666;font-size:0.82rem;padding:1rem 0;">All members are settled up!</p>
  </div>

  <!-- User Settlement Status Card -->
  <div class="card settl-status-card" style="padding:1.25rem;margin-bottom:1rem;">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:0.5rem;">
      <div style="font-size:0.9rem;font-weight:600;">Your Settlement Status</div>
      <button id="settlSettleBtn" class="hidden" style="padding:0.35rem 0.9rem;font-size:0.78rem;font-weight:600;border:none;border-radius:0.4rem;background:var(--mint-leaf);color:#fff;cursor:pointer;transition:opacity 0.3s,transform 0.3s;">Settle</button>
    </div>
    <div id="settlUserPeriod" style="font-size:0.78rem;color:#666;margin-bottom:0.75rem;"></div>
    <div id="settlUserStatus"></div>
    <div id="settlConfirmedMsg" class="hidden" style="text-align:center;padding:1.25rem 0;transition:opacity 0.4s ease;">
      <div id="settlConfirmedIcon" class="hidden" style="margin-bottom:0.75rem;">
        <svg width="52" height="52" viewBox="0 0 52 52" fill="none" style="display:inline-block;">
          <circle cx="26" cy="26" r="24" stroke="#22c55e" stroke-width="2.5" fill="none"/>
          <path d="M16 27l7 7 13-14" stroke="#22c55e" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" fill="none"/>
        </svg>
      </div>
      <div id="settlConfirmedText" style="font-size:0.88rem;font-weight:600;color:#000;"></div>
      <div id="settlWaitingText" style="font-size:0.78rem;color:#666;margin-top:0.4rem;"></div>
    </div>
    <p id="settlUserNone" class="hidden" style="text-align:center;color:#666;font-size:0.82rem;padding:1rem 0;">You are all settled up!</p>
  </div>

  <!-- Late Expenses Settlement Card (post-settlement adjustments) -->
  <div id="postSettlCard" class="card post-settl-card hidden" style="padding:1.25rem;margin-bottom:1rem;">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:0.5rem;">
      <div>
        <div style="font-size:0.9rem;font-weight:600;color:#b45309;">Late Expenses Settlement</div>
        <div style="font-size:0.7rem;color:#888;margin-top:0.15rem;">Expenses completed during a settled period but priced afterwards</div>
      </div>
      <button id="postSettlBtn" class="hidden" style="padding:0.35rem 0.9rem;font-size:0.78rem;font-weight:600;border:none;border-radius:0.4rem;background:#f59e0b;color:#fff;cursor:pointer;transition:opacity 0.3s,transform 0.3s;">Settle</button>
    </div>

    <!-- Post-settlement summary -->
    <div id="postSettlSummary" style="display:grid;grid-template-columns:1fr 1fr;gap:0.5rem;margin-bottom:0.75rem;">
      <div style="background:#fffbeb;border-radius:0.5rem;padding:0.6rem 0.75rem;">
        <div style="font-size:0.68rem;text-transform:uppercase;letter-spacing:0.04em;color:#92400e;">Late Total</div>
        <div id="postSettlTotal" style="font-size:1.1rem;font-weight:700;color:#000;">&mdash;</div>
      </div>
      <div style="background:#fffbeb;border-radius:0.5rem;padding:0.6rem 0.75rem;">
        <div style="font-size:0.68rem;text-transform:uppercase;letter-spacing:0.04em;color:#92400e;">Per Person</div>
        <div id="postSettlPerPerson" style="font-size:1.1rem;font-weight:700;color:#000;">&mdash;</div>
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
        <svg width="52" height="52" viewBox="0 0 52 52" fill="none" style="display:inline-block;">
          <circle cx="26" cy="26" r="24" stroke="#22c55e" stroke-width="2.5" fill="none"/>
          <path d="M16 27l7 7 13-14" stroke="#22c55e" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" fill="none"/>
        </svg>
      </div>
      <div id="postSettlConfirmedText" style="font-size:0.88rem;font-weight:600;color:#000;"></div>
      <div id="postSettlWaitingText" style="font-size:0.78rem;color:#666;margin-top:0.4rem;"></div>
    </div>
  </div>

  <!-- Past Settlements Card -->
  <div class="card" style="padding:1.25rem;">
    <div style="font-size:0.9rem;font-weight:600;margin-bottom:0.75rem;">Past Settlements</div>
    <div id="settlHistoryList"></div>
    <p id="settlHistoryEmpty" class="hidden" style="text-align:center;color:#666;font-size:0.82rem;padding:1rem 0;">No past settlements.</p>
  </div>

</div><!-- /settlContent -->
</div><!-- /panelSettlement -->

<!-- ========== MODALS ========== -->

<!-- Date Filter Modal removed — now inline -->

<!-- Budget Modal -->
<div id="budgetModal" class="hidden" style="
  position:fixed;inset:0;z-index:5000;display:flex;align-items:center;justify-content:center;
  background:rgba(0,0,0,0.55);padding:1rem;
">
  <div class="card" style="width:100%;max-width:380px;padding:1.75rem;">
    <h3 style="font-size:1.05rem;font-weight:700;margin-bottom:1rem;">Set Monthly Budget</h3>
    <div class="form-group">
      <label for="budgetInput">Budget Amount</label>
      <input type="number" id="budgetInput" class="form-input" placeholder="e.g. 5000" min="1" step="0.01" />
    </div>
    <p style="font-size:0.8rem;color:#666;margin-bottom:1rem;">This budget will be applied to the current month.</p>
    <button id="budgetSaveBtn" class="btn" style="width:100%;">Save Budget</button>
  </div>
</div>

<!-- Settlement Detail Modal -->
<div id="settlDetailModal" class="hidden" style="
  position:fixed;inset:0;z-index:5000;display:flex;align-items:center;justify-content:center;
  background:rgba(0,0,0,0.55);padding:1rem;
">
  <div class="card" style="width:100%;max-width:480px;padding:1.75rem;max-height:80vh;overflow-y:auto;">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem;">
      <h3 style="font-size:1.05rem;font-weight:700;">Settlement Details</h3>
      <button id="settlDetailClose" class="btn btn-icon">&times;</button>
    </div>
    <div id="settlDetailContent"></div>
    <div id="settlDetailTotal" style="margin-top:1rem;padding-top:0.75rem;border-top:2px solid #e0e0e0;font-size:1rem;font-weight:700;text-align:right;"></div>
    <button id="settlDownloadPdf" class="hidden" style="display:none;margin-top:1rem;width:100%;padding:0.6rem;font-size:0.85rem;font-weight:600;border:none;border-radius:0.5rem;background:var(--mint-leaf);color:#fff;cursor:pointer;">Download as PDF</button>
  </div>
</div>

<script>
/* ========== TAB SWITCHING ========== */
function switchExpTab(tab) {
  var myPanel = document.getElementById('panelMyExpenses');
  var stPanel = document.getElementById('panelSettlement');
  var tabMy   = document.getElementById('tabMyExpenses');
  var tabSt   = document.getElementById('tabSettlement');
  if (tab === 'my') {
    myPanel.classList.remove('hidden');
    stPanel.classList.add('hidden');
    tabMy.classList.add('active');
    tabSt.classList.remove('active');
  } else {
    myPanel.classList.add('hidden');
    stPanel.classList.remove('hidden');
    tabSt.classList.add('active');
    tabMy.classList.remove('active');
    loadSettlementGroups();
  }
}

/* ========== MY EXPENSES MODULE ========== */
(function(){
  var now = new Date();
  var curYear  = now.getFullYear();
  var curMonth = now.getMonth();
  var allExpenses = [];
  var catChart = null, dayChart = null;

  var $label       = document.getElementById('expMonthLabel');
  var $prevBtn     = document.getElementById('expPrevMonth');
  var $nextBtn     = document.getElementById('expNextMonth');
  var $totalSpent  = document.getElementById('expTotalSpent');
  var $personal    = document.getElementById('expPersonal');
  var $group       = document.getElementById('expGroup');
  var $shareWrap   = document.getElementById('expShareWrap');
  var $share       = document.getElementById('expShare');
  var $catCanvas   = document.getElementById('chartCategory');
  var $dayCanvas   = document.getElementById('chartDaily');
  var $catEmpty    = document.getElementById('chartCatEmpty');
  var $dayEmpty    = document.getElementById('chartDayEmpty');
  var $personalList  = document.getElementById('expPersonalList');
  var $groupList     = document.getElementById('expGroupList');
  var $personalEmpty = document.getElementById('expPersonalEmpty');
  var $groupEmpty    = document.getElementById('expGroupEmpty');
  var $sortField     = document.getElementById('expSortField');
  var $sortOrder     = document.getElementById('expSortOrder');

  // Budget card elements
  var $budgetAmtDisp   = document.getElementById('budgetAmountDisplay');
  var $budgetSpentDisp = document.getElementById('budgetSpentDisplay');
  var $budgetFill      = document.getElementById('budgetProgressFill');
  var $budgetPctLabel  = document.getElementById('budgetPctLabel');
  var $budgetRemaining = document.getElementById('budgetRemainingLabel');
  var $setBudgetBtn    = document.getElementById('expSetBudgetBtn');

  // Budget modal
  var $modal       = document.getElementById('budgetModal');
  var $budgetInput = document.getElementById('budgetInput');
  var $saveBtn     = document.getElementById('budgetSaveBtn');

  // Inline date filter
  var $startDate       = document.getElementById('expStartDate');
  var $endDate         = document.getElementById('expEndDate');
  var $dateFilterBtn   = document.getElementById('expDateFilterBtn');
  var $dateClearBtn    = document.getElementById('expDateClearBtn');
  var dateRangeActive = false;

  var monthKey = function(){ return curYear + '-' + String(curMonth+1).padStart(2,'0'); };
  var fmtMoney = function(n){ return '\u20B9' + Number(n).toLocaleString('en-IN', {minimumFractionDigits:2, maximumFractionDigits:2}); };
  var monthNames = ['January','February','March','April','May','June','July','August','September','October','November','December'];
  window._expFmtMoney = fmtMoney;

  function updateLabel(){ $label.textContent = monthNames[curMonth] + ' ' + curYear; }

  var chartColorMap = {
    'General': '#6b7280',
    'Food/Groceries': '#84cc16',
    'Food': '#84cc16',
    'Groceries': '#84cc16',
    'Transport': '#14b8a6',
    'Utilities': '#a3a33a',
    'Bills': '#166534',
    'Shopping': '#6ee7b7',
    'Education': '#22d3ee',
    'Health': '#10b981',
    'Others': '#4d7c0f'
  };

  function categoryColor(name, index) {
    var key = (name || '').trim();
    if (chartColorMap[key]) return chartColorMap[key];

    var fallbackPalette = ['#6b7280', '#84cc16', '#14b8a6', '#a3a33a', '#166534', '#6ee7b7', '#22d3ee', '#10b981', '#4d7c0f'];
    return fallbackPalette[index % fallbackPalette.length];
  }

  async function loadMonth(){
    updateLabel();
    var mk = monthKey();
    try {
      var results = await Promise.all([
        get(API + '/expenses/summary.php?month=' + mk),
        get(API + '/expenses/list.php?month=' + mk),
        get(API + '/budgets/get.php?month=' + mk)
      ]);
      var sumRes = results[0], listRes = results[1], budRes = results[2];
      if(sumRes.ok) renderSummary(sumRes);
      if(listRes.ok){ allExpenses = listRes.expenses; renderList(); }
      renderBudgetCard(budRes.ok ? budRes.budget : null, sumRes.ok ? sumRes.total_spent : 0);
    } catch(err){ console.error(err); }
  }

  function renderBudgetCard(budget, spent) {
    var spentVal = Number(spent) || 0;
    $budgetSpentDisp.textContent = fmtMoney(spentVal);
    if (budget && budget.amount_limit) {
      var limit = Number(budget.amount_limit);
      $budgetAmtDisp.textContent = fmtMoney(limit);
      var rawPct = limit > 0 ? (spentVal / limit) * 100 : 0;
      var pct = Math.min(100, rawPct);
      $budgetPctLabel.textContent = rawPct > 100 ? 'Budget Exceeded' : pct.toFixed(0) + '% Used';
      $budgetPctLabel.style.color = rawPct > 100 ? '#ef4444' : '#666';
      $budgetRemaining.textContent = fmtMoney(Math.max(0, limit - spentVal)) + ' Remaining';
      $budgetFill.style.width = pct + '%';
      $budgetFill.style.background = pct >= 100 ? '#ef4444' : pct >= 80 ? '#f59e0b' : 'var(--mint-leaf)';
      $setBudgetBtn.textContent = 'Edit Budget >';
    } else {
      $budgetAmtDisp.textContent = '\u2014';
      $budgetPctLabel.textContent = '0% Used';
      $budgetRemaining.textContent = '\u20B90.00 Remaining';
      $budgetFill.style.width = '0%';
      $setBudgetBtn.textContent = 'Set Budget >';
    }
  }

  function renderSummary(d){
    $totalSpent.textContent = fmtMoney(d.total_spent);
    $personal.textContent   = fmtMoney(d.personal_total);
    $group.textContent      = fmtMoney(d.group_total);

    if ($shareWrap && $share) {
      if (Number(d.group_total) > 0) {
        $share.textContent = fmtMoney(d.group_share || 0);
        $shareWrap.classList.remove('hidden');
      } else {
        $shareWrap.classList.add('hidden');
      }
    }

    if(d.by_category.length){
      $catEmpty.classList.add('hidden');
      $catCanvas.style.display = '';
      var labels = d.by_category.map(function(c){return c.name;});
      var values = d.by_category.map(function(c){return c.total;});
      var colors = d.by_category.map(function(c, i){ return categoryColor(c.name, i); });
      if(catChart) catChart.destroy();
      catChart = new Chart($catCanvas, {
        type: 'doughnut',
        data: { labels: labels, datasets: [{ data: values, backgroundColor: colors, borderWidth: 0 }] },
        options: { cutout:'60%', plugins:{ legend:{ display:true, position:'bottom', labels:{ color:'#000', font:{size:11}, padding:10 } }, tooltip:{ callbacks:{ label: function(ctx){ return ctx.label + ': ' + fmtMoney(ctx.parsed); } } } } }
      });
    } else {
      $catEmpty.classList.remove('hidden'); $catCanvas.style.display = 'none';
      if(catChart){ catChart.destroy(); catChart = null; }
    }

    if(d.by_day.length){
      $dayEmpty.classList.add('hidden'); $dayCanvas.style.display = '';
      var daysInMonth = new Date(curYear, curMonth+1, 0).getDate();
      var personalMap = {}, groupMap = {};
      d.by_day.forEach(function(x){
        personalMap[x.day] = x.personal_total || 0;
        groupMap[x.day] = x.group_total || 0;
      });
      var dayLabels = [], dayValues = [];
      var personalValues = [], groupValues = [];
      for(var i=1; i<=daysInMonth; i++){
        dayLabels.push(i);
        personalValues.push(personalMap[i] || 0);
        groupValues.push(groupMap[i] || 0);
      }
      if(dayChart) dayChart.destroy();
      dayChart = new Chart($dayCanvas, {
        type: 'line',
        data: {
          labels: dayLabels,
          datasets: [
            { label:'Personal', data: personalValues, borderColor:'#3b82f6', backgroundColor:'#3b82f6', pointRadius:0, pointHoverRadius:0, pointHitRadius:6, tension:0.35, borderWidth:2, fill:false },
            { label:'Group', data: groupValues, borderColor:'#52b788', backgroundColor:'#52b788', pointRadius:0, pointHoverRadius:0, pointHitRadius:6, tension:0.35, borderWidth:2, fill:false }
          ]
        },
        options: { responsive:true, scales:{ x:{ ticks:{ color:'#000', font:{size:10} }, grid:{ display:false } }, y:{ ticks:{ color:'#000', font:{size:10}, callback: function(v){return '\u20B9'+v;} }, grid:{ color:'rgba(0,0,0,0.08)' }, beginAtZero:true } }, plugins:{ legend:{ display:true, position:'bottom', labels:{ color:'#000', font:{size:11}, usePointStyle:true, pointStyle:'circle' } }, tooltip:{ callbacks:{ label: function(ctx){ return ctx.dataset.label + ': ' + fmtMoney(ctx.parsed.y); } } } } }
      });
    } else {
      $dayEmpty.classList.remove('hidden'); $dayCanvas.style.display = 'none';
      if(dayChart){ dayChart.destroy(); dayChart = null; }
    }
  }

function sortExpenses(arr) {
    var field = $sortField.value;
    var asc   = $sortOrder.value === 'asc';
    var sorted = arr.slice();
    sorted.sort(function(a, b) {
      var va, vb;
      if (field === 'date')     { va = a.expense_date; vb = b.expense_date; }
      else if (field === 'name')    { va = (a.note || '').toLowerCase(); vb = (b.note || '').toLowerCase(); }
      else if (field === 'amount')  { va = parseFloat(a.amount); vb = parseFloat(b.amount); }
      else if (field === 'category'){ va = (a.category_name || '').toLowerCase(); vb = (b.category_name || '').toLowerCase(); }
      if (va < vb) return asc ? -1 : 1;
      if (va > vb) return asc ? 1 : -1;
      return 0;
    });
    return sorted;
  }

  function renderExpRow(e) {
    var dotClass;
    if (e.type === 'personal') dotClass = 'exp-row-dot--personal';
    else if (e.settled)        dotClass = 'exp-row-dot--settled';
    else                       dotClass = 'exp-row-dot--unsettled';
    var tag = e.type === 'group' ? ' <span class="exp-row-grouptag">' + escHtml(e.group_name || 'Group') + '</span>' : '';
    var paidInfo = e.type === 'group' && e.payer_username ? ' · paid by ' + escHtml(e.payer_username) : '';
    return '<div class="exp-row">'
      + '<span class="exp-row-dot ' + dotClass + '"></span>'
      + '<div class="exp-row-info">'
      + '<div class="exp-row-name">' + escHtml(e.note || '\u2014') + tag + '</div>'
      + '<div class="exp-row-meta">' + e.expense_date + ' &middot; ' + escHtml(e.category_name) + paidInfo + '</div>'
      + '</div>'
      + '<div class="exp-row-amt">' + fmtMoney(e.amount) + '</div>'
      + '</div>';
  }

  function renderList(){
    var personal = sortExpenses(allExpenses.filter(function(e){ return e.type === 'personal'; }));
    var group    = sortExpenses(allExpenses.filter(function(e){ return e.type === 'group'; }));

    if (personal.length) {
      $personalEmpty.classList.add('hidden');
      $personalList.innerHTML = personal.map(renderExpRow).join('');
    } else {
      $personalList.innerHTML = '';
      $personalEmpty.classList.remove('hidden');
    }

    if (group.length) {
      $groupEmpty.classList.add('hidden');
      $groupList.innerHTML = group.map(renderExpRow).join('');
    } else {
      $groupList.innerHTML = '';
      $groupEmpty.classList.remove('hidden');
    }
  }

  var escHtml = escapeHTML;

  /* ---- Events ---- */
  $prevBtn.onclick = function(){ clearDateFilter(); curMonth--; if(curMonth<0){curMonth=11;curYear--;} loadMonth(); };
  $nextBtn.onclick = function(){ clearDateFilter(); curMonth++; if(curMonth>11){curMonth=0;curYear++;} loadMonth(); };
  $sortField.onchange = function(){ renderList(); };
  $sortOrder.onchange = function(){ renderList(); };

  /* ---- Expense List PDF Download ---- */
  function downloadExpensePdf(type) {
    var expenses = sortExpenses(allExpenses.filter(function(e){ return e.type === type; }));
    if (!expenses.length) { alert('No ' + type + ' expenses to download.'); return; }

    var jsPDF = window.jspdf.jsPDF;
    var doc = new jsPDF({ orientation:'portrait', unit:'mm', format:'a4' });
    var pageW = doc.internal.pageSize.getWidth();
    var pdfMoney = function(n){ return 'Rs. ' + Number(n).toLocaleString('en-IN', {minimumFractionDigits:2, maximumFractionDigits:2}); };
    var title = type === 'personal' ? 'Personal Expenses' : 'Group Expenses';
    var period = $label.textContent;

    doc.setFontSize(16);
    doc.setFont('helvetica','bold');
    doc.text(title, pageW/2, 18, {align:'center'});

    doc.setFontSize(10);
    doc.setFont('helvetica','normal');
    doc.text(period, pageW/2, 26, {align:'center'});

    var head, rows;
    if (type === 'personal') {
      head = [['#','Description','Category','Date','Amount']];
      rows = expenses.map(function(e, i){
        return [(i+1).toString(), e.note || '-', e.category_name, e.expense_date, pdfMoney(e.amount)];
      });
      doc.autoTable({
        startY: 32, head: head, body: rows,
        styles: { fontSize:9, cellPadding:2.5, overflow:'linebreak' },
        headStyles: { fillColor:[59,130,246], textColor:255, fontStyle:'bold' },
        columnStyles: { 0:{halign:'center',cellWidth:10}, 1:{cellWidth:55}, 2:{cellWidth:30}, 3:{cellWidth:28}, 4:{halign:'right',cellWidth:30} },
        alternateRowStyles: { fillColor:[245,245,245] },
        margin: {left:14,right:14}, tableWidth:'wrap'
      });
    } else {
      head = [['#','Description','Group','Category','Date','Amount']];
      rows = expenses.map(function(e, i){
        return [(i+1).toString(), e.note || '-', e.group_name || '-', e.category_name, e.expense_date, pdfMoney(e.amount)];
      });
      doc.autoTable({
        startY: 32, head: head, body: rows,
        styles: { fontSize:9, cellPadding:2.5, overflow:'linebreak' },
        headStyles: { fillColor:[82,183,136], textColor:255, fontStyle:'bold' },
        columnStyles: { 0:{halign:'center',cellWidth:10}, 1:{cellWidth:40}, 2:{cellWidth:28}, 3:{cellWidth:25}, 4:{cellWidth:24}, 5:{halign:'right',cellWidth:28} },
        alternateRowStyles: { fillColor:[245,245,245] },
        margin: {left:14,right:14}, tableWidth:'wrap'
      });
    }

    var total = expenses.reduce(function(s,e){ return s + parseFloat(e.amount); }, 0);
    var finalY = doc.lastAutoTable.finalY + 6;
    doc.setFontSize(11);
    doc.setFont('helvetica','bold');
    doc.text('Total: ' + pdfMoney(total), pageW - 14, finalY, {align:'right'});

    doc.setFontSize(8);
    doc.setFont('helvetica','normal');
    doc.setTextColor(150);
    doc.text('Generated on ' + new Date().toLocaleString(), pageW/2, doc.internal.pageSize.getHeight() - 8, {align:'center'});

    var filename = type + '_expenses_' + monthKey() + '.pdf';
    doc.save(filename);
  }

  document.getElementById('expPersonalPdf').onclick = function(){ downloadExpensePdf('personal'); };
  document.getElementById('expGroupPdf').onclick = function(){ downloadExpensePdf('group'); };

  $dateFilterBtn.onclick = function(){ applyDateFilter(); };
  $dateClearBtn.onclick  = function(){ clearDateFilter(); loadMonth(); };

  async function applyDateFilter(){
    var s = $startDate.value, e = $endDate.value;
    if(!s || !e){ alert('Please select both start and end dates.'); return; }
    if(s > e){ alert('Start date must be before end date.'); return; }
    dateRangeActive = true;
    $dateClearBtn.classList.remove('hidden');
    $label.textContent = s + ' \u2014 ' + e;
    try {
      var listRes = await get(API + '/expenses/list.php?start=' + encodeURIComponent(s) + '&end=' + encodeURIComponent(e));
      if(listRes.ok){
        allExpenses = listRes.expenses; renderList();
        var total = allExpenses.reduce(function(a,x){return a+parseFloat(x.amount);},0);
        var personal = allExpenses.filter(function(x){return x.type==='personal';}).reduce(function(a,x){return a+parseFloat(x.amount);},0);
        var group = allExpenses.filter(function(x){return x.type==='group';}).reduce(function(a,x){return a+parseFloat(x.amount);},0);
        $totalSpent.textContent = fmtMoney(total);
        $personal.textContent = fmtMoney(personal);
        $group.textContent = fmtMoney(group);
        if(catChart){ catChart.destroy(); catChart=null; } if(dayChart){ dayChart.destroy(); dayChart=null; }
        $catCanvas.style.display='none'; $dayCanvas.style.display='none';
        $catEmpty.textContent='Charts unavailable for date range.'; $catEmpty.classList.remove('hidden');
        $dayEmpty.textContent='Charts unavailable for date range.'; $dayEmpty.classList.remove('hidden');
      }
    } catch(err){ console.error(err); }
  }

  function clearDateFilter(){
    dateRangeActive = false;
    $startDate.value = ''; $endDate.value = '';
    $dateClearBtn.classList.add('hidden');
    $catEmpty.textContent = 'No data this month.';
    $dayEmpty.textContent = 'No data this month.';
  }

  // Budget modal
  $setBudgetBtn.onclick = function(){
    $budgetInput.value = '';
    $modal.classList.remove('hidden'); $modal.style.display = 'flex';
    $budgetInput.focus();
  };
  var closeModal = function(){ $modal.classList.add('hidden'); $modal.style.display = ''; };
  $modal.onclick = function(ev){ if(ev.target === $modal) closeModal(); };

  $saveBtn.onclick = async function(){
    var val = parseFloat($budgetInput.value);
    if(!val || val <= 0) return;
    try {
      var res = await post(API + '/budgets/set.php', {
        month: monthKey(),
        amount_limit: val
      });
      if(res.ok){ closeModal(); loadMonth(); }
      else { alert(res.error || 'Failed to save budget.'); }
    } catch(err){ alert('Network error.'); }
  };

  loadMonth();
})();

/* ========== UNPRICED ITEMS MODULE ========== */
(function(){
  var $container  = document.getElementById('unpricedListContainer');
  var $empty      = document.getElementById('unpricedListEmpty');
  var $badge      = document.getElementById('unpricedCount');
  var fmtMoney    = window._expFmtMoney;
  var escHtml = escapeHTML;

  window.loadUnpricedItems = async function(){
    try {
      var res = await get(API + '/expenses/unpriced.php');
      if (!res.ok) return;
      var items = res.items;
      if (!items.length) {
        $container.innerHTML = '';
        $empty.classList.remove('hidden');
        $badge.style.display = 'none';
        return;
      }
      $empty.classList.add('hidden');
      $badge.textContent = items.length;
      $badge.style.display = 'inline-block';

      $container.innerHTML = items.map(function(it){
        var src = it.group_id
          ? '<span class="unpriced-src">\uD83D\uDCCB ' + escHtml(it.group_name || 'Group') + '</span>'
          : '<span class="unpriced-src">\uD83D\uDD12 Personal</span>';
        var paidByHtml = '';
        if (it.group_id) {
          paidByHtml = '<select class="unpriced-paidby-select form-input" data-group="' + it.group_id + '" style="font-size:0.8rem;padding:0.35rem 0.5rem;min-width:100px;">'
            + '<option value="">Paid by…</option></select>';
        }
        return '<div class="unpriced-row" data-id="' + it.id + '" data-group="' + (it.group_id || '') + '">'
          + '<div class="unpriced-info">'
          + '<div class="unpriced-name">' + escHtml(it.description) + '</div>'
          + '<div class="unpriced-meta">'
          + (it.category_name ? escHtml(it.category_name) + ' &middot; ' : '')
          + 'Checked ' + formatCheckedDate(it.checked_at)
          + ' &middot; ' + src
          + '</div>'
          + '</div>'
          + '<div class="unpriced-action">'
          + paidByHtml
          + '<input type="number" class="unpriced-price-input" placeholder="\u20B9 Price" min="0.01" step="0.01" />'
          + '<button class="unpriced-add-btn" data-id="' + it.id + '" title="Add price &amp; create expense">\u2713</button>'
          + '</div>'
          + '</div>';
      }).join('');

      // Populate paid_by selects for group items
      var groupSelects = $container.querySelectorAll('.unpriced-paidby-select');
      var groupCache = {};
      groupSelects.forEach(function(sel){
        var gid = sel.dataset.group;
        if (groupCache[gid]) {
          fillPaidBySelect(sel, groupCache[gid]);
        } else {
          get(API + '/groups/details.php?group_id=' + gid).then(function(res){
            if (res.ok && res.members) {
              groupCache[gid] = res.members;
              $container.querySelectorAll('.unpriced-paidby-select[data-group="' + gid + '"]').forEach(function(s){
                fillPaidBySelect(s, res.members);
              });
            }
          });
        }
      });

      function fillPaidBySelect(sel, members) {
        sel.innerHTML = '<option value="">Paid by…</option>';
        members.forEach(function(m){
          sel.innerHTML += '<option value="' + m.user_id + '">' + escHtml(m.username) + '</option>';
        });
      }

      // Bind add-price buttons
      $container.querySelectorAll('.unpriced-add-btn').forEach(function(btn){
        btn.addEventListener('click', function(){
          var row = btn.closest('.unpriced-row');
          var input = row.querySelector('.unpriced-price-input');
          var price = parseFloat(input.value);
          if (!price || price <= 0) { input.focus(); input.style.borderColor = '#ef4444'; return; }
          var paidBy = '';
          var paidBySel = row.querySelector('.unpriced-paidby-select');
          if (paidBySel) {
            paidBy = paidBySel.value;
            if (!paidBy) { paidBySel.focus(); paidBySel.style.borderColor = '#ef4444'; return; }
          }
          priceItem(btn.dataset.id, price, btn, paidBy);
        });
      });

      // Allow Enter key in price input
      $container.querySelectorAll('.unpriced-price-input').forEach(function(inp){
        inp.addEventListener('keydown', function(e){
          if (e.key === 'Enter') {
            e.preventDefault();
            inp.closest('.unpriced-row').querySelector('.unpriced-add-btn').click();
          }
        });
        inp.addEventListener('input', function(){ inp.style.borderColor = ''; });
      });
    } catch(e) { console.error(e); }
  };

  function formatCheckedDate(d) {
    if (!d) return '\u2014';
    var dt = new Date(d + 'T00:00:00');
    var months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
    return months[dt.getMonth()] + ' ' + dt.getDate() + ', ' + dt.getFullYear();
  }

  async function priceItem(itemId, price, btn, paidBy) {
    btn.disabled = true;
    btn.textContent = '\u2026';
    try {
      var res = await post(API + '/expenses/price_unpriced.php', {
        item_id: itemId,
        price: price,
        paid_by: paidBy || undefined
      });
      if (res.ok) {
        // Animate row removal
        var row = btn.closest('.unpriced-row');
        row.style.transition = 'opacity 0.3s, transform 0.3s';
        row.style.opacity = '0';
        row.style.transform = 'translateX(20px)';
        setTimeout(function(){ loadUnpricedItems(); }, 300);
      } else {
        alert(res.error || 'Failed to add price.');
        btn.disabled = false;
        btn.textContent = '\u2713';
      }
    } catch(e) {
      alert('Network error.');
      btn.disabled = false;
      btn.textContent = '\u2713';
    }
  }

  // Load on init
  loadUnpricedItems();
})();

/* ========== SETTLEMENT MODULE ========== */
(function(){
  var currentUserId = <?= $currentUserId ?>;
  var firstName = <?= json_encode($firstName) ?>;
  var fmtMoney = window._expFmtMoney;
  var escHtml = escapeHTML;

  var $groupSelect  = document.getElementById('settlGroupSelect');
  var $loading      = document.getElementById('settlLoading');
  var $content      = document.getElementById('settlContent');
  var $totalSpend   = document.getElementById('settlTotalSpend');
  var $perPerson    = document.getElementById('settlPerPerson');
  var $myContrib    = document.getElementById('settlMyContrib');
  var $breakdownList  = document.getElementById('settlBreakdownList');
  var $breakdownEmpty = document.getElementById('settlBreakdownEmpty');
  var $userPeriod   = document.getElementById('settlUserPeriod');
  var $userStatus   = document.getElementById('settlUserStatus');
  var $userNone     = document.getElementById('settlUserNone');
  var $historyList  = document.getElementById('settlHistoryList');
  var $historyEmpty = document.getElementById('settlHistoryEmpty');
  var $detailModal   = document.getElementById('settlDetailModal');
  var $detailClose   = document.getElementById('settlDetailClose');
  var $detailContent = document.getElementById('settlDetailContent');
  var $detailTotal   = document.getElementById('settlDetailTotal');
  var $downloadPdf   = document.getElementById('settlDownloadPdf');
  var $settleBtn     = document.getElementById('settlSettleBtn');
  var $confirmedMsg  = document.getElementById('settlConfirmedMsg');
  var $confirmedIcon = document.getElementById('settlConfirmedIcon');
  var $confirmedText = document.getElementById('settlConfirmedText');
  var $waitingText   = document.getElementById('settlWaitingText');
  var lastDetailData = null;

  var groupsLoaded = false;
  var currentGroupId = null;
  var isGroupAdmin = false;
  var cachedCalcData = null;

  window.loadSettlementGroups = async function(){
    if (groupsLoaded) return;
    try {
      var res = await get(API + '/groups/user_groups.php');
      if (res.ok) {
        $groupSelect.innerHTML = '<option value="">\u2014 Choose a group \u2014</option>' +
          res.groups.map(function(g){ return '<option value="' + g.id + '">' + escHtml(g.name) + '</option>'; }).join('');
        groupsLoaded = true;
      }
    } catch(e) { console.error(e); }
  };

  $groupSelect.onchange = function(){
    var gid = $groupSelect.value;
    if (!gid) { $content.classList.add('hidden'); $settleBtn.classList.add('hidden'); return; }
    currentGroupId = gid;
    loadSettlement(gid);
  };

  async function loadSettlement(groupId) {
    $loading.classList.remove('hidden');
    $content.classList.add('hidden');
    try {
      var results = await Promise.all([
        get(API + '/settlements/calculate.php?group_id=' + groupId),
        get(API + '/settlements/history.php?group_id=' + groupId)
      ]);
      var calcRes = results[0], histRes = results[1];
      $loading.classList.add('hidden');
      if (!calcRes.ok) { alert(calcRes.error || 'Failed to load settlement.'); return; }
      $content.classList.remove('hidden');
      cachedCalcData = calcRes;
      // Check if current user is admin
      var myMemberInfo = calcRes.members.find(function(m){return m.user_id === currentUserId;});
      isGroupAdmin = myMemberInfo && myMemberInfo.role === 'admin';
      // Show settle button to ALL members when there is unsettled spending
      // Hide if user already confirmed or no spending
      if (calcRes.total_spend > 0 && !calcRes.user_confirmed) {
        $settleBtn.classList.remove('hidden');
      } else {
        $settleBtn.classList.add('hidden');
      }
      renderSettlementSummary(calcRes);
      renderBreakdown(calcRes);
      renderUserStatus(calcRes);
      renderHistory(histRes.ok ? histRes.settlements : [], groupId);
      if (calcRes.post_settlement_count > 0) {
        loadPostSettlement(groupId);
      } else {
        document.getElementById('postSettlCard').classList.add('hidden');
      }
    } catch(e) {
      $loading.classList.add('hidden');
      console.error(e);
    }
  }

  function renderSettlementSummary(data) {
    $totalSpend.textContent = fmtMoney(data.total_spend);
    $perPerson.textContent  = fmtMoney(data.per_person);
    var myMember = data.members.find(function(m){return m.user_id === currentUserId;});
    $myContrib.textContent = fmtMoney(myMember ? myMember.contribution : 0);
  }

  function renderBreakdown(data) {
    if (!data.members.length) { $breakdownList.innerHTML = ''; $breakdownEmpty.classList.remove('hidden'); return; }
    $breakdownEmpty.classList.add('hidden');

    var owesMap = {};
    var recvMap = {};
    data.members.forEach(function(m){ owesMap[m.user_id] = []; recvMap[m.user_id] = []; });
    data.settlements.forEach(function(s){
      owesMap[s.from_id].push({ to: s.to_username, amount: s.amount });
      recvMap[s.to_id].push({ from: s.from_username, amount: s.amount });
    });

    $breakdownList.innerHTML = data.members.map(function(m){
      var owes = owesMap[m.user_id] || [];
      var recv = recvMap[m.user_id] || [];
      var totalOwes = owes.reduce(function(a,x){return a+x.amount;}, 0);
      var totalRecv = recv.reduce(function(a,x){return a+x.amount;}, 0);

      var detail = '';
      if (owes.length) {
        detail += owes.map(function(o){ return '<div style="font-size:0.78rem;color:#ef4444;">Owes ' + fmtMoney(o.amount) + ' to ' + escHtml(o.to) + '</div>'; }).join('');
      }
      if (recv.length) {
        var fromNames = recv.map(function(r){ return escHtml(r.from); }).join(' and ');
        detail += '<div style="font-size:0.78rem;color:#22c55e;">To be paid ' + fmtMoney(totalRecv) + ' by ' + fromNames + '</div>';
      }
      if (!owes.length && !recv.length) {
        detail = '<div style="font-size:0.78rem;color:#666;">Owes to none &middot; To be paid by none</div>';
      }

      var amountText = totalOwes > 0 ? '<span style="color:#ef4444;">' + fmtMoney(totalOwes) + '</span>' :
                        totalRecv > 0 ? '<span style="color:#22c55e;">' + fmtMoney(totalRecv) + '</span>' :
                        '<span style="color:#666;">\u20B90.00</span>';

      return '<div style="padding:0.75rem 0;border-bottom:1px solid rgba(0,0,0,0.08);">'
        + '<div style="display:flex;justify-content:space-between;align-items:center;">'
        + '<span style="font-weight:600;font-size:0.88rem;">' + escHtml(m.username) + '</span>'
        + amountText
        + '</div>'
        + detail
        + '</div>';
    }).join('');
  }

  function renderUserStatus(data) {
    // Reset all status sections
    $userStatus.innerHTML = '';
    $confirmedMsg.classList.add('hidden');
    $confirmedIcon.classList.add('hidden');
    $confirmedText.textContent = '';
    $waitingText.textContent = '';
    $userNone.classList.add('hidden');

    if (data.period_start) {
      $userPeriod.innerHTML = '<strong>Period:</strong> ' + formatDate(data.period_start) + ' to ' + formatDate(data.period_end);
    } else {
      $userPeriod.innerHTML = '<strong>Period:</strong> No expenses recorded yet.';
    }

    var userOwes = data.settlements.filter(function(s){return s.from_id === currentUserId;});
    var userRecv = data.settlements.filter(function(s){return s.to_id === currentUserId;});
    var hasDebits  = userOwes.length > 0;
    var hasCredits = userRecv.length > 0;

    // If no spending at all
    if (data.total_spend <= 0) {
      $userNone.classList.remove('hidden');
      return;
    }

    // If user already confirmed their settlement
    if (data.user_confirmed) {
      $confirmedMsg.classList.remove('hidden');
      $confirmedMsg.style.opacity = '0';
      var remaining = data.member_count - data.confirmed_count;
      if (remaining <= 0) {
        // All members settled
        $confirmedIcon.classList.remove('hidden');
        $confirmedText.textContent = 'You are all settled up!';
        $waitingText.textContent = '';
      } else {
        var label = hasDebits ? 'debits' : hasCredits ? 'credits' : 'obligations';
        $confirmedText.textContent = 'You have successfully settled your ' + label + '.';
        $waitingText.textContent = 'Waiting for ' + remaining + ' member(s) to settle.';
      }
      // Animate in
      requestAnimationFrame(function(){ $confirmedMsg.style.opacity = '1'; });
      return;
    }

    // Not confirmed — show normal debit/credit details
    if (!hasDebits && !hasCredits) {
      $userNone.classList.remove('hidden');
      return;
    }

    var html = '';
    userOwes.forEach(function(s){
      html += '<div style="display:flex;align-items:center;gap:0.75rem;padding:0.6rem 0;border-bottom:1px solid rgba(0,0,0,0.08);">'
        + '<div style="flex:1;">'
        + '<div style="font-size:0.85rem;font-weight:500;">' + escHtml(firstName) + ' \u2192 ' + escHtml(s.to_username) + '</div>'
        + '<div style="font-size:0.72rem;color:#666;">' + escHtml(firstName) + ' (Debtor) \u2192 ' + escHtml(s.to_username) + ' (Creditor)</div>'
        + '</div>'
        + '<div style="font-weight:700;color:#ef4444;font-size:0.95rem;">\u2193 ' + fmtMoney(s.amount) + '</div>'
        + '</div>';
    });
    userRecv.forEach(function(s){
      html += '<div style="display:flex;align-items:center;gap:0.75rem;padding:0.6rem 0;border-bottom:1px solid rgba(0,0,0,0.08);">'
        + '<div style="flex:1;">'
        + '<div style="font-size:0.85rem;font-weight:500;">' + escHtml(s.from_username) + ' \u2192 ' + escHtml(firstName) + '</div>'
        + '<div style="font-size:0.72rem;color:#666;">' + escHtml(s.from_username) + ' (Debtor) \u2192 ' + escHtml(firstName) + ' (Creditor)</div>'
        + '</div>'
        + '<div style="font-weight:700;color:#22c55e;font-size:0.95rem;">\u2191 ' + fmtMoney(s.amount) + '</div>'
        + '</div>';
    });
    $userStatus.innerHTML = html;
  }

  function renderHistory(settlements, groupId) {
    if (!settlements.length) {
      $historyList.innerHTML = '';
      $historyEmpty.classList.remove('hidden');
      return;
    }
    $historyEmpty.classList.add('hidden');
    $historyList.innerHTML = settlements.map(function(s){
      return '<div style="padding:0.75rem 0;border-bottom:1px solid rgba(0,0,0,0.08);">'
        + '<div style="font-size:0.85rem;font-weight:500;">' + escHtml(s.payer_name) + ' paid ' + escHtml(s.payee_name) + '</div>'
        + '<div style="font-size:0.82rem;color:#000;margin-top:0.15rem;">Amount: <strong>' + fmtMoney(s.amount) + '</strong></div>'
        + '<div style="font-size:0.72rem;color:#666;margin-top:0.1rem;">Period: ' + formatDate(s.period_start) + ' \u2013 ' + formatDate(s.period_end) + '</div>'
        + '<button class="btn-outline" style="margin-top:0.5rem;padding:0.25rem 0.6rem;font-size:0.72rem;border-radius:0.4rem;" onclick="viewSettlementDetail(' + s.id + ',' + groupId + ',\'' + s.period_start + '\',\'' + s.period_end + '\')">View More Details</button>'
        + '</div>';
    }).join('');
  }

  function formatDate(d) {
    if (!d) return '\u2014';
    var dt = new Date(d + 'T00:00:00');
    var months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
    return months[dt.getMonth()] + ' ' + dt.getDate() + ', ' + dt.getFullYear();
  }

  window.viewSettlementDetail = async function(settlId, groupId, start, end) {
    $detailModal.classList.remove('hidden');
    $detailModal.style.display = 'flex';
    $detailContent.innerHTML = '<p style="text-align:center;color:#666;padding:1rem;">Loading\u2026</p>';
    $detailTotal.textContent = '';
    $downloadPdf.classList.add('hidden'); $downloadPdf.style.display = 'none';
    lastDetailData = null;
    try {
      var res = await get(API + '/settlements/details.php?group_id=' + groupId + '&start=' + start + '&end=' + end);
      if (!res.ok) { $detailContent.innerHTML = '<p style="color:#ef4444;">Failed to load details.</p>'; return; }
      if (!res.expenses.length) { $detailContent.innerHTML = '<p style="color:#666;text-align:center;">No expenses found for this period.</p>'; return; }
      lastDetailData = { expenses: res.expenses, total: res.total, start: start, end: end };
      $detailContent.innerHTML = '<div style="display:grid;grid-template-columns:2fr 1fr 1fr 1fr;gap:0.5rem;font-size:0.72rem;font-weight:600;color:#666;padding-bottom:0.5rem;border-bottom:1px solid #e0e0e0;text-transform:uppercase;">'
        + '<span>Item</span><span>Category</span><span>Date</span><span style="text-align:right;">Amount</span>'
        + '</div>'
        + res.expenses.map(function(e){
          return '<div style="display:grid;grid-template-columns:2fr 1fr 1fr 1fr;gap:0.5rem;font-size:0.82rem;padding:0.5rem 0;border-bottom:1px solid rgba(0,0,0,0.06);">'
            + '<span style="white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">' + escHtml(e.note || '\u2014') + ' <span style="font-size:0.7rem;color:#666;">paid by ' + escHtml(e.payer_username || e.added_by) + '</span></span>'
            + '<span style="color:#666;">' + escHtml(e.category_name) + '</span>'
            + '<span style="color:#666;">' + e.expense_date + '</span>'
            + '<span style="text-align:right;font-weight:600;">' + fmtMoney(e.amount) + '</span>'
            + '</div>';
        }).join('');
      $detailTotal.textContent = 'Total Settled Amount: ' + fmtMoney(res.total);
      $downloadPdf.classList.remove('hidden'); $downloadPdf.style.display = 'block';
    } catch(e) {
      $detailContent.innerHTML = '<p style="color:#ef4444;">Network error.</p>';
    }
  };

  var closeDetailModal = function(){ $detailModal.classList.add('hidden'); $detailModal.style.display = ''; };
  $detailClose.onclick = closeDetailModal;
  $detailModal.onclick = function(ev){ if(ev.target === $detailModal) closeDetailModal(); };

  /* ---- Download Settlement PDF ---- */
  $downloadPdf.onclick = function(){
    if (!lastDetailData) return;
    var jsPDF = window.jspdf.jsPDF;
    var doc = new jsPDF({ orientation:'portrait', unit:'mm', format:'a4' });
    var pageW = doc.internal.pageSize.getWidth();
    var pdfMoney = function(n){ return 'Rs. ' + Number(n).toLocaleString('en-IN', {minimumFractionDigits:2, maximumFractionDigits:2}); };

    // Title
    doc.setFontSize(16);
    doc.setFont('helvetica','bold');
    doc.text('Settlement Details', pageW/2, 18, {align:'center'});

    // Period line
    doc.setFontSize(10);
    doc.setFont('helvetica','normal');
    doc.text('Period: ' + formatDate(lastDetailData.start) + '  to  ' + formatDate(lastDetailData.end), pageW/2, 26, {align:'center'});

    // Table
    var rows = lastDetailData.expenses.map(function(e, i){
      return [
        (i+1).toString(),
        e.note || '-',
        e.payer_username || e.added_by,
        e.category_name,
        e.expense_date,
        pdfMoney(e.amount)
      ];
    });

    doc.autoTable({
      startY: 32,
      head: [['#','Item','Paid By','Category','Date','Amount']],
      body: rows,
      styles: { fontSize:9, cellPadding:2.5, overflow:'linebreak' },
      headStyles: { fillColor:[82,183,136], textColor:255, fontStyle:'bold' },
      columnStyles: {
        0: {halign:'center', cellWidth:10},
        1: {cellWidth:45},
        2: {cellWidth:30},
        3: {cellWidth:28},
        4: {cellWidth:24},
        5: {halign:'right', cellWidth:28}
      },
      alternateRowStyles: { fillColor:[245,245,245] },
      margin: {left:14,right:14},
      tableWidth: 'wrap'
    });

    // Total row below the table
    var finalY = doc.lastAutoTable.finalY + 6;
    doc.setFontSize(11);
    doc.setFont('helvetica','bold');
    doc.text('Total Settled Amount: ' + pdfMoney(lastDetailData.total), pageW - 14, finalY, {align:'right'});

    // Footer
    doc.setFontSize(8);
    doc.setFont('helvetica','normal');
    doc.setTextColor(150);
    doc.text('Generated on ' + new Date().toLocaleString(), pageW/2, doc.internal.pageSize.getHeight() - 8, {align:'center'});

    doc.save('Settlement_' + lastDetailData.start + '_to_' + lastDetailData.end + '.pdf');
  };

  /* ---- Individual Settlement Confirmation ---- */
  $settleBtn.onclick = async function(){
    if (!currentGroupId) return;
    if (!confirm('Confirm your settlement for this group? This acknowledges your debts/credits.')) return;
    $settleBtn.disabled = true;
    $settleBtn.textContent = 'Confirming\u2026';
    try {
      var res = await post(API + '/settlements/confirm.php', {
        group_id: currentGroupId
      });
      if (res.ok) {
        // Reload settlement data to reflect updated confirmation state
        await loadSettlement(currentGroupId);
      } else {
        alert(res.error || 'Failed to confirm settlement.');
      }
    } catch(e) {
      console.error(e);
      alert('Network error.');
    }
    $settleBtn.disabled = false;
    $settleBtn.textContent = 'Settle';
  };

  /* ---- Post-Settlement (Late Expenses) Module ---- */
  var $psCard          = document.getElementById('postSettlCard');
  var $psBtn           = document.getElementById('postSettlBtn');
  var $psTotal         = document.getElementById('postSettlTotal');
  var $psPerPerson     = document.getElementById('postSettlPerPerson');
  var $psExpenseList   = document.getElementById('postSettlExpenseList');
  var $psBreakdown     = document.getElementById('postSettlBreakdown');
  var $psUserStatus    = document.getElementById('postSettlUserStatus');
  var $psConfirmedMsg  = document.getElementById('postSettlConfirmedMsg');
  var $psConfirmedIcon = document.getElementById('postSettlConfirmedIcon');
  var $psConfirmedText = document.getElementById('postSettlConfirmedText');
  var $psWaitingText   = document.getElementById('postSettlWaitingText');

  window.loadPostSettlement = async function(groupId) {
    // Reset
    $psCard.classList.add('hidden');
    if (!groupId) return;

    try {
      var res = await get(API + '/settlements/post_calculate.php?group_id=' + groupId);
      if (!res.ok || !res.has_expenses) return;

      $psCard.classList.remove('hidden');
      $psTotal.textContent = fmtMoney(res.total_spend);
      $psPerPerson.textContent = fmtMoney(res.per_person);

      // Show settle button if user hasn't confirmed yet
      if (!res.user_confirmed) {
        $psBtn.classList.remove('hidden');
      } else {
        $psBtn.classList.add('hidden');
      }

      // Render late expenses list
      if (res.expenses.length) {
        $psExpenseList.innerHTML = '<div style="font-size:0.78rem;font-weight:600;color:#92400e;margin-bottom:0.4rem;">Late Expenses (' + res.expenses.length + ')</div>'
          + res.expenses.map(function(e){
            return '<div style="display:flex;align-items:center;gap:0.5rem;padding:0.4rem 0;border-bottom:1px solid rgba(0,0,0,0.06);font-size:0.82rem;">'
              + '<div style="flex:1;min-width:0;"><span style="font-weight:500;">' + escHtml(e.note || '\u2014') + '</span> <span style="font-size:0.7rem;color:#666;">paid by ' + escHtml(e.payer_username || e.added_by) + '</span></div>'
              + '<div style="font-size:0.72rem;color:#666;">' + e.expense_date + '</div>'
              + '<div style="font-weight:600;white-space:nowrap;">' + fmtMoney(e.amount) + '</div>'
              + '</div>';
          }).join('');
      } else {
        $psExpenseList.innerHTML = '';
      }

      // Render breakdown
      if (res.settlements.length) {
        $psBreakdown.innerHTML = '<div style="font-size:0.78rem;font-weight:600;color:#92400e;margin-bottom:0.4rem;">Settlement Breakdown</div>'
          + res.settlements.map(function(s){
            return '<div style="display:flex;align-items:center;gap:0.5rem;padding:0.45rem 0;border-bottom:1px solid rgba(0,0,0,0.06);font-size:0.82rem;">'
              + '<div style="flex:1;">' + escHtml(s.from_username) + ' \u2192 ' + escHtml(s.to_username) + '</div>'
              + '<div style="font-weight:600;color:#ef4444;">' + fmtMoney(s.amount) + '</div>'
              + '</div>';
          }).join('');
      } else {
        $psBreakdown.innerHTML = '';
      }

      // Render user status
      renderPostSettlStatus(res);
    } catch(e) { console.error(e); }
  };

  function renderPostSettlStatus(data) {
    $psUserStatus.innerHTML = '';
    $psConfirmedMsg.classList.add('hidden');
    $psConfirmedIcon.classList.add('hidden');
    $psConfirmedText.textContent = '';
    $psWaitingText.textContent = '';

    if (data.user_confirmed) {
      $psConfirmedMsg.classList.remove('hidden');
      $psConfirmedMsg.style.opacity = '0';
      var remaining = data.member_count - data.confirmed_count;
      if (remaining <= 0) {
        $psConfirmedIcon.classList.remove('hidden');
        $psConfirmedText.textContent = 'You are all settled up!';
        $psWaitingText.textContent = '';
        $psBtn.classList.add('hidden');
      } else {
        $psConfirmedText.textContent = 'You have confirmed the late expenses settlement.';
        $psWaitingText.textContent = 'Waiting for ' + remaining + ' member(s) to settle.';
      }
      requestAnimationFrame(function(){ $psConfirmedMsg.style.opacity = '1'; });
      return;
    }

    // Show debit/credit for current user
    var userOwes = data.settlements.filter(function(s){return s.from_id === currentUserId;});
    var userRecv = data.settlements.filter(function(s){return s.to_id === currentUserId;});

    if (!userOwes.length && !userRecv.length) {
      $psUserStatus.innerHTML = '<p style="text-align:center;color:#666;font-size:0.82rem;padding:0.5rem 0;">Equal contribution — no transfers needed.</p>';
      return;
    }

    var html = '';
    userOwes.forEach(function(s){
      html += '<div style="display:flex;align-items:center;gap:0.5rem;padding:0.45rem 0;border-bottom:1px solid rgba(0,0,0,0.06);font-size:0.82rem;">'
        + '<div style="flex:1;">' + escHtml(firstName) + ' \u2192 ' + escHtml(s.to_username) + '</div>'
        + '<div style="font-weight:700;color:#ef4444;">\u2193 ' + fmtMoney(s.amount) + '</div>'
        + '</div>';
    });
    userRecv.forEach(function(s){
      html += '<div style="display:flex;align-items:center;gap:0.5rem;padding:0.45rem 0;border-bottom:1px solid rgba(0,0,0,0.06);font-size:0.82rem;">'
        + '<div style="flex:1;">' + escHtml(s.from_username) + ' \u2192 ' + escHtml(firstName) + '</div>'
        + '<div style="font-weight:700;color:#22c55e;">\u2191 ' + fmtMoney(s.amount) + '</div>'
        + '</div>';
    });
    $psUserStatus.innerHTML = html;
  }

  $psBtn.onclick = async function(){
    if (!currentGroupId) return;
    if (!confirm('Confirm the late expenses settlement for this group?')) return;
    $psBtn.disabled = true;
    $psBtn.textContent = 'Confirming\u2026';
    try {
      var res = await post(API + '/settlements/post_confirm.php', {
        group_id: currentGroupId
      });
      if (res.ok) {
        await loadPostSettlement(currentGroupId);
        // If all settled, also reload main settlement
        if (res.all_settled) await loadSettlement(currentGroupId);
      } else {
        alert(res.error || 'Failed to confirm.');
      }
    } catch(e) {
      console.error(e);
      alert('Network error.');
    }
    $psBtn.disabled = false;
    $psBtn.textContent = 'Settle';
  };

})();
</script>

<style>
/* Tab Switcher — pill style */
.exp-tabs-container {
  display:flex;
  background:#efefef;
  border-radius:0.75rem;
  padding:0.25rem;
  margin-bottom:1rem;
  gap:0.25rem;
}
.exp-tab {
  flex:1; padding:0.6rem 1rem; font-size:0.9rem; font-weight:600;
  border:none; cursor:pointer; border-radius:0.55rem;
  transition: background 0.25s, color 0.25s, box-shadow 0.25s;
  background:transparent; color:#888;
}
.exp-tab.active {
  background:#fff; color:#000;
  box-shadow:0 1px 4px rgba(0,0,0,0.1);
}
.exp-tab:not(.active):hover {
  color:#555;
}

/* Inline Date Filter */
.exp-date-filter-row {
  display:flex;
  align-items:center;
  gap:0.5rem;
  background:#efefef;
  border-radius:0.75rem;
  padding:0.3rem 0.4rem;
  margin-bottom:1rem;
}
.exp-date-input-wrap {
  flex:1;
  min-width:0;
  background:#fff;
  border-radius:0.55rem;
  overflow:hidden;
}
.exp-date-input {
  width:100%;
  border:none;
  background:transparent;
  padding:0.55rem 0.6rem;
  font-size:0.88rem;
  font-weight:600;
  color:#333;
  font-family:inherit;
  outline:none;
  box-sizing:border-box;
}
.exp-date-input::-webkit-calendar-picker-indicator { opacity:0.5; cursor:pointer; }
.exp-date-to {
  font-size:0.82rem;
  color:#999;
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
.exp-date-apply { background:var(--mint-leaf); color:#fff; }
.exp-date-apply:hover { opacity:0.85; }
.exp-date-clear { background:#ef4444; color:#fff; }
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
  #panelSettlement [style*="grid-template-columns:repeat(3"] { grid-template-columns: 1fr !important; }
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
  background: #f5f5f5;
  color: #000;
  border: 1px solid #ccc;
  border-radius: 0.4rem;
  padding: 0.25rem 0.5rem;
  font-size: 0.75rem;
  font-family: inherit;
  cursor: pointer;
  outline: none;
  transition: border-color 0.2s;
}
.exp-sort-select:focus { border-color: var(--mint-leaf); }

/* Two-Column Expense Layout */
.exp-two-col {
  display: flex;
  gap: 0.75rem;
}
.exp-col {
  flex: 1;
  min-width: 0;
  background: #fafafa;
  border-radius: 0.6rem;
  border: 1px solid rgba(0,0,0,0.06);
  display: flex;
  flex-direction: column;
}
.exp-col-header {
  font-size: 0.78rem;
  font-weight: 600;
  padding: 0.6rem 0.75rem;
  border-bottom: 1px solid rgba(0,0,0,0.08);
  display: flex;
  align-items: center;
  gap: 0.4rem;
  color: #333;
}
.exp-pdf-btn {
  margin-left: auto;
  background: none;
  border: 1px solid rgba(0,0,0,0.12);
  border-radius: 0.35rem;
  padding: 0.15rem 0.4rem;
  font-size: 0.72rem;
  cursor: pointer;
  color: #555;
  transition: background 0.2s, border-color 0.2s;
  line-height: 1;
}
.exp-pdf-btn:hover {
  background: rgba(82,183,136,0.1);
  border-color: var(--mint-leaf);
  color: var(--mint-leaf);
}
.exp-col-dot {
  width: 8px;
  height: 8px;
  border-radius: 50%;
  flex-shrink: 0;
}
.exp-col-dot--personal { background: #3b82f6; }
.exp-col-dot--group    { background: var(--mint-leaf); }
.exp-col-body {
  max-height: 360px;
  overflow-y: auto;
  padding: 0 0.5rem;
}
.exp-col-empty {
  text-align: center;
  color: #888;
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
  border-bottom: 1px solid rgba(0,0,0,0.06);
  border-radius: 0.3rem;
  transition: background 0.15s;
}
.exp-row:last-child { border-bottom: none; }
.exp-row:hover { background: rgba(82,183,136,0.06); }
.exp-row-dot {
  width: 7px;
  height: 7px;
  border-radius: 50%;
  flex-shrink: 0;
}
.exp-row-dot--personal  { background: #3b82f6; }
.exp-row-dot--unsettled { background: #22c55e; }
.exp-row-dot--settled   { background: #9ca3af; }
.exp-row-info {
  flex: 1;
  min-width: 0;
}
.exp-row-name {
  font-size: 0.8rem;
  font-weight: 500;
  color: #000;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.exp-row-grouptag {
  font-size: 0.62rem;
  background: rgba(82,183,136,0.15);
  color: var(--mint-leaf);
  padding: 0.05rem 0.35rem;
  border-radius: 999px;
  vertical-align: middle;
}
.exp-row-meta {
  font-size: 0.68rem;
  color: #666;
  margin-top: 0.1rem;
}
.exp-row-amt {
  font-weight: 600;
  font-size: 0.82rem;
  color: #000;
  white-space: nowrap;
  flex-shrink: 0;
}

/* Unpriced Items Section */
.unpriced-card {
  border: 1px dashed #d4a843;
  background: #fffdf5;
}
.unpriced-badge {
  font-size: 0.7rem;
  font-weight: 700;
  background: #f59e0b;
  color: #fff;
  padding: 0.1rem 0.5rem;
  border-radius: 999px;
  min-width: 20px;
  text-align: center;
}
.unpriced-row {
  display: flex;
  align-items: center;
  gap: 0.75rem;
  padding: 0.65rem 0;
  border-bottom: 1px solid rgba(0,0,0,0.06);
}
.unpriced-row:last-child { border-bottom: none; }
.unpriced-info { flex: 1; min-width: 0; }
.unpriced-name {
  font-size: 0.85rem;
  font-weight: 500;
  color: #000;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.unpriced-meta { font-size: 0.72rem; color: #666; margin-top: 0.15rem; }
.unpriced-src {
  font-size: 0.65rem;
  background: rgba(82,183,136,0.12);
  color: var(--sea-green);
  padding: 0.05rem 0.35rem;
  border-radius: 999px;
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
  border: 1px solid #ccc;
  border-radius: 0.4rem;
  font-size: 0.82rem;
  font-family: inherit;
  background: #fff;
  color: #000;
  outline: none;
  transition: border-color 0.2s;
}
.unpriced-price-input:focus { border-color: var(--mint-leaf); }
.unpriced-add-btn {
  width: 32px;
  height: 32px;
  border-radius: 50%;
  border: none;
  background: var(--mint-leaf);
  color: #fff;
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

/* Post-Settlement (Late Expenses) Section */
.post-settl-card {
  border: 1px dashed #f59e0b;
  background: #fffdf5;
}
.post-settl-card .card-header {
  background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
}
.ps-expense-row {
  display: flex;
  align-items: center;
  gap: 0.75rem;
  padding: 0.55rem 0;
  border-bottom: 1px solid rgba(0,0,0,0.06);
}
.ps-expense-row:last-child { border-bottom: none; }
.ps-exp-info { flex: 1; min-width: 0; }
.ps-exp-desc {
  font-size: 0.85rem;
  font-weight: 500;
  color: #000;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.ps-exp-meta { font-size: 0.72rem; color: #666; margin-top: 0.15rem; }
.ps-exp-amt {
  font-size: 0.85rem;
  font-weight: 600;
  color: #d97706;
  flex-shrink: 0;
}
</style>
