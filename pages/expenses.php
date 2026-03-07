<?php
/**
 * pages/expenses.php — Spending history, charts & budget tracking.
 * Included by public/index.php when page=expenses.
 * Uses Chart.js (loaded in index.php head).
 */
?>

<!-- Month Selector -->
<div style="display:flex;align-items:center;justify-content:center;gap:1rem;margin-bottom:1.25rem;">
  <button id="expPrevMonth" class="btn-outline" style="padding:0.35rem 0.65rem;font-size:0.85rem;border-radius:0.4rem;">&#8592;</button>
  <h2 id="expMonthLabel" style="font-size:1.15rem;font-weight:700;min-width:160px;text-align:center;"></h2>
  <button id="expNextMonth" class="btn-outline" style="padding:0.35rem 0.65rem;font-size:0.85rem;border-radius:0.4rem;">&#8594;</button>
</div>

<!-- Summary Cards Row -->
<div id="expSummaryRow" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:0.75rem;margin-bottom:1.25rem;">

  <!-- Total Spent / Budget -->
  <div class="card" style="padding:1.25rem;">
    <div style="font-size:0.78rem;text-transform:uppercase;letter-spacing:0.04em;color:var(--celadon);margin-bottom:0.35rem;">Total Spent</div>
    <div id="expTotalSpent" style="font-size:1.6rem;font-weight:700;color:var(--mint-leaf);">—</div>
    <div id="expBudgetBar" style="margin-top:0.6rem;display:none;">
      <div style="display:flex;justify-content:space-between;font-size:0.72rem;color:var(--celadon);margin-bottom:0.25rem;">
        <span id="expBudgetLabel">Budget: —</span>
        <span id="expBudgetPct">0%</span>
      </div>
      <div style="height:8px;background:rgba(255,255,255,0.08);border-radius:999px;overflow:hidden;">
        <div id="expBudgetFill" style="height:100%;border-radius:999px;background:var(--mint-leaf);width:0%;transition:width 0.6s ease;"></div>
      </div>
    </div>
    <button id="expSetBudgetBtn" class="btn-outline" style="margin-top:0.65rem;padding:0.3rem 0.7rem;font-size:0.75rem;border-radius:0.4rem;">Set Budget</button>
  </div>

  <!-- Personal vs Group -->
  <div class="card" style="padding:1.25rem;">
    <div style="font-size:0.78rem;text-transform:uppercase;letter-spacing:0.04em;color:var(--celadon);margin-bottom:0.35rem;">Breakdown</div>
    <div style="display:flex;gap:1.5rem;margin-top:0.35rem;">
      <div>
        <div style="font-size:0.72rem;color:var(--celadon);">Personal</div>
        <div id="expPersonal" style="font-size:1.15rem;font-weight:600;color:var(--light-mint);">—</div>
      </div>
      <div>
        <div style="font-size:0.72rem;color:var(--celadon);">Group</div>
        <div id="expGroup" style="font-size:1.15rem;font-weight:600;color:var(--light-mint);">—</div>
      </div>
    </div>
  </div>
</div>

<!-- Charts Row -->
<div id="expChartsRow" style="display:grid;grid-template-columns:1fr 1fr;gap:0.75rem;margin-bottom:1.25rem;">

  <!-- Category Doughnut -->
  <div class="card" style="padding:1.25rem;">
    <div style="font-size:0.82rem;font-weight:600;margin-bottom:0.75rem;">By Category</div>
    <div style="position:relative;max-width:220px;margin:0 auto;">
      <canvas id="chartCategory"></canvas>
    </div>
    <p id="chartCatEmpty" class="hidden" style="text-align:center;color:var(--celadon);font-size:0.82rem;padding:2rem 0;">
      No data this month.
    </p>
  </div>

  <!-- Daily Bar -->
  <div class="card" style="padding:1.25rem;">
    <div style="font-size:0.82rem;font-weight:600;margin-bottom:0.75rem;">Daily Spending</div>
    <canvas id="chartDaily"></canvas>
    <p id="chartDayEmpty" class="hidden" style="text-align:center;color:var(--celadon);font-size:0.82rem;padding:2rem 0;">
      No data this month.
    </p>
  </div>
