<?php
/**
 * پلانی چارەسەر tab — data, the new-plan dialog, look and behaviour. The
 * markup it fills is patients/_plans_tab.php.
 *
 * The tab lists the patient's plans as cards; each plan lives on its own page,
 * treatment-plans/view.php (chart, the work table, routing, the appointment
 * and the transfer to a case). A new plan opens straight on that page.
 * Nothing here is money — see classes/TreatmentPlan.php.
 */
if (empty($canViewPlans)) {
    return;
}

$tpwViewerId   = (int)($currentUser['id'] ?? 0);
$tpwRestricted = AccessGuard::isRestrictedDoctor();
$tpwPlans = [];
foreach ($treatmentPlans as $tp) {
    $tpwState      = TreatmentPlan::designationState($tp);
    $tpwTreatingId = TreatmentPlan::treatingDoctorId($tp);
    $tpwTreating   = (string)($tp['treating_doctor_name'] ?? '');

    // A short summary of the work: one line per treatment, with its count.
    $tpwWork = [];
    $tpwCounts = ['live' => 0, 'planned' => 0, 'transferred' => 0];
    foreach ($treatmentPlanManager->getItems((int)$tp['id']) as $it) {
        if (($it['status'] ?? '') === 'cancelled') {
            continue;
        }
        $tpwCounts['live']++;
        if (($it['status'] ?? '') === 'planned') {
            $tpwCounts['planned']++;
        } elseif (($it['status'] ?? '') === 'transferred') {
            $tpwCounts['transferred']++;
        }
        $name = (string)$it['treatment_name'];
        $tpwWork[$name] = ($tpwWork[$name] ?? 0) + max(1, (int)$it['quantity']);
    }
    arsort($tpwWork);

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
        // Work moves only into the treating doctor's case (same rule as the plan page).
        'canTransfer' => $canTransferPlans && $tpwTreatingId !== null
            && (!$tpwRestricted || $tpwTreatingId === $tpwViewerId),
        'counts' => $tpwCounts,
        'work'   => array_map(fn($n, $q) => ['name' => $n, 'qty' => $q], array_keys($tpwWork), array_values($tpwWork)),
        'totals' => [
            'estimated' => (float)($tp['total_estimated'] ?? 0),
            'discount'  => (float)($tp['total_discount'] ?? 0),
            'final'     => (float)($tp['total_final'] ?? 0),
        ],
    ];
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
                <i class="fas fa-plus"></i> دروستکردن و کردنەوە
            </button>
        </div>
    </div>
</div>
<?php endif; ?>

