<?php
/**
 * What stands behind one income figure — the button beside a row of the income
 * sheet (accountant/income.php), and beside the x-ray card of the financial
 * summary, and the dialog it opens: the figure's parts as coloured cards, every
 * receipt behind it, and for each one its receipt (in a second dialog), its
 * case or patient, and where it is edited.
 *
 *   doctor (id = users.id)  chair cash on approved invoices of the period's work,
 *                           approved payments and old standalone installments —
 *                           ds_compute_range()'s treatmentCash + oldDebtCollected
 *   xray                    includes/xray-income.php, type by type
 *   pharmacy / exam         the pharmacy's confirmed sales less returns / the
 *                           visit fees, plus the amounts typed in by hand
 *   lab / store / general   (id = users.id) a doctor row's expense columns:
 *                           the costs written in his name
 *
 * Every kind's lines add up to the figure the button stands beside: the
 * queries are the ones that figure is made of. The body is fetched when the
 * button is pressed (accountant/income-detail.php), so the sheet stays light.
 */

require_once __DIR__ . '/xray-income.php';
require_once __DIR__ . '/../classes/IncomeManualEntry.php';

const INCOME_DETAIL_KINDS = ['doctor', 'xray', 'pharmacy', 'exam', 'lab', 'store', 'general'];

/** The button beside a figure. */
function income_detail_button(string $kind, int $id = 0): string
{
    return '<button type="button" class="xi-btn" data-income-detail="' . htmlspecialchars($kind, ENT_QUOTES, 'UTF-8') . '" data-id="' . $id . '"'
        . ' title="وەسڵەکانی پشت ئەم ژمارەیە"><i class="fas fa-layer-group"></i></button>';
}

/**
 * The figure's parts: ['title', 'groups' => [key => label, color, count?], 'lines' => [group, day, no,
 * patient, patient_id, note, amount, receipt, print, links]], or null for an unknown kind.
 */
