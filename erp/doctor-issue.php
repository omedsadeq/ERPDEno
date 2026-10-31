<?php
/**
 * پێدانی مەواد بە دکتۆر — the store's main screen.
 *
 * Opens on a start step that must be completed before a single material can
 * be entered: first the kind (ئاسایی / ئیمپلانت), then the doctor, then the
 * warehouse (the main one is preselected). After saving, the next voucher
 * asks again — a doctor or kind never carries over by accident.
 *
 * Entry is row by row: every row has its own مەواد cell — type a name, code or
 * barcode, pick from the list (or Enter on an exact barcode), then the
 * quantity; Enter moves to the next row. There is always one empty row at the
 * bottom to type into.
 *
 * The price the doctor is charged follows ONE saved setting (نرخی فرۆشتن /
 * نرخی کڕین, ErpDoctorIssue::priceMode()), switchable here by whoever may
 * change the store settings. Saving goes to erp/api/doctor-issues.php →
 * ErpDoctorIssue::create(): stock leaves at cost, and the charged total
 * becomes one خەرجی کۆگا row in the doctor's name.
 *
 *   F2 entry row · F4 change doctor/kind · Enter next · ↑↓ rows
 *   Ctrl+Delete remove row · Ctrl+S save · Ctrl+P save and print
 */

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../classes/ErpDoctorIssue.php';
erp_require('erp.doctor_issue.create');

$pageTitle  = 'پێدانی مەواد بە دکتۆر';
$breadcrumb = [
    ['title' => 'کۆگا', 'url' => APP_URL . '/erp/index.php'],
    ['title' => 'پێدانی مەواد بە دکتۆر'],
];

$warehouses = erp_warehouses();
$defaultWh  = erp_default_warehouse_id();
$doctors    = ErpDoctorIssue::doctors();
$nextNumber = ErpSequence::preview('DOCTOR_ISSUE', 0);
$priceMode  = ErpDoctorIssue::priceMode();
$canSetPrice = erp_can('erp.settings');

require_once __DIR__ . '/../includes/header.php';
?>

<!-- The voucher opens as a large dialog over the store page, so the list of
     materials has the screen; closing goes back to where it was opened. -->
<div class="did-overlay" id="didDialog">
<div class="did-box" role="dialog" aria-modal="true" aria-labelledby="didTitle">
    <div class="did-head">
        <span class="did-ico"><i class="fas fa-hand-holding-medical"></i></span>
        <h3 id="didTitle">پێدانی مەواد بە دکتۆر</h3>
        <div class="did-tools">
            <a class="did-btn" href="<?= APP_URL ?>/erp/doctor-issues.php" title="وەسڵەکان"><i class="fas fa-file-invoice"></i></a>
            <button type="button" class="did-btn" onclick="diClose()" title="داخستن"><i class="fas fa-times"></i></button>
        </div>
    </div>
    <div class="did-body">

<div class="di" id="diRoot">
  <div class="di-main">
    <!-- Who / what / where — filled by the start step, changed only through it -->
    <div class="di-head">
        <button type="button" class="di-kind-chip" id="diKindChip" onclick="DI.open()" title="گۆڕینی جۆر (F4)">
            <i class="fas fa-layer-group"></i> <span id="diKindLabel">—</span>
        </button>
        <button type="button" class="di-doc-chip" onclick="DI.open()" title="گۆڕینی دکتۆر (F4)">
            <span class="di-avatar" id="diDocAvatar">؟</span>
            <span><small>دکتۆر</small><b id="diDocName">هەڵنەبژێردراوە</b></span>
        </button>
        <div class="di-meta">
            <span><i class="fas fa-warehouse"></i> <b id="diWhName">—</b></span>
            <span><i class="fas fa-hashtag"></i> <b dir="ltr"><?= erp_esc($nextNumber) ?></b></span>
            <label class="di-date"><i class="fas fa-calendar"></i>
                <input type="date" id="diDate" value="<?= date('Y-m-d') ?>" max="<?= date('Y-m-d') ?>" aria-label="بەروار">
            </label>
        </div>
        <?php if ($canSetPrice): ?>
        <div class="di-price" role="radiogroup" aria-label="نرخی مەواد بۆ دکتۆر" title="یەکجار پاشەکەوت دەکرێت و بۆ هەموو وەسڵە نوێیەکان کار دەکات">
            <span class="di-price-label"><i class="fas fa-tag"></i> نرخ</span>
            <?php foreach (ErpDoctorIssue::PRICE_MODES as $m => $label): ?>
            <label><input type="radio" name="diPriceMode" value="<?= $m ?>" <?= $m === $priceMode ? 'checked' : '' ?>><span><?= erp_esc($label) ?></span></label>
            <?php endforeach; ?>
        </div>
        <?php else: ?>
        <span class="di-price is-fixed"><span class="di-price-label"><i class="fas fa-tag"></i> نرخ</span><b><?= erp_esc(ErpDoctorIssue::priceModeLabel($priceMode)) ?></b></span>
        <?php endif; ?>
        <button type="button" class="di-change" onclick="DI.open()"><i class="fas fa-pen"></i> گۆڕین</button>
    </div>

    <div class="di-toolbar">
        <span><i class="fas fa-list-ol" aria-hidden="true"></i> ڕیز بە ڕیز: لە خانەی <b>مەواد</b> ناو، کۆد یان بارکۆد بنووسە و لە لیستەکە هەڵیبژێرە، پاشان بڕ.</span>
        <span class="di-keys"><kbd>Enter</kbd> ڕیزی داهاتوو · <kbd>↑</kbd><kbd>↓</kbd> ڕیزەکان · <kbd>Ctrl+Delete</kbd> سڕینەوە · <kbd>Ctrl+S</kbd> پاشەکەوت</span>
    </div>

    <div class="di-grid">
        <table class="di-table">
            <thead>
                <tr>
                    <th class="di-idx">#</th>
                    <th>مەواد</th>
                    <th class="di-n" style="width:90px">بەردەست</th>
                    <th class="di-n" style="width:100px">بڕ</th>
                    <th class="di-n" style="width:140px">نرخ</th>
                    <th class="di-n" style="width:130px">کۆ</th>
                    <th class="di-del"></th>
                </tr>
            </thead>
            <tbody id="diBody"></tbody>
        </table>
    </div>
  </div>

    <div class="di-side">
        <div class="di-total">
            <div class="di-total-row"><span>ژمارەی مەواد</span><b id="diCount">0</b></div>
            <div class="di-total-row grand"><span>کۆی گشتی</span><b id="diSum">0</b></div>
            <p class="di-total-note" id="diTotalNote">ئەم بڕە دەچێتە خەرجی کۆگا لەسەر ناوی دکتۆر.</p>
            <div class="di-actions">
                <button type="button" class="btn btn-primary" id="diSave" onclick="diSave(false)"><i class="fas fa-save"></i> پاشەکەوت</button>
                <button type="button" class="btn btn-secondary" onclick="diSave(true)"><i class="fas fa-print"></i> پاشەکەوت و چاپ</button>
                <button type="button" class="btn btn-subtle" onclick="diClear()" title="پاککردنەوەی لیست"><i class="fas fa-eraser"></i></button>
            </div>
        </div>
        <div class="di-notes">
            <label for="diNotes">تێبینی</label>
            <textarea id="diNotes" rows="3" placeholder="ئارەزوومەندانە — بۆ نموونە: بۆ کەیسی نەخۆشێک"></textarea>
            <div class="di-msg" id="diMsg" role="alert"></div>
        </div>
    </div>
