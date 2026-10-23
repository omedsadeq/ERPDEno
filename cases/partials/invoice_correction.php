<?php
// Admin-only scope, supplied by invoice_pane.php. All text stays escaped even
// inside the JSON seed: treatment/notes may contain arbitrary user text.
$ce = static function ($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); };
$cp = new Payment();
$lastCorrection = Database::getInstance()->fetch("SELECT a.created_at,u.full_name FROM activity_log a LEFT JOIN users u ON u.id=a.user_id WHERE a.action='invoice_admin_corrected' AND a.table_name='case_invoices' AND a.record_id=? ORDER BY a.id DESC LIMIT 1",[$tid]);
?>
<form class="ic-editor" data-correction-for="<?= $tid ?>" hidden novalidate>
    <script type="application/json" class="ic-seed"><?= json_encode($correctionState, JSON_UNESCAPED_UNICODE|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?></script>
    <div class="ic-heading">
        <div><strong><i class="fas fa-pen-to-square"></i> دەستکاری وەسڵ</strong><p>گۆڕانکارییەکان تەنها بە دوگمەی پاشەکەوت تۆمار دەکرێن.</p></div>
        <span class="ic-admin"><i class="fas fa-user-shield"></i> ئەدمین</span>
    </div>
    <?php if ($lastCorrection): ?><p class="ic-last">دوایین دەستکاری: <?= $ce($lastCorrection['full_name']) ?> · <bdi><?= $ce($lastCorrection['created_at']) ?></bdi></p><?php endif; ?>
    <?php if ($correctionState['plans']): ?><p class="ic-notice">ئەم وەسڵە پلانی قیستی هەیە. بۆ گۆڕینی بڕی وەسڵ یان پێشەکی، سەرەتا پلانەکە ڕێکبخە. تێبینی و وردەکارییەکان دەتوانیت بگۆڕیت.</p><?php endif; ?>
    <div class="ic-fields">
        <label>ژمارەی وەسڵ<input name="invoice_no" maxlength="60" value="<?= $ce($tab['invoice_no']) ?>" dir="ltr" required></label>
        <label>بەرواری وەسڵ<input type="date" name="work_date" value="<?= $ce($tab['work_date']) ?>" max="<?= date('Y-m-d') ?>" required></label>
        <label>تێبینی وەسڵ<textarea name="notes" maxlength="10000" rows="2"><?= $ce($tab['notes'] ?? '') ?></textarea></label>
        <label>تێبینی ژمێریاری<textarea name="accounting_notes" maxlength="10000" rows="2"><?= $ce($tab['accounting_notes'] ?? '') ?></textarea></label>
    </div>
    <div class="ic-section-title"><strong>چارەسەرەکان</strong><button type="button" class="vt-btn" data-ic-add><i class="fas fa-plus"></i> زیادکردنی ڕیز</button></div>
    <div class="ic-table-wrap"><table class="ic-table">
        <thead><tr><th>ناوەڕۆک</th><th>ددان (FDI)</th><th>شوێن</th><th>ژمارە</th><th>نرخی تاک</th><th>داشکاندنی ڕیز</th><th>کۆ</th><th>تێبینی</th><th></th></tr></thead>
        <tbody data-ic-rows></tbody>
    </table></div>
    <div class="ic-summary">
        <div class="ic-fields">
            <label>داشکاندنی وەسڵ<span class="ic-discount"><input type="number" name="discount_value" min="0" step="0.01" value="<?= $ce(($tab['discount_mode'] ?? '') === 'percent' ? $tab['discount_value'] : ($tab['discount_amount'] ?? 0)) ?>" required><select name="discount_mode"><option value="amount">IQD</option><option value="percent" <?= ($tab['discount_mode'] ?? '') === 'percent' ? 'selected' : '' ?>>%</option></select></span></label>
            <label>نەقدی وەرگیراوی ناو وەسڵ<input type="number" name="received_amount" min="0" step="0.01" value="<?= $ce($tab['draft_received_amount'] ?? 0) ?>" required><small>قەبزەکانی دواتر لە خوارەوە جیاوازن.</small></label>
            <?php if (!empty($case['is_historical'])): ?>
            <label>دراوی پێشوو<input type="number" name="prior_paid_amount" min="0" step="0.01" value="<?= $ce($tab['prior_paid_amount'] ?? 0) ?>" required><small>دراوی پێش سیستەم؛ ناچێتە ناو سندوق.</small></label>
            <?php else: ?><input type="hidden" name="prior_paid_amount" value="0"><?php endif; ?>
        </div>
        <div class="ic-preview" aria-live="polite">
            <span>کۆی چارەسەرەکان <b data-ic-total>0</b></span>
            <span>دوای داشکاندن <b data-ic-net>0</b></span>
            <span>کۆی وەرگیراو <b data-ic-paid>0</b></span>
            <span class="ic-balance">ماوە <b data-ic-remaining>0</b></span>
            <?php if ((float)($tab['advance_paid_amount'] ?? 0) > 0): ?><small>ئامانەتی دانراو: <?= number_format($tab['advance_paid_amount'],2) ?> — پارەی پێشتر وەرگیراوە.</small><?php endif; ?>
        </div>
    </div>
    <?php if ($correctionState['payments']): ?>
    <strong>قەبزە پەیوەندیدارەکان</strong>
    <p class="ic-help">بڕی قەبز بڕی تەواوی قەبزەکەیە. ئەگەر بۆ چەند وەسڵ دابەش کرابێت، دابەشکردنەکەش نوێ دەکرێتەوە.</p>
    <div class="ic-table-wrap"><table class="ic-table ic-payments"><thead><tr><th>قەبز</th><th>بەروار</th><th>بڕی تەواوی قەبز</th><th>شێوازی پارەدان</th><th>لەم وەسڵە</th></tr></thead><tbody>
    <?php foreach ($correctionState['payments'] as $p):
        $block = $p['status'] !== 'approved' ? 'قەبزەکە پەسەند نەکراوە؛ لە بەشی قەبزەکان بەڕێوەی ببە.' : $cp->approvedEditBlockReason($p);
    ?>
        <tr data-ic-payment="<?= (int)$p['id'] ?>" data-ic-editable="<?= $block === null ? '1' : '0' ?>">
            <td><bdi><?= $ce($p['payment_number']) ?></bdi><?php if ($block): ?><small><?= $ce($block) ?></small><?php endif; ?></td>
            <td><input type="date" data-p-field="payment_date" value="<?= $ce(substr($p['payment_date'],0,10)) ?>" max="<?= date('Y-m-d') ?>" <?= $block ? 'disabled' : '' ?> required></td>
            <td><input type="number" data-p-field="amount" min="0.01" step="0.01" value="<?= $ce($p['amount']) ?>" <?= $block ? 'disabled' : '' ?> required></td>
            <td><select data-p-field="payment_method" <?= $block ? 'disabled' : '' ?>><?php if ($p['payment_method'] === 'advance'): ?><option value="advance" selected>لە ئامانەت</option><?php endif; ?><?php foreach (['cash'=>'نەقد','card'=>'کارت','transfer'=>'گواستنەوە','other'=>'هی تر'] as $method=>$label): ?><option value="<?= $method ?>" <?= $p['payment_method'] === $method ? 'selected' : '' ?>><?= $label ?></option><?php endforeach; ?></select></td>
            <td><bdi><?= number_format($p['allocated_amount'],2) ?></bdi><small><?= $p['status'] === 'approved' ? 'پەسەندکراو' : $ce($p['status']) ?></small></td>
        </tr>
    <?php endforeach; ?></tbody></table></div>
    <?php endif; ?>
    <div class="ic-error" role="alert" hidden></div>
    <div class="ic-bottom">
        <label>هۆکاری دەستکاریکردن<input name="reason" maxlength="500" placeholder="بۆ نموونە: ڕاستکردنەوەی نرخی چارەسەر" required></label>
        <div class="ic-buttons"><button type="submit" class="vt-btn vt-btn--approve" data-ic-save><i class="fas fa-check"></i> پاشەکەوتکردن</button><button type="button" class="vt-btn" data-ic-cancel>هەڵوەشاندنەوە</button></div>
    </div>
</form>