function income_detail_data(Database $db, string $kind, int $id, string $from, string $to): ?array
{
    $app = APP_URL;
    $patientLink = fn($pid) => $pid ? ['نەخۆش', 'fa-user', $app . '/patients/view.php?id=' . (int)$pid] : null;

    if ($kind === 'xray') {
        $lines = [];
        foreach (xray_income_lines($db, $from, $to) as $l) {
            $lines[] = [
                'group' => $l['xray_type'], 'day' => $l['day'], 'no' => $l['request_number'],
                'patient' => $l['patient'], 'note' => $l['kind'] === 'debt' ? 'دانەوەی قەرز' : 'نەقد', 'amount' => (float)$l['amount'],
                'receipt' => $app . '/xray/receipt.php?id=' . (int)$l['id'] . '&embed=1',
                'print' => $app . '/xray/receipt.php?id=' . (int)$l['id'] . '&autoprint=1',
                'links' => array_filter([['داواکاری', 'fa-x-ray', $app . '/xray/view.php?id=' . (int)$l['id']], $patientLink($l['patient_id'])]),
            ];
        }
        $groups = [];
        foreach (xray_income_totals(xray_income_lines($db, $from, $to)) as $type => $t) {
            $groups[$type] = ['label' => XRAY_INCOME_TYPES[$type]['label'] ?? $type, 'color' => XRAY_INCOME_TYPES[$type]['color'] ?? '#64748b',
                              'count' => $t['count'], 'unit' => 'داواکاری'];
        }
        return ['title' => 'X-ray', 'groups' => $groups, 'lines' => $lines];
    }

    if ($kind === 'doctor') {
        $doctor = $db->fetch("SELECT full_name FROM users WHERE id = ? AND role = 'doctor'", [$id]);
        if (!$doctor) {
            return null;
        }
        $lines = [];
        $caseLinks = fn($caseId, $pid) => array_filter([['کەیس', 'fa-folder-open', $app . '/cases/view.php?id=' . (int)$caseId], $patientLink($pid)]);
        // Chair cash on the period's approved invoices (ds_compute_range: chair_received)
        foreach ($db->fetchAll(
            "SELECT ci.id, ci.invoice_no, ci.work_date AS day, ci.case_id, ci.draft_received_amount AS amount, pt.id AS patient_id, pt.full_name AS patient
             FROM case_invoices ci
             JOIN cases c ON c.id = ci.case_id
             JOIN patient_folders pf ON pf.id = c.folder_id
             JOIN patients pt ON pt.id = pf.patient_id
             WHERE ci.work_date BETWEEN :f AND :t AND ci.accounting_status = 'approved' AND ci.draft_received_amount <> 0
               AND COALESCE(ci.doctor_id, pf.doctor_id) = :uid",
            ['f' => $from, 't' => $to, 'uid' => $id]
        ) as $r) {
            $lines[] = ['group' => 'invoice', 'day' => $r['day'], 'no' => $r['invoice_no'], 'patient' => $r['patient'], 'note' => 'لەسەر کورسی',
                        'amount' => (float)$r['amount'],
                        'receipt' => $app . '/cases/receipt.php?id=' . (int)$r['case_id'] . '&invoice=' . (int)$r['id'] . '&embed=1',
                        'print' => $app . '/cases/receipt.php?id=' . (int)$r['case_id'] . '&invoice=' . (int)$r['id'] . '&autoprint=1',
                        'links' => $caseLinks($r['case_id'], $r['patient_id'])];
        }
        // Approved payments: the part for this period's work and the rest, as ds_compute_range counts them
        foreach ($db->fetchAll(
            "SELECT p.id, p.payment_number, DATE(p.payment_date) AS day, p.case_id, p.amount, p.payment_method,
                    pt.id AS patient_id, pt.full_name AS patient,
                    COALESCE(SUM(CASE WHEN ci.work_date BETWEEN :f2 AND :t2 THEN pa.allocated_amount ELSE 0 END), 0) AS today_part
             FROM payments p
             JOIN cases c ON c.id = p.case_id
             JOIN patient_folders pf ON pf.id = c.folder_id
             JOIN patients pt ON pt.id = pf.patient_id
             LEFT JOIN payment_allocations pa ON pa.payment_id = p.id
             LEFT JOIN case_invoices ci ON ci.id = pa.invoice_id
             WHERE p.status = 'approved' AND DATE(p.payment_date) BETWEEN :f AND :t AND pf.doctor_id = :uid
             GROUP BY p.id, p.payment_number, p.payment_date, p.case_id, p.amount, p.payment_method, pt.id, pt.full_name",
            ['f' => $from, 't' => $to, 'f2' => $from, 't2' => $to, 'uid' => $id]
        ) as $r) {
            $today = (float)$r['today_part'];
            $note = $r['payment_method'] === 'advance' ? 'لە ئامانەت' : ($today > 0 ? 'بۆ کاری ئەم ماوەیە' : 'قەرزی کۆن');
            $lines[] = ['group' => 'payment', 'day' => $r['day'], 'no' => $r['payment_number'], 'patient' => $r['patient'], 'note' => $note,
                        'amount' => $today + max(0, (float)$r['amount'] - $today),
                        'receipt' => $app . '/cases/voucher.php?payment=' . (int)$r['id'] . '&embed=1',
                        'print' => $app . '/cases/voucher.php?payment=' . (int)$r['id'] . '&autoprint=1',
                        'links' => $caseLinks($r['case_id'], $r['patient_id'])];
        }
        // Old standalone installments (not tied to a payment row)
        foreach ($db->fetchAll(
            "SELECT ip.id, DATE(ip.paid_at) AS day, pl.case_id, ip.amount, pt.id AS patient_id, pt.full_name AS patient
             FROM installment_payments ip
             JOIN installment_plans pl ON pl.id = ip.plan_id
             JOIN cases c ON c.id = pl.case_id
             JOIN patient_folders pf ON pf.id = c.folder_id
             JOIN patients pt ON pt.id = pf.patient_id
             WHERE DATE(ip.paid_at) BETWEEN :f AND :t AND pf.doctor_id = :uid AND ip.payment_id IS NULL",
            ['f' => $from, 't' => $to, 'uid' => $id]
        ) as $r) {
            $lines[] = ['group' => 'installment', 'day' => $r['day'], 'no' => 'IP-' . $r['id'], 'patient' => $r['patient'], 'note' => 'قیستی کۆن',
                        'amount' => (float)$r['amount'],
                        'receipt' => $app . '/cases/receipt.php?id=' . (int)$r['case_id'] . '&embed=1',
                        'print' => $app . '/cases/receipt.php?id=' . (int)$r['case_id'] . '&autoprint=1',
                        'links' => $caseLinks($r['case_id'], $r['patient_id'])];
        }
        return ['title' => $doctor['full_name'], 'lines' => $lines, 'groups' => [
            'invoice'     => ['label' => 'وەسڵی کار', 'color' => '#0284c7'],
            'payment'     => ['label' => 'پارەدان', 'color' => '#15803d'],
            'installment' => ['label' => 'قیست', 'color' => '#d97706'],
        ]];
    }

    if ($kind === 'lab' || $kind === 'store' || $kind === 'general') {
        // A doctor row's expense columns: the costs written in his name
        // (ds_compute_range: labExpense / storeExpense / generalExpense).
        $doctor = $db->fetch("SELECT full_name FROM users WHERE id = ? AND role = 'doctor'", [$id]);
        if (!$doctor) {
            return null;
        }
        $page = ['lab' => 'expenses-lab', 'store' => 'expenses-inventory', 'general' => 'expenses-general'][$kind];
        $listLink = fn($day) => ['لە لیستی خەرجی', 'fa-pen', $app . '/admin/' . $page . '.php?date_from=' . $day . '&date_to=' . $day];
        $lines = [];
        $groups = [];
        if ($kind === 'store') {
            // By kind: each falls on him by its own percentage (includes/store-expense-share.php).
            $groups = ['normal' => ['label' => 'ئاسایی', 'color' => '#0f766e'], 'implant' => ['label' => 'ئیمپلانت', 'color' => '#7c3aed']];
            foreach ($db->fetchAll(
                "SELECT e.id, e.expense_date AS day, e.expense_kind, e.amount, e.description, e.supplier, e.invoice_number,
                        e.source_type, e.source_id, ec.category_name
                 FROM expenses_store e LEFT JOIN expense_categories_store ec ON ec.id = e.category_id
                 WHERE e.doctor_id = :uid AND e.expense_date BETWEEN :f AND :t",
                ['uid' => $id, 'f' => $from, 't' => $to]
            ) as $r) {
                $voucher = $r['source_type'] === 'erp_doctor_issue' && $r['source_id'];
                $lines[] = ['group' => $r['expense_kind'] === 'implant' ? 'implant' : 'normal', 'day' => $r['day'],
                            'no' => $r['invoice_number'] ?: 'STR-' . $r['id'], 'patient' => $r['description'],
                            'note' => $voucher ? 'مەواد بۆ دکتۆر' : ($r['category_name'] ?: ($r['supplier'] ?: '')), 'amount' => (float)$r['amount'],
                            'receipt' => $voucher ? $app . '/erp/print-doctor-issue.php?id=' . (int)$r['source_id'] . '&autoprint=0' : null,
                            'print' => $voucher ? $app . '/erp/print-doctor-issue.php?id=' . (int)$r['source_id'] . '&autoprint=1' : null,
                            'links' => [$listLink($r['day'])]];
            }
        } else {
            // By group, in the group's own colour.
            $table = $kind === 'lab' ? 'expenses_lab' : 'expenses_general';
            $catTable = $kind === 'lab' ? 'expense_categories_lab' : 'expense_categories_general';
            $extra = $kind === 'lab' ? 'e.lab_name AS who, e.receipt_number AS no, e.patient_id' : 'e.vendor AS who, e.receipt_number AS no, NULL AS patient_id';
            foreach ($db->fetchAll(
                "SELECT e.id, e.expense_date AS day, e.amount, e.description, e.category_id, {$extra},
                        ec.category_name, ec.color, s.name AS subgroup_name
                 FROM {$table} e
                 LEFT JOIN {$catTable} ec ON ec.id = e.category_id
                 LEFT JOIN expense_subgroups s ON s.id = e.subgroup_id
                 WHERE e.doctor_id = :uid AND e.expense_date BETWEEN :f AND :t",
                ['uid' => $id, 'f' => $from, 't' => $to]
            ) as $r) {
                $key = 'c' . (int)$r['category_id'];
                $groups[$key] ??= ['label' => $r['category_name'] ?: 'بێ گرووپ', 'color' => $r['color'] ?: '#64748b'];
                $lines[] = ['group' => $key, 'day' => $r['day'], 'no' => $r['no'] ?: strtoupper(substr($kind, 0, 3)) . '-' . $r['id'],
                            'patient' => $r['description'], 'note' => trim(($r['subgroup_name'] ?? '') . ' ' . ($r['who'] ?? '')),
                            'amount' => (float)$r['amount'], 'receipt' => null, 'print' => null,
                            'links' => array_filter([$listLink($r['day']), $patientLink($r['patient_id'] ?? null)])];
            }
        }
        $label = ['lab' => 'خەرجی تاقیگە', 'store' => 'خەرجی کۆگا', 'general' => 'خەرجی گشتی'][$kind];
        return ['title' => $label . ' — ' . $doctor['full_name'], 'groups' => $groups, 'lines' => $lines];
    }

    if ($kind === 'pharmacy' || $kind === 'exam') {
        $lines = [];
        if ($kind === 'pharmacy' && $db->tableExists('erp2_sales_invoices')) {
            foreach ($db->fetchAll(
                "SELECT s.id, s.invoice_number, s.invoice_date AS day, s.grand_total, c.name AS customer, c.patient_id
                 FROM erp2_sales_invoices s LEFT JOIN erp2_customers c ON c.id = s.customer_id
                 WHERE s.status = 'confirmed' AND s.invoice_date BETWEEN :f AND :t",
                ['f' => $from, 't' => $to]
            ) as $r) {
                $lines[] = ['group' => 'sale', 'day' => $r['day'], 'no' => $r['invoice_number'], 'patient' => $r['customer'], 'note' => 'فرۆشتن',
                            'amount' => (float)$r['grand_total'],
                            'receipt' => $app . '/erp2/print-invoice.php?id=' . (int)$r['id'] . '&size=a4&autoprint=0',
                            'print' => $app . '/erp2/print-invoice.php?id=' . (int)$r['id'] . '&size=a4&autoprint=1',
                            'links' => array_filter([['دەستکاری', 'fa-pen', $app . '/erp2/sales.php?edit=' . (int)$r['id']], $patientLink($r['patient_id'])])];
            }
            if ($db->tableExists('erp2_sales_returns')) {
                foreach ($db->fetchAll(
                    "SELECT r.id, r.return_number, r.return_date AS day, r.grand_total, c.name AS customer, c.patient_id
                     FROM erp2_sales_returns r LEFT JOIN erp2_customers c ON c.id = r.customer_id
                     WHERE r.status = 'confirmed' AND r.return_date BETWEEN :f AND :t",
                    ['f' => $from, 't' => $to]
                ) as $r) {
                    $lines[] = ['group' => 'return', 'day' => $r['day'], 'no' => $r['return_number'], 'patient' => $r['customer'], 'note' => 'گەڕاندنەوە',
                                'amount' => -(float)$r['grand_total'], 'receipt' => null, 'print' => null,
                                'links' => array_filter([['گەڕاندنەوەکان', 'fa-rotate-left', $app . '/erp2/sales-returns.php'], $patientLink($r['patient_id'])])];
                }
            }
        }
        if ($kind === 'exam') {
            foreach ($db->fetchAll(
                "SELECT v.id, v.visit_date AS day, v.price, pt.id AS patient_id, pt.full_name AS patient
                 FROM visits v LEFT JOIN patients pt ON pt.id = v.patient_id
                 WHERE v.visit_date BETWEEN :f AND :t AND v.price > 0",
                ['f' => $from, 't' => $to]
            ) as $r) {
                $lines[] = ['group' => 'visit', 'day' => $r['day'], 'no' => 'V-' . $r['id'], 'patient' => $r['patient'], 'note' => 'پارەی پشکنین',
                            'amount' => (float)$r['price'], 'receipt' => null, 'print' => null,
                            'links' => array_filter([['دەستکاری سەردان', 'fa-pen', $app . '/edit_visit.php?id=' . (int)$r['id']], $patientLink($r['patient_id'])])];
            }
        }
        // Typed in by hand on the sheet: edited in its own list below the sheet.
        foreach ((new IncomeManualEntry())->listRange($from, $to) as $m) {
            if (($m['kind'] ?? '') !== $kind) {
                continue;
            }
            $lines[] = ['group' => 'manual', 'day' => $m['entry_date'], 'no' => 'دەستی', 'patient' => $m['note'] ?: null,
                        'note' => (int)$m['qty'] > 0 ? (int)$m['qty'] . ' دانە' : 'دەستی', 'amount' => (float)$m['amount'], 'receipt' => null, 'print' => null,
                        'links' => [['دەستکاری', 'fa-pen', '#incManual']]];
        }
        return ['title' => $kind === 'pharmacy' ? 'Pharmacy' : 'Examinations', 'lines' => $lines, 'groups' => $kind === 'pharmacy'
            ? ['sale' => ['label' => 'فرۆشتن', 'color' => '#0284c7'], 'return' => ['label' => 'گەڕاندنەوە', 'color' => '#dc2626'],
               'manual' => ['label' => 'دەستی', 'color' => '#b45309']]
            : ['visit' => ['label' => 'پشکنین', 'color' => '#0f766e'], 'manual' => ['label' => 'دەستی', 'color' => '#b45309']]];
    }
    return null;
}

