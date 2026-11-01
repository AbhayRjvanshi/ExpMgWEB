var expmgConfig = JSON.parse(document.getElementById('expmgPageConfig').textContent);

// CSP-safe delegated listeners (replaces inline onclick attributes).
document.addEventListener('click', function (e) {
  var btn = e.target.closest('[data-view-detail]');
  if (!btn) return;
  var p = btn.getAttribute('data-view-detail').split('|');
  viewSettlementDetail(p[0], p[1], p[2], p[3]);
});
(function () {
  var tabMy = document.getElementById('tabMyExpenses');
  var tabSt = document.getElementById('tabSettlement');
  if (tabMy) tabMy.addEventListener('click', function () { switchExpTab('my'); });
  if (tabSt) tabSt.addEventListener('click', function () { switchExpTab('settlement'); });
})();

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

/* ========== SHARED DATE HELPER ========== */
/* Safely parse MySQL DATE ("YYYY-MM-DD") or DATETIME ("YYYY-MM-DD HH:MM:SS")
   values. Strips the time portion before constructing a local Date so
   DATETIME strings never produce Invalid Date / "undefined NaN, NaN" output. */
function parseSafeDate(d) {
  if (!d || typeof d !== 'string') return null;
  var datePart = d.trim().split(/[ T]/)[0];
  if (!/^\d{4}-\d{2}-\d{2}$/.test(datePart)) return null;
  var parsed = new Date(datePart + 'T00:00:00');
  return isNaN(parsed.getTime()) ? null : parsed;
}

/* Inline SVG icon constants (replace platform emoji in UI chrome) */
var ICON_LOCK_SVG = '<svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:-2px;"><rect x="4" y="11" width="16" height="9" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/></svg>';
var ICON_GROUP_SVG = '<svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:-2px;"><rect x="8" y="2" width="8" height="4" rx="1"/><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/></svg>';

/* Read a live CSS custom property (theme-aware) for Chart.js, which needs
   resolved color values — CSS var() strings do not work on canvas. */
