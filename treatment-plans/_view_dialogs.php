<?php
/**
 * Dialog markup for treatment-plans/view.php (included from it — never open
 * this file directly). Mirrors the case page's dialogs one for one.
 */
if (!isset($plan)) { exit; }
?>

<!-- ═══ Tooth detail panel (same as the case page) ═══ -->
<div class="tooth-detail-overlay" id="toothDetailOverlay" onclick="closeToothDetail()"></div>
<div class="tooth-detail-panel" id="toothDetailPanel">
    <div class="tooth-detail-header">
        <div class="tooth-detail-info">
            <div class="tooth-big-icon" id="toothDetailSvg"><i class="fas fa-tooth"></i></div>
            <div class="tooth-detail-title">
                <h3 id="toothDetailNumber">ددان</h3>
                <p id="toothDetailName"></p>
            </div>
        </div>
        <button class="close-detail-btn" onclick="closeToothDetail()"><i class="fas fa-times"></i></button>
    </div>
    <div class="tooth-detail-body">
        <div class="tooth-quick-stats" id="toothQuickStats">
            <div class="quick-stat">
                <div class="quick-stat-value" id="toothTreatmentCount">0</div>
                <div class="quick-stat-label">ئیش</div>
            </div>
            <div class="quick-stat">
                <div class="quick-stat-value" id="toothTotalCost">0</div>
                <div class="quick-stat-label">کۆی خەمڵێنراو</div>
            </div>
        </div>
        <div class="tooth-detail-section">
            <h4><i class="fas fa-clipboard-list"></i> ئیشە پلانکراوەکان</h4>
            <div id="toothTreatmentsList"></div>
        </div>
    </div>
    <?php if ($isEditable): ?>
    <div class="tooth-detail-footer">
        <button class="btn btn-primary add-treatment-btn-panel" onclick="pvAddForCurrentTooth()">
            <i class="fas fa-plus"></i> زیادکردنی ئیش بۆ ئەم ددانە
        </button>
    </div>
    <?php endif; ?>
</div>

