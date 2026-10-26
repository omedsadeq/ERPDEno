/*
 * ڕەچەتە — the sheet's own behaviour (includes/rx_sheet.php).
 *
 *   QR codes   drawn from the doctor's links (assets/vendor/qrcode-generator)
 *   fit        a long medicine list shrinks until it fits its box
 *   paper      'blank' prints the whole design, 'pad' only what the clinic's
 *              pre-printed pad leaves empty, shifted by this printer's offset
 *   sold       the prices and the «فرۆشرا» stamp, on a sold prescription
 *
 * The paper and the pad offset are remembered per computer (each printer
 * feeds paper a little differently) — a convenience only: without storage the
 * sheet prints on blank paper with no offset. window.rxSheet lets the print
 * dialog (assets/js/rx-print.js) drive the page inside its frame.
 */
(function () {
    'use strict';
    var html = document.documentElement;
    var params = new URLSearchParams(location.search);
    var mine = document.currentScript;

    function get(k) { try { return localStorage.getItem(k); } catch (e) { return null; } }
    function set(k, v) { try { localStorage.setItem(k, String(v)); } catch (e) { /* not kept: fine */ } }
    function num(v) { var n = parseFloat(v); return isFinite(n) ? Math.max(-15, Math.min(15, n)) : 0; }

    function applyOffset() {
        var pad = html.classList.contains('paper-pad');
        html.style.setProperty('--ox', (pad ? num(get('rx_pad_ox')) : 0) + 'mm');
        html.style.setProperty('--oy', (pad ? num(get('rx_pad_oy')) : 0) + 'mm');
    }
    function setPaper(p) {
        p = p === 'pad' ? 'pad' : 'blank';
        html.classList.toggle('paper-pad', p === 'pad');
        html.classList.toggle('paper-blank', p !== 'pad');
        document.querySelectorAll('[data-paper]').forEach(function (b) { b.classList.toggle('on', b.getAttribute('data-paper') === p); });
        applyOffset();
        return p;
    }
    function setSold(on) {
        html.classList.toggle('show-sold', !!on && html.getAttribute('data-can-sold') === '1');
        var box = document.getElementById('rxSoldBox');
        if (box) box.checked = html.classList.contains('show-sold');
        fit();
    }

    function drawQr() {
        if (typeof window.qrcode !== 'function') return;
        document.querySelectorAll('[data-qr]').forEach(function (el) {
            var url = el.getAttribute('data-qr');
            if (!url) return;
            try {
                var q = window.qrcode(0, 'M');
                q.addData(url);
                q.make();
                el.innerHTML = q.createSvgTag({ cellSize: 2, margin: 0, scalable: true });
            } catch (e) { el.textContent = ''; }
        });
    }

    /* the pad's labels: squeezed to their width on the PDF, never stretched */
    function fitLabels() {
        var sheet = document.querySelector('.rx-sheet');
        if (!sheet) return;
        var mm = sheet.offsetWidth / 210;
        document.querySelectorAll('.rx-lbl[data-w]').forEach(function (el) {
            el.style.transform = 'none';
            var k = (parseFloat(el.getAttribute('data-w')) * mm) / el.offsetWidth;
            if (k > 0 && k < 1) el.style.transform = 'scaleX(' + k.toFixed(3) + ')';
        });
    }

    /* a credentials line (or the degrees, the address) longer than the panel
       is wide shrinks to fit, instead of being cut off */
    function fitLines() {
        document.querySelectorAll('.rx-creds div, .rx-deg, .rx-addr span, .rx-phones span').forEach(function (el) {
            el.style.fontSize = '';
            var box = el.parentElement.classList.contains('rx-panel') ? el : el.closest('.rx-panel');
            var room = el.classList.contains('rx-deg') || el.parentElement.classList.contains('rx-creds') ? el.clientWidth : box.clientWidth - 12;
            if (el.scrollWidth > room + 1) {
                var size = parseFloat(getComputedStyle(el).fontSize);
                el.style.fontSize = (size * room / el.scrollWidth).toFixed(2) + 'px';
            }
        });
    }

    /* the medicine list: biggest size that still fits its box */
    function fit() {
        fitLabels();
        fitLines();
        var box = document.querySelector('.rx-body');
        if (!box) return;
        var s = 1;
        html.style.setProperty('--rx-fs', s);
        while (box.scrollHeight > box.clientHeight + 1 && s > 0.6) {
            s = Math.round((s - 0.04) * 100) / 100;
            html.style.setProperty('--rx-fs', s);
        }
    }

    /* inside the print dialog: the whole sheet in view, whatever the frame's size */
    function scaleToFrame() {
        if (!document.body.classList.contains('embed')) return;
        var desk = document.querySelector('.rx-desk'), sheet = document.querySelector('.rx-desk .rx-sheet');
        if (!desk || !sheet) return;
        // Placed by hand: centring a box wider than the frame and then scaling
        // it does not land in the middle on a right-to-left page.
        var w = sheet.offsetWidth, h = sheet.offsetHeight, room = document.documentElement.clientWidth;
        var s = Math.min(1.25, (room - 24) / w, (window.innerHeight - 20) / h);   // the whole page in view
        desk.style.cssText = 'position:relative;display:block;padding:0;height:' + Math.ceil(h * s + 20) + 'px';
        sheet.style.cssText = 'position:absolute;top:10px;left:' + Math.max(0, (room - w * s) / 2) + 'px;'
            + 'transform-origin:0 0;transform:scale(' + s + ')';
    }

    window.rxSheet = {
        setPaper: function (p) { set('rx_paper', setPaper(p)); },
        paper: function () { return html.classList.contains('paper-pad') ? 'pad' : 'blank'; },
        setOffset: function (x, y) { set('rx_pad_ox', num(x)); set('rx_pad_oy', num(y)); applyOffset(); },
        offset: function () { return { x: num(get('rx_pad_ox')), y: num(get('rx_pad_oy')) }; },
        setSold: setSold,
        canSold: function () { return html.getAttribute('data-can-sold') === '1'; },
        print: function () { fit(); window.focus(); window.print(); }
    };

    setPaper(params.get('paper') || get('rx_paper') || 'blank');
    setSold(params.get('sold') === '1');
    drawQr();
    // Lay out as soon as the font is there; print only once the pictures are too.
    var fonts = document.fonts && document.fonts.ready ? document.fonts.ready : Promise.resolve();
    var loaded = new Promise(function (r) { if (document.readyState === 'complete') r(); else window.addEventListener('load', r); });
    fonts.then(function () { fit(); scaleToFrame(); });
    Promise.all([fonts, loaded]).then(function () {
        fit();
        scaleToFrame();
        if (mine && mine.getAttribute('data-autoprint') === '1') setTimeout(function () { window.print(); }, 200);
    });
    window.addEventListener('resize', scaleToFrame);
})();
