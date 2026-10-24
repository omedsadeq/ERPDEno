<?php
/**
 * سەرچاوە مرۆییەکان — the day editor and the quick punch box.
 *
 * hr_day_editor() prints a window that opens on one employee's work day from
 * any HR screen — the monthly sheet, the reports, the punches page, the
 * dashboard — and fixes it there: change a punch's time, switch IN/OUT,
 * delete, add a punch, add or remove hours, or take a one-click fix for a
 * missing punch. Panjamor had this behind «بینین» on its in/out report only.
 *
 *     <?php hr_day_editor(); ?>                     once, near the end of the page
 *     <td data-hrd-e="12" data-hrd-d="2026-09-05">  any element: a click opens that day
 *     HrDay.open(12, '2026-09-05')                  or from script
 *
 * Self-contained (own window, own styles, own CSRF header from the page's
 * <meta name="csrf-token">) so it also works on the full-screen monthly sheet,
 * which has no clinic layout. After a change, closing the window updates only
 * the page's [data-hr-refresh] parts (see refresh() below), not the whole page.
 *
 * hr_quick_punch($employees) prints «تۆماری خێرا»: a PIN and IN / OUT /
 * automatic, recorded at this moment — for when the device is down.
 */

if (!function_exists('hr_day_editor')) {
    function hr_day_editor(): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;
        $api = APP_URL . '/hr/api/day.php';
        $punchesUrl = APP_URL . '/hr/punches.php';
        ?>
<style>
.hrd-overlay{position:fixed;inset:0;background:rgba(15,23,42,.45);display:none;align-items:flex-start;justify-content:center;z-index:5000;padding:4vh 12px;overflow:auto}
.hrd-overlay.open{display:flex}
.hrd{background:#fff;border-radius:14px;width:100%;max-width:680px;box-shadow:0 24px 60px -12px rgba(15,23,42,.45);overflow:hidden;font-size:13.5px;color:#1f2937}
.hrd-head{display:flex;align-items:flex-start;gap:12px;padding:14px 16px;background:linear-gradient(135deg,#0f766e,#115e59);color:#fff}
.hrd-head h3{margin:0;font-size:17px;font-weight:800;color:#fff}
.hrd-head small{display:block;opacity:.85;font-size:12.5px;margin-top:2px}
.hrd-x{margin-inline-start:auto;background:rgba(255,255,255,.15);border:0;color:#fff;width:32px;height:32px;border-radius:8px;cursor:pointer;font-size:15px}
.hrd-x:hover{background:rgba(255,255,255,.28)}
.hrd-body{padding:14px 16px 6px;max-height:72vh;overflow:auto}
.hrd-sum{display:flex;flex-wrap:wrap;gap:6px;margin-bottom:12px}
.hrd-pill{display:inline-flex;align-items:center;gap:5px;padding:4px 10px;border-radius:20px;background:#f1f5f9;font-size:12.5px;font-weight:700;color:#334155}
.hrd-pill b{direction:ltr;unicode-bidi:isolate}
.hrd-pill.g{background:#dcfce7;color:#15803d}.hrd-pill.r{background:#fee2e2;color:#b91c1c}
.hrd-pill.a{background:#fef3c7;color:#b45309}.hrd-pill.b{background:#e0f2fe;color:#0369a1}
.hrd-fix{border:1px solid #fde68a;background:#fffbeb;border-radius:10px;padding:10px 12px;margin-bottom:12px}
.hrd-fix-t{font-weight:800;color:#b45309;font-size:12.5px;margin-bottom:6px}
.hrd-fix button{margin:3px 0 3px 6px;border:1px solid #f59e0b;background:#fff;color:#92400e;border-radius:8px;padding:6px 11px;font-weight:700;font-size:12.5px;cursor:pointer}
.hrd-fix button:hover{background:#fef3c7}
.hrd-sec{font-size:12px;font-weight:800;color:#64748b;margin:12px 0 6px}
.hrd-pairs{display:flex;flex-wrap:wrap;gap:5px}
.hrd-pair{direction:ltr;padding:3px 9px;border-radius:7px;background:#f1f5f9;font-size:12.5px;font-variant-numeric:tabular-nums}
.hrd-pair.bad{background:#fffbeb;color:#b45309;border:1px solid #fde68a}
.hrd-list{display:flex;flex-direction:column;gap:6px}
.hrd-p{display:flex;align-items:center;gap:8px;padding:8px 10px;border:1px solid #e5e7eb;border-radius:10px;border-inline-start:4px solid #16a34a}
.hrd-p.out{border-inline-start-color:#dc2626}
.hrd-type{font-weight:800;font-size:12px;padding:2px 8px;border-radius:6px;background:#dcfce7;color:#15803d;white-space:nowrap}
.hrd-p.out .hrd-type{background:#fee2e2;color:#b91c1c}
.hrd-time{font-size:17px;font-weight:800;direction:ltr;font-variant-numeric:tabular-nums}
.hrd-meta{font-size:11.5px;color:#94a3b8;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.hrd-acts{margin-inline-start:auto;display:flex;gap:4px;flex-shrink:0}
.hrd-acts button,.hrd-mini{border:1px solid #e2e8f0;background:#fff;border-radius:7px;width:32px;height:30px;cursor:pointer;color:#475569}
.hrd-acts button:hover,.hrd-mini:hover{background:#f1f5f9}
.hrd-acts .del:hover{background:#fee2e2;color:#b91c1c;border-color:#fecaca}
.hrd-p input[type=time]{height:32px;border:1px solid #cbd5e1;border-radius:7px;padding:0 6px;direction:ltr}
.hrd-form{display:flex;flex-wrap:wrap;gap:8px;align-items:flex-end;background:#f8fafc;border:1px dashed #cbd5e1;border-radius:10px;padding:10px;margin-top:8px}
.hrd-form label{display:block;font-size:11.5px;color:#64748b;margin-bottom:3px}
.hrd-form select,.hrd-form input{height:34px;border:1px solid #cbd5e1;border-radius:7px;padding:0 8px;font:inherit;background:#fff}
.hrd-form input[type=time],.hrd-form input[type=number]{direction:ltr}
.hrd-go{height:34px;border:0;border-radius:8px;background:#0f766e;color:#fff;font-weight:800;padding:0 14px;cursor:pointer}
.hrd-go:hover{background:#115e59}
.hrd-hint{flex-basis:100%;font-size:11.5px;color:#94a3b8}
.hrd-adj{display:flex;align-items:center;gap:8px;padding:6px 10px;border-radius:8px;background:#f8fafc;margin-bottom:4px;font-size:12.5px}
.hrd-adj b{direction:ltr}
.hrd-foot{display:flex;align-items:center;gap:8px;padding:10px 16px;border-top:1px solid #f1f5f9;background:#fafbfc}
.hrd-foot a{font-size:12.5px;color:#0f766e;font-weight:700;text-decoration:none}
.hrd-msg{margin-inline-start:auto;font-size:12.5px;font-weight:700}
.hrd-msg.ok{color:#15803d}.hrd-msg.err{color:#b91c1c}
.hrd-empty{padding:14px;text-align:center;color:#94a3b8;border:1px dashed #e2e8f0;border-radius:10px}
.hrd-load{padding:40px;text-align:center;color:#94a3b8}
[data-hrd-e]{cursor:pointer}
[data-hrd-e]:hover{outline:2px solid #0f766e;outline-offset:-2px}
@media print{.hrd-overlay{display:none!important}}
</style>
<div class="hrd-overlay" id="hrdOverlay" role="dialog" aria-modal="true" aria-labelledby="hrdTitle">
    <div class="hrd">
        <div class="hrd-head">
            <div><h3 id="hrdTitle">…</h3><small id="hrdSub"></small></div>
            <button type="button" class="hrd-x" onclick="HrDay.close()" title="داخستن (Esc)"><i class="fas fa-times"></i></button>
        </div>
        <div class="hrd-body" id="hrdBody"><div class="hrd-load"><i class="fas fa-spinner fa-spin"></i></div></div>
        <div class="hrd-foot">
            <a id="hrdMonth" href="#"><i class="fas fa-calendar"></i> هەموو مانگەکە</a>
            <span class="hrd-msg" id="hrdMsg"></span>
        </div>
    </div>
</div>
<script>
window.HrDay = (function () {
    var API = <?= json_encode($api) ?>, PUNCHES = <?= json_encode($punchesUrl) ?>;
    var overlay = document.getElementById('hrdOverlay'), body = document.getElementById('hrdBody');
    var cur = null, changed = false, busy = false;
    var T = {in: 'هاتن', out: 'ڕۆیشتن'};

    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c];
        });
    }
    function token() {
        var m = document.querySelector('meta[name="csrf-token"]');
        return m ? m.getAttribute('content') : '';
    }
    function msg(text, ok) {
        var el = document.getElementById('hrdMsg');
        el.textContent = text || '';
        el.className = 'hrd-msg ' + (ok ? 'ok' : 'err');
    }
    function request(method, params) {
        var url = API, opts = {method: method, credentials: 'same-origin',
            headers: {'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json', 'X-CSRF-Token': token()}};
        if (method === 'GET') {
            url += '?' + new URLSearchParams(params).toString();
        } else {
            var fd = new FormData();
            Object.keys(params).forEach(function (k) { fd.append(k, params[k]); });
            opts.body = fd;
        }
        return fetch(url, opts).then(function (r) {
            return r.json().catch(function () { return {success: false, message: 'وەڵامی سێرڤەر نەخوێندرایەوە'}; });
        }).catch(function () { return {success: false, message: 'کێشەی پەیوەندی'}; });
    }
    function post(action, params) {
        if (busy) return Promise.resolve();
        busy = true;
        params = params || {};
        params.action = action;
        return request('POST', params).then(function (j) {
            busy = false;
            if (!j || !j.success) { msg((j && j.message) || 'هەڵە', false); return; }
            changed = true;
            if (j.day) { cur = j.day; render(); }
            msg(j.message, true);
        });
    }

    function render() {
        var d = cur, can = d.can, h = '';
        document.getElementById('hrdTitle').textContent = d.employee.name;
        // Dates and times kept left-to-right, or the RTL line flips them.
        document.getElementById('hrdSub').innerHTML = esc(d.weekday) + ' <bdo dir="ltr">' + esc(d.date) + '</bdo>'
            + (d.employee.shift ? ' · ' + esc(d.employee.shift) + ' <bdo dir="ltr">' + esc(d.employee.shift_time) + '</bdo>' : '')
            + ' · PIN ' + esc(d.employee.pin);
        document.getElementById('hrdMonth').href = PUNCHES + '?employee_id=' + d.employee.id + '&month=' + d.date.slice(0, 7);
        document.getElementById('hrdMonth').style.display = can.punches ? '' : 'none';

        h += '<div class="hrd-sum">';
        h += '<span class="hrd-pill">پەنجەمۆر <b>' + esc(d.hours) + '</b></span>';
        if (d.add) h += '<span class="hrd-pill g">زیادە <b>+' + esc(d.add) + '</b></span>';
        if (d.sub) h += '<span class="hrd-pill r">لێبڕین <b>−' + esc(d.sub) + '</b></span>';
        if (d.add || d.sub) h += '<span class="hrd-pill b">کۆ <b>' + esc(d.net) + '</b></span>';
        if (d.late) h += '<span class="hrd-pill a">دواکەوتن <b>' + d.late + '</b> خولەک</span>';
        if (d.early) h += '<span class="hrd-pill a">زوو ڕۆیشتن <b>' + d.early + '</b> خولەک</span>';
        if (d.holiday) h += '<span class="hrd-pill">پشوو: ' + esc(d.holiday) + '</span>';
        if (d.unpaired) h += '<span class="hrd-pill r"><i class="fas fa-triangle-exclamation"></i> ' + d.unpaired + ' پەنجەمۆری بێ جووت</span>';
        h += '</div>';

        if (d.suggestions.length) {
            h += '<div class="hrd-fix"><div class="hrd-fix-t"><i class="fas fa-wand-magic-sparkles"></i> چاککردنی خێرا</div>';
            d.suggestions.forEach(function (s, i) {
                h += '<button type="button" data-fix="' + i + '"><i class="fas ' + (s.kind === 'add' ? 'fa-plus' : 'fa-right-left') + '"></i> ' + esc(s.label) + '</button>';
            });
            h += '</div>';
        }

        if (d.pairs.length) {
            h += '<div class="hrd-pairs">';
            d.pairs.forEach(function (p) {
                h += '<span class="hrd-pair' + (p.ok ? '' : ' bad') + '">' + (p.in || '؟') + ' → ' + (p.out || '؟') + (p.hours ? ' (' + p.hours + ')' : '') + '</span>';
            });
            h += '</div>';
        }

        h += '<div class="hrd-sec">پەنجەمۆرەکان</div><div class="hrd-list">';
        if (!d.punches.length) {
            h += '<div class="hrd-empty">لەم ڕۆژەدا هیچ پەنجەمۆرێک نییە</div>';
        }
        d.punches.forEach(function (p) {
            h += '<div class="hrd-p ' + p.type + '" data-id="' + p.id + '">'
                + '<span class="hrd-type">' + T[p.type] + '</span>'
                + '<span class="hrd-time">' + esc(p.time) + '</span>'
                + (p.next_day ? '<span class="hrd-pill" title="دوای نیوەشەو، سەر بەم ڕۆژی کارەیە">+1 ڕۆژ</span>' : '')
                + '<span class="hrd-meta">' + (p.source === 'device' ? 'ئامێر' : 'دەستی') + (p.notes ? ' · ' + esc(p.notes) : '') + '</span>';
            if (can.punches) {
                h += '<span class="hrd-acts">'
                    + '<button type="button" data-act="edit" title="گۆڕینی کات"><i class="fas fa-clock"></i></button>'
                    + '<button type="button" data-act="toggle" title="گۆڕین بۆ ' + (p.type === 'in' ? T.out : T.in) + '"><i class="fas fa-right-left"></i></button>'
                    + '<button type="button" data-act="delete" class="del" title="سڕینەوە"><i class="fas fa-trash"></i></button>'
                    + '</span>';
            }
            h += '</div>';
        });
        h += '</div>';

        if (can.punches) {
            h += '<form class="hrd-form" id="hrdAdd">'
                + '<div><label>جۆر</label><select name="type"><option value="in"' + (d.next.type === 'in' ? ' selected' : '') + '>هاتن</option><option value="out"' + (d.next.type === 'out' ? ' selected' : '') + '>ڕۆیشتن</option></select></div>'
                + '<div><label>کات</label><input type="time" name="time" required value="' + esc(d.next.time) + '"></div>'
                + '<div style="flex:1;min-width:140px"><label>تێبینی</label><input type="text" name="notes" maxlength="255" style="width:100%" placeholder="بۆچی بە دەست؟"></div>'
                + '<button type="submit" class="hrd-go"><i class="fas fa-plus"></i> زیادکردن</button>'
                + '<div class="hrd-hint">کاتی پێش 08:00 بۆ دوای نیوەشەوی هەمان ڕۆژی کار تۆمار دەکرێت.</div>'
                + '</form>';
        }

        if (can.adjustments || d.adjustments.length) {
            h += '<div class="hrd-sec">زیادە و لێبڕینی کاتژمێر</div>';
            d.adjustments.forEach(function (a) {
                h += '<div class="hrd-adj"><span class="hrd-type" style="' + (a.type === 'overtime' ? '' : 'background:#fee2e2;color:#b91c1c') + '">'
                    + (a.type === 'overtime' ? 'زیادە' : 'لێبڕین') + '</span><b>' + (a.type === 'overtime' ? '+' : '−') + esc(a.hours) + '</b>'
                    + '<span class="hrd-meta" style="flex:1">' + esc(a.reason) + (a.by ? ' · ' + esc(a.by) : '') + '</span>'
                    + (can.adjustments ? '<button type="button" class="hrd-mini" data-adj="' + a.id + '" title="سڕینەوە"><i class="fas fa-trash"></i></button>' : '')
                    + '</div>';
            });
            if (can.adjustments) {
                h += '<form class="hrd-form" id="hrdAdj">'
                    + '<div><label>جۆر</label><select name="adj_type"><option value="overtime">زیادە</option><option value="deduction">لێبڕین</option></select></div>'
                    + '<div><label>کاتژمێر</label><input type="number" name="hours" min="0.25" max="12" step="0.25" required style="width:80px"></div>'
                    + '<div style="flex:1;min-width:140px"><label>هۆکار</label><input type="text" name="reason" required maxlength="500" style="width:100%"></div>'
                    + '<button type="submit" class="hrd-go"><i class="fas fa-plus"></i></button>'
                    + '</form>';
            }
        }
        body.innerHTML = h;
    }

    // One listener for everything drawn above.
    body.addEventListener('click', function (e) {
        var b = e.target.closest('button');
        if (!b || !cur) return;
        if (b.dataset.fix !== undefined) {
            var s = cur.suggestions[+b.dataset.fix];
            if (s.kind === 'toggle') post('toggle', {id: s.id});
            else post('add', {employee_id: cur.employee.id, date: cur.date, time: s.time, type: s.type, notes: 'چاککردنی خێرا'});
            return;
        }
        if (b.dataset.adj) {
            if (confirm('ئەم تۆمارە بسڕدرێتەوە؟')) post('adj_delete', {id: b.dataset.adj});
            return;
        }
        var row = b.closest('.hrd-p');
        if (!row) return;
        var id = row.dataset.id, act = b.dataset.act;
        if (act === 'toggle') post('toggle', {id: id});
        else if (act === 'delete') { if (confirm('ئەم پەنجەمۆرە بسڕدرێتەوە؟')) post('delete', {id: id}); }
        else if (act === 'edit') {
            var t = row.querySelector('.hrd-time');
            var acts = row.querySelector('.hrd-acts');
            t.outerHTML = '<input type="time" step="1" class="hrd-edit" value="' + t.textContent + '">';
            acts.innerHTML = '<button type="button" data-act="save" title="پاشەکەوت" style="color:#15803d"><i class="fas fa-check"></i></button>'
                + '<button type="button" data-act="cancel" title="پاشگەزبوونەوە"><i class="fas fa-times"></i></button>';
            row.querySelector('.hrd-edit').focus();
        } else if (act === 'save') post('set_time', {id: id, time: row.querySelector('.hrd-edit').value});
        else if (act === 'cancel') render();
    });
    body.addEventListener('keydown', function (e) {
        if (e.key === 'Enter' && e.target.classList.contains('hrd-edit')) {
            e.preventDefault();
            post('set_time', {id: e.target.closest('.hrd-p').dataset.id, time: e.target.value});
        }
    });
    body.addEventListener('submit', function (e) {
        e.preventDefault();
        var f = e.target, data = Object.fromEntries(new FormData(f).entries());
        data.employee_id = cur.employee.id;
        data.date = cur.date;
        post(f.id === 'hrdAdj' ? 'adj_add' : 'add', data);
    });

    function open(employeeId, date) {
        cur = null;
        msg('', true);
        body.innerHTML = '<div class="hrd-load"><i class="fas fa-spinner fa-spin"></i></div>';
        document.getElementById('hrdTitle').textContent = '…';
        document.getElementById('hrdSub').textContent = '';
        overlay.classList.add('open');
        request('GET', {action: 'day', employee_id: employeeId, date: date}).then(function (j) {
            if (!j || !j.success) { body.innerHTML = '<div class="hrd-empty">' + esc((j && j.message) || 'هەڵە') + '</div>'; return; }
            cur = j.day;
            render();
        });
    }
    function close() {
        overlay.classList.remove('open');
        if (changed) { changed = false; refresh(); }
    }

    // Bring the page's numbers up to date without reloading it.
    //   - a page that follows its own data (the live board) sets
    //     window.hrOnDayChange and is simply told to ask again;
    //   - otherwise the page is fetched in the background and only its
    //     [data-hr-refresh="…"] parts are swapped in — the rest of the page,
    //     the sidebar and the scroll position stay as they are;
    //   - a page without such parts is reloaded.
    function refresh() {
        if (typeof window.hrOnDayChange === 'function') { window.hrOnDayChange(); return; }
        var parts = document.querySelectorAll('[data-hr-refresh]');
        if (!parts.length) { location.reload(); return; }
        fetch(location.href, {credentials: 'same-origin', headers: {'Accept': 'text/html'}})
            .then(function (r) { if (!r.ok) throw new Error(r.status); return r.text(); })
            .then(function (html) {
                var doc = new DOMParser().parseFromString(html, 'text/html');
                parts.forEach(function (part) {
                    var fresh = doc.querySelector('[data-hr-refresh="' + part.getAttribute('data-hr-refresh') + '"]');
                    if (fresh && fresh.innerHTML !== part.innerHTML) part.innerHTML = fresh.innerHTML;
                });
            })
            .catch(function () { location.reload(); });
    }
    overlay.addEventListener('click', function (e) { if (e.target === overlay) close(); });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && overlay.classList.contains('open')) { e.preventDefault(); close(); }
    });
    // A click on anything carrying data-hrd-e opens that day — unless it
    // landed on a link or control of its own inside it.
    document.addEventListener('click', function (e) {
        var el = e.target.closest('[data-hrd-e]');
        if (!el) return;
        var inner = e.target.closest('a,button,input,select,textarea,label');
        if (inner && inner !== el && el.contains(inner)) return;
        e.preventDefault();
        open(el.dataset.hrdE, el.dataset.hrdD);
    });

    return {open: open, close: close, refresh: refresh, isOpen: function () { return overlay.classList.contains('open'); }};
})();
</script>
        <?php
    }

    /** «تۆماری خێرا»: a PIN and a button, recorded at this moment. */
    function hr_quick_punch(array $employees): void
    {
        hr_day_editor();
        ?>
<div class="hrq" id="hrQuick">
    <div class="hrq-t"><i class="fas fa-bolt"></i> تۆماری خێرا <small>کاتێک ئامێرەکە کار ناکات — کاتی ئێستا تۆمار دەکرێت</small></div>
    <form class="hrq-f" autocomplete="off">
        <input type="text" name="pin" list="hrQuickList" inputmode="numeric" placeholder="PIN یان ناو" required aria-label="PIN">
        <datalist id="hrQuickList">
            <?php foreach ($employees as $e): ?><option value="<?= hr_esc(hr_pin($e)) ?>"><?= hr_esc($e['full_name']) ?></option><?php endforeach; ?>
        </datalist>
        <button type="submit" name="type" value="auto" class="hrq-b auto" title="هاتن یان ڕۆیشتن، بەپێی دوایین پەنجەمۆری ئەمڕۆ"><i class="fas fa-fingerprint"></i> خۆکار</button>
        <button type="submit" name="type" value="in" class="hrq-b in"><i class="fas fa-arrow-right-to-bracket"></i> هاتن</button>
        <button type="submit" name="type" value="out" class="hrq-b out"><i class="fas fa-arrow-right-from-bracket"></i> ڕۆیشتن</button>
        <span class="hrq-msg" id="hrQuickMsg"></span>
    </form>
</div>
<style>
.hrq{background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:12px 14px;margin-bottom:16px;border-inline-start:4px solid #7c3aed}
.hrq-t{font-weight:800;color:#5b21b6;margin-bottom:8px}
.hrq-t small{font-weight:600;color:#94a3b8;margin-inline-start:6px}
.hrq-f{display:flex;flex-wrap:wrap;gap:8px;align-items:center}
.hrq-f input{height:38px;border:1px solid #cbd5e1;border-radius:9px;padding:0 12px;font:inherit;width:220px;direction:ltr;text-align:right}
.hrq-b{height:38px;border:0;border-radius:9px;padding:0 14px;font-weight:800;cursor:pointer;color:#fff}
.hrq-b.auto{background:#7c3aed}.hrq-b.in{background:#16a34a}.hrq-b.out{background:#dc2626}
.hrq-b:disabled{opacity:.6}
.hrq-msg{font-weight:700;font-size:13px}
.hrq-msg.ok{color:#15803d}.hrq-msg.err{color:#b91c1c}
</style>
<script>
(function () {
    var box = document.getElementById('hrQuick'), form = box.querySelector('form'), out = document.getElementById('hrQuickMsg');
    var names = {};
    box.querySelectorAll('#hrQuickList option').forEach(function (o) { names[o.textContent.trim().toLowerCase()] = o.value; });
    var lastType = 'auto';
    form.addEventListener('click', function (e) { var b = e.target.closest('button'); if (b) lastType = b.value; });
    form.addEventListener('submit', function (e) {
        e.preventDefault();
        // A PIN as used on the device, or a name picked from the list.
        var raw = form.pin.value.trim(), pin = names[raw.toLowerCase()] || raw.split(/\s+/)[0];
        if (!pin) { out.textContent = 'PIN یان ناوی کارمەند بنووسە'; out.className = 'hrq-msg err'; return; }
        var fd = new FormData();
        fd.append('action', 'quick'); fd.append('pin', pin); fd.append('type', e.submitter ? e.submitter.value : lastType);
        var m = document.querySelector('meta[name="csrf-token"]');
        form.querySelectorAll('button').forEach(function (b) { b.disabled = true; });
        fetch(<?= json_encode(APP_URL . '/hr/api/day.php') ?>, {method: 'POST', body: fd, credentials: 'same-origin',
            headers: {'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json', 'X-CSRF-Token': m ? m.content : ''}})
            .then(function (r) { return r.json(); })
            .catch(function () { return {success: false, message: 'کێشەی پەیوەندی'}; })
            .then(function (j) {
                form.querySelectorAll('button').forEach(function (b) { b.disabled = false; });
                out.textContent = j.message || '';
                out.className = 'hrq-msg ' + (j.success ? 'ok' : 'err');
                if (j.success) {
                    form.pin.value = '';
                    form.pin.focus();
                    HrDay.refresh();   // only the page's tables, not the whole page
                    setTimeout(function () { if (out.textContent === j.message) out.textContent = ''; }, 6000);
                } else {
                    form.pin.select();
                }
            });
    });
    lastType = 'auto';
})();
</script>
        <?php
    }
}
