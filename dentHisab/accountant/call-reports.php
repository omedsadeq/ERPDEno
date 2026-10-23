<?php
/**
 * Call Reports — list of every debt-collection call made + statistics
 * ڕاپۆرتی پەیوەندییەکان
 */

require_once __DIR__ . '/../config/config.php';

$pageTitle = 'ڕاپۆرتی پەیوەندییەکان';
$breadcrumb = [
    ['title' => 'کۆکردنەوەی قەرز', 'url' => APP_URL . '/accountant/debt-collection.php'],
    ['title' => 'ڕاپۆرتی پەیوەندییەکان']
];

// Role gate BEFORE header output, so the 403 redirect actually works
require_once __DIR__ . '/../classes/Auth.php';
$__gate = new Auth();
$__gate->requireLogin();
if (!$__gate->isAccountant() && !$__gate->isAdmin() && !$__gate->isDoctor()) {
    header('Location: ' . APP_URL . '/403.php');
    exit;
}

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../classes/DebtCollection.php';

$debtCollection = new DebtCollection();

// GET params can arrive as arrays (?search[]=) — coerce to strings so trim()
// and htmlspecialchars() don't fatal on PHP 8
$getStr = function ($key, $default = '') {
    $v = $_GET[$key] ?? $default;
    return is_string($v) ? $v : $default;
};

// Filters (default: this month)
$dateFrom = $getStr('date_from', date('Y-m-01'));
$dateTo = $getStr('date_to', date('Y-m-d'));
$filterUser = intval($_GET['user_id'] ?? 0);
$filterStatus = $getStr('status');
$search = trim($getStr('search'));
$page = max(1, intval($_GET['page'] ?? 1));

// Stats take the SAME filters as the list — otherwise the cards show
// clinic-wide numbers while the table shows the filtered subset
$report = $debtCollection->getCallReport($dateFrom, $dateTo, $filterUser, $filterStatus, $search);
$logs = $debtCollection->getCallLogs($dateFrom, $dateTo, $filterUser, $filterStatus, $search, $page);
$callStatusLabels = DebtCollection::getCallStatusLabels();
$callStatusColors = DebtCollection::getCallStatusColors();

