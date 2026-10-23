<?php
/**
 * وەسڵەکانی مەواد — every hand-over of materials to a doctor.
 *
 * Filter by date, doctor, kind (ئاسایی / ئیمپلانت) and status. The totals and
 * the per-doctor table count confirmed vouchers only — those are exactly the
 * amounts sitting in خەرجی کۆگا. Cancelling (erp.doctor_issue.cancel) returns
 * the stock and removes the expense; the voucher stays as history.
 */

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../classes/ErpDoctorIssue.php';
erp_require('erp.doctor_issue.view');

$pageTitle  = 'وەسڵەکانی مەواد بۆ دکتۆر';
$breadcrumb = [
    ['title' => 'کۆگا', 'url' => APP_URL . '/erp/index.php'],
    ['title' => 'وەسڵەکانی مەواد'],
];

$filters = [
    'from'      => $_GET['from'] ?? date('Y-m-01'),
    'to'        => $_GET['to'] ?? date('Y-m-d'),
    'doctor_id' => (int)($_GET['doctor'] ?? 0),
    'kind'      => (string)($_GET['kind'] ?? ''),
    'status'    => (string)($_GET['status'] ?? ''),
];
$data      = ErpDoctorIssue::search($filters);
$doctors   = ErpDoctorIssue::doctors();
$canCreate = erp_can('erp.doctor_issue.create');
$canCancel = erp_can('erp.doctor_issue.cancel');
$canExpenses = Permission::getInstance()->can('page.expenses_inventory');
$flash = $_SESSION['erp_flash'] ?? null;
unset($_SESSION['erp_flash']);

$expenseUrl = APP_URL . '/admin/expenses-inventory.php?date_from=' . urlencode($data['from']) . '&date_to=' . urlencode($data['to'])
    . ($filters['kind'] ? '&kind=' . urlencode($filters['kind']) : '')
    . ($filters['doctor_id'] ? '&doctor=' . (int)$filters['doctor_id'] : '');

require_once __DIR__ . '/../includes/header.php';
?>

<?php if ($flash): ?>
<div class="alert alert-info" data-auto-hide><?= erp_esc($flash) ?></div>
<?php endif; ?>

<div class="dil-cards">
    <div class="dil-card is-total">
        <span class="dil-card-icon"><i class="fas fa-hand-holding-medical"></i></span>
        <div><small>کۆی مەواد بۆ دکتۆران</small><b><?= erp_money($data['normal'] + $data['implant']) ?></b><em><?= (int)$data['count'] ?> وەسڵ</em></div>
    </div>
    <div class="dil-card is-normal">
        <span class="dil-card-icon"><i class="fas fa-box-open"></i></span>
        <div><small>ئاسایی</small><b><?= erp_money($data['normal']) ?></b></div>
    </div>
    <div class="dil-card is-implant">
        <span class="dil-card-icon"><i class="fas fa-tooth"></i></span>
        <div><small>ئیمپلانت</small><b><?= erp_money($data['implant']) ?></b></div>
    </div>
    <?php if ($canCreate): ?>
    <a class="dil-new" href="<?= APP_URL ?>/erp/doctor-issue.php"><i class="fas fa-plus"></i> پێدانی مەوادی نوێ</a>
    <?php endif; ?>
</div>

<form class="dil-filters" method="get">
    <label><span>لە</span><input type="date" name="from" value="<?= erp_esc($data['from']) ?>"></label>
    <label><span>بۆ</span><input type="date" name="to" value="<?= erp_esc($data['to']) ?>"></label>
    <label><span>دکتۆر</span>
        <select name="doctor">
            <option value="">هەموو</option>
            <?php foreach ($doctors as $d): ?>
            <option value="<?= (int)$d['id'] ?>" <?= (int)$d['id'] === $filters['doctor_id'] ? 'selected' : '' ?>><?= erp_esc($d['full_name']) ?></option>
            <?php endforeach; ?>
        </select>
    </label>
    <div class="dil-seg" role="radiogroup" aria-label="جۆر">
        <?php foreach (['' => 'هەموو', 'normal' => 'ئاسایی', 'implant' => 'ئیمپلانت'] as $k => $label): ?>
        <label class="dil-seg-opt is-<?= $k ?: 'all' ?>"><input type="radio" name="kind" value="<?= $k ?>" <?= $filters['kind'] === $k ? 'checked' : '' ?> onchange="this.form.submit()"><span><?= $label ?></span></label>
        <?php endforeach; ?>
    </div>
    <label><span>دۆخ</span>
        <select name="status">
            <option value="">هەموو</option>
            <option value="confirmed" <?= $filters['status'] === 'confirmed' ? 'selected' : '' ?>>پەسەندکراو</option>
            <option value="cancelled" <?= $filters['status'] === 'cancelled' ? 'selected' : '' ?>>هەڵوەشێنراوە</option>
        </select>
    </label>
    <button type="submit" class="btn btn-primary"><i class="fas fa-filter"></i> پاڵاوتن</button>
    <?php if ($canExpenses): ?>
    <a class="btn btn-subtle" href="<?= erp_esc($expenseUrl) ?>"><i class="fas fa-receipt"></i> لە خەرجی کۆگا</a>
    <?php endif; ?>
