<?php
/**
 * «سەردانی نوێ» as a large dialog: new_visit.php?embed=1 in an iframe, so the
 * whole form works exactly as on its own page — patient search, the checks on
 * save, and the after-save choice (مەوعید / ژووری چاوەڕوانی).
 *
 * Include once on a page (visits.php, waiting-room.php, index.php). Any link or
 * button with data-new-visit-dialog then opens the dialog instead of the page;
 * Ctrl/Shift/middle-click still opens the page itself. openNewVisitDialog()
 * opens it from script.
 *
 * The form talks to this page with postMessage (same origin only):
 *   close     the dialog closes, nothing saved
 *   saved     a visit was saved and its after-save choice is done:
 *             close and reload, so the new visit shows in this page's list
 *   navigate  go to that page of the app (the waiting room, appointments)
 */
if (!empty($newVisitDialogIncluded)) {
    return;
}
$newVisitDialogIncluded = true;
?>
<div class="nvd-overlay" id="newVisitDialog" hidden>
    <div class="nvd-box" role="dialog" aria-modal="true" aria-labelledby="nvdTitle">
        <div class="nvd-head">
            <span class="nvd-ico"><i class="fas fa-user-plus"></i></span>
            <h3 id="nvdTitle">سەردانی نوێ</h3>
            <span class="nvd-keys"><kbd>Ctrl</kbd>+<kbd>Enter</kbd> تۆمارکردن</span>
            <a class="nvd-btn" href="<?= APP_URL ?>/new_visit.php" title="کردنەوە لە پەڕەیەکی جیادا"><i class="fas fa-up-right-from-square"></i></a>
            <button type="button" class="nvd-btn" onclick="closeNewVisitDialog()" title="داخستن (Esc)"><i class="fas fa-times"></i></button>
        </div>
        <div class="nvd-body">
            <div class="nvd-loading" id="nvdLoading"><i class="fas fa-spinner fa-spin"></i> بار دەکرێت…</div>
            <iframe id="nvdFrame" title="سەردانی نوێ"></iframe>
        </div>
    </div>
</div>

<style>
.nvd-overlay { position: fixed; inset: 0; z-index: 2500; background: rgba(15, 23, 42, .45); display: flex; align-items: center; justify-content: center; padding: 2vh 2vw; }
.nvd-overlay[hidden] { display: none; }
.nvd-box { width: min(1440px, 100%); height: 100%; background: var(--bg-solid-base, #f3f3f3); border-radius: 14px; overflow: hidden; display: flex; flex-direction: column; box-shadow: 0 24px 60px rgba(15, 23, 42, .35); }
.nvd-head { display: flex; align-items: center; gap: 10px; padding: 10px 14px; background: #fff; border-bottom: 1px solid #e5e7eb; }
.nvd-ico { width: 34px; height: 34px; border-radius: 9px; display: inline-flex; align-items: center; justify-content: center; color: #fff; background: linear-gradient(135deg, #4caf50, #2e7d32); }
.nvd-head h3 { margin: 0; font-size: 16px; font-weight: 800; color: #1f2937; }
.nvd-keys { margin-inline-start: auto; font-size: 11.5px; color: #94a3b8; }
.nvd-keys kbd { font-family: inherit; font-size: 10.5px; background: #f1f5f9; border: 1px solid #e2e8f0; border-radius: 4px; padding: 0 4px; }
.nvd-btn { width: 34px; height: 34px; border: 1px solid #e5e7eb; border-radius: 8px; background: #fff; color: #475569; display: inline-flex; align-items: center; justify-content: center; cursor: pointer; text-decoration: none; }
.nvd-btn:hover { background: #f1f5f9; color: #0f172a; }
.nvd-body { position: relative; flex: 1; min-height: 0; }
.nvd-body iframe { position: absolute; inset: 0; width: 100%; height: 100%; border: 0; background: transparent; }
.nvd-loading { position: absolute; inset: 0; display: flex; align-items: center; justify-content: center; gap: 8px; color: #64748b; font-size: 14px; }
body.nvd-open { overflow: hidden; }
@media (max-width: 700px) { .nvd-overlay { padding: 0; } .nvd-box { border-radius: 0; } .nvd-keys { display: none; } }
</style>

<script>
(function () {
    'use strict';
    const SRC = '<?= APP_URL ?>/new_visit.php?embed=1';
    const overlay = document.getElementById('newVisitDialog');
    const frame = document.getElementById('nvdFrame');
    const loading = document.getElementById('nvdLoading');
    if (!overlay || !frame) return;
    // The dialog sits on <body>, above the page's own layout and dialogs.
    if (overlay.parentNode !== document.body) document.body.appendChild(overlay);

    frame.addEventListener('load', function () {
        if (frame.getAttribute('src') && frame.getAttribute('src') !== 'about:blank') loading.hidden = true;
    });

    window.openNewVisitDialog = function () {
        loading.hidden = false;
        frame.src = SRC + '&t=' + Date.now();
        overlay.hidden = false;
        document.body.classList.add('nvd-open');
        frame.focus();
    };

    // Something typed and not saved yet? (Same origin, so the form can be read.)
    function hasUnsavedInput() {
        try {
            const d = frame.contentDocument;
            if (!d || /[?&]success=1/.test(d.location.search)) return false;
            return ['patient_search', 'full_name', 'phone', 'notes', 'patient_notes']
                .some(id => { const el = d.getElementById(id); return el && String(el.value || '').trim() !== ''; });
        } catch (e) {
            return false;
        }
    }

    window.closeNewVisitDialog = function (reload, force) {
        if (overlay.hidden) return;
        if (!force && !reload && hasUnsavedInput() && !confirm('سەردانەکە تۆمار نەکراوە. داخرێت و زانیارییە نووسراوەکان لاببرێن؟')) return;
        overlay.hidden = true;
        document.body.classList.remove('nvd-open');
        frame.src = 'about:blank';
        if (reload) location.reload();
    };

    window.addEventListener('message', function (e) {
        if (e.origin !== location.origin || e.source !== frame.contentWindow) return;
        const msg = e.data || {};
        if (msg.source !== 'new-visit') return;
        if (msg.action === 'close') closeNewVisitDialog(false, true);
        else if (msg.action === 'saved') closeNewVisitDialog(true);
        else if (msg.action === 'navigate' && msg.url) {
            const url = new URL(msg.url, location.href);
            if (url.origin === location.origin) location.href = url.href;
        }
    });

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && !overlay.hidden) closeNewVisitDialog();
    });

    // Links and buttons marked data-new-visit-dialog open the dialog instead.
    document.addEventListener('click', function (e) {
        const trigger = e.target.closest('[data-new-visit-dialog]');
        if (!trigger || e.defaultPrevented || e.button !== 0 || e.ctrlKey || e.metaKey || e.shiftKey) return;
        e.preventDefault();
        openNewVisitDialog();
    });
})();
</script>
