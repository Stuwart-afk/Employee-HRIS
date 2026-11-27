<?php

require_once __DIR__ . '/../authentication/guard.php';

require_access('audit_logs', 'view', 'all');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

function auditBadgeClass(string $action): string
{
    $action = strtolower($action);

    if (str_contains($action, 'failed')
        || str_contains($action, 'delete')
        || str_contains($action, 'deactivate')) {
        return 'badge-danger';
    }

    if (str_contains($action, 'approve')
        || str_contains($action, 'create')
        || str_contains($action, 'activate')
        || ($action === 'login')) {
        return 'badge-success';
    }

    if (str_contains($action, 'reject')
        || str_contains($action, 'cancel')) {
        return 'badge-warning';
    }

    if (str_contains($action, 'update')
        || str_contains($action, 'status_change')
        || str_contains($action, 'edit')) {
        return 'badge-info';
    }

    return 'badge-neutral';
}

function auditFormatLabel(string $value): string
{
    return ucwords(str_replace(['_', '.', '-'], ' ', $value));
}

function auditPageUrl(int $page): string
{
    $query = $_GET;
    $query['page'] = $page;

    unset($query['export']);

    return '/admin/audit_logs.php?' . http_build_query($query);
}

function auditCsvSafe($value): string
{
    $value = (string)($value ?? '');

    // Prevent spreadsheet formula injection in exported CSV files.
    if (preg_match('/^[\s]*[=+\-@]/u', $value)) {
        return "'" . $value;
    }

    return $value;
}

function auditValidDate(string $date): bool
{
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);

    return $parsed !== false
        && $parsed->format('Y-m-d') === $date;
}

/*
|--------------------------------------------------------------------------
| Filters
|--------------------------------------------------------------------------
*/

$filterAction   = trim($_GET['action'] ?? '');
$filterEntity   = trim($_GET['entity_type'] ?? '');
$filterUser     = trim($_GET['actor'] ?? '');
$filterDateFrom = trim($_GET['date_from'] ?? '');
$filterDateTo   = trim($_GET['date_to'] ?? '');

$page = max(1, (int)($_GET['page'] ?? 1));

$perPage = 25;
$offset = ($page - 1) * $perPage;

$isExport = ($_GET['export'] ?? '') === 'csv';

$errors = [];

if ($filterDateFrom !== '' && !auditValidDate($filterDateFrom)) {
    $errors[] = 'Invalid starting date.';
    $filterDateFrom = '';
}

if ($filterDateTo !== '' && !auditValidDate($filterDateTo)) {
    $errors[] = 'Invalid ending date.';
    $filterDateTo = '';
}

if (
    $filterDateFrom !== ''
    && $filterDateTo !== ''
    && $filterDateFrom > $filterDateTo
) {
    $errors[] = 'The starting date cannot be after the ending date.';
}

/*
|--------------------------------------------------------------------------
| Build WHERE conditions
|--------------------------------------------------------------------------
*/

$where = [];
$params = [];

if ($filterAction !== '') {
    $where[] = 'al.action = :action';
    $params[':action'] = $filterAction;
}

if ($filterEntity !== '') {
    $where[] = 'al.entity_type = :entity_type';
    $params[':entity_type'] = $filterEntity;
}

if ($filterUser !== '') {
    $where[] = "(
        u.username LIKE :actor_username
        OR u.id = :actor_id
        OR e.first_name LIKE :actor_first
        OR e.last_name LIKE :actor_last
        OR CONCAT_WS(' ', e.first_name, e.last_name) LIKE :actor_full
        OR e.employee_id LIKE :employee_id
    )";

    $search = '%' . $filterUser . '%';

    $params[':actor_username'] = $search;
    $params[':actor_id'] = ctype_digit($filterUser)
        ? (int)$filterUser
        : 0;
    $params[':actor_first'] = $search;
    $params[':actor_last'] = $search;
    $params[':actor_full'] = $search;
    $params[':employee_id'] = $search;
}

if ($filterDateFrom !== '') {
    $where[] = 'al.created_at >= :date_from';
    $params[':date_from'] = $filterDateFrom . ' 00:00:00';
}