<style>
/* ── پلانی چارەسەر tab: the list of plans ─────────────────────────── */
.tpw [hidden] { display: none !important; }
.tpw { contain: inline-size; }
.tpw-top { display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap; margin-bottom: 14px; }
.tpw-top-title { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
.tpw-top-title h4 { margin: 0; }
.tpw-est {
    display: inline-flex; align-items: center; gap: 6px; padding: 4px 11px; border-radius: 999px;
    background: #eef6fc; color: var(--win-accent, #0078d4); font-size: 11.5px; font-weight: 600; cursor: help;
}
.tpw-list { display: flex; flex-direction: column; gap: 12px; }
.tpw-card {
    border: 1px solid #e6e8f0; border-radius: 14px; background: #fff; overflow: hidden; cursor: pointer;
    transition: border-color .15s, box-shadow .15s;
}
.tpw-card:hover { border-color: #a8cbed; box-shadow: 0 4px 14px rgba(15, 23, 42, .07); }
.tpw-card.is-wanted { border-color: var(--win-accent, #0078d4); box-shadow: 0 0 0 3px rgba(var(--win-accent-rgb, 0, 120, 212), .14); }
.tpw-card.is-closed { opacity: .82; }
.tpw-head {
    display: flex; justify-content: space-between; align-items: flex-start; gap: 14px; flex-wrap: wrap;
    padding: 14px 16px; background: linear-gradient(135deg, #f7fbfe, #f5f7fb); border-bottom: 1px solid #eceef5;
}
.tpw-id { display: flex; gap: 12px; align-items: flex-start; min-width: 0; }
.tpw-ico {
    width: 44px; height: 44px; border-radius: 12px; flex-shrink: 0; display: flex; align-items: center; justify-content: center;
    background: linear-gradient(135deg, #e7f2fd, #cfe4f8); color: var(--win-accent, #0078d4); font-size: 18px;
}
.tpw-name { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; font-size: 16px; font-weight: 800; color: #183449; }
.tpw-badge { padding: 3px 10px; border-radius: 999px; font-size: 11.5px; font-weight: 700; background: #f1f5f9; color: #475569; }
.tpw-badge.is-open { background: #e7f2fd; color: var(--win-accent-dark, #005a9e); }
.tpw-badge.is-partial { background: #fff3e0; color: #c2410c; }
.tpw-badge.is-done { background: #e8f5e9; color: #2e7d32; }
.tpw-badge.is-cancelled { background: #ffebee; color: #c62828; }
.tpw-chip { padding: 3px 10px; border-radius: 999px; font-size: 11.5px; font-weight: 600; background: #eef2f7; color: #475569; }
.tpw-meta { display: flex; flex-wrap: wrap; gap: 4px 14px; margin-top: 5px; font-size: 12.5px; color: #64748b; }
.tpw-meta i { color: #94a3b8; margin-inline-end: 4px; }
.tpw-actions { display: flex; gap: 6px; flex-wrap: wrap; }
.tpw-body { display: grid; grid-template-columns: minmax(0, 1fr) auto; gap: 14px; padding: 12px 16px 14px; align-items: start; }
.tpw-work { display: flex; flex-wrap: wrap; gap: 6px; }
.tpw-work span { background: #f8fafc; border: 1px solid #e9eef4; border-radius: 8px; padding: 3px 9px; font-size: 12.5px; color: #334155; }
.tpw-work b { color: var(--win-accent-dark, #005a9e); margin-inline-start: 4px; }
.tpw-work .is-none { color: #94a3b8; background: none; border-style: dashed; }
.tpw-route { margin-top: 8px; font-size: 12px; color: #64748b; }
.tpw-route b { font-weight: 700; }
.tpw-route .is-warn { color: #c2410c; }
.tpw-route .is-ok { color: #2e7d32; }
.tpw-route .is-info { color: var(--win-accent-dark, #005a9e); }
.tpw-sum { display: flex; gap: 14px; text-align: center; }
.tpw-sum div { min-width: 82px; }
.tpw-sum small { display: block; font-size: 11px; color: #94a3b8; }
.tpw-sum b { font-size: 15px; color: #183449; direction: ltr; display: block; font-variant-numeric: tabular-nums; }
.tpw-sum .is-final b { color: var(--win-accent-dark, #005a9e); }
.tpw-sum .is-cut b { color: #c2410c; }
.tpw-empty { text-align: center; padding: 34px 16px; border: 1px dashed #d8e2ec; border-radius: 14px; background: #fbfdff; }
.tpw-empty-ico {
    width: 54px; height: 54px; border-radius: 16px; display: inline-flex; align-items: center; justify-content: center; margin-bottom: 10px;
    background: linear-gradient(135deg, #e7f2fd, #cfe4f8); color: var(--win-accent, #0078d4); font-size: 22px;
}
.tpw-empty b { display: block; font-size: 15px; color: #183449; margin-bottom: 6px; }
.tpw-empty p { margin: 0 0 14px; color: #64748b; font-size: 12.5px; line-height: 1.8; }

/* dialog */
.tpw-scope { display: flex; gap: 8px; }
.tpw-scope .tp-radio { flex: 1; justify-content: center; }
.tp-radio {
    display: inline-flex; align-items: center; gap: 7px; padding: 8px 13px;
    border: 1.5px solid #e0e0ec; border-radius: 8px; cursor: pointer; font-size: 13px; font-weight: 500;
}
.tp-radio:has(input:checked) { border-color: var(--win-accent, #0078d4); background: #eef6fc; color: var(--win-accent-dark, #005a9e); font-weight: 600; }
.tp-radio input { accent-color: var(--win-accent, #0078d4); }
.tp-dialog-msg { font-size: 12.5px; font-weight: 600; min-height: 17px; }
.tp-dialog-msg.is-error { color: #c62828; }
.tp-dialog-msg.is-ok { color: #2e7d32; }

@media (max-width: 720px) {
    .tpw-body { grid-template-columns: 1fr; }
    .tpw-sum { justify-content: space-between; }
}
</style>

<script>
/* ── پلانی چارەسەر tab: the patient's plans, each opening its own page ── */
(function () {
    'use strict';
    const API = '<?= APP_URL ?>/ajax/treatment_plan.php';
    const PATIENT_ID = <?= (int)$patientId ?>;
    const PLAN_PAGE = '<?= APP_URL ?>/treatment-plans/view.php?id=';
    const PRINT_PAGE = '<?= APP_URL ?>/treatment-plans/print.php?id=';
    const CAN_CREATE = <?= $canWritePlans ? 'true' : 'false' ?>;
    const PLANS = <?= json_encode($tpwPlans, $tpwJson) ?>;
    const PLAN_STATUS = {
        open: ['چاوەڕوانی بڕیاری نەخۆش', 'is-open'], partial: ['بەشێکی گوازراوەتەوە', 'is-partial'],
        transferred: ['تەواو گوازراوەتەوە', 'is-done'], cancelled: ['هەڵوەشێنراوەتەوە', 'is-cancelled']
    };
    const ARCH_LABEL = { upper: 'ئەرکی سەرەوە', lower: 'ئەرکی خوارەوە', both: 'هەردوو ئەرک' };
    const ROUTE_CLASS = { undecided: 'is-warn', awaiting_reception: 'is-warn', self: 'is-ok', doctor: 'is-info', chosen: 'is-info' };

    const $ = id => document.getElementById(id);
    const nf = n => Number(n || 0).toLocaleString('en-US');
    const esc = s => String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    const isOpen = p => p.status === 'open' || p.status === 'partial';
    const wanted = Number(new URLSearchParams(location.search).get('plan')) || 0;

    function cardHtml(p) {
        const st = PLAN_STATUS[p.status] || [p.status, ''];
        const scope = p.scope === 'arch'
            ? '<i class="fas fa-teeth"></i> ئەرک' + (p.arch ? ' · ' + ARCH_LABEL[p.arch] : '')
            : '<i class="fas fa-tooth"></i> ددان';
        const meta = (p.doctor ? '<span><i class="fas fa-user-md"></i>د. ' + esc(p.doctor) + '</span>' : '')
            + '<span><i class="fas fa-calendar"></i><bdi>' + esc(p.date) + '</bdi></span>'
            + (p.complaint ? '<span><i class="fas fa-comment-medical"></i>' + esc(p.complaint) + '</span>' : '')
            + (p.duration ? '<span><i class="fas fa-hourglass-half"></i>' + esc(p.duration) + '</span>' : '');
        const pending = p.counts.planned;
        const work = p.work.length
            ? p.work.slice(0, 6).map(w => '<span>' + esc(w.name) + '<b>× ' + w.qty + '</b></span>').join('')
              + (p.work.length > 6 ? '<span class="is-none">+' + (p.work.length - 6) + ' جۆری تر</span>' : '')
            : '<span class="is-none">هێشتا هیچ چارەسەرێک نییە</span>';
        // Buttons stop the click from also opening the card.
        const stop = ' onclick="event.stopPropagation()"';
        return '<div class="tpw-card' + (p.id === wanted ? ' is-wanted' : '') + (isOpen(p) ? '' : ' is-closed') + '"'
            + ' role="link" tabindex="0" data-plan="' + p.id + '" title="کردنەوەی پەڕەی پلان">'
            + '<div class="tpw-head">'
            +   '<div class="tpw-id"><span class="tpw-ico"><i class="fas fa-clipboard-list"></i></span><div>'
            +     '<div class="tpw-name"><bdi>' + esc(p.number) + '</bdi><span class="tpw-badge ' + st[1] + '">' + st[0] + '</span>'
            +     '<span class="tpw-chip">' + scope + '</span></div>'
            +     '<div class="tpw-meta">' + meta + '</div>'
            +   '</div></div>'
            +   '<div class="tpw-actions">'
            +     (p.canTransfer && pending && isOpen(p)
                    ? '<a class="btn btn-success btn-sm" href="' + PLAN_PAGE + p.id + '&transfer=1"' + stop + '><i class="fas fa-exchange-alt"></i> گواستنەوە بۆ کەیس (' + pending + ')</a>' : '')
            +     '<a class="btn btn-outline btn-sm" target="_blank" href="' + PRINT_PAGE + p.id + '"' + stop + '><i class="fas fa-print"></i> چاپ</a>'
            +     '<a class="btn btn-primary btn-sm" href="' + PLAN_PAGE + p.id + '"' + stop + '><i class="fas fa-folder-open"></i> کردنەوە</a>'
            +   '</div>'
            + '</div>'
            + '<div class="tpw-body">'
            +   '<div><div class="tpw-work">' + work + '</div>'
            +     (p.route.label ? '<div class="tpw-route"><i class="fas fa-route"></i> کێ چارەسەر دەکات: <b class="' + (ROUTE_CLASS[p.route.state] || '') + '">' + esc(p.route.label) + '</b></div>' : '')
            +   '</div>'
            +   '<div class="tpw-sum">'
            +     '<div><small>ئیش</small><b>' + p.counts.live + '</b></div>'
            +     '<div><small>کۆی خەمڵێنراو</small><b>' + nf(p.totals.estimated) + '</b></div>'
            +     (p.totals.discount > 0 ? '<div class="is-cut"><small>داشکاندن</small><b>' + nf(p.totals.discount) + '</b></div>' : '')
            +     '<div class="is-final"><small>کۆتایی</small><b>' + nf(p.totals.final) + '</b></div>'
            +   '</div>'
            + '</div></div>';
    }

    function render() {
        const host = $('tpwList');
        if (!host) return;
        if (!PLANS.length) {
            host.innerHTML = '<div class="tpw-empty"><span class="tpw-empty-ico"><i class="fas fa-clipboard-list"></i></span>'
                + '<b>هێشتا پلانی چارەسەر نییە</b>'
                + '<p>دوای پشکنین پلانێک دروست بکە؛ پلانەکە لە پەڕەی خۆیدا دەکرێتەوە بۆ دیاریکردنی ددان و چارەسەرەکان.</p>'
                + (CAN_CREATE ? '<button type="button" class="btn btn-primary" onclick="tpNewPlan()"><i class="fas fa-plus-circle"></i> دروستکردنی پلان</button>' : '')
                + '</div>';
            return;
        }
        host.innerHTML = PLANS.map(cardHtml).join('');
        host.querySelectorAll('.tpw-card').forEach(card => {
            const open = () => { location.href = PLAN_PAGE + card.dataset.plan; };
            card.addEventListener('click', open);
            card.addEventListener('keydown', e => { if (e.key === 'Enter') open(); });
        });
    }

    function post(action, data) {
        const fd = new FormData();
        fd.append('action', action);
        Object.keys(data).forEach(k => { if (data[k] !== undefined && data[k] !== null) fd.append(k, data[k]); });
        return fetch(API, { method: 'POST', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(r => r.json())
            .catch(() => ({ success: false, error: 'هەڵە لە پەیوەندی' }));
    }

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
            if (!res || !res.success) {
                btn.disabled = false;
                m.textContent = (res && (res.error || res.message)) || 'هەڵە';
                m.classList.add('is-error');
                return;
            }
            // The new plan opens on its own page, ready for its chart and work.
            location.href = PLAN_PAGE + res.plan_id;
        });
    };

    render();
    document.addEventListener('DOMContentLoaded', function () {
        const qs = new URLSearchParams(location.search);
        if (qs.get('tab') === 'plans' && typeof showTab === 'function') showTab('plans');
    });
})();
</script>
