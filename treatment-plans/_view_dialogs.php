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

<!-- ═══ The case's treatment picker: one card a treatment type, ★ first ═══ -->
<div class="tp-overlay" id="treatmentPicker">
    <div class="tp-sheet">
        <div class="tp-head">
            <h4 id="tpTitle"><i class="fas fa-briefcase-medical"></i> هەڵبژاردنی چارەسەر</h4>
            <button type="button" class="tp-close" onclick="closeTreatmentPicker()"><i class="fas fa-times"></i></button>
        </div>
        <div class="tp-search-row">
            <i class="fas fa-search"></i>
            <input type="text" id="tpSearch" placeholder="گەڕان بۆ چارەسەر...">
        </div>
        <div class="tp-cats" id="tpCats"></div>
        <!-- a click picks a card, «دیارکردن» or a double click uses it -->
        <div class="tp-list" id="tpList"></div>
        <div class="tp-foot" id="tpFoot">
            <div class="tp-foot-acts">
                <button type="button" class="tp-confirm" id="tpConfirm" onclick="tpConfirmPick()" disabled>دیارکردن</button>
                <button type="button" class="tp-cancel" onclick="closeTreatmentPicker()">هەڵوەشاندنەوە</button>
            </div>
            <!-- one tooth from the chart: what is already planned for it -->
            <button type="button" class="tp-history" id="tpHistory" onclick="openQuickToothHistory()" hidden></button>
        </div>
    </div>
</div>

<?php if ($isEditable): ?>
<!-- ═══ Teeth selected on the chart (as on the case page) ═══ -->
<div id="bulkActionBar" style="display: none;">
    <div class="bulk-bar-inner">
        <span class="bulk-bar-count"><i class="fas fa-tooth"></i> <span id="bulkCount">0</span> ددان هەڵبژێردراوە</span>
        <span class="bulk-bar-teeth" id="bulkTeethList"></span>
        <div class="bulk-bar-actions">
            <button type="button" class="btn btn-sm" onclick="clearBulkSelection()" style="background:#eef2f5;color:#37474f;border:1px solid #cfd8dc;">
                <i class="fas fa-eraser"></i> لابردن
            </button>
            <button type="button" class="btn btn-sm" id="bulkHistBtn" onclick="bulkShowHistory()"
                    style="background:#fff;color:#0369a1;border:1px solid #bae6fd;" title="ئیشە پلانکراوەکانی ئەم ددانە">
                <i class="fas fa-history"></i> مێژوو
            </button>
            <button type="button" class="btn btn-sm btn-primary" id="bulkApplyBtn" onclick="openQuickTeethAdd()">
                <i class="fas fa-plus-circle"></i> زیادکردنی ئیش
            </button>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- ═══ «ئیشی ئەرک» — the case's dialog: the arch, then the jobs as cards
     (several at once), each at its catalog price an arch — corrected in the
     table afterwards. Saved by arSave() (add_arch_items). ═══ -->
<div class="dialog-overlay" id="archTreatmentDialog">
    <div class="dialog ar2-dialog">
        <div class="ar2-head">
            <h4><i class="fas fa-teeth"></i> ئیشی ئەرک <small>زنجیر · تاخم · هەموو ئەرکێک</small></h4>
            <button type="button" class="ar2-close" onclick="closeDialog('archTreatmentDialog')"><i class="fas fa-times"></i></button>
        </div>
        <div class="ar2-body">
            <div class="ar2-step"><span class="ar2-num">١</span> کام ئەرک؟</div>
            <div class="ar2-arches">
                <?php foreach ([
                    ['upper', 'سەرەوە', 'Maxilla', ''],
                    ['lower', 'خوارەوە', 'Mandible', ''],
                    ['both', 'هەردوو', 'سەرەوە و خوارەوە', '×٢'],
                ] as [$archKey, $archLabel, $archSub, $archBadge]): ?>
                <button type="button" class="ar2-arch ar2-arch--<?= $archKey ?>" data-arch="<?= $archKey ?>" onclick="arSetArch('<?= $archKey ?>')">
                    <span class="ar2-arch-check"><i class="fas fa-check"></i></span>
                    <?php if ($archBadge): ?><span class="ar2-arch-badge"><?= $archBadge ?></span><?php endif; ?>
                    <span class="ar2-art" data-art="<?= $archKey ?>"></span>
                    <span class="ar2-arch-text"><b><?= $archLabel ?></b><small><?= $archSub ?></small></span>
                </button>
                <?php endforeach; ?>
            </div>
            <div class="ar2-step"><span class="ar2-num">٢</span> کام ئیش؟ <small class="ar2-hint">— چەند دانەیەک دەتوانیت هەڵبژێریت</small></div>
            <div class="ar2-cards" id="arCards"></div>
            <div class="tp-dialog-msg" id="arMsg"></div>
        </div>
        <div class="ar2-foot">
            <button type="button" class="ar2-save" id="arSaveBtn" onclick="arSave()" disabled><i class="fas fa-plus-circle"></i> زیادکردن</button>
            <button type="button" class="ar2-cancel" onclick="closeDialog('archTreatmentDialog')">داخستن</button>
            <span class="ar2-total">کۆ: <b id="archTotalPriceDisplay">0</b></span>
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
