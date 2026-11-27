<?php

declare(strict_types=1);

require_once __DIR__ . '/../authentication/guard.php';
require_access('audit_logs', 'view', 'all');
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';

$cssPageOverride = 'audit_logs';
date_default_timezone_set('Asia/Manila');

function auditLabel(string $value): string
{
    return ucwords(str_replace(['_', '.', '-'], ' ', trim($value)));
}

function auditBadgeClass(string $action, string $severity = ''): string
{
    $action = strtolower($action);
    $severity = strtolower($severity);

    if ($severity === 'critical' || $severity === 'high' || str_contains($action, 'failed') || str_contains($action, 'delete') || str_contains($action, 'deactivate')) {
        return 'badge-danger';
    }
    if (str_contains($action, 'approve') || str_contains($action, 'create') || str_contains($action, 'activate') || $action === 'login') {
        return 'badge-success';
    }
    if (str_contains($action, 'reject') || str_contains($action, 'cancel')) {
        return 'badge-warning';
    }
    if (str_contains($action, 'update') || str_contains($action, 'status_change') || str_contains($action, 'edit')) {
        return 'badge-info';
    }
    return 'badge-neutral';
}

function auditSeverity(string $action, array $details): string
{
    $value = strtolower((string)($details['severity'] ?? ''));
    if (in_array($value, ['low', 'info', 'medium', 'high', 'critical'], true)) {
        return $value;
    }
    $action = strtolower($action);
    if (str_contains($action, 'failed') || str_contains($action, 'delete') || str_contains($action, 'deactivate')) {
        return 'high';
    }
    if (str_contains($action, 'password') || str_contains($action, 'permission') || str_contains($action, 'role')) {
        return 'medium';
    }
    return 'info';
}

function auditDate(string $value): bool
{
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    return $date !== false && $date->format('Y-m-d') === $value;
}

function auditUrl(array $replace = []): string
{
    $query = array_merge($_GET, $replace);
    return '/admin/audit_logs.php?' . http_build_query($query);
}

function auditCsvSafe(mixed $value): string
{
    $value = (string)($value ?? '');
    return preg_match('/^[\s]*[=+\-@]/u', $value) ? "'" . $value : $value;
}