/** The dialog's body for one figure. */
function income_detail_body(array $d, string $from, string $to): void
{
    $e = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    $money = fn($v) => ($v < 0 ? '−' : '') . number_format(abs((float)$v));
    usort($d['lines'], fn($a, $b) => [$b['day'], $b['no']] <=> [$a['day'], $a['no']]);
    $groups = $d['groups'];
    foreach ($groups as $key => &$g) {
        $mine = array_filter($d['lines'], fn($l) => $l['group'] === $key);
        $g['amount'] = array_sum(array_column($mine, 'amount'));
        $g['count'] ??= count($mine);
        $g['lines'] = count($mine);
    }
    unset($g);
    $total = array_sum(array_column($d['lines'], 'amount'));
    $period = $from === $to ? $from : $from . ' بۆ ' . $to;
    ?>
<div class="xi-sum" data-title="<?= $e($d['title']) ?>">کۆی گشتی <b><?= $money($total) ?></b> · <?= count($d['lines']) ?> وەسڵ · <bdi><?= $e($period) ?></bdi></div>
<div class="xi-types" style="--n:<?= max(1, count($groups)) ?>">
    <?php foreach ($groups as $key => $g): ?>
    <button type="button" class="xi-type" data-type="<?= $e($key) ?>" style="--c:<?= $e($g['color']) ?>" <?= $g['lines'] ? '' : 'disabled' ?>>
        <span class="l"><?= $e($g['label']) ?></span>
        <b><?= $money($g['amount']) ?></b>
        <small><?= number_format($g['count']) ?> <?= $e($g['unit'] ?? 'وەسڵ') ?></small>
    </button>
    <?php endforeach; ?>
</div>
<div class="xi-head">
    <h4 class="xi-list-title">هەمووی</h4>
    <?php if ($d['lines']): ?>
    <label class="xi-search"><i class="fas fa-search"></i><input type="search" class="xi-q" placeholder="گەڕان: ناو، ژمارە، بڕ، ڕێکەوت…" autocomplete="off"></label>
    <span class="xi-found"></span>
    <?php endif; ?>
    <button type="button" class="xi-all" hidden>هەمووی</button>
</div>
<?php if (!$d['lines']): ?>
<div class="xi-empty">هیچ وەسڵێک لەم ماوەیەدا نییە</div>
<?php else: ?>
<div class="xi-scroll">
    <table class="xi-tbl no-responsive">
        <thead><tr><th>ڕێکەوت</th><th>ژمارە</th><th>ناو</th><th>جۆر</th><th>بڕ</th><th>کردار</th></tr></thead>
        <tbody>
        <?php foreach ($d['lines'] as $l): $g = $groups[$l['group']] ?? ['label' => $l['group'], 'color' => '#64748b']; ?>
            <tr data-type="<?= $e($l['group']) ?>" data-amount="<?= (float)$l['amount'] ?>"
                data-text="<?= $e(implode(' ', [$l['day'], $l['no'], $l['patient'] ?? '', $g['label'], $l['note'], (string)round(abs($l['amount']))])) ?>"<?php if ($l['receipt']): ?> data-receipt="<?= $e($l['receipt']) ?>" data-print="<?= $e($l['print']) ?>" data-no="<?= $e($l['no']) ?>"<?php endif; ?>>
                <td><bdi><?= $e($l['day']) ?></bdi></td>
                <td class="no"><?= $e($l['no']) ?></td>
                <td><?= $e($l['patient'] ?? '—') ?></td>
                <td><span class="xi-tag" style="--c:<?= $e($g['color']) ?>"><?= $e($g['label']) ?></span> <span class="xi-kind"><?= $e($l['note']) ?></span></td>
                <td class="n<?= $l['amount'] < 0 ? ' neg' : '' ?>"><?= $money($l['amount']) ?></td>
                <td class="act">
                    <?php if ($l['receipt']): ?><button type="button" class="xi-rc"><i class="fas fa-receipt"></i> وەسڵ</button><?php endif; ?>
                    <?php foreach ($l['links'] as [$label, $icon, $url]): ?>
                    <a class="xi-ln" href="<?= $e($url) ?>"<?= $url[0] === '#' ? ' data-close' : ' target="_blank" rel="noopener"' ?> title="<?= $e($label) ?>" aria-label="<?= $e($label) ?>"><i class="fas <?= $e($icon) ?>"></i></a>
                    <?php endforeach; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <div class="xi-empty xi-none" hidden>هیچ وەسڵێک بەم گەڕانە نەدۆزرایەوە</div>
</div>
<?php endif; ?>
    <?php
}

