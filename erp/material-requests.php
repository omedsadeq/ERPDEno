<?php
/**
 * داواکاری مەواد — doctors' requests for store materials, sent from a case
 * (cases/view.php «مەواد»). The store approves (quantities may be lowered,
 * 0 drops a line) which creates the مەواد بۆ دکتۆر voucher and takes the
 * stock out, or rejects with a reason. ErpMaterialRequest holds the rules.
 */

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../classes/ErpMaterialRequest.php';
erp_require(ErpMaterialRequest::APPROVE_PERMISSION);

$status = (string)($_GET['status'] ?? 'pending');
if (!isset(ErpMaterialRequest::STATUS_LABELS[$status])) {
    $status = 'pending';
}
$requests = ErpMaterialRequest::queue($status);
$pendingCount = ErpMaterialRequest::countPending();
$approvedIssue = (int)($_GET['issue'] ?? 0);

$pageTitle  = 'داواکاری مەواد';
$breadcrumb = [
    ['title' => 'کۆگا', 'url' => APP_URL . '/erp/index.php'],
    ['title' => 'داواکاری مەواد'],
];
require_once __DIR__ . '/../includes/header.php';
?>
<style>
.mrq-tabs { display: flex; gap: 6px; flex-wrap: wrap; margin-bottom: 14px; }
.mrq-tabs a { padding: 7px 14px; border: 1px solid #dbe3ea; border-radius: 8px; background: #fff; color: #334155; font-weight: 700; text-decoration: none; }
.mrq-tabs a.on { background: var(--win-accent, #0078D4); border-color: var(--win-accent, #0078D4); color: #fff; }
.mrq-tabs .n { display: inline-block; min-width: 20px; margin-inline-start: 4px; padding: 0 6px; border-radius: 10px; background: #fef3c7; color: #92400e; font-size: 11px; text-align: center; }
.mrq-card { margin-bottom: 14px; padding: 14px 16px; border: 1px solid #e2e8f0; border-inline-start: 4px solid #f59e0b; border-radius: 10px; background: #fff; }
.mrq-card.is-approved { border-inline-start-color: #16a34a; }
.mrq-card.is-rejected { border-inline-start-color: #dc2626; }
.mrq-card.is-cancelled { border-inline-start-color: #94a3b8; }
.mrq-head { display: flex; align-items: center; gap: 14px; flex-wrap: wrap; margin-bottom: 10px; }
.mrq-head b { font-size: 15px; }
.mrq-meta { color: #475569; font-size: 13px; }
.mrq-meta i { color: #94a3b8; margin-inline-end: 3px; }
.mrq-table { width: 100%; border-collapse: collapse; margin-bottom: 10px; }
.mrq-table th { background: #f8fafc; color: #475569; font-size: 12px; font-weight: 700; padding: 7px 8px; text-align: start; border-bottom: 1px solid #e2e8f0; }
.mrq-table td { padding: 6px 8px; border-bottom: 1px solid #f1f5f9; font-size: 13px; }
.mrq-table td.num { direction: ltr; text-align: center; }
.mrq-table input { width: 100px; padding: 5px 8px; border: 1px solid #cbd5e1; border-radius: 6px; text-align: center; direction: ltr; font: inherit; }
.mrq-table tr.short td { background: #fff7ed; }
.mrq-short { color: #b45309; font-size: 11.5px; }
.mrq-note { margin: 0 0 10px; padding: 7px 10px; border-radius: 6px; background: #f8fafc; color: #334155; font-size: 13px; }
.mrq-note.is-decision { background: #fef2f2; color: #991b1b; }
.mrq-actions { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
.mrq-actions select { padding: 6px 10px; border: 1px solid #cbd5e1; border-radius: 6px; font: inherit; }
.mrq-empty { padding: 40px; text-align: center; color: #64748b; background: #fff; border: 1px dashed #cbd5e1; border-radius: 10px; }
</style>

<?php if ($approvedIssue > 0): ?>
<div class="alert alert-success">
    <i class="fas fa-check-circle"></i> داواکارییەکە پەسەندکرا و مەوادەکان لە کۆگا دەرچوون.
    <a href="<?= APP_URL ?>/erp/print-doctor-issue.php?id=<?= $approvedIssue ?>" target="_blank" rel="noopener"><i class="fas fa-print"></i> چاپکردنی وەسڵ</a>
</div>
<?php endif; ?>

<div class="mrq-tabs">
    <?php foreach (ErpMaterialRequest::STATUS_LABELS as $key => $label): ?>
    <a href="?status=<?= $key ?>" class="<?= $key === $status ? 'on' : '' ?>"><?= erp_esc($label) ?>
        <?php if ($key === 'pending' && $pendingCount): ?><span class="n"><?= $pendingCount ?></span><?php endif; ?></a>
    <?php endforeach; ?>
</div>

<?php if (!$requests): ?>
<div class="mrq-empty"><i class="fas fa-inbox"></i> هیچ داواکارییەکی <?= erp_esc(ErpMaterialRequest::STATUS_LABELS[$status]) ?> نییە</div>
<?php endif; ?>

<?php foreach ($requests as $r): $rid = (int)$r['id']; ?>
<div class="mrq-card is-<?= erp_esc($r['status']) ?>" data-request="<?= $rid ?>">
    <div class="mrq-head">
        <b>#<?= $rid ?></b>
        <span class="mrq-meta"><i class="fas fa-user-doctor"></i><?= erp_esc($r['doctor_name'] ?? '—') ?></span>
        <span class="mrq-meta"><i class="fas fa-user"></i><?= erp_esc($r['patient_name'] ?? '—') ?></span>
        <span class="mrq-meta"><i class="fas fa-folder-open"></i>
            <a href="<?= APP_URL ?>/cases/view.php?id=<?= (int)$r['case_id'] ?>" target="_blank" rel="noopener"><?= erp_esc($r['case_number'] ?? ('#' . (int)$r['case_id'])) ?></a></span>
        <span class="mrq-meta" dir="ltr"><i class="fas fa-clock"></i><?= erp_esc(substr((string)$r['created_at'], 0, 16)) ?></span>
        <?php if ($r['status'] !== 'pending'): ?>
        <span class="mrq-meta"><i class="fas fa-user-check"></i><?= erp_esc($r['decided_by_name'] ?? '—') ?> · <span dir="ltr"><?= erp_esc(substr((string)$r['decided_at'], 0, 16)) ?></span></span>
        <?php endif; ?>
        <?php if (!empty($r['issue_id'])): ?>
        <a href="<?= APP_URL ?>/erp/print-doctor-issue.php?id=<?= (int)$r['issue_id'] ?>&autoprint=0" target="_blank" rel="noopener"><i class="fas fa-file-medical"></i> <?= erp_esc($r['issue_number']) ?></a>
        <?php endif; ?>
    </div>

    <?php if (!empty($r['notes'])): ?><p class="mrq-note"><i class="fas fa-comment"></i> <?= erp_esc($r['notes']) ?></p><?php endif; ?>
    <?php if (!empty($r['decision_note'])): ?><p class="mrq-note is-decision"><i class="fas fa-reply"></i> <?= erp_esc($r['decision_note']) ?></p><?php endif; ?>

    <table class="mrq-table">
        <thead><tr><th>مەواد</th><th>داواکراو</th><th>لە کۆگا</th><th><?= $r['status'] === 'pending' ? 'بڕی پەسەندکردن' : 'پەسەندکراو' ?></th></tr></thead>
        <tbody>
        <?php foreach ($r['items'] as $it):
            $short = (float)$it['stock'] < (float)$it['quantity']; ?>
            <tr class="<?= $r['status'] === 'pending' && $short ? 'short' : '' ?>">
                <td><?= erp_esc($it['product_name']) ?></td>
                <td class="num"><?= erp_qty($it['quantity']) ?> <?= erp_esc($it['unit'] ?? '') ?></td>
                <td class="num"><?= erp_qty($it['stock']) ?>
                    <?php if ($r['status'] === 'pending' && $short): ?><div class="mrq-short">کەمترە لە داواکراو</div><?php endif; ?></td>
                <td class="num">
                    <?php if ($r['status'] === 'pending'): ?>
                    <input type="number" min="0" step="any" data-item="<?= (int)$it['id'] ?>"
                           value="<?= erp_esc(rtrim(rtrim(number_format(min((float)$it['quantity'], max(0, (float)$it['stock'])), 3, '.', ''), '0'), '.')) ?>">
                    <?php else: ?>
                    <?= $it['approved_quantity'] !== null ? erp_qty($it['approved_quantity']) : '—' ?>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <?php if ($r['status'] === 'pending'): ?>
    <div class="mrq-actions">
        <label>جۆری خەرجی
            <select data-kind>
                <?php foreach (ErpDoctorIssue::KINDS as $k => $label): ?>
                <option value="<?= $k ?>"><?= erp_esc($label) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <button type="button" class="btn btn-success" data-approve><i class="fas fa-check"></i> پەسەندکردن و دەرکردن لە کۆگا</button>
        <button type="button" class="btn btn-danger" data-reject><i class="fas fa-times"></i> ڕەتکردنەوە</button>
    </div>
    <?php endif; ?>
</div>
<?php endforeach; ?>

<script>
(function () {
    const API = '<?= APP_URL ?>/erp/api/material-requests.php';
    async function post(data) {
        const fd = new FormData();
        fd.set('csrf_token', window.CSRF_TOKEN || '');
        Object.keys(data).forEach(k => fd.set(k, data[k]));
        const res = await fetch(API, { method: 'POST', body: fd });
        return res.json();
    }
    document.addEventListener('click', async function (e) {
        const card = e.target.closest('[data-request]');
        if (!card) return;
        const id = card.dataset.request;
        if (e.target.closest('[data-approve]')) {
            const btn = e.target.closest('[data-approve]');
            const quantities = {};
            card.querySelectorAll('input[data-item]').forEach(inp => { quantities[inp.dataset.item] = inp.value === '' ? 0 : inp.value; });
            if (!confirm('مەوادەکان لە کۆگا دەردەکرێن و دەخرێنە سەر حیسابی دکتۆر. دڵنیایت؟')) return;
            btn.disabled = true;
            try {
                const d = await post({ action: 'approve', id: id, kind: card.querySelector('[data-kind]').value, quantities: JSON.stringify(quantities) });
                if (!d.ok) { alert(d.error || 'پەسەند نەکرا'); btn.disabled = false; return; }
                window.location.href = '?status=pending&issue=' + encodeURIComponent(d.issue_id);
            } catch (err) { alert('هەڵەیەک ڕوویدا لە پەیوەندیکردن'); btn.disabled = false; }
        } else if (e.target.closest('[data-reject]')) {
            const reason = prompt('هۆکاری ڕەتکردنەوە:');
            if (reason === null) return;
            if (!reason.trim()) { alert('هۆکاری ڕەتکردنەوە بنووسە'); return; }
            const d = await post({ action: 'reject', id: id, reason: reason.trim() });
            if (!d.ok) { alert(d.error || 'ڕەت نەکرایەوە'); return; }
            window.location.reload();
        }
    });
})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