function auditJsonForScript(array $value): string
{
    return json_encode($value, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?: '{}';
}

$action = trim((string)($_GET['action'] ?? ''));
$entity = trim((string)($_GET['entity_type'] ?? ''));
$actor = trim((string)($_GET['actor'] ?? ''));
$severity = strtolower(trim((string)($_GET['severity'] ?? '')));
$search = trim((string)($_GET['q'] ?? ''));
$dateFrom = trim((string)($_GET['date_from'] ?? ''));
$dateTo = trim((string)($_GET['date_to'] ?? ''));
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 25;
$errors = [];

if ($dateFrom !== '' && !auditDate($dateFrom)) {
    $errors[] = 'Invalid starting date.';
    $dateFrom = '';
}
if ($dateTo !== '' && !auditDate($dateTo)) {
    $errors[] = 'Invalid ending date.';
    $dateTo = '';
}
if ($dateFrom !== '' && $dateTo !== '' && $dateFrom > $dateTo) {
    $errors[] = 'The starting date cannot be after the ending date.';
}
if ($severity !== '' && !in_array($severity, ['info', 'medium', 'high', 'critical'], true)) {
    $severity = '';
}

$where = [];
$params = [];
if ($action !== '') {
    $where[] = 'al.action = :action';
    $params[':action'] = $action;
}
if ($entity !== '') {
    $where[] = 'al.entity_type = :entity_type';
    $params[':entity_type'] = $entity;
}
if ($actor !== '') {
    $where[] = '(u.username LIKE :actor_username OR e.first_name LIKE :actor_first OR e.last_name LIKE :actor_last OR e.employee_id LIKE :employee_id OR al.actor_user_id = :actor_id)';
    $like = '%' . $actor . '%';
    $params[':actor_username'] = $like;
    $params[':actor_first'] = $like;
    $params[':actor_last'] = $like;
    $params[':employee_id'] = $like;
    $params[':actor_id'] = ctype_digit($actor) ? (int)$actor : 0;
}
if ($search !== '') {
    $where[] = '(al.action LIKE :q_action OR al.entity_type LIKE :q_entity OR al.details LIKE :q_details)';
    $like = '%' . $search . '%';
    $params[':q_action'] = $like;
    $params[':q_entity'] = $like;
    $params[':q_details'] = $like;
}
if ($dateFrom !== '') {
    $where[] = 'al.created_at >= :date_from';
    $params[':date_from'] = $dateFrom . ' 00:00:00';
}
if ($dateTo !== '') {
    $where[] = 'al.created_at < DATE_ADD(:date_to, INTERVAL 1 DAY)';
    $params[':date_to'] = $dateTo;
}
$severitySql = "(
    LOWER(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(al.details, '$.severity')), '')) = :severity_json
    OR (:severity_fallback = 'high' AND (al.action LIKE '%failed%' OR al.action LIKE '%delete%' OR al.action LIKE '%deactivate%'))
    OR (:severity_fallback = 'medium' AND (al.action LIKE '%password%' OR al.action LIKE '%permission%' OR al.action LIKE '%role%'))
)";
if ($severity !== '') {
    $where[] = $severitySql;
    $params[':severity_json'] = $severity;
    $params[':severity_fallback'] = $severity;
}
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$allActions = $pdo->query('SELECT DISTINCT action FROM audit_logs ORDER BY action')->fetchAll(PDO::FETCH_COLUMN);
$allEntities = $pdo->query('SELECT DISTINCT entity_type FROM audit_logs ORDER BY entity_type')->fetchAll(PDO::FETCH_COLUMN);

