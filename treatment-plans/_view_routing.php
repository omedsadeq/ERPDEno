<?php
/**
 * کێ چارەسەر دەکات؟ + مەوعیدی چارەسەر — dialogs, styles and script for the
 * plan page's routing panels (treatment-plans/view.php).
 *
 * The examiner (دکتۆری دەستنیشانکەر) decides who treats: himself, one named
 * colleague, or «چەند دکتۆرێک» — then reception picks the doctor. The doctor
 * who ends up treating is the only one who books the visit; nobody books on
 * another doctor's behalf. The server enforces all of it
 * (ajax/treatment_plan.php → TreatmentPlan::designate / chooseDoctor /
 * bookAppointment); this file only asks.
 */
if (!isset($plan)) { exit; }

$routeDurationOptions = [15, 20, 30, 45, 60, 90, 120, 180];
$routeDefaultDuration = 30;
$routeWanted = $liveAppointment ? (int)$liveAppointment['duration'] : (int)$plan['total_minutes'];
if ($routeWanted > 0) {
    // Nearest listed length, so a 50-minute plan offers 45, not 30.
    usort($routeDurationOptions, function ($a, $b) use ($routeWanted) {
        return abs($a - $routeWanted) <=> abs($b - $routeWanted) ?: $a <=> $b;
    });
    $routeDefaultDuration = $routeDurationOptions[0];
    sort($routeDurationOptions);
}
?>

<?php if ($canDesignate): ?>
<div class="dialog-overlay" id="tpRouteDialog">
    <div class="dialog" style="max-width: 540px;">
        <div class="dialog-header">
            <h4 class="dialog-title"><i class="fas fa-route"></i> کێ چارەسەری ئەم نەخۆشە دەکات؟</h4>
            <button type="button" class="dialog-close" onclick="closeDialog('tpRouteDialog')"><i class="fas fa-times"></i></button>
        </div>
        <div class="dialog-body">
            <label class="tp-radio tp-radio-block">
                <input type="radio" name="tpRouteMode" value="self" onchange="pvRouteModeChanged()"> خۆم چارەسەری دەکەم
            </label>
            <label class="tp-radio tp-radio-block">
                <input type="radio" name="tpRouteMode" value="doctor" onchange="pvRouteModeChanged()"> دکتۆرێکی دیاریکراو (دکتۆری دەستنیشانکراو)
            </label>
            <label class="tp-radio tp-radio-block">
                <input type="radio" name="tpRouteMode" value="doctors" onchange="pvRouteModeChanged()"> چەند دکتۆرێک — پێشوازی بڕیار دەدات بۆ کام دکتۆر
            </label>

            <div class="form-group" id="tpRouteDoctorBox" hidden style="margin-top: 10px;">
                <label class="form-label required" for="tpRouteDoctor">دکتۆری دەستنیشانکراو</label>
                <select id="tpRouteDoctor" class="form-control">
                    <option value="">— دکتۆرێک هەڵبژێرە —</option>
                    <?php foreach ($doctorChoices as $did => $dname): ?>
                    <?php if ((int)$did === (int)$plan['doctor_id']) continue; ?>
                    <option value="<?= (int)$did ?>" <?= $designState === 'doctor' && (int)$did === (int)$treatingId ? 'selected' : '' ?>><?= htmlspecialchars($dname) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group" id="tpRouteDoctorsBox" hidden style="margin-top: 10px;">
                <label class="form-label">دکتۆرەکان <small style="font-weight:500; color:#8a8aa0;">(ئارەزوومەندانە)</small></label>
                <small class="tp-route-help">ئەگەر هیچ دکتۆرێک دیاری نەکەیت، پێشوازی دەتوانێت هەر دکتۆرێک هەڵبژێرێت. ئەگەر دیاری بکەیت، لانیکەم دوو دکتۆر.</small>
                <div class="tp-route-cands">
                    <?php foreach ($doctorChoices as $did => $dname): ?>
                    <label class="tp-route-cand">
                        <input type="checkbox" class="tp-route-cand-cb" value="<?= (int)$did ?>" <?= in_array((int)$did, $candidateIds, true) ? 'checked' : '' ?>>
                        <span><?= htmlspecialchars($dname) ?><?= (int)$did === (int)$plan['doctor_id'] ? ' (خۆت)' : '' ?></span>
                    </label>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="tp-route-help" style="margin-top: 8px;">
                <i class="fas fa-info-circle"></i> تەنها دکتۆری چارەسەرکەر خۆی مەوعید دادەنێت و کەیسەکە لەسەر ناوی ئەو دەکرێتەوە.
            </div>
            <div class="tp-dialog-msg" id="tpRouteMsg"></div>
        </div>
        <div class="dialog-footer">
            <button type="button" class="btn btn-secondary" onclick="closeDialog('tpRouteDialog')">هەڵوەشاندنەوە</button>
            <button type="button" class="btn btn-primary" id="tpRouteSave" onclick="pvRouteSave()">
                <i class="fas fa-check"></i> پاشەکەوتکردن
            </button>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if ($canBook): ?>
