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
    <button id="expSetBudgetBtn" class="btn-outline" style="padding:0.3rem 0.7rem;font-size:0.75rem;border-radius:0.4rem;">Set Budget &gt;</button>
  </div>
</div>

<!-- Month Selector -->
<div style="display:flex;align-items:center;justify-content:center;gap:1rem;margin-bottom:1.25rem;">
  <button id="expPrevMonth" class="btn-outline" style="padding:0.35rem 0.65rem;font-size:0.85rem;border-radius:0.4rem;">&#8592;</button>
  <h2 id="expMonthLabel" style="font-size:1.15rem;font-weight:700;min-width:160px;text-align:center;"></h2>
  <button id="expNextMonth" class="btn-outline" style="padding:0.35rem 0.65rem;font-size:0.85rem;border-radius:0.4rem;">&#8594;</button>
</div>

<!-- Summary Cards Row -->
<div id="expSummaryRow" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:0.75rem;margin-bottom:1.25rem;">
  <div class="card" style="padding:1.25rem;">
    <div style="font-size:0.78rem;text-transform:uppercase;letter-spacing:0.04em;color:#666;margin-bottom:0.35rem;">Total Spent</div>
    <div id="expTotalSpent" style="font-size:1.6rem;font-weight:700;color:#000;">&mdash;</div>
  </div>
  <div class="card" style="padding:1.25rem;">
    <div style="font-size:0.78rem;text-transform:uppercase;letter-spacing:0.04em;color:#666;margin-bottom:0.35rem;">Breakdown</div>
    <div style="display:flex;gap:1.5rem;margin-top:0.35rem;">
      <div>
        <div style="font-size:0.72rem;color:#666;">Personal</div>
        <div id="expPersonal" style="font-size:1.15rem;font-weight:600;color:#000;">&mdash;</div>
      </div>
      <div>
        <div style="font-size:0.72rem;color:#666;">Group</div>
        <div id="expGroup" style="font-size:1.15rem;font-weight:600;color:#000;">&mdash;</div>
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

