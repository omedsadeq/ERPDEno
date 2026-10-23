<?php
/**
 * پلانی چارەسەر tab — data, dialogs, look and behaviour. The markup it fills
 * is patients/_plans_tab.php.
 *
 * Every write goes through ajax/treatment_plan.php (add_item / update_item /
 * remove_item / create_plan), which applies the same rules as the plan page:
 * only the examining doctor changes a plan's work, and only while it is open.
 * Nothing here is money — see classes/TreatmentPlan.php.
 */
if (empty($canViewPlans)) {
    return;
}
require_once __DIR__ . '/../classes/DentalChart.php';
require_once __DIR__ . '/../classes/Treatment.php';

$tpwViewerId   = (int)($currentUser['id'] ?? 0);
$tpwRestricted = AccessGuard::isRestrictedDoctor();
$tpwPlans = [];
foreach ($treatmentPlans as $tp) {
    $tpwState      = TreatmentPlan::designationState($tp);
    $tpwTreatingId = TreatmentPlan::treatingDoctorId($tp);
    $tpwTreating   = (string)($tp['treating_doctor_name'] ?? '');
    $tpwPlans[] = [
        'id'        => (int)$tp['id'],
        'number'    => (string)$tp['plan_number'],
        'status'    => (string)$tp['status'],
        'scope'     => ($tp['scope_type'] ?? 'tooth') === 'arch' ? 'arch' : 'tooth',
        'arch'      => $tp['selected_arch'] ?: null,
        'doctor'    => (string)($tp['doctor_name'] ?? ''),
        'date'      => date('Y-m-d', strtotime($tp['created_at'])),
        'complaint' => (string)($tp['chief_complaint'] ?? ''),
        'duration'  => (string)($tp['duration_text'] ?? ''),
        // کێ چارەسەر دەکات؟ — the examiner's decision, shown; changed on the plan page.
        'route'     => ['state' => $tpwState, 'label' => [
            'undecided'          => 'دیاری نەکراوە',
            'self'               => 'دکتۆری پشکنەر خۆی',
            'doctor'             => 'نێردراوە بۆ ' . $tpwTreating,
            'awaiting_reception' => 'چەند دکتۆرێک — چاوەڕوانی پێشوازی',
            'chosen'             => 'پێشوازی ناردی بۆ ' . $tpwTreating,
        ][$tpwState] ?? ''],
        // The same rules as treatment-plans/view.php: the plan's work is the
        // examiner's note, and work moves only into the treating doctor's case.
        'canWrite'    => $canWritePlans && (!$tpwRestricted || (int)$tp['doctor_id'] === $tpwViewerId),
        'canTransfer' => $canTransferPlans && $tpwTreatingId !== null
            && (!$tpwRestricted || $tpwTreatingId === $tpwViewerId),
        'totals' => [
            'estimated' => (float)($tp['total_estimated'] ?? 0),
            'discount'  => (float)($tp['total_discount'] ?? 0),
            'final'     => (float)($tp['total_final'] ?? 0),
        ],
        'items' => $treatmentPlanManager->getItems((int)$tp['id']),
    ];
}

$tpwChart = new DentalChart();
$tpwToothNames = [];
foreach ($tpwChart->getAllToothNumbers() as $tpwNum) {
    $tpwToothNames[$tpwNum] = $tpwChart->getToothName($tpwNum);
}

// The catalogue for the add dialog: tooth work, and whole-arch work.
$tpwCatalogue = ['tooth' => [], 'arch' => []];
if ($canWritePlans) {
    $tpwTreatments = new Treatment();
    foreach (['tooth', 'arch'] as $tpwScope) {
        foreach ($tpwTreatments->getByScope($tpwScope) as $t) {
            $tpwCatalogue[$tpwScope][] = [
                'id'    => (int)$t['id'],
                'name'  => (string)$t['treatment_name'],
                'cat'   => (string)($t['category'] ?? 'other'),
                'price' => (float)$t['default_price'],
            ];
        }
    }
}
$tpwJson = JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE;
?>

