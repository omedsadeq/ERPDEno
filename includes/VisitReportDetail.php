<?php
/**
 * ڕاپۆرتی سەردانەکان — the visits behind one cell of the matrix.
 *
 * Every cell of «ئاماری سەردانەکان» on visits-report.php has a button; it
 * opens a dialog with the visits that make up that number. The visits are
 * read one by one with the same filters as the matrix (visit date, visit
 * type) and sorted into the same row and column by dh_visit_report_classify,
 * so the list always holds exactly as many visits as the cell says.
 *
 * A cell is a row and a doctor column:
 *   row  requested | reception | other | unassigned | assigned (all) |
 *        new | old | unknown | r:<staff id> (one receptionist's transfers) |
 *        patients (ئیش و پشکنین: every visit of both types)
 * With both types chosen, every row but patients is the checkups'.
 *   doc  a doctors.id, or 'all' for the کۆی گشتی column
 */
require_once __DIR__ . '/VisitReportStats.php';

const VISIT_REPORT_DETAIL_ROWS = [
    'requested' => 'داواکراو', 'reception' => 'گواستنەوەی پێشوازی', 'other' => 'دیاریکردنی تر',
    'unassigned' => 'دکتۆر دیاری نەکراوە', 'assigned' => 'کۆی گشتی', 'new' => 'نوێ', 'old' => 'کۆن',
    'unknown' => 'دۆخی نەخۆش نادیارە', 'patients' => 'نەخۆشەکان',
];

/** The cell asked for, checked: ['row' => key, 'staff' => id|0, 'doc' => id|null] or null. */
function dh_visit_report_detail_cell(string $row, string $doc): ?array
{
    $staff = 0;
    if (preg_match('/^r:(\d+)$/', $row, $m)) {
        $staff = (int) $m[1];
        $row = 'reception';
    } elseif (!isset(VISIT_REPORT_DETAIL_ROWS[$row])) {
        return null;
    }
    if ($doc !== 'all' && !ctype_digit($doc)) {
        return null;
    }
    return ['row' => $row, 'staff' => $staff, 'doc' => $doc === 'all' ? null : (int) $doc];
}

/** The visits counted in one cell, oldest first. */
function dh_visit_report_cell_visits(Database $db, string $from, string $to, string $visitType, array $cell): array
{
    if ($cell['row'] === 'patients') {
        // every visit, ئیش and پشکنین, each for its own type's doctor
        $all = array_merge(
            dh_visit_report_cell_visits($db, $from, $to, 'treatment', ['row' => 'assigned'] + $cell),
            dh_visit_report_cell_visits($db, $from, $to, 'checkup', ['row' => 'assigned'] + $cell)
        );
        usort($all, static fn($a, $b) => [$a['visit_date'], (string) $a['arrival_time'], (int) $a['id']] <=> [$b['visit_date'], (string) $b['arrival_time'], (int) $b['id']]);
        return $all;
    }
    $visitType = $visitType === 'treatment' ? 'treatment' : 'checkup';
    $doctorSql = dh_visit_report_doctor_sql($visitType);
    $params = ['from' => $from, 'to' => $to, 'visit_type' => $visitType];
    $where = '';
    if ($cell['doc'] !== null) {
        $where = " AND {$doctorSql} = :doc";
        $params['doc'] = $cell['doc'];
    }
    $out = [];
    foreach ($db->fetchAll(
        "SELECT v.id, v.visit_type, v.visit_date, v.arrival_time, v.patient_id, COALESCE(NULLIF(p.full_name, ''), v.patient_name) AS patient,
                v.mobile_number, {$doctorSql} AS doctor_id, v.requested_doctor_id, v.assigned_doctor_id,
                v.doctor_assignment_source, v.patient_status, v.receptionist_id, v.procedure_treatment, v.referral_source,
                dr.name AS requested_name, dd.name AS doctor_name, u.full_name AS receptionist_name
         FROM visits v
         LEFT JOIN patients p ON p.id = v.patient_id
         LEFT JOIN doctors dr ON dr.id = v.requested_doctor_id
         LEFT JOIN doctors dd ON dd.id = {$doctorSql}
         LEFT JOIN users u ON u.id = v.receptionist_id
         WHERE v.visit_date BETWEEN :from AND :to AND v.visit_type = :visit_type{$where}
         ORDER BY v.visit_date, v.arrival_time, v.id",
        $params
    ) as $v) {
        $c = dh_visit_report_classify($v);
        $row = $cell['row'];
        $in = $row === 'assigned'
            || (in_array($row, ['new', 'old', 'unknown'], true) ? $c['status'] === $row : $c['allocation'] === $row);
        if ($in && $cell['staff'] > 0 && $c['staff'] !== $cell['staff']) {
            $in = false;
        }
        if ($in) {
            $v['allocation'] = $c['allocation'];
            $v['status'] = $c['status'];
            $out[] = $v;
        }
    }
    return $out;
}