<!-- Recent Expenses List -->
<div class="card" style="padding:1.25rem;">
  <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:0.75rem;">
    <span style="font-size:0.82rem;font-weight:600;">Month's Expenses</span>
    <select id="expTypeFilter" style="background:#f5f5f5;color:#000;border:1px solid #ccc;border-radius:0.4rem;padding:0.25rem 0.5rem;font-size:0.75rem;">
      <option value="all">All</option>
      <option value="personal">Personal</option>
      <option value="group">Group</option>
    </select>
  </div>
  <div id="expListContainer" style="max-height:360px;overflow-y:auto;"></div>
  <p id="expListEmpty" class="hidden" style="text-align:center;color:#666;font-size:0.82rem;padding:1.5rem 0;">No expenses found.</p>
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
      <button id="settlDetailClose" style="background:none;border:none;font-size:1.4rem;cursor:pointer;color:#666;">&times;</button>
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
  var $catCanvas   = document.getElementById('chartCategory');
  var $dayCanvas   = document.getElementById('chartDaily');
  var $catEmpty    = document.getElementById('chartCatEmpty');
  var $dayEmpty    = document.getElementById('chartDayEmpty');
  var $listCont    = document.getElementById('expListContainer');
  var $listEmpty   = document.getElementById('expListEmpty');
  var $typeFilter  = document.getElementById('expTypeFilter');

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

  var chartPalette = ['#52b788','#40916c','#74c69d','#2d6a4f','#95d5b2','#b7e4c7','#1b4332','#d8f3dc'];

  async function loadMonth(){
    updateLabel();
    var mk = monthKey();
    try {
      var results = await Promise.all([
        fetch('../api/expenses/summary.php?month=' + mk).then(function(r){return r.json();}),
        fetch('../api/expenses/list.php?month=' + mk).then(function(r){return r.json();}),
        fetch('../api/budgets/get.php?month=' + mk).then(function(r){return r.json();})
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

    if(d.by_category.length){
      $catEmpty.classList.add('hidden');
      $catCanvas.style.display = '';
      var labels = d.by_category.map(function(c){return c.name;});
      var values = d.by_category.map(function(c){return c.total;});
      if(catChart) catChart.destroy();
      catChart = new Chart($catCanvas, {
        type: 'doughnut',
        data: { labels: labels, datasets: [{ data: values, backgroundColor: chartPalette.slice(0, labels.length), borderWidth: 0 }] },
        options: { cutout:'60%', plugins:{ legend:{ display:true, position:'bottom', labels:{ color:'#000', font:{size:11}, padding:10 } }, tooltip:{ callbacks:{ label: function(ctx){ return ctx.label + ': ' + fmtMoney(ctx.parsed); } } } } }
      });
    } else {
      $catEmpty.classList.remove('hidden'); $catCanvas.style.display = 'none';
      if(catChart){ catChart.destroy(); catChart = null; }
    }

    if(d.by_day.length){
      $dayEmpty.classList.add('hidden'); $dayCanvas.style.display = '';
      var daysInMonth = new Date(curYear, curMonth+1, 0).getDate();
      var dayMap = {}; d.by_day.forEach(function(x){ dayMap[x.day] = x.total; });
      var dayLabels = [], dayValues = [];
      for(var i=1; i<=daysInMonth; i++){ dayLabels.push(i); dayValues.push(dayMap[i] || 0); }
      if(dayChart) dayChart.destroy();
      dayChart = new Chart($dayCanvas, {
        type: 'bar',
        data: { labels: dayLabels, datasets: [{ label:'Spent', data: dayValues, backgroundColor:'rgba(82,183,136,0.6)', borderRadius:3, maxBarThickness:18 }] },
        options: { responsive:true, scales:{ x:{ ticks:{ color:'#000', font:{size:10} }, grid:{ display:false } }, y:{ ticks:{ color:'#000', font:{size:10}, callback: function(v){return '\u20B9'+v;} }, grid:{ color:'rgba(0,0,0,0.08)' }, beginAtZero:true } }, plugins:{ legend:{ display:false }, tooltip:{ callbacks:{ label: function(ctx){return fmtMoney(ctx.parsed.y);} } } } }
      });
    } else {
      $dayEmpty.classList.remove('hidden'); $dayCanvas.style.display = 'none';
      if(dayChart){ dayChart.destroy(); dayChart = null; }
    }
  }

  function renderList(){
    var filter = $typeFilter.value;
    var filtered = filter === 'all' ? allExpenses : allExpenses.filter(function(e){return e.type === filter;});
    if(!filtered.length){ $listCont.innerHTML = ''; $listEmpty.classList.remove('hidden'); return; }
    $listEmpty.classList.add('hidden');
    $listCont.innerHTML = filtered.map(function(e){
      var icon = e.type === 'group' ? '\uD83D\uDC65' : '\uD83D\uDC64';
      var tag  = e.type === 'group' ? '<span style="font-size:0.65rem;background:rgba(82,183,136,0.15);color:var(--mint-leaf);padding:0.1rem 0.4rem;border-radius:999px;">' + escHtml(e.group_name || 'Group') + '</span>' : '';
      return '<div style="display:flex;align-items:center;gap:0.65rem;padding:0.6rem 0;border-bottom:1px solid rgba(0,0,0,0.08);">'
        + '<span style="font-size:1.1rem;">' + icon + '</span>'
        + '<div style="flex:1;min-width:0;">'
        + '<div style="font-size:0.82rem;font-weight:500;color:#000;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">' + escHtml(e.category_name) + (e.note ? ' \u2014 ' + escHtml(e.note) : '') + ' ' + tag + '</div>'
        + '<div style="font-size:0.7rem;color:#666;">' + e.expense_date + ' &middot; by ' + escHtml(e.added_by) + '</div>'
        + '</div>'
        + '<div style="font-weight:600;font-size:0.9rem;color:#000;white-space:nowrap;">' + fmtMoney(e.amount) + '</div>'
        + '</div>';
    }).join('');
  }

  function escHtml(s){ var d=document.createElement('div'); d.textContent=s; return d.innerHTML; }

  /* ---- Events ---- */
  $prevBtn.onclick = function(){ clearDateFilter(); curMonth--; if(curMonth<0){curMonth=11;curYear--;} loadMonth(); };
  $nextBtn.onclick = function(){ clearDateFilter(); curMonth++; if(curMonth>11){curMonth=0;curYear++;} loadMonth(); };
  $typeFilter.onchange = function(){ renderList(); };

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
      var listRes = await fetch('../api/expenses/list.php?start=' + encodeURIComponent(s) + '&end=' + encodeURIComponent(e)).then(function(r){return r.json();});
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
    var fd = new FormData();
    fd.append('month', monthKey());
    fd.append('amount_limit', val);
    try {
      var res = await fetch('../api/budgets/set.php', {method:'POST', body:fd}).then(function(r){return r.json();});
      if(res.ok){ closeModal(); loadMonth(); }
      else { alert(res.error || 'Failed to save budget.'); }
    } catch(err){ alert('Network error.'); }
  };

  loadMonth();
})();

