<?php
// Include-only: requesting this file by URL gives a 404.
if (realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    http_response_code(404);
    exit;
}

$reportEmbedded   = $reportEmbedded ?? false;
$reportFormAction = $reportEmbedded ? '/hr/dashboard.php' : '/hr/report.php';
$reportResetHref  = $reportEmbedded ? '/hr/dashboard.php' : '/hr/report.php';

$monthlyHires     = $monthlyHires ?? ['labels' => [], 'counts' => []];
$empTypeBreakdown = $empTypeBreakdown ?? [];

$deptChartLabels = [];
$deptChartMonth  = [];
$deptChart6M     = [];
foreach ($headcount as $hc) {
    $deptChartLabels[] = (string)($hc['department_name'] ?? 'Department');
    $deptChartMonth[]  = (int)($hc['new_hires_month'] ?? 0);
    $deptChart6M[]     = (int)($hc['new_hires_6m'] ?? 0);
}
?>

<?php if (!empty($queryError)): ?>
  <div class="error"><strong>Debug:</strong> <?= htmlspecialchars($queryError) ?></div>
<?php endif; ?>

<style>
  .report-live-bar {
    display: flex;
    align-items: center;
    justify-content: flex-end;
    gap: 10px;
    margin-bottom: 10px;
    font-size: 12px;
    color: #94a3b8;
  }
  .report-live-dot {
    display: inline-block;
    width: 8px;
    height: 8px;
    border-radius: 50%;
    background: #34d399;
  }
  .report-charts-grid {
    display: grid;
    grid-template-columns: 1.4fr 1fr;
    gap: 16px;
    margin: 16px 0;
  }
  .report-charts-row2 {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 16px;
    margin-bottom: 16px;
  }
  @media (max-width: 900px) {
    .report-charts-grid,
    .report-charts-row2 {
      grid-template-columns: 1fr;
    }
  }
  .report-chart-panel {
    background: rgba(15, 23, 42, 0.72);
    border: 1px solid rgba(148, 163, 184, 0.2);
    border-radius: 12px;
    padding: 16px 18px;
  }
  .report-chart-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
    margin-bottom: 12px;
  }
  .report-chart-header h3 {
    margin: 0;
    font-size: 14px;
    font-weight: 700;
    color: #f8fafc;
  }
  .report-chart-header span {
    font-size: 11.5px;
    color: #94a3b8;
  }
  .report-chart-canvas-wrap {
    position: relative;
    height: 240px;
    width: 100%;
  }
</style>

<!-- Live Sync Indicator -->
<div class="report-live-bar">
  <span class="report-live-dot" id="reportLiveDot"></span>
  <span id="reportLiveStatus">Live auto-sync active • Updated just now</span>
  <button type="button" class="button" id="reportSyncNowBtn" style="padding:4px 10px;font-size:12px;">↻ Sync Now</button>
</div>

<!-- Quick Stats + New Hires -->
<div class="metric-strip">
  <article class="metric-card">
    <span class="metric-title">Active Employees</span>
    <strong class="metric-number green"><?= (int)($stats['active'] ?? 0) ?></strong>
    <span class="metric-description">Currently marked active</span>
  </article>
  <article class="metric-card">
    <span class="metric-title">Inactive Employees</span>
    <strong class="metric-number"><?= (int)($stats['inactive'] ?? 0) ?></strong>
    <span class="metric-description">Currently marked inactive</span>
  </article>
  <article class="metric-card">
    <span class="metric-title">New Hires (This Month)</span>
    <strong class="metric-number blue"><?= (int)($stats['new_hires_month'] ?? 0) ?></strong>
    <span class="metric-description">Hired in <?= htmlspecialchars(date('F Y')) ?></span>
  </article>
  <article class="metric-card">
    <span class="metric-title">New Hires (Last 30 Days)</span>
    <strong class="metric-number purple"><?= (int)($stats['new_hires_30d'] ?? 0) ?></strong>
    <span class="metric-description">Past 30 days • <?= (int)($stats['depts'] ?? 0) ?> depts • <?= (int)($stats['pending_leaves'] ?? 0) ?> pending leaves</span>
  </article>