<?php if ($canWrite): ?>
<!-- ═══ Add / edit one planned job (tooth) — the case's add-treatment dialog ═══ -->
<div class="dialog-overlay" id="addTreatmentDialog">
    <div class="dialog treatment-dialog-modern">
        <div class="treatment-dialog-header">
            <h4><i class="fas fa-plus-circle"></i> <span id="itTitle">زیادکردنی ئیش</span></h4>
            <button type="button" class="treatment-dialog-close" onclick="closeDialog('addTreatmentDialog')"><i class="fas fa-times"></i></button>
        </div>
        <div class="treatment-dialog-body">
            <input type="hidden" id="itItemId" value="">
            <?php if (empty($toothTreatments) && empty($archTreatments)): ?>
            <div class="treatment-alert treatment-alert-warning">
                <i class="fas fa-exclamation-triangle"></i>
                <span>هیچ چارەسەرێک نییە. <a href="<?= APP_URL ?>/admin/settings.php?tab=treatments">زیادکردن</a></span>
            </div>
            <?php endif; ?>

          <div class="pl-grid">
            <div class="pl-col">
            <div class="treatment-form-grid">
                <div class="treatment-form-field" id="itToothField">
                    <label><i class="fas fa-tooth"></i> ددان</label>
                    <select id="itToothSelect" onchange="itOnToothChange()">
                        <option value="">هەڵبژێرە...</option>
                        <?php
                        $toothSelectGroups = [
                            'upper_right' => 'سەرەوەی ڕاست (UR)',
                            'upper_left'  => 'سەرەوەی چەپ (UL)',
                            'lower_left'  => 'خوارەوەی چەپ (LL)',
                            'lower_right' => 'خوارەوەی ڕاست (LR)',
                        ];
                        $toothSelectSets = ['permanent' => 'گەورە', 'primary' => 'منداڵ'];
                        foreach ($toothSelectGroups as $groupKey => $groupLabel):
                            foreach ($toothSelectSets as $setName => $setLabel):
                        ?>
                        <optgroup label="<?= $groupLabel ?> — <?= $setLabel ?>">
                            <?php foreach ($teethSets[$setName][$groupKey] as $num): ?>
                            <option value="<?= $num ?>"><?= $palmerNumbers[$num] ?> - <?= $toothNames[$num] ?></option>
                            <?php endforeach; ?>
                        </optgroup>
                        <?php endforeach; endforeach; ?>
                    </select>
                    <small style="color:#0f766e;font-weight:600;margin-top:5px;">
                        <i class="fas fa-briefcase-medical"></i> بێ ددان: وەک کاری گشتی تۆمار دەکرێت
                    </small>
                </div>
                <div class="treatment-form-field treatment-field-full" id="itPickerField">
                    <label><i class="fas fa-briefcase-medical"></i> چارەسەر <span class="required">*</span></label>
                    <div style="display: flex; gap: 6px;">
                        <select id="itTreatmentSelect" onchange="itTreatmentChanged()" style="display: none;">
                            <option value="">هەڵبژێرە...</option>
                            <?php foreach ($toothTreatments as $t): ?>
                            <option value="<?= (int)$t['id'] ?>" data-price="<?= (float)$t['default_price'] ?>"
                                    data-category="<?= htmlspecialchars($t['category'] ?? 'other') ?>"
                                    data-name="<?= htmlspecialchars($t['treatment_name']) ?>">
                                <?= htmlspecialchars($t['treatment_name']) ?> (<?= number_format((float)$t['default_price']) ?>)
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <button type="button" id="itPickerBtn" class="tp-trigger" onclick="openTreatmentPicker()">
                            <i class="fas fa-hand-pointer"></i> هەڵبژاردنی چارەسەر...
                        </button>
                    </div>
                </div>
            </div>

            <div class="treatment-form-field treatment-field-full" id="surfaceSection" style="display: none;">
                <label><i class="fas fa-bullseye"></i> ڕووی ددان <small style="color: var(--text-secondary); font-weight: 400;">(کلیک لەسەر بەشەکانی ددانەکە بکە)</small></label>
                <div class="surface-picker">
                    <div class="surface-tooth-holder" id="surfaceToothPreview"></div>
                    <div class="surface-chips" id="surfaceChips"></div>
                </div>
                <input type="hidden" id="itSurface" value="">
            </div>

            <div class="treatment-form-field treatment-field-full">
                <label><i class="fas fa-pen"></i> ناوی چارەسەر <span class="required">*</span></label>
                <input type="text" id="itName" placeholder="ناوی چارەسەر...">
            </div>
            <div class="treatment-form-field treatment-field-full">
                <label><i class="fas fa-comment"></i> تێبینی</label>
                <input type="text" id="itNotes" placeholder="تێبینی ئارەزوومەندانە...">
            </div>
            </div><!-- /pl-col -->

            <div class="pl-col">
            <div class="treatment-pricing-section">
                <div class="pricing-header"><i class="fas fa-coins"></i> نرخ (خەمڵاندن)</div>
                <div class="pricing-content">
                    <div class="price-row default-price">
                        <span class="price-label">نرخی پێشنیارکراو:</span>
                        <span class="price-value" id="itDefaultPrice">0</span>
                    </div>
                    <div class="price-row agreement-price">
                        <span class="price-label">نرخی خەمڵێنراو:</span>
                        <input type="number" id="itUnitPrice" min="0" step="250" oninput="itRecalc()" placeholder="نرخ بنووسە...">
                        <button type="button" class="use-default-btn" onclick="itUseDefault()" title="بەکارهێنانی نرخی پێشنیارکراو"><i class="fas fa-sync-alt"></i></button>
                    </div>
                </div>
            </div>
            <div class="treatment-form-grid">
                <div class="treatment-form-field">
                    <label><i class="fas fa-hashtag"></i> ژمارە</label>
                    <input type="number" id="itQty" value="1" min="1" step="1" oninput="itRecalc()">
                </div>
                <div class="treatment-form-field">
                    <label><i class="fas fa-percent"></i> داشکاندن</label>
                    <input type="number" id="itDiscount" value="0" min="0" step="250" oninput="itRecalc()">
                </div>
                <div class="treatment-form-field treatment-field-full">
                    <label><i class="fas fa-clock"></i> کاتی خەمڵێنراو (خولەک)</label>
                    <input type="number" id="itMinutes" min="0" step="5" placeholder="—">
                </div>
            </div>
            <div class="treatment-total-display">
                <span class="total-label">کۆی ئەم ئیشە:</span>
                <span class="total-value" id="itTotal">0</span>
            </div>
            <div class="tp-dialog-msg" id="itMsg"></div>
            </div><!-- /pl-col -->
          </div><!-- /pl-grid -->
        </div>
        <div class="treatment-dialog-footer">
            <button type="button" class="treatment-btn-primary" id="itSaveBtn" onclick="itSave(false)"><i class="fas fa-plus-circle"></i> <span id="itSaveLabel">زیادکردن</span></button>
            <button type="button" class="treatment-btn-primary" id="itSaveNewBtn" style="background: linear-gradient(135deg, #107c10, #0e6b0e);" onclick="itSave(true)">
                <i class="fas fa-plus"></i> پاشەکەوت و زیادکردنی تر
            </button>
            <button type="button" class="treatment-btn-secondary" onclick="closeDialog('addTreatmentDialog')"><i class="fas fa-times"></i> داخستن</button>
        </div>
    </div>
