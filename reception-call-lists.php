<?php
/**
 * Reception call-list inbox
 * All appointment batches doctors have sent to the logged-in reception/admin.
 */

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/classes/Database.php';
require_once __DIR__ . '/classes/Auth.php';
require_once __DIR__ . '/classes/Notification.php';

$db = Database::getInstance();
$auth = new Auth();
if (!$auth->isLoggedIn()) {
    header('Location: ' . APP_URL . '/login.php');
    exit;
}

$currentUser = $auth->getCurrentUser();
if (!$auth->isAdmin() && !$auth->isReceptionist()) {
    header('Location: ' . APP_URL . '/403.php');
    exit;
}

$status = $_GET['status'] ?? 'all';
if (!in_array($status, ['all', 'unread', 'read'], true)) {
    $status = 'all';
}
$doctorId = max(0, (int)($_GET['doctor'] ?? 0));
$dateFrom = trim((string)($_GET['from'] ?? ''));
$dateTo = trim((string)($_GET['to'] ?? ''));
$query = trim((string)($_GET['q'] ?? ''));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) {
    $dateFrom = '';
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
    $dateTo = '';
}

$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 40;
$notificationManager = new Notification();
$filters = [
    'status' => $status,
    'doctor_id' => $doctorId,
    'from' => $dateFrom,
    'to' => $dateTo,
    'q' => $query,
];
$result = $notificationManager->getReceptionCallLists(
    (int)$currentUser['id'],
    $filters,
    $perPage,
    ($page - 1) * $perPage
);
$lists = $result['rows'];
$totalRows = (int)$result['total'];
$totalPages = max(1, (int)ceil($totalRows / $perPage));
if ($page > $totalPages && $totalRows > 0) {
    $redirectQuery = $_GET;
    $redirectQuery['page'] = $totalPages;
    header('Location: ' . APP_URL . '/reception-call-lists.php?' . http_build_query($redirectQuery));
    exit;
}

$unreadCount = $notificationManager->getReceptionCallListUnreadCount((int)$currentUser['id']);
$doctors = $db->fetchAll(
    "SELECT DISTINCT u.id, u.full_name
     FROM notifications n
     INNER JOIN users u ON u.id = n.created_by
     WHERE n.user_id = :user_id AND n.type = :type
     ORDER BY u.full_name",
    ['user_id' => (int)$currentUser['id'], 'type' => Notification::TYPE_RECEPTION_LIST]
);

$groupCounts = [];
foreach ($lists as $list) {
    $groupKey = ($list['list_date'] ?? '') . '|' . (int)($list['doctor_id'] ?? 0);
    $groupCounts[$groupKey] = ($groupCounts[$groupKey] ?? 0) + 1;
}

function rcli_page_url($targetPage) {
    $params = $_GET;
    $params['page'] = max(1, (int)$targetPage);
    return APP_URL . '/reception-call-lists.php?' . http_build_query($params);
}

$pageTitle = 'لیستی پەیوەندیکردن';
$breadcrumb = [['title' => 'لیستی پەیوەندیکردن']];
require_once __DIR__ . '/includes/header.php';
?>