/** The dialog's title for a cell. */
function dh_visit_report_detail_title(Database $db, array $cell): string
{
    $row = VISIT_REPORT_DETAIL_ROWS[$cell['row']];
    if ($cell['staff'] > 0) {
        $name = $db->fetch('SELECT full_name FROM users WHERE id = ?', [$cell['staff']])['full_name'] ?? ('کارمەند #' . $cell['staff']);
        $row = 'گواستنەوەی ' . $name;
    } elseif ($cell['row'] === 'assigned') {
        $row = 'هەموو سەردانەکان';
    } elseif ($cell['row'] === 'patients') {
        $row = 'نەخۆشەکان (ئیش و پشکنین)';
    }
    if ($cell['doc'] === null) {
        return $row . ' — هەموو دکتۆرەکان';
    }
    $doctor = $cell['doc'] > 0 ? ($db->fetch('SELECT name FROM doctors WHERE id = ?', [$cell['doc']])['name'] ?? ('دکتۆر #' . $cell['doc'])) : 'دکتۆر دیاری نەکراوە';
    return $row . ' — ' . $doctor;
}

/** The dialog's body: a count, a search box and the visits. */
function dh_visit_report_detail_body(array $visits, string $from, string $to): string
{
    $e = static fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
    $app = APP_URL;
    $allocation = ['requested' => 'داواکراو', 'reception' => 'گواستنەوەی پێشوازی', 'other' => 'دیاریکردنی تر', 'unassigned' => 'دیاری نەکراو'];
    $status = ['new' => ['نوێ', 'new'], 'old' => ['کۆن', 'old'], 'unknown' => ['نادیار', 'unk']];
    $html = '<div class="vrd-sum"><b>' . number_format(count($visits)) . '</b> سەردان · <span dir="ltr">' . $e($from) . ($from !== $to ? ' — ' . $e($to) : '') . '</span></div>';
    if (!$visits) {
        return $html . '<div class="vrd-empty">هیچ سەردانێک نییە</div>';
    }
    $html .= '<input type="search" class="vrd-search" placeholder="گەڕان: ناو، مۆبایل، دکتۆر، کارمەند…" aria-label="گەڕان لە سەردانەکان">'
        . '<div class="vrd-scroll"><table class="vrd-tbl"><thead><tr><th>#</th><th>بەروار</th><th>کات</th><th>جۆر</th><th>نەخۆش</th><th>مۆبایل</th>'
        . '<th>دکتۆری داواکراو</th><th>دکتۆری بینراو</th><th>دابەشکردن</th><th>کارمەندی پێشوازی</th><th>نوێ/کۆن</th><th>ئیش / سەرچاوە</th><th></th></tr></thead><tbody>';
    foreach ($visits as $i => $v) {
        [$stLabel, $stClass] = $status[$v['status']];
        $note = trim((string) ($v['procedure_treatment'] ?? '')) ?: trim((string) ($v['referral_source'] ?? ''));
        $links = '<a href="' . $e($app . '/edit_visit.php?id=' . (int) $v['id']) . '" target="_blank" rel="noopener" title="سەردان"><i class="fas fa-pen"></i></a>';
        if (!empty($v['patient_id'])) {
            $links .= '<a href="' . $e($app . '/patients/view.php?id=' . (int) $v['patient_id']) . '" target="_blank" rel="noopener" title="نەخۆش"><i class="fas fa-user"></i></a>';
        }
        $html .= '<tr><td class="n">' . ($i + 1) . '</td><td class="d">' . $e($v['visit_date']) . '</td>'
            . '<td class="d">' . $e($v['arrival_time'] ? substr((string) $v['arrival_time'], 0, 5) : '') . '</td>'
            . '<td><span class="vrd-tag t-' . $e($v['visit_type']) . '">' . ($v['visit_type'] === 'treatment' ? 'ئیش' : 'پشکنین') . '</span></td>'
            . '<td class="p">' . $e($v['patient'] ?: '—') . '</td><td class="d">' . $e($v['mobile_number'] ?? '') . '</td>'
            . '<td>' . $e($v['requested_name'] ?? '—') . '</td><td>' . $e($v['doctor_name'] ?? '—') . '</td>'
            . '<td><span class="vrd-tag a-' . $e($v['allocation']) . '">' . $e($allocation[$v['allocation']]) . '</span></td>'
            . '<td>' . $e($v['receptionist_name'] ?? '—') . '</td>'
            . '<td><span class="vrd-tag s-' . $stClass . '">' . $stLabel . '</span></td>'
            . '<td class="w">' . $e(mb_strimwidth($note, 0, 80, '…')) . '</td><td class="l">' . $links . '</td></tr>';
    }
    return $html . '</tbody></table></div>';
}

