/*
 * ڕەچەتە — the print dialog: the doctor's A5 sheet (erp2/prescription-print.php)
 * in a frame, with the paper switch, the sold copy and the print button.
 *
 *   RxPrint.open(id)                 a prescription
 *   RxPrint.open(id, { sold: true }) start on the copy with prices + «فرۆشرا»
 *   RxPrint.open(id, { onClose: fn }) fn runs when the dialog is closed
 *
 * Used by the case page (cases/view.php), the pharmacy's list
 * (erp2/prescriptions.php) and the till (erp2/pos.php) — one dialog, one look.
 * Self-contained: its own styles, above any page's own dialogs. The paper and
 * the pad offset are kept by the sheet itself (assets/js/rx-sheet.js).
 *
 * Load with data-app="<APP_URL>" on the script tag.
 */
(function () {
    'use strict';
    if (window.RxPrint) return;
    var me = document.currentScript;
    var APP = (me && me.getAttribute('data-app')) || '';
    var ov = null, frame = null, current = 0, onClose = null;

    var CSS = ''
        + '.rxp-ov{position:fixed;inset:0;z-index:100000;display:grid;place-items:center;padding:14px;background:rgba(8,26,34,.55);backdrop-filter:blur(2px);font-family:var(--app-font-family,"NRT",Tahoma,sans-serif)}'
        + '.rxp-ov[hidden]{display:none}'
        + '.rxp-md{width:min(1020px,100%);max-height:calc(100vh - 28px);display:flex;flex-direction:column;background:#fff;border-radius:16px;overflow:hidden;box-shadow:0 30px 60px -20px rgba(2,20,25,.6);direction:rtl}'
        + '.rxp-h{display:flex;flex-wrap:wrap;align-items:center;gap:8px;padding:10px 14px;border-bottom:1px solid #e2e8f0}'
        + '.rxp-h .t{font-weight:800;font-size:15px;color:#0f2a3a;display:flex;align-items:center;gap:8px}'
        + '.rxp-h .t i{color:#296fa0;font-style:normal;font-family:Georgia,serif;font-size:20px}'
        + '.rxp-sp{flex:1}'
        + '.rxp-seg{display:inline-flex;border:1px solid #cbd5e1;border-radius:10px;overflow:hidden}'
        + '.rxp-seg button{border:0;background:#fff;padding:7px 12px;font:inherit;font-size:13px;font-weight:700;color:#475569;cursor:pointer}'
        + '.rxp-seg button.on{background:#296fa0;color:#fff}'
        + '.rxp-chk{display:inline-flex;align-items:center;gap:6px;font-size:13px;font-weight:700;color:#b91c1c;padding:6px 10px;border:1px solid #fecaca;border-radius:10px;background:#fff5f5;cursor:pointer}'
        + '.rxp-btn{border:1px solid #cbd5e1;background:#fff;border-radius:10px;padding:7px 12px;font:inherit;font-size:13px;font-weight:700;color:#334155;cursor:pointer}'
        + '.rxp-btn.go{background:#14b8a6;border-color:#14b8a6;color:#fff;padding:7px 18px}'
        + '.rxp-btn.x{width:36px;padding:7px 0}'
        + '.rxp-cal{display:flex;flex-wrap:wrap;align-items:center;gap:10px;padding:8px 14px;background:#f8fafc;border-bottom:1px solid #e2e8f0;font-size:12.5px;color:#334155}'
        + '.rxp-cal[hidden]{display:none}'
        + '.rxp-cal input{width:70px;padding:4px 6px;border:1px solid #cbd5e1;border-radius:7px;font:inherit;text-align:center;direction:ltr}'
        + '.rxp-cal small{color:#64748b}'
        + '.rxp-f{width:100%;height:min(70vh,680px);border:0;background:#e9eef2;display:block}';

    function build() {
        if (ov) return;
        var st = document.createElement('style');
        st.textContent = CSS;
        document.head.appendChild(st);
        ov = document.createElement('div');
        ov.className = 'rxp-ov';
        ov.hidden = true;
        ov.innerHTML = ''
            + '<div class="rxp-md" role="dialog" aria-modal="true" aria-label="ڕەچەتە">'
            + '<div class="rxp-h">'
            +   '<span class="t"><i>&#8478;</i> ڕەچەتە <span class="rxp-no"></span></span><span class="rxp-sp"></span>'
            +   '<span class="rxp-seg" role="group" aria-label="جۆری کاغەز">'
            +     '<button type="button" data-p="blank" title="هەموو دیزاینەکە چاپ دەکرێت">کاغەزی سپی</button>'
            +     '<button type="button" data-p="pad" title="بۆ کاغەزی ڕەچەتەی ئامادە: تەنها ناو، تەمەن، ڕێکەوت و دەرمانەکان">کاغەزی چاپکراو</button>'
            +   '</span>'
            +   '<label class="rxp-chk" hidden><input type="checkbox" class="rxp-sold"> نرخ و مۆری «فرۆشرا»</label>'
            +   '<button type="button" class="rxp-btn rxp-calbtn" hidden title="ئەگەر نووسینەکان لەسەر کاغەزی چاپکراو جێگیر نەبوون">ڕێکخستنی شوێن</button>'
            +   '<button type="button" class="rxp-btn go">چاپکردن</button>'
            +   '<button type="button" class="rxp-btn x" aria-label="داخستن">&#10005;</button>'
            + '</div>'
            + '<div class="rxp-cal" hidden>'
            +   '<b>ڕێکخستنی شوێن بۆ کاغەزی چاپکراو</b>'
            +   '<label>بۆ ڕاست <input type="number" step="0.5" min="-15" max="15" data-o="x"> mm</label>'
            +   '<label>بۆ خوارەوە <input type="number" step="0.5" min="-15" max="15" data-o="y"> mm</label>'
            +   '<small>یەکجار تاقی بکەرەوە؛ ئەم کۆمپیوتەرە بیری دەمێنێت. ژمارەی سالب بۆ چەپ / سەرەوە.</small>'
            + '</div>'
            + '<iframe class="rxp-f" title="ڕەچەتە"></iframe>'
            + '</div>';
        document.body.appendChild(ov);
        frame = ov.querySelector('.rxp-f');

        ov.addEventListener('mousedown', function (e) { if (e.target === ov) close(); });
        ov.querySelector('.x').addEventListener('click', close);
        ov.querySelector('.go').addEventListener('click', function () { var s = sheet(); if (s) s.print(); });
        ov.querySelectorAll('.rxp-seg button').forEach(function (b) {
            b.addEventListener('click', function () { var s = sheet(); if (s) { s.setPaper(b.getAttribute('data-p')); sync(); } });
        });
        ov.querySelector('.rxp-sold').addEventListener('change', function (e) { var s = sheet(); if (s) s.setSold(e.target.checked); });
        ov.querySelector('.rxp-calbtn').addEventListener('click', function () { var c = ov.querySelector('.rxp-cal'); c.hidden = !c.hidden; });
        ov.querySelectorAll('.rxp-cal input').forEach(function (inp) {
            inp.addEventListener('input', function () {
                var s = sheet(); if (!s) return;
                var x = ov.querySelector('[data-o="x"]').value, y = ov.querySelector('[data-o="y"]').value;
                s.setOffset(x || 0, y || 0);
            });
        });
        frame.addEventListener('load', sync);
        // While open, the page behind gets no keys: Esc closes, and the till's
        // F-keys (F10 = pay) must never fire under the dialog.
        document.addEventListener('keydown', function (e) {
            if (ov.hidden) return;
            if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); close(); }
            else if (/^F\d+$/.test(e.key)) { e.preventDefault(); e.stopPropagation(); }
        }, true);
    }

    function sheet() {
        try { return frame.contentWindow && frame.contentWindow.rxSheet; } catch (e) { return null; }
    }

    /* the bar follows the page inside: its paper, whether it can show prices, the offset */
    function sync() {
        var s = sheet();
        if (!s) return;
        var p = s.paper();
        ov.querySelectorAll('.rxp-seg button').forEach(function (b) { b.classList.toggle('on', b.getAttribute('data-p') === p); });
        var pad = p === 'pad';
        ov.querySelector('.rxp-calbtn').hidden = !pad;
        if (!pad) ov.querySelector('.rxp-cal').hidden = true;
        var o = s.offset();
        ov.querySelector('[data-o="x"]').value = o.x;
        ov.querySelector('[data-o="y"]').value = o.y;
        var can = s.canSold();
        ov.querySelector('.rxp-chk').hidden = !can;
        ov.querySelector('.rxp-sold').checked = can && frame.contentDocument.documentElement.classList.contains('show-sold');
    }

    function open(id, opts) {
        opts = opts || {};
        build();
        current = Number(id) || 0;
        if (!current) return;
        onClose = typeof opts.onClose === 'function' ? opts.onClose : null;
        ov.querySelector('.rxp-no').textContent = '#' + current;
        ov.querySelector('.rxp-chk').hidden = true;
        ov.querySelector('.rxp-cal').hidden = true;
        frame.src = APP + '/erp2/prescription-print.php?id=' + current + '&embed=1' + (opts.sold ? '&sold=1' : '');
        ov.hidden = false;
        setTimeout(function () { ov.querySelector('.go').focus(); }, 0);
    }
    function close() {
        if (!ov || ov.hidden) return;
        ov.hidden = true;
        frame.src = 'about:blank';
        var cb = onClose;
        onClose = null;
        if (cb) cb();
    }

    window.RxPrint = { open: open, close: close };
})();