if ($filterDateTo !== '') {
    $where[] = 'al.created_at < DATE_ADD(:date_to, INTERVAL 1 DAY)';
    $params[':date_to'] = $filterDateTo;
}

$whereSql = $where
    ? 'WHERE ' . implode(' AND ', $where)
    : '';

/*
|--------------------------------------------------------------------------
| Dropdown values
|--------------------------------------------------------------------------
*/

$allActions = $pdo->query("
    SELECT DISTINCT action
    FROM audit_logs
    ORDER BY action
")->fetchAll(PDO::FETCH_COLUMN);

$allEntities = $pdo->query("
    SELECT DISTINCT entity_type
    FROM audit_logs
    ORDER BY entity_type
")->fetchAll(PDO::FETCH_COLUMN);

/*
|--------------------------------------------------------------------------
| Summary statistics
|--------------------------------------------------------------------------
*/

$summarySql = "
    SELECT
        COUNT(*) AS total,

        COALESCE(SUM(
            DATE(al.created_at) = CURRENT_DATE()
        ), 0) AS today,

        COALESCE(SUM(
            al.action NOT LIKE '%failed%'
            AND (
                al.action LIKE '%create%'
                OR al.action LIKE '%approve%'
                OR al.action LIKE '%login%'
            )
        ), 0) AS successful,

        COALESCE(SUM(
            al.action LIKE '%failed%'
            OR al.action LIKE '%reject%'
        ), 0) AS failed

    FROM audit_logs al
    LEFT JOIN users u
        ON u.id = al.actor_user_id
    LEFT JOIN employees e
        ON e.id = u.employee_id

    $whereSql
";

$summaryStmt = $pdo->prepare($summarySql);
$summaryStmt->execute($params);

$summary = $summaryStmt->fetch(PDO::FETCH_ASSOC) ?: [
    'total' => 0,
    'today' => 0,
    'successful' => 0,
    'failed' => 0,
];

/*
|--------------------------------------------------------------------------
| Pagination count
|--------------------------------------------------------------------------
*/

$countSql = "
    SELECT COUNT(*)
    FROM audit_logs al
    LEFT JOIN users u
        ON u.id = al.actor_user_id
    LEFT JOIN employees e
        ON e.id = u.employee_id

    $whereSql
";

$countStmt = $pdo->prepare($countSql);
$countStmt->execute($params);

$totalRows = (int)$countStmt->fetchColumn();

$totalPages = max(1, (int)ceil($totalRows / $perPage));

$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

/*
|--------------------------------------------------------------------------
| Fetch audit records
|--------------------------------------------------------------------------
*/

$dataSql = "
    SELECT
        al.id,
        al.actor_user_id,
        u.username AS actor_username,
        u.role AS actor_role,

        e.employee_id AS employee_number,
        e.first_name,
        e.last_name,

        al.action,
        al.entity_type,
        al.entity_id,
        al.details,
        al.created_at

    FROM audit_logs al

    LEFT JOIN users u
        ON u.id = al.actor_user_id

    LEFT JOIN employees e
        ON e.id = u.employee_id

    $whereSql

    ORDER BY al.created_at DESC, al.id DESC
";

/*
|--------------------------------------------------------------------------
| CSV export
|--------------------------------------------------------------------------
*/

if ($isExport && empty($errors)) {
    $exportStmt = $pdo->prepare($dataSql);
    $exportStmt->execute($params);

    header('Content-Type: text/csv; charset=UTF-8');

    header(
        'Content-Disposition: attachment; filename="audit_trail_' .
        date('Y-m-d_His') .
        '.csv"'
    );

    $out = fopen('php://output', 'w');

    // UTF-8 BOM for spreadsheet compatibility.
    fwrite($out, "\xEF\xBB\xBF");

    fputcsv($out, [
        'ID',
        'Actor User ID',
        'Username',
        'Employee Name',
        'Employee Number',
        'Role',
        'Action',
        'Entity Type',
        'Entity ID',
        'Details',
        'Timestamp',
    ]);

    while ($row = $exportStmt->fetch(PDO::FETCH_ASSOC)) {
        $employeeName = trim(
            ($row['first_name'] ?? '') . ' ' .
            ($row['last_name'] ?? '')
        );

        $details = $row['details'] ?? '';

        fputcsv($out, [
            auditCsvSafe($row['id']),
            auditCsvSafe($row['actor_user_id'] ?? 'System'),
            auditCsvSafe($row['actor_username'] ?? 'System'),
            auditCsvSafe($employeeName ?: '—'),
            auditCsvSafe($row['employee_number'] ?? '—'),
            auditCsvSafe($row['actor_role'] ?? '—'),
            auditCsvSafe($row['action']),
            auditCsvSafe($row['entity_type']),
            auditCsvSafe($row['entity_id'] ?? '—'),
            auditCsvSafe($details),
            auditCsvSafe($row['created_at']),
        ]);
    }

    fclose($out);
    exit;
}

/*
|--------------------------------------------------------------------------
| Paginated records
|--------------------------------------------------------------------------
*/

$dataStmt = $pdo->prepare($dataSql . "
    LIMIT :limit OFFSET :offset
");

foreach ($params as $key => $value) {
    $dataStmt->bindValue($key, $value);
}

$dataStmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$dataStmt->bindValue(':offset', $offset, PDO::PARAM_INT);

$dataStmt->execute();

$logs = $dataStmt->fetchAll(PDO::FETCH_ASSOC);

/*
|--------------------------------------------------------------------------
| CSV URL
|--------------------------------------------------------------------------
*/

$exportQuery = $_GET;
unset($exportQuery['page']);

$exportQuery['export'] = 'csv';

$exportUrl = '/admin/audit_logs.php?' .
    http_build_query($exportQuery);

require __DIR__ . '/../templates/adminTemplates/header.php';

?>

<section class="audit-page">

    <div class="audit-heading">
        <div>
            <p class="audit-eyebrow">SYSTEM MONITORING</p>

            <h1>Audit Trail</h1>

            <p class="audit-description">
                Monitor user activities, system events, and changes
                made throughout the HRIS.
            </p>
        </div>

        <a href="<?= h($exportUrl) ?>"
           class="audit-export-btn">

            <span aria-hidden="true">↓</span>
            Export CSV
        </a>
    </div>

    <?php if (!empty($errors)): ?>
        <div class="audit-alert">
            <?php foreach ($errors as $error): ?>
                <p><?= h($error) ?></p>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <!-- Summary cards -->

    <div class="audit-stats">

        <article class="audit-stat">
            <div class="audit-stat-top">
                <span>Total Records</span>
                <span class="audit-stat-icon">▤</span>
            </div>

            <strong><?= number_format((int)$summary['total']) ?></strong>

            <small>Matching audit records</small>
        </article>

        <article class="audit-stat">
            <div class="audit-stat-top">
                <span>Today's Activity</span>
                <span class="audit-stat-icon">◷</span>
            </div>

            <strong><?= number_format((int)$summary['today']) ?></strong>

            <small>Events recorded today</small>
        </article>

        <article class="audit-stat">
            <div class="audit-stat-top">
                <span>Successful Events</span>
                <span class="audit-stat-icon success">✓</span>
            </div>

            <strong><?= number_format((int)$summary['successful']) ?></strong>

            <small>Creates, approvals and logins</small>
        </article>

        <article class="audit-stat">
            <div class="audit-stat-top">
                <span>Failed / Rejected</span>
                <span class="audit-stat-icon danger">!</span>
            </div>

            <strong><?= number_format((int)$summary['failed']) ?></strong>

            <small>Failed or rejected events</small>
        </article>

    </div>

    <!-- Filters -->

    <div class="audit-filter-card">

        <div class="audit-filter-heading">
            <div>
                <h2>Activity Records</h2>
                <p>Filter and inspect recorded system activities.</p>
            </div>

            <span class="audit-record-count">
                <?= number_format($totalRows) ?> records
            </span>
        </div>

        <form method="get"
              action="/admin/audit_logs.php"
              class="audit-filter-form">

            <input type="hidden" name="page" value="1">

            <div class="audit-filter-field">
                <label for="audit-action">Action</label>

                <select name="action" id="audit-action">
                    <option value="">All Actions</option>

                    <?php foreach ($allActions as $action): ?>
                        <option
                            value="<?= h($action) ?>"
                            <?= $filterAction === $action ? 'selected' : '' ?>>

                            <?= h(auditFormatLabel($action)) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="audit-filter-field">
                <label for="audit-entity">Entity</label>

                <select name="entity_type" id="audit-entity">
                    <option value="">All Entities</option>

                    <?php foreach ($allEntities as $entity): ?>
                        <option
                            value="<?= h($entity) ?>"
                            <?= $filterEntity === $entity ? 'selected' : '' ?>>

                            <?= h(auditFormatLabel($entity)) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="audit-filter-field audit-actor-field">
                <label for="audit-actor">Actor</label>

                <input
                    type="text"
                    name="actor"
                    id="audit-actor"
                    placeholder="Username, employee name or ID"
                    value="<?= h($filterUser) ?>">
            </div>

            <div class="audit-filter-field">
                <label for="audit-from">Date From</label>

                <input
                    type="date"
                    name="date_from"
                    id="audit-from"
                    value="<?= h($filterDateFrom) ?>">
            </div>

            <div class="audit-filter-field">
                <label for="audit-to">Date To</label>

                <input
                    type="date"
                    name="date_to"
                    id="audit-to"
                    value="<?= h($filterDateTo) ?>">
            </div>

            <div class="audit-filter-actions">
                <button type="submit" class="audit-filter-btn">
                    Apply Filters
                </button>

                <a href="/admin/audit_logs.php"
                   class="audit-reset-btn">
                    Reset
                </a>
            </div>

        </form>

    </div>

    <!-- Audit table -->

    <div class="audit-table-card">

        <div class="audit-table-scroll">

            <table class="audit-table">

                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Timestamp</th>
                        <th>Actor</th>
                        <th>Action</th>
                        <th>Entity</th>
                        <th>Record ID</th>
                        <th>Details</th>
                    </tr>
                </thead>

                <tbody>

                    <?php if (empty($logs)): ?>

                        <tr>
                            <td colspan="7">
                                <div class="audit-empty">
                                    <span>▤</span>
                                    <strong>No audit records found</strong>
                                    <p>
                                        Try changing your filters or
                                        resetting the search.
                                    </p>
                                </div>
                            </td>
                        </tr>

                    <?php else: ?>

                        <?php foreach ($logs as $log): ?>

                            <?php
                            $details = [];

                            if (!empty($log['details'])) {
                                $decoded = json_decode(
                                    $log['details'],
                                    true
                                );

                                if (is_array($decoded)) {
                                    $details = $decoded;
                                }
                            }

                            $ip = $details['ip'] ?? null;
                            $userAgent = $details['user_agent'] ?? null;

                            unset(
                                $details['ip'],
                                $details['user_agent']
                            );

                            $detailsPreview = $details
                                ? json_encode(
                                    $details,
                                    JSON_UNESCAPED_UNICODE |
                                    JSON_PRETTY_PRINT
                                )
                                : '';

                            $employeeName = trim(
                                ($log['first_name'] ?? '') . ' ' .
                                ($log['last_name'] ?? '')
                            );

                            $isSystem = $log['actor_user_id'] === null;
                            ?>

                            <tr>

                                <td class="audit-id">
                                    #<?= (int)$log['id'] ?>
                                </td>

                                <td class="audit-timestamp">
                                    <strong>
                                        <?= h(date(
                                            'M d, Y',
                                            strtotime($log['created_at'])
                                        )) ?>
                                    </strong>

                                    <small>
                                        <?= h(date(
                                            'h:i:s A',
                                            strtotime($log['created_at'])
                                        )) ?>
                                    </small>
                                </td>

                                <td>
                                    <div class="audit-actor">

                                        <span class="audit-avatar <?= $isSystem ? 'system' : '' ?>">
                                            <?= $isSystem
                                                ? 'S'
                                                : h(strtoupper(
                                                    substr(
                                                        $employeeName !== ''
                                                            ? $employeeName
                                                            : $log['actor_username'],
                                                        0,
                                                        1
                                                    )
                                                )) ?>
                                        </span>

                                        <div class="audit-actor-info">

                                            <strong>
                                                <?= $isSystem
                                                    ? 'System'
                                                    : h(
                                                        $employeeName !== ''
                                                            ? $employeeName
                                                            : $log['actor_username']
                                                    ) ?>
                                            </strong>

                                            <small>
                                                <?php if (!$isSystem): ?>
                                                    <?= h($log['actor_username']) ?>

                                                    <?php if (!empty($log['actor_role'])): ?>
                                                        · <?= h(auditFormatLabel($log['actor_role'])) ?>
                                                    <?php endif; ?>
                                                <?php else: ?>
                                                    Automated event
                                                <?php endif; ?>
                                            </small>

                                        </div>
                                    </div>
                                </td>

                                <td>
                                    <span class="audit-badge <?= auditBadgeClass($log['action']) ?>">
                                        <?= h(auditFormatLabel($log['action'])) ?>
                                    </span>
                                </td>

                                <td>
                                    <span class="audit-entity">
                                        <?= h(auditFormatLabel($log['entity_type'])) ?>
                                    </span>
                                </td>

                                <td class="audit-record-id">
                                    <?= $log['entity_id'] !== null
                                        ? '#' . (int)$log['entity_id']
                                        : '—' ?>
                                </td>

                                <td class="audit-details-cell">

                                    <?php if ($detailsPreview !== '' || $ip || $userAgent): ?>

                                        <details class="audit-details">

                                            <summary>
                                                View details
                                            </summary>

                                            <div class="audit-details-content">

                                                <?php if ($detailsPreview !== ''): ?>
                                                    <pre><?= h($detailsPreview) ?></pre>
                                                <?php endif; ?>

                                                <?php if ($ip): ?>
                                                    <div class="audit-extra">
                                                        <span>IP Address</span>
                                                        <strong><?= h($ip) ?></strong>
                                                    </div>
                                                <?php endif; ?>

                                                <?php if ($userAgent): ?>
                                                    <div class="audit-extra">
                                                        <span>User Agent</span>
                                                        <p><?= h($userAgent) ?></p>
                                                    </div>
                                                <?php endif; ?>

                                            </div>

                                        </details>

                                    <?php else: ?>

                                        <span class="audit-no-details">—</span>

                                    <?php endif; ?>

                                </td>

                            </tr>

                        <?php endforeach; ?>

                    <?php endif; ?>

                </tbody>

            </table>

        </div>

        <!-- Footer -->

        <div class="audit-table-footer">

            <span>
                Showing
                <strong><?= number_format(count($logs)) ?></strong>
                of
                <strong><?= number_format($totalRows) ?></strong>
                records
            </span>

            <?php if ($totalPages > 1): ?>

                <div class="audit-pagination">

                    <?php if ($page > 1): ?>
                        <a href="<?= h(auditPageUrl($page - 1)) ?>">
                            ← Previous
                        </a>
                    <?php endif; ?>

                    <?php
                    $start = max(1, $page - 2);
                    $end = min($totalPages, $page + 2);
                    ?>

                    <?php if ($start > 1): ?>
                        <a href="<?= h(auditPageUrl(1)) ?>">1</a>

                        <?php if ($start > 2): ?>
                            <span>…</span>
                        <?php endif; ?>
                    <?php endif; ?>

                    <?php for ($i = $start; $i <= $end; $i++): ?>
                        <a
                            href="<?= h(auditPageUrl($i)) ?>"
                            class="<?= $i === $page ? 'active' : '' ?>">

                            <?= $i ?>
                        </a>
                    <?php endfor; ?>

                    <?php if ($end < $totalPages): ?>
                        <?php if ($end < $totalPages - 1): ?>
                            <span>…</span>
                        <?php endif; ?>

                        <a href="<?= h(auditPageUrl($totalPages)) ?>">
                            <?= $totalPages ?>
                        </a>
                    <?php endif; ?>

                    <?php if ($page < $totalPages): ?>
                        <a href="<?= h(auditPageUrl($page + 1)) ?>">
                            Next →
                        </a>
                    <?php endif; ?>

                </div>

            <?php endif; ?>

        </div>

    </div>

</section>

</main>
</body>
</html>