</div>

<!-- Recent Expenses List -->
<div class="card" style="padding:1.25rem;">
  <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:0.75rem;">
    <span style="font-size:0.82rem;font-weight:600;">Month's Expenses</span>
    <select id="expTypeFilter" style="background:rgba(255,255,255,0.06);color:var(--pale-mint);border:1px solid rgba(255,255,255,0.12);border-radius:0.4rem;padding:0.25rem 0.5rem;font-size:0.75rem;">
      <option value="all">All</option>
      <option value="personal">Personal</option>
      <option value="group">Group</option>
    </select>
  </div>
  <div id="expListContainer" style="max-height:360px;overflow-y:auto;"></div>
  <p id="expListEmpty" class="hidden" style="text-align:center;color:var(--celadon);font-size:0.82rem;padding:1.5rem 0;">No expenses found.</p>
</div>

<!-- Budget Modal -->
<div id="budgetModal" class="hidden" style="
  position:fixed;inset:0;z-index:5000;display:flex;align-items:center;justify-content:center;
  background:rgba(0,0,0,0.55);padding:1rem;
">
  <div class="card" style="width:100%;max-width:380px;padding:1.75rem;">
    <h3 style="font-size:1.05rem;font-weight:700;margin-bottom:1rem;">Set Monthly Budget</h3>
    <p id="budgetMonthHint" style="font-size:0.8rem;color:var(--celadon);margin-bottom:0.75rem;"></p>
    <input type="number" id="budgetInput" class="form-input" placeholder="e.g. 5000" min="1" step="0.01" style="margin-bottom:1rem;" />
    <div style="display:flex;gap:0.5rem;">
      <button id="budgetSaveBtn" class="btn" style="flex:1;">Save</button>
      <button id="budgetCancelBtn" class="btn-outline" style="flex:1;" type="button">Cancel</button>
    </div>
  </div>
</div>