$summaryStmt = $pdo->prepare("SELECT
    COUNT(*) AS total,
    COALESCE(SUM(DATE(al.created_at) = CURDATE()), 0) AS today,
    COALESCE(SUM(al.action LIKE '%failed%' OR al.action LIKE '%reject%' OR al.action LIKE '%delete%'), 0) AS risky,
    COUNT(DISTINCT al.actor_user_id) AS actors
    FROM audit_logs al
    LEFT JOIN users u ON u.id = al.actor_user_id
    LEFT JOIN employees e ON e.id = u.employee_id
    $whereSql");
$summaryStmt->execute($params);
$summary = $summaryStmt->fetch(PDO::FETCH_ASSOC) ?: ['total' => 0, 'today' => 0, 'risky' => 0, 'actors' => 0];

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM audit_logs al LEFT JOIN users u ON u.id = al.actor_user_id LEFT JOIN employees e ON e.id = u.employee_id $whereSql");
$countStmt->execute($params);
$totalRows = (int)$countStmt->fetchColumn();
$totalPages = max(1, (int)ceil($totalRows / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

$trendFilterSql = $where ? ' AND ' . implode(' AND ', $where) : '';
$trendStmt = $pdo->prepare("SELECT DATE(al.created_at) AS day, COUNT(*) AS total
    FROM audit_logs al LEFT JOIN users u ON u.id = al.actor_user_id LEFT JOIN employees e ON e.id = u.employee_id
    WHERE al.created_at >= DATE_SUB(CURDATE(), INTERVAL 13 DAY) $trendFilterSql
    GROUP BY DATE(al.created_at) ORDER BY day");
$trendParams = $params;
try { $trendStmt->execute($trendParams); } catch (Throwable $e) { $trendStmt = null; }
$trendRows = $trendStmt ? $trendStmt->fetchAll(PDO::FETCH_ASSOC) : [];
$trendMap = [];
foreach ($trendRows as $row) { $trendMap[$row['day']] = (int)$row['total']; }
$trendLabels = [];
$trendData = [];
for ($i = 13; $i >= 0; $i--) {
    $day = date('Y-m-d', strtotime('-' . $i . ' days'));
    $trendLabels[] = date('M j', strtotime($day));
    $trendData[] = $trendMap[$day] ?? 0;
}

$breakdownStmt = $pdo->prepare("SELECT al.entity_type AS label, COUNT(*) AS total FROM audit_logs al LEFT JOIN users u ON u.id = al.actor_user_id LEFT JOIN employees e ON e.id = u.employee_id $whereSql GROUP BY al.entity_type ORDER BY total DESC LIMIT 6");
$breakdownStmt->execute($params);
$breakdown = $breakdownStmt->fetchAll(PDO::FETCH_ASSOC);
$topActionStmt = $pdo->prepare("SELECT al.action AS label, COUNT(*) AS total FROM audit_logs al LEFT JOIN users u ON u.id = al.actor_user_id LEFT JOIN employees e ON e.id = u.employee_id $whereSql GROUP BY al.action ORDER BY total DESC LIMIT 6");
$topActionStmt->execute($params);
$topActions = $topActionStmt->fetchAll(PDO::FETCH_ASSOC);

$dataSql = "SELECT al.id, al.actor_user_id, u.username AS actor_username, u.role AS actor_role,
    e.employee_id AS employee_number, e.first_name, e.last_name, al.action, al.entity_type,
    al.entity_id, al.details, al.created_at
    FROM audit_logs al LEFT JOIN users u ON u.id = al.actor_user_id LEFT JOIN employees e ON e.id = u.employee_id
    $whereSql ORDER BY al.created_at DESC, al.id DESC";
$isExport = ($_GET['export'] ?? '') === 'csv';
if ($isExport && !$errors) {
    $stmt = $pdo->prepare($dataSql);
    $stmt->execute($params);
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="audit_trail_' . date('Y-m-d_His') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['ID', 'Timestamp', 'Actor', 'Username', 'Role', 'Action', 'Entity', 'Record ID', 'Severity', 'Details']);
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $details = json_decode((string)$row['details'], true);
        $details = is_array($details) ? $details : [];
        fputcsv($out, [
            auditCsvSafe($row['id']), auditCsvSafe($row['created_at']),
            auditCsvSafe(trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? '')) ?: 'System'),
            auditCsvSafe($row['actor_username'] ?? 'System'), auditCsvSafe($row['actor_role'] ?? '—'),
            auditCsvSafe($row['action']), auditCsvSafe($row['entity_type']), auditCsvSafe($row['entity_id'] ?? '—'),
            auditCsvSafe(auditSeverity($row['action'], $details)), auditCsvSafe(json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
        ]);
    }
    fclose($out);
    exit;
}

$stmt = $pdo->prepare($dataSql . ' LIMIT :limit OFFSET :offset');
foreach ($params as $key => $value) { $stmt->bindValue($key, $value); }
$stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

$exportUrl = auditUrl(['export' => 'csv', 'page' => null]);
require __DIR__ . '/../templates/adminTemplates/header.php';
?>

<style>
.audit-analytics{display:grid;grid-template-columns:1.35fr 1fr 1fr;gap:.85rem}.audit-panel{min-width:0;padding:1rem;border:1px solid var(--line);border-radius:.85rem;background:var(--panel)}.audit-panel h3{margin:0;color:var(--moon);font-size:.9rem;font-weight:750}.audit-panel p{margin:.25rem 0 0;color:var(--muted);font-size:.72rem}.audit-chart-wrap{height:175px;margin-top:.8rem}.audit-breakdown{display:flex;flex-direction:column;gap:.7rem;margin-top:1rem}.audit-breakdown-row{display:grid;grid-template-columns:minmax(0,1fr) 2.2rem;gap:.6rem;align-items:center;font-size:.75rem}.audit-breakdown-label{overflow:hidden;color:var(--text);text-overflow:ellipsis;white-space:nowrap}.audit-breakdown-count{color:var(--moon);font-weight:750;text-align:right}.audit-breakdown-track{height:.32rem;margin-top:.3rem;overflow:hidden;border-radius:99px;background:var(--panel-2)}.audit-breakdown-fill{height:100%;border-radius:inherit;background:linear-gradient(90deg,var(--blue),var(--teal))}.audit-modal{border:1px solid var(--line);background:var(--panel);color:var(--text)}.audit-modal .modal-header,.audit-modal .modal-footer{border-color:var(--line)}.audit-modal .modal-title{color:var(--moon);font-weight:750}.audit-modal .btn-close{filter:invert(1);opacity:.7}.audit-detail-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:.8rem;margin-bottom:1rem}.audit-detail-item{padding:.7rem;border:1px solid var(--line);border-radius:.6rem;background:var(--panel-2)}.audit-detail-item span{display:block;margin-bottom:.25rem;color:var(--muted);font-size:.65rem;font-weight:750;letter-spacing:.06em;text-transform:uppercase}.audit-detail-item strong{color:var(--text);font-size:.8rem;overflow-wrap:anywhere}.audit-json{max-height:310px;overflow:auto;margin:0;padding:.85rem;border:1px solid var(--line);border-radius:.6rem;background:var(--page-2);color:var(--text);font: .72rem/1.6 ui-monospace,SFMono-Regular,Consolas,monospace;white-space:pre-wrap;overflow-wrap:anywhere}.audit-view-btn{border:0;background:transparent;color:var(--teal);font-size:.76rem;font-weight:750}.audit-view-btn:hover{color:var(--moon)}.audit-risk{display:inline-flex;align-items:center;gap:.3rem;margin-top:.25rem;color:#fb7185;font-size:.68rem;font-weight:700}.audit-risk-dot{width:.4rem;height:.4rem;border-radius:50%;background:#fb7185}.audit-filter-form{grid-template-columns:repeat(6,minmax(0,1fr))}.audit-search-field{grid-column:span 2}.audit-meta{display:flex;align-items:center;gap:.6rem;color:var(--muted);font-size:.72rem}.audit-meta strong{color:var(--text)}
@media(max-width:1100px){.audit-analytics{grid-template-columns:1fr 1fr}.audit-analytics .audit-panel:first-child{grid-column:1/-1}.audit-filter-form{grid-template-columns:repeat(3,minmax(0,1fr))}.audit-search-field{grid-column:span 2}}
@media(max-width:700px){.audit-analytics{grid-template-columns:1fr}.audit-analytics .audit-panel:first-child{grid-column:auto}.audit-filter-form{grid-template-columns:repeat(2,minmax(0,1fr))}.audit-search-field{grid-column:span 2}.audit-detail-grid{grid-template-columns:1fr}}
@media(max-width:420px){.audit-filter-form{grid-template-columns:1fr}.audit-search-field{grid-column:auto}}
</style>

<section class="audit-page">
    <div class="audit-heading">
        <div>
            <p class="audit-eyebrow">SECURITY &amp; COMPLIANCE</p>
            <h1>Audit Trail</h1>
            <p class="audit-description">A read-only record of authentication, data changes, approvals, and system events across the HRIS.</p>
        </div>
        <div class="d-flex flex-wrap gap-2">
            <a href="<?= h(auditUrl(['export' => 'csv', 'page' => null])) ?>" class="audit-export-btn"><i class="bi bi-download"></i> Export CSV</a>
        </div>
    </div>

    <?php if ($errors): ?><div class="audit-alert"><?php foreach ($errors as $error): ?><p><?= h($error) ?></p><?php endforeach; ?></div><?php endif; ?>

    <div class="audit-stats">
        <article class="audit-stat"><div class="audit-stat-top"><span>Matching events</span><span class="audit-stat-icon"><i class="bi bi-activity"></i></span></div><strong><?= number_format((int)$summary['total']) ?></strong><small>Immutable records in this view</small></article>
        <article class="audit-stat"><div class="audit-stat-top"><span>Events today</span><span class="audit-stat-icon"><i class="bi bi-calendar2-check"></i></span></div><strong><?= number_format((int)$summary['today']) ?></strong><small>Recorded since midnight</small></article>
        <article class="audit-stat"><div class="audit-stat-top"><span>Risk signals</span><span class="audit-stat-icon danger"><i class="bi bi-shield-exclamation"></i></span></div><strong><?= number_format((int)$summary['risky']) ?></strong><small>Failed, rejected, or destructive actions</small></article>
        <article class="audit-stat"><div class="audit-stat-top"><span>Unique actors</span><span class="audit-stat-icon success"><i class="bi bi-people"></i></span></div><strong><?= number_format((int)$summary['actors']) ?></strong><small>Authenticated users represented</small></article>
    </div>

    <div class="audit-analytics">
        <article class="audit-panel"><div class="d-flex justify-content-between gap-2"><div><h3>Activity volume</h3><p>Last 14 days, based on the active filters</p></div><i class="bi bi-bar-chart-line" style="color:var(--teal)"></i></div><div class="audit-chart-wrap"><canvas id="auditTrendChart"></canvas></div></article>
        <article class="audit-panel"><h3>Events by entity</h3><p>Where activity is concentrated</p><div class="audit-breakdown"><?php $max = max(1, (int)($breakdown[0]['total'] ?? 1)); foreach ($breakdown as $row): ?><div class="audit-breakdown-row"><div><div class="audit-breakdown-label"><?= h(auditLabel((string)$row['label'])) ?></div><div class="audit-breakdown-track"><div class="audit-breakdown-fill" style="width:<?= min(100, round(((int)$row['total'] / $max) * 100)) ?>%"></div></div></div><div class="audit-breakdown-count"><?= (int)$row['total'] ?></div></div><?php endforeach; ?><?php if (!$breakdown): ?><p>No entity data available.</p><?php endif; ?></div></article>
        <article class="audit-panel"><h3>Top actions</h3><p>Most frequent event types</p><div class="audit-breakdown"><?php $max = max(1, (int)($topActions[0]['total'] ?? 1)); foreach ($topActions as $row): ?><div class="audit-breakdown-row"><div><div class="audit-breakdown-label"><?= h(auditLabel((string)$row['label'])) ?></div><div class="audit-breakdown-track"><div class="audit-breakdown-fill" style="width:<?= min(100, round(((int)$row['total'] / $max) * 100)) ?>%"></div></div></div><div class="audit-breakdown-count"><?= (int)$row['total'] ?></div></div><?php endforeach; ?><?php if (!$topActions): ?><p>No action data available.</p><?php endif; ?></div></article>
    </div>

    <div class="audit-filter-card">
        <div class="audit-filter-heading"><div><h2>Search and filter events</h2><p>Use the detail button in each row to inspect the complete event payload.</p></div><span class="audit-record-count"><?= number_format($totalRows) ?> records</span></div>
        <form method="get" action="/admin/audit_logs.php" class="audit-filter-form">
            <div class="audit-filter-field audit-search-field"><label for="audit-q">Search payload</label><input id="audit-q" name="q" value="<?= h($search) ?>" placeholder="Action, entity, request ID, IP…"></div>
            <div class="audit-filter-field"><label for="audit-action">Action</label><select id="audit-action" name="action"><option value="">All actions</option><?php foreach ($allActions as $value): ?><option value="<?= h($value) ?>" <?= $action === $value ? 'selected' : '' ?>><?= h(auditLabel($value)) ?></option><?php endforeach; ?></select></div>
            <div class="audit-filter-field"><label for="audit-entity">Entity</label><select id="audit-entity" name="entity_type"><option value="">All entities</option><?php foreach ($allEntities as $value): ?><option value="<?= h($value) ?>" <?= $entity === $value ? 'selected' : '' ?>><?= h(auditLabel($value)) ?></option><?php endforeach; ?></select></div>
            <div class="audit-filter-field"><label for="audit-severity">Severity</label><select id="audit-severity" name="severity"><option value="">All severity</option><?php foreach (['info', 'medium', 'high', 'critical'] as $value): ?><option value="<?= $value ?>" <?= $severity === $value ? 'selected' : '' ?>><?= ucfirst($value) ?></option><?php endforeach; ?></select></div>
            <div class="audit-filter-field"><label for="audit-actor">Actor</label><input id="audit-actor" name="actor" value="<?= h($actor) ?>" placeholder="Username, name, ID"></div>
            <div class="audit-filter-field"><label for="audit-from">Date from</label><input id="audit-from" type="date" name="date_from" value="<?= h($dateFrom) ?>"></div>
            <div class="audit-filter-field"><label for="audit-to">Date to</label><input id="audit-to" type="date" name="date_to" value="<?= h($dateTo) ?>"></div>
            <div class="audit-filter-actions"><button class="audit-filter-btn" type="submit"><i class="bi bi-funnel"></i> Apply filters</button><a class="audit-reset-btn" href="/admin/audit_logs.php">Reset</a></div>
        </form>
    </div>

    <div class="audit-table-card">
        <div class="audit-table-scroll"><table class="audit-table"><thead><tr><th>ID</th><th>Timestamp</th><th>Actor</th><th>Event</th><th>Entity</th><th>Record</th><th>Inspect</th></tr></thead><tbody>
        <?php if (!$logs): ?><tr><td colspan="7"><div class="audit-empty"><span><i class="bi bi-inbox"></i></span><strong>No audit records found</strong><p>Try changing the filters or resetting the search.</p></div></td></tr><?php endif; ?>
        <?php foreach ($logs as $index => $log): $details = json_decode((string)$log['details'], true); $details = is_array($details) ? $details : []; $level = auditSeverity((string)$log['action'], $details); $name = trim(($log['first_name'] ?? '') . ' ' . ($log['last_name'] ?? '')); $system = $log['actor_user_id'] === null; $payload = ['id' => (int)$log['id'], 'timestamp' => (string)$log['created_at'], 'actor' => $system ? 'System / automated process' : ($name ?: ($log['actor_username'] ?? 'Unknown')), 'username' => $log['actor_username'] ?? 'System', 'role' => $log['actor_role'] ?? '—', 'action' => auditLabel((string)$log['action']), 'entity' => auditLabel((string)$log['entity_type']), 'entity_id' => $log['entity_id'] !== null ? '#' . $log['entity_id'] : '—', 'severity' => ucfirst($level), 'details' => $details]; ?>
            <tr><td class="audit-id">#<?= (int)$log['id'] ?></td><td class="audit-timestamp"><strong><?= h(date('M d, Y', strtotime($log['created_at']))) ?></strong><small><?= h(date('h:i:s A', strtotime($log['created_at']))) ?></small></td><td><div class="audit-actor"><span class="audit-avatar <?= $system ? 'system' : '' ?>"><?= $system ? 'S' : h(strtoupper(substr($name ?: ($log['actor_username'] ?? 'U'), 0, 1))) ?></span><div class="audit-actor-info"><strong><?= h($system ? 'System' : ($name ?: ($log['actor_username'] ?? 'Unknown'))) ?></strong><small><?= h($system ? 'Automated event' : (($log['actor_username'] ?? 'Unknown') . ' · ' . auditLabel((string)($log['actor_role'] ?? 'user')))) ?></small></div></div></td><td><span class="audit-badge <?= h(auditBadgeClass((string)$log['action'], $level)) ?>"><?= h(auditLabel((string)$log['action'])) ?></span><?php if (in_array($level, ['high', 'critical'], true)): ?><div class="audit-risk"><span class="audit-risk-dot"></span><?= h(ucfirst($level)) ?> attention</div><?php endif; ?></td><td><span class="audit-entity"><?= h(auditLabel((string)$log['entity_type'])) ?></span></td><td class="audit-record-id"><?= $log['entity_id'] !== null ? '#' . (int)$log['entity_id'] : '—' ?></td><td><button type="button" class="audit-view-btn" data-bs-toggle="modal" data-bs-target="#auditDetailModal" data-audit-id="audit-payload-<?= $index ?>"><i class="bi bi-eye"></i> View details</button><script type="application/json" id="audit-payload-<?= $index ?>"><?= auditJsonForScript($payload) ?></script></td></tr>
        <?php endforeach; ?></tbody></table></div>
        <div class="audit-table-footer"><span class="audit-meta">Showing <strong><?= number_format(count($logs)) ?></strong> of <strong><?= number_format($totalRows) ?></strong> records</span><?php if ($totalPages > 1): ?><div class="audit-pagination"><?php if ($page > 1): ?><a href="<?= h(auditUrl(['page' => $page - 1])) ?>">←</a><?php endif; ?><?php $start = max(1, $page - 2); $end = min($totalPages, $page + 2); for ($i = $start; $i <= $end; $i++): ?><a class="<?= $i === $page ? 'active' : '' ?>" href="<?= h(auditUrl(['page' => $i])) ?>"><?= $i ?></a><?php endfor; ?><?php if ($page < $totalPages): ?><a href="<?= h(auditUrl(['page' => $page + 1])) ?>">→</a><?php endif; ?></div><?php endif; ?></div>
    </div>
</section>

<div class="modal fade" id="auditDetailModal" tabindex="-1" aria-labelledby="auditDetailModalLabel" aria-hidden="true"><div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable"><div class="modal-content audit-modal"><div class="modal-header"><h5 class="modal-title" id="auditDetailModalLabel"><i class="bi bi-shield-check me-2"></i>Audit event details</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div><div class="modal-body"><div class="audit-detail-grid" id="auditDetailGrid"></div><h6 class="mb-2" style="color:var(--moon)">Recorded payload</h6><pre class="audit-json" id="auditDetailJson">{}</pre></div><div class="modal-footer"><span class="me-auto" style="color:var(--muted);font-size:.72rem"><i class="bi bi-lock me-1"></i>Audit records are read-only.</span><button type="button" class="audit-reset-btn" data-bs-dismiss="modal">Close</button></div></div></div></div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
(() => {
  const canvas = document.getElementById('auditTrendChart');
  if (canvas && window.Chart) new Chart(canvas.getContext('2d'), {type:'line',data:{labels:<?= json_encode($trendLabels) ?>,datasets:[{data:<?= json_encode($trendData) ?>,borderColor:'#76f5e8',backgroundColor:'rgba(118,245,232,.10)',fill:true,tension:.35,pointRadius:2,pointBackgroundColor:'#76f5e8'}]},options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{display:false}},scales:{x:{grid:{color:'rgba(42,53,102,.35)'},ticks:{color:'#B4A5C0',font:{size:10}}},y:{beginAtZero:true,grid:{color:'rgba(42,53,102,.35)'},ticks:{color:'#B4A5C0',precision:0,font:{size:10}}}}}});
  const modal = document.getElementById('auditDetailModal');
  modal?.addEventListener('show.bs.modal', event => {
    const id = event.relatedTarget?.getAttribute('data-audit-id');
    const source = id ? document.getElementById(id) : null;
    if (!source) return;
    const item = JSON.parse(source.textContent || '{}');
    const grid = document.getElementById('auditDetailGrid');
    const fields = [['Event', item.action], ['Entity', item.entity + ' ' + item.entity_id], ['Actor', item.actor], ['Account', item.username + ' · ' + item.role], ['Timestamp', item.timestamp], ['Severity', item.severity]];
    grid.innerHTML = fields.map(([label, value]) => `<div class="audit-detail-item"><span>${label}</span><strong>${String(value ?? '—').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]))}</strong></div>`).join('');
    document.getElementById('auditDetailJson').textContent = JSON.stringify(item.details || {}, null, 2);
  });
})();
</script>
</main></body></html>
