<?php
/**
 * One visit-tab pane: the receipt header strip + its treatments table.
 *
 * Rendered in two places and MUST stay one source of truth:
 *   1. cases/view.php page load — inside the tab loop
 *   2. the AJAX response after adding/deleting work, so only this pane is
 *      swapped instead of reloading the whole page
 *
 * Expects (from the including scope):
 *   $tab             invoice row (+ fifo_covered / fifo_remaining / pending_amount
 *                    / treatment_count)
 *   $rows            that invoice's case_treatments rows
 *   $case, $isAdmin, $isDoctor, $dentalChart, $palmerNumbers, $toothTreatments
 *   $canReceiveMoney true for the roles that book money without approval
 *                    (admin, accountant) — the doctor may also write
 *                    وەرگیراو on their own draft/sent receipt, but it becomes
 *                    a REQUEST (Payment::createRequest), never booked money,
 *                    until an accountant approves it — see $canSetPaid below
 *   $showInvoiceActions  render the پەسەندکردن / پلانی قیست header buttons.
 *                    Only the accountant screen sets it, because only that
 *                    page has the handlers behind them — an admin on
 *                    cases/view.php must not be shown buttons that go nowhere.
 *   $planFor         (optional) [invoice_id => plan row] active instalment
 *                    plans, keyed by the receipt they were agreed against
 *   $planEligibility (optional) [invoice_id => CaseInvoice::installmentEligibility()]
 *   $activeInvoiceId which pane starts visible (ignored when $paneAlwaysVisible)
 *   $canDeleteInvoices optional admin deletion control on cases/view.php
 */

$tid      = (int)$tab['id'];
$ipMoney = static function ($value) {
    $value = round((float)$value, 2);
    return number_format($value, abs($value - round($value)) > 0.0001 ? 2 : 0);
};
$accountingStatus = $tab['accounting_status'] ?? ($tab['status'] === 'draft' ? 'draft' : 'approved');
$isDraft  = in_array($accountingStatus, ['draft', 'returned'], true);
// A sent receipt is the accountant's document: frozen for everyone unless the
// admin escape hatch is switched on in admin/settings.php?tab=access.
$sentEditable = $accountingStatus === 'sent' && $isAdmin && Settings::allowEditSentInvoice();
$approvedEditable = $accountingStatus === 'approved' && !empty($canEditApprovedTreatments);
// The doctor's own correction right (permission cases.edit_sent_invoice): the
// work on his sent/approved invoice — never its received money.
$doctorEditable = !empty($doctorCanEditSent) && in_array($accountingStatus, ['sent', 'approved'], true);
$editable = $case['status'] === 'open' && ($isDraft || $sentEditable || $approvedEditable || $doctorEditable);
$panePendingPlan = !empty($pendingOrRejectedPlan)
    && ($pendingOrRejectedPlan['status'] ?? '') === 'pending'
    && (int)($pendingOrRejectedPlan['invoice_id'] ?? 0) === $tid
    ? $pendingOrRejectedPlan
    : null;
$quadKeys = ['UR' => 'upper_right', 'UL' => 'upper_left', 'LR' => 'lower_right', 'LL' => 'lower_left'];

