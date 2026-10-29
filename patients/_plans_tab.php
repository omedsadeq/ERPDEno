<?php
/**
 * پلانی چارەسەر tab of the patient page — the markup only.
 *
 * The patient's plans as cards; each opens its own page,
 * treatment-plans/view.php, where the chart, the work table, routing, the
 * appointment and the transfer to a case live. «پلانی نوێ» makes a plan in a
 * click and opens that page.
 *
 * #tpwList is drawn by patients/_plans_tab_assets.php from the plans
 * patients/view.php loaded. A plan is a quote, not money: nothing here reaches
 * the accounting tables until an item is transferred to a case.
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
            <button type="button" class="btn btn-primary" onclick="tpNewPlan(this)">
                <i class="fas fa-plus"></i> پلانی نوێ
            </button>
            <?php endif; ?>
        </div>
        <div class="tpw-list" id="tpwList"></div>
    </div>
</div>