<div class="dialog-overlay" id="tpBookDialog">
    <div class="dialog" style="max-width: 520px;">
        <div class="dialog-header">
            <h4 class="dialog-title"><i class="fas fa-calendar-plus"></i> مەوعیدی چارەسەر — <?= htmlspecialchars($plan['patient_name']) ?></h4>
            <button type="button" class="dialog-close" onclick="closeDialog('tpBookDialog')"><i class="fas fa-times"></i></button>
        </div>
        <div class="dialog-body">
            <div class="tp-book-grid">
                <div class="form-group">
                    <label class="form-label required" for="tpBookDate">بەروار</label>
                    <input type="date" id="tpBookDate" class="form-control" min="<?= date('Y-m-d') ?>"
                           value="<?= htmlspecialchars($liveAppointment['appointment_date'] ?? date('Y-m-d', strtotime('+1 day'))) ?>"
                           onchange="pvBookLoadDay()">
                </div>
                <div class="form-group">
                    <label class="form-label required" for="tpBookTime">کات</label>
                    <input type="time" id="tpBookTime" class="form-control"
                           value="<?= htmlspecialchars($liveAppointment ? substr((string)$liveAppointment['appointment_time'], 0, 5) : '') ?>">
                </div>
                <div class="form-group">
                    <label class="form-label" for="tpBookDuration">ماوە</label>
                    <select id="tpBookDuration" class="form-control">
                        <?php foreach ($routeDurationOptions as $m): ?>
                        <option value="<?= $m ?>" <?= $m === $routeDefaultDuration ? 'selected' : '' ?>><?= $m ?> خولەک</option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="form-group">
                <label class="form-label" for="tpBookNotes">تێبینی</label>
                <input type="text" id="tpBookNotes" class="form-control" placeholder="ئارەزوومەندانە" autocomplete="off">
            </div>
            <div class="tp-book-day">
                <div class="tp-book-day-title"><i class="fas fa-list"></i> مەوعیدەکانی خۆت لەو ڕۆژەدا</div>
                <div id="tpBookDayList" class="tp-book-day-list">—</div>
            </div>
            <div class="tp-dialog-msg" id="tpBookMsg"></div>
        </div>
        <div class="dialog-footer">
            <button type="button" class="btn btn-secondary" onclick="closeDialog('tpBookDialog')">داخستن</button>
            <button type="button" class="btn btn-primary" id="tpBookSave" onclick="pvBookSave()">
                <i class="fas fa-check"></i> <?= $liveAppointment ? 'نوێکردنەوەی مەوعید' : 'دانانی مەوعید' ?>
            </button>
        </div>
    </div>
</div>
<?php endif; ?>