</div>

<!-- ═══ Searchable, categorized treatment picker ═══ -->
<div class="tp-overlay" id="treatmentPicker">
    <div class="tp-sheet">
        <div class="tp-head">
            <h4><i class="fas fa-briefcase-medical"></i> هەڵبژاردنی چارەسەر</h4>
            <button type="button" class="tp-close" onclick="closeTreatmentPicker()"><i class="fas fa-times"></i></button>
        </div>
        <div class="tp-search-row"><i class="fas fa-search"></i><input type="text" id="tpSearch" placeholder="گەڕان بۆ چارەسەر..."></div>
        <div class="tp-cats" id="tpCats"></div>
        <div class="tp-list" id="tpList"></div>
    </div>
</div>

<!-- ═══ Whole-arch job — the case's arch treatment dialog ═══ -->
<div class="dialog-overlay" id="archTreatmentDialog">
    <div class="dialog arch-dialog-modern">
        <div class="arch-dialog-header">
            <h4><i class="fas fa-teeth"></i> زیادکردنی چارەسەری ئەرک</h4>
            <button type="button" class="arch-dialog-close" onclick="closeDialog('archTreatmentDialog')"><i class="fas fa-times"></i></button>
        </div>
        <div class="arch-dialog-body">
          <input type="hidden" id="arArchType" value="">
          <div class="pl-grid">
            <div class="pl-col">
            <div class="arch-form-field">
                <label><i class="fas fa-teeth-open"></i> هەڵبژاردنی ئەرک <span class="required">*</span></label>
                <div class="arch-select-chart-modern">
                    <div class="arch-select-panel" data-arch="upper" onclick="arSelectArch('upper')">
                        <div class="arch-select-visual">
                            <svg viewBox="0 0 280 80" class="arch-select-svg">
                                <path d="M 15 65 Q 140 -10 265 65" fill="none" stroke="#0078d4" stroke-width="2.5" class="arch-select-outline"/>
                                <g class="arch-teeth-indicators">
                                    <rect x="20" y="42" width="14" height="20" rx="2"/><rect x="38" y="34" width="14" height="24" rx="2"/>
                                    <rect x="56" y="26" width="14" height="28" rx="2"/><rect x="74" y="20" width="12" height="30" rx="2"/>
                                    <rect x="90" y="16" width="11" height="32" rx="2"/><rect x="105" y="13" width="10" height="34" rx="2"/>
                                    <rect x="119" y="11" width="9" height="35" rx="2"/><rect x="132" y="10" width="8" height="36" rx="2"/>
                                    <rect x="144" y="10" width="8" height="36" rx="2"/><rect x="156" y="11" width="9" height="35" rx="2"/>
                                    <rect x="169" y="13" width="10" height="34" rx="2"/><rect x="183" y="16" width="11" height="32" rx="2"/>
                                    <rect x="198" y="20" width="12" height="30" rx="2"/><rect x="214" y="26" width="14" height="28" rx="2"/>
                                    <rect x="232" y="34" width="14" height="24" rx="2"/><rect x="250" y="42" width="14" height="20" rx="2"/>
                                </g>
                            </svg>
                        </div>
                        <div class="arch-select-label">
                            <i class="fas fa-check-circle arch-select-check"></i>
                            <span>ئەرکی سەرەوە (Maxilla)</span>
                            <span class="arch-select-count">یەک کاری سەرانسەری ئەرک</span>
                        </div>
                    </div>
                    <div class="arch-select-panel" data-arch="lower" onclick="arSelectArch('lower')">
                        <div class="arch-select-visual">
                            <svg viewBox="0 0 280 80" class="arch-select-svg">
                                <path d="M 20 15 Q 140 90 260 15" fill="none" stroke="#0078d4" stroke-width="2.5" class="arch-select-outline"/>
                                <g class="arch-teeth-indicators">
                                    <rect x="25" y="18" width="13" height="18" rx="2"/><rect x="42" y="24" width="13" height="22" rx="2"/>
                                    <rect x="59" y="30" width="13" height="25" rx="2"/><rect x="76" y="36" width="11" height="27" rx="2"/>
                                    <rect x="91" y="40" width="10" height="29" rx="2"/><rect x="105" y="44" width="9" height="30" rx="2"/>
                                    <rect x="118" y="46" width="8" height="31" rx="2"/><rect x="130" y="48" width="7" height="32" rx="2"/>
                                    <rect x="141" y="48" width="7" height="32" rx="2"/><rect x="152" y="46" width="8" height="31" rx="2"/>
                                    <rect x="164" y="44" width="9" height="30" rx="2"/><rect x="177" y="40" width="10" height="29" rx="2"/>
                                    <rect x="191" y="36" width="11" height="27" rx="2"/><rect x="206" y="30" width="13" height="25" rx="2"/>
                                    <rect x="223" y="24" width="13" height="22" rx="2"/><rect x="240" y="18" width="13" height="18" rx="2"/>
                                </g>
                            </svg>
                        </div>
                        <div class="arch-select-label">
                            <i class="fas fa-check-circle arch-select-check"></i>
                            <span>ئەرکی خوارەوە (Mandible)</span>
                            <span class="arch-select-count">یەک کاری سەرانسەری ئەرک</span>
                        </div>
                    </div>
                </div>
                <p class="arch-select-hint-modern"><i class="fas fa-info-circle"></i> کرتە بکە لەسەر ئەرکەکان بۆ هەڵبژاردن. دەتوانیت هەردووکیان هەڵبژێریت.</p>
            </div>
            <div class="arch-form-field">
                <label><i class="fas fa-briefcase-medical"></i> چارەسەر <span class="required">*</span></label>
                <select id="arTreatmentSelect" onchange="arTreatmentChanged()">
                    <option value="">هەڵبژێرە...</option>
                    <?php foreach ($archTreatments as $t): ?>
                    <option value="<?= (int)$t['id'] ?>" data-price="<?= (float)$t['default_price'] ?>" data-name="<?= htmlspecialchars($t['treatment_name']) ?>">
                        <?= htmlspecialchars($t['treatment_name']) ?> (<?= number_format((float)$t['default_price']) ?>)
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="arch-form-field">
                <label><i class="fas fa-pen"></i> ناوی چارەسەر <span class="required">*</span></label>
                <input type="text" id="arName" placeholder="ناوی چارەسەر...">
            </div>
            </div><!-- /pl-col -->

            <div class="pl-col">
            <div class="arch-form-field">
                <label><i class="fas fa-tags"></i> شێوازی نرخدانان <span class="required">*</span></label>
                <div class="arch-pricing-mode-selector">
                    <label class="arch-pricing-mode-option selected" data-mode="per_arch" onclick="arSwitchMode('per_arch')">
                        <input type="radio" name="arPricingMode" value="per_arch" checked>
                        <i class="fas fa-teeth-open"></i><span>نرخ بۆ هەر ئەرکێک</span>
                    </label>
                    <label class="arch-pricing-mode-option" data-mode="total" onclick="arSwitchMode('total')">
                        <input type="radio" name="arPricingMode" value="total">
                        <i class="fas fa-calculator"></i><span>کۆی گشتی</span>
                    </label>
                    <label class="arch-pricing-mode-option" data-mode="separate" id="arSeparateOption" style="display: none;" onclick="arSwitchMode('separate')">
                        <input type="radio" name="arPricingMode" value="separate">
                        <i class="fas fa-divide"></i><span>نرخی جیاواز</span>
                    </label>
                </div>
            </div>
            <div class="arch-pricing-section" id="arPerArchPricing">
                <div class="arch-pricing-header orange"><i class="fas fa-coins"></i> نرخ بۆ هەر ئەرکێک</div>
                <div class="arch-pricing-content">
                    <div class="arch-price-row">
                        <span class="arch-price-label">نرخی پێشنیارکراو:</span>
                        <span class="arch-price-value" id="arDefaultPrice">0</span>
                    </div>
                    <div class="arch-price-row">
                        <span class="arch-price-label">نرخی خەمڵێنراو:</span>
                        <input type="number" id="arUnitPrice" min="0" oninput="arCalc()" placeholder="نرخ بنووسە...">
                        <button type="button" class="arch-use-default-btn" onclick="arUseDefault()" title="بەکارهێنانی نرخی پێشنیارکراو"><i class="fas fa-sync-alt"></i></button>
                    </div>
                </div>
            </div>
            <div class="arch-pricing-section" id="arTotalPricing" style="display: none;">
                <div class="arch-pricing-header green"><i class="fas fa-hand-holding-usd"></i> کۆی نرخی خەمڵێنراو</div>
                <div class="arch-pricing-content">
                    <div class="arch-price-row">
                        <span class="arch-price-label" style="color: #2e7d32; font-weight: 600;">کۆی پارە:</span>
                        <input type="number" id="arTotalAgreed" min="0" oninput="arCalc()" placeholder="کۆی نرخ..." class="green-input">
                    </div>
                    <div class="arch-price-row calculated">
                        <span class="arch-price-label">نرخی هەر ئەرکێک:</span>
                        <span class="arch-price-value green" id="arCalcPerArch">0</span>
                    </div>
                </div>
            </div>
            <div class="arch-pricing-section" id="arSeparatePricing" style="display: none;">
                <div class="arch-pricing-header purple"><i class="fas fa-layer-group"></i> نرخی جیاواز بۆ هەر ئەرکێک</div>
                <div class="arch-pricing-content">
                    <div class="arch-price-row">
                        <span class="arch-price-label" style="color: var(--win-accent, #0078d4); font-weight: 600;"><i class="fas fa-arrow-up"></i> ماکزیلا / سەرەوە:</span>
                        <input type="number" id="arUpperPrice" min="0" oninput="arCalc()" placeholder="نرخ..." class="blue-input">
                    </div>
                    <div class="arch-price-row">
                        <span class="arch-price-label" style="color: #9c27b0; font-weight: 600;"><i class="fas fa-arrow-down"></i> ماندیبل / خوارەوە:</span>
                        <input type="number" id="arLowerPrice" min="0" oninput="arCalc()" placeholder="نرخ..." class="purple-input">
                    </div>
                </div>
            </div>
            <div class="arch-total-display">
                <span class="arch-total-label">کۆی گشتی (<span id="arArchCount">0</span> ئەرک):</span>
                <span class="arch-total-value" id="arTotal">0</span>
            </div>
            <div class="arch-form-field">
                <label><i class="fas fa-clock"></i> کاتی خەمڵێنراو بۆ هەر ئەرکێک (خولەک)</label>
                <input type="number" id="arMinutes" min="0" step="5" placeholder="—">
            </div>
            <div class="arch-form-field">
                <label><i class="fas fa-comment"></i> تێبینی</label>
                <input type="text" id="arNotes" placeholder="تێبینی ئارەزوومەندانە...">
            </div>
            <div class="tp-dialog-msg" id="arMsg"></div>
            </div><!-- /pl-col -->
          </div><!-- /pl-grid -->
        </div>
        <div class="arch-dialog-footer">
            <button type="button" class="arch-btn-primary" id="arSaveBtn" onclick="arSave()"><i class="fas fa-plus-circle"></i> زیادکردن بۆ ئەرکی هەڵبژێردراو</button>
            <button type="button" class="arch-btn-secondary" onclick="closeDialog('archTreatmentDialog')"><i class="fas fa-times"></i> داخستن</button>
        </div>
    </div>
