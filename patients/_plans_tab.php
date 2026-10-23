<?php
/**
 * پلانی چارەسەر tab of the patient page — the markup only.
 *
 * Built like the inside of a case: the selected plan's tooth chart on top and
 * its work table under it, with the work added, changed and removed right
 * here. Routing (کێ چارەسەر دەکات؟), the appointment and the transfer to a case
 * stay on the plan's own page, treatment-plans/view.php.
 *
 * Everything inside #tpwPlan is drawn by patients/_plans_tab_assets.php from
 * the plans patients/view.php loaded. A plan is a quote, not money: nothing
 * here reaches the accounting tables until an item is transferred to a case.
 */
if (!isset($canViewPlans)) {
    exit;
}
?>
<div class="tab-content" id="tab-plans" style="display: none;">
    <div class="panel-body tpw" id="tpw">
        <div class="tpw-top">
            <div class="tpw-top-title">
                <h4><i class="fas fa-clipboard-list"></i> پلانی چارەسەر</h4>
                <span class="tpw-est" title="پلانی چارەسەر تەنها پێشنیارە بۆ نەخۆش. هیچ پارەیەک ناچێتە ناو بەشی دارایی تا ئایتمەکە بگوازرێتەوە بۆ کەیس.">
                    <i class="fas fa-calculator"></i> خەمڵاندنە — نەچووەتە حسابات
                </span>
            </div>
            <?php if ($canWritePlans): ?>
            <button type="button" class="btn btn-primary" onclick="tpNewPlan()">
                <i class="fas fa-plus"></i> پلانی نوێ
            </button>
            <?php endif; ?>
        </div>
        <div class="tpw-switch" id="tpwSwitch" hidden></div>
        <div id="tpwPlan"></div>
    </div>
</div>