<style>
.rcli-page { direction: rtl; }
.rcli-titlebar {
    display: flex; align-items: center; justify-content: space-between; gap: 14px;
    padding: 0 0 12px; margin-bottom: 12px; border-bottom: 2px solid #0f766e;
}
.rcli-titlebar h2 { margin: 0; color: #17324d; font-size: 20px; }
.rcli-titlebar p { margin: 4px 0 0; color: #6b7d90; font-size: 12px; }
.rcli-counts { display: flex; align-items: center; gap: 10px; font-size: 12px; color: #526579; }
.rcli-counts span { border-right: 1px solid #d5dde5; padding-right: 10px; }
.rcli-counts span:first-child { border-right: 0; padding-right: 0; }
.rcli-counts strong { color: #0f172a; font-size: 16px; margin-right: 4px; }
.rcli-counts .unread strong { color: #c2410c; }
.rcli-filter {
    display: grid; grid-template-columns: minmax(180px, 1.5fr) repeat(4, minmax(130px, 1fr)) auto;
    gap: 8px; align-items: end; padding: 12px; margin-bottom: 12px;
    background: #f8fafc; border: 1px solid #d8e1e9; border-radius: 6px;
}
.rcli-field label { display: block; margin-bottom: 5px; color: #4a5d70; font-size: 11px; font-weight: 700; }
.rcli-field input, .rcli-field select {
    width: 100%; height: 36px; border: 1px solid #bdc9d5; border-radius: 4px;
    background: #fff; color: #172b3f; padding: 5px 9px; font: inherit;
}
.rcli-filter-actions { display: flex; gap: 6px; }
.rcli-btn {
    min-height: 36px; display: inline-flex; align-items: center; justify-content: center; gap: 6px;
    padding: 6px 13px; border: 1px solid #0f766e; border-radius: 4px; background: #0f766e;
    color: #fff; text-decoration: none; cursor: pointer; font: inherit; white-space: nowrap;
}
.rcli-btn:hover { background: #115e59; color: #fff; }
.rcli-btn.secondary { background: #fff; border-color: #bdc9d5; color: #516477; }
.rcli-btn.secondary:hover { background: #eef3f7; color: #263b50; }
.rcli-table-wrap { overflow-x: auto; border: 1px solid #cbd5df; border-radius: 5px; background: #fff; }
.rcli-table { width: 100%; min-width: 900px; border-collapse: collapse; }
.rcli-table th, .rcli-table td { padding: 9px 10px; border-left: 1px solid #dbe2e8; border-bottom: 1px solid #e2e8ee; text-align: center; font-size: 12px; }
.rcli-table th:last-child, .rcli-table td:last-child { border-left: 0; }
.rcli-table thead th { background: #eaf1f5; color: #20364b; font-weight: 700; }
.rcli-group td {
    padding: 8px 12px; text-align: right; background: #f0fdfa; color: #0f766e;
    border-top: 1px solid #99d5cb; border-bottom: 1px solid #b8ddd7; font-weight: 700;
}
.rcli-group small { color: #65807c; font-weight: 500; margin-right: 8px; }
.rcli-unread-row td { background: #fffdf7; }
.rcli-status { display: inline-flex; align-items: center; gap: 5px; font-weight: 700; }
.rcli-status.unread { color: #c2410c; }
.rcli-status.read { color: #64748b; }
.rcli-message { max-width: 330px; text-align: right !important; color: #53677a; line-height: 1.55; }
.rcli-open { color: #fff; background: #087f73; border: 1px solid #087f73; border-radius: 4px; padding: 5px 10px; text-decoration: none; display: inline-flex; gap: 5px; align-items: center; }
.rcli-open:hover { color: #fff; background: #06665d; }
.rcli-empty { padding: 55px 15px; text-align: center; color: #708296; }
.rcli-empty i { display: block; margin-bottom: 10px; font-size: 34px; color: #aebbc7; }
.rcli-pagination { display: flex; justify-content: center; align-items: center; gap: 8px; margin-top: 14px; }
.rcli-pagination span { color: #627487; font-size: 12px; }
@media (max-width: 1050px) {
    .rcli-filter { grid-template-columns: repeat(2, minmax(160px, 1fr)); }
    .rcli-filter-actions { grid-column: 1 / -1; }
}
@media (max-width: 650px) {
    .rcli-titlebar { align-items: flex-start; flex-direction: column; }
    .rcli-filter { grid-template-columns: 1fr; }
    .rcli-filter-actions { grid-column: auto; }
}
</style>

<div class="rcli-page">
    <div class="rcli-titlebar">
        <div>
            <h2><i class="fas fa-phone-volume"></i> لیستی پەیوەندیکردن</h2>
            <p>هەموو ئەو لیستانەی دکتۆرەکان ناردوویانە بۆ پەیوەندیکردن بە نەخۆش</p>
        </div>
        <div class="rcli-counts">
            <span>کۆی لیست <strong><?= number_format($totalRows) ?></strong></span>
            <span class="unread">نوێ <strong><?= number_format($unreadCount) ?></strong></span>
        </div>
    </div>

    <form class="rcli-filter" method="GET">
        <div class="rcli-field">
            <label for="rcli-q">گەڕان</label>
            <input id="rcli-q" type="search" name="q" value="<?= htmlspecialchars($query) ?>" placeholder="ناوی دکتۆر یان تێبینی...">
        </div>
        <div class="rcli-field">
            <label for="rcli-doctor">دکتۆر</label>
            <select id="rcli-doctor" name="doctor">
                <option value="0">هەموو دکتۆرەکان</option>
                <?php foreach ($doctors as $doctor): ?>
                <option value="<?= (int)$doctor['id'] ?>" <?= $doctorId === (int)$doctor['id'] ? 'selected' : '' ?>>
                    <?= htmlspecialchars($doctor['full_name']) ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="rcli-field">
            <label for="rcli-status">دۆخی لیست</label>
            <select id="rcli-status" name="status">
                <option value="all" <?= $status === 'all' ? 'selected' : '' ?>>هەموو</option>
                <option value="unread" <?= $status === 'unread' ? 'selected' : '' ?>>نوێ / نەخوێندراوە</option>
                <option value="read" <?= $status === 'read' ? 'selected' : '' ?>>کرایەوە</option>
            </select>
        </div>
        <div class="rcli-field">
            <label for="rcli-from">لە بەرواری</label>
            <input id="rcli-from" type="date" name="from" value="<?= htmlspecialchars($dateFrom) ?>">
        </div>
        <div class="rcli-field">
            <label for="rcli-to">تا بەرواری</label>
            <input id="rcli-to" type="date" name="to" value="<?= htmlspecialchars($dateTo) ?>">
        </div>
        <div class="rcli-filter-actions">
            <button class="rcli-btn" type="submit"><i class="fas fa-search"></i> گەڕان</button>
            <a class="rcli-btn secondary" href="<?= APP_URL ?>/reception-call-lists.php"><i class="fas fa-undo"></i> پاککردنەوە</a>
        </div>
    </form>

    <div class="rcli-table-wrap">
        <?php if (!$lists): ?>
        <div class="rcli-empty">
            <i class="fas fa-inbox"></i>
            <strong>هیچ لیستێکی پەیوەندیکردن نییە</strong>
            <div style="margin-top:5px;">کاتێک دکتۆر لیستێک بنێرێت، لێرە دەردەکەوێت.</div>
        </div>
        <?php else: ?>
        <table class="rcli-table">
            <thead>
                <tr>
                    <th style="width:55px;">ژ</th>
                    <th style="width:110px;">دۆخ</th>
                    <th style="width:120px;">کاتی ناردن</th>
                    <th style="width:90px;">نەخۆش</th>
                    <th>تێبینی دکتۆر</th>
                    <th style="width:105px;">کردار</th>
                </tr>
            </thead>
            <tbody>
            <?php
                $lastGroup = null;
                $rowNumber = ($page - 1) * $perPage;
                foreach ($lists as $list):
                    $rowNumber++;
                    $groupKey = ($list['list_date'] ?? '') . '|' . (int)($list['doctor_id'] ?? 0);
                    if ($groupKey !== $lastGroup):
                        $lastGroup = $groupKey;
            ?>
                <tr class="rcli-group">
                    <td colspan="6">
                        <i class="fas fa-calendar-day"></i>
                        <?= htmlspecialchars($list['list_date'] ?? '-') ?>
                        <span style="margin-right:14px;"><i class="fas fa-user-md"></i> د. <?= htmlspecialchars($list['doctor_name'] ?? '-') ?></span>
                        <small><?= (int)($groupCounts[$groupKey] ?? 1) ?> ناردن</small>
                    </td>
                </tr>
            <?php endif; ?>
                <tr class="<?= empty($list['is_read']) ? 'rcli-unread-row' : '' ?>">
                    <td><?= $rowNumber ?></td>
                    <td>
                        <?php if (empty($list['is_read'])): ?>
                        <span class="rcli-status unread"><i class="fas fa-circle"></i> نوێ</span>
                        <?php else: ?>
                        <span class="rcli-status read"><i class="fas fa-check"></i> کرایەوە</span>
                        <?php endif; ?>
                    </td>
                    <td><?= htmlspecialchars(date('Y-m-d H:i', strtotime($list['created_at']))) ?></td>
                    <td><strong><?= number_format((int)($list['patient_count'] ?? 0)) ?></strong></td>
                    <td class="rcli-message">
                        <?php if (!empty($list['list_note'])): ?>
                            <?= nl2br(htmlspecialchars($list['list_note'])) ?>
                        <?php else: ?>
                            <?= nl2br(htmlspecialchars((string)($list['message'] ?? '-'))) ?>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php
                            $relativeLink = (string)($list['link'] ?? '');
                            $openLink = strpos($relativeLink, 'http') === 0 ? $relativeLink : APP_URL . $relativeLink;
                        ?>
                        <a class="rcli-open" href="<?= htmlspecialchars($openLink) ?>">
                            <i class="fas fa-eye"></i> بینین
                        </a>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>

    <?php if ($totalPages > 1): ?>
    <div class="rcli-pagination">
        <?php if ($page > 1): ?>
        <a class="rcli-btn secondary" href="<?= htmlspecialchars(rcli_page_url($page - 1)) ?>"><i class="fas fa-chevron-right"></i> پێشوو</a>
        <?php endif; ?>
        <span>پەڕەی <?= $page ?> لە <?= $totalPages ?></span>
        <?php if ($page < $totalPages): ?>
        <a class="rcli-btn secondary" href="<?= htmlspecialchars(rcli_page_url($page + 1)) ?>">دواتر <i class="fas fa-chevron-left"></i></a>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