</div>

<!-- ═══ Plan header edit ═══ -->
<div class="dialog-overlay" id="planHeaderDialog">
    <div class="dialog" style="max-width: 520px;">
        <div class="dialog-header">
            <h4 class="dialog-title"><i class="fas fa-edit"></i> دەستکاری زانیاری پلان</h4>
            <button type="button" class="dialog-close" onclick="closeDialog('planHeaderDialog')"><i class="fas fa-times"></i></button>
        </div>
        <div class="dialog-body">
            <div class="form-group">
                <label class="form-label">سکاڵای سەرەکی / گلەیی نەخۆش</label>
                <input type="text" id="hdComplaint" class="form-control" value="<?= htmlspecialchars($plan['chief_complaint'] ?? '') ?>">
            </div>
            <?php if ($isArch): ?>
            <div class="form-group">
                <label class="form-label">ئەرک</label>
                <div style="display: flex; gap: 8px;">
                    <?php foreach (['upper' => 'سەرەوە', 'lower' => 'خوارەوە', 'both' => 'هەردوو'] as $v => $l): ?>
                    <label class="tp-radio"><input type="radio" name="hdArch" value="<?= $v ?>" <?= $selectedArch === $v ? 'checked' : '' ?>> <?= $l ?></label>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
            <div class="form-group">
                <label class="form-label">ماوەی پێویست بۆ چارەسەر</label>
                <input type="text" id="hdDuration" class="form-control" value="<?= htmlspecialchars($plan['duration_text'] ?? '') ?>" placeholder="نمونە: ٢ هەفتە / ٣ سەردان">
            </div>
            <div class="form-group">
                <label class="form-label">تێبینی بۆ نەخۆش</label>
                <textarea id="hdNotes" class="form-control" rows="3"><?= htmlspecialchars($plan['notes'] ?? '') ?></textarea>
            </div>
            <div class="tp-dialog-msg" id="hdMsg"></div>
        </div>
        <div class="dialog-footer">
            <button type="button" class="btn btn-secondary" onclick="closeDialog('planHeaderDialog')">هەڵوەشاندنەوە</button>
            <button type="button" class="btn btn-primary" id="hdSaveBtn" onclick="hdSave()"><i class="fas fa-save"></i> پاشەکەوت</button>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if ($canTransfer): ?>