<script>
(function(){
  /* ---- State ---- */
  const now = new Date();
  let curYear  = now.getFullYear();
  let curMonth = now.getMonth(); // 0-based
  let allExpenses = [];
  let catChart = null, dayChart = null;

  /* ---- Elements ---- */
  const $label       = document.getElementById('expMonthLabel');
  const $prevBtn     = document.getElementById('expPrevMonth');
  const $nextBtn     = document.getElementById('expNextMonth');
  const $totalSpent  = document.getElementById('expTotalSpent');
  const $personal    = document.getElementById('expPersonal');
  const $group       = document.getElementById('expGroup');
  const $budgetBar   = document.getElementById('expBudgetBar');
  const $budgetLabel = document.getElementById('expBudgetLabel');
  const $budgetPct   = document.getElementById('expBudgetPct');
  const $budgetFill  = document.getElementById('expBudgetFill');
  const $setBudgetBtn= document.getElementById('expSetBudgetBtn');
  const $catCanvas   = document.getElementById('chartCategory');
  const $dayCanvas   = document.getElementById('chartDaily');
  const $catEmpty    = document.getElementById('chartCatEmpty');
  const $dayEmpty    = document.getElementById('chartDayEmpty');
  const $listCont    = document.getElementById('expListContainer');
  const $listEmpty   = document.getElementById('expListEmpty');
  const $typeFilter  = document.getElementById('expTypeFilter');
  // Modal
  const $modal       = document.getElementById('budgetModal');
  const $budgetInput = document.getElementById('budgetInput');
  const $budgetHint  = document.getElementById('budgetMonthHint');
  const $saveBtn     = document.getElementById('budgetSaveBtn');
  const $cancelBtn   = document.getElementById('budgetCancelBtn');

  /* ---- Helpers ---- */
  const monthKey = () => `${curYear}-${String(curMonth+1).padStart(2,'0')}`;
  const fmtMoney = n => '\u20B9' + Number(n).toLocaleString('en-IN', {minimumFractionDigits:2, maximumFractionDigits:2});
  const monthNames = ['January','February','March','April','May','June','July','August','September','October','November','December'];

  function updateLabel(){
    $label.textContent = `${monthNames[curMonth]} ${curYear}`;
  }

  /* ---- Chart colours (green palette) ---- */
  const chartPalette = [
    '#52b788','#40916c','#74c69d','#2d6a4f','#95d5b2',
    '#b7e4c7','#1b4332','#d8f3dc'
  ];

  /* ---- Fetch summary & render ---- */
  async function loadMonth(){
    updateLabel();
    const mk = monthKey();
    try {
      const [sumRes, listRes] = await Promise.all([
        fetch(`../api/expenses/summary.php?month=${mk}`).then(r=>r.json()),
        fetch(`../api/expenses/list.php?month=${mk}`).then(r=>r.json())
      ]);
      if(sumRes.ok) renderSummary(sumRes);
      if(listRes.ok){
        allExpenses = listRes.expenses;
        renderList();
      }
    } catch(err){ console.error(err); }
  }

  function renderSummary(d){
    $totalSpent.textContent = fmtMoney(d.total_spent);
    $personal.textContent   = fmtMoney(d.personal_total);
    $group.textContent      = fmtMoney(d.group_total);

    // Budget bar
    if(d.budget !== null){
      $budgetBar.style.display = 'block';
      $budgetLabel.textContent = 'Budget: ' + fmtMoney(d.budget);
      const pct = Math.min(100, (d.total_spent / d.budget) * 100);
      $budgetPct.textContent = pct.toFixed(0) + '%';
      $budgetFill.style.width = pct + '%';
      $budgetFill.style.background = pct >= 100 ? '#ef4444' : pct >= 80 ? '#f59e0b' : 'var(--mint-leaf)';
    } else {
      $budgetBar.style.display = 'none';
    }

    // Category doughnut
    if(d.by_category.length){
      $catEmpty.classList.add('hidden');
      $catCanvas.style.display = '';
      const labels = d.by_category.map(c=>c.name);
      const values = d.by_category.map(c=>c.total);
      if(catChart) catChart.destroy();
      catChart = new Chart($catCanvas, {
        type: 'doughnut',
        data: {
          labels,
          datasets: [{
            data: values,
            backgroundColor: chartPalette.slice(0, labels.length),
            borderWidth: 0
          }]
        },
        options: {
          cutout: '60%',
          plugins: {
            legend: { display: true, position:'bottom', labels:{ color:'#b7e4c7', font:{size:11}, padding:10 } },
            tooltip: {
              callbacks: {
                label: ctx => `${ctx.label}: ${fmtMoney(ctx.parsed)}`
              }
            }
          }
        }
      });
    } else {
      $catEmpty.classList.remove('hidden');
      $catCanvas.style.display = 'none';
      if(catChart){ catChart.destroy(); catChart = null; }
    }

    // Daily bar chart
    if(d.by_day.length){
      $dayEmpty.classList.add('hidden');
      $dayCanvas.style.display = '';
      // Fill all days of the month
      const daysInMonth = new Date(curYear, curMonth+1, 0).getDate();
      const dayMap = {};
      d.by_day.forEach(x => dayMap[x.day] = x.total);
      const dayLabels = [], dayValues = [];
      for(let i=1; i<=daysInMonth; i++){
        dayLabels.push(i);
        dayValues.push(dayMap[i] || 0);
      }

      if(dayChart) dayChart.destroy();
      dayChart = new Chart($dayCanvas, {
        type: 'bar',
        data: {
          labels: dayLabels,
          datasets: [{
            label: 'Spent',
            data: dayValues,
            backgroundColor: 'rgba(82,183,136,0.6)',
            borderRadius: 3,
            maxBarThickness: 18
          }]
        },
        options: {
          responsive: true,
          scales: {
            x: { ticks: { color:'#95d5b2', font:{size:10} }, grid:{ display:false } },
            y: { ticks: { color:'#95d5b2', font:{size:10}, callback: v=>'\u20B9'+v }, grid:{ color:'rgba(255,255,255,0.05)' }, beginAtZero:true }
          },
          plugins: {
            legend: { display:false },
            tooltip: {
              callbacks: {
                label: ctx => fmtMoney(ctx.parsed.y)
              }
            }
          }
        }
      });
    } else {
      $dayEmpty.classList.remove('hidden');
      $dayCanvas.style.display = 'none';
      if(dayChart){ dayChart.destroy(); dayChart = null; }
    }
  }

  /* ---- Expense list ---- */
  function renderList(){
    const filter = $typeFilter.value;
    const filtered = filter === 'all' ? allExpenses : allExpenses.filter(e => e.type === filter);
    if(!filtered.length){
      $listCont.innerHTML = '';
      $listEmpty.classList.remove('hidden');
      return;
    }
    $listEmpty.classList.add('hidden');

    $listCont.innerHTML = filtered.map(e => {
      const icon = e.type === 'group' ? '\uD83D\uDC65' : '\uD83D\uDC64';
      const tag  = e.type === 'group' ? `<span style="font-size:0.65rem;background:rgba(82,183,136,0.15);color:var(--mint-leaf);padding:0.1rem 0.4rem;border-radius:999px;">${escHtml(e.group_name || 'Group')}</span>` : '';
      return `
        <div style="display:flex;align-items:center;gap:0.65rem;padding:0.6rem 0;border-bottom:1px solid rgba(255,255,255,0.06);">
          <span style="font-size:1.1rem;">${icon}</span>
          <div style="flex:1;min-width:0;">
            <div style="font-size:0.82rem;font-weight:500;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">
              ${escHtml(e.category_name)}${e.note ? ' \u2014 '+escHtml(e.note) : ''} ${tag}
            </div>
            <div style="font-size:0.7rem;color:var(--celadon);">${e.expense_date} &middot; by ${escHtml(e.added_by)}</div>
          </div>
          <div style="font-weight:600;font-size:0.9rem;color:var(--mint-leaf);white-space:nowrap;">${fmtMoney(e.amount)}</div>
        </div>`;
    }).join('');
  }

  function escHtml(s){ const d=document.createElement('div'); d.textContent=s; return d.innerHTML; }

  /* ---- Events ---- */
  $prevBtn.onclick = () => { curMonth--; if(curMonth<0){curMonth=11;curYear--;} loadMonth(); };
  $nextBtn.onclick = () => { curMonth++; if(curMonth>11){curMonth=0;curYear++;} loadMonth(); };
  $typeFilter.onchange = () => renderList();

  // Budget modal
  $setBudgetBtn.onclick = () => {
    $budgetHint.textContent = `For ${monthNames[curMonth]} ${curYear}`;
    $budgetInput.value = '';
    $modal.classList.remove('hidden');
    $modal.style.display = 'flex';
    $budgetInput.focus();
  };
  $cancelBtn.onclick = () => { $modal.classList.add('hidden'); $modal.style.display = ''; };
  $modal.onclick = (ev) => { if(ev.target === $modal){ $cancelBtn.click(); } };

  $saveBtn.onclick = async () => {
    const val = parseFloat($budgetInput.value);
    if(!val || val <= 0) return;
    const fd = new FormData();
    fd.append('month', monthKey());
    fd.append('amount_limit', val);
    try {
      const res = await fetch('../api/budgets/set.php', {method:'POST', body:fd}).then(r=>r.json());
      if(res.ok){
        $cancelBtn.click();
        loadMonth();
      } else {
        alert(res.error || 'Failed to save budget.');
      }
    } catch(err){ alert('Network error.'); }
  };

  /* ---- Init ---- */
  loadMonth();
})();
</script>

<style>
/* Responsive: stack charts on mobile */
@media (max-width: 640px) {
  #expSummaryRow,
  #expChartsRow { grid-template-columns: 1fr !important; }
}
</style>
