/* =====================================================================
   lightbox.js — Global image zoom lightbox (dependency-free)
   - Click any <img data-lightbox>, <img class="zoomable">, or any <img>
     inside .xray-image-preview, .lightbox-target, or .zoomable to open
     a full-screen zoom overlay.
   - Mobile: pinch-to-zoom, single-finger pan, double-tap toggle.
   - Desktop: mouse wheel zoom, click-drag pan, double-click toggle.
   - ESC, click outside, X button, or +/-/0 keys to control.
   - PDF / non-image previews skipped — original click handler kept.
   ===================================================================== */
(function () {
    if (window.RioLightbox) return; // guard against double-include

    var overlay = document.createElement('div');
    overlay.className = 'rio-lightbox-overlay';
    overlay.setAttribute('role', 'dialog');
    overlay.setAttribute('aria-hidden', 'true');
    overlay.innerHTML =
        '<button type="button" class="rio-lb-btn rio-lb-close" aria-label="Close" title="داخستن">&times;</button>' +
        '<button type="button" class="rio-lb-btn rio-lb-zoom-in" aria-label="Zoom in" title="گەورەکردن">+</button>' +
        '<button type="button" class="rio-lb-btn rio-lb-zoom-out" aria-label="Zoom out" title="بچوک کردن">&minus;</button>' +
        '<button type="button" class="rio-lb-btn rio-lb-reset" aria-label="Reset" title="گەڕاندنەوە"><svg viewBox="0 0 24 24" width="18" height="18" fill="currentColor"><path d="M12 5V1L7 6l5 5V7c3.31 0 6 2.69 6 6s-2.69 6-6 6-6-2.69-6-6H4c0 4.42 3.58 8 8 8s8-3.58 8-8-3.58-8-8-8z"/></svg></button>' +
        '<a class="rio-lb-btn rio-lb-download" target="_blank" rel="noopener" aria-label="Download" title="داگرتن"><svg viewBox="0 0 24 24" width="18" height="18" fill="currentColor"><path d="M5 20h14v-2H5v2zM19 9h-4V3H9v6H5l7 7 7-7z"/></svg></a>' +
        '<div class="rio-lb-stage"><img class="rio-lb-image" alt=""></div>' +
        '<div class="rio-lb-hint">دووجار کلیک یان pinch بۆ زووم</div>';

    function attachOverlay() {
        if (!overlay.parentNode) document.body.appendChild(overlay);
    }
    if (document.body) attachOverlay();
    else document.addEventListener('DOMContentLoaded', attachOverlay);

    var img       = overlay.querySelector('.rio-lb-image');
    var stage     = overlay.querySelector('.rio-lb-stage');
    var dl        = overlay.querySelector('.rio-lb-download');
    var btnClose  = overlay.querySelector('.rio-lb-close');
    var btnZoomIn = overlay.querySelector('.rio-lb-zoom-in');
    var btnZoomOut= overlay.querySelector('.rio-lb-zoom-out');
    var btnReset  = overlay.querySelector('.rio-lb-reset');

    var scale = 1, tx = 0, ty = 0;
    var dragging = false, dragOrigX = 0, dragOrigY = 0;
    var pinchStartDist = 0, pinchStartScale = 1;
    var pinchCenter = null;
    var touchPanStart = null;
    var lastTapTime = 0;

    function apply() {
        img.style.transform = 'translate(' + tx + 'px,' + ty + 'px) scale(' + scale + ')';
    }

    function reset() {
        scale = 1; tx = 0; ty = 0; apply();
    }

    function clamp(v, lo, hi) { return Math.min(hi, Math.max(lo, v)); }

    function zoomBy(factor, cx, cy) {
        var newScale = clamp(scale * factor, 0.5, 8);
        if (cx != null && cy != null) {
            var rect = stage.getBoundingClientRect();
            var px = cx - rect.left - rect.width / 2;
            var py = cy - rect.top - rect.height / 2;
            // anchor zoom around (cx, cy)
            tx = px - (px - tx) * (newScale / scale);
            ty = py - (py - ty) * (newScale / scale);
        }
        scale = newScale;
        apply();
    }

    function open(src, title) {
        if (!src) return;
        img.src = src;
        img.alt = title || '';
        dl.href = src;
        reset();
        overlay.classList.add('open');
        overlay.setAttribute('aria-hidden', 'false');
        document.documentElement.style.overflow = 'hidden';
        document.body.style.overflow = 'hidden';
    }

    function close() {
        overlay.classList.remove('open');
        overlay.setAttribute('aria-hidden', 'true');
        document.documentElement.style.overflow = '';
        document.body.style.overflow = '';
        setTimeout(function () { img.src = ''; }, 200);
    }

    // ---- Button + key handlers ------------------------------------------
    btnClose.addEventListener('click', function (e) { e.stopPropagation(); close(); });
    btnZoomIn.addEventListener('click', function (e) { e.stopPropagation(); zoomBy(1.3); });
    btnZoomOut.addEventListener('click', function (e) { e.stopPropagation(); zoomBy(1 / 1.3); });
    btnReset.addEventListener('click', function (e) { e.stopPropagation(); reset(); });

    overlay.addEventListener('click', function (e) {
        // close when clicking the dark backdrop (not the image or buttons)
        if (e.target === overlay || e.target === stage) close();
    });

    document.addEventListener('keydown', function (e) {
        if (!overlay.classList.contains('open')) return;
        switch (e.key) {
            case 'Escape': close(); break;
            case '+': case '=': zoomBy(1.3); break;
            case '-': case '_': zoomBy(1 / 1.3); break;
            case '0': reset(); break;
        }
    });

    // ---- Mouse wheel zoom -----------------------------------------------
    stage.addEventListener('wheel', function (e) {
        if (!overlay.classList.contains('open')) return;
        e.preventDefault();
        zoomBy(e.deltaY < 0 ? 1.15 : 1 / 1.15, e.clientX, e.clientY);
    }, { passive: false });

    // ---- Mouse drag pan -------------------------------------------------
    img.addEventListener('mousedown', function (e) {
        e.preventDefault();
        dragging = true;
        dragOrigX = e.clientX - tx;
        dragOrigY = e.clientY - ty;
        img.style.cursor = 'grabbing';
    });
    document.addEventListener('mousemove', function (e) {
        if (!dragging) return;
        tx = e.clientX - dragOrigX;
        ty = e.clientY - dragOrigY;
        apply();
    });
    document.addEventListener('mouseup', function () {
        if (!dragging) return;
        dragging = false;
        img.style.cursor = '';
    });

    // ---- Double-click / double-tap toggle -------------------------------
    img.addEventListener('click', function (e) {
        e.stopPropagation();
        var now = Date.now();
        if (now - lastTapTime < 350) {
            if (scale > 1.05) reset();
            else zoomBy(2.4, e.clientX, e.clientY);
        }
        lastTapTime = now;
    });

    // ---- Touch: pinch zoom + single-finger pan --------------------------
    stage.addEventListener('touchstart', function (e) {
        if (e.touches.length === 2) {
            var a = e.touches[0], b = e.touches[1];
            pinchStartDist = Math.hypot(b.clientX - a.clientX, b.clientY - a.clientY);
            pinchStartScale = scale;
            pinchCenter = {
                x: (a.clientX + b.clientX) / 2,
                y: (a.clientY + b.clientY) / 2
            };
            touchPanStart = null;
        } else if (e.touches.length === 1) {
            var t = e.touches[0];
            touchPanStart = { x: t.clientX - tx, y: t.clientY - ty };
        }
    }, { passive: true });

    stage.addEventListener('touchmove', function (e) {
        if (e.touches.length === 2 && pinchCenter) {
            e.preventDefault();
            var a = e.touches[0], b = e.touches[1];
            var dist = Math.hypot(b.clientX - a.clientX, b.clientY - a.clientY);
            var factor = (dist / (pinchStartDist || 1));
            var newScale = clamp(pinchStartScale * factor, 0.5, 8);
            var rect = stage.getBoundingClientRect();
            var px = pinchCenter.x - rect.left - rect.width / 2;
            var py = pinchCenter.y - rect.top - rect.height / 2;
            tx = px - (px - tx) * (newScale / scale);
            ty = py - (py - ty) * (newScale / scale);
            scale = newScale;
            apply();
        } else if (e.touches.length === 1 && touchPanStart) {
            // single-finger pan
            e.preventDefault();
            var t = e.touches[0];
            tx = t.clientX - touchPanStart.x;
            ty = t.clientY - touchPanStart.y;
            apply();
        }
    }, { passive: false });

    stage.addEventListener('touchend', function (e) {
        if (e.touches.length < 2) pinchCenter = null;
        if (e.touches.length === 0) touchPanStart = null;
    }, { passive: true });

    // ---- Auto-binder: attach to relevant images -------------------------
    var SELECTOR = [
        'img[data-lightbox]',
        'img.zoomable',
        '.zoomable img',
        '.lightbox-target img',
        '.xray-image-preview img',
        '.case-image-preview img',
        '.attachment-image img'
    ].join(',');

    function bindImage(el) {
        if (!el || el.dataset.rioLb === '1') return;
        el.dataset.rioLb = '1';
        el.style.cursor = 'zoom-in';
        el.addEventListener('click', function (e) {
            e.preventDefault();
            e.stopPropagation();
            open(el.currentSrc || el.src, el.alt);
        });
    }

    function bindWrappers(root) {
        // .xray-image-preview has onclick="window.open(...)" — replace with lightbox
        root.querySelectorAll('.xray-image-preview').forEach(function (wrap) {
            if (wrap.dataset.rioLbWrap === '1') return;
            var inner = wrap.querySelector('img');
            if (!inner) return; // PDF preview etc. — keep original behavior
            wrap.dataset.rioLbWrap = '1';
            wrap.removeAttribute('onclick');
            wrap.style.cursor = 'zoom-in';
            wrap.addEventListener('click', function (e) {
                e.preventDefault();
                e.stopPropagation();
                open(inner.currentSrc || inner.src, inner.alt);
            }, true);
        });
    }

    function scan(root) {
        root = root || document;
        if (!root.querySelectorAll) return;
        root.querySelectorAll(SELECTOR).forEach(bindImage);
        bindWrappers(root);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () { scan(); });
    } else {
        scan();
    }

    if ('MutationObserver' in window) {
        var t = null;
        new MutationObserver(function () {
            clearTimeout(t);
            t = setTimeout(function () { scan(); }, 80);
        }).observe(document.documentElement, { childList: true, subtree: true });
    }

    window.RioLightbox = { open: open, close: close, scan: scan };
})();