$callers = $db->fetchAll("
    SELECT DISTINCT u.id, u.full_name
    FROM call_logs cl
    INNER JOIN users u ON u.id = cl.called_by
    ORDER BY u.full_name
");

$totalPages = max(1, (int)ceil($logs['total'] / $logs['per_page']));
$buildUrl = function ($extra = []) {
    $params = array_merge($_GET, $extra);
    return APP_URL . '/accountant/call-reports.php?' . http_build_query($params);
};
?>

<style>
.cr-container { max-width: 1400px; margin: 0 auto; }
.cr-toolbar {
    display: flex; gap: 8px; margin-bottom: 8px; flex-wrap: wrap; align-items: center;
    background: #fff; padding: 8px 12px; border-radius: 12px;
    box-shadow: 0 1px 4px rgba(0,0,0,0.06);
}
.cr-toolbar select, .cr-toolbar input {
    padding: 7px 10px; border: 1px solid #e0e0e0; border-radius: 8px;
    font-size: 12.5px; background: #fff;
}
.cr-toolbar .btn {
    padding: 8px 16px; border-radius: 8px; font-size: 12.5px; font-weight: 700;
    border: none; cursor: pointer; display: inline-flex; align-items: center; gap: 6px;
}
.cr-btn-filter { background: #0078d4; color: #fff; }
.cr-btn-print { background: #f0f0f0; color: #333; }
.cr-quick { display: inline-flex; gap: 5px; }
.cr-quick a {
    padding: 6px 11px; border-radius: 999px; font-size: 11.5px; font-weight: 700;
    border: 1px solid #e0e0e0; color: #444; background: #f8f9fa; text-decoration: none;
}
.cr-quick a.active { background: #e3f2fd; border-color: #0078d4; color: #005a9e; }

/* Stats */
.cr-stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(130px, 1fr)); gap: 8px; margin-bottom: 8px; }
.cr-stat {
    background: #fff; border-radius: 10px; padding: 10px 14px;
    box-shadow: 0 1px 4px rgba(0,0,0,0.06); border-right: 4px solid #0078d4;
}
.cr-stat-value { font-size: 17px; font-weight: 800; color: #1a1a2e; }
.cr-stat-label { font-size: 11px; color: #777; margin-top: 2px; }
.cr-stat.green { border-color: #27ae60; } .cr-stat.green .cr-stat-value { color: #27ae60; }
.cr-stat.red { border-color: #e74c3c; } .cr-stat.red .cr-stat-value { color: #e74c3c; }
.cr-stat.orange { border-color: #f39c12; } .cr-stat.orange .cr-stat-value { color: #f39c12; }
.cr-stat.blue { border-color: #3498db; } .cr-stat.blue .cr-stat-value { color: #3498db; }

/* Panels */
.cr-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; margin-bottom: 8px; }
@media (max-width: 900px) { .cr-grid { grid-template-columns: 1fr; } }
.cr-panel {
    background: #fff; border-radius: 12px; overflow: hidden;
    box-shadow: 0 1px 4px rgba(0,0,0,0.06);
}
.cr-panel-title {
    padding: 9px 14px; font-size: 12.5px; font-weight: 800; color: #444;
    background: #f8f9fa; border-bottom: 1px solid #eee;
    display: flex; align-items: center; gap: 7px;
}
.cr-table { width: 100%; border-collapse: collapse; font-size: 12.5px; }
.cr-table th {
    padding: 8px 12px; font-weight: 600; color: #555; text-align: right;
    background: #fcfcfd; border-bottom: 1px solid #eee; font-size: 11.5px; white-space: nowrap;
}
.cr-table td { padding: 8px 12px; border-bottom: 1px solid #f4f4f4; vertical-align: middle; }
.cr-table tbody tr:hover { background: #f8faff; }
.cr-badge {
    display: inline-block; padding: 3px 10px; border-radius: 20px;
    font-size: 11px; font-weight: 700; color: #fff; white-space: nowrap;
}
.cr-note { max-width: 260px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; color: #666; font-size: 12px; }
.cr-status-chips { display: flex; flex-wrap: wrap; gap: 6px; padding: 12px 14px; }
.cr-status-chip {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 5px 12px; border-radius: 999px; font-size: 12px; font-weight: 700; color: #fff;
}
.cr-pagination { display: flex; gap: 5px; justify-content: center; padding: 12px; flex-wrap: wrap; }
.cr-pagination a, .cr-pagination span {
    padding: 6px 12px; border-radius: 8px; font-size: 12.5px; text-decoration: none;
    border: 1px solid #e0e0e0; color: #444; background: #fff;
}
.cr-pagination .current { background: #0078d4; color: #fff; border-color: #0078d4; }
.cr-empty { text-align: center; padding: 34px; color: #999; }

@media print {
    .cr-toolbar, .nav-pane, .titlebar, .cr-pagination { display: none !important; }
}
</style>

<div class="cr-container">
    <!-- Filters -->
    <form method="GET" class="cr-toolbar">
        <span class="cr-quick">
            <?php
            // Iraqi work week starts on Saturday
            $weekStart = date('N') == 6 ? date('Y-m-d') : date('Y-m-d', strtotime('last saturday'));
            $isToday = $dateFrom === date('Y-m-d') && $dateTo === date('Y-m-d');
            $isWeek = $dateFrom === $weekStart && $dateTo === date('Y-m-d') && !$isToday;
            $isMonth = $dateFrom === date('Y-m-01') && $dateTo === date('Y-m-d');
            // $buildUrl keeps the active employee/status/search filters on the chips
            ?>
            <a class="<?= $isToday ? 'active' : '' ?>" href="<?= $buildUrl(['date_from' => date('Y-m-d'), 'date_to' => date('Y-m-d'), 'page' => 1]) ?>">ئەمڕۆ</a>
            <a class="<?= $isWeek ? 'active' : '' ?>" href="<?= $buildUrl(['date_from' => $weekStart, 'date_to' => date('Y-m-d'), 'page' => 1]) ?>">ئەم هەفتەیە</a>
            <a class="<?= $isMonth ? 'active' : '' ?>" href="<?= $buildUrl(['date_from' => date('Y-m-01'), 'date_to' => date('Y-m-d'), 'page' => 1]) ?>">ئەم مانگە</a>
        </span>
        <input type="date" name="date_from" value="<?= htmlspecialchars($dateFrom) ?>">
        <input type="date" name="date_to" value="<?= htmlspecialchars($dateTo) ?>">
        <select name="user_id">
            <option value="0">هەموو کارمەندەکان</option>
            <?php foreach ($callers as $caller): ?>
            <option value="<?= (int)$caller['id'] ?>" <?= $filterUser === (int)$caller['id'] ? 'selected' : '' ?>><?= htmlspecialchars($caller['full_name']) ?></option>
            <?php endforeach; ?>
        </select>
        <select name="status">
            <option value="">هەموو دۆخەکان</option>
            <?php foreach ($callStatusLabels as $key => $label): ?>
            <option value="<?= $key ?>" <?= $filterStatus === $key ? 'selected' : '' ?>><?= $label ?></option>
            <?php endforeach; ?>
        </select>
        <input type="text" name="search" placeholder="گەڕان بە ناو، ژمارە، کۆد..." value="<?= htmlspecialchars($search) ?>" style="min-width:170px;">
        <button type="submit" class="btn cr-btn-filter"><i class="fas fa-filter"></i> فلتەر</button>
        <button type="button" class="btn cr-btn-print" onclick="window.print()"><i class="fas fa-print"></i> چاپکردن</button>
    </form>

    <!-- Statistics -->
    <div class="cr-stats">
        <div class="cr-stat">
            <div class="cr-stat-value"><?= number_format((int)($report['totals']['calls'] ?? 0)) ?></div>
            <div class="cr-stat-label"><i class="fas fa-phone-alt"></i> کۆی پەیوەندی</div>
        </div>
        <div class="cr-stat blue">
            <div class="cr-stat-value"><?= number_format((int)($report['totals']['patients'] ?? 0)) ?></div>
            <div class="cr-stat-label"><i class="fas fa-users"></i> نەخۆشی جیاواز</div>
        </div>
        <div class="cr-stat green">
            <div class="cr-stat-value"><?= number_format((int)($report['totals']['answered'] ?? 0)) ?></div>
            <div class="cr-stat-label"><i class="fas fa-check"></i> وەڵامدراوە</div>
        </div>
        <div class="cr-stat red">
            <div class="cr-stat-value"><?= number_format((int)($report['totals']['no_answer'] ?? 0)) ?></div>
            <div class="cr-stat-label"><i class="fas fa-phone-slash"></i> وەڵام نەدراوە</div>
        </div>
        <div class="cr-stat orange">
            <div class="cr-stat-value"><?= number_format((int)($report['totals']['promises'] ?? 0)) ?></div>
            <div class="cr-stat-label"><i class="fas fa-handshake"></i> بەڵێنی پارەدان</div>
        </div>
        <div class="cr-stat orange">
            <div class="cr-stat-value"><?= number_format((float)($report['totals']['promised_amount'] ?? 0)) ?></div>
            <div class="cr-stat-label"><i class="fas fa-hand-holding-usd"></i> بڕی بەڵێندراو</div>
        </div>
        <div class="cr-stat green">
            <div class="cr-stat-value"><?= number_format((int)($report['totals']['paid'] ?? 0)) ?></div>
            <div class="cr-stat-label"><i class="fas fa-coins"></i> پارەی دا</div>
        </div>
        <div class="cr-stat blue">
            <div class="cr-stat-value"><?= number_format((int)($report['totals']['scheduled'] ?? 0)) ?></div>
            <div class="cr-stat-label"><i class="fas fa-calendar-check"></i> سەردانی دیاریکرد</div>
        </div>
    </div>

    <div class="cr-grid">
        <!-- Per employee -->
        <div class="cr-panel">
            <div class="cr-panel-title"><i class="fas fa-user-friends"></i> بە پێی کارمەند</div>
            <?php if (empty($report['by_user'])): ?>
            <div class="cr-empty">هیچ پەیوەندییەک لەم ماوەیەدا نییە</div>
            <?php else: ?>
            <table class="cr-table">
                <thead>
                    <tr>
                        <th>کارمەند</th>
                        <th>پەیوەندی</th>
                        <th>وەڵام</th>
                        <th>بەڵێن</th>
                        <th>بڕی بەڵێن</th>
                        <th>پارەدان</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($report['by_user'] as $u): ?>
                    <?php $answerRate = (int)$u['calls'] > 0 ? round(((int)$u['answered'] / (int)$u['calls']) * 100) : 0; ?>
                    <tr style="cursor:pointer;" onclick="window.location='<?= $buildUrl(['user_id' => (int)$u['called_by'], 'page' => 1]) ?>'" title="فلتەر بەم کارمەندە">
                        <td><strong><?= htmlspecialchars($u['full_name'] ?? '—') ?></strong></td>
                        <td><?= number_format((int)$u['calls']) ?></td>
                        <td style="color:#27ae60;font-weight:700;"><?= number_format((int)$u['answered']) ?> <span style="color:#aaa;font-weight:400;font-size:11px;">(<?= $answerRate ?>%)</span></td>
                        <td style="color:#f39c12;font-weight:700;"><?= number_format((int)$u['promises']) ?></td>
                        <td><?= number_format((float)$u['promised_amount']) ?></td>
                        <td style="color:#27ae60;font-weight:700;"><?= number_format((int)$u['paid']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>

        <!-- Per status -->
        <div class="cr-panel">
            <div class="cr-panel-title"><i class="fas fa-chart-pie"></i> بە پێی ئەنجامی پەیوەندی</div>
            <?php if (empty($report['by_status'])): ?>
            <div class="cr-empty">هیچ پەیوەندییەک لەم ماوەیەدا نییە</div>
            <?php else: ?>
            <div class="cr-status-chips">
                <?php foreach ($report['by_status'] as $s): ?>
                <a class="cr-status-chip" style="background:<?= $callStatusColors[$s['call_status']] ?? '#999' ?>;text-decoration:none;"
                   href="<?= $buildUrl(['status' => $s['call_status'], 'page' => 1]) ?>" title="فلتەر بەم ئەنجامە">
                    <?= $callStatusLabels[$s['call_status']] ?? htmlspecialchars($s['call_status']) ?>
                    <strong><?= number_format((int)$s['c']) ?></strong>
                </a>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Call list -->
    <div class="cr-panel">
        <div class="cr-panel-title">
            <i class="fas fa-list"></i> لیستی پەیوەندییەکان
            <span style="margin-right:auto;font-weight:400;color:#888;"><?= number_format($logs['total']) ?> پەیوەندی</span>
        </div>
        <?php if (empty($logs['rows'])): ?>
        <div class="cr-empty"><i class="fas fa-phone-slash" style="font-size:22px;display:block;margin-bottom:8px;"></i> هیچ پەیوەندییەک نەدۆزرایەوە</div>
        <?php else: ?>
        <div style="overflow-x:auto;">
            <table class="cr-table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>بەروار و کات</th>
                        <th>نەخۆش</th>
                        <th>ژمارە</th>
                        <th>کارمەند</th>
                        <th>ئەنجام</th>
                        <th>بەڵێن</th>
                        <th>پەیگیری</th>
                        <th>تێبینی</th>
                    </tr>
                </thead>
                <tbody>
                    <?php $rowNum = ($logs['page'] - 1) * $logs['per_page'] + 1; ?>
                    <?php foreach ($logs['rows'] as $log): ?>
                    <tr>
                        <td><?= $rowNum++ ?></td>
                        <td style="white-space:nowrap;">
                            <?= date('Y/m/d', strtotime($log['call_date'])) ?>
                            <span style="color:#999;font-size:11px;"><?= date('H:i', strtotime($log['call_date'])) ?></span>
                        </td>
                        <td>
                            <a href="<?= APP_URL ?>/patients/view.php?id=<?= (int)$log['patient_id'] ?>" style="color:#1a1a2e;text-decoration:none;font-weight:600;">
                                <?= htmlspecialchars($log['patient_name'] ?? '—') ?>
                            </a>
                            <?php if (!empty($log['patient_code'])): ?>
                            <div style="font-size:10.5px;color:#999;"><?= htmlspecialchars($log['patient_code']) ?></div>
                            <?php endif; ?>
                        </td>
                        <td style="direction:ltr;text-align:right;font-size:12px;"><?= htmlspecialchars($log['patient_phone'] ?? '') ?></td>
                        <td><?= htmlspecialchars($log['called_by_name'] ?? '—') ?></td>
                        <td>
                            <span class="cr-badge" style="background:<?= $callStatusColors[$log['call_status']] ?? '#999' ?>;">
                                <?= $callStatusLabels[$log['call_status']] ?? $log['call_status'] ?>
                            </span>
                        </td>
                        <td style="white-space:nowrap;">
                            <?php // a promise may have an amount without a date — still show it,
                                  // it is counted in the "بڕی بەڵێندراو" stat ?>
                            <?php if (!empty($log['promised_date']) || (float)($log['promised_amount'] ?? 0) > 0): ?>
                            <span style="color:#f39c12;font-weight:700;"><?= number_format((float)($log['promised_amount'] ?? 0)) ?></span>
                            <?php if (!empty($log['promised_date'])): ?>
                            <div style="font-size:10.5px;color:#999;"><?= htmlspecialchars(substr($log['promised_date'], 0, 10)) ?></div>
                            <?php else: ?>
                            <div style="font-size:10.5px;color:#ccc;">بێ بەروار</div>
                            <?php endif; ?>
                            <?php else: ?>
                            <span style="color:#ccc;">—</span>
                            <?php endif; ?>
                        </td>
                        <td style="white-space:nowrap;font-size:12px;">
                            <?= !empty($log['next_follow_up']) ? htmlspecialchars(substr($log['next_follow_up'], 0, 10)) : '<span style="color:#ccc;">—</span>' ?>
                        </td>
                        <td><div class="cr-note" title="<?= htmlspecialchars($log['report_note'] ?? '') ?>"><?= htmlspecialchars($log['report_note'] ?? '') ?: '—' ?></div></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php if ($totalPages > 1): ?>
        <div class="cr-pagination">
            <?php for ($p = 1; $p <= $totalPages; $p++): ?>
                <?php if ($p === $logs['page']): ?>
                <span class="current"><?= $p ?></span>
                <?php elseif ($p <= 2 || $p > $totalPages - 2 || abs($p - $logs['page']) <= 2): ?>
                <a href="<?= $buildUrl(['page' => $p]) ?>"><?= $p ?></a>
                <?php elseif (abs($p - $logs['page']) === 3): ?>
                <span>…</span>
                <?php endif; ?>
            <?php endfor; ?>
        </div>
        <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
