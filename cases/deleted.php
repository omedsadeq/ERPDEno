<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../classes/Auth.php';
require_once __DIR__ . '/../classes/CaseDeletion.php';
$auth = new Auth();
$auth->requireLogin();
if (!$auth->isAdmin() && !$auth->isDoctor()) { http_response_code(403); exit('ڕێگەت پێنەدراوە'); }
$db = Database::getInstance();
$user = $auth->getCurrentUser();
$patientId = max(0, (int)($_GET['patient_id'] ?? 0));
$archiveId = max(0, (int)($_GET['id'] ?? 0));
$where = '1=1'; $params = [];
if (!$auth->isAdmin()) { $where .= ' AND doctor_id=?'; $params[] = (int)$user['id']; }
if ($patientId) { $where .= ' AND patient_id=?'; $params[] = $patientId; }
$detail = null;
if ($archiveId) {
    $detail = $db->fetch('SELECT * FROM deleted_cases WHERE ' . $where . ' AND id=?', array_merge($params, [$archiveId]));
    if (!$detail) { http_response_code(404); exit('تۆمار نەدۆزرایەوە'); }
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::require();
    if (!$auth->isAdmin()) { http_response_code(403); exit('تەنها بەڕێوەبەر دەتوانێت فایلەکانی کەیس بسڕێتەوە'); }
    if ($detail && ($_POST['action'] ?? '') === 'retry_cleanup') {
        (new CaseDeletion())->cleanupFiles($archiveId);
        header('Location: ' . APP_URL . '/cases/deleted.php?id=' . $archiveId . ($patientId ? '&patient_id=' . $patientId : ''));
        exit;
    }
    http_response_code(400); exit;
}
$page = max(1, (int)($_GET['page'] ?? 1));
$total = (int)$db->fetch('SELECT COUNT(*) AS n FROM deleted_cases WHERE ' . $where, $params)['n'];
$page = min($page, max(1, (int)ceil($total / 25)));
$rows = $detail ? [] : $db->fetchAll('SELECT id, case_number, case_name, patient_id, patient_name, doctor_name,
    deleted_by_name, deletion_reason, deleted_at, cleanup_pending FROM deleted_cases WHERE ' . $where
    . ' ORDER BY deleted_at DESC, id DESC LIMIT 25 OFFSET ' . (($page - 1) * 25), $params);
$pageTitle = 'کەیسە سڕاوەکان';
$labels = [
    'cases' => 'کەیس', 'case_treatments' => 'چارەسەرەکان', 'case_invoices' => 'پسووڵەکان',
    'payments' => 'پارەدانەکان', 'payment_allocations' => 'دابەشکردنی پارەدان',
    'installment_plans' => 'پلانی قیست', 'installment_schedule' => 'خشتەی قیست',
    'installment_payments' => 'پارەدانی قیست', 'accounting_reports' => 'ڕاپۆرتی ژمێریاری',
    'case_images' => 'وێنەی کەیس', 'patient_images' => 'وێنەی نەخۆش', 'dental_chart' => 'نەخشەی ددان',
    'xray_requests' => 'داواکاری ئێکسڕەی', 'xray_images' => 'وێنەی ئێکسڕەی',
    'patient_xray_debts' => 'قەرزی ئێکسڕەی', 'xray_debt_payments' => 'پارەدانی قەرزی ئێکسڕەی',
    'xray_payment_logs' => 'تۆماری پارەدانی ئێکسڕەی', 'xray_request_history' => 'مێژووی ئێکسڕەی',
    'patient_advance_allocations' => 'ئامانەتی بەکارهاتوو', 'patient_debt_payments' => 'پسووڵەی پارەدانی قەرز',
    'arch_procedure_logs' => 'کاری فەک', 'arch_work_receipts' => 'پسووڵەی کاری فەک',
    'prescriptions' => 'ڕەچەتە', 'prescription_items' => 'دەرمانەکان', 'lab_orders' => 'کاری تاقیگە',
    'appointments' => 'مەوعیدەکان', 'notifications' => 'ئاگادارکردنەوەکان',
    'treatment_plan_items' => 'کاری پلانی چارەسەر', 'expenses_lab' => 'خەرجی تاقیگە', 'expenses' => 'خەرجی',
];
$fieldLabels = ['id' => 'ژمارە', 'case_id' => 'ژمارەی کەیس', 'case_number' => 'کۆدی کەیس',
    'case_name' => 'ناوی کەیس', 'amount' => 'بڕ', 'total_amount' => 'کۆی بڕ', 'paid_amount' => 'پارەی دراو',
    'discount_amount' => 'داشکاندن', 'balance' => 'ماوە', 'status' => 'دۆخ', 'notes' => 'تێبینی',
    'created_at' => 'بەرواری دروستکردن', 'payment_date' => 'بەرواری پارەدان', 'treatment_name' => 'ناوی چارەسەر',
    'invoice_no' => 'ژمارەی پسووڵە', 'work_date' => 'بەرواری کار', 'payment_method' => 'شێوازی پارەدان'];