</form>

<div class="dil-layout">
    <div class="dil-panel">
        <?php if (!$data['rows']): ?>
        <div class="dil-empty"><i class="fas fa-box-open"></i><p>هیچ وەسڵێک لەم ماوەیەدا نییە.</p></div>
        <?php else: ?>
        <div class="dil-scroll">
            <table class="dil-table">
                <thead>
                    <tr><th>ژمارە</th><th>بەروار</th><th>دکتۆر</th><th>جۆر</th><th>کۆگا</th><th class="n">مەواد</th><th class="n">کۆی گشتی</th><th>دۆخ</th><th></th></tr>
                </thead>
                <tbody>
                    <?php foreach ($data['rows'] as $r): $cancelled = $r['status'] === 'cancelled'; ?>
                    <tr class="<?= $cancelled ? 'is-cancelled' : '' ?>">
                        <td><b dir="ltr"><?= erp_esc($r['issue_number']) ?></b></td>
                        <td><span dir="ltr"><?= erp_esc($r['issue_date']) ?></span></td>
                        <td><?= erp_esc($r['doctor_name'] ?? '—') ?></td>
                        <td><span class="dil-kind is-<?= erp_esc($r['expense_kind']) ?>"><?= erp_esc(ErpDoctorIssue::kindLabel($r['expense_kind'])) ?></span></td>
                        <td><?= erp_esc($r['warehouse_name']) ?></td>
                        <td class="n"><?= (int)$r['line_count'] ?></td>
                        <td class="n"><b><?= erp_money($r['total_amount']) ?></b><small class="dil-mode"><?= erp_esc(ErpDoctorIssue::priceModeLabel($r['price_mode'])) ?></small></td>
                        <td>
                            <?php if ($cancelled): ?>
                            <span class="dil-status is-cancelled" title="<?= erp_esc($r['cancel_reason'] ?? '') ?>">هەڵوەشێنراوە</span>
                            <?php else: ?>
                            <span class="dil-status">پەسەندکراو</span>
                            <?php endif; ?>
                        </td>
                        <td class="dil-actions">
                            <a class="dil-btn" href="<?= APP_URL ?>/erp/print-doctor-issue.php?id=<?= (int)$r['id'] ?>&autoprint=0" target="_blank" title="بینین و چاپ"><i class="fas fa-print"></i></a>
                            <?php if ($canCancel && !$cancelled): ?>
                            <button type="button" class="dil-btn is-danger" title="هەڵوەشاندنەوە"
                                    onclick="dilCancel(<?= (int)$r['id'] ?>, '<?= erp_esc($r['issue_number']) ?>')"><i class="fas fa-ban"></i></button>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>

    <aside class="dil-panel dil-side">
        <h4><i class="fas fa-user-doctor"></i> بەپێی دکتۆر</h4>
        <?php if (!$data['by_doctor']): ?>
        <p class="dil-muted">هیچ داتایەک نییە.</p>
        <?php else: ?>
        <table class="dil-mini">
            <thead><tr><th>دکتۆر</th><th class="n">ئاسایی</th><th class="n">ئیمپلانت</th></tr></thead>
            <tbody>
                <?php foreach ($data['by_doctor'] as $d): ?>
                <tr>
                    <td><a href="?<?= erp_esc(http_build_query(array_merge($_GET, ['doctor' => (int)$d['doctor_id']]))) ?>"><?= erp_esc($d['doctor_name'] ?? '—') ?></a></td>
                    <td class="n"><?= erp_money($d['normal']) ?></td>
                    <td class="n"><?= erp_money($d['implant']) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <p class="dil-muted">ئەم بڕانە لە خەرجی کۆگان و لە پڕۆفایلی هەر دکتۆرێک وەک «خەرجی کۆگا» دەردەکەون.</p>
        <?php endif; ?>
    </aside>
