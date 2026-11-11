<?php
/**
 * بەشی دکتۆر و لێزان لە خەرجی کۆگا.
 *
 * Each doctor carries a percentage of the store expense written in the doctor's
 * name, one for ئاسایی and one for ئیمپلانت (users.store_share_normal / _implant);
 * the rest falls on لێزان. An expense with no doctor is لێزان's whole.
 *
 * This is the clinic's own sheet (کۆگا · کۆگا دکتۆر · کۆگا لێزان · implant ·
 * دکتۆرimp · لێزانimp) on admin/expenses-inventory.php, and the «کۆگا بەر دکتۆر»
 * columns of the income sheet (accountant/_income_data.php). These functions
 * only show the division. Both percentages move money (ds_compute_range,
 * CashboxLedger::expenseRate100 — the income percentage until one is set):
 * the owner's sheet leaves the store out of «کۆی ماوە دوای خەرجی» and takes
 * the doctor's part of ئاسایی and of ئیمپلانت off his share after the split.
 */

const STORE_SHARE_KINDS = ['normal' => 'ئاسایی', 'implant' => 'ئیمپلانت'];

/** A typed percentage: null when left empty, otherwise held to 0–100. */
function store_share_clean($raw): ?float {
    $raw = trim((string)$raw);
    if ($raw === '' || !is_numeric($raw)) {
        return null;
    }
    return max(0.0, min(100.0, round((float)$raw, 2)));
}

/**
 * An amount divided by the doctor's percentage. The doctor's part is rounded
 * and لێزان takes the rest, so the two always add up to the amount. Both are
 * null while the percentage is not set: no division is guessed.
 */
function store_share_split(float $amount, ?float $rate): array {
    if ($rate === null) {
        return ['doctor' => null, 'center' => null];
    }
    $doctor = round($amount * $rate / 100);
    return ['doctor' => $doctor, 'center' => $amount - $doctor];
}

/** Who can carry a share: the same people the page offers as an expense's doctor. */
function store_share_doctors(): array {
    return Database::getInstance()->fetchAll(
        "SELECT id, full_name, store_share_normal, store_share_implant
         FROM users WHERE role IN ('doctor', 'admin') AND is_active = 1 ORDER BY full_name"
    );
}

/** Saves the percentages typed in the dialog (share[<user id>][normal|implant]). */
function store_share_save(array $posted, int $userId): void {
    $db = Database::getInstance();
    foreach (store_share_doctors() as $doctor) {
        $id = (int)$doctor['id'];
        $new = [
            'store_share_normal'  => store_share_clean($posted[$id]['normal'] ?? ''),
            'store_share_implant' => store_share_clean($posted[$id]['implant'] ?? ''),
        ];
        $old = [
            'store_share_normal'  => store_share_clean($doctor['store_share_normal']),
            'store_share_implant' => store_share_clean($doctor['store_share_implant']),
        ];
        if ($new !== $old) {
            $db->update('users', $new, 'id = :id', ['id' => $id]);
            AuditLog::log('store_share_update', 'users', $id, $old, $new, $userId);
        }
    }
}

/**
 * One row per doctor, and one for the expenses with no doctor, under the page's
 * own filter ($where / $params use the alias e), so its figures answer the same
 * question as the list beside them.
 */
function store_share_rows(string $where, array $params): array {
    $rows = Database::getInstance()->fetchAll(
        "SELECT e.doctor_id, d.full_name AS doctor_name, d.store_share_normal, d.store_share_implant,
                COALESCE(SUM(CASE WHEN e.expense_kind = 'normal' THEN e.amount END), 0) AS normal,
                COALESCE(SUM(CASE WHEN e.expense_kind = 'implant' THEN e.amount END), 0) AS implant
         FROM expenses_store e
         LEFT JOIN users d ON d.id = e.doctor_id
         WHERE {$where}
         GROUP BY e.doctor_id, d.full_name, d.store_share_normal, d.store_share_implant
         ORDER BY e.doctor_id IS NULL, SUM(e.amount) DESC",
        $params
    );
    foreach ($rows as &$row) {
        foreach (array_keys(STORE_SHARE_KINDS) as $kind) {
            $amount = (float)$row[$kind];
            $rate = $row['doctor_id'] === null ? 0.0 : store_share_clean($row["store_share_$kind"]);
            // Nothing spent of this kind: nothing to divide, whatever the percentage
            $parts = $amount > 0 ? store_share_split($amount, $rate) : ['doctor' => 0.0, 'center' => 0.0];
            $row[$kind] = ['amount' => $amount, 'rate' => $rate] + $parts;
        }
    }
    unset($row);
    return $rows;
}