// Group by treatment + price + clinical location. Whole-arch and service work
// intentionally stays outside the tooth quadrants: it is one billable job,
// not a hidden list of synthetic teeth.
$groups = [];
foreach ($rows as $ct) {
    if (($ct['status'] ?? '') === 'cancelled') continue;
    $archType = in_array(($ct['arch_type'] ?? 'none'), ['upper', 'lower'], true)
        ? $ct['arch_type'] : 'none';
    // Any treatment may be saved with or without a tooth; the row decides.
    $requiresTooth = $archType === 'none' && !empty($ct['tooth_number']);
    $key = ($ct['treatment_id'] ?? 0) . '_' . $ct['treatment_name'] . '_' . $ct['unit_price']
        . '_' . $archType . '_' . ($requiresTooth ? 'tooth' : 'service');
    if (!isset($groups[$key])) {
        $groups[$key] = [
            'group_uid' => 'tg-' . (int)$ct['id'],
            'treatment_id' => (int)($ct['treatment_id'] ?? 0),
            'treatment_name' => $ct['treatment_name'],
            'unit_price'     => $ct['unit_price'],
            'arch_type' => $archType,
            'requires_tooth' => $requiresTooth,
            'upper_left' => [], 'upper_right' => [], 'lower_left' => [], 'lower_right' => [],
            'upper_left_fdi' => [], 'upper_right_fdi' => [], 'lower_left_fdi' => [], 'lower_right_fdi' => [],
            'total_quantity' => 0, 'total_price' => 0, 'discount' => 0, 'treatment_ids' => [],
        ];
    }
    if ($requiresTooth && $ct['tooth_number']) {
        $q = $dentalChart->getQuadrant($ct['tooth_number']);
        if (isset($quadKeys[$q])) {
            $groups[$key][$quadKeys[$q]][] = $palmerNumbers[$ct['tooth_number']] ?? $ct['tooth_number'];
            $groups[$key][$quadKeys[$q] . '_fdi'][] = $ct['tooth_number'];
        }
    }
    $groups[$key]['total_quantity'] += $ct['quantity'];
    $groups[$key]['total_price']    += $ct['final_price'];
    $groups[$key]['discount']       += $ct['discount'];
    $groups[$key]['treatment_ids'][] = $ct['id'];
}
$paneHidden = !empty($paneAlwaysVisible) ? false : ($tid !== $activeInvoiceId);
?>
<div class="vt-pane" data-invoice-pane="<?= $tid ?>" <?= $paneHidden ? 'hidden' : '' ?>>

    <div class="vt-head vt-head--<?= $tab['status'] ?>">
        <div class="vt-head-main">
            <span class="vt-head-no" dir="ltr"><?= htmlspecialchars($tab['invoice_no']) ?></span>
            <span class="vt-head-date"><i class="fas fa-calendar-day"></i> <?= htmlspecialchars($tab['work_date']) ?></span>
            <?php
            // Whose work this receipt is (the doctor's cashbox and income go by
            // it), and who really made it when that was not the doctor — an
            // admin or accountant filing it for him. Anyone but a doctor may
            // re-pick the doctor until the receipt is approved.
            $paneDoctorId = (int)($tab['doctor_id'] ?? 0);
            $paneMadeBy = (int)($tab['created_by'] ?? 0);
            $paneCanSetDoctor = empty($isDoctor) && empty($showInvoiceActions) && $case['status'] === 'open'
                && in_array($accountingStatus, ['draft', 'returned', 'sent'], true) && !empty($invoiceDoctors);
            ?>
            <?php if ($paneCanSetDoctor): ?>
            <label class="vt-head-badge is-doctor is-pick" title="پارەی ئەم وەسڵە دەچێتە سەر ئەم دکتۆرە — سندوق و ڕێژە">
                <i class="fas fa-user-doctor"></i>
                <select onchange="setInvoiceDoctor(<?= $tid ?>, this.value, this)" data-current="<?= $paneDoctorId ?>" aria-label="دکتۆری وەسڵ">
                    <?php if (!$paneDoctorId): ?><option value="">— دکتۆر هەڵبژێرە —</option><?php endif; ?>
                    <?php foreach ($invoiceDoctors as $invDoc): ?>
                    <option value="<?= (int)$invDoc['id'] ?>"<?= (int)$invDoc['id'] === $paneDoctorId ? ' selected' : '' ?>><?= htmlspecialchars($invDoc['full_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <?php elseif (empty($isDoctor) && !empty($tab['doctor_name'])): ?>
            <span class="vt-head-badge is-doctor"><i class="fas fa-user-doctor"></i> <?= htmlspecialchars($tab['doctor_name']) ?></span>
            <?php endif; ?>
            <?php if ($paneMadeBy > 0 && $paneMadeBy !== $paneDoctorId && !empty($tab['created_by_name'])): ?>
            <span class="vt-head-badge is-madeby" title="ئەم وەسڵە دکتۆر خۆی دروستی نەکردووە">
                <i class="fas fa-user-pen"></i> لەلایەن <?= htmlspecialchars($tab['created_by_name']) ?> دروستکراوە
            </span>
            <?php endif; ?>
            <?php if (!empty($case['is_historical'])): ?>
            <span class="vt-head-badge is-historical" title="کاری پێش سیستەم — دراوی پێشوو ناچێتە ناو داهاتی سندوق"><i class="fas fa-history"></i> کەیسی کۆن</span>
            <?php endif; ?>
            <?php if ($accountingStatus === 'returned'): ?>
            <span class="vt-head-badge is-part"><i class="fas fa-rotate-left"></i> گەڕێندراوەتەوە بۆ ڕاستکردنەوە</span>
            <?php if (!empty($tab['return_reason'])): ?><span class="vt-head-badge is-pending" title="<?= htmlspecialchars($tab['return_reason']) ?>"><?= htmlspecialchars(mb_strimwidth($tab['return_reason'], 0, 48, '…')) ?></span><?php endif; ?>
            <?php elseif ($isDraft): ?>
            <span class="vt-head-badge is-draft"><i class="fas fa-pen-ruler"></i> نەنێردراو</span>
            <?php elseif ($tab['status'] === 'processed'): ?>
            <span class="vt-head-badge is-done"><i class="fas fa-check-double"></i> پارە وەرگیراوە</span>
            <?php if ($doctorEditable): ?>
            <span class="vt-head-badge is-locked" title="دەتوانیت کار و نرخ ڕاست بکەیتەوە؛ پارەی وەرگیراو ناگۆڕدرێت"><i class="fas fa-lock"></i> پارەکەی قوفڵە</span>
            <?php elseif (!$sentEditable): ?>
            <span class="vt-head-badge is-locked" title="دوای ناردن ناتوانرێت بگۆڕدرێت"><i class="fas fa-lock"></i> قوفڵدراو</span>
            <?php endif; ?>
            <?php else: ?>
            <span class="vt-head-badge is-sent"><i class="fas fa-paper-plane"></i> لای ژمێریار</span>
            <?php if ($doctorEditable): ?>
            <span class="vt-head-badge is-locked" title="دەتوانیت کار و نرخ ڕاست بکەیتەوە؛ پارەی وەرگیراو ناگۆڕدرێت"><i class="fas fa-lock"></i> پارەکەی قوفڵە</span>
            <?php elseif (!$sentEditable): ?>
            <span class="vt-head-badge is-locked" title="دوای ناردن ناتوانرێت بگۆڕدرێت"><i class="fas fa-lock"></i> قوفڵدراو</span>
            <?php endif; ?>
            <?php if (($tab['fifo_remaining'] ?? 0) > 0.009): ?>
            <span class="vt-head-badge is-part">ماوە <?= $ipMoney($tab['fifo_remaining']) ?></span>
            <?php endif; ?>
            <?php if (($tab['pending_amount'] ?? 0) > 0.009): ?>
            <span class="vt-head-badge is-pending" title="داواکاری پارەدانی دکتۆر — چاوەڕوانی پەسەندکردن">
                <i class="fas fa-hourglass-half"></i> چاوەڕوانی ژمێریار <?= $ipMoney($tab['pending_amount']) ?>
            </span>
            <?php endif; ?>
            <?php if (($tab['payment_intent'] ?? '') === 'installment'): ?>
            <span class="vt-head-badge is-plan"><i class="fas fa-credit-card"></i>
                <?= $panePendingPlan ? 'پلانی قیست ـ چاوەڕوان' : 'پلانی قیست' ?>
                <?php if ($panePendingPlan && (float)($panePendingPlan['proposed_down_payment'] ?? 0) > 0): ?>
                    · پێشەکی <?= $ipMoney($panePendingPlan['proposed_down_payment']) ?>
                <?php endif; ?>
            </span>
            <?php elseif (($tab['payment_intent'] ?? '') === 'debt'): ?>
            <span class="vt-head-badge is-part"><i class="fas fa-hand-holding-usd"></i> قەرزی تەواو</span>
            <?php endif; ?>
            <?php endif; ?>
            <?php if ((float)($tab['draft_received_amount'] ?? 0) > 0.009): ?>
            <span class="vt-head-badge is-part"><i class="fas fa-file-invoice-dollar"></i> وەرگیراوی ناو وەسڵ <?= $ipMoney($tab['draft_received_amount']) ?></span>
            <?php endif; ?>
        </div>
        <div class="vt-head-actions">
            <span class="vt-head-total"><?= $ipMoney($tab['total_amount']) ?></span>
            <?php if ($isAdmin && !empty($canDeleteInvoices) && $case['status'] === 'open' && $accountingStatus !== 'cancelled'): ?>
            <button type="button" class="vt-btn vt-btn--edit" data-admin-edit-invoice="<?= $tid ?>" aria-expanded="false">
                <i class="fas fa-pen-to-square"></i> دەستکاری <span lang="en">Edit</span>
            </button>
            <?php endif; ?>
            <?php if ($isAdmin && !empty($canDeleteInvoices)): ?>
            <button type="button" class="vt-btn vt-btn--del" title="سڕینەوەی پسووڵە و هەموو تۆمارە پەیوەندیدارەکانی"
                onclick="openDeleteInvoiceDialog(<?= $tid ?>, <?= htmlspecialchars(json_encode($tab['invoice_no'], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP), ENT_QUOTES, 'UTF-8') ?>)">
                <i class="fas fa-trash"></i> سڕینەوەی پسووڵە
            </button>
            <?php endif; ?>
            <button type="button" class="vt-btn" onclick="openReceiptDialog(<?= $tid ?>)" title="چاپکردنی ئەم وەسڵە">
                <i class="fas fa-print"></i> چاپ
            </button>
            <?php
            // Accountant-side actions. A doctor never sees these — approving
            // money and agreeing instalments are the accountant's job.
            if (!empty($showInvoiceActions) && !$isDraft && $case['status'] === 'open'):
                $invPlan   = $planFor[$tid] ?? null;
                $invElig   = $planEligibility[$tid] ?? null;
                // What this receipt is waiting on the accountant to confirm.
                $toApprove = (float)($tab['pending_amount'] ?? 0);
            ?>
            <button type="button" class="vt-btn vt-btn--approve"
                    onclick="approveInvoice(<?= $tid ?>, <?= htmlspecialchars(json_encode($tab['invoice_no']), ENT_QUOTES) ?>, <?= json_encode($toApprove) ?>)"
                    title="پەسەندکردنی ئەم وەسڵە و مارککردنی وەک بینراو">
                <i class="fas fa-circle-check"></i> پەسەندکردن<?= $toApprove > 0.009 ? ' ' . $ipMoney($toApprove) : '' ?>
            </button>
            <?php if ($invPlan): ?>
            <span class="vt-head-badge is-plan" title="ئەم وەسڵە پلانی قیستی هەیە">
                <i class="fas fa-credit-card"></i> قیست
            </span>
            <?php elseif ($invElig && $invElig['allowed']): ?>
            <button type="button" class="vt-btn vt-btn--plan"
                    onclick="openInvoiceInstallment(<?= $tid ?>, <?= htmlspecialchars(json_encode($tab['invoice_no']), ENT_QUOTES) ?>, <?= json_encode($invElig['remaining']) ?>)">
                <i class="fas fa-credit-card"></i> پلانی قیست
            </button>
            <?php elseif ($invElig && $invElig['message'] !== ''): ?>
            <button type="button" class="vt-btn" disabled title="<?= htmlspecialchars($invElig['message']) ?>">
                <i class="fas fa-credit-card"></i> پلانی قیست
            </button>
            <?php endif; ?>
            <?php endif; ?>
            <?php
            // Doctor-side undo of a send: only while the receipt sits unapproved
            // with the accountant and no money was booked or requested on it.
            // CaseInvoice::recall() re-checks all of this server-side.
            $canRecall = empty($showInvoiceActions) && (!empty($isDoctor) || $isAdmin)
                && $case['status'] === 'open' && $accountingStatus === 'sent'
                && $tab['status'] !== 'processed'
                && (float)($tab['fifo_covered'] ?? 0) <= 0.009
                && (float)($tab['pending_amount'] ?? 0) <= 0.009;
            if ($canRecall): ?>
            <form method="POST" style="display:inline;"
                  onsubmit="return confirm('ناردنی ئەم وەسڵە بۆ ژمێریار کانسڵ بکرێتەوە؟\nوەسڵەکە دەگەڕێتەوە بۆ ڕەشنووس و دەتوانیت دەستکاری بکەیت و دووبارە بینێریتەوە.')">
                <?= Csrf::field() ?>
                <input type="hidden" name="action" value="recall_invoice">
                <input type="hidden" name="invoice_id" value="<?= $tid ?>">
                <button type="submit" class="vt-btn vt-btn--recall" title="کانسڵکردنەوەی ناردن بۆ ژمێریار بۆ دەستکاریکردن">
                    <i class="fas fa-rotate-left"></i> کانسڵکردنی ناردن
                </button>
            </form>
            <?php endif; ?>
            <?php if ($isDraft && $case['status'] === 'open'): ?>
            <button type="button" class="vt-btn vt-btn--send"
                    onclick="openSendInvoiceDialog(<?= $tid ?>, <?= htmlspecialchars(json_encode($tab['invoice_no']), ENT_QUOTES) ?>, <?= (int)$tab['treatment_count'] ?>, <?= json_encode((float)$tab['total_amount']) ?>, <?= htmlspecialchars(json_encode(($tab['discount_mode'] ?? '') === 'percent' ? 'percent' : 'amount'), ENT_QUOTES) ?>, <?= json_encode((float)($tab['discount_value'] ?? $tab['discount_amount'] ?? 0)) ?>, <?= json_encode((float)($tab['fifo_covered'] ?? 0)) ?>, <?= json_encode((float)($tab['draft_received_amount'] ?? 0)) ?>, <?= json_encode(!empty($case['is_historical']) ? (float)($tab['prior_paid_amount'] ?? 0) : 0) ?>)">
                <i class="fas fa-paper-plane"></i> ناردن بۆ ژمێریار
            </button>
            <?php endif; ?>
        </div>
    </div>

    <div class="table-container">
        <table class="table rcpt-table">
            <thead>
                <tr>
                    <th class="rt-c-no">ژ</th>
                    <th class="rt-c-desc">ناوەڕۆک<span class="en">Description</span></th>
                    <th class="rt-c-teeth">چارتی ددان<span class="en">Teeth</span></th>
                    <th class="rt-c-qty">ژمارە<span class="en">Quantity</span></th>
                    <th class="rt-c-unit">نرخی تاک<span class="en">Unit Price</span></th>
                    <th class="rt-c-total">نرخی کۆ<span class="en">Total Price</span></th>
                    <?php if ($case['status'] === 'open'): ?>
                    <th class="rt-c-act">کردارەکان</th>
                    <?php endif; ?>
                </tr>
            </thead>
            <tbody>
                <?php $rn = 1; foreach ($groups as $gt): ?>
                <tr data-treatment-group="<?= htmlspecialchars($gt['group_uid']) ?>">
                    <td class="rt-c-no"><?= $rn++ ?></td>
                    <td class="rt-c-desc"><?= htmlspecialchars($gt['treatment_name']) ?></td>
                    <td class="rt-c-teeth">
                        <?php if ($gt['arch_type'] === 'upper'): ?>
                        <span class="rt-location-badge is-upper"><i class="fas fa-teeth-open"></i> ماکزیلا / سەرەوە</span>
                        <?php elseif ($gt['arch_type'] === 'lower'): ?>
                        <span class="rt-location-badge is-lower"><i class="fas fa-teeth-open"></i> ماندیبل / خوارەوە</span>
                        <?php elseif (!$gt['requires_tooth']): ?>
                        <span class="rt-location-badge is-service"><i class="fas fa-briefcase-medical"></i> بێ ژمارەی ددان</span>
                        <?php elseif ($gt['upper_right'] || $gt['upper_left'] || $gt['lower_right'] || $gt['lower_left']): ?>
                        <div class="tooth-cross">
                            <span class="tc-ur"><?= htmlspecialchars(implode(',', $gt['upper_right'])) ?></span>
                            <span class="tc-ul"><?= htmlspecialchars(implode(',', $gt['upper_left'])) ?></span>
                            <span class="tc-lr"><?= htmlspecialchars(implode(',', $gt['lower_right'])) ?></span>
                            <span class="tc-ll"><?= htmlspecialchars(implode(',', $gt['lower_left'])) ?></span>
                        </div>
                        <?php endif; ?>
                    </td>
                    <td class="rt-c-qty"><?= number_format($gt['total_quantity']) ?></td>
                    <td class="rt-c-unit"><?= $ipMoney($gt['unit_price']) ?></td>
                    <td class="rt-c-total"><?= $ipMoney($gt['total_price']) ?></td>
                    <?php if ($case['status'] === 'open'): ?>
                    <td class="rt-c-act">
                        <?php if ($editable): ?>
                        <span class="rt-row-actions">
                            <button type="button" class="rt-row-action is-edit"
                                    onclick="editGroupedTreatment(<?= htmlspecialchars(json_encode($gt)) ?>)" title="دەستکاری">
                                <i class="fas fa-pen"></i>
                            </button>
                            <button type="button" class="rt-row-action is-delete"
                                    onclick="rtDeleteGroup(<?= $tid ?>, <?= htmlspecialchars(json_encode($gt['treatment_ids']), ENT_QUOTES) ?>, <?= htmlspecialchars(json_encode($gt['treatment_name']), ENT_QUOTES) ?>)"
                                    title="سڕینەوە">
                                <i class="fas fa-trash-alt"></i>
                            </button>
                        </span>
                        <?php else: ?>
                        <i class="fas fa-lock" style="color:#a9b6c3;" title="وەسڵی بۆ نێردراوە — تەنها بەڕێوەبەر دەتوانێت"></i>
                        <?php endif; ?>
                    </td>
                    <?php endif; ?>
                </tr>
                <?php endforeach; ?>

                <?php if ($isDraft && $case['status'] === 'open'): ?>
                <!-- Quick-entry row, one per draft tab -->
                <tr class="rt-add-row" data-add-for="<?= $tid ?>">
                    <td class="rt-c-no"><i class="fas fa-plus" style="color:var(--win-accent, #0078D4);"></i></td>
                    <td class="rt-c-desc">
                        <?php if (empty($toothTreatments)): ?>
                        <button type="button" class="btn btn-sm" onclick="openManageTreatmentsDialog()"
                                style="width:100%;font-size:12px;background:#fff7ed;border:1px dashed #fb923c;color:#9a3412;">
                            <i class="fas fa-plus-circle"></i> هیچ جۆرێکی چارەسەر نییە — زیادبکە
                        </button>
                        <?php else: ?>
                        <!-- Search + list in one: type to filter, or open the full
                             list with the caret. Options come from treatmentCatalog
                             in JS, so the markup stays small however many there are.
                             Tab order: treatment → four tooth boxes → ژمارە → نرخ. -->
                        <div class="rt-combo">
                            <input type="text" class="rt-field rt-combo-input" tabindex="1" autocomplete="off"
                                   placeholder="چارەسەر بگەڕێ یان هەڵبژێرە..." aria-label="چارەسەر">
                            <input type="hidden" class="rt-combo-id" value="">
                            <button type="button" class="rt-combo-caret" tabindex="-1" title="پیشاندانی هەموو چارەسەرەکان">
                                <i class="fas fa-chevron-down"></i>
                            </button>
                        </div>
                        <?php endif; ?>
                    </td>
                    <td class="rt-c-teeth">
                        <!-- Type Palmer numbers straight into a quadrant («5,7» = two
                             teeth) or click the chart button to pick them with the
                             mouse. Either way ژمارە follows the count. -->
                        <div class="rt-teeth-wrap">
                            <div class="tooth-cross is-input">
                                <input type="text" class="tc-ur rt-tooth-in" data-q="UR" tabindex="2" placeholder="—" title="سەرەوەی ڕاست">
                                <input type="text" class="tc-ul rt-tooth-in" data-q="UL" tabindex="3" placeholder="—" title="سەرەوەی چەپ">
                                <input type="text" class="tc-lr rt-tooth-in" data-q="LR" tabindex="4" placeholder="—" title="خوارەوەی ڕاست">
                                <input type="text" class="tc-ll rt-tooth-in" data-q="LL" tabindex="5" placeholder="—" title="خوارەوەی چەپ">
                            </div>
                            <button type="button" class="rt-teeth-btn" tabindex="-1"
                                    onclick="openVisitToothPicker(<?= $tid ?>, 'UR')"
                                    title="هەڵبژاردنی ددان لە چارتەوە"><i class="fas fa-tooth"></i></button>
                            <span class="rt-no-tooth"><i class="fas fa-briefcase-medical"></i> بێ ژمارەی ددان</span>
                        </div>
                    </td>
                    <td class="rt-c-qty">
                        <input type="number" class="rt-field rt-in-qty is-auto" value="1" min="1" step="1" tabindex="6"
                               title="خۆکار لە ژمارەی ددانەکانەوە — دەتوانیت بیگۆڕیت"
                               oninput="rtQtyTouched(<?= $tid ?>)" onchange="rtCalcTotal(<?= $tid ?>)">
                    </td>
                    <td class="rt-c-unit">
                        <input type="number" class="rt-field rt-in-price" value="0" min="0" step="0.01" tabindex="7"
                               oninput="rtCalcTotal(<?= $tid ?>)">
                    </td>
                    <td class="rt-c-total"><span class="rt-in-total">0</span></td>
                    <td class="rt-c-act">
                        <button type="button" class="rt-act-btn is-save rt-save-btn" tabindex="8"
                                onclick="rtSave(<?= $tid ?>)" title="پاشەکەوت (Enter)"><i class="fas fa-check"></i></button>
                        <button type="button" class="rt-act-btn is-clear" tabindex="-1"
                                onclick="rtClear(<?= $tid ?>)" title="پاککردنەوە (Esc)"><i class="fas fa-eraser"></i></button>
                    </td>
                </tr>
                <?php endif; ?>

                <?php
                // The paper pad has six ruled lines. Pad to six so the on-screen
                // table reads exactly like the receipt it prints to.
                $usedRows = count($groups) + (($isDraft && $case['status'] === 'open') ? 1 : 0);
                for ($i = $usedRows + 1; $i <= 6; $i++):
                ?>
                <tr class="rt-blank-row">
                    <td class="rt-c-no"><?= $i ?></td>
                    <td class="rt-c-desc">&nbsp;</td>
                    <td class="rt-c-teeth">&nbsp;</td>
                    <td class="rt-c-qty">&nbsp;</td>
                    <td class="rt-c-unit">&nbsp;</td>
                    <td class="rt-c-total">&nbsp;</td>
                    <?php if ($case['status'] === 'open'): ?><td class="rt-c-act">&nbsp;</td><?php endif; ?>
                </tr>
                <?php endfor; ?>
            </tbody>
        </table>
    </div>

    <?php
    // Totals block, same shape as the printed receipt. Every figure belongs to
    // THIS receipt — the case balance is deliberately not shown.
    $invDiscount  = (float)($tab['discount_amount'] ?? 0);
    $invNet       = max(0, (float)$tab['total_amount'] - $invDiscount);
    $invPaid      = (float)($tab['fifo_covered'] ?? 0);
    // A doctor's request that the accountant has not acted on yet. Deliberately
    // NOT added to $invPaid — it is not money until it is approved.
    $invPending   = (float)($tab['pending_amount'] ?? 0);
    // This figure belongs to the treatment invoice in every accounting state.
    // It never becomes a pending payment request.
    $invDraftReceived = (float)($tab['draft_received_amount'] ?? 0);
    // دراوی پێشوو — money paid before the system (historical cases). Shown on
    // its own line and never inside وەرگیراو, because it is not cash taken
    // here; an approved invoice's paid_amount already includes it.
    $invIsHistorical = !empty($case['is_historical']);
    $invPrior = (float)($tab['prior_paid_amount'] ?? 0);
    // Legacy custody allocations are included in received once approved;
    // unapproved allocations keep their existing draft controls below.
    $invAdvance = (float)($tab['advance_paid_amount'] ?? 0);
    // Resolved here rather than threaded in: this partial is included from two
    // different scopes in cases/view.php (the AJAX tab renderer and the page
    // render), and a caller that forgot to set it would silently hide the
    // patient's money. Memoised per patient for the life of the request, since
    // every tab on the page asks the same question.
    // (a $GLOBALS memo, not `static`: this file is included at function scope
    // in one place and at file scope in the other, and `static` is only legal
    // inside a function.)
    if (!isset($patientAdvanceAvailable)) {
        $advancePatientId = (int)($case['patient_id'] ?? 0);
        if (!isset($GLOBALS['ip_advance_balance_memo'])) {
            $GLOBALS['ip_advance_balance_memo'] = [];
        }
        if ($advancePatientId > 0 && !array_key_exists($advancePatientId, $GLOBALS['ip_advance_balance_memo'])) {
            require_once __DIR__ . '/../../classes/PatientAdvance.php';
            $GLOBALS['ip_advance_balance_memo'][$advancePatientId] =
                (new PatientAdvance())->balanceOf($advancePatientId);
        }
        $advanceAvailable = (float)($GLOBALS['ip_advance_balance_memo'][$advancePatientId] ?? 0);
    } else {
        $advanceAvailable = (float)$patientAdvanceAvailable;
    }
    $inlinePaidIsRequest = $isDraft;
    $invCovered = $invPrior + ($accountingStatus === 'approved' ? 0 : $invAdvance);
    $invEntered = $accountingStatus === 'approved'
        ? max(0, min($invNet, $invPaid) - $invCovered)
        : min(max(0, $invNet - $invCovered), max($invPaid, $invDraftReceived));
    $invRemaining = max(0, $invNet - $invEntered - $invCovered);
    // The doctor types دراوی پێشوو on their own draft of a historical case.
    // Not on the accountant screen: that page has no handler for it.
    $canSetPrior = $invIsHistorical && empty($showInvoiceActions)
        && $case['status'] === 'open' && $isDraft;
    // ئامانەت is not limited to historical cases — the patient paid at this
    // counter either way, and the doctor decides which receipt it settles.
    $canUseAdvance = in_array($currentUser['role'] ?? '', ['admin','accountant'], true)
        && empty($showInvoiceActions) && $case['status'] === 'open' && $isDraft
        && ($advanceAvailable > 0.009 || $invAdvance > 0.009);
    // Who may type into وەرگیراو. Admin/accountant book money outright, on any
    // receipt that still owes something. The doctor (page access already
    // guarantees this is their own case) may only file a REQUEST, and only
    // while the receipt is still a draft/returned document. Once sent, this
    // treatment invoice is immutable; later money uses a new receipt draft.
    $canSetPaid = $case['status'] === 'open' && $isDraft && $invRemaining > 0.009;
    ?>
    <!-- Same footer as the printed receipt: the signature/notes rules on the
         right, the boxed totals on the left. Notes column comes FIRST so RTL
         places it on the right, exactly like the paper pad. -->
    <div class="vt-foot">
        <div class="vt-foot-side">
            <div class="vt-foot-row">
                <span class="vt-foot-label">تێبینی :</span>
                <span class="vt-foot-fill"><?= htmlspecialchars($tab['notes'] ?? '') ?></span>
            </div>
            <div class="vt-foot-row">
                <span class="vt-foot-label">وەرگر :</span>
                <span class="vt-foot-fill">&nbsp;</span>
            </div>
            <div class="vt-foot-row">
                <span class="vt-foot-label">واژوو :</span>
                <span class="vt-foot-fill">&nbsp;</span>
            </div>
            <div class="vt-foot-row">
                <span class="vt-foot-label">مۆر :</span>
                <span class="vt-foot-fill">&nbsp;</span>
            </div>
        </div>

        <div class="vt-foot-totals">
            <div class="vt-total-row">
                <span class="vt-total-label">کۆی گشتی :</span>
                <span class="vt-total-box" data-invoice-total="<?= $tid ?>"><?= $ipMoney($tab['total_amount']) ?></span>
            </div>
            <?php
            // Who may re-cut the discount. A doctor: only their own draft. The
            // money roles: any receipt, because negotiating the final figure is
            // exactly what the receipt is handed over FOR — that is a money
            // term, not a rewrite of the billed work, which stays behind
            // $editable and the admin escape hatch.
            $canDiscount = $case['status'] === 'open' && (
                $isDraft || ($accountingStatus === 'sent' && ($sentEditable || $doctorEditable || !empty($canReceiveMoney)))
                // The doctor's correction right reaches an approved invoice's
                // discount too — never below what was paid (editApproved).
                || ($accountingStatus === 'approved' && $doctorEditable)
            );
            ?>
            <?php
            // How the discount was given is remembered, so a percentage keeps
            // reading as a percentage; a flat amount shows no percentage at all.
            $discMode  = ($tab['discount_mode'] ?? 'amount') === 'percent' ? 'percent' : 'amount';
            $discValue = (float)($tab['discount_value'] ?? 0);
            $discShown = $discMode === 'percent' ? $discValue : $invDiscount;
            $trimNum = function ($n) { return rtrim(rtrim(number_format($n, 2, '.', ''), '0'), '.'); };
            ?>
            <?php if ($canDiscount): ?>
            <!-- Typed straight into the box; the toggle switches between a flat
                 amount and a percentage of this receipt's total. Saves on blur
                 or Enter — no dialog, no page reload. -->
            <div class="vt-total-row">
                <span class="vt-total-label">داشکاندن :</span>
                <span class="vt-total-box vt-disc-box<?= $discMode === 'percent' ? ' is-percent' : '' ?>"
                      data-disc-for="<?= $tid ?>"
                      data-total="<?= (float)$tab['total_amount'] ?>" data-mode="<?= $discMode ?>">
                    <input type="number" class="vt-disc-input" min="0" step="0.01"
                           value="<?= $discShown > 0 ? $trimNum($discShown) : '' ?>"
                           placeholder="0"
                           onchange="rtSaveDiscount(<?= $tid ?>)"
                           oninput="rtDiscPreview(<?= $tid ?>)"
                           onkeydown="if(event.key==='Enter'){event.preventDefault();this.blur();}">
                    <button type="button" class="vt-disc-mode" onclick="rtToggleDiscMode(<?= $tid ?>)"
                            title="گۆڕین بۆ ڕێژە / پارە"><?= $discMode === 'percent' ? '%' : 'IQD' ?></button>
                </span>
                <!-- Percentage discounts also show the money they come to -->
                <span class="vt-disc-eq" data-disc-eq="<?= $tid ?>"<?= $discMode === 'percent' && $invDiscount > 0 ? '' : ' hidden' ?>>
                    = <span data-disc-eq-val="<?= $tid ?>"><?= $ipMoney($invDiscount) ?></span>
                </span>
            </div>
            <?php elseif ($invDiscount > 0): ?>
            <div class="vt-total-row">
                <span class="vt-total-label">داشکاندن<?= $discMode === 'percent' ? ' (' . $trimNum($discValue) . '%)' : '' ?> :</span>
                <span class="vt-total-box is-cut"><?= $ipMoney($invDiscount) ?></span>
            </div>
            <?php endif; ?>
            <?php if ($invDiscount > 0 || $canDiscount): ?>
            <div class="vt-total-row">
                <span class="vt-total-label">دوای داشکاندن :</span>
                <span class="vt-total-box" data-disc-net="<?= $tid ?>"><?= $ipMoney($invNet) ?></span>
            </div>
            <?php endif; ?>
            <!-- Typed straight into the box, like داشکاندن above it. The number
                 is what this box will READ afterwards, so the server works back
                 from it to the payment behind it (CaseInvoice::planPaidTarget)
                 and shows that sum for confirmation before anything is booked.
                  On a draft it saves only on the invoice itself — no payment
                  request is created. -->
            <div class="vt-total-row">
                <span class="vt-total-label">وەرگیراو :</span>
                <?php if ($canSetPaid): ?>
                <span class="vt-total-box vt-paid-box" data-paid-for="<?= $tid ?>"
                      data-net="<?= $invNet ?>" data-covered="<?= $invEntered ?>" data-prior="<?= $invPrior ?>"
                      data-approved="<?= $invPaid ?>" data-is-request="<?= $inlinePaidIsRequest ? 1 : 0 ?>"
                      data-draft="<?= $isDraft ? 1 : 0 ?>">
                    <input type="number" class="vt-paid-input" min="0" step="0.01"
                           value="<?= $invEntered > 0.009 ? $trimNum($invEntered) : '' ?>"
                           placeholder="0"
                           title="بڕی وەرگیراوی ئەم وەسڵە بنووسە"
                           onchange="rtSavePaid(<?= $tid ?>)"
                           oninput="rtPaidPreview(<?= $tid ?>)"
                           onkeydown="if(event.key==='Enter'){event.preventDefault();this.blur();}">
                </span>
                <?php else: ?>
                <span class="vt-total-box is-paid" data-paid-for="<?= $tid ?>"
                      data-net="<?= $invNet ?>" data-covered="<?= $invEntered ?>" data-prior="<?= $invPrior ?>"><?= $ipMoney($invEntered) ?></span>
                <?php endif; ?>
                <?php if ($invPending > 0.009): ?>
                <span class="vt-paid-pending" title="داواکاری پارەدانی دکتۆر — چاوەڕوانی پەسەندکردنی ژمێریار">
                    <i class="fas fa-hourglass-half"></i> <?= $ipMoney($invPending) ?>
                </span>
                <?php endif; ?>
            </div>
            <?php if ((float)($tab['advance_received_amount'] ?? 0) > 0.009): ?>
            <div class="vt-received-source">لەوانە <?= $ipMoney($tab['advance_received_amount']) ?> لە ئەمانەت گوازراوەتەوە</div>
            <?php endif; ?>
            <?php if ($invIsHistorical || $invPrior > 0.009): ?>
            <!-- دراوی پێشوو: paid before the system. Lowers ماوە like وەرگیراو,
                 but no cash report reads it (case_invoices.prior_paid_amount). -->
            <div class="vt-total-row">
                <span class="vt-total-label is-prior">دراوی پێشوو :</span>
                <?php if ($canSetPrior): ?>
                <span class="vt-total-box vt-paid-box" data-prior-for="<?= $tid ?>"
                      data-net="<?= $invNet ?>" data-prior="<?= $invPrior ?>">
                    <input type="number" class="vt-paid-input vt-prior-input" min="0" step="0.01"
                           value="<?= $invPrior > 0.009 ? $trimNum($invPrior) : '' ?>"
                           placeholder="0"
                           title="ئەو پارەیەی پێش سیستەم وەرگیراوە — ناچێتە ناو داهاتی سندوق"
                           onchange="rtSavePrior(<?= $tid ?>)"
                           oninput="rtPriorPreview(<?= $tid ?>)"
                           onkeydown="if(event.key==='Enter'){event.preventDefault();this.blur();}">
                </span>
                <?php else: ?>
                <span class="vt-total-box is-prior" data-prior-for="<?= $tid ?>" data-prior="<?= $invPrior ?>"><?= $ipMoney($invPrior) ?></span>
                <?php endif; ?>
                <span class="vt-prior-note" title="ئەم بڕە ناچێتە ناو داهاتی سندوق"><i class="fas fa-history"></i> کۆن</span>
            </div>
            <?php endif; ?>
            <?php if ($accountingStatus !== 'approved' && ($advanceAvailable > 0.009 || $invAdvance > 0.009)): ?>
            <!-- ئامانەت: cash the counter already took from this patient on an
                 earlier day, now settling this receipt. The money was counted
                 in the cash report that day, so nothing is counted here. -->
            <div class="vt-total-row">
                <span class="vt-total-label is-prior">ئامانەت :</span>
                <span class="vt-total-box is-prior" data-advance-for="<?= $tid ?>"><?= $ipMoney($invAdvance) ?></span>
                <?php if ($canUseAdvance): ?>
                <span class="vt-prior-note" style="cursor:pointer;"
                      title="ئامانەتی بەردەستی نەخۆش: <?= $ipMoney($advanceAvailable) ?>"
                      onclick="rtUseAdvance(<?= $tid ?>, <?= $advanceAvailable ?>, <?= $invRemaining ?>, <?= $invAdvance ?>)">
                    <i class="fas fa-hand-holding-usd"></i> بەردەست: <?= $ipMoney($advanceAvailable) ?>
                </span>
                <?php elseif ($advanceAvailable > 0.009): ?>
                <span class="vt-prior-note">بەردەست: <?= $ipMoney($advanceAvailable) ?> — بەکارهێنان لەلایەن ژمێریار</span>
                <?php endif; ?>
            </div>
            <?php endif; ?>
            <div class="vt-total-row">
                <span class="vt-total-label">ماوە :</span>
                <span class="vt-total-box<?= $invRemaining > 0.009 ? ' is-due' : '' ?>"
                      data-disc-rem="<?= $tid ?>" data-paid="<?= $invEntered ?>"><?= $ipMoney($invRemaining) ?></span>
            </div>
        </div>
    </div>
    <?php if ($isAdmin && !empty($canDeleteInvoices) && $case['status'] === 'open' && $accountingStatus !== 'cancelled'):
        require_once __DIR__ . '/../../classes/InvoiceCorrection.php';
        $correctionState = (new InvoiceCorrection())->snapshot((int)$case['id'], $tid, (int)($_SESSION['user_id'] ?? 0));
        include __DIR__ . '/invoice_correction.php';
    endif; ?>
</div>