/** The dialog and its script; once on the page. $url is the endpoint with the page's filters. */
function dh_visit_report_detail_shell(string $url): void
{
    ?>
<style>
.vr-cell{display:inline-flex;align-items:center;gap:7px;justify-content:center}
.vr-xbtn{width:24px;height:24px;padding:0;border-radius:7px;border:1px solid #bae6fd;background:#f0f9ff;color:#0369a1;cursor:pointer;font-size:11px;
    display:inline-grid;place-items:center;flex-shrink:0}
.vr-xbtn:hover{background:#0284c7;border-color:#0284c7;color:#fff}
.vrd-dlg{max-width:1100px;width:calc(100vw - 32px);max-height:90vh;display:flex;flex-direction:column}
.vrd-dlg .dialog-body{overflow:hidden;display:flex;flex-direction:column;min-height:160px}
.vrd-dlg .dialog-title i{color:#0284c7}
.vrd-sum{margin-bottom:10px;font-size:13px;color:#64748b}.vrd-sum b{color:#0f172a;font-size:15px}
.vrd-search{width:100%;max-width:360px;height:34px;border:1px solid #cbd5e1;border-radius:8px;padding:0 10px;font:inherit;font-size:13px;margin-bottom:10px}
.vrd-scroll{overflow:auto;border:1px solid #e2e8f0;border-radius:10px}
.vrd-tbl{width:100%;border-collapse:collapse;font-size:12.5px}
.vrd-tbl th{position:sticky;top:0;background:#f1f5f9;color:#334155;font-weight:700;padding:8px;border-bottom:1px solid #e2e8f0;white-space:nowrap;text-align:right}
.vrd-tbl td{padding:7px 8px;border-bottom:1px solid #eef2f5;white-space:nowrap;text-align:right}
.vrd-tbl tr:hover td{background:#f8fafc}
.vrd-tbl .n{color:#94a3b8;text-align:center}.vrd-tbl .d{direction:ltr;text-align:right;color:#475569}
.vrd-tbl .p{font-weight:700;color:#0f172a}.vrd-tbl .w{white-space:normal;min-width:140px;color:#475569}
.vrd-tbl .l a{display:inline-grid;place-items:center;width:26px;height:26px;border-radius:7px;border:1px solid #e2e8f0;color:#475569;margin-inline-start:4px}
.vrd-tbl .l a:hover{border-color:#0284c7;color:#0284c7}
.vrd-tag{display:inline-block;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:700;background:#f1f5f9;color:#475569}
.vrd-tag.a-requested{background:#ecfdf5;color:#047857}.vrd-tag.a-reception{background:#eff6ff;color:#1d4ed8}
.vrd-tag.a-other{background:#fefce8;color:#a16207}.vrd-tag.a-unassigned{background:#fef2f2;color:#b91c1c}
.vrd-tag.t-treatment{background:#dcfce7;color:#15803d}.vrd-tag.t-checkup{background:#e0f2fe;color:#0369a1}
.vrd-tag.s-new{background:#e0f2fe;color:#0369a1}.vrd-tag.s-old{background:#fef3c7;color:#b45309}
.vrd-empty,.vrd-loading{padding:36px;text-align:center;color:#94a3b8;font-size:13px}
@media print{.vr-xbtn{display:none}}
</style>
<div class="dialog-overlay" id="vrDetailDialog"><div class="dialog vrd-dlg" role="dialog" aria-modal="true" aria-labelledby="vrd-title">
    <div class="dialog-header"><h3 class="dialog-title" id="vrd-title"><i class="fas fa-layer-group"></i> <span></span></h3>
        <button type="button" class="dialog-close" onclick="closeDialog('vrDetailDialog')" aria-label="داخستن"><i class="fas fa-times"></i></button></div>
    <div class="dialog-body" id="vrdBody"></div>
</div></div>
<script>
(function () {
    var url = <?= json_encode($url) ?>;
    var body = document.getElementById('vrdBody'), title = document.querySelector('#vrd-title span'), asked = 0;
    document.addEventListener('click', function (ev) {
        var btn = ev.target.closest('[data-vr-row]');
        if (!btn) return;
        var ask = ++asked;
        title.textContent = '';
        body.innerHTML = '<div class="vrd-loading"><i class="fas fa-spinner fa-spin"></i> چاوەڕێ بکە…</div>';
        openDialog('vrDetailDialog');
        fetch(url + '&row=' + encodeURIComponent(btn.dataset.vrRow) + '&doc=' + encodeURIComponent(btn.dataset.vrDoc), {credentials: 'same-origin'})
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (ask !== asked) return;
                if (!d.ok) { body.innerHTML = '<div class="vrd-empty"></div>'; body.firstChild.textContent = d.error || 'هەڵەیەک ڕوویدا'; return; }
                title.textContent = d.title;
                body.innerHTML = d.html;
            })
            .catch(function () { if (ask === asked) body.innerHTML = '<div class="vrd-empty">نەتوانرا لیستەکە بهێنرێت</div>'; });
    });
    body.addEventListener('input', function (ev) {
        if (!ev.target.classList.contains('vrd-search')) return;
        var q = ev.target.value.trim().toLowerCase();
        body.querySelectorAll('.vrd-tbl tbody tr').forEach(function (tr) {
            tr.style.display = !q || tr.textContent.toLowerCase().indexOf(q) !== -1 ? '' : 'none';
        });
    });
})();
</script>
    <?php
}