/** A row's part ('doctor' / 'center') of ئاسایی and ئیمپلانت together; null while either is undivided. */
function store_share_both(array $row, string $part): ?float {
    if ($row['normal'][$part] === null || $row['implant'][$part] === null) {
        return null;
    }
    return $row['normal'][$part] + $row['implant'][$part];
}

/** What falls on each doctor over a period, for the income sheet: [user id => amount or null]. */
function store_share_on_doctors(string $from, string $to): array {
    $out = [];
    foreach (store_share_rows('e.expense_date BETWEEN :from AND :to', ['from' => $from, 'to' => $to]) as $row) {
        if ($row['doctor_id'] !== null) {
            $out[(int)$row['doctor_id']] = store_share_both($row, 'doctor');
        }
    }
    return $out;
}

/** The income sheet's «کۆگا بەر دکتۆر» cell: empty on a row with no doctor, — while a percentage is missing. */
function store_share_cell(array $row, callable $money): string {
    if (!array_key_exists('storeDoctor', $row)) {
        return '';
    }
    return $row['storeDoctor'] === null ? '—' : $money($row['storeDoctor']);
}

/** The sheet and, for whoever may change them, the percentages' dialog. */
function store_share_render(string $where, array $params, bool $canEdit): void {
    $rows = store_share_rows($where, $params);
    $e = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    $money = fn($v) => $v === null ? '<span class="ss-none">—</span>' : number_format($v);
    $totals = ['normal' => ['amount' => 0.0, 'doctor' => 0.0, 'center' => 0.0], 'implant' => ['amount' => 0.0, 'doctor' => 0.0, 'center' => 0.0]];
    $unset = false;
    foreach ($rows as $row) {
        foreach (array_keys(STORE_SHARE_KINDS) as $kind) {
            $totals[$kind]['amount'] += $row[$kind]['amount'];
            $totals[$kind]['doctor'] += (float)$row[$kind]['doctor'];
            $totals[$kind]['center'] += (float)$row[$kind]['center'];
            $unset = $unset || ($row[$kind]['amount'] > 0 && $row[$kind]['rate'] === null);
        }
    }
    ?>
<?php if (isset($_GET['shares'])): ?>
<div class="alert alert-success" data-auto-hide><i class="fas fa-check-circle alert-icon"></i> ڕێژەی دکتۆرەکان پاشەکەوت کرا</div>
<?php endif; ?>

<style>
.ss-card { background: #fff; border-radius: 8px; overflow: hidden; box-shadow: 0 1px 4px rgba(0,0,0,.06); border: 1px solid #e0e0e0; margin-bottom: 12px; }
.ss-head { display: flex; align-items: center; justify-content: space-between; gap: 8px; padding: 8px 14px; background: linear-gradient(135deg, #ff9800, #f57c00); color: #fff; }
.ss-head h4 { margin: 0; font-size: 13px; font-weight: 700; display: flex; align-items: center; gap: 6px; }
.ss-set { display: inline-flex; align-items: center; gap: 6px; height: 30px; padding: 0 12px; border: 0; border-radius: 7px; background: #fff; color: #e65100; font: inherit; font-size: 12px; font-weight: 700; cursor: pointer; }
.ss-set:hover { background: #fff3e0; }
.ss-scroll { overflow-x: auto; }
.ss-table { width: 100%; border-collapse: collapse; font-size: 12px; }
.ss-table th, .ss-table td { padding: 7px 8px; text-align: center; white-space: nowrap; border-bottom: 1px solid #f0f0f0; }
.ss-table th { font-weight: 700; color: #333; background: #f8f9fa; }
.ss-table th.ss-doc, .ss-table td.ss-doc { text-align: start; font-weight: 700; color: #1f2937; }
.ss-table .is-doctor { background: #eff6ff; color: #1d4ed8; }
.ss-table .is-center { background: #fff7ed; color: #c2410c; }
.ss-table .is-normal { background: #f0fdfa; color: #0f766e; }
.ss-table .is-implant { background: #f5f3ff; color: #6d28d9; }
.ss-table th.is-doctor { background: #dbeafe; }
.ss-table th.is-center { background: #ffedd5; }
.ss-table th.is-normal { background: #ccfbf1; }
.ss-table th.is-implant { background: #ede9fe; }
.ss-table td b { font-variant-numeric: tabular-nums; }
.ss-table td small { display: block; font-size: 11px; font-weight: 600; opacity: .85; }
.ss-table tfoot td { font-weight: 800; border-top: 2px solid #d0d0d0; border-bottom: 0; background: #f1f3f5; }
.ss-table tfoot td.is-doctor { background: #dbeafe; }
.ss-table tfoot td.is-center { background: #ffedd5; }
.ss-table tfoot td.is-normal { background: #ccfbf1; }
.ss-table tfoot td.is-implant { background: #ede9fe; }
.ss-none { color: #94a3b8; font-weight: 400; }
.ss-rate-missing { display: block; width: fit-content; margin: 2px auto 0; padding: 1px 7px; border: 0; border-radius: 6px; background: #fff3e0; color: #b45309; font: inherit; font-size: 11px; font-weight: 700; }
button.ss-rate-missing { cursor: pointer; }
.ss-empty { padding: 18px 14px; text-align: center; font-size: 12.5px; color: #888; }
.ss-warn { padding: 7px 14px; font-size: 12px; font-weight: 600; color: #92400e; background: #fffbeb; border-top: 1px solid #fde68a; }
.ss-dialog-note { margin: 0 0 10px; font-size: 12px; line-height: 1.7; color: #546e7a; }
.ss-rates { width: 100%; border-collapse: collapse; font-size: 12.5px; }
.ss-rates th { padding: 6px; font-size: 11.5px; color: #546e7a; text-align: center; }
.ss-rates th:first-child, .ss-rates td:first-child { text-align: start; }
.ss-rates td { padding: 4px 6px; border-top: 1px solid #f0f0f0; font-weight: 600; }
.ss-rates td input { width: 84px; padding: 6px 8px; border: 1.5px solid #e0e0e0; border-radius: 6px; font: inherit; font-size: 12.5px; text-align: center; direction: ltr; background: #fafafa; }
.ss-rates td input:focus { border-color: #ff9800; background: #fff; outline: none; }
</style>

<div class="ss-card">
    <div class="ss-head">
        <h4><i class="fas fa-percent"></i> بەشی دکتۆر و لێزان لە خەرجی کۆگا</h4>
        <?php if ($canEdit): ?>
        <button type="button" class="ss-set" onclick="openDialog('storeShareDialog')"><i class="fas fa-sliders"></i> ڕێژەی دکتۆرەکان</button>
        <?php endif; ?>
    </div>
    <?php if (!$rows): ?>
    <div class="ss-empty">هیچ خەرجییەکی کۆگا لەم ماوەیەدا نییە</div>
    <?php else: ?>
    <div class="ss-scroll">
        <table class="ss-table no-responsive">
            <thead>
                <tr>
                    <th class="ss-doc">دکتۆر</th>
                    <th>کۆی گشتی</th>
                    <th class="is-doctor">بەر دکتۆر</th>
                    <th class="is-center">بەر لێزان</th>
                    <th class="is-normal">ئاسایی</th>
                    <th class="is-implant">ئیمپلانت</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $row): $noDoctor = $row['doctor_id'] === null; ?>
                <tr>
                    <td class="ss-doc"><?= $noDoctor ? '<span class="ss-none">بێ دکتۆر</span>' : $e($row['doctor_name']) ?></td>
                    <td><b><?= number_format($row['normal']['amount'] + $row['implant']['amount']) ?></b></td>
                    <td class="is-doctor"><b><?= $money(store_share_both($row, 'doctor')) ?></b></td>
                    <td class="is-center"><b><?= $money(store_share_both($row, 'center')) ?></b></td>
                    <?php foreach (array_keys(STORE_SHARE_KINDS) as $kind): $cell = $row[$kind]; ?>
                    <td class="is-<?= $kind ?>">
                        <?php if ($cell['amount'] <= 0): ?><span class="ss-none">—</span>
                        <?php else: ?><b><?= number_format($cell['amount']) ?></b>
                        <?php if ($noDoctor): ?>
                        <?php elseif ($cell['rate'] !== null): ?><small><?= rtrim(rtrim(number_format($cell['rate'], 2, '.', ''), '0'), '.') ?>٪ · دکتۆر <?= number_format($cell['doctor']) ?> · لێزان <?= number_format($cell['center']) ?></small>
                        <?php elseif ($canEdit): ?><button type="button" class="ss-rate-missing" onclick="openDialog('storeShareDialog')">ڕێژە دیاری بکە</button>
                        <?php else: ?><span class="ss-rate-missing">ڕێژە دیاری نەکراوە</span><?php endif; ?>
                        <?php endif; ?>
                    </td>
                    <?php endforeach; ?>
                </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr>
                    <td class="ss-doc">کۆ</td>
                    <td><?= number_format($totals['normal']['amount'] + $totals['implant']['amount']) ?></td>
                    <td class="is-doctor"><?= number_format($totals['normal']['doctor'] + $totals['implant']['doctor']) ?></td>
                    <td class="is-center"><?= number_format($totals['normal']['center'] + $totals['implant']['center']) ?></td>
                    <?php foreach (array_keys(STORE_SHARE_KINDS) as $kind): ?>
                    <td class="is-<?= $kind ?>"><?= number_format($totals[$kind]['amount']) ?><small>دکتۆر <?= number_format($totals[$kind]['doctor']) ?> · لێزان <?= number_format($totals[$kind]['center']) ?></small></td>
                    <?php endforeach; ?>
                </tr>
            </tfoot>
        </table>
    </div>
    <?php if ($unset): ?>
    <div class="ss-warn"><i class="fas fa-exclamation-triangle"></i> ڕێژەی هەندێک دکتۆر دیاری نەکراوە، بۆیە ئەو بڕانە لە کۆی «دکتۆر» و «لێزان» دا نین.</div>
    <?php endif; ?>
    <?php endif; ?>
</div>

<?php if ($canEdit): ?>
<div class="dialog-overlay" id="storeShareDialog">
    <div class="dialog exp-dialog-modern">
        <div class="exp-dialog-header">
            <h4><i class="fas fa-percent"></i> ڕێژەی دکتۆرەکان لە خەرجی کۆگا</h4>
            <button type="button" class="exp-dialog-close" onclick="closeDialog('storeShareDialog')"><i class="fas fa-times"></i></button>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="store_shares">
            <div class="exp-dialog-body">
                <p class="ss-dialog-note">ئەو ڕێژەیەی خەرجی کۆگا کە دەکەوێتە سەر دکتۆر، ئەوەی تر بەر لێزان دەکەوێت. جیایە لە ڕێژەی داهاتی دکتۆر.
                    خەرجی کۆگا لە «کۆی ماوە دوای خەرجی» کەم ناکرێتەوە: بەشی دکتۆر لە ئاسایی و لە ئیمپلانت، هەریەک بە ڕێژەی خۆی، دوای دابەشکردن لە پشکی دکتۆر کەم دەکرێتەوە. بەتاڵ = ڕێژەی داهاتی دکتۆر.</p>
                <table class="ss-rates no-responsive">
                    <thead><tr><th>دکتۆر</th><th>ئاسایی ٪</th><th>ئیمپلانت ٪</th></tr></thead>
                    <tbody>
                        <?php foreach (store_share_doctors() as $doctor): $id = (int)$doctor['id']; ?>
                        <tr>
                            <td><?= $e($doctor['full_name']) ?></td>
                            <?php foreach (array_keys(STORE_SHARE_KINDS) as $kind): $rate = store_share_clean($doctor["store_share_$kind"]); ?>
                            <td style="text-align: center;"><input type="number" name="share[<?= $id ?>][<?= $kind ?>]" min="0" max="100" step="0.01" value="<?= $rate === null ? '' : $e($rate) ?>" aria-label="<?= $e($doctor['full_name'] . ' — ' . STORE_SHARE_KINDS[$kind]) ?>"></td>
                            <?php endforeach; ?>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="exp-dialog-footer">
                <button type="submit" class="exp-btn-primary"><i class="fas fa-save"></i> پاشەکەوت</button>
                <button type="button" class="exp-btn-secondary" onclick="closeDialog('storeShareDialog')">داخستن</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>
    <?php
}