function deletion_escape($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
require_once __DIR__ . '/../includes/header.php';
?>
<div class="page-header">
    <h1 class="page-title"><i class="fas fa-history"></i> <?= deletion_escape($pageTitle) ?></h1>
    <a class="btn btn-secondary" href="<?= APP_URL ?><?= $patientId ? '/patients/view.php?id=' . $patientId . '&tab=folders' : '/cases/index.php' ?>">گەڕانەوە</a>
</div>
<div class="panel"><div class="panel-body">
<?php if ($detail):
    $snapshot = json_decode($detail['snapshot'], true);
    $counts = json_decode($detail['record_counts'], true);
?>
    <h3><?= deletion_escape($detail['case_name'] ?: $detail['case_number']) ?> — <?= deletion_escape($detail['case_number']) ?></h3>
    <p>نەخۆش: <?= deletion_escape($detail['patient_name']) ?> | دکتۆر: <?= deletion_escape($detail['doctor_name']) ?></p>
    <p>سڕایەوە لەلایەن <?= deletion_escape($detail['deleted_by_name']) ?> — <?= deletion_escape($detail['deleted_at']) ?></p>
    <h4>هۆکاری سڕینەوە</h4>
    <p style="white-space:pre-wrap;overflow-wrap:anywhere;"><?= deletion_escape($detail['deletion_reason']) ?></p>
    <?php if ($detail['cleanup_pending']): ?>
    <div class="alert alert-warning">کەیس و تۆمارەکانی سڕاونەتەوە؛ سڕینەوەی هەندێک فایل هێشتا تەواو نەبووە.</div>
    <?php if ($auth->isAdmin()): ?>
    <form method="POST">
        <input type="hidden" name="csrf_token" value="<?= deletion_escape(Csrf::token()) ?>">
        <input type="hidden" name="action" value="retry_cleanup">
        <button class="btn btn-secondary" type="submit">دووبارە هەوڵدان بۆ سڕینەوەی فایلەکان</button>
    </form>
    <?php endif; ?>
    <?php endif; ?>
    <?php if (!empty($snapshot['financial_summary'])): ?>
    <h4>پوختەی دارایی پێش سڕینەوە</h4>
    <p>کۆی بڕ: <?= number_format((float)($snapshot['financial_summary']['approved_amount'] ?? 0)) ?> |
        پارەی دراو: <?= number_format((float)($snapshot['financial_summary']['paid_amount'] ?? 0)) ?> |
        ماوە: <?= number_format((float)($snapshot['financial_summary']['balance'] ?? 0)) ?></p>
    <?php endif; ?>
    <h4>تۆمارە سڕاوەکان (<?= array_sum($counts) ?>)</h4>
    <?php foreach (($snapshot['records'] ?? []) as $table => $records): ?>
    <details style="margin:12px 0;padding:12px;border:1px solid #ddd;border-radius:8px;">
        <summary style="cursor:pointer;font-weight:600;"><?= deletion_escape($labels[$table] ?? $table) ?> (<?= count($records) ?>)</summary>
        <?php foreach ($records as $record): ?>
        <div style="overflow-x:auto;margin-top:12px;"><table class="table"><tbody>
            <?php foreach ($record as $field => $value): ?>
            <tr><th style="width:220px;"><?= deletion_escape($fieldLabels[$field] ?? $field) ?></th><td style="white-space:pre-wrap;overflow-wrap:anywhere;"><?= deletion_escape($value ?? '—') ?></td></tr>
            <?php endforeach; ?>
        </tbody></table></div>
        <?php endforeach; ?>
    </details>
    <?php endforeach; ?>
    <?php foreach (($snapshot['shared_records_before'] ?? []) as $table => $records): ?>
    <details style="margin:12px 0;"><summary><?= deletion_escape($labels[$table] ?? $table) ?> — پێش گۆڕانکاری</summary>
        <div style="overflow-x:auto;"><table class="table"><tbody>
        <?php foreach ($records as $record): foreach ($record as $field => $value): ?>
        <tr><th><?= deletion_escape($fieldLabels[$field] ?? $field) ?></th><td><?= deletion_escape($value ?? '—') ?></td></tr>
        <?php endforeach; endforeach; ?>
        </tbody></table></div>
    </details>
    <?php endforeach; ?>
    <a class="btn btn-secondary" href="<?= APP_URL ?>/cases/deleted.php<?= $patientId ? '?patient_id=' . $patientId : '' ?>">لیستی کەیسە سڕاوەکان</a>
<?php else: ?>
    <p>کۆی تۆمارەکان: <?= $total ?></p>
    <div style="overflow-x:auto;"><table class="table">
        <thead><tr><th>کەیس</th><th>نەخۆش</th><th>دکتۆر</th><th>سڕایەوە لەلایەن</th><th>بەروار</th><th>هۆکار</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($rows as $row): ?>
        <tr>
            <td><?= deletion_escape($row['case_number']) ?><br><?= deletion_escape($row['case_name']) ?></td>
            <td><?= deletion_escape($row['patient_name']) ?></td><td><?= deletion_escape($row['doctor_name']) ?></td>
            <td><?= deletion_escape($row['deleted_by_name']) ?></td><td><?= deletion_escape($row['deleted_at']) ?></td>
            <td style="max-width:300px;overflow-wrap:anywhere;"><?= deletion_escape($row['deletion_reason']) ?></td>
            <td><a class="btn btn-secondary" href="<?= APP_URL ?>/cases/deleted.php?id=<?= (int)$row['id'] ?><?= $patientId ? '&patient_id=' . $patientId : '' ?>">وردەکاری</a>
                <?php if ($row['cleanup_pending']): ?><span class="badge badge-warning">فایلەکان</span><?php endif; ?></td>
        </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?><tr><td colspan="7">هیچ کەیسێکی سڕاوە نییە</td></tr><?php endif; ?>
        </tbody>
    </table></div>
    <?php if ($page > 1): ?><a class="btn btn-secondary" href="?page=<?= $page - 1 ?>&patient_id=<?= $patientId ?>">پێشوو</a><?php endif; ?>
    <?php if ($page * 25 < $total): ?><a class="btn btn-secondary" href="?page=<?= $page + 1 ?>&patient_id=<?= $patientId ?>">دواتر</a><?php endif; ?>
<?php endif; ?>
</div></div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
