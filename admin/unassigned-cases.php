<?php
/**
 * Unassigned Case Log (Admin Only)
 * تۆماری کەیسە ئازادەکان
 *
 * Every case a doctor opened for a patient they did not already own, recorded
 * while the `allow_unassigned_patient_cases` switch is ON. Read this before
 * turning the switch OFF: it shows how many patients entered the system that
 * way and which doctor did it.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../classes/Auth.php';
require_once __DIR__ . '/../classes/Database.php';
require_once __DIR__ . '/../classes/Settings.php';
require_once __DIR__ . '/../classes/UnassignedCaseLog.php';

$auth = new Auth();
$auth->requireLogin();
if (!$auth->isAdmin()) {
    header('Location: ' . APP_URL . '/403.php');
    exit;
}

$db = Database::getInstance();

// Filters
$filterDoctor = intval($_GET['doctor_id'] ?? 0);
$filterAction = $_GET['action_type'] ?? '';
$validActions = [
    UnassignedCaseLog::ACTION_CASE_UNASSIGNED,
    UnassignedCaseLog::ACTION_CASE_OTHER_DOCTOR,
];
if (!in_array($filterAction, $validActions, true)) {
    $filterAction = '';
}
$filterFrom = trim($_GET['date_from'] ?? '');
$filterTo   = trim($_GET['date_to'] ?? '');
foreach (['filterFrom', 'filterTo'] as $dateVar) {
    if ($$dateVar !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $$dateVar)) {
        $$dateVar = '';
    }
}

$filters = [
    'doctor_id'   => $filterDoctor ?: null,
    'action_type' => $filterAction ?: null,
    'date_from'   => $filterFrom ?: null,
    'date_to'     => $filterTo ?: null,
];

$page    = max(1, intval($_GET['page'] ?? 1));
$perPage = ITEMS_PER_PAGE;
$total   = UnassignedCaseLog::count($filters);
$totalPages = max(1, (int)ceil($total / $perPage));
$page    = min($page, $totalPages);
$rows    = UnassignedCaseLog::recent($filters, $perPage, ($page - 1) * $perPage);
$byDoctor = UnassignedCaseLog::byDoctor($filters);

$doctors = $db->fetchAll(
    "SELECT id, full_name FROM users WHERE role = 'doctor' ORDER BY full_name"
);

$switchOn = Settings::allowUnassignedPatientCases();

$actionLabels = [
    UnassignedCaseLog::ACTION_CASE_UNASSIGNED   => 'نەخۆشی بێ دکتۆر',
    UnassignedCaseLog::ACTION_CASE_OTHER_DOCTOR => 'نەخۆشی دکتۆرێکی تر',
];
$relationLabels = [
    'unassigned'   => 'هیچ دکتۆرێکی نەبوو',
    'other_doctor' => 'لای دکتۆرێکی تر بوو',
    'referred'     => 'ڕەوانەکراو / خۆی تۆماری کردبوو',
];

/** Preserve the current filters when building a pagination link. */
function ucl_page_url(int $targetPage): string
{
    $query = $_GET;
    $query['page'] = $targetPage;
    return '?' . http_build_query($query);
}

$pageTitle = 'تۆماری کەیسە ئازادەکان';
$breadcrumb = [
    ['title' => 'ڕێکخستنەکان', 'url' => APP_URL . '/admin/settings.php?tab=access'],
    ['title' => 'تۆماری کەیسە ئازادەکان'],
];

require_once __DIR__ . '/../includes/header.php';
?>

