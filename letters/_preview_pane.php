<?php
/**
 * The live A4 preview beside a form (letters/edit.php, letters/settings.php).
 * Every change in the form is posted to letters/preview.php and the returned
 * page is shown, scaled to the column — the same renderer the printer uses.
 *
 * Expects:
 *   $previewFormId  id of the form to watch
 *   $previewExtra   extra fields to post, e.g. ['sample' => '1']
 *   $previewTitle   caption above the sheet
 */
$previewExtra = $previewExtra ?? [];
$previewTitle = $previewTitle ?? 'پێشبینین — ئەوەی چاپ دەکرێت';
?>
<div class="ltp-col">
    <div class="ltp-bar">
        <span><i class="fas fa-eye"></i> <?= htmlspecialchars($previewTitle) ?></span>
        <span id="ltpState" class="ltp-state"></span>
    </div>
    <div class="ltp-wrap" id="ltpWrap">
        <iframe id="ltpFrame" title="پێشبینینی نوسراو" tabindex="-1"></iframe>
    </div>
</div>

<style>
.ltp-col { position: sticky; top: 12px; min-width: 0; }
.ltp-bar { display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px; font-size: 13px; font-weight: 600; color: #4b5563; }
.ltp-state { font-weight: 400; color: #9ca3af; font-size: 12px; }
.ltp-wrap { position: relative; overflow: hidden; border-radius: 10px; background: #d9dee6; border: 1px solid #cfd6df; height: 600px; }
#ltpFrame { position: absolute; top: 0; right: 0; width: 830px; height: 1160px; border: 0; transform-origin: top right; background: #d9dee6; }
@media (max-width: 1100px) { .ltp-col { position: static; } }
</style>

<script>
(function () {
    var form = document.getElementById(<?= json_encode($previewFormId) ?>);
    var extra = <?= json_encode((object)$previewExtra, JSON_UNESCAPED_UNICODE) ?>;
    var frame = document.getElementById('ltpFrame');
    var wrap = document.getElementById('ltpWrap');
    var state = document.getElementById('ltpState');
    var timer = null, seq = 0;

    function fit() {
        var scale = Math.min(1, wrap.clientWidth / 830);
        frame.style.transform = 'scale(' + scale + ')';
        wrap.style.height = Math.ceil(frame.offsetHeight * scale) + 'px';
    }

    function refresh() {
        var mine = ++seq;
        var fd = new FormData(form);
        // Images go up on save, not on every keystroke.
        Array.from(form.querySelectorAll('input[type=file]')).forEach(function (el) { fd.delete(el.name); });
        Object.keys(extra).forEach(function (k) { fd.set(k, extra[k]); });
        state.textContent = 'نوێ دەکرێتەوە...';
        fetch('<?= APP_URL ?>/letters/preview.php', { method: 'POST', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function (r) { if (!r.ok) throw new Error(r.status); return r.text(); })
            .then(function (html) {
                if (mine !== seq) return;
                frame.srcdoc = html;
                state.textContent = '';
            })
            .catch(function () { if (mine === seq) state.textContent = 'پێشبینین نوێ نەکرایەوە'; });
    }

    // Grow the frame to the whole letter (two pages when it runs over).
    frame.addEventListener('load', function () {
        try {
            frame.style.height = Math.max(1160, frame.contentDocument.documentElement.scrollHeight) + 'px';
        } catch (e) {}
        fit();
    });

    function changed(ev) {
        if (ev && ev.target && ev.target.type === 'file') return;
        clearTimeout(timer);
        timer = setTimeout(refresh, 350);
    }
    form.addEventListener('input', changed);
    form.addEventListener('change', changed);
    window.addEventListener('resize', fit);
    window.ltpRefresh = refresh;
    fit();
    refresh();
})();
</script>