<!-- ═══ گواستنەوە — the one step that turns the estimate into billable work ═══ -->
<div class="dialog-overlay" id="tpTransferDialog">
    <div class="dialog" style="max-width: 620px;">
        <div class="dialog-header">
            <h4 class="dialog-title"><i class="fas fa-exchange-alt"></i> گواستنەوە بۆ کەیس</h4>
            <button type="button" class="dialog-close" onclick="closeDialog('tpTransferDialog')"><i class="fas fa-times"></i></button>
        </div>
        <div class="dialog-body">
            <div class="tp-transfer-warn">
                <i class="fas fa-exclamation-triangle"></i>
                <div>دوای گواستنەوە، ئەم ئیشانە دەبنە <strong>ئیشی ڕاستەقینە لەسەر کەیس</strong> و بڕە پارەکەیان دەچێتە ناو بەشی دارایی. ئەم کردارە ناگەڕێتەوە.</div>
            </div>
            <?php if (!empty($treatingName)): ?>
            <div class="tp-route-note" style="margin: -4px 0 14px;">
                <i class="fas fa-user-md"></i> کەیسەکە لەسەر ناوی <b><?= htmlspecialchars($treatingName) ?></b> (دکتۆری چارەسەرکەر) دەبێت — هەر دکتۆرە و تەنها کەیسی خۆی دەبینێت.
            </div>
            <?php endif; ?>
            <div class="form-group">
                <label class="form-label">ئایتمەکان بۆ گواستنەوە</label>
                <div class="tp-tr-items" id="tpTrItems"></div>
            </div>
            <div class="form-group">
                <label class="form-label">بۆ کوێ بگوازرێتەوە؟</label>
                <label class="tp-radio tp-radio-block">
                    <input type="radio" name="tpTrTarget" value="new" checked onchange="tpTrTargetChanged()"> کەیسێکی نوێ دروست بکە
                </label>
                <label class="tp-radio tp-radio-block" <?= empty($caseTargets) ? 'style="opacity:.5;"' : '' ?>>
                    <input type="radio" name="tpTrTarget" value="existing" onchange="tpTrTargetChanged()" <?= empty($caseTargets) ? 'disabled' : '' ?>> زیادکردن بۆ کەیسێکی کراوە
                </label>
            </div>
            <div class="form-group" id="tpTrNewCaseBox">
                <label class="form-label required">ناوی کەیس</label>
                <select id="tpTrCaseName" class="form-control">
                    <option value="">— هەڵبژێرە —</option>
                    <?php foreach ($caseNames as $cn): ?>
                    <option value="<?= htmlspecialchars($cn) ?>"><?= htmlspecialchars($cn) ?></option>
                    <?php endforeach; ?>
                </select>
                <?php if (!$caseNames): ?>
                <small style="color:#f44336;display:block;margin-top:6px;">هیچ ناوێکی کەیس بۆ ئەم جۆرە بوارە زیاد نەکراوە</small>
                <?php endif; ?>
            </div>
            <div class="form-group" id="tpTrExistingCaseBox" hidden>
                <label class="form-label required">کەیس</label>
                <select id="tpTrCaseId" class="form-control">
                    <?php foreach ($caseTargets as $c): ?>
                    <option value="<?= (int)$c['id'] ?>">
                        <?= htmlspecialchars($c['case_number'] . ' — ' . ($c['case_name'] ?: $c['folder_name'])) ?>
                        <?= !empty($c['doctor_name']) ? ' (' . htmlspecialchars($c['doctor_name']) . ')' : '' ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="tp-tr-summary" id="tpTrSummary"></div>
            <div class="tp-dialog-msg" id="tpTrMsg"></div>
        </div>
        <div class="dialog-footer">
            <button type="button" class="btn btn-secondary" onclick="closeDialog('tpTransferDialog')">هەڵوەشاندنەوە</button>
            <button type="button" class="btn btn-primary" id="tpTrSave" onclick="tpDoTransfer()" style="background: linear-gradient(135deg,#2e7d32,#1b5e20);">
                <i class="fas fa-exchange-alt"></i> گواستنەوە
            </button>
        </div>
    </div>
</div>
<?php endif; ?>