/** The two dialogs and their script; once per page, for the period the page shows. */
function income_detail_shell(string $from, string $to): void
{
    ?>
<style>
.xi-amt{display:flex;align-items:center;justify-content:flex-end;gap:8px}
.xi-btn{width:26px;height:26px;padding:0;border-radius:8px;border:1px solid #bae6fd;background:#f0f9ff;color:#0369a1;cursor:pointer;font-size:12px;
    display:inline-grid;place-items:center;flex-shrink:0;vertical-align:middle}
.xi-btn:hover{background:#0284c7;border-color:#0284c7;color:#fff}
.xi-dlg{max-width:980px;width:calc(100vw - 32px);max-height:90vh;display:flex;flex-direction:column}
.xi-dlg .dialog-body{overflow-y:auto;min-height:160px}
.xi-dlg .dialog-title i{color:#0284c7}
.xi-loading{padding:40px;text-align:center;color:#94a3b8}
.xi-sum{margin-bottom:12px;font-size:13px;color:#64748b}
.xi-sum b{font-size:16px;color:#0f172a}
.xi-types{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:10px;margin-bottom:14px}
.xi-type{position:relative;display:flex;flex-direction:column;align-items:flex-start;gap:2px;padding:12px 14px;border-radius:12px;
    border:1.5px solid color-mix(in srgb,var(--c) 30%,#fff);background:color-mix(in srgb,var(--c) 7%,#fff);font:inherit;text-align:start;cursor:pointer;transition:transform .15s,box-shadow .15s}
.xi-type:hover{transform:translateY(-1px);box-shadow:0 6px 16px -10px var(--c)}
.xi-type .l{font-size:13px;font-weight:800;color:var(--c)}
.xi-type b{font-size:19px;color:#0f172a;font-variant-numeric:tabular-nums;direction:ltr}
.xi-type small{font-size:12px;color:#64748b}
.xi-type.is-on{border-color:var(--c);background:var(--c)}
.xi-type.is-on .l,.xi-type.is-on b,.xi-type.is-on small{color:#fff}
.xi-type:disabled{opacity:.5;cursor:default;transform:none;box-shadow:none}
.xi-head{display:flex;flex-wrap:wrap;align-items:center;gap:8px 10px;margin-bottom:8px}
.xi-head h4{margin:0;font-size:14px;color:#0f172a}
.xi-search{flex:1;min-width:200px;max-width:360px;display:flex;align-items:center;gap:8px;height:34px;padding:0 10px;margin-inline-start:auto;
    border:1px solid #cbd5e1;border-radius:9px;background:#fff;color:#94a3b8;cursor:text}
.xi-search:focus-within{border-color:#0284c7;box-shadow:0 0 0 3px rgba(2,132,199,.12)}
.xi-search input{flex:1;min-width:0;height:100%;border:0;outline:0;background:none;font:inherit;font-size:13px;color:#0f172a}
.xi-found{font-size:12.5px;font-weight:700;color:#0369a1;white-space:nowrap}
.xi-found:empty{display:none}
.xi-head button{height:30px;padding:0 12px;border-radius:8px;border:1px solid #e2e8f0;background:#fff;color:#475569;font:inherit;font-size:12.5px;cursor:pointer}
.xi-head button[hidden]{display:none}
.xi-scroll{overflow-x:auto;border:1px solid #e2e8f0;border-radius:10px}
.xi-tbl{width:100%;border-collapse:collapse;font-size:13px}
.xi-tbl th{position:sticky;top:0;padding:8px 10px;background:#f8fafc;color:#475569;font-size:12px;text-align:start;border-bottom:1px solid #e2e8f0;white-space:nowrap}
.xi-tbl td{padding:7px 10px;border-bottom:1px solid #f1f5f9;white-space:nowrap}
.xi-tbl tbody tr[data-receipt]{cursor:pointer}
.xi-tbl tbody tr:hover td{background:#f0f9ff}
.xi-tbl td.n{text-align:end;font-weight:700;font-variant-numeric:tabular-nums;direction:ltr}
.xi-tbl td.n.neg{color:#dc2626}
.xi-tbl td.no{direction:ltr;text-align:end;color:#334155}
.xi-tbl td.act{display:flex;gap:4px;align-items:center}
.xi-tag{display:inline-block;padding:1px 8px;border-radius:6px;background:color-mix(in srgb,var(--c) 12%,#fff);color:var(--c);font-weight:700;font-size:12px}
.xi-kind{font-size:11.5px;color:#64748b}
.xi-rc,.xi-ln{height:28px;padding:0 9px;border-radius:7px;font:inherit;font-size:12px;font-weight:700;cursor:pointer;display:inline-flex;align-items:center;gap:5px;text-decoration:none}
.xi-rc{border:1px solid #bae6fd;background:#f0f9ff;color:#0369a1}
.xi-ln{width:28px;justify-content:center;padding:0;border:1px solid #e2e8f0;background:#fff;color:#475569}
.xi-ln:hover{border-color:#94a3b8;color:#0f172a}
.xi-empty{padding:24px;text-align:center;color:#94a3b8;font-size:13px}
.xi-rcd{max-width:880px;width:calc(100vw - 32px);height:92vh;display:flex;flex-direction:column}
.xi-rcd .dialog-header{gap:8px}
.xi-rcd .dialog-header a{margin-inline-start:auto;height:32px;display:inline-flex;align-items:center;gap:6px;padding:0 12px;border-radius:8px;
    background:#0284c7;color:#fff;font-size:12.5px;font-weight:700;text-decoration:none}
.xi-rcd iframe{flex:1;width:100%;border:0;background:#f4f6f8}
@media (max-width:640px){.xi-types{grid-template-columns:1fr 1fr}}
</style>

<div class="dialog-overlay" id="incomeDetailDialog"><div class="dialog xi-dlg" role="dialog" aria-modal="true" aria-labelledby="xi-title">
    <div class="dialog-header"><h3 class="dialog-title" id="xi-title"><i class="fas fa-layer-group"></i> <span></span></h3>
        <button type="button" class="dialog-close" onclick="closeDialog('incomeDetailDialog')" aria-label="داخستن"><i class="fas fa-times"></i></button></div>
    <div class="dialog-body" id="xiBody"></div>
</div></div>

<div class="dialog-overlay" id="incomeDetailReceipt"><div class="dialog xi-rcd" role="dialog" aria-modal="true" aria-labelledby="xi-rc-title">
    <div class="dialog-header"><h3 class="dialog-title" id="xi-rc-title"></h3>
        <a href="#" id="xiPrint" target="_blank" rel="noopener"><i class="fas fa-print"></i> چاپ</a>
        <button type="button" class="dialog-close" onclick="incomeDetailReceiptClose()" aria-label="داخستن"><i class="fas fa-times"></i></button></div>
    <iframe id="xiFrame" title="وەسڵ"></iframe>
</div></div>

<script>
(function () {
    var url = <?= json_encode(APP_URL . '/accountant/income-detail.php?from=' . rawurlencode($from) . '&to=' . rawurlencode($to)) ?>;
    var body = document.getElementById('xiBody'), title = document.querySelector('#xi-title span');
    var asked = 0;
    // A button beside a figure: its parts are fetched now, so the page stays light.
    document.addEventListener('click', function (ev) {
        var btn = ev.target.closest('[data-income-detail]');
        if (!btn) return;
        var mine = ++asked;
        filter = {type: '', q: ''};
        title.textContent = '…';
        body.innerHTML = '<div class="xi-loading"><i class="fas fa-spinner fa-spin"></i></div>';
        openDialog('incomeDetailDialog');
        fetch(url + '&kind=' + encodeURIComponent(btn.dataset.incomeDetail) + '&id=' + encodeURIComponent(btn.dataset.id || 0), {credentials: 'same-origin'})
            .then(function (r) { if (!r.ok) throw new Error(r.status); return r.text(); })
            .then(function (html) {
                if (mine !== asked) return;
                body.innerHTML = html;
                var sum = body.querySelector('.xi-sum');
                title.textContent = sum ? sum.dataset.title : '';
            })
            .catch(function () { if (mine === asked) body.innerHTML = '<div class="xi-empty">نەکرایەوە، دووبارە هەوڵ بدەرەوە</div>'; });
    });
    // What is shown: the card picked (or all) and what is typed in the search.
    // Letters typed on an Arabic keyboard and Eastern digits match too, and an
    // amount matches with or without its commas.
    var filter = {type: '', q: ''};
    function norm(s) {
        return String(s).toLowerCase()
            .replace(/[٠-٩]/g, function (d) { return '٠١٢٣٤٥٦٧٨٩'.indexOf(d); })
            .replace(/[۰-۹]/g, function (d) { return '۰۱۲۳۴۵۶۷۸۹'.indexOf(d); })
            .replace(/ي/g, 'ی').replace(/ك/g, 'ک').replace(/ة/g, 'ە').replace(/[,،]/g, '').replace(/\s+/g, ' ').trim();
    }
    function apply() {
        var rows = body.querySelectorAll('.xi-tbl tbody tr'), q = norm(filter.q), n = 0, sum = 0;
        rows.forEach(function (tr) {
            if (tr._t === undefined) tr._t = norm(tr.dataset.text);
            var ok = (!filter.type || tr.dataset.type === filter.type) && (!q || tr._t.indexOf(q) !== -1);
            tr.hidden = !ok;
            if (ok) { n++; sum += parseFloat(tr.dataset.amount) || 0; }
        });
        var found = body.querySelector('.xi-found'), none = body.querySelector('.xi-none');
        if (found) found.textContent = filter.type || q ? n + ' وەسڵ · کۆ ' + Math.round(sum).toLocaleString('en-US') : '';
        if (none) none.hidden = n > 0;
    }
    // A card shows only its part; again, or «هەمووی», shows all.
    function show(type, name) {
        filter.type = type;
        body.querySelectorAll('.xi-type').forEach(function (c) { c.classList.toggle('is-on', c.dataset.type === type); });
        body.querySelector('.xi-list-title').textContent = type ? name : 'هەمووی';
        body.querySelector('.xi-all').hidden = !type;
        apply();
    }
    body.addEventListener('input', function (ev) {
        if (!ev.target.classList.contains('xi-q')) return;
        filter.q = ev.target.value;
        apply();
    });
    body.addEventListener('click', function (ev) {
        var card = ev.target.closest('.xi-type');
        if (card) { show(card.classList.contains('is-on') ? '' : card.dataset.type, card.querySelector('.l').textContent); return; }
        if (ev.target.closest('.xi-all')) { show(''); return; }
        var link = ev.target.closest('a.xi-ln');
        if (link) { if (link.hasAttribute('data-close')) closeDialog('incomeDetailDialog'); return; }
        // A receipt opens over the list.
        var tr = ev.target.closest('.xi-tbl tbody tr[data-receipt]');
        if (!tr) return;
        document.getElementById('xi-rc-title').textContent = 'وەسڵ ' + tr.dataset.no;
        document.getElementById('xiPrint').href = tr.dataset.print;
        document.getElementById('xiFrame').src = tr.dataset.receipt;
        openDialog('incomeDetailReceipt');
    });
    window.incomeDetailReceiptClose = function () {
        closeDialog('incomeDetailReceipt');
        document.getElementById('xiFrame').src = 'about:blank';
    };
})();
</script>
    <?php
}