<?php if ($canWritePlans): ?>
<!-- ═══ پلانی چارەسەری نوێ ═══ -->
<div class="dialog-overlay" id="tpNewPlanDialog">
    <div class="dialog" style="max-width: 500px;">
        <div class="dialog-header">
            <h4 class="dialog-title">پلانی چارەسەری نوێ</h4>
            <button type="button" class="dialog-close" onclick="closeDialog('tpNewPlanDialog')">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <div class="dialog-body">
            <?php if ($hasFullAccess): ?>
            <div class="form-group">
                <label class="form-label required">دکتۆر</label>
                <select id="tpNewDoctor" class="form-control" required>
                    <?php foreach ($doctorOptions as $doctor): ?>
                    <?php if (empty($doctor['is_active'])) continue; ?>
                    <option value="<?= (int)$doctor['id'] ?>" <?= (int)$doctor['id'] === (int)$currentUser['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($doctor['full_name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>
            <div class="form-group">
                <label class="form-label required">جۆری بوار</label>
                <div class="tpw-scope">
                    <label class="tp-radio"><input type="radio" name="tpNewScope" value="tooth" checked onchange="tpScopeChanged()">
                        <i class="fas fa-tooth" style="color: #4caf50;"></i> ددان</label>
                    <label class="tp-radio"><input type="radio" name="tpNewScope" value="arch" onchange="tpScopeChanged()">
                        <i class="fas fa-teeth" style="color: #9c27b0;"></i> ئەرک (جاو)</label>
                </div>
            </div>
            <div class="form-group" id="tpNewArchBox" style="display: none;">
                <label class="form-label required">ئەرک</label>
                <div class="tpw-scope">
                    <label class="tp-radio"><input type="radio" name="tpNewArch" value="upper"> سەرەوە</label>
                    <label class="tp-radio"><input type="radio" name="tpNewArch" value="lower"> خوارەوە</label>
                    <label class="tp-radio"><input type="radio" name="tpNewArch" value="both" checked> هەردوو</label>
                </div>
            </div>
            <div class="form-group">
                <label class="form-label">سکاڵای سەرەکی</label>
                <textarea id="tpNewComplaint" class="form-control" rows="2" placeholder="چی ئازاری هەیە؟"></textarea>
            </div>
            <div class="form-group">
                <label class="form-label">ماوەی پێویست بۆ چارەسەر</label>
                <input type="text" id="tpNewDuration" class="form-control" placeholder="نمونە: ٢ هەفتە / ٣ سەردان">
            </div>
            <div class="form-group">
                <label class="form-label">تێبینی</label>
                <textarea id="tpNewNotes" class="form-control" rows="2"></textarea>
            </div>
            <div class="tp-dialog-msg" id="tpNewMsg"></div>
        </div>
        <div class="dialog-footer">
            <button type="button" class="btn btn-secondary" onclick="closeDialog('tpNewPlanDialog')">هەڵوەشاندنەوە</button>
            <button type="button" class="btn btn-primary" id="tpNewSave" onclick="tpCreatePlan()">
                <i class="fas fa-plus"></i> دروستکردن
            </button>
        </div>
    </div>
</div>

<!-- ═══ زیادکردن / دەستکاریی ئیش ═══ -->
<div class="dialog-overlay" id="tpwItemDialog">
    <div class="dialog tpw-dialog" style="max-width: 560px;">
        <div class="dialog-header">
            <h4 class="dialog-title" id="tpwItemTitle">زیادکردنی ئیش</h4>
            <button type="button" class="dialog-close" onclick="closeDialog('tpwItemDialog')">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <div class="dialog-body">
            <div class="tpw-target" id="tpwTarget"></div>
            <div class="form-group">
                <label class="form-label required" for="tpwName">ئیش</label>
                <input type="text" id="tpwName" class="form-control" autocomplete="off" placeholder="بگەڕێ لە لیستەکە یان ناوی ئیش بنووسە...">
                <div class="tpw-cat" id="tpwCat"></div>
            </div>
            <div class="tpw-fields">
                <div class="form-group">
                    <label class="form-label required" for="tpwPrice">نرخ</label>
                    <input type="number" id="tpwPrice" class="form-control" min="0" step="any" inputmode="decimal">
                </div>
                <div class="form-group" id="tpwQtyBox">
                    <label class="form-label" for="tpwQty">ژمارە</label>
                    <input type="number" id="tpwQty" class="form-control" min="1" step="1" value="1">
                </div>
                <div class="form-group">
                    <label class="form-label" for="tpwDisc">داشکاندن</label>
                    <input type="number" id="tpwDisc" class="form-control" min="0" step="any" value="0" inputmode="decimal">
                </div>
            </div>
            <div class="form-group">
                <label class="form-label" for="tpwNote">تێبینی</label>
                <input type="text" id="tpwNote" class="form-control" autocomplete="off">
            </div>
            <div class="tpw-sum" id="tpwSum"></div>
            <div class="tp-dialog-msg" id="tpwMsg"></div>
        </div>
        <div class="dialog-footer">
            <button type="button" class="btn btn-secondary" onclick="closeDialog('tpwItemDialog')">هەڵوەشاندنەوە</button>
            <button type="button" class="btn btn-primary" id="tpwSave" onclick="tpwSave()">
                <i class="fas fa-check"></i> پاشەکەوت
            </button>
        </div>
    </div>
</div>
<?php endif; ?>

<style>
/* ── پلانی چارەسەر tab ─────────────────────────────────────────────── */
.tpw [hidden], .tpw-dialog [hidden] { display: none !important; }
/* The tab takes the column's width instead of setting it: the chart and the
   table scroll inside themselves, the page beside them never widens. */
.tpw { contain: inline-size; }
.tpw-top { display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap; margin-bottom: 14px; }
.tpw-top-title { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
.tpw-top-title h4 { margin: 0; }
.tpw-est {
    display: inline-flex; align-items: center; gap: 6px; padding: 4px 11px; border-radius: 999px;
    background: #eef6fc; color: var(--win-accent, #0078d4); font-size: 11.5px; font-weight: 600; cursor: help;
}

/* plan switcher */
.tpw-switch { display: flex; gap: 8px; overflow-x: auto; padding: 2px 2px 6px; margin-bottom: 10px; }
.tpw-pill {
    display: inline-flex; align-items: center; gap: 8px; padding: 7px 13px; flex-shrink: 0;
    border: 1px solid #e3e6ef; border-radius: 10px; background: #fff; cursor: pointer;
    font: inherit; font-size: 12.5px; font-weight: 700; color: #334155; transition: border-color .15s, background .15s;
}
.tpw-pill:hover { border-color: #a8cbed; }
.tpw-pill.is-active { border-color: var(--win-accent, #0078d4); background: #eef6fc; color: var(--win-accent-dark, #005a9e); box-shadow: 0 0 0 3px rgba(var(--win-accent-rgb, 0, 120, 212), .12); }
.tpw-pill.is-closed { opacity: .7; }
.tpw-pill-d { font-weight: 500; color: #94a3b8; font-size: 11.5px; }
.tpw-pill-sum { color: #64748b; direction: ltr; }
.tpw-pill-dot { width: 8px; height: 8px; border-radius: 50%; background: #94a3b8; }
.tpw-pill-dot.is-open { background: var(--win-accent, #0078d4); }
.tpw-pill-dot.is-partial { background: #e65100; }
.tpw-pill-dot.is-done { background: #2e7d32; }
.tpw-pill-dot.is-cancelled { background: #c62828; }

/* the plan card */
.tpw-card { border: 1px solid #e6e8f0; border-radius: 14px; background: #fff; overflow: hidden; }
.tpw-head {
    display: flex; justify-content: space-between; align-items: flex-start; gap: 14px; flex-wrap: wrap;
    padding: 16px 18px; background: linear-gradient(135deg, #f7fbfe, #f5f7fb); border-bottom: 1px solid #eceef5;
}
.tpw-id { display: flex; gap: 12px; align-items: flex-start; min-width: 0; }
.tpw-ico {
    width: 46px; height: 46px; border-radius: 12px; flex-shrink: 0; display: flex; align-items: center; justify-content: center;
    background: linear-gradient(135deg, #e7f2fd, #cfe4f8); color: var(--win-accent, #0078d4); font-size: 19px;
}
.tpw-name { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; font-size: 16px; font-weight: 800; color: #183449; }
.tpw-badge { padding: 3px 10px; border-radius: 999px; font-size: 11.5px; font-weight: 700; background: #f1f5f9; color: #475569; }
.tpw-badge.is-open { background: #e7f2fd; color: var(--win-accent-dark, #005a9e); }
.tpw-badge.is-partial { background: #fff3e0; color: #c2410c; }
.tpw-badge.is-done { background: #e8f5e9; color: #2e7d32; }
.tpw-badge.is-cancelled { background: #ffebee; color: #c62828; }
.tpw-chip { padding: 3px 10px; border-radius: 999px; font-size: 11.5px; font-weight: 600; background: #eef2f7; color: #475569; }
.tpw-meta { display: flex; flex-wrap: wrap; gap: 4px 16px; margin-top: 6px; font-size: 12.5px; color: #64748b; }
.tpw-meta i { color: #a0aec0; margin-inline-end: 5px; }
.tpw-actions { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }

.tpw-stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(118px, 1fr)); border-bottom: 1px solid #eceef5; }
.tpw-stat { padding: 12px 14px; display: flex; flex-direction: column; gap: 2px; min-width: 0; }
.tpw-stat + .tpw-stat { border-inline-start: 1px solid #eceef5; }
.tpw-stat-l { font-size: 11.5px; color: #78819a; font-weight: 600; }
.tpw-stat-v { font-size: 18px; font-weight: 800; color: #0f172a; line-height: 1.3; }
.tpw-stat small { font-size: 11px; color: #94a3b8; }
.tpw-stat.is-disc .tpw-stat-v { color: #c2410c; }
.tpw-stat.is-final { background: #f7fbfe; }
.tpw-stat.is-final .tpw-stat-v { color: var(--win-accent-dark, #005a9e); }
.tpw-stat-r { font-size: 13px; font-weight: 700; color: #334155; }
.tpw-stat a { font-size: 11.5px; font-weight: 700; }
.tpw-stat.is-warn .tpw-stat-r { color: #c2410c; }
.tpw-stat.is-ok .tpw-stat-r { color: #047857; }
.tpw-stat.is-info .tpw-stat-r { color: #1d4ed8; }

.tpw-sec { padding: 16px 18px; }
.tpw-sec + .tpw-sec { border-top: 1px solid #eceef5; }
.tpw-sec-head { display: flex; align-items: center; justify-content: space-between; gap: 10px; flex-wrap: wrap; margin-bottom: 12px; }
.tpw-sec-head h5 { margin: 0; display: flex; align-items: center; gap: 8px; font-size: 14px; font-weight: 800; color: #1e293b; }
.tpw-sec-head h5 > i { color: var(--win-accent, #0078d4); }
.tpw-count {
    min-width: 22px; height: 20px; padding: 0 7px; border-radius: 999px; background: #e7f2fd; color: var(--win-accent-dark, #005a9e);
    font-size: 11.5px; display: inline-flex; align-items: center; justify-content: center;
}
.tpw-check { display: inline-flex; align-items: center; gap: 7px; font-size: 12.5px; color: #475569; cursor: pointer; user-select: none; }
.tpw-check input { accent-color: var(--win-accent, #0078d4); width: 15px; height: 15px; }

/* the chart — patient's right on the viewer's left, as on the case chart */
.tpw-chart {
    direction: ltr; padding: 12px 10px; overflow-x: auto; container-type: inline-size;
    background: radial-gradient(ellipse at center, #ffffff 0%, #f8fafd 70%); border: 1px solid #edf0f6; border-radius: 12px;
}
.tpw-jaw { display: flex; flex-direction: column; align-items: center; gap: 2px; min-width: max-content; margin: 0 auto; }
.tpw-row { display: flex; justify-content: center; }
.tpw-jaw.is-upper .tpw-row { align-items: flex-end; }
.tpw-jaw.is-lower .tpw-row { align-items: flex-start; }
.tpw-q { display: flex; gap: 1px; }
.tpw-qdiv { width: 1px; margin: 4px 7px; background: repeating-linear-gradient(#cfd8e3 0 4px, transparent 4px 8px); }
.tpw-mid { display: flex; align-items: center; gap: 10px; margin: 5px auto; max-width: 820px; color: #a0aec0; font-size: 10.5px; font-weight: 700; }
.tpw-mid i { flex: 1; border-top: 1px dashed #d5dce6; }
.tpw-t {
    position: relative; display: flex; flex-direction: column; align-items: center; gap: 3px;
    /* sixteen teeth fill the chart's width: smaller on a narrow screen, never cramped */
    width: clamp(30px, calc((100cqw - 36px) / 16), 54px); padding: 5px 2px; border: 1.5px solid transparent; border-radius: 11px;
    background: transparent; cursor: pointer; font: inherit; color: inherit;
    transition: background .15s, border-color .15s, transform .15s;
}
.tpw-jaw.is-lower .tpw-t { flex-direction: column-reverse; }
.tpw-t:hover { background: #f1f5f9; transform: translateY(-1px); }
.tpw-t:focus-visible { outline: 2px solid var(--win-accent, #0078d4); outline-offset: 1px; }
.tpw-t.is-sel { background: #eaf2ff; border-color: #3b82f6; }
.tpw-tn { font-size: 11.5px; font-weight: 800; color: #a3afbf; line-height: 1; }
.tpw-t.has .tpw-tn { color: var(--win-accent-dark, #005a9e); }
.tpw-t.is-sel .tpw-tn { color: #1d4ed8; }
.tpw-ts { display: block; width: 76%; aspect-ratio: 44 / 60; }
.tpw-ts svg { display: block; width: 100%; height: 100%; }
.tpw-jaw.is-upper .tpw-ts { transform: scaleY(-1); }   /* crowns face the bite */
.tpw-row.is-primary .tpw-t { width: clamp(28px, calc((100cqw - 36px) / 16 * .86), 46px); }
.tpw-row.is-primary .tpw-ts { width: 62%; }
.tpw-row.is-primary .tpw-tn { color: #9cc3ea; }
/* the count sits on the crown side, by the bite, clear of the tooth's number */
.tpw-tc {
    position: absolute; bottom: 1px; right: 1px; min-width: 17px; height: 17px; padding: 0 4px; border-radius: 999px;
    background: var(--win-accent, #0078d4); color: #fff; border: 2px solid #fff; font-size: 9.5px; font-weight: 800;
    display: flex; align-items: center; justify-content: center; box-shadow: 0 2px 5px rgba(0, 90, 158, .3);
}
.tpw-jaw.is-lower .tpw-tc { bottom: auto; top: 1px; }
.tpw-t.is-done .tpw-tc { background: #2e7d32; box-shadow: 0 2px 5px rgba(46, 125, 50, .3); }

/* whole-arch work */
.tpw-lane {
    display: flex; align-items: center; gap: 8px; flex-wrap: wrap; padding: 8px 12px; margin: 0 0 10px;
    border: 1px dashed #c4dcf2; border-radius: 10px; background: #f7fbfe; font-size: 12.5px;
}
.tpw-lane.is-lower { margin: 10px 0 0; }
.tpw-lane.is-click { cursor: pointer; transition: background .15s, border-color .15s; }
.tpw-lane.is-click:hover { background: #eef6fc; border-color: #8fbbe6; }
.tpw-lane-l { font-weight: 800; color: var(--win-accent, #0078d4); }
.tpw-lane-it {
    display: inline-flex; align-items: center; gap: 6px; padding: 3px 10px; border-radius: 999px;
    background: #fff; border: 1px solid #d6e6f5; color: #183449; font-weight: 600;
}
.tpw-lane-it i { width: 8px; height: 8px; border-radius: 50%; }
.tpw-lane-it.is-done { border-color: #c8e6c9; color: #2e7d32; }
.tpw-lane-empty { color: #7a98b5; }
.tpw-lane-add { margin-inline-start: auto; color: #3b8fd9; }

/* selection bar + legend */
.tpw-bar {
    display: flex; align-items: center; justify-content: space-between; gap: 10px; flex-wrap: wrap;
    margin-top: 10px; padding: 9px 12px; border-radius: 10px; background: #f8fafc; border: 1px solid #e9eef4;
    font-size: 12.5px; color: #64748b;
}
.tpw-bar i { margin-inline-end: 4px; }
.tpw-bar.is-picked { background: #eef4ff; border-color: #bfd6fb; color: #1e3a8a; }
.tpw-bar-acts { display: flex; gap: 6px; }
.tpw-pk { display: inline-flex; gap: 4px; flex-wrap: wrap; margin-inline-start: 6px; vertical-align: middle; }
.tpw-legend { display: flex; flex-wrap: wrap; gap: 6px 16px; margin-top: 10px; font-size: 11.5px; color: #64748b; }
.tpw-legend i { display: inline-block; width: 10px; height: 10px; border-radius: 3px; margin-inline-end: 6px; vertical-align: -1px; }

/* the work table */
.tpw-table-wrap { overflow-x: auto; border: 1px solid #edf0f6; border-radius: 12px; }
.tpw-table { width: 100%; border-collapse: collapse; font-size: 13px; }
.tpw-table th {
    background: #f8fafc; color: #64748b; font-size: 11.5px; font-weight: 700; text-align: start;
    padding: 10px 9px; border-bottom: 1px solid #edf0f6; white-space: nowrap;
}
.tpw-table td { padding: 9px 9px; border-bottom: 1px solid #f1f4f8; vertical-align: middle; }
.tpw-table tbody tr:last-child td { border-bottom: 0; }
.tpw-table tbody tr:hover td { background: #fafcfe; }
.tpw-table tr.is-hl td { background: #eef4ff; }
.tpw-table tr.is-off td { opacity: .55; }
.tpw-table tfoot td { background: #f7fbfe; font-weight: 800; color: #183449; border-top: 1px solid #dbe8f5; }
.tpw-table .tpw-n { text-align: left; direction: ltr; white-space: nowrap; font-variant-numeric: tabular-nums; }
.tpw-table th.tpw-n { direction: rtl; }
.tpw-table .is-disc { color: #c2410c; }
.tpw-table .is-final { font-weight: 800; color: var(--win-accent-dark, #005a9e); }
.tpw-tooth-chip {
    display: inline-flex; align-items: baseline; gap: 3px; padding: 2px 8px; border-radius: 7px; direction: ltr;
    background: #f1f5f9; border: 1px solid #e2e8f0; font-weight: 800; color: #334155; white-space: nowrap;
}
.tpw-tooth-chip small { font-size: 9.5px; font-weight: 700; color: #94a3b8; }
.tpw-arch-chip { padding: 2px 9px; border-radius: 7px; background: #f3e5f5; color: #7b1fa2; font-weight: 700; font-size: 11.5px; white-space: nowrap; }
.tpw-muted { color: #94a3b8; font-size: 12px; }
.tpw-iname { display: flex; align-items: center; gap: 8px; min-width: 130px; font-weight: 700; color: #1e293b; }
.tpw-iname small { color: #64748b; font-weight: 600; }
.tpw-cdot { width: 9px; height: 9px; border-radius: 50%; flex-shrink: 0; }
.tpw-inote { display: block; margin-top: 2px; padding-inline-start: 17px; color: #94a3b8; font-size: 11.5px; }
.tpw-st { display: inline-block; padding: 2px 9px; border-radius: 999px; font-size: 11px; font-weight: 700; white-space: nowrap; text-decoration: none; }
.tpw-st.is-planned { background: #e7f2fd; color: var(--win-accent-dark, #005a9e); }
.tpw-st.is-done { background: #e8f5e9; color: #2e7d32; }
.tpw-st.is-cancelled { background: #f1f5f9; color: #64748b; }
.tpw-case { display: block; margin-top: 3px; font-size: 11px; font-weight: 700; color: #2e7d32; direction: ltr; text-align: right; }
.tpw-acts { display: flex; gap: 2px; justify-content: flex-end; }
.tpw-ib {
    width: 27px; height: 27px; border-radius: 7px; border: 0; background: transparent; color: #94a3b8;
    cursor: pointer; display: inline-flex; align-items: center; justify-content: center;
}
.tpw-ib:hover { background: #f1f5f9; color: #334155; }
.tpw-ib.is-del:hover { background: #fee2e2; color: #b91c1c; }
.tpw-none { text-align: center; color: #94a3b8; padding: 26px 12px !important; }

/* no plan yet: the empty chart behind a call to action */
.tpw-empty { position: relative; border: 1px solid #e6e8f0; border-radius: 14px; overflow: hidden; background: #fff; }
.tpw-empty .tpw-chart { border: 0; border-radius: 0; filter: grayscale(1); opacity: .4; pointer-events: none; padding: 26px 10px; }
.tpw-empty-cta {
    position: absolute; inset: 0; display: flex; align-items: center; justify-content: center; padding: 16px;
    background: linear-gradient(180deg, rgba(255, 255, 255, .35), rgba(255, 255, 255, .85));
}
.tpw-empty-box {
    max-width: 400px; padding: 22px 24px; text-align: center; background: #fff;
    border: 1px solid #dfebf6; border-radius: 16px; box-shadow: 0 18px 40px -18px rgba(0, 90, 158, .35);
}
.tpw-empty-ico {
    width: 54px; height: 54px; margin: 0 auto 10px; border-radius: 16px; display: flex; align-items: center; justify-content: center;
    background: linear-gradient(135deg, #e7f2fd, #cfe4f8); color: var(--win-accent, #0078d4); font-size: 22px;
}
.tpw-empty-box b { display: block; font-size: 15px; color: #183449; margin-bottom: 6px; }
.tpw-empty-box p { margin: 0 0 14px; color: #64748b; font-size: 12.5px; line-height: 1.8; }

/* dialogs */
.tpw-scope { display: flex; gap: 8px; }
.tpw-scope .tp-radio { flex: 1; justify-content: center; }
.tpw-target {
    display: flex; align-items: center; gap: 6px; flex-wrap: wrap; margin-bottom: 14px; padding: 9px 12px;
    background: #f8fafc; border: 1px solid #e9eef4; border-radius: 10px;
}
.tpw-target .tp-radio { padding: 5px 11px; }
.tpw-tg-l { font-size: 12px; font-weight: 800; color: #475569; margin-inline-end: 4px; }
.tpw-cat { margin-top: 6px; max-height: 210px; overflow-y: auto; border: 1px solid #e9eef4; border-radius: 10px; padding: 4px; }
.tpw-cat:empty { display: none; }
.tpw-cat-it {
    display: flex; align-items: center; gap: 8px; width: 100%; padding: 7px 10px; border: 0; border-radius: 8px;
    background: transparent; cursor: pointer; font: inherit; font-size: 13px; text-align: start; color: #1e293b;
}
.tpw-cat-it:hover { background: #f2f8fd; }
.tpw-cat-it.is-on { background: #e7f2fd; box-shadow: inset 3px 0 0 var(--win-accent, #0078d4); }
.tpw-cat-it i { width: 9px; height: 9px; border-radius: 50%; flex-shrink: 0; }
.tpw-cat-it span { flex: 1; font-weight: 600; }
.tpw-cat-it small { color: #94a3b8; font-size: 11px; }
.tpw-cat-it b { direction: ltr; color: var(--win-accent-dark, #005a9e); font-size: 12.5px; }
.tpw-cat-none { padding: 10px; color: #64748b; font-size: 12px; }
.tpw-fields { display: grid; grid-template-columns: repeat(auto-fit, minmax(120px, 1fr)); gap: 0 12px; }
.tpw-sum {
    display: flex; align-items: center; justify-content: space-between; padding: 10px 14px; margin-bottom: 6px;
    border-radius: 10px; background: #f7fbfe; border: 1px solid #dfebf6; color: #64748b; font-size: 12.5px;
}
.tpw-sum b { font-size: 17px; color: var(--win-accent-dark, #005a9e); direction: ltr; }

.tp-radio {
    display: inline-flex; align-items: center; gap: 7px; padding: 8px 13px;
    border: 1.5px solid #e0e0ec; border-radius: 8px; cursor: pointer; font-size: 13px; font-weight: 500;
}
.tp-radio:has(input:checked) { border-color: var(--win-accent, #0078d4); background: #eef6fc; color: var(--win-accent-dark, #005a9e); font-weight: 600; }
.tp-radio input { accent-color: var(--win-accent, #0078d4); }
.tp-dialog-msg { font-size: 12.5px; font-weight: 600; min-height: 17px; }
.tp-dialog-msg.is-error { color: #c62828; }
.tp-dialog-msg.is-ok { color: #2e7d32; }

@media (max-width: 640px) {
    .tpw-head, .tpw-sec { padding: 14px 12px; }
    .tpw-stat { padding: 10px 12px; }
    .tpw-stat-v { font-size: 16px; }
}
</style>

<script src="<?= asset_url('assets/js/tooth-chart.js') ?>"></script>
<script>
/* ── پلانی چارەسەر tab: chart + table of the selected plan ──────────── */
(function () {
    'use strict';
    const API = '<?= APP_URL ?>/ajax/treatment_plan.php';
    const PATIENT_ID = <?= (int)$patientId ?>;
    const PLAN_PAGE = '<?= APP_URL ?>/treatment-plans/view.php?id=';
    const PRINT_PAGE = '<?= APP_URL ?>/treatment-plans/print.php?id=';
    const CASE_PAGE = '<?= APP_URL ?>/cases/view.php?id=';
    const CAN_CREATE = <?= $canWritePlans ? 'true' : 'false' ?>;
    const PLANS = <?= json_encode($tpwPlans, $tpwJson) ?>;
    const CATALOGUE = <?= json_encode($tpwCatalogue, $tpwJson) ?>;
    const TOOTH_NAMES = <?= json_encode($tpwToothNames, $tpwJson) ?>;
    const TC = window.ToothChart;

    // Rows of the chart, left to right as drawn (the patient's right first).
    const TEETH = {
        permanent: { upper: [[18, 17, 16, 15, 14, 13, 12, 11], [21, 22, 23, 24, 25, 26, 27, 28]],
                     lower: [[48, 47, 46, 45, 44, 43, 42, 41], [31, 32, 33, 34, 35, 36, 37, 38]] },
        primary:   { upper: [[55, 54, 53, 52, 51], [61, 62, 63, 64, 65]],
                     lower: [[85, 84, 83, 82, 81], [71, 72, 73, 74, 75]] }
    };
    const QUADRANT = { 1: 'UR', 2: 'UL', 3: 'LL', 4: 'LR', 5: 'UR', 6: 'UL', 7: 'LL', 8: 'LR' };
    const PLAN_STATUS = {
        open: ['چاوەڕوانی بڕیاری نەخۆش', 'is-open'], partial: ['بەشێکی گوازراوەتەوە', 'is-partial'],
        transferred: ['تەواو گوازراوەتەوە', 'is-done'], cancelled: ['هەڵوەشێنراوەتەوە', 'is-cancelled']
    };
    const ITEM_STATUS = { planned: ['پلانکراو', 'is-planned'], transferred: ['گوازراوە', 'is-done'], cancelled: ['هەڵوەشاوە', 'is-cancelled'] };
    const ARCH_LABEL = { upper: 'ئەرکی سەرەوە', lower: 'ئەرکی خوارەوە', both: 'هەردوو ئەرک' };
    const CAT_LABEL = {
        examination: 'پشکنین', cleaning: 'پاککردنەوە', filling: 'پڕکردنەوە', extraction: 'ڕاکێشان',
        root_canal: 'چارەسەری دەمار', crown: 'تاج', bridge: 'پرد', implant: 'ئیمپلانت', orthodontic: 'ڕاستکردنەوە',
        whitening: 'سپیکردنەوە', cosmetic: 'جوانکاری', surgery: 'نەشتەرگەری', other: 'تر'
    };
    const ROUTE_CLASS = { undecided: 'is-warn', awaiting_reception: 'is-warn', self: 'is-ok', doctor: 'is-info', chosen: 'is-info' };

    let current = 0;               // id of the plan on screen
    const picked = new Set();      // teeth selected on the chart
    let showPrimary = null;        // milk teeth: null = shown when the plan has work on them
    let dlg = null;                // the open add/edit dialog's state

    const $ = id => document.getElementById(id);
    const nf = n => Number(n || 0).toLocaleString('en-US');
    const esc = s => String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    const toast = (type, text) => { if (typeof showToast === 'function') showToast(type, text); else if (type === 'error') alert(text); };
    const isOpen = p => p.status === 'open' || p.status === 'partial';
    const editable = p => !!p && p.canWrite && isOpen(p);
    const live = p => p.items.filter(it => it.status !== 'cancelled');
    const plan = () => PLANS.find(p => p.id === current) || null;
    const palmer = fdi => TC.isPrimary(fdi) ? 'ABCDE'.charAt(fdi % 10 - 1) : String(fdi % 10);
    const toothChip = fdi => '<span class="tpw-tooth-chip" title="' + esc((TOOTH_NAMES[fdi] || '') + ' · ' + fdi) + '"><small>'
        + QUADRANT[Math.floor(fdi / 10)] + '</small>' + palmer(fdi) + '</span>';

    function normItem(r) {
        return {
            id: Number(r.id), tooth: Number(r.tooth_number) || 0, surface: r.tooth_surface || '',
            arch: (r.arch_type === 'upper' || r.arch_type === 'lower') ? r.arch_type : 'none',
            name: r.treatment_name || '', category: r.category || 'other',
            qty: Number(r.quantity) || 1, unit: Number(r.unit_price) || 0, total: Number(r.total_price) || 0,
            discount: Number(r.discount) || 0, final: Number(r.final_price) || 0,
            status: r.status || 'planned', notes: r.notes || '',
            caseId: Number(r.transferred_case_id) || 0, caseNumber: r.transferred_case_number || ''
        };
    }
    PLANS.forEach(p => { p.items = (p.items || []).map(normItem); });

    function applyResponse(p, res) {
        if (res.plan) {
            p.status = res.plan.status;
            p.arch = res.plan.selected_arch || null;
            p.totals = {
                estimated: Number(res.plan.total_estimated) || 0,
                discount: Number(res.plan.total_discount) || 0,
                final: Number(res.plan.total_final) || 0
            };
        }
        if (res.items) p.items = res.items.map(normItem);
    }

    function post(action, data) {
        const fd = new FormData();
        fd.append('action', action);
        Object.keys(data).forEach(k => { if (data[k] !== undefined && data[k] !== null) fd.append(k, data[k]); });
        return fetch(API, { method: 'POST', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(r => r.json())
            .catch(() => ({ success: false, error: 'هەڵە لە پەیوەندی' }));
    }

    /* ── drawing ───────────────────────────────────────────────────── */
    function render() {
        renderSwitch();
        const host = $('tpwPlan');
        if (!host) return;
        const p = plan();
        host.innerHTML = p
            ? '<div class="tpw-card">' + headHtml(p) + statsHtml(p) + chartHtml(p) + tableHtml(p) + '</div>'
            : emptyHtml();
    }

    function renderSwitch() {
        const box = $('tpwSwitch');
        if (!box) return;
        box.hidden = PLANS.length < 2;
        box.innerHTML = PLANS.length < 2 ? '' : PLANS.map(p => {
            const st = PLAN_STATUS[p.status] || [p.status, ''];
            return '<button type="button" class="tpw-pill' + (p.id === current ? ' is-active' : '') + (isOpen(p) ? '' : ' is-closed')
                + '" onclick="tpwPick(' + p.id + ')" title="' + esc(st[0]) + '">'
                + '<i class="tpw-pill-dot ' + st[1] + '"></i><bdi>' + esc(p.number) + '</bdi>'
                + '<span class="tpw-pill-d">' + esc(p.date) + '</span>'
                + '<span class="tpw-pill-sum">' + nf(p.totals.final) + '</span></button>';
        }).join('');
    }

    function emptyHtml() {
        return '<div class="tpw-empty">'
            + '<div class="tpw-chart">' + jawHtml(null, 'upper', false) + midHtml() + jawHtml(null, 'lower', false) + '</div>'
            + '<div class="tpw-empty-cta"><div class="tpw-empty-box">'
            +   '<span class="tpw-empty-ico"><i class="fas fa-clipboard-list"></i></span>'
            +   '<b>هێشتا پلانی چارەسەر نییە</b>'
            +   '<p>دوای پشکنین پلانێک دروست بکە، پاشان لەسەر چارتەکە ئەو ددانانە دیاری بکە کە ئیشیان پێویستە — نرخەکان لە خشتەکەدا کۆ دەکرێنەوە.</p>'
            +   (CAN_CREATE ? '<button type="button" class="btn btn-primary" onclick="tpNewPlan()"><i class="fas fa-plus-circle"></i> دروستکردنی پلان</button>' : '')
            + '</div></div></div>';
    }

    function headHtml(p) {
        const st = PLAN_STATUS[p.status] || [p.status, ''];
        const pending = live(p).filter(it => it.status === 'planned').length;
        const scope = p.scope === 'arch'
            ? '<i class="fas fa-teeth"></i> ئەرک' + (p.arch ? ' · ' + ARCH_LABEL[p.arch] : '')
            : '<i class="fas fa-tooth"></i> ددان';
        const meta = (p.doctor ? '<span><i class="fas fa-user-md"></i>د. ' + esc(p.doctor) + '</span>' : '')
            + '<span><i class="fas fa-calendar"></i><bdi>' + esc(p.date) + '</bdi></span>'
            + (p.complaint ? '<span><i class="fas fa-comment-medical"></i>' + esc(p.complaint) + '</span>' : '')
            + (p.duration ? '<span><i class="fas fa-hourglass-half"></i>' + esc(p.duration) + '</span>' : '');
        return '<div class="tpw-head">'
            + '<div class="tpw-id"><span class="tpw-ico"><i class="fas fa-clipboard-list"></i></span><div>'
            +   '<div class="tpw-name"><bdi>' + esc(p.number) + '</bdi><span class="tpw-badge ' + st[1] + '">' + st[0] + '</span>'
            +   '<span class="tpw-chip">' + scope + '</span></div>'
            +   '<div class="tpw-meta">' + meta + '</div>'
            + '</div></div>'
            + '<div class="tpw-actions">'
            +   (p.canTransfer && pending && isOpen(p)
                    ? '<a class="btn btn-success btn-sm" href="' + PLAN_PAGE + p.id + '&transfer=1"><i class="fas fa-exchange-alt"></i> گواستنەوە بۆ کەیس (' + pending + ')</a>' : '')
            +   '<a class="btn btn-outline btn-sm" target="_blank" href="' + PRINT_PAGE + p.id + '"><i class="fas fa-print"></i> چاپ</a>'
            +   '<a class="btn btn-outline btn-sm" href="' + PLAN_PAGE + p.id + '" title="کێ چارەسەر دەکات، مەوعید و گواستنەوە"><i class="fas fa-external-link-alt"></i> پەڕەی پلان</a>'
            + '</div></div>';
    }

    function statsHtml(p) {
        const its = live(p);
        const planned = its.filter(it => it.status === 'planned').length;
        const moved = its.length - planned;
        const r = p.route || { state: 'undecided', label: '' };
        const stat = (label, value, sub, cls) => '<div class="tpw-stat ' + (cls || '') + '"><span class="tpw-stat-l">' + label + '</span>'
            + '<span class="tpw-stat-v">' + value + '</span>' + (sub ? '<small>' + sub + '</small>' : '') + '</div>';
        return '<div class="tpw-stats">'
            + stat('ئیشەکان', nf(its.length), its.length ? (moved ? planned + ' پلانکراو · ' + moved + ' گوازراوە' : 'هەمووی پلانکراوە') : '')
            + stat('کۆی خەمڵێنراو', nf(p.totals.estimated), '')
            + stat('داشکاندن', p.totals.discount ? nf(p.totals.discount) : '—', '', 'is-disc')
            + stat('کۆی کۆتایی', nf(p.totals.final), 'خەمڵاندنە — نەچووەتە حسابات', 'is-final')
            + '<div class="tpw-stat ' + (ROUTE_CLASS[r.state] || '') + '"><span class="tpw-stat-l">کێ چارەسەر دەکات؟</span>'
            +   '<span class="tpw-stat-r"><i class="fas fa-route"></i> ' + esc(r.label) + '</span>'
            +   (r.state === 'undecided' && editable(p) ? '<a href="' + PLAN_PAGE + p.id + '">دیاریکردن ←</a>' : '')
            + '</div></div>';
    }

    function chartHtml(p) {
        const prim = showPrimary === null ? live(p).some(it => it.tooth && TC.isPrimary(it.tooth)) : showPrimary;
        return '<section class="tpw-sec">'
            + '<div class="tpw-sec-head"><h5><i class="fas fa-tooth"></i> چارتی ددان</h5>'
            +   '<label class="tpw-check"><input type="checkbox"' + (prim ? ' checked' : '') + ' onchange="tpwPrimary(this.checked)"> ددانی شیری (منداڵ)</label></div>'
            + laneHtml(p, 'upper')
            + '<div class="tpw-chart">' + jawHtml(p, 'upper', prim) + midHtml() + jawHtml(p, 'lower', prim) + '</div>'
            + laneHtml(p, 'lower')
            + barHtml(p)
            + legendHtml(p)
            + '</section>';
    }

    function midHtml() { return '<div class="tpw-mid"><span>ڕاست</span><i></i><span>چەپ</span></div>'; }

    function jawHtml(p, jaw, primary) {
        const sets = jaw === 'upper' ? ['permanent', 'primary'] : ['primary', 'permanent'];
        return '<div class="tpw-jaw is-' + jaw + '">' + sets.filter(s => s === 'permanent' || primary).map(s =>
            '<div class="tpw-row' + (s === 'primary' ? ' is-primary' : '') + '">'
            + '<div class="tpw-q">' + TEETH[s][jaw][0].map(f => toothHtml(p, f)).join('') + '</div>'
            + '<span class="tpw-qdiv"></span>'
            + '<div class="tpw-q">' + TEETH[s][jaw][1].map(f => toothHtml(p, f)).join('') + '</div>'
            + '</div>').join('') + '</div>';
    }

    function toothHtml(p, fdi) {
        const work = p ? live(p).filter(it => it.tooth === fdi) : [];
        const n = work.length;
        const done = n > 0 && work.every(it => it.status === 'transferred');
        const name = TOOTH_NAMES[fdi] || ('ددان ' + fdi);
        const tip = name + ' · ' + fdi + (n ? ' — ' + work.map(it => it.name).join('، ') : '');
        const drawn = work.map(it => ({ category: it.category, treatment_name: it.name, tooth_surface: it.surface }));
        return '<button type="button" class="tpw-t' + (n ? ' has' : '') + (done ? ' is-done' : '') + (picked.has(fdi) ? ' is-sel' : '') + '"'
            + ' title="' + esc(tip) + '"' + (p ? ' onclick="tpwTooth(' + fdi + ')"' : ' tabindex="-1"') + '>'
            + '<span class="tpw-tn">' + palmer(fdi) + '</span>'
            + '<span class="tpw-ts">' + TC.svg(fdi, drawn, { idPrefix: 'tpw', label: name }) + '</span>'
            + (n ? '<span class="tpw-tc">' + (done ? '<i class="fas fa-check"></i>' : n) + '</span>' : '')
            + '</button>';
    }

    function laneHtml(p, arch) {
        const work = live(p).filter(it => it.arch === arch);
        const covered = p.scope === 'arch' && (!p.arch || p.arch === 'both' || p.arch === arch);
        if (!work.length && !covered) return '';
        const ed = editable(p);
        return '<div class="tpw-lane is-' + arch + (ed ? ' is-click' : '') + '"'
            + (ed ? ' onclick="tpwArch(\'' + arch + '\')" title="زیادکردنی ئیش بۆ هەموو ' + ARCH_LABEL[arch] + '"' : '') + '>'
            + '<span class="tpw-lane-l"><i class="fas fa-arrow-' + (arch === 'upper' ? 'up' : 'down') + '"></i> ' + ARCH_LABEL[arch] + '</span>'
            + (work.length
                ? work.map(it => '<span class="tpw-lane-it' + (it.status === 'transferred' ? ' is-done' : '') + '">'
                    + '<i style="background:' + TC.color(it.category, it.name) + '"></i>' + esc(it.name) + ' · <bdi>' + nf(it.final) + '</bdi></span>').join('')
                : '<span class="tpw-lane-empty">' + (ed ? 'کلیک بکە بۆ ئیشێک لەسەر هەموو ئەرکەکە' : 'هیچ ئیشێکی ئەرک نییە') + '</span>')
            + (ed && work.length ? '<span class="tpw-lane-add"><i class="fas fa-plus"></i></span>' : '')
            + '</div>';
    }

    function barHtml(p) {
        const ed = editable(p);
        if (picked.size) {
            const list = Array.from(picked).sort((a, b) => a - b);
            return '<div class="tpw-bar is-picked">'
                + '<div><b>' + list.length + ' ددان</b> هەڵبژێردراوە <span class="tpw-pk">' + list.map(toothChip).join('') + '</span></div>'
                + '<div class="tpw-bar-acts">'
                +   (ed ? '<button type="button" class="btn btn-primary btn-sm" onclick="tpwAdd()"><i class="fas fa-plus"></i> زیادکردنی ئیش</button>' : '')
                +   '<button type="button" class="btn btn-secondary btn-sm" onclick="tpwClear()">لابردن</button>'
                + '</div></div>';
        }
        if (!isOpen(p)) {
            return '<div class="tpw-bar"><span><i class="fas fa-lock"></i> ئەم پلانە «' + (PLAN_STATUS[p.status] || [''])[0] + '» — تەنها بۆ بینینە.</span></div>';
        }
        if (!ed) return '';
        return '<div class="tpw-bar"><span><i class="fas fa-hand-pointer"></i> کلیک لەسەر ددان بکە بۆ هەڵبژاردنی — دەتوانیت چەند ددانێک پێکەوە هەڵبژێریت.</span>'
            + '<button type="button" class="btn btn-outline btn-sm" onclick="tpwAdd()"><i class="fas fa-plus"></i> '
            + (p.scope === 'arch' ? 'ئیشی ئەرک' : 'ئیشی گشتی (بێ ددان)') + '</button></div>';
    }

    function legendHtml(p) {
        const seen = {};
        live(p).forEach(it => {
            const color = TC.color(it.category, it.name);
            let label = CAT_LABEL[it.category] || CAT_LABEL.other;
            if (it.category === 'filling' && color === TC.COLORS.composite) label += ' (کۆمپۆزیت)';
            seen[label] = color;
        });
        const keys = Object.keys(seen);
        return keys.length ? '<div class="tpw-legend">' + keys.map(k => '<span><i style="background:' + seen[k] + '"></i>' + k + '</span>').join('') + '</div>' : '';
    }

    function tableHtml(p) {
        const ed = editable(p);
        let rows = p.items.map(it => {
            const st = ITEM_STATUS[it.status] || [it.status, ''];
            const where = it.tooth ? toothChip(it.tooth)
                : (it.arch !== 'none' ? '<span class="tpw-arch-chip">' + ARCH_LABEL[it.arch] + '</span>' : '<span class="tpw-muted">گشتی</span>');
            const state = '<span class="tpw-st ' + st[1] + '">' + st[0] + '</span>'
                + (it.status === 'transferred' && it.caseId
                    ? '<a class="tpw-case" href="' + CASE_PAGE + it.caseId + '" title="کردنەوەی کەیس">' + esc(it.caseNumber) + '</a>' : '');
            const acts = ed && it.status === 'planned'
                ? '<button type="button" class="tpw-ib" title="دەستکاری" onclick="tpwEdit(' + it.id + ')"><i class="fas fa-pen"></i></button>'
                + '<button type="button" class="tpw-ib is-del" title="سڕینەوە" onclick="tpwRemove(' + it.id + ')"><i class="fas fa-trash-alt"></i></button>'
                : '';
            return '<tr class="' + (it.tooth && picked.has(it.tooth) ? 'is-hl' : '') + (it.status === 'cancelled' ? ' is-off' : '') + '">'
                + '<td>' + where + '</td>'
                + '<td><div class="tpw-iname"><i class="tpw-cdot" style="background:' + TC.color(it.category, it.name) + '"></i>'
                +   esc(it.name) + (it.qty > 1 ? ' <small>× ' + it.qty + '</small>' : '') + '</div>'
                +   (it.notes ? '<small class="tpw-inote">' + esc(it.notes) + '</small>' : '') + '</td>'
                + '<td class="tpw-n">' + nf(it.total) + '</td>'
                + '<td class="tpw-n is-disc">' + (it.discount > 0 ? nf(it.discount) : '—') + '</td>'
                + '<td class="tpw-n is-final">' + nf(it.final) + '</td>'
                + '<td>' + state + '</td>'
                + '<td><div class="tpw-acts">' + acts + '</div></td></tr>';
        }).join('');
        if (!rows) {
            rows = '<tr><td colspan="7" class="tpw-none">هێشتا هیچ ئیشێک نییە'
                + (ed ? ' — ددانێک لەسەر چارتەکە هەڵبژێرە و «زیادکردنی ئیش» دابگرە.' : '.') + '</td></tr>';
        }
        return '<section class="tpw-sec">'
            + '<div class="tpw-sec-head"><h5><i class="fas fa-list-check"></i> ئیشەکان <span class="tpw-count">' + live(p).length + '</span></h5>'
            + (ed ? '<button type="button" class="btn btn-primary btn-sm" onclick="tpwAdd()"><i class="fas fa-plus"></i> زیادکردن</button>' : '') + '</div>'
            + '<div class="tpw-table-wrap"><table class="tpw-table"><thead><tr>'
            + '<th>ددان</th><th>ئیش</th><th class="tpw-n">نرخ</th><th class="tpw-n">داشکاندن</th><th class="tpw-n">کۆتایی</th><th>دۆخ</th><th></th>'
            + '</tr></thead><tbody>' + rows + '</tbody>'
            + (p.items.length ? '<tfoot><tr><td colspan="2">کۆی گشتی</td>'
                + '<td class="tpw-n">' + nf(p.totals.estimated) + '</td>'
                + '<td class="tpw-n is-disc">' + (p.totals.discount ? nf(p.totals.discount) : '—') + '</td>'
                + '<td class="tpw-n is-final">' + nf(p.totals.final) + '</td><td colspan="2"></td></tr></tfoot>' : '')
            + '</table></div></section>';
    }

    /* ── chart actions ─────────────────────────────────────────────── */
    window.tpwPick = function (id) {
        current = id;
        picked.clear();
        showPrimary = null;
        try { const u = new URL(location.href); u.searchParams.set('plan', id); history.replaceState(null, '', u); } catch (e) {}
        render();
    };
    window.tpwTooth = function (fdi) {
        if (picked.has(fdi)) picked.delete(fdi); else picked.add(fdi);
        render();
    };
    window.tpwClear = function () { picked.clear(); render(); };
    window.tpwPrimary = function (on) { showPrimary = !!on; render(); };

    /* ── add / edit / remove work ──────────────────────────────────── */
    const msg = (text, isError) => { const el = $('tpwMsg'); el.textContent = text || ''; el.className = 'tp-dialog-msg' + (isError ? ' is-error' : ''); };
    const catalogueKind = () => (dlg.mode === 'arch' || (dlg.mode === 'edit' && dlg.item.arch !== 'none')) ? 'arch' : 'tooth';

    window.tpwAdd = function () {
        const p = plan();
        if (!editable(p)) return;
        if (picked.size) openItemDialog({ mode: 'teeth', teeth: Array.from(picked).sort((a, b) => a - b) });
        else if (p.scope === 'arch') openItemDialog({ mode: 'arch', arch: p.arch === 'upper' || p.arch === 'lower' ? p.arch : 'both' });
        else openItemDialog({ mode: 'general' });
    };
    window.tpwArch = function (arch) { if (editable(plan())) openItemDialog({ mode: 'arch', arch: arch }); };
    window.tpwEdit = function (id) {
        const p = plan(), it = p && p.items.find(x => x.id === id);
        if (it && editable(p)) openItemDialog({ mode: 'edit', item: it });
    };

    function openItemDialog(state) {
        dlg = Object.assign({ teeth: [], arch: 'both', item: null, treatmentId: 0 }, state);
        const it = dlg.item, edit = dlg.mode === 'edit';
        $('tpwItemTitle').textContent = edit ? 'دەستکاریی ئیش' : 'زیادکردنی ئیش';
        $('tpwTarget').innerHTML = targetHtml();
        $('tpwName').value = edit ? it.name : '';
        // Changing WHAT the work is means removing it and adding the other one:
        // the row's catalogue link (and so its colour on the chart) stays true.
        $('tpwName').readOnly = edit;
        $('tpwPrice').value = edit ? it.unit : '';
        $('tpwQty').value = edit ? it.qty : 1;
        $('tpwDisc').value = edit ? it.discount : 0;
        $('tpwNote').value = edit ? it.notes : '';
        $('tpwQtyBox').hidden = !(dlg.mode === 'general' || (edit && !it.tooth && it.arch === 'none'));
        msg('');
        renderCatalogue();
        updateSum();
        openDialog('tpwItemDialog');
        setTimeout(() => (edit ? $('tpwPrice') : $('tpwName')).focus(), 80);
    }

    function targetHtml() {
        if (dlg.mode === 'teeth') return '<span class="tpw-tg-l">ددان:</span>' + dlg.teeth.map(toothChip).join('');
        if (dlg.mode === 'arch') {
            return '<span class="tpw-tg-l">ئەرک:</span>' + ['upper', 'lower', 'both'].map(a =>
                '<label class="tp-radio"><input type="radio" name="tpwArchPick" value="' + a + '"' + (dlg.arch === a ? ' checked' : '')
                + ' onchange="tpwArchPick(this.value)"> ' + ARCH_LABEL[a] + '</label>').join('');
        }
        if (dlg.mode === 'general') {
            return '<span class="tpw-tg-l">گشتی:</span><span class="tpw-muted">ئیشێک بێ ژمارەی ددان — بۆ ددان، سەرەتا ددانەکان لەسەر چارتەکە هەڵبژێرە.</span>';
        }
        const it = dlg.item;
        return '<span class="tpw-tg-l">' + (it.tooth ? 'ددان:' : (it.arch !== 'none' ? 'ئەرک:' : 'گشتی')) + '</span>'
            + (it.tooth ? toothChip(it.tooth) : (it.arch !== 'none' ? '<span class="tpw-arch-chip">' + ARCH_LABEL[it.arch] + '</span>' : ''));
    }
    window.tpwArchPick = function (a) { dlg.arch = a; updateSum(); };

    function renderCatalogue() {
        const box = $('tpwCat');
        if (dlg.mode === 'edit') { box.innerHTML = ''; return; }
        const all = CATALOGUE[catalogueKind()] || [];
        const q = $('tpwName').value.trim().toLowerCase();
        const sel = all.find(t => t.id === dlg.treatmentId);
        const list = (!q || (sel && sel.name.toLowerCase() === q)) ? all : all.filter(t => t.name.toLowerCase().includes(q));
        box.innerHTML = list.length
            ? list.map(t => '<button type="button" class="tpw-cat-it' + (t.id === dlg.treatmentId ? ' is-on' : '') + '" onclick="tpwPickWork(' + t.id + ')">'
                + '<i style="background:' + TC.color(t.cat, t.name) + '"></i><span>' + esc(t.name) + '</span>'
                + '<small>' + (CAT_LABEL[t.cat] || '') + '</small><b>' + nf(t.price) + '</b></button>').join('')
            : (q ? '<div class="tpw-cat-none">لە لیستەکەدا نییە — بە ناوی «' + esc($('tpwName').value.trim()) + '» زیاد دەکرێت.</div>' : '');
    }
    window.tpwPickWork = function (id) {
        const t = (CATALOGUE[catalogueKind()] || []).find(x => x.id === id);
        if (!t) return;
        dlg.treatmentId = t.id;
        $('tpwName').value = t.name;
        $('tpwPrice').value = t.price;
        renderCatalogue();
        updateSum();
        $('tpwPrice').focus();
        $('tpwPrice').select();
    };

    function updateSum() {
        if (!dlg) return;
        const price = Math.max(0, Number($('tpwPrice').value) || 0);
        const qty = $('tpwQtyBox').hidden ? 1 : Math.max(1, parseInt($('tpwQty').value, 10) || 1);
        const one = Math.max(0, price * qty - Math.max(0, Number($('tpwDisc').value) || 0));
        const n = dlg.mode === 'teeth' ? dlg.teeth.length : (dlg.mode === 'arch' && dlg.arch === 'both' ? 2 : 1);
        $('tpwSum').innerHTML = n > 1
            ? '<span>' + n + ' × ' + nf(one) + '</span><b>' + nf(one * n) + '</b>'
            : '<span>کۆی ئەم ئیشە</span><b>' + nf(one) + '</b>';
    }

    window.tpwSave = async function () {
        const p = plan();
        if (!dlg || !editable(p)) return;
        const name = $('tpwName').value.trim();
        if (!name) { msg('ئیشەکە لە لیستەکە هەڵبژێرە یان ناوەکەی بنووسە', true); $('tpwName').focus(); return; }
        const price = $('tpwPrice').value.trim();
        if (price === '' || !(Number(price) >= 0)) { msg('نرخ بنووسە', true); $('tpwPrice').focus(); return; }
        const fields = {
            treatment_name: name,
            unit_price: Number(price),
            discount: Math.max(0, Number($('tpwDisc').value) || 0),
            quantity: $('tpwQtyBox').hidden ? 1 : Math.max(1, parseInt($('tpwQty').value, 10) || 1),
            notes: $('tpwNote').value.trim()
        };
        const calls = [];
        if (dlg.mode === 'edit') {
            calls.push(['update_item', Object.assign({ item_id: dlg.item.id }, fields)]);
        } else {
            const base = Object.assign({ plan_id: p.id, treatment_id: dlg.treatmentId || '' }, fields);
            if (dlg.mode === 'teeth') dlg.teeth.forEach(f => calls.push(['add_item', Object.assign({}, base, { tooth_number: f, arch_type: 'none' })]));
            else if (dlg.mode === 'arch') calls.push(['add_item', Object.assign({}, base, { arch_type: dlg.arch })]);
            else calls.push(['add_item', Object.assign({}, base, { arch_type: 'none' })]);
        }
        const btn = $('tpwSave');
        btn.disabled = true;
        msg('پاشەکەوت دەکرێت...');
        let last = null, failed = null;
        for (const [action, data] of calls) {
            const res = await post(action, data);
            if (res && res.success) last = res; else { failed = res || {}; break; }
        }
        btn.disabled = false;
        if (last) applyResponse(p, last);
        if (failed) { render(); msg(failed.error || failed.message || 'هەڵە', true); return; }
        closeDialog('tpwItemDialog');
        const edited = dlg.mode === 'edit';
        if (!edited) picked.clear();
        dlg = null;
        render();
        toast('success', edited ? 'گۆڕانکارییەکە پاشەکەوت کرا' : (calls.length > 1 ? calls.length + ' ئیش زیادکرا' : 'ئیشەکە زیادکرا'));
    };

    window.tpwRemove = async function (id) {
        const p = plan(), it = p && p.items.find(x => x.id === id);
        if (!it || !editable(p)) return;
        if (!confirm('«' + it.name + '» لە پلانەکە بسڕدرێتەوە؟')) return;
        const res = await post('remove_item', { item_id: id });
        if (!res || !res.success) { toast('error', (res && res.error) || 'هەڵە'); return; }
        applyResponse(p, res);
        render();
        toast('success', 'ئیشەکە سڕایەوە');
    };

    if ($('tpwName')) {
        $('tpwName').addEventListener('input', () => {
            const sel = (CATALOGUE[catalogueKind()] || []).find(t => t.id === dlg.treatmentId);
            if (sel && sel.name !== $('tpwName').value.trim()) dlg.treatmentId = 0;
            renderCatalogue();
        });
        $('tpwName').addEventListener('keydown', e => {
            if (e.key !== 'Enter') return;
            e.preventDefault();
            const first = $('tpwCat').querySelector('.tpw-cat-it');
            if (!dlg.treatmentId && first && $('tpwName').value.trim()) first.click();
            else $('tpwPrice').focus();
        });
        ['tpwPrice', 'tpwQty', 'tpwDisc'].forEach(id => $(id).addEventListener('input', updateSum));
        ['tpwPrice', 'tpwQty', 'tpwDisc', 'tpwNote'].forEach(id => $(id).addEventListener('keydown', e => {
            if (e.key === 'Enter') { e.preventDefault(); tpwSave(); }
        }));
    }

    /* ── new plan ──────────────────────────────────────────────────── */
    window.tpNewPlan = function () {
        const m = $('tpNewMsg');
        if (m) { m.textContent = ''; m.className = 'tp-dialog-msg'; }
        openDialog('tpNewPlanDialog');
    };
    window.tpScopeChanged = function () {
        const scope = (document.querySelector('input[name="tpNewScope"]:checked') || {}).value || 'tooth';
        const box = $('tpNewArchBox');
        if (box) box.style.display = scope === 'arch' ? '' : 'none';
    };
    window.tpCreatePlan = function () {
        const btn = $('tpNewSave'), m = $('tpNewMsg');
        m.className = 'tp-dialog-msg';
        const scope = (document.querySelector('input[name="tpNewScope"]:checked') || {}).value || 'tooth';
        const data = {
            patient_id: PATIENT_ID,
            scope_type: scope,
            chief_complaint: $('tpNewComplaint').value.trim(),
            duration_text: $('tpNewDuration').value.trim(),
            notes: $('tpNewNotes').value.trim()
        };
        if (scope === 'arch') data.selected_arch = (document.querySelector('input[name="tpNewArch"]:checked') || {}).value || 'both';
        const doc = $('tpNewDoctor');
        if (doc && doc.value) data.doctor_id = doc.value;
        btn.disabled = true;
        post('create_plan', data).then(res => {
            btn.disabled = false;
            if (!res || !res.success) {
                m.textContent = (res && (res.error || res.message)) || 'هەڵە';
                m.classList.add('is-error');
                return;
            }
            // Straight back to this tab, on the new plan, ready for its chart.
            location.href = '<?= APP_URL ?>/patients/view.php?id=' + PATIENT_ID + '&tab=plans&plan=' + res.plan_id;
        });
    };

    /* ── start ─────────────────────────────────────────────────────── */
    const qs = new URLSearchParams(location.search);
    const wanted = Number(qs.get('plan')) || 0;
    const first = PLANS.find(p => p.id === wanted) || PLANS.find(isOpen) || PLANS[0];
    current = first ? first.id : 0;
    render();
    document.addEventListener('DOMContentLoaded', function () {
        if (qs.get('tab') === 'plans' && typeof showTab === 'function') showTab('plans');
    });
})();
</script>