<style>
/* ── کێ چارەسەر دەکات؟ ─────────────────────────────────────────────── */
.tp-route-badge { background: #eef2ff; color: #3730a3; }
.tp-route-badge.is-undecided { background: #fff7ed; color: #9a3412; }
.tp-route-badge.is-self { background: #ecfdf5; color: #047857; }
.tp-route-badge.is-doctor { background: #eff6ff; color: #1d4ed8; }
.tp-route-badge.is-waiting { background: #fef3c7; color: #92400e; }
.tp-route-state { display: flex; gap: 10px; align-items: flex-start; margin-top: 10px; padding: 10px 12px; border-radius: 9px; border: 1px solid #e5e7eb; background: #f9fafb; font-size: 12.5px; line-height: 1.6; }
.tp-route-state > i { font-size: 16px; margin-top: 2px; }
.tp-route-state b { display: block; color: #1f2937; }
.tp-route-state small { display: block; color: #6b7280; }
.tp-route-state.is-undecided { background: #fff7ed; border-color: #fed7aa; }
.tp-route-state.is-undecided > i { color: #ea580c; }
.tp-route-state.is-self { background: #ecfdf5; border-color: #a7f3d0; }
.tp-route-state.is-self > i { color: #059669; }
.tp-route-state.is-doctor { background: #eff6ff; border-color: #bfdbfe; }
.tp-route-state.is-doctor > i { color: #2563eb; }
.tp-route-state.is-waiting { background: #fffbeb; border-color: #fde68a; }
.tp-route-state.is-waiting > i { color: #d97706; }
.tp-route-quick { display: flex; gap: 6px; flex-wrap: wrap; margin-top: 10px; }
.tp-route-quick .btn { flex: 1 1 auto; }
.tp-route-note { margin-top: 10px; padding: 8px 11px; border-radius: 8px; background: #f3f4f6; color: #4b5563; font-size: 12px; line-height: 1.6; }
.tp-route-note.is-soft { background: transparent; padding: 4px 0 0; color: #6b7280; }
.tp-route-choose { margin-top: 12px; padding-top: 12px; border-top: 1px dashed #e5e7eb; }
.tp-route-help { display: block; color: #6b7280; font-size: 12px; line-height: 1.6; }
.tp-route-cands { display: grid; grid-template-columns: repeat(auto-fill, minmax(190px, 1fr)); gap: 6px; margin-top: 8px; }
.tp-route-cand { display: flex; align-items: center; gap: 8px; padding: 7px 10px; border: 1.5px solid #e0e0ec; border-radius: 8px; font-size: 12.5px; cursor: pointer; }
.tp-route-cand:has(input:checked) { border-color: #5e35b1; background: #f3effb; color: #4527a0; font-weight: 600; }
.tp-route-cand input { accent-color: #5e35b1; }
/* ── مەوعیدی چارەسەر ───────────────────────────────────────────────── */
.tp-appt-card { padding: 11px 13px; border-radius: 10px; background: linear-gradient(135deg, #ecfeff, #eff6ff); border: 1px solid #bae6fd; }
.tp-appt-day { font-size: 12px; color: #0369a1; font-weight: 600; }
.tp-appt-date { font-size: 17px; font-weight: 800; color: #0c4a6e; margin: 2px 0; }
.tp-appt-doc { font-size: 12px; color: #475569; }
.tp-book-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(130px, 1fr)); gap: 10px; }
.tp-book-day { margin-top: 4px; padding: 10px 12px; border: 1px solid #e5e7eb; border-radius: 9px; background: #fafafa; }
.tp-book-day-title { font-size: 12px; font-weight: 700; color: #374151; margin-bottom: 6px; }
.tp-book-day-list { font-size: 12.5px; color: #4b5563; max-height: 150px; overflow-y: auto; }
.tp-book-day-row { display: flex; gap: 10px; padding: 4px 0; border-bottom: 1px solid #f0f0f0; }
.tp-book-day-row:last-child { border-bottom: none; }
.tp-book-day-row bdi { font-weight: 700; color: #111827; min-width: 44px; }
</style>

<script>
// Routing + booking. Reuses post()/setMsg()/toast() from _view_script.php.
(function () {
    function fail(res, fallback) { return (res && (res.error || res.message)) || fallback || 'هەڵە'; }

<?php if ($canDesignate): ?>
    window.pvRouteSelf = function () {
        post('designate', { plan_id: PLAN_ID, mode: 'self' }).then(function (res) {
            if (!res || !res.success) { toast('error', fail(res)); return; }
            location.reload();
        }).catch(function () { toast('error', 'هەڵە لە پەیوەندی'); });
    };
    window.pvRouteOpen = function (mode) {
        const radio = document.querySelector('input[name="tpRouteMode"][value="' + (mode || 'self') + '"]');
        if (radio) radio.checked = true;
        pvRouteModeChanged();
        setMsg('tpRouteMsg', '');
        openDialog('tpRouteDialog');
    };
    window.pvRouteModeChanged = function () {
        const mode = (document.querySelector('input[name="tpRouteMode"]:checked') || {}).value;
        document.getElementById('tpRouteDoctorBox').hidden = mode !== 'doctor';
        document.getElementById('tpRouteDoctorsBox').hidden = mode !== 'doctors';
    };
    window.pvRouteSave = function () {
        const mode = (document.querySelector('input[name="tpRouteMode"]:checked') || {}).value;
        if (!mode) { setMsg('tpRouteMsg', 'تکایە هەڵبژاردنێک بکە', 'is-error'); return; }
        const payload = { plan_id: PLAN_ID, mode: mode };
        if (mode === 'doctor') {
            payload.doctor_id = document.getElementById('tpRouteDoctor').value;
            if (!payload.doctor_id) { setMsg('tpRouteMsg', 'تکایە دکتۆرێک هەڵبژێرە', 'is-error'); return; }
        }
        if (mode === 'doctors') {
            payload.candidate_ids = Array.from(document.querySelectorAll('.tp-route-cand-cb:checked')).map(function (b) { return b.value; });
            if (payload.candidate_ids.length === 1) {
                setMsg('tpRouteMsg', 'تەنها یەک دکتۆرت هەڵبژاردووە — «دکتۆرێکی دیاریکراو» هەڵبژێرە', 'is-error');
                return;
            }
        }
        const btn = document.getElementById('tpRouteSave');
        btn.disabled = true;
        post('designate', payload).then(function (res) {
            btn.disabled = false;
            if (!res || !res.success) { setMsg('tpRouteMsg', fail(res), 'is-error'); return; }
            location.reload();
        }).catch(function () { btn.disabled = false; setMsg('tpRouteMsg', 'هەڵە لە پەیوەندی', 'is-error'); });
    };
<?php endif; ?>

<?php if ($canChoose): ?>
    window.pvChooseDoctor = function () {
        const sel = document.getElementById('pvChooseDoctor');
        if (!sel || !sel.value) { toast('error', 'تکایە دکتۆرێک هەڵبژێرە'); return; }
        const btn = document.getElementById('pvChooseBtn');
        btn.disabled = true;
        post('choose_doctor', { plan_id: PLAN_ID, doctor_id: sel.value }).then(function (res) {
            btn.disabled = false;
            if (!res || !res.success) { toast('error', fail(res)); return; }
            location.reload();
        }).catch(function () { btn.disabled = false; toast('error', 'هەڵە لە پەیوەندی'); });
    };
<?php endif; ?>

<?php if ($canBook): ?>
    window.pvBookLoadDay = function () {
        const list = document.getElementById('tpBookDayList');
        const date = document.getElementById('tpBookDate').value;
        if (!date) { list.textContent = '—'; return; }
        list.textContent = '...';
        fetch(API + '?action=doctor_day&date=' + encodeURIComponent(date), { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                if (!res || !res.success) { list.textContent = fail(res); return; }
                if (!res.appointments.length) { list.textContent = 'هیچ مەوعیدێکت نییە لەو ڕۆژەدا'; return; }
                list.innerHTML = res.appointments.map(function (a) {
                    return '<div class="tp-book-day-row"><bdi>' + escapeHtml(a.appointment_time) + '</bdi>'
                        + '<span>' + escapeHtml(a.patient_name) + (a.duration ? ' · ' + Number(a.duration) + ' خولەک' : '') + '</span></div>';
                }).join('');
            })
            .catch(function () { list.textContent = 'هەڵە لە پەیوەندی'; });
    };
    window.pvBookOpen = function () {
        setMsg('tpBookMsg', '');
        pvBookLoadDay();
        openDialog('tpBookDialog');
    };
    window.pvBookSave = function () {
        const date = document.getElementById('tpBookDate').value;
        const time = document.getElementById('tpBookTime').value;
        if (!date || !time) { setMsg('tpBookMsg', 'بەروار و کات پێویستن', 'is-error'); return; }
        const btn = document.getElementById('tpBookSave');
        btn.disabled = true;
        post('book_appointment', {
            plan_id: PLAN_ID,
            appointment_date: date,
            appointment_time: time,
            duration: document.getElementById('tpBookDuration').value,
            notes: document.getElementById('tpBookNotes').value.trim()
        }).then(function (res) {
            btn.disabled = false;
            if (!res || !res.success) { setMsg('tpBookMsg', fail(res), 'is-error'); return; }
            location.reload();
        }).catch(function () { btn.disabled = false; setMsg('tpBookMsg', 'هەڵە لە پەیوەندی', 'is-error'); });
    };
    window.pvBookCancel = function () {
        const reason = prompt('هۆکاری هەڵوەشاندنەوەی مەوعید (ئارەزوومەندانە):');
        if (reason === null) return;
        post('cancel_appointment', { plan_id: PLAN_ID, reason: reason }).then(function (res) {
            if (!res || !res.success) { toast('error', fail(res)); return; }
            location.reload();
        }).catch(function () { toast('error', 'هەڵە لە پەیوەندی'); });
    };
<?php endif; ?>
})();
</script>