</div>

    </div>
</div>
</div>

<!-- ═══ Start step: kind → doctor → warehouse ═══ -->
<div class="dis-overlay" id="disOverlay" hidden>
    <div class="dis-card" role="dialog" aria-modal="true" aria-labelledby="disTitle">
        <div class="dis-head">
            <span class="dis-icon"><i class="fas fa-hand-holding-medical" aria-hidden="true"></i></span>
            <div>
                <h3 id="disTitle">پێدانی مەواد بە دکتۆر</h3>
                <p>سەرەتا جۆر و دکتۆر و کۆگا دیاری بکە، پاشان مەوادەکان زیاد بکە.</p>
            </div>
        </div>

        <section class="dis-step">
            <div class="dis-label"><span class="dis-num">1</span> جۆری خەرجی</div>
            <div class="dis-kinds" role="radiogroup" aria-label="جۆری خەرجی">
                <label class="dis-kind dis-kind--normal">
                    <input type="radio" name="disKind" value="normal">
                    <span class="dis-kind-box"><i class="fas fa-box-open"></i><b>ئاسایی</b><small>مەوادی ڕۆژانە</small></span>
                </label>
                <label class="dis-kind dis-kind--implant">
                    <input type="radio" name="disKind" value="implant">
                    <span class="dis-kind-box"><i class="fas fa-tooth"></i><b>ئیمپلانت</b><small>مەوادی ئیمپلانت</small></span>
                </label>
            </div>
        </section>

        <section class="dis-step">
            <div class="dis-label"><span class="dis-num">2</span> دکتۆر</div>
            <div class="dis-search">
                <i class="fas fa-magnifying-glass" aria-hidden="true"></i>
                <input id="disQ" type="search" placeholder="ناوی دکتۆر..." autocomplete="off" aria-label="گەڕانی دکتۆر">
            </div>
            <div class="dis-doctors" id="disDoctors" role="radiogroup" aria-label="دکتۆر">
                <?php foreach ($doctors as $d):
                    $initial = mb_substr(trim(preg_replace('/^د\.\s*/u', '', $d['full_name'])), 0, 1); ?>
                <label class="dis-doc" data-name="<?= erp_esc(mb_strtolower($d['full_name'])) ?>">
                    <input type="radio" name="disDoc" value="<?= (int)$d['id'] ?>" data-name="<?= erp_esc($d['full_name']) ?>">
                    <span class="dis-doc-box"><span class="di-avatar"><?= erp_esc($initial) ?></span><span><?= erp_esc($d['full_name']) ?></span></span>
                </label>
                <?php endforeach; ?>
                <?php if (!$doctors): ?><div class="dis-empty">هیچ دکتۆرێکی چالاک نییە</div><?php endif; ?>
            </div>
        </section>

        <section class="dis-step">
            <div class="dis-label"><span class="dis-num">3</span> کۆگا</div>
            <div class="dis-whs" role="radiogroup" aria-label="کۆگا">
                <?php foreach ($warehouses as $w): $isMain = (int)$w['id'] === $defaultWh; ?>
                <label class="dis-wh">
                    <input type="radio" name="disWh" value="<?= (int)$w['id'] ?>" data-name="<?= erp_esc($w['name']) ?>" <?= $isMain ? 'checked' : '' ?>>
                    <span class="dis-wh-box"><i class="fas <?= $isMain ? 'fa-warehouse' : 'fa-building' ?>"></i><span><?= erp_esc($w['name']) ?></span><?php if ($isMain): ?><em>سەرەکی</em><?php endif; ?></span>
                </label>
                <?php endforeach; ?>
            </div>
            <div class="dis-note" id="disWhNote" hidden><i class="fas fa-lock"></i> مەواد لە لیستەکەدایە و بەردەستی ئەو کۆگایە نیشان دەدات. بۆ گۆڕینی کۆگا سەرەتا لیستەکە پاک بکەرەوە.</div>
        </section>

        <div class="dis-msg" id="disMsg" role="alert"></div>
        <div class="dis-foot">
            <a class="btn btn-subtle" id="disBack" href="<?= APP_URL ?>/erp/doctor-issues.php">وەسڵەکان</a>
            <button type="button" class="btn btn-subtle" id="disCancel" hidden>پاشگەزبوونەوە</button>
            <button type="button" class="dis-go" id="disGo" disabled>دەستپێکردن <i class="fas fa-arrow-left"></i></button>
        </div>
    </div>
</div>