</div>

<!-- Graphs: Past Months Hiring + New Hires by Department -->
<div class="report-charts-grid">
  <div class="report-chart-panel">
    <div class="report-chart-header">
      <h3>New Employees Hired (Past 6 Months)</h3>
      <span>Monthly Trend</span>
    </div>
    <div class="report-chart-canvas-wrap">
      <canvas id="reportMonthlyHiresChart"></canvas>
    </div>
  </div>

  <div class="report-chart-panel">
    <div class="report-chart-header">
      <h3>New Hires by Department</h3>
      <span>This Month vs Past 6M</span>
    </div>
    <div class="report-chart-canvas-wrap">
      <canvas id="reportDeptHiresChart"></canvas>
    </div>
  </div>
</div>

<?php if (!empty($empTypeBreakdown)): ?>
<div class="report-charts-row2">
  <div class="report-chart-panel">
    <div class="report-chart-header">
      <h3>Employees by Employment Type</h3>
      <span>Workforce Mix</span>
    </div>
    <div class="report-chart-canvas-wrap">
      <canvas id="reportEmpTypeChart"></canvas>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- Headcount & New Hires Table -->
<article class="card report-card">
  <div class="card-heading">
    <h2>Headcount &amp; New Hires by Department</h2>
    <form method="get" action="<?= htmlspecialchars($reportFormAction) ?>" class="report-toolbar">
      <label class="muted">Sort by
        <select name="sort_by">
          <option value="department_name" <?= $sortBy === 'department_name' ? 'selected' : '' ?>>Department</option>
          <option value="active_count"    <?= $sortBy === 'active_count'    ? 'selected' : '' ?>>Active</option>
          <option value="inactive_count"  <?= $sortBy === 'inactive_count'  ? 'selected' : '' ?>>Inactive</option>
          <option value="new_hires_month" <?= $sortBy === 'new_hires_month' ? 'selected' : '' ?>>New Hires (Month)</option>
          <option value="new_hires_6m"    <?= $sortBy === 'new_hires_6m'    ? 'selected' : '' ?>>New Hires (6M)</option>
          <option value="total"           <?= $sortBy === 'total'           ? 'selected' : '' ?>>Total</option>
        </select>
      </label>
      <label class="muted">Direction
        <select name="dir">
          <option value="asc"  <?= $dir === 'asc'  ? 'selected' : '' ?>>ASC</option>
          <option value="desc" <?= $dir === 'desc' ? 'selected' : '' ?>>DESC</option>
        </select>
      </label>
      <button class="primary-button" type="submit">Apply</button>
      <a class="button" href="<?= htmlspecialchars($reportResetHref) ?>">Reset</a>
      <?php if ($reportEmbedded): ?>
        <a class="button" href="/hr/report.php?export=headcount_csv&sort_by=<?= urlencode($sortBy) ?>&dir=<?= urlencode($dir) ?>">⭳ Export CSV</a>
      <?php endif; ?>
    </form>
  </div>

  <table>
    <thead>
      <tr>
        <th>#</th>
        <th>Department</th>
        <th>Active</th>
        <th>Inactive</th>
        <th>New Hires (Month)</th>
        <th>New Hires (6M)</th>
        <th>Total</th>
      </tr>
    </thead>
    <tbody>
      <?php if (empty($headcount)): ?>
        <tr>
          <td colspan="7" class="muted" style="text-align:center;padding:20px">
            No departments found.
            <?php if (!empty($queryError)): ?>
              <br><small>See the red debug message above for the SQL error.</small>
            <?php endif; ?>
          </td>
        </tr>
      <?php else: ?>
        <?php foreach ($headcount as $i => $r): ?>
          <tr>
            <td><?= $i + 1 ?></td>
            <td><?= htmlspecialchars((string)($r['department_name'] ?? '')) ?></td>
            <td><?= (int)($r['active_count']    ?? 0) ?></td>
            <td><?= (int)($r['inactive_count']  ?? 0) ?></td>
            <td><?= (int)($r['new_hires_month'] ?? 0) ?></td>
            <td><?= (int)($r['new_hires_6m']    ?? 0) ?></td>
            <td><?= (int)($r['total']           ?? 0) ?></td>
          </tr>
        <?php endforeach; ?>
      <?php endif; ?>
    </tbody>
  </table>