/* ========== SETTLEMENT MODULE ========== */
(function(){
  var API = '../api';
  var currentUserId = <?= $currentUserId ?>;
  var firstName = <?= json_encode($firstName) ?>;
  var fmtMoney = window._expFmtMoney;
  function escHtml(s){ var d=document.createElement('div'); d.textContent=s; return d.innerHTML; }

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
      var res = await fetch(API + '/groups/user_groups.php').then(function(r){return r.json();});
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
        fetch(API + '/settlements/calculate.php?group_id=' + groupId).then(function(r){return r.json();}),
        fetch(API + '/settlements/history.php?group_id=' + groupId).then(function(r){return r.json();})
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
      var res = await fetch(API + '/settlements/details.php?group_id=' + groupId + '&start=' + start + '&end=' + end).then(function(r){return r.json();});
      if (!res.ok) { $detailContent.innerHTML = '<p style="color:#ef4444;">Failed to load details.</p>'; return; }
      if (!res.expenses.length) { $detailContent.innerHTML = '<p style="color:#666;text-align:center;">No expenses found for this period.</p>'; return; }
      lastDetailData = { expenses: res.expenses, total: res.total, start: start, end: end };
      $detailContent.innerHTML = '<div style="display:grid;grid-template-columns:2fr 1fr 1fr 1fr;gap:0.5rem;font-size:0.72rem;font-weight:600;color:#666;padding-bottom:0.5rem;border-bottom:1px solid #e0e0e0;text-transform:uppercase;">'
        + '<span>Item</span><span>Category</span><span>Date</span><span style="text-align:right;">Amount</span>'
        + '</div>'
        + res.expenses.map(function(e){
          return '<div style="display:grid;grid-template-columns:2fr 1fr 1fr 1fr;gap:0.5rem;font-size:0.82rem;padding:0.5rem 0;border-bottom:1px solid rgba(0,0,0,0.06);">'
            + '<span style="white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">' + escHtml(e.note || '\u2014') + ' <span style="font-size:0.7rem;color:#666;">by ' + escHtml(e.added_by) + '</span></span>'
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
        e.added_by,
        e.category_name,
        e.expense_date,
        pdfMoney(e.amount)
      ];
    });

    doc.autoTable({
      startY: 32,
      head: [['#','Item','Added By','Category','Date','Amount']],
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
      var fd = new FormData();
      fd.append('group_id', currentGroupId);
      var res = await fetch(API + '/settlements/confirm.php', {method:'POST', body:fd}).then(function(r){return r.json();});
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
}
</style>