<style>
.did-overlay { position: fixed; inset: 0; z-index: 1040; background: rgba(15, 23, 42, .45); display: flex; align-items: center; justify-content: center; padding: 1.2vh 1vw; }
.did-box { width: min(1760px, 100%); height: 100%; background: var(--bg-solid-base, #f3f3f3); border-radius: 14px; overflow: hidden; display: flex; flex-direction: column; box-shadow: 0 24px 60px rgba(15, 23, 42, .35); }
.did-head { display: flex; align-items: center; gap: 10px; padding: 10px 14px; background: #fff; border-bottom: 1px solid #e5e7eb; }
.did-ico { width: 34px; height: 34px; border-radius: 9px; display: inline-flex; align-items: center; justify-content: center; color: #fff; background: linear-gradient(135deg, #0ea5e9, #0369a1); }
.did-head h3 { margin: 0; font-size: 16px; font-weight: 800; color: #1f2937; }
.did-tools { margin-inline-start: auto; display: flex; gap: 6px; }
.did-btn { width: 34px; height: 34px; border: 1px solid #e5e7eb; border-radius: 8px; background: #fff; color: #475569; display: inline-flex; align-items: center; justify-content: center; cursor: pointer; text-decoration: none; }
.did-btn:hover { background: #f1f5f9; color: #0f172a; }
.did-body { flex: 1; min-height: 0; padding: 12px; display: flex; flex-direction: column; }
body.did-open { overflow: hidden; }
/* the list takes the height; the total, the buttons and the note sit beside it */
.di { --k: #94a3b8; flex: 1; min-height: 0; display: grid; grid-template-columns: minmax(0, 1fr) 360px; gap: 12px; }
.di-main, .di-side { display: flex; flex-direction: column; gap: 12px; min-height: 0; }
.di.is-normal { --k: #0d9488; }
.di.is-implant { --k: #7c3aed; }
.di-head { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; padding: 12px 14px; background: #fff;
    border: 1px solid #e6ebf1; border-radius: 14px; border-inline-start: 5px solid var(--k); }
.di-kind-chip { display: inline-flex; align-items: center; gap: 8px; padding: 8px 14px; border: 0; border-radius: 999px; cursor: pointer;
    background: var(--k); color: #fff; font-family: inherit; font-size: 14px; font-weight: 800; }
.di-doc-chip { display: inline-flex; align-items: center; gap: 10px; padding: 4px 12px 4px 6px; border: 1px solid #e2e8f0; border-radius: 999px;
    background: #f8fafc; cursor: pointer; font-family: inherit; text-align: start; }
.di-doc-chip small { display: block; font-size: 10.5px; color: #94a3b8; }
.di-doc-chip b { font-size: 14px; color: #0f172a; }
.di-avatar { width: 34px; height: 34px; border-radius: 50%; display: grid; place-items: center; flex-shrink: 0;
    background: var(--k, #64748b); color: #fff; font-weight: 800; font-size: 15px; }
.di-meta { display: flex; align-items: center; gap: 16px; flex-wrap: wrap; color: #64748b; font-size: 12.5px; }
.di-meta i { color: #94a3b8; }
.di-meta b { color: #334155; }
.di-date { display: inline-flex; align-items: center; gap: 6px; }
.di-date input { border: 1px solid #dbe3ea; border-radius: 7px; padding: 4px 8px; font-family: inherit; font-size: 12.5px; }
.di-change { margin-inline-start: auto; border: 1px solid #dbe3ea; background: #fff; border-radius: 8px; padding: 7px 12px;
    font-family: inherit; font-size: 12.5px; font-weight: 600; color: #334155; cursor: pointer; }
.di-change:hover { border-color: var(--k); color: var(--k); }
.di-price { display: inline-flex; align-items: center; gap: 2px; padding: 3px; border-radius: 10px; background: #f1f5f9; font-size: 12.5px; }
.di-price-label { padding: 0 8px; color: #64748b; font-weight: 700; white-space: nowrap; }
.di-price label input { position: absolute; opacity: 0; pointer-events: none; }
.di-price label span { display: inline-block; padding: 5px 11px; border-radius: 7px; font-weight: 700; color: #64748b; cursor: pointer; white-space: nowrap; }
.di-price label input:checked + span { background: #fff; color: #0369a1; box-shadow: 0 1px 2px rgba(15, 23, 42, .1); }
.di-price label input:focus-visible + span { outline: 2px solid #0369a1; }
.di-price.is-fixed b { padding: 5px 11px; color: #0369a1; }
.di-toolbar { display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap; padding: 9px 14px;
    border-radius: 12px; background: var(--k); color: #fff; font-size: 12.5px; }
.di-toolbar i { opacity: .85; }
.di-keys { opacity: .9; white-space: nowrap; }
.di-keys kbd { background: rgba(255, 255, 255, .2); border-radius: 4px; padding: 1px 6px; font-family: inherit; font-size: 11px; }
.di-grid { flex: 1; background: #fff; border: 1px solid #e6ebf1; border-radius: 12px; overflow: auto; min-height: 220px; }
.di-table { width: 100%; border-collapse: collapse; font-size: 13px; }
.di-table thead th { position: sticky; top: 0; z-index: 1; background: #f1f5f9; padding: 9px 10px; font-size: 12px; font-weight: 700;
    color: #475569; text-align: right; border-bottom: 2px solid #e2e8f0; }
.di-table td { padding: 0 10px; border-bottom: 1px solid #f1f5f9; height: 42px; }
.di-table td input { width: 100%; border: 0; background: transparent; padding: 8px 4px; font-family: inherit; font-size: 13px; }
.di-table td input:focus { outline: 2px solid var(--k); border-radius: 6px; background: #fffef5; }
.di-table tr.is-short td { background: #fff1f2; }
.di-n { text-align: left !important; direction: ltr; font-variant-numeric: tabular-nums; white-space: nowrap; }
.di-idx { width: 40px; text-align: center !important; color: #94a3b8; }
.di-del { width: 40px; text-align: center; }
.di-del button { border: 0; background: none; color: #dc2626; cursor: pointer; font-size: 14px; padding: 6px; }
.di-name { min-width: 260px; }
.di-name input { font-weight: 700; color: #0f172a; }
.di-name small { display: block; color: #94a3b8; font-size: 11px; padding: 0 4px 4px; margin-top: -4px; }
.di-table tr.is-blank td { background: #fafcff; }
.di-table tr.is-blank .di-name input { font-weight: 500; }
.di-table tr.is-blank .di-idx i { color: var(--k); opacity: .6; }
.di-blank-hint { color: #cbd5e1; font-size: 12px; }
.di-nosale { display: block; font-size: 10px; color: #b45309; font-weight: 700; direction: rtl; text-align: left; }
.di-table tr.is-sel td { box-shadow: inset 0 0 0 9999px rgba(3, 105, 161, .04); }
.di-notes, .di-total { background: #fff; border: 1px solid #e6ebf1; border-radius: 12px; padding: 12px 14px; }
.di-notes label { display: block; font-size: 12px; font-weight: 700; color: #475569; margin-bottom: 6px; }
.di-notes textarea { width: 100%; border: 1px solid #dbe3ea; border-radius: 8px; padding: 8px 10px; font-family: inherit; font-size: 13px; resize: vertical; }
.di-msg { min-height: 18px; margin-top: 6px; font-size: 12.5px; font-weight: 700; color: #dc2626; }
.di-total-row { display: flex; justify-content: space-between; align-items: center; padding: 4px 0; font-size: 13px; color: #475569; }
.di-total-row b { direction: ltr; font-variant-numeric: tabular-nums; color: #0f172a; }
.di-total-row.grand { border-top: 2px solid #0f172a; margin-top: 6px; padding-top: 8px; font-size: 20px; font-weight: 800; }
.di-total-row.grand b { color: var(--k); }
.di-total-note { margin: 6px 0 10px; font-size: 11.5px; color: #64748b; line-height: 1.6; }
.di-actions { display: flex; gap: 8px; }
.di-actions .btn { flex: 1; min-width: 0; justify-content: center; min-height: 42px; padding-inline: 10px; white-space: nowrap; }
.di-actions .btn-subtle { flex: 0 0 42px; padding: 0; }
/* start step */
.dis-overlay { position: fixed; inset: 0; z-index: 1200; display: grid; place-items: center; padding: 20px;
    background: rgba(15, 23, 42, .45); backdrop-filter: blur(3px); }
.dis-overlay[hidden], .dis-card [hidden] { display: none !important; }
.dis-card { width: min(620px, 100%); max-height: calc(100vh - 40px); overflow: auto; background: #fff; border-radius: 18px;
    box-shadow: 0 24px 60px -20px rgba(15, 23, 42, .5); }
.dis-head { display: flex; gap: 12px; align-items: center; padding: 20px 22px 6px; }
.dis-icon { width: 46px; height: 46px; border-radius: 13px; display: grid; place-items: center; color: #fff; font-size: 20px;
    background: linear-gradient(135deg, #0ea5e9, #0369a1); }
.dis-head h3 { margin: 0; font-size: 17px; color: #0f172a; }
.dis-head p { margin: 2px 0 0; font-size: 12.5px; color: #64748b; }
.dis-step { padding: 12px 22px 4px; }
.dis-label { display: flex; align-items: center; gap: 8px; font-size: 13px; font-weight: 700; color: #1f2937; margin-bottom: 9px; }
.dis-num { width: 22px; height: 22px; border-radius: 50%; display: grid; place-items: center; background: #0369a1; color: #fff; font-size: 12px; }
.dis-kinds { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
.dis-kind input, .dis-doc input, .dis-wh input { position: absolute; opacity: 0; pointer-events: none; }
.dis-kind-box { display: flex; flex-direction: column; align-items: center; gap: 4px; padding: 16px 10px; border-radius: 14px;
    border: 2px solid #e2e8f0; cursor: pointer; transition: border-color .15s, background .15s, transform .15s; }
.dis-kind-box i { font-size: 24px; color: #94a3b8; }
.dis-kind-box b { font-size: 16px; color: #0f172a; }
.dis-kind-box small { font-size: 11.5px; color: #94a3b8; }
.dis-kind--normal { --kc: #0d9488; }
.dis-kind--implant { --kc: #7c3aed; }
.dis-kind-box:hover { border-color: var(--kc); }
.dis-kind input:checked + .dis-kind-box { border-color: var(--kc); background: color-mix(in srgb, var(--kc) 8%, #fff); transform: translateY(-1px); }
.dis-kind input:checked + .dis-kind-box i, .dis-kind input:checked + .dis-kind-box b { color: var(--kc); }
.dis-kind input:focus-visible + .dis-kind-box, .dis-doc input:focus-visible + .dis-doc-box, .dis-wh input:focus-visible + .dis-wh-box {
    outline: 2px solid #0369a1; outline-offset: 2px; }
.dis-search { position: relative; margin-bottom: 8px; }
.dis-search i { position: absolute; top: 50%; inset-inline-start: 12px; transform: translateY(-50%); color: #94a3b8; }
.dis-search input { width: 100%; height: 40px; padding-inline: 36px 12px; border: 1.5px solid #dbe3ea; border-radius: 10px; font-family: inherit; font-size: 13.5px; }
.dis-search input:focus { outline: none; border-color: #0369a1; }
.dis-doctors { display: grid; grid-template-columns: repeat(auto-fill, minmax(170px, 1fr)); gap: 8px; max-height: 210px; overflow: auto; padding: 2px; }
.dis-doc-box { display: flex; align-items: center; gap: 9px; padding: 7px 10px; border-radius: 12px; border: 1.5px solid #e2e8f0; cursor: pointer;
    font-size: 13px; font-weight: 600; color: #334155; }
.dis-doc-box .di-avatar { width: 30px; height: 30px; font-size: 13px; background: #94a3b8; }
.dis-doc-box:hover { border-color: #0369a1; }
.dis-doc input:checked + .dis-doc-box { border-color: #0369a1; background: #f0f9ff; color: #0f172a; }
.dis-doc input:checked + .dis-doc-box .di-avatar { background: #0369a1; }
.dis-doc.is-hidden { display: none; }
.dis-whs { display: grid; grid-template-columns: repeat(auto-fill, minmax(150px, 1fr)); gap: 8px; }
.dis-wh-box { display: flex; align-items: center; gap: 8px; padding: 9px 12px; border-radius: 10px; border: 1.5px solid #e2e8f0; cursor: pointer;
    font-size: 13px; font-weight: 600; color: #334155; }
.dis-wh-box i { color: #94a3b8; }
.dis-wh-box em { margin-inline-start: auto; font-style: normal; font-size: 10.5px; padding: 2px 8px; border-radius: 999px; background: #ecfdf5; color: #047857; }
.dis-wh input:checked + .dis-wh-box { border-color: #0369a1; background: #f0f9ff; color: #0f172a; }
.dis-wh input:checked + .dis-wh-box i { color: #0369a1; }
.dis-wh input:disabled + .dis-wh-box { opacity: .5; cursor: not-allowed; }
.dis-note { margin-top: 8px; padding: 8px 11px; border-radius: 8px; background: #fffbeb; color: #92400e; font-size: 12px; }
.dis-empty { padding: 10px; color: #94a3b8; font-size: 12.5px; }
.dis-msg { min-height: 18px; padding: 6px 22px 0; font-size: 12.5px; font-weight: 700; color: #dc2626; }
.dis-foot { display: flex; gap: 8px; align-items: center; padding: 10px 22px 20px; }
.dis-go { margin-inline-start: auto; display: inline-flex; align-items: center; gap: 9px; padding: 11px 24px; border: 0; border-radius: 11px;
    background: #0369a1; color: #fff; font-size: 14px; font-weight: 800; font-family: inherit; cursor: pointer; }
.dis-go:disabled { background: #cbd5e1; cursor: not-allowed; }
.dis-go:focus-visible { outline: 3px solid rgba(3, 105, 161, .35); outline-offset: 2px; }
.ac-box{position:absolute;z-index:60;background:#fff;border:1px solid #ccc;border-radius:8px;box-shadow:0 8px 24px rgba(0,0,0,.16);max-height:280px;overflow:auto;min-width:300px}
.ac-item{padding:8px 11px;cursor:pointer;font-size:13px;border-bottom:1px solid #f2f2f2}
.ac-item:hover,.ac-item.on{background:#f0f9ff}
.ac-item .meta{font-size:11px;color:#888}
@media (max-width: 900px) {
    .did-overlay { padding: 0; } .did-box { border-radius: 0; } .did-body { overflow: auto; }
    .di { grid-template-columns: minmax(0, 1fr); flex: none; } .di-grid { flex: none; min-height: 240px; } .di-keys { display: none; }
}
@media (prefers-reduced-motion: reduce) { .dis-kind-box { transition: none; } .dis-overlay { backdrop-filter: none; } }
</style>

<script src="<?= asset_url('assets/js/erp-common.js') ?>"></script>
<script>
(function () {
    const API_P = '<?= APP_URL ?>/erp/api/products.php';
    const API_D = '<?= APP_URL ?>/erp/api/doctor-issues.php';
    const APP = '<?= APP_URL ?>';
    const KINDS = { normal: 'ئاسایی', implant: 'ئیمپلانت' };
    const MODES = { sale: 'نرخی فرۆشتن', cost: 'نرخی کڕین' };
    let MODE = '<?= $priceMode === 'cost' ? 'cost' : 'sale' ?>';
    const U = window.ErpUI;
    const $ = id => document.getElementById(id);
    const root = $('diRoot'), overlay = $('disOverlay');
    let rows = [], saving = false, acTimer = null, acReq = 0, selRow = -1;
    const state = { ready: false, kind: '', doctorId: 0, doctorName: '', warehouseId: <?= (int)$defaultWh ?>, warehouseName: '' };

    const msg = t => { $('diMsg').textContent = t || ''; };
    const initial = name => (String(name || '').replace(/^د\.\s*/, '').trim().charAt(0) || '؟');

    /* ---------- the dialog ---------- */
    // Both on <body>, the start step after the dialog so it stays on top.
    document.body.appendChild($('didDialog'));
    document.body.appendChild(overlay);
    document.body.classList.add('did-open');

    // Back to the page it was opened from (the store page when opened
    // directly); materials on the list ask first, since closing drops them.
    window.diClose = function () {
        if (filled().length && !confirm('ئەم وەسڵە پاشەکەوت نەکراوە. داخرێت و مەوادەکان لاببرێن؟')) return;
        let back = null;
        try {
            const ref = new URL(document.referrer);
            if (ref.origin === location.origin && !/\/erp\/doctor-issue\.php$/.test(ref.pathname)) back = ref.href;
        } catch (e) {}
        location.href = back || APP + '/erp/index.php';
    };

    /* ---------- start step ---------- */
    function refreshGo() {
        const kind = document.querySelector('input[name="disKind"]:checked');
        const doc = document.querySelector('input[name="disDoc"]:checked');
        const wh = document.querySelector('input[name="disWh"]:checked');
        $('disGo').disabled = !(kind && doc && wh);
    }
    function open(fresh) {
        if (fresh) { state.ready = false; }
        document.querySelectorAll('input[name="disKind"]').forEach(r => { r.checked = state.ready && r.value === state.kind; });
        document.querySelectorAll('input[name="disDoc"]').forEach(r => { r.checked = state.ready && Number(r.value) === state.doctorId; });
        const whLocked = filled().length > 0;
        document.querySelectorAll('input[name="disWh"]').forEach(r => {
            r.checked = Number(r.value) === state.warehouseId;
            r.disabled = whLocked && !r.checked;
        });
        $('disWhNote').hidden = !whLocked;
        $('disCancel').hidden = !state.ready;
        $('disBack').hidden = state.ready;
        $('disQ').value = ''; filterDoctors('');
        $('disMsg').textContent = '';
        refreshGo();
        overlay.hidden = false;
        root.inert = true;
        U.hideAC();
        setTimeout(() => {
            const first = state.ready ? $('disGo') : document.querySelector('input[name="disKind"]');
            if (first) first.focus();
        }, 0);
    }
    function close() { overlay.hidden = true; root.inert = false; }
    function apply() {
        const kind = document.querySelector('input[name="disKind"]:checked');
        const doc = document.querySelector('input[name="disDoc"]:checked');
        const wh = document.querySelector('input[name="disWh"]:checked');
        if (!kind) { $('disMsg').textContent = 'جۆری خەرجی هەڵبژێرە: ئاسایی یان ئیمپلانت'; return; }
        if (!doc) { $('disMsg').textContent = 'دکتۆر هەڵبژێرە'; $('disQ').focus(); return; }
        if (!wh) { $('disMsg').textContent = 'کۆگا هەڵبژێرە'; return; }
        Object.assign(state, {
            ready: true, kind: kind.value, doctorId: Number(doc.value), doctorName: doc.dataset.name,
            warehouseId: Number(wh.value), warehouseName: wh.dataset.name
        });
        close();
        paintHead();
        focusEntry();
    }
    function paintHead() {
        root.classList.toggle('is-normal', state.kind === 'normal');
        root.classList.toggle('is-implant', state.kind === 'implant');
        $('diKindLabel').textContent = state.ready ? KINDS[state.kind] : '—';
        $('diDocName').textContent = state.ready ? state.doctorName : 'هەڵنەبژێردراوە';
        $('diDocAvatar').textContent = state.ready ? initial(state.doctorName) : '؟';
        $('diWhName').textContent = state.warehouseName || '—';
        $('diTotalNote').textContent = state.ready
            ? 'ئەم بڕە (' + MODES[MODE] + ') دەچێتە خەرجی کۆگا (' + KINDS[state.kind] + ') لەسەر ناوی ' + state.doctorName + '.'
            : 'ئەم بڕە دەچێتە خەرجی کۆگا لەسەر ناوی دکتۆر.';
    }
    function filterDoctors(q) {
        q = String(q || '').trim().toLowerCase();
        document.querySelectorAll('.dis-doc').forEach(l => l.classList.toggle('is-hidden', q !== '' && l.dataset.name.indexOf(q) === -1));
    }
    $('disQ').addEventListener('input', e => filterDoctors(e.target.value));
    $('disQ').addEventListener('keydown', e => {
        if (e.key !== 'Enter') return;
        e.preventDefault();
        const visible = Array.from(document.querySelectorAll('.dis-doc:not(.is-hidden) input'));
        if (visible.length === 1) { visible[0].checked = true; refreshGo(); $('disGo').focus(); }
    });
    overlay.addEventListener('change', e => {
        refreshGo();
        if (e.target.name === 'disKind') { setTimeout(() => $('disQ').focus(), 0); }
        if (e.target.name === 'disDoc' && !$('disGo').disabled) { setTimeout(() => $('disGo').focus(), 0); }
    });
    $('disGo').addEventListener('click', apply);
    $('disCancel').addEventListener('click', () => { close(); focusEntry(); });
    document.addEventListener('keydown', e => {
        if (overlay.hidden) return;
        if (e.key === 'Escape' && state.ready) { e.preventDefault(); e.stopImmediatePropagation(); close(); focusEntry(); return; }
        if (/^F\d+$/.test(e.key) || (e.ctrlKey && /^[sSpP]$/.test(e.key))) { e.preventDefault(); e.stopImmediatePropagation(); }
    }, true);

    /* ---------- row model ---------- */
    // A row is either a picked material or the empty entry row; there is
    // always exactly one empty row at the bottom to type into.
    const blank = () => ({ product_id: 0, name: '', code: '', unit: '', quantity: 1, sale_price: 0, cost: 0, stock: 0 });
    const isBlank = r => !r.product_id;
    const filled = () => rows.filter(r => !isBlank(r));
    const costOf = p => Number(p.avg_cost) > 0 ? Number(p.avg_cost) : Number(p.purchase_price || 0);
    const noSale = r => MODE === 'sale' && !(Number(r.sale_price) > 0);
    const priceOf = r => (MODE === 'sale' && Number(r.sale_price) > 0) ? Number(r.sale_price) : Number(r.cost);
    const lineTotal = r => Number(r.quantity) * priceOf(r);
    function ensureBlank() { if (!rows.length || !isBlank(rows[rows.length - 1])) rows.push(blank()); }

    function render() {
        ensureBlank();
        $('diBody').innerHTML = rows.map((r, i) => {
            if (isBlank(r)) {
                return '<tr class="is-blank' + (i === selRow ? ' is-sel' : '') + '" data-row="' + i + '">'
                    + '<td class="di-idx"><i class="fas fa-plus"></i></td>'
                    + '<td class="di-name"><input data-i="' + i + '" data-f="name" value="' + U.esc(r.name) + '" autocomplete="off"'
                    + ' placeholder="ناو، کۆد یان بارکۆدی مەواد بنووسە..." aria-label="مەواد"></td>'
                    + '<td colspan="5" class="di-blank-hint">' + (filled().length ? '' : 'یەکەم مەواد لێرە بنووسە') + '</td></tr>';
            }
            const short = Number(r.quantity) > Number(r.stock);
            return '<tr class="' + (short ? 'is-short' : '') + (i === selRow ? ' is-sel' : '') + '" data-row="' + i + '">'
                + '<td class="di-idx">' + (i + 1) + '</td>'
                + '<td class="di-name"><input data-i="' + i + '" data-f="name" value="' + U.esc(r.name) + '" autocomplete="off" aria-label="مەواد">'
                + '<small>' + U.esc(r.code) + (r.unit ? ' · ' + U.esc(r.unit) : '') + '</small></td>'
                + '<td class="di-n"' + (short ? ' style="color:#dc2626;font-weight:800"' : '') + '>' + U.qf(r.stock) + '</td>'
                + '<td class="di-n"><input type="number" min="0" step="any" class="di-n" value="' + r.quantity + '" data-i="' + i + '" data-f="qty" aria-label="بڕ"></td>'
                + '<td class="di-n" data-price="' + i + '">' + U.nf(Math.round(priceOf(r)))
                + (noSale(r) ? '<span class="di-nosale">نرخی فرۆشتنی نییە — نرخی کڕین</span>' : '') + '</td>'
                + '<td class="di-n" data-total="' + i + '"><b>' + U.nf(Math.round(lineTotal(r))) + '</b></td>'
                + '<td class="di-del"><button type="button" data-del="' + i + '" title="لابردن" aria-label="لابردن"><i class="fas fa-times"></i></button></td>'
                + '</tr>';
        }).join('');
        totals();
    }
    function cell(i, f) { return document.querySelector('#diBody [data-i="' + i + '"][data-f="' + f + '"]'); }
    function focusCell(i, f, caretEnd) {
        const el = cell(i, f);
        if (!el) return;
        selRow = i;
        document.querySelectorAll('#diBody tr').forEach(tr => tr.classList.toggle('is-sel', Number(tr.dataset.row) === i));
        el.focus();
        if (caretEnd) { try { const n = el.value.length; el.setSelectionRange(n, n); } catch (e) {} }
        else if (el.select) { el.select(); }
    }
    function focusEntry() { render(); focusCell(rows.length - 1, 'name'); }
    function totals() {
        $('diCount').textContent = filled().length;
        $('diSum').textContent = U.nf(Math.round(filled().reduce((s, r) => s + lineTotal(r), 0)));
    }

    function pick(i, p, qty) {
        const dup = rows.findIndex((r, j) => j !== i && r.product_id === Number(p.id));
        if (dup >= 0) {
            rows[i] = blank();
            render();
            focusCell(dup, 'qty');
            msg('«' + p.name + '» پێشتر لە ڕیزی ' + (dup + 1) + ' دایە — بڕەکەی لێرە بگۆڕە');
            return;
        }
        // A pack barcode brings its own count; otherwise a row that was being
        // re-chosen keeps the quantity already typed on it.
        const keepQty = Number(rows[i].quantity) > 0 ? Number(rows[i].quantity) : 1;
        rows[i] = {
            product_id: Number(p.id), name: p.name, code: p.product_code || '', unit: p.unit_code || '',
            quantity: (qty && qty !== 1) ? qty : keepQty,
            sale_price: Number(p.sale_price || 0), cost: costOf(p), stock: Number(p.stock || 0)
        };
        msg('');
        render();
        focusCell(i, 'qty');
    }
    function removeRow(i) {
        if (!rows[i] || isBlank(rows[i])) return;
        rows.splice(i, 1);
        render();
        focusCell(Math.min(i, rows.length - 1), 'name');
    }

    /* ---------- the مەواد cell: search as you type ---------- */
    async function lookup(i, commit) {
        const r = rows[i], el = cell(i, 'name');
        if (!r || !el) return;
        const q = el.value.trim();
        if (!q) return;
        if (!state.ready) { open(); return; }
        const my = ++acReq;
        if (commit) {
            const b = await U.getJson(API_P + '?action=barcode&code=' + encodeURIComponent(q) + '&warehouse_id=' + state.warehouseId);
            if (my !== acReq || rows[i] !== r) return;
            if (b && b.ok && b.product) { U.hideAC(); pick(i, b.product, Number(b.product.unit_factor || 1)); return; }
        }
        const s = await U.getJson(API_P + '?action=search&q=' + encodeURIComponent(q) + '&warehouse_id=' + state.warehouseId);
        // Drop an answer that arrives after the row changed or a newer search started.
        if (my !== acReq || rows[i] !== r || !isBlank(r) || r.name.trim() !== q) return;
        const res = (s && s.ok) ? s.results : [];
        if (commit) {
            const exact = res.filter(p => p.barcode === q || p.product_code === q);
            if (exact.length === 1) { U.hideAC(); pick(i, exact[0], 1); return; }
            if (res.length === 1) { U.hideAC(); pick(i, res[0], 1); return; }
            if (!res.length) { msg('مەواد نەدۆزرایەوە: ' + q); return; }
        }
        const cur = cell(i, 'name');
        if (!res.length || !cur || document.activeElement !== cur) { if (!res.length) U.hideAC(); return; }
        U.showAC(cur, res.map(p => ({
            label: p.name,
            meta: 'کۆد ' + p.product_code + ' · بەردەست ' + U.qf(p.stock) + ' · ' + MODES[MODE] + ' '
                + U.nf(Math.round(MODE === 'sale' && Number(p.sale_price) > 0 ? Number(p.sale_price) : costOf(p))),
            data: p
        })), p => pick(i, p, 1));
    }

    $('diBody').addEventListener('input', e => {
        const el = e.target, i = Number(el.dataset.i), f = el.dataset.f;
        if (isNaN(i) || !rows[i]) return;
        if (f === 'name') {
            if (!state.ready) { el.value = rows[i].name; open(); return; }
            if (!isBlank(rows[i])) {
                // Retyping a picked material's name lets go of it: the row has
                // to be chosen again, or it would keep the old material.
                rows[i] = Object.assign(blank(), { name: el.value, quantity: rows[i].quantity });
                render();
                focusCell(i, 'name', true);
            } else {
                rows[i].name = el.value;
            }
            clearTimeout(acTimer);
            if (!el.value.trim()) { U.hideAC(); return; }
            acTimer = setTimeout(() => lookup(i, false), 200);
            return;
        }
        if (f === 'qty') {
            const v = parseFloat(el.value);
            rows[i].quantity = isNaN(v) || v < 0 ? 0 : v;
            const t = document.querySelector('[data-total="' + i + '"]');
            if (t) t.innerHTML = '<b>' + U.nf(Math.round(lineTotal(rows[i]))) + '</b>';
            el.closest('tr').classList.toggle('is-short', rows[i].quantity > rows[i].stock);
            totals();
        }
    });
    $('diBody').addEventListener('keydown', e => {
        const el = e.target, i = Number(el.dataset.i), f = el.dataset.f;
        if (isNaN(i) || !rows[i]) return;
        if (f === 'name' && U.acHandleKey(e)) return;
        if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
            e.preventDefault();
            const j = i + (e.key === 'ArrowDown' ? 1 : -1);
            if (j >= 0 && j < rows.length) focusCell(j, cell(j, f) ? f : 'name');
            return;
        }
        if (e.key === 'Delete' && e.ctrlKey) { e.preventDefault(); removeRow(i); return; }
        if (e.key !== 'Enter') return;
        e.preventDefault();
        if (f === 'name') {
            clearTimeout(acTimer);
            if (isBlank(rows[i])) {
                if (el.value.trim()) lookup(i, true);
                else if (filled().length) $('diNotes').focus();
            } else {
                focusCell(i, 'qty');
            }
            return;
        }
        if (f === 'qty') {
            const j = Math.min(i + 1, rows.length - 1);
            focusCell(j, 'name');
        }
    });
    $('diBody').addEventListener('focusin', e => {
        const i = Number(e.target.dataset.i);
        if (isNaN(i)) return;
        selRow = i;
        document.querySelectorAll('#diBody tr').forEach(tr => tr.classList.toggle('is-sel', Number(tr.dataset.row) === i));
    });
    $('diBody').addEventListener('focusout', e => {
        if (e.target.dataset && e.target.dataset.f === 'name') setTimeout(U.hideAC, 150);
    });
    $('diBody').addEventListener('click', e => {
        const b = e.target.closest('[data-del]');
        if (b) removeRow(Number(b.dataset.del));
    });

    /* ---------- the price setting (saved once, for every voucher) ---------- */
    document.querySelectorAll('input[name="diPriceMode"]').forEach(r => r.addEventListener('change', async () => {
        const mode = r.value, before = MODE;
        const fd = new FormData();
        fd.append('action', 'set_price_mode');
        fd.append('mode', mode);
        try {
            const res = await fetch(API_D + '?action=set_price_mode', { method: 'POST', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest' } });
            const d = await res.json();
            if (!d || !d.ok) throw new Error((d && d.error) || 'هەڵە');
            MODE = d.mode;
            showToast('success', 'پاشەکەوتکرا: مەواد بۆ دکتۆر بە ' + MODES[MODE]);
        } catch (err) {
            document.querySelector('input[name="diPriceMode"][value="' + before + '"]').checked = true;
            showToast('error', err.message || 'هەڵە');
        }
        const keep = selRow;
        render(); paintHead();
        if (keep >= 0) focusCell(Math.min(keep, rows.length - 1), 'name');
    }));

    /* ---------- save ---------- */
    async function save(print) {
        if (saving) return;
        if (!state.ready) { open(); return; }
        msg('');
        for (let i = 0; i < rows.length; i++) {
            if (isBlank(rows[i]) && rows[i].name.trim()) {
                msg('ڕیزی ' + (i + 1) + ': مەوادەکە هەڵنەبژێردراوە — Enter لێبدە یان لە لیستەکە هەڵیبژێرە');
                focusCell(i, 'name', true); return;
            }
        }
        const lines = filled();
        if (!lines.length) { msg('هیچ مەوادێک زیاد نەکراوە'); focusEntry(); return; }
        const zero = lines.find(r => !(Number(r.quantity) > 0));
        if (zero) { msg('بڕی «' + zero.name + '» پێویستە'); focusCell(rows.indexOf(zero), 'qty'); return; }
        const short = lines.find(r => Number(r.quantity) > Number(r.stock));
        if (short) { msg('بڕی «' + short.name + '» لە کۆگا کەمترە لە داواکراو'); focusCell(rows.indexOf(short), 'qty'); return; }
        saving = true;
        $('diSave').disabled = true;
        try {
            const d = await U.postJson(API_D + '?action=save', {
                expense_kind: state.kind,
                doctor_id: state.doctorId,
                warehouse_id: state.warehouseId,
                issue_date: $('diDate').value,
                notes: $('diNotes').value.trim(),
                items: lines.map(r => ({ product_id: r.product_id, quantity: r.quantity }))
            });
            if (!d || !d.ok) { msg((d && d.error) || 'هەڵە لە پاشەکەوتکردن'); return; }
            showToast('success', 'وەسڵ ' + d.issue_number + ' پاشەکەوتکرا — ' + U.nf(Math.round(d.total_amount))
                + ' (' + MODES[d.price_mode] + ') چووە خەرجی کۆگا (' + KINDS[state.kind] + ')');
            if (d.no_sale_price && d.no_sale_price.length) {
                showToast('warning', 'ئەم مەوادانە نرخی فرۆشتنیان نییە و بە نرخی کڕین حساب کران: ' + d.no_sale_price.join('، '));
            }
            if (d.zero_cost && d.zero_cost.length) {
                showToast('warning', 'ئەم مەوادانە نرخیان تۆمار نەکراوە و بە سفر دەرچوون: ' + d.zero_cost.join('، '));
            }
            if (print) window.open(APP + '/erp/print-doctor-issue.php?id=' + d.id, '_blank');
            rows = []; selRow = -1; $('diNotes').value = ''; render();
            // The next voucher is a new hand-over: ask for kind and doctor again.
            open(true);
        } finally {
            saving = false;
            $('diSave').disabled = false;
        }
    }
    function clearList() { rows = []; selRow = -1; msg(''); focusEntry(); }

    document.addEventListener('keydown', e => {
        if (!overlay.hidden) return;
        if (e.key === 'F2') { e.preventDefault(); focusCell(rows.length - 1, 'name'); return; }
        if (e.key === 'F4') { e.preventDefault(); open(); return; }
        if (e.ctrlKey && /^[sS]$/.test(e.key)) { e.preventDefault(); save(false); return; }
        if (e.ctrlKey && /^[pP]$/.test(e.key)) { e.preventDefault(); save(true); return; }
    });

    window.DI = { open: () => open(false) };
    window.diSave = save;
    window.diClear = clearList;

    render();
    paintHead();
    open(true);
})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