</article>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js"></script>
<script>
(function () {
  if (typeof Chart === 'undefined') return;

  const gridColor = 'rgba(148, 163, 184, 0.14)';
  const tickColor = '#94a3b8';

  const initialMonthly = <?= json_encode($monthlyHires, JSON_UNESCAPED_UNICODE) ?>;
  const initialDept = {
    labels: <?= json_encode($deptChartLabels, JSON_UNESCAPED_UNICODE) ?>,
    month:  <?= json_encode($deptChartMonth, JSON_UNESCAPED_UNICODE) ?>,
    sixM:   <?= json_encode($deptChart6M, JSON_UNESCAPED_UNICODE) ?>
  };
  const initialEmpType = <?= json_encode($empTypeBreakdown, JSON_UNESCAPED_UNICODE) ?>;

  let monthlyChart = null;
  let deptChart = null;
  let empTypeChart = null;

  const mCanvas = document.getElementById('reportMonthlyHiresChart');
  if (mCanvas) {
    monthlyChart = new Chart(mCanvas, {
      type: 'line',
      data: {
        labels: initialMonthly.labels || [],
        datasets: [{
          label: 'New Hires',
          data: initialMonthly.counts || [],
          borderColor: '#38bdf8',
          backgroundColor: 'rgba(56, 189, 248, 0.18)',
          borderWidth: 2.5,
          pointBackgroundColor: '#38bdf8',
          pointRadius: 4,
          fill: true,
          tension: 0.32
        }]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        animation: { duration: 350 },
        plugins: { legend: { labels: { color: '#e2e8f0' } } },
        scales: {
          x: { ticks: { color: tickColor }, grid: { color: gridColor } },
          y: { beginAtZero: true, ticks: { color: tickColor, precision: 0 }, grid: { color: gridColor } }
        }
      }
    });
  }

  const dCanvas = document.getElementById('reportDeptHiresChart');
  if (dCanvas) {
    deptChart = new Chart(dCanvas, {
      type: 'bar',
      data: {
        labels: initialDept.labels || [],
        datasets: [
          {
            label: 'This Month',
            data: initialDept.month || [],
            backgroundColor: '#34d399',
            borderRadius: 6
          },
          {
            label: 'Past 6 Months',
            data: initialDept.sixM || [],
            backgroundColor: '#818cf8',
            borderRadius: 6
          }
        ]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        animation: { duration: 350 },
        plugins: { legend: { labels: { color: '#e2e8f0' } } },
        scales: {
          x: { ticks: { color: tickColor }, grid: { display: false } },
          y: { beginAtZero: true, ticks: { color: tickColor, precision: 0 }, grid: { color: gridColor } }
        }
      }
    });
  }

  const tCanvas = document.getElementById('reportEmpTypeChart');
  if (tCanvas) {
    empTypeChart = new Chart(tCanvas, {
      type: 'doughnut',
      data: {
        labels: Object.keys(initialEmpType || {}),
        datasets: [{
          data: Object.values(initialEmpType || {}),
          backgroundColor: ['#38bdf8', '#a78bfa', '#34d399', '#fbbf24', '#f87171'],
          borderColor: '#0f172a',
          borderWidth: 2
        }]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        animation: { duration: 350 },
        plugins: { legend: { position: 'right', labels: { color: '#e2e8f0' } } }
      }
    });
  }

  function escapeHtml(str) {
    const div = document.createElement('div');
    div.textContent = str == null ? '' : String(str);
    return div.innerHTML;
  }

  function applyLivePayload(data) {
    if (!data || !data.ok) return;

    const s = data.stats || {};
    const metricNums = document.querySelectorAll('.metric-strip .metric-card .metric-number');
    if (metricNums.length >= 4) {
      metricNums[0].textContent = Number(s.active ?? 0);
      metricNums[1].textContent = Number(s.inactive ?? 0);
      metricNums[2].textContent = Number(s.new_hires_month ?? 0);
      metricNums[3].textContent = Number(s.new_hires_30d ?? 0);
    }

    const tbody = document.querySelector('.report-card table tbody');
    if (tbody && Array.isArray(data.headcount)) {
      if (data.headcount.length === 0) {
        tbody.innerHTML = '<tr><td colspan="7" class="muted" style="text-align:center;padding:20px">No departments found.</td></tr>';
      } else {
        tbody.innerHTML = data.headcount.map((r, idx) => `
          <tr>
            <td>${idx + 1}</td>
            <td>${escapeHtml(r.department_name ?? '')}</td>
            <td>${Number(r.active_count ?? 0)}</td>
            <td>${Number(r.inactive_count ?? 0)}</td>
            <td>${Number(r.new_hires_month ?? 0)}</td>
            <td>${Number(r.new_hires_6m ?? 0)}</td>
            <td>${Number(r.total ?? 0)}</td>
          </tr>
        `).join('');
      }
    }

    if (monthlyChart && data.monthlyHires) {
      monthlyChart.data.labels = data.monthlyHires.labels || [];
      monthlyChart.data.datasets[0].data = data.monthlyHires.counts || [];
      monthlyChart.update('none');
    }

    if (deptChart && data.deptChart) {
      deptChart.data.labels = data.deptChart.labels || [];
      deptChart.data.datasets[0].data = data.deptChart.month || [];
      deptChart.data.datasets[1].data = data.deptChart.sixM || [];
      deptChart.update('none');
    }

    if (empTypeChart && data.empTypeBreakdown) {
      empTypeChart.data.labels = Object.keys(data.empTypeBreakdown);
      empTypeChart.data.datasets[0].data = Object.values(data.empTypeBreakdown);
      empTypeChart.update('none');
    }

    const statusEl = document.getElementById('reportLiveStatus');
    const dotEl = document.getElementById('reportLiveDot');
    if (statusEl && data.updated_at) {
      statusEl.textContent = `Live auto-sync active • Updated ${data.updated_at}`;
    }
    if (dotEl) {
      dotEl.style.background = '#34d399';
    }
  }

  let isFetching = false;
  async function syncLiveReport() {
    if (isFetching) return;
    isFetching = true;

    const dotEl = document.getElementById('reportLiveDot');
    try {
      const url = new URL('/hr/report.php', window.location.origin);
      const curParams = new URLSearchParams(window.location.search);
      if (curParams.get('sort_by')) url.searchParams.set('sort_by', curParams.get('sort_by'));
      if (curParams.get('dir')) url.searchParams.set('dir', curParams.get('dir'));
      url.searchParams.set('live_report', '1');
      url.searchParams.set('_t', Date.now().toString());

      const res = await fetch(url.toString(), {
        cache: 'no-store',
        headers: { 'X-Live-Report': '1' }
      });
      if (!res.ok) throw new Error('HTTP ' + res.status);
      const data = await res.json();
      applyLivePayload(data);
    } catch (_) {
      if (dotEl) dotEl.style.background = '#fbbf24';
    } finally {
      isFetching = false;
    }
  }

  const syncBtn = document.getElementById('reportSyncNowBtn');
  if (syncBtn) {
    syncBtn.addEventListener('click', syncLiveReport);
  }

  document.addEventListener('hris:employee-updated', syncLiveReport);

  const POLL_MS = 5000;
  let timer = setInterval(() => {
    if (!document.hidden) syncLiveReport();
  }, POLL_MS);

  document.addEventListener('visibilitychange', () => {
    clearInterval(timer);
    if (!document.hidden) {
      syncLiveReport();
      timer = setInterval(() => {
        if (!document.hidden) syncLiveReport();
      }, POLL_MS);
    }
  });
})();
</script>