<?php
// employee/overtime.php

date_default_timezone_set('Asia/Manila');

require_once __DIR__ . '/../authentication/guard.php';
require_once __DIR__ . '/../config/database.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    @session_start();
}

$employeeId = (int)($_SESSION['employee_id'] ?? 0);
$userId     = (int)($_SESSION['user_id'] ?? 0);

if ($employeeId <= 0 && $userId > 0) {
    try {
        $stmt = $pdo->prepare("SELECT `id` FROM `employees` WHERE `user_id` = ? LIMIT 1");
        $stmt->execute([$userId]);
        $employeeId = (int)($stmt->fetchColumn() ?: 0);
        if ($employeeId > 0) {
            $_SESSION['employee_id'] = $employeeId;
        }
    } catch (Throwable $e) {}
}

if ($employeeId <= 0) {
    header('Location: /authentication/login.php');
    exit;
}

$allowedTypes = [
    'Regular Day',
    'Rest Day',
    'Special Non-Working Holiday',
    'Regular Holiday',
];

function eot_ensure_table(PDO $pdo): void {
    $pdo->exec("SET time_zone = '+08:00'");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `overtime_requests` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `employee_id` INT UNSIGNED NOT NULL,
            `work_date` DATE NOT NULL,
            `start_time` TIME NOT NULL,
            `end_time` TIME NOT NULL,
            `requested_minutes` INT NOT NULL DEFAULT 0,
            `approved_minutes` INT NOT NULL DEFAULT 0,
            `ot_type` VARCHAR(40) NOT NULL DEFAULT 'Regular Day',
            `reason` TEXT NOT NULL,
            `status` VARCHAR(24) NOT NULL DEFAULT 'Pending',
            `reviewed_by` VARCHAR(120) NULL,
            `reviewed_at` DATETIME NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            KEY `idx_ot_emp_date` (`employee_id`, `work_date`),
            KEY `idx_ot_status` (`status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}

function eot_load_state(PDO $pdo, int $employeeId): array {
    $monthStart = date('Y-m-01');
    $monthEnd   = date('Y-m-t');
    $monthApprovedMins = 0;
    $monthPendingMins  = 0;

    $stmt = $pdo->prepare("
        SELECT * FROM `overtime_requests`
        WHERE `employee_id` = ?
        ORDER BY `work_date` DESC, `id` DESC
        LIMIT 100
    ");
    $stmt->execute([$employeeId]);
    $requests = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($requests as $r) {
        if ($r['work_date'] >= $monthStart && $r['work_date'] <= $monthEnd) {
            if ($r['status'] === 'Approved') {
                $monthApprovedMins += (int)$r['approved_minutes'];
            } elseif ($r['status'] === 'Pending') {
                $monthPendingMins += (int)$r['requested_minutes'];
            }
        }
    }

    return [
        'server_time'         => date('H:i:s'),
        'month_approved_hrs'  => number_format($monthApprovedMins / 60, 2),
        'month_pending_hrs'   => number_format($monthPendingMins / 60, 2),
        'total_count'         => count($requests),
        'requests'            => $requests,
    ];
}

function eot_handle_action(PDO $pdo, int $employeeId, array $input, array $allowedTypes): string {
    $action = (string)($input['ot_action'] ?? '');

    if ($action === 'submit_ot') {
        $workDate  = trim((string)($input['work_date'] ?? ''));
        $startTime = trim((string)($input['start_time'] ?? ''));
        $endTime   = trim((string)($input['end_time'] ?? ''));
        $otType    = trim((string)($input['ot_type'] ?? 'Regular Day'));
        $reason    = trim((string)($input['reason'] ?? ''));

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $workDate)) {
            throw new RuntimeException('Please select a valid overtime date.');
        }
        if (!preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $startTime) || !preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $endTime)) {
            throw new RuntimeException('Please provide valid start and end times.');
        }
        if (strlen($startTime) === 5) $startTime .= ':00';
        if (strlen($endTime) === 5) $endTime .= ':00';

        if (!in_array($otType, $allowedTypes, true)) {
            $otType = 'Regular Day';
        }
        if ($reason === '') {
            throw new RuntimeException('Please provide a reason for your overtime request.');
        }

        $startTs = strtotime($workDate . ' ' . $startTime);
        $endTs   = strtotime($workDate . ' ' . $endTime);
        if ($endTs <= $startTs) {
            $endTs = strtotime('+1 day', $endTs);
        }

        $reqMinutes = (int)round(($endTs - $startTs) / 60);
        if ($reqMinutes < 30) {
            throw new RuntimeException('Minimum overtime request is 30 minutes.');
        }
        if ($reqMinutes > 720) {
            throw new RuntimeException('Overtime request cannot exceed 12 hours (720 minutes) per shift.');
        }

        $dup = $pdo->prepare("
            SELECT COUNT(*) FROM `overtime_requests`
            WHERE `employee_id` = ? AND `work_date` = ? AND `status` IN ('Pending', 'Approved')
              AND `start_time` < ? AND `end_time` > ?
        ");
        $dup->execute([$employeeId, $workDate, $endTime, $startTime]);
        if ((int)$dup->fetchColumn() > 0) {
            throw new RuntimeException('You already have an overlapping Pending or Approved overtime request on this date.');
        }

        $ins = $pdo->prepare("
            INSERT INTO `overtime_requests`
                (`employee_id`, `work_date`, `start_time`, `end_time`, `requested_minutes`, `approved_minutes`, `ot_type`, `reason`, `status`)
            VALUES (?, ?, ?, ?, ?, 0, ?, ?, 'Pending')
        ");
        $ins->execute([$employeeId, $workDate, $startTime, $endTime, $reqMinutes, $otType, $reason]);

        return 'Overtime request submitted (' . number_format($reqMinutes / 60, 2) . ' hrs). Awaiting approval.';
    }

    if ($action === 'cancel_ot') {
        $reqId = (int)($input['request_id'] ?? 0);
        $upd = $pdo->prepare("
            UPDATE `overtime_requests`
            SET `status` = 'Cancelled'
            WHERE `id` = ? AND `employee_id` = ? AND `status` = 'Pending'
        ");
        $upd->execute([$reqId, $employeeId]);
        if ($upd->rowCount() > 0) {
            return 'Pending overtime request cancelled.';
        }
        throw new RuntimeException('Only pending overtime requests can be cancelled.');
    }

    throw new RuntimeException('Invalid overtime action.');
}

$isAjax = (isset($_GET['ajax']) && $_GET['ajax'] === 'live_state')
    || (isset($_POST['ajax']) && $_POST['ajax'] === '1')
    || (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower((string)$_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest');

try {
    eot_ensure_table($pdo);
} catch (Throwable $e) {}

if ($isAjax) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    try {
        $msg = '';
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $msg = eot_handle_action($pdo, $employeeId, $_POST, $allowedTypes);
        }
        $payload = eot_load_state($pdo, $employeeId);
        $payload['ok'] = true;
        $payload['message'] = $msg;
        echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

$flashOk  = '';
$flashErr = '';
$today    = date('Y-m-d');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $flashOk = eot_handle_action($pdo, $employeeId, $_POST, $allowedTypes);
    } catch (Throwable $e) {
        $flashErr = $e->getMessage();
    }
}

$initState = eot_load_state($pdo, $employeeId);

$empHeader = __DIR__ . '/../templates/employeeTemplates/header.php';
if (is_file($empHeader)) {
    require_once $empHeader;
}
?>

<link rel="stylesheet" href="https://cdn.datatables.net/1.13.8/css/jquery.dataTables.min.css">
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js"></script>

<style>
  .eot-wrap { max-width: 1120px; margin: 22px auto 56px; padding: 0 20px; color: #f8fafc; }
  .eot-top { display: flex; flex-wrap: wrap; justify-content: space-between; align-items: center; gap: 12px; margin-bottom: 18px; }
  .eot-title { margin: 0 0 4px; font-size: 24px; font-weight: 700; color: #fff; display: flex; align-items: center; gap: 10px; }
  .eot-sub { margin: 0; font-size: 13px; color: #94a3b8; }
  .eot-live-pill {
    display: inline-flex; align-items: center; gap: 6px; padding: 4px 10px; border-radius: 999px;
    font-size: 11px; font-weight: 700; background: rgba(16, 185, 129, 0.16); color: #34d399;
    border: 1px solid rgba(52, 211, 153, 0.35);
  }
  .eot-live-dot { width: 7px; height: 7px; border-radius: 50%; background: #34d399; }
  .eot-kpis { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 12px; margin-bottom: 18px; }
  .eot-kpi { background: #111827; border: 1px solid rgba(148,163,184,0.18); border-radius: 14px; padding: 14px 16px; }
  .eot-kpi-lbl { font-size: 11px; text-transform: uppercase; letter-spacing: 0.04em; color: #94a3b8; font-weight: 700; }
  .eot-kpi-val { font-size: 24px; font-weight: 700; color: #fff; margin-top: 6px; }
  .eot-card { background: #111827; border: 1px solid rgba(148,163,184,0.18); border-radius: 14px; padding: 18px 20px; margin-bottom: 16px; }
  .eot-card h2 { margin: 0 0 12px; font-size: 16px; color: #fff; }
  .eot-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(170px, 1fr)); gap: 12px; margin-bottom: 12px; }
  .eot-input, .eot-select, .eot-textarea {
    width: 100%; padding: 8px 10px; border-radius: 8px; border: 1px solid #334155;
    background: #0f172a; color: #f8fafc; font-size: 13px; box-sizing: border-box; margin-top: 4px;
  }
  .eot-btn {
    padding: 9px 16px; border-radius: 8px; border: none; background: #2563eb;
    color: #fff; font-size: 13px; font-weight: 600; cursor: pointer;
  }
  .eot-btn:hover { background: #1d4ed8; }
  .eot-btn-cancel {
    padding: 4px 10px; border-radius: 6px; border: 1px solid rgba(248,113,113,0.4);
    background: rgba(239,68,68,0.15); color: #fca5a5; font-size: 11.5px; font-weight: 600; cursor: pointer;
  }
  .eot-table-wrap { overflow-x: auto; }
  .eot-table { width: 100%; border-collapse: collapse; font-size: 13px; }
  .eot-table th, .eot-table td { padding: 10px; border-bottom: 1px solid rgba(148,163,184,0.14); text-align: left; }
  .eot-table th { font-size: 11px; text-transform: uppercase; color: #94a3b8; background: #0f172a; }
  .eot-badge { padding: 3px 9px; border-radius: 999px; font-size: 11px; font-weight: 700; display: inline-block; }
  .b-app { background: rgba(22,163,74,0.2); color: #4ade80; }
  .b-pen { background: rgba(245,158,11,0.2); color: #fbbf24; }
  .b-rej { background: rgba(239,68,68,0.2); color: #f87171; }
  .b-can { background: rgba(148,163,184,0.2); color: #cbd5e1; }
  .eot-alert-ok { background: rgba(22,163,74,0.18); border: 1px solid rgba(74,222,128,0.4); color: #bbf7d0; padding: 10px 14px; border-radius: 10px; margin-bottom: 14px; font-size: 13px; }
  .eot-alert-err { background: rgba(239,68,68,0.18); border: 1px solid rgba(248,113,113,0.4); color: #fecaca; padding: 10px 14px; border-radius: 10px; margin-bottom: 14px; font-size: 13px; }

  .dataTables_wrapper { color: #e2e8f0; font-size: 12.5px; margin-top: 8px; }
  .dataTables_wrapper .dataTables_filter input,
  .dataTables_wrapper .dataTables_length select {
    background: #0f172a; color: #f8fafc; border: 1px solid #334155; border-radius: 7px; padding: 4px 8px; margin: 0 4px;
  }
  .dataTables_wrapper .dataTables_info,
  .dataTables_wrapper .dataTables_paginate {
    color: #94a3b8 !important; padding-top: 10px;
  }
  .dataTables_wrapper .dataTables_paginate .paginate_button {
    color: #e2e8f0 !important; border: 1px solid rgba(148,163,184,0.2) !important;
    background: rgba(15,23,42,0.75) !important; border-radius: 6px !important; margin-left: 3px;
  }
  .dataTables_wrapper .dataTables_paginate .paginate_button.current,
  .dataTables_wrapper .dataTables_paginate .paginate_button.current:hover {
    background: #2563eb !important; border-color: #2563eb !important; color: #fff !important;
  }
  table.dataTable.no-footer { border-bottom: 1px solid rgba(148,163,184,0.18) !important; }
</style>

<div class="eot-wrap">
  <div class="eot-top">
    <div>
      <h1 class="eot-title">
        <span>Overtime Requests</span>
        <span class="eot-live-pill"><span class="eot-live-dot"></span> <span id="eotLiveText">LIVE SYNC</span></span>
      </h1>
      <p class="eot-sub">File overtime authorizations and track approved hours in real time without refreshing.</p>
    </div>
    <span style="font-size:12px;color:#94a3b8;" id="eotSyncClock">Synced at <?= htmlspecialchars($initState['server_time'], ENT_QUOTES, 'UTF-8') ?></span>
  </div>

  <div id="eotAlertBox">
    <?php if ($flashOk !== ''): ?>
      <div class="eot-alert-ok"><?= htmlspecialchars($flashOk, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>
    <?php if ($flashErr !== ''): ?>
      <div class="eot-alert-err"><?= htmlspecialchars($flashErr, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>
  </div>

  <div class="eot-kpis">
    <div class="eot-kpi">
      <div class="eot-kpi-lbl">Approved OT (This Month)</div>
      <div class="eot-kpi-val" id="eotKpiApproved"><?= htmlspecialchars($initState['month_approved_hrs'], ENT_QUOTES, 'UTF-8') ?> hrs</div>
    </div>
    <div class="eot-kpi">
      <div class="eot-kpi-lbl">Pending OT (This Month)</div>
      <div class="eot-kpi-val" id="eotKpiPending"><?= htmlspecialchars($initState['month_pending_hrs'], ENT_QUOTES, 'UTF-8') ?> hrs</div>
    </div>
    <div class="eot-kpi">
      <div class="eot-kpi-lbl">Total Requests Logged</div>
      <div class="eot-kpi-val" id="eotKpiTotal"><?= (int)$initState['total_count'] ?></div>
    </div>
  </div>

  <div class="eot-card">
    <h2>File Overtime Request</h2>
    <form method="post" id="eotSubmitForm">
      <input type="hidden" name="ot_action" value="submit_ot">
      <div class="eot-grid">
        <div>
          <label class="eot-kpi-lbl">Work Date</label>
          <input type="date" name="work_date" value="<?= htmlspecialchars($today, ENT_QUOTES, 'UTF-8') ?>" class="eot-input" required>
        </div>
        <div>
          <label class="eot-kpi-lbl">OT Start Time</label>
          <input type="time" name="start_time" value="17:00" class="eot-input" required>
        </div>
        <div>
          <label class="eot-kpi-lbl">OT End Time</label>
          <input type="time" name="end_time" value="19:00" class="eot-input" required>
        </div>
        <div>
          <label class="eot-kpi-lbl">Overtime Type</label>
          <select name="ot_type" class="eot-select">
            <?php foreach ($allowedTypes as $type): ?>
              <option value="<?= htmlspecialchars($type, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($type, ENT_QUOTES, 'UTF-8') ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
      <div style="margin-bottom:12px;">
        <label class="eot-kpi-lbl">Task / Justification</label>
        <textarea name="reason" rows="2" class="eot-textarea" placeholder="Describe the deliverables or reason requiring overtime..." required></textarea>
      </div>
      <button type="submit" class="eot-btn" id="eotSubmitBtn">Submit Overtime Request</button>
    </form>
  </div>

  <div class="eot-card">
    <h2>My Overtime History</h2>
    <div class="eot-table-wrap">
      <table class="eot-table display" id="eotHistoryTable">
        <thead>
          <tr>
            <th>Date</th>
            <th>Time Window</th>
            <th>Type</th>
            <th>Requested</th>
            <th>Approved</th>
            <th>Reason</th>
            <th>Status</th>
            <th>Action</th>
          </tr>
        </thead>
        <tbody id="eotHistoryTbody"></tbody>
      </table>
    </div>
  </div>
</div>

<script>
(function () {
  let stateData = <?= json_encode($initState, JSON_UNESCAPED_UNICODE) ?>;
  let histDt = null;

  function esc(v) {
    return String(v ?? '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  function showNotice(type, text) {
    const box = document.getElementById('eotAlertBox');
    if (!box || !text) return;
    const cls = type === 'err' ? 'eot-alert-err' : 'eot-alert-ok';
    box.innerHTML = `<div class="${cls}">${esc(text)}</div>`;
  }

  function buildHistoryRows(rows) {
    return (rows || []).map(r => {
      const st = String(r.status || 'Pending');
      const bCls = st === 'Approved' ? 'b-app' : (st === 'Rejected' ? 'b-rej' : (st === 'Cancelled' ? 'b-can' : 'b-pen'));
      const reqMins = Number(r.requested_minutes || 0);
      const appMins = Number(r.approved_minutes || 0);
      const win = `${String(r.start_time || '').slice(0, 5)} - ${String(r.end_time || '').slice(0, 5)}`;
      const appTxt = st === 'Approved' ? `${appMins}m (${(appMins / 60).toFixed(2)}h)` : '—';
      const actHtml = st === 'Pending'
        ? `<button type="button" class="eot-btn-cancel" data-cancel-id="${Number(r.id)}">Cancel</button>`
        : '<span style="color:#64748b;font-size:12px;">—</span>';

      return [
        esc(r.work_date),
        esc(win),
        esc(r.ot_type),
        `${reqMins}m (${(reqMins / 60).toFixed(2)}h)`,
        esc(appTxt),
        esc(r.reason),
        `<span class="eot-badge ${bCls}">${esc(st)}</span>`,
        actHtml
      ];
    });
  }

  function renderHistory(rows) {
    if (!window.jQuery || !$.fn || !$.fn.DataTable) return;
    const dataRows = buildHistoryRows(rows);

    if (!histDt) {
      histDt = $('#eotHistoryTable').DataTable({
        data: dataRows,
        pageLength: 10,
        lengthMenu: [5, 10, 25, 50],
        order: [[0, 'desc']],
        autoWidth: false,
        columnDefs: [
          { orderable: false, targets: 7 }
        ],
        language: {
          search: 'Search:',
          lengthMenu: 'Show _MENU_ entries',
          emptyTable: 'No overtime requests filed yet.'
        }
      });
    } else {
      histDt.clear().rows.add(dataRows).draw(false);
    }
  }

  function applyState(payload) {
    if (!payload || !payload.ok) return;
    stateData = payload;
    const setText = (id, val) => {
      const el = document.getElementById(id);
      if (el) el.textContent = String(val);
    };
    setText('eotKpiApproved', `${payload.month_approved_hrs ?? '0.00'} hrs`);
    setText('eotKpiPending', `${payload.month_pending_hrs ?? '0.00'} hrs`);
    setText('eotKpiTotal', payload.total_count ?? 0);
    if (payload.server_time) {
      setText('eotSyncClock', `Synced at ${payload.server_time}`);
    }
    renderHistory(payload.requests || []);
  }

  async function pollState() {
    try {
      const res = await fetch(`?ajax=live_state&_t=${Date.now()}`, { cache: 'no-store' });
      if (!res.ok) return;
      const data = await res.json();
      applyState(data);
    } catch (_) {}
  }

  const submitForm = document.getElementById('eotSubmitForm');
  const submitBtn  = document.getElementById('eotSubmitBtn');
  if (submitForm) {
    submitForm.addEventListener('submit', async (e) => {
      e.preventDefault();
      if (submitBtn) submitBtn.disabled = true;
      try {
        const fd = new FormData(submitForm);
        fd.append('ajax', '1');
        const res = await fetch(window.location.pathname, {
          method: 'POST',
          body: fd,
          headers: { 'X-Requested-With': 'XMLHttpRequest' }
        });
        const data = await res.json();
        if (!res.ok || !data.ok) {
          showNotice('err', (data && data.error) || 'Could not submit overtime request.');
        } else {
          showNotice('ok', data.message || 'Overtime request submitted.');
          if (submitForm.reason) submitForm.reason.value = '';
          applyState(data);
        }
      } catch (_) {
        showNotice('err', 'Network error while submitting request.');
      } finally {
        if (submitBtn) submitBtn.disabled = false;
      }
    });
  }

  const tbody = document.getElementById('eotHistoryTbody');
  if (tbody) {
    tbody.addEventListener('click', async (e) => {
      const btn = e.target.closest('[data-cancel-id]');
      if (!btn) return;
      const reqId = btn.getAttribute('data-cancel-id');
      btn.disabled = true;
      try {
        const fd = new FormData();
        fd.append('ajax', '1');
        fd.append('ot_action', 'cancel_ot');
        fd.append('request_id', reqId);
        const res = await fetch(window.location.pathname, {
          method: 'POST',
          body: fd,
          headers: { 'X-Requested-With': 'XMLHttpRequest' }
        });
        const data = await res.json();
        if (!res.ok || !data.ok) {
          showNotice('err', (data && data.error) || 'Could not cancel request.');
        } else {
          showNotice('ok', data.message || 'Request cancelled.');
          applyState(data);
        }
      } catch (_) {
        showNotice('err', 'Network error while cancelling request.');
      } finally {
        btn.disabled = false;
      }
    });
  }

  window.addEventListener('load', () => {
    renderHistory(stateData.requests || []);
    pollState();
    setInterval(pollState, 3000);

    (async function attachWs() {
      const liveText = document.getElementById('eotLiveText');
      try {
        for (const u of ['/api/realtime_token.php', '../api/realtime_token.php']) {
          const r = await fetch(u, { cache: 'no-store' });
          if (!r.ok) continue;
          const j = await r.json();
          const token = j.token || (j.data && j.data.token) || '';
          if (!token) continue;
          const proto = window.location.protocol === 'https:' ? 'wss:' : 'ws:';
          const ws = new WebSocket(`${proto}//${window.location.hostname}:8090/ws?token=${encodeURIComponent(token)}`);
          ws.onopen = () => { if (liveText) liveText.textContent = 'LIVE • WS'; };
          ws.onmessage = (ev) => {
            try {
              const pkt = JSON.parse(ev.data);
              const t = (pkt && pkt.event && pkt.event.type) || '';
              if (t.startsWith('overtime.') || t.startsWith('attendance.')) {
                pollState();
              }
            } catch (_) {}
          };
          ws.onclose = () => { if (liveText) liveText.textContent = 'LIVE POLL'; };
          break;
        }
      } catch (_) {}
    })();
  });
})();
</script>