</div>

<?php if ($canCancel): ?>
<div class="dialog-overlay" id="dilCancelDialog">
    <div class="dialog" style="max-width: 440px;">
        <div class="dialog-header">
            <h4 class="dialog-title"><i class="fas fa-ban"></i> هەڵوەشاندنەوەی وەسڵ <span id="dilCancelNo" dir="ltr"></span></h4>
            <button type="button" class="dialog-close" onclick="closeDialog('dilCancelDialog')"><i class="fas fa-times"></i></button>
        </div>
        <div class="dialog-body">
            <p style="margin:0 0 10px;font-size:13px;line-height:1.7">مەوادەکان دەگەڕێنەوە بۆ کۆگا و بڕەکەی لە خەرجی کۆگا لادەبرێت. وەسڵەکە وەک «هەڵوەشێنراوە» دەمێنێتەوە.</p>
            <label class="form-label" for="dilCancelReason">هۆکار</label>
            <input id="dilCancelReason" class="form-control" maxlength="255" placeholder="ئارەزوومەندانە">
            <div id="dilCancelMsg" style="min-height:18px;margin-top:6px;color:#dc2626;font-weight:700;font-size:12.5px"></div>
        </div>
        <div class="dialog-footer">
            <button type="button" class="btn btn-secondary" onclick="closeDialog('dilCancelDialog')">داخستن</button>
            <button type="button" class="btn btn-danger" id="dilCancelGo"><i class="fas fa-ban"></i> هەڵوەشاندنەوە</button>
        </div>
    </div>
