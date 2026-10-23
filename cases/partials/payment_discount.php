<?php
$paymentDiscountDraft = !$canReceiveMoney ? ($doctorPaymentDraft ?? []) : [];
$paymentDiscountValue = (float)($paymentDiscountDraft['discount_value'] ?? 0);
?>
<div class="payment-settlement-discount" id="paymentDiscountFields" data-available="<?= (float)$paymentDialogMax ?>">
    <div class="payment-form-grid">
        <div class="payment-form-field">
            <label for="paymentDiscountValue"><i class="fas fa-percent"></i> داشکاندنی زیاتر</label>
            <input type="number" name="discount_value" id="paymentDiscountValue" min="0" step="0.01" value="<?= $paymentDiscountValue ?>">
        </div>
        <div class="payment-form-field">
            <label for="paymentDiscountMode">جۆری داشکاندن</label>
            <select name="discount_mode" id="paymentDiscountMode">
                <option value="amount">بڕ (IQD)</option>
                <option value="percent" <?= ($paymentDiscountDraft['discount_mode'] ?? '') === 'percent' ? 'selected' : '' ?>>ڕێژە ٪</option>
            </select>
        </div>
    </div>
    <p class="payment-discount-hint">داشکاندنی زیاتر لە قەرزی بەردەست؛ ڕێژەش لە هەمان قەرز هەژمار دەکرێت. <?= $canReceiveMoney ? 'پارە و داشکاندن پێکەوە تۆمار دەکرێن.' : 'پارە و داشکاندن پێکەوە بۆ بەڕێوەبەر / ژمێریار دەنێردرێن؛ تا پەسەندکردن قەرز ناگۆڕێت.' ?></p>
    <div class="payment-form-field" id="paymentDiscountReasonField" hidden>
        <label for="paymentDiscountReason">هۆکاری داشکاندن <span class="required">*</span></label>
        <input type="text" name="discount_reason" id="paymentDiscountReason" maxlength="500" value="<?= htmlspecialchars($paymentDiscountDraft['discount_reason'] ?? '', ENT_QUOTES, 'UTF-8') ?>" placeholder="هۆکاری داشکاندن بنووسە...">
    </div>
    <div class="payment-discount-preview" aria-live="polite">
        <div><span>داشکاندن</span><strong id="paymentDiscountAmount">0</strong></div>
        <div><span>قەرزی بەردەست دوای پارە و داشکاندن</span><strong id="paymentDiscountRemaining">0</strong></div>
    </div>
</div>
<style>
.payment-settlement-discount { margin:14px 0; padding:12px; border:1px solid #d5e5d7; border-radius:10px; background:#f5faf5; }
.payment-settlement-discount .payment-form-grid { grid-template-columns:1fr 1fr; }
.payment-form-grid.payment-form-grid-doctor { grid-template-columns:1fr; }
.payment-discount-hint { font-size:12px; line-height:1.8; margin:6px 0 10px; color:#526455; }
.payment-discount-preview { display:grid; gap:6px; font-size:12px; margin-top:8px; }
.payment-discount-preview div { display:flex; justify-content:space-between; gap:10px; }
.payment-discount-preview strong { direction:ltr; }
.payment-dialog-modern { max-height:92vh; display:flex; flex-direction:column; }
.payment-dialog-modern #paymentForm { display:flex; flex-direction:column; min-height:0; overflow:hidden; }
.payment-dialog-modern .payment-dialog-body { overflow-y:auto; min-height:0; }
.payment-dialog-modern .payment-dialog-header, .payment-dialog-modern .payment-dialog-footer { flex-shrink:0; }
</style>
<script>
(() => {
    const box = document.getElementById('paymentDiscountFields');
    const cash = document.getElementById('payment_amount_input');
    const value = document.getElementById('paymentDiscountValue');
    const mode = document.getElementById('paymentDiscountMode');
    const reason = document.getElementById('paymentDiscountReason');
    const available = Number(box.dataset.available);
    const format = n => new Intl.NumberFormat('en-US', {maximumFractionDigits:2}).format(n);
    let lastCashMax = Math.max(0, available - <?= (float)($paymentDiscountDraft['discount_amount'] ?? 0) ?>);
    function update(adjustCash) {
        const discount = Math.round((mode.value === 'percent' ? available * Number(value.value) / 100 : Number(value.value)) * 100) / 100;
        const max = Math.max(0, Math.round((available - discount) * 100) / 100);
        // Keep partial cash as entered; a full-balance collection follows the discount.
        if (adjustCash && Math.abs(Number(cash.value) - lastCashMax) < 0.009) { cash.value = max > 0 ? max : ''; }
        lastCashMax = max;
        cash.max = max;
        value.max = mode.value === 'percent' ? 100 : available;
        const invalid = !Number.isFinite(discount) || discount < 0 || Number(value.value) < 0
            || discount + Number(cash.value) > available + 0.009;
        value.setCustomValidity(invalid ? 'کۆی پارە و داشکاندن لە قەرزی بەردەست زیاترە' : '');
        document.getElementById('paymentDiscountReasonField').hidden = !(discount > 0);
        reason.required = discount > 0;
        document.getElementById('paymentDiscountAmount').textContent = format(discount || 0);
        document.getElementById('paymentDiscountRemaining').textContent = format(Math.max(0, available - discount - Number(cash.value)));
    }
    value.addEventListener('input', () => update(true));
    mode.addEventListener('change', () => update(true));
    cash.addEventListener('input', () => update(false));
    document.getElementById('paymentForm').addEventListener('submit', () => update(false));
    update(false);
})();
</script>