function expmgCssVar(name) {
  if (window.ExpMgTheme && typeof ExpMgTheme.cssVar === 'function') {
    return ExpMgTheme.cssVar(name);
  }
  return getComputedStyle(document.body).getPropertyValue(name).trim();
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
    'Food/Groceries': '#1f6f4c',
    'Food': '#1f6f4c',
    'Groceries': '#1f6f4c',
    'Shopping': '#4f46e5',
    'Bills': '#f59e0b',
    'Utilities': '#8b5cf6',
    'Transport': '#0ea5e9',
    'Health': '#ec4899',
    'Education': '#6366f1',
    'General': '#64748b',
    'Others': '#eab308'
  };

  function categoryColor(name, index) {
    var key = (name || '').trim();
    if (chartColorMap[key]) return chartColorMap[key];

    var fallbackPalette = ['#1f6f4c', '#4f46e5', '#f59e0b', '#8b5cf6', '#0ea5e9', '#ec4899', '#6366f1', '#64748b', '#eab308', '#14b8a6'];
    return fallbackPalette[index % fallbackPalette.length];
  }

  // Monthly mode: hide the custom-range banner, show stepper + budget card,
  // and hide the standalone Total Spent card (the budget card already shows
  // the identical figure for the selected month).
  function enterMonthlyMode() {
    var banner = document.getElementById('expCustomRangeBanner');
    var nav = document.getElementById('expMonthNav');
    var budgetCard = document.getElementById('budgetCard');
    var totalCard = document.getElementById('expTotalSpentCard');
    if (banner) banner.classList.add('hidden');
    if (nav) nav.classList.remove('hidden');
    if (budgetCard) budgetCard.classList.remove('hidden');
    if (totalCard) totalCard.classList.add('is-hidden-monthly');
  }

  async function loadMonth(){
    enterMonthlyMode();
    updateLabel();
    var mk = monthKey();
    try {
      var results = await Promise.all([
        get(API + '/expenses/summary.php?month=' + mk),
        get(API + '/expenses/list.php?month=' + mk)
      ]);
      var sumRes = results[0], listRes = results[1];
      if(sumRes.ok) renderSummary(sumRes);
      if(listRes.ok){ allExpenses = listRes.expenses; renderList(); }
      // summary.php already returns the month's budget in its payload —
      // the extra budgets/get.php request on every month change is redundant.
      renderBudgetCard(
        (sumRes.ok && sumRes.budget !== null && typeof sumRes.budget !== 'undefined')
          ? { amount_limit: sumRes.budget }
          : null,
        sumRes.ok ? sumRes.total_spent : 0
      );
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
      $budgetPctLabel.style.color = rawPct > 100 ? 'var(--danger-600)' : 'var(--text-muted)';
      $budgetRemaining.textContent = fmtMoney(Math.max(0, limit - spentVal)) + ' Remaining';
      $budgetFill.style.width = pct + '%';
      $budgetFill.style.background = pct >= 100 ? 'var(--danger-600)' : pct >= 80 ? 'var(--amber-500)' : 'var(--accent-primary)';
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
      var catTotal = values.reduce(function(a, v){ return a + (Math.abs(Number(v)) || 0); }, 0);
      catChart = new Chart($catCanvas, {
        type: 'doughnut',
        data: { labels: labels, datasets: [{ data: values, backgroundColor: colors, borderColor: expmgCssVar('--bg-surface'), borderWidth: 2 }] },
        options: { cutout:'58%', plugins:{ legend:{ display:true, position:'bottom', labels:{ color:expmgCssVar('--text-secondary'), font:{size:11}, padding:10, generateLabels: function(chart){
          var ds = chart.data.datasets[0] || {};
          var data = ds.data || [];
          return chart.data.labels.map(function(label, i){
            var val = Math.abs(Number(data[i])) || 0;
            var pct = catTotal > 0 ? ' (' + Math.round((val / catTotal) * 100) + '%)' : ' (0%)';
            return {
              text: label + ' \u2014 ' + fmtMoney(val) + pct,
              fillStyle: (ds.backgroundColor && ds.backgroundColor[i]) ? ds.backgroundColor[i] : expmgCssVar('--border-medium'),
              strokeStyle: expmgCssVar('--bg-surface'),
              lineWidth: 2,
              hidden: !chart.getDataVisibility(i),
              index: i,
              datasetIndex: 0
            };
          });
        } } }, tooltip:{ callbacks:{ label: function(ctx){ return ctx.label + ': ' + fmtMoney(ctx.parsed); } } } } }
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
            { label:'Personal', data: personalValues, borderColor:expmgCssVar('--accent-primary'), backgroundColor:expmgCssVar('--accent-primary'), pointRadius:0, pointHoverRadius:0, pointHitRadius:6, cubicInterpolationMode:'monotone', borderWidth:2, fill:false },
            { label:'Group', data: groupValues, borderColor:expmgCssVar('--accent-secondary'), backgroundColor:expmgCssVar('--accent-secondary'), pointRadius:0, pointHoverRadius:0, pointHitRadius:6, cubicInterpolationMode:'monotone', borderWidth:2, fill:false }
          ]
        },
        options: { responsive:true, scales:{ x:{ ticks:{ color:expmgCssVar('--chart-tick'), font:{size:10} }, grid:{ display:false } }, y:{ ticks:{ color:expmgCssVar('--chart-tick'), font:{size:10}, callback: function(v){return '\u20B9'+v;} }, grid:{ color:expmgCssVar('--chart-grid') }, beginAtZero:true } }, plugins:{ legend:{ display:true, position:'bottom', labels:{ color:expmgCssVar('--text-secondary'), font:{size:11}, usePointStyle:true, pointStyle:'circle' } }, tooltip:{ callbacks:{ label: function(ctx){ return ctx.dataset.label + ': ' + fmtMoney(ctx.parsed.y); } } } } }
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
  document.getElementById('expCustomRangeReset').onclick = function(){ clearDateFilter(); loadMonth(); };

  /* ---- Live chart re-theming: palette / dark-mode switches dispatch
     'expmg:theme-change' (helpers.js ExpMgTheme); re-read tokens and
     update the visible Chart.js instances immediately. ---- */
  document.addEventListener('expmg:theme-change', function() {
    if (catChart) {
      catChart.data.datasets[0].borderColor = expmgCssVar('--bg-surface');
      catChart.options.plugins.legend.labels.color = expmgCssVar('--text-secondary');
      catChart.update();
    }
    if (dayChart) {
      dayChart.data.datasets[0].borderColor = expmgCssVar('--accent-primary');
      dayChart.data.datasets[0].backgroundColor = expmgCssVar('--accent-primary');
      dayChart.data.datasets[1].borderColor = expmgCssVar('--accent-secondary');
      dayChart.data.datasets[1].backgroundColor = expmgCssVar('--accent-secondary');
      dayChart.options.scales.x.ticks.color = expmgCssVar('--chart-tick');
      dayChart.options.scales.y.ticks.color = expmgCssVar('--chart-tick');
      dayChart.options.scales.y.grid.color = expmgCssVar('--chart-grid');
      dayChart.options.plugins.legend.labels.color = expmgCssVar('--text-secondary');
      dayChart.update();
    }
  });

  async function applyDateFilter(){
    var s = $startDate.value, e = $endDate.value;
    if(!s || !e){ alert('Please select both start and end dates.'); return; }
    if(s > e){ alert('Start date must be before end date.'); return; }
    dateRangeActive = true;
    $dateClearBtn.classList.remove('hidden');
    // Custom-range mode: the month stepper and Monthly Budget card do not
    // apply to this view — hide them, show the range banner and the
    // standalone Total Spent card.
    document.getElementById('expCustomRangeLabel').textContent = 'Custom date range: ' + s + ' to ' + e;
    document.getElementById('expCustomRangeBanner').classList.remove('hidden');
    document.getElementById('expMonthNav').classList.add('hidden');
    document.getElementById('budgetCard').classList.add('hidden');
    document.getElementById('expTotalSpentCard').classList.remove('is-hidden-monthly');
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
          ? '<span class="unpriced-src">' + ICON_GROUP_SVG + ' ' + escHtml(it.group_name || 'Group') + '</span>'
          : '<span class="unpriced-src">' + ICON_LOCK_SVG + ' Personal</span>';
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
          + 'Checked ' + (formatCheckedDate(it.checked_at) || 'recently')
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
      var groupCache = {}; // gid -> in-flight Promise resolving to members (deduped)
      groupSelects.forEach(function(sel){
        var gid = sel.dataset.group;
        // Cache the PROMISE synchronously so N unpriced items from the same
        // group fire exactly ONE groups/details.php request.
        var membersPromise = groupCache[gid];
        if (!membersPromise) {
          membersPromise = groupCache[gid] = get(API + '/groups/details.php?group_id=' + gid).then(function(res){
            return (res.ok && res.members) ? res.members : null;
          });
        }
        membersPromise.then(function(members){
          if (members) fillPaidBySelect(sel, members);
        });
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
          if (!price || price <= 0) { input.focus(); input.style.borderColor = 'var(--danger-600)'; return; }
          var paidBy = '';
          var paidBySel = row.querySelector('.unpriced-paidby-select');
          if (paidBySel) {
            paidBy = paidBySel.value;
            if (!paidBy) { paidBySel.focus(); paidBySel.style.borderColor = 'var(--danger-600)'; return; }
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
    var dt = parseSafeDate(d);
    if (!dt) return '';
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
  var currentUserId = expmgConfig.currentUserId;
  var firstName = expmgConfig.firstName;
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
        detail += owes.map(function(o){ return '<div style="font-size:0.78rem;color:var(--danger-600);">Owes ' + fmtMoney(o.amount) + ' to ' + escHtml(o.to) + '</div>'; }).join('');
      }
      if (recv.length) {
        var fromNames = recv.map(function(r){ return escHtml(r.from); }).join(' and ');
        detail += '<div style="font-size:0.78rem;color:var(--success-600);">To be paid ' + fmtMoney(totalRecv) + ' by ' + fromNames + '</div>';
      }
      if (!owes.length && !recv.length) {
        detail = '<div style="font-size:0.78rem;color:var(--text-muted);">Owes to none &middot; To be paid by none</div>';
      }

      var amountText = totalOwes > 0 ? '<span style="color:var(--danger-600);">' + fmtMoney(totalOwes) + '</span>' :
                        totalRecv > 0 ? '<span style="color:var(--success-600);">' + fmtMoney(totalRecv) + '</span>' :
                        '<span style="color:var(--text-muted);">\u20B90.00</span>';

      return '<div style="padding:0.75rem 0;border-bottom:1px solid var(--border-soft);">'
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
      html += '<div style="display:flex;align-items:center;gap:0.75rem;padding:0.6rem 0;border-bottom:1px solid var(--border-soft);">'
        + '<div style="flex:1;">'
        + '<div style="font-size:0.85rem;font-weight:500;">' + escHtml(firstName) + ' \u2192 ' + escHtml(s.to_username) + '</div>'
        + '<div style="font-size:0.72rem;color:var(--text-muted);">' + escHtml(firstName) + ' (Debtor) \u2192 ' + escHtml(s.to_username) + ' (Creditor)</div>'
        + '</div>'
        + '<div style="font-weight:700;color:var(--danger-600);font-size:0.95rem;">\u2193 ' + fmtMoney(s.amount) + '</div>'
        + '</div>';
    });
    userRecv.forEach(function(s){
      html += '<div style="display:flex;align-items:center;gap:0.75rem;padding:0.6rem 0;border-bottom:1px solid var(--border-soft);">'
        + '<div style="flex:1;">'
        + '<div style="font-size:0.85rem;font-weight:500;">' + escHtml(s.from_username) + ' \u2192 ' + escHtml(firstName) + '</div>'
        + '<div style="font-size:0.72rem;color:var(--text-muted);">' + escHtml(s.from_username) + ' (Debtor) \u2192 ' + escHtml(firstName) + ' (Creditor)</div>'
        + '</div>'
        + '<div style="font-weight:700;color:var(--success-600);font-size:0.95rem;">\u2191 ' + fmtMoney(s.amount) + '</div>'
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
      // Equal-contribution marker row (payer === payee, amount 0.00) is the
      // settlement-lock record for the period. Render it as a period closure,
      // never as "<User> paid <User> — ₹0.00". The DB row must stay untouched.
      var isMarker = Number(s.amount) === 0 && String(s.payer_id) === String(s.payee_id);
      var titleHtml = isMarker
        ? 'Settled Period (Equal Contributions)'
        : escHtml(s.payer_name) + ' paid ' + escHtml(s.payee_name);
      var amountHtml = isMarker
        ? 'No balance transfer required (' + fmtMoney(s.amount) + ')'
        : 'Amount: <strong>' + fmtMoney(s.amount) + '</strong>';
      return '<div style="padding:0.75rem 0;border-bottom:1px solid var(--border-soft);">'
        + '<div style="font-size:0.85rem;font-weight:500;">' + titleHtml + '</div>'
        + '<div style="font-size:0.82rem;color:var(--text-primary);margin-top:0.15rem;">' + amountHtml + '</div>'
        + '<div style="font-size:0.72rem;color:var(--text-muted);margin-top:0.1rem;">Period: ' + formatDate(s.period_start) + ' \u2013 ' + formatDate(s.period_end) + '</div>'
        + '<button class="btn-outline" style="margin-top:0.5rem;padding:0.25rem 0.6rem;font-size:0.72rem;border-radius:0.4rem;" data-view-detail="' + s.id + '|' + groupId + '|' + s.period_start + '|' + s.period_end + '">View More Details</button>'
        + '</div>';
    }).join('');
  }

  function formatDate(d) {
    var dt = parseSafeDate(d);
    if (!dt) return '\u2014';
    var months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
    return months[dt.getMonth()] + ' ' + dt.getDate() + ', ' + dt.getFullYear();
  }

  window.viewSettlementDetail = async function(settlId, groupId, start, end) {
    $detailModal.classList.remove('hidden');
    $detailModal.style.display = 'flex';
    $detailContent.innerHTML = '<p style="text-align:center;color:var(--text-muted);padding:1rem;">Loading\u2026</p>';
    $detailTotal.textContent = '';
    $downloadPdf.classList.add('hidden'); $downloadPdf.style.display = 'none';
    lastDetailData = null;
    try {
      var res = await get(API + '/settlements/details.php?group_id=' + groupId + '&start=' + start + '&end=' + end);
      if (!res.ok) { $detailContent.innerHTML = '<p style="color:var(--danger-600);">Failed to load details.</p>'; return; }
      if (!res.expenses.length) { $detailContent.innerHTML = '<p style="color:var(--text-muted);text-align:center;">No expenses found for this period.</p>'; return; }
      lastDetailData = { expenses: res.expenses, total: res.total, start: start, end: end };
      $detailContent.innerHTML = '<div style="display:grid;grid-template-columns:2fr 1fr 1fr 1fr;gap:0.5rem;font-size:0.72rem;font-weight:600;color:var(--text-muted);padding-bottom:0.5rem;border-bottom:1px solid var(--border-soft);text-transform:uppercase;">'
        + '<span>Item</span><span>Category</span><span>Date</span><span style="text-align:right;">Amount</span>'
        + '</div>'
        + res.expenses.map(function(e){
          return '<div style="display:grid;grid-template-columns:2fr 1fr 1fr 1fr;gap:0.5rem;font-size:0.82rem;padding:0.5rem 0;border-bottom:1px solid var(--border-soft);">'
            + '<span style="white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">' + escHtml(e.note || '\u2014') + ' <span style="font-size:0.7rem;color:var(--text-muted);">paid by ' + escHtml(e.payer_username || e.added_by) + '</span></span>'
            + '<span style="color:var(--text-muted);">' + escHtml(e.category_name) + '</span>'
            + '<span style="color:var(--text-muted);">' + e.expense_date + '</span>'
            + '<span style="text-align:right;font-weight:600;">' + fmtMoney(e.amount) + '</span>'
            + '</div>';
        }).join('');
      $detailTotal.textContent = 'Total Settled Amount: ' + fmtMoney(res.total);
      $downloadPdf.classList.remove('hidden'); $downloadPdf.style.display = 'block';
    } catch(e) {
      $detailContent.innerHTML = '<p style="color:var(--danger-600);">Network error.</p>';
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
        $psExpenseList.innerHTML = '<div style="font-size:0.78rem;font-weight:600;color:var(--amber-800);margin-bottom:0.4rem;">Late Expenses (' + res.expenses.length + ')</div>'
          + res.expenses.map(function(e){
            return '<div style="display:flex;align-items:center;gap:0.5rem;padding:0.4rem 0;border-bottom:1px solid var(--border-soft);font-size:0.82rem;">'
              + '<div style="flex:1;min-width:0;"><span style="font-weight:500;">' + escHtml(e.note || '\u2014') + '</span> <span style="font-size:0.7rem;color:var(--text-muted);">paid by ' + escHtml(e.payer_username || e.added_by) + '</span></div>'
              + '<div style="font-size:0.72rem;color:var(--text-muted);">' + (e.expense_date === '1970-01-01' ? 'Date unrecorded' : e.expense_date) + '</div>'
              + '<div style="font-weight:600;white-space:nowrap;">' + fmtMoney(e.amount) + '</div>'
              + '</div>';
          }).join('');
      } else {
        $psExpenseList.innerHTML = '';
      }

      // Render breakdown
      if (res.settlements.length) {
        $psBreakdown.innerHTML = '<div style="font-size:0.78rem;font-weight:600;color:var(--amber-800);margin-bottom:0.4rem;">Settlement Breakdown</div>'
          + res.settlements.map(function(s){
            return '<div style="display:flex;align-items:center;gap:0.5rem;padding:0.45rem 0;border-bottom:1px solid var(--border-soft);font-size:0.82rem;">'
              + '<div style="flex:1;">' + escHtml(s.from_username) + ' \u2192 ' + escHtml(s.to_username) + '</div>'
              + '<div style="font-weight:600;color:var(--danger-600);">' + fmtMoney(s.amount) + '</div>'
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
      $psUserStatus.innerHTML = '<p style="text-align:center;color:var(--text-muted);font-size:0.82rem;padding:0.5rem 0;">Equal contribution — no transfers needed.</p>';
      return;
    }

    var html = '';
    userOwes.forEach(function(s){
      html += '<div style="display:flex;align-items:center;gap:0.5rem;padding:0.45rem 0;border-bottom:1px solid var(--border-soft);font-size:0.82rem;">'
        + '<div style="flex:1;">' + escHtml(firstName) + ' \u2192 ' + escHtml(s.to_username) + '</div>'
        + '<div style="font-weight:700;color:var(--danger-600);">\u2193 ' + fmtMoney(s.amount) + '</div>'
        + '</div>';
    });
    userRecv.forEach(function(s){
      html += '<div style="display:flex;align-items:center;gap:0.5rem;padding:0.45rem 0;border-bottom:1px solid var(--border-soft);font-size:0.82rem;">'
        + '<div style="flex:1;">' + escHtml(s.from_username) + ' \u2192 ' + escHtml(firstName) + '</div>'
        + '<div style="font-weight:700;color:var(--success-600);">\u2191 ' + fmtMoney(s.amount) + '</div>'
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