<style>
.ucl-banner {
    display: flex; align-items: center; gap: 12px;
    padding: 12px 16px; border-radius: 10px; margin-bottom: 14px;
    font-size: 13px; line-height: 1.8;
}
.ucl-banner.on  { background: #fff8e1; border: 1.5px solid #ffe082; color: #8d6e00; }
.ucl-banner.off { background: #e8f5e9; border: 1.5px solid #c8e6c9; color: #1b5e20; }
.ucl-banner i { font-size: 20px; }
.ucl-banner a { color: inherit; font-weight: 700; }

.ucl-stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 12px; margin-bottom: 14px; }
.ucl-stat { background: #fff; border: 1.5px solid #e3e8ef; border-radius: 10px; padding: 12px 14px; }
.ucl-stat-value { font-size: 20px; font-weight: 700; color: var(--win-accent, #0078d4); }
.ucl-stat-label { font-size: 11.5px; color: #78909c; margin-top: 3px; }

.ucl-filters {
    background: #fff; border: 1.5px solid #e3e8ef; border-radius: 10px;
    padding: 12px 14px; margin-bottom: 14px;
    display: flex; flex-wrap: wrap; gap: 10px; align-items: flex-end;
}
.ucl-filters .fg { display: flex; flex-direction: column; gap: 4px; }
.ucl-filters label { font-size: 11px; font-weight: 600; color: #37474f; }
.ucl-filters select, .ucl-filters input[type="date"] {
    padding: 7px 10px; border: 1.5px solid #e0e0e0; border-radius: 6px;
    font-size: 12.5px; font-family: inherit; background: #fff;
}

.ucl-table-wrap { background: #fff; border: 1.5px solid #e3e8ef; border-radius: 10px; overflow-x: auto; }
.ucl-table { width: 100%; border-collapse: collapse; font-size: 12.5px; }
.ucl-table th {
    background: #f7f9fc; padding: 10px; text-align: right;
    font-weight: 700; color: #37474f; white-space: nowrap;
    border-bottom: 1.5px solid #e3e8ef;
}
.ucl-table td { padding: 10px; border-bottom: 1px solid #f0f0f0; color: #2c3e50; }
.ucl-table tbody tr:hover { background: #f8fbff; }
.ucl-badge {
    display: inline-block; padding: 3px 9px; border-radius: 12px;
    font-size: 11px; font-weight: 700; white-space: nowrap;
}
.ucl-badge.unassigned   { background: #e3f2fd; color: #1565c0; }
.ucl-badge.other_doctor { background: #fff3e0; color: #e65100; }
.ucl-empty { padding: 34px; text-align: center; color: #90a4ae; font-size: 13px; }
.ucl-empty i { font-size: 34px; display: block; margin-bottom: 10px; opacity: .5; }

.ucl-pagination { display: flex; gap: 6px; justify-content: center; margin-top: 14px; flex-wrap: wrap; }
.ucl-pagination a, .ucl-pagination span {
    padding: 7px 12px; border-radius: 6px; border: 1.5px solid #e0e0e0;
    font-size: 12.5px; text-decoration: none; color: #37474f; background: #fff;
}
.ucl-pagination .active { background: var(--win-accent, #0078d4); color: #fff; border-color: var(--win-accent, #0078d4); font-weight: 700; }
</style>

<?php if ($switchOn): ?>
<div class="ucl-banner on">
    <i class="fas fa-unlock-alt"></i>
    <div>
        دەستگەیشتنی نەرم لە ئێستادا <b>چالاکە</b> — دکتۆرەکان دەتوانن کەیس بۆ هەر نەخۆشێک دروست بکەن.
        دوای تەواوبوونی ڕێکخستنی داتا، لە <a href="<?= APP_URL ?>/admin/settings.php?tab=access">ڕێکخستنەکان</a> ناچالاکی بکە.
    </div>
</div>
<?php else: ?>
<div class="ucl-banner off">
    <i class="fas fa-lock"></i>
    <div>
        دەستگەیشتنی نەرم <b>ناچالاکە</b> — دکتۆر تەنها ئەو نەخۆشانە دەبینێت کە بۆی ڕەوانە کراون.
        ئەم تۆمارە مێژووی قۆناغی گواستنەوە پیشان دەدات.
    </div>
</div>
<?php endif; ?>

<div class="ucl-stats">
    <div class="ucl-stat">
        <div class="ucl-stat-value"><?= number_format($total) ?></div>
        <div class="ucl-stat-label">کۆی کەیسی ئازاد</div>
    </div>
    <div class="ucl-stat">
        <div class="ucl-stat-value"><?= number_format(count($byDoctor)) ?></div>
        <div class="ucl-stat-label">ژمارەی دکتۆرەکان</div>
    </div>
    <?php foreach (array_slice($byDoctor, 0, 3) as $d): ?>
    <div class="ucl-stat">
        <div class="ucl-stat-value"><?= number_format((int)$d['total']) ?></div>
        <div class="ucl-stat-label"><?= htmlspecialchars($d['doctor_name'] ?? '—') ?></div>
    </div>
    <?php endforeach; ?>
</div>

<form method="GET" class="ucl-filters">
    <div class="fg">
        <label>دکتۆر</label>
        <select name="doctor_id">
            <option value="">هەموو دکتۆرەکان</option>
            <?php foreach ($doctors as $d): ?>
            <option value="<?= (int)$d['id'] ?>" <?= $filterDoctor === (int)$d['id'] ? 'selected' : '' ?>>
                <?= htmlspecialchars($d['full_name']) ?>
            </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="fg">
        <label>جۆر</label>
        <select name="action_type">
            <option value="">هەموو جۆرەکان</option>
            <?php foreach ($actionLabels as $key => $label): ?>
            <option value="<?= htmlspecialchars($key) ?>" <?= $filterAction === $key ? 'selected' : '' ?>>
                <?= htmlspecialchars($label) ?>
            </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="fg">
        <label>لە بەرواری</label>
        <input type="date" name="date_from" value="<?= htmlspecialchars($filterFrom) ?>">
    </div>
    <div class="fg">
        <label>تا بەرواری</label>
        <input type="date" name="date_to" value="<?= htmlspecialchars($filterTo) ?>">
    </div>
    <button type="submit" class="btn btn-primary"><i class="fas fa-filter"></i> پاڵاوتن</button>
    <a href="<?= APP_URL ?>/admin/unassigned-cases.php" class="btn btn-secondary"><i class="fas fa-times"></i> پاککردنەوە</a>
</form>

<div class="ucl-table-wrap">
    <?php if (empty($rows)): ?>
    <div class="ucl-empty">
        <i class="fas fa-clipboard-check"></i>
        هیچ کەیسێکی ئازاد تۆمار نەکراوە
    </div>
    <?php else: ?>
    <table class="ucl-table">
        <thead>
            <tr>
                <th>بەروار</th>
                <th>دکتۆر</th>
                <th>نەخۆش</th>
                <th>کۆدی نەخۆش</th>
                <th>جۆر</th>
                <th>دۆخی پێشوو</th>
                <th>کەیس</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($rows as $r): ?>
            <tr>
                <td style="white-space:nowrap"><?= htmlspecialchars(date('Y-m-d H:i', strtotime($r['created_at']))) ?></td>
                <td><?= htmlspecialchars($r['doctor_name'] ?? '—') ?></td>
                <td><?= htmlspecialchars($r['patient_name'] ?? '—') ?></td>
                <td style="direction:ltr;text-align:left"><?= htmlspecialchars($r['patient_code'] ?? '—') ?></td>
                <td>
                    <span class="ucl-badge <?= $r['action_type'] === UnassignedCaseLog::ACTION_CASE_OTHER_DOCTOR ? 'other_doctor' : 'unassigned' ?>">
                        <?= htmlspecialchars($actionLabels[$r['action_type']] ?? $r['action_type']) ?>
                    </span>
                </td>
                <td><?= htmlspecialchars($relationLabels[$r['patient_relation']] ?? $r['patient_relation']) ?></td>
                <td>
                    <?php if (!empty($r['case_id'])): ?>
                    <a href="<?= APP_URL ?>/cases/view.php?id=<?= (int)$r['case_id'] ?>">#<?= (int)$r['case_id'] ?></a>
                    <?php else: ?>—<?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>

<?php if ($totalPages > 1): ?>
<div class="ucl-pagination">
    <?php if ($page > 1): ?>
        <a href="<?= htmlspecialchars(ucl_page_url($page - 1)) ?>"><i class="fas fa-chevron-right"></i></a>
    <?php endif; ?>
    <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
        <?php if ($i === $page): ?>
            <span class="active"><?= $i ?></span>
        <?php else: ?>
            <a href="<?= htmlspecialchars(ucl_page_url($i)) ?>"><?= $i ?></a>
        <?php endif; ?>
    <?php endfor; ?>
    <?php if ($page < $totalPages): ?>
        <a href="<?= htmlspecialchars(ucl_page_url($page + 1)) ?>"><i class="fas fa-chevron-left"></i></a>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