</div>
<script>
(function () {
    let current = 0;
    window.dilCancel = function (id, number) {
        current = id;
        document.getElementById('dilCancelNo').textContent = number;
        document.getElementById('dilCancelReason').value = '';
        document.getElementById('dilCancelMsg').textContent = '';
        openDialog('dilCancelDialog');
        setTimeout(() => document.getElementById('dilCancelReason').focus(), 50);
    };
    document.getElementById('dilCancelGo').addEventListener('click', async function () {
        const btn = this;
        btn.disabled = true;
        try {
            const fd = new FormData();
            fd.append('action', 'cancel');
            fd.append('id', current);
            fd.append('reason', document.getElementById('dilCancelReason').value.trim());
            const r = await fetch('<?= APP_URL ?>/erp/api/doctor-issues.php?action=cancel', { method: 'POST', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest' } });
            const d = await r.json();
            if (!d || !d.ok) { document.getElementById('dilCancelMsg').textContent = (d && d.error) || 'هەڵە'; return; }
            location.reload();
        } catch (e) {
            document.getElementById('dilCancelMsg').textContent = 'کێشەی پەیوەندی ڕوویدا';
        } finally { btn.disabled = false; }
    });
})();
</script>
<?php endif; ?>

<style>
.dil-cards { display: flex; gap: 12px; flex-wrap: wrap; align-items: stretch; margin-bottom: 14px; }
.dil-card { flex: 1 1 200px; display: flex; align-items: center; gap: 12px; padding: 14px 16px; background: #fff; border: 1px solid #e6ebf1;
    border-radius: 14px; border-inline-start: 5px solid var(--c); }
.dil-card.is-total { --c: #0369a1; }
.dil-card.is-normal { --c: #0d9488; }
.dil-card.is-implant { --c: #7c3aed; }
.dil-card-icon { width: 42px; height: 42px; border-radius: 12px; display: grid; place-items: center; background: var(--c); color: #fff; font-size: 18px; }
.dil-card small { display: block; font-size: 12px; color: #64748b; }
.dil-card b { display: block; font-size: 21px; color: #0f172a; direction: ltr; text-align: start; font-variant-numeric: tabular-nums; }
.dil-card em { font-style: normal; font-size: 11.5px; color: #94a3b8; }
.dil-new { display: inline-flex; align-items: center; justify-content: center; gap: 8px; padding: 0 22px; border-radius: 14px;
    background: linear-gradient(135deg, #0ea5e9, #0369a1); color: #fff; font-weight: 800; text-decoration: none; min-height: 70px; }
.dil-new:hover { color: #fff; text-decoration: none; filter: brightness(1.06); }
.dil-filters { display: flex; gap: 10px; flex-wrap: wrap; align-items: flex-end; padding: 10px 12px; background: #fff; border: 1px solid #e6ebf1;
    border-radius: 12px; margin-bottom: 14px; }
.dil-filters label span { display: block; font-size: 11px; font-weight: 700; color: #64748b; margin-bottom: 3px; }
.dil-filters input, .dil-filters select { height: 34px; border: 1px solid #dbe3ea; border-radius: 8px; padding: 0 9px; font-family: inherit; font-size: 13px; }
.dil-seg { display: inline-flex; padding: 3px; background: #f1f5f9; border-radius: 10px; }
.dil-seg-opt input { position: absolute; opacity: 0; pointer-events: none; }
.dil-seg-opt span { display: inline-block; padding: 6px 14px; border-radius: 8px; font-size: 13px; font-weight: 700; color: #64748b; cursor: pointer; }
.dil-seg-opt input:checked + span { background: #fff; color: #0f172a; box-shadow: 0 1px 2px rgba(15, 23, 42, .1); }
.dil-seg-opt.is-normal input:checked + span { color: #0d9488; }
.dil-seg-opt.is-implant input:checked + span { color: #7c3aed; }
.dil-seg-opt input:focus-visible + span { outline: 2px solid #0369a1; }
.dil-layout { display: grid; grid-template-columns: 1fr 320px; gap: 14px; align-items: start; }
.dil-panel { background: #fff; border: 1px solid #e6ebf1; border-radius: 12px; overflow: hidden; }
.dil-scroll { overflow-x: auto; }
.dil-table { width: 100%; border-collapse: collapse; font-size: 13px; }
.dil-table th { background: #f1f5f9; padding: 9px 10px; font-size: 12px; color: #475569; text-align: right; white-space: nowrap; }
.dil-table td { padding: 9px 10px; border-bottom: 1px solid #f1f5f9; white-space: nowrap; }
.dil-table .n, .dil-mini .n { text-align: left; direction: ltr; font-variant-numeric: tabular-nums; }
.dil-table tr.is-cancelled td { color: #94a3b8; text-decoration: line-through; }
.dil-table tr.is-cancelled td.dil-actions, .dil-table tr.is-cancelled td:nth-child(8) { text-decoration: none; }
.dil-mode { display: block; font-size: 10.5px; color: #94a3b8; font-weight: 600; }
.dil-kind { display: inline-block; padding: 2px 10px; border-radius: 999px; font-size: 11.5px; font-weight: 800; }
.dil-kind.is-normal { background: #ccfbf1; color: #0f766e; }
.dil-kind.is-implant { background: #ede9fe; color: #6d28d9; }
.dil-status { font-size: 11.5px; font-weight: 700; color: #047857; }
.dil-status.is-cancelled { color: #dc2626; }
.dil-actions { display: flex; gap: 6px; }
.dil-btn { width: 30px; height: 30px; border-radius: 8px; display: inline-grid; place-items: center; border: 1px solid #dbe3ea; background: #fff;
    color: #334155; cursor: pointer; text-decoration: none; }
.dil-btn:hover { border-color: #0369a1; color: #0369a1; }
.dil-btn.is-danger:hover { border-color: #dc2626; color: #dc2626; }
.dil-side { padding: 12px 14px; }
.dil-side h4 { margin: 0 0 10px; font-size: 14px; }
.dil-mini { width: 100%; border-collapse: collapse; font-size: 12.5px; }
.dil-mini th { font-size: 11px; color: #64748b; text-align: right; padding: 4px 0; border-bottom: 1px solid #e2e8f0; }
.dil-mini td { padding: 6px 0; border-bottom: 1px solid #f1f5f9; }
.dil-muted { font-size: 11.5px; color: #94a3b8; line-height: 1.6; margin: 10px 0 0; }
.dil-empty { padding: 48px 16px; text-align: center; color: #94a3b8; }
.dil-empty i { font-size: 34px; margin-bottom: 10px; color: #bae6fd; }
@media (max-width: 1000px) { .dil-layout { grid-template-columns: 1fr; } }
</style>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
