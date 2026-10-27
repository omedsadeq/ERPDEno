<?php
/**
 * The salary-receipt dialog (وەسڵی مووچەدان), shared by hr/payroll.php and
 * hr/salary-receipts.php. hr/salary-receipt.php runs inside it (&embed=1).
 *
 *   <button data-hr-receipt="…/hr/salary-receipt.php?embed=1&id=12">…</button>
 *   HrReceipt.open(url) / HrReceipt.close()
 *
 * When the receipt inside is paid or signed it posts {type: 'hr-receipt'};
 * the page's [data-hr-refresh] parts are then fetched again and swapped in
 * place (never the whole page), and 'hr:refreshed' is fired on document.
 * A page can set window.hrOnReceipt to do something else.
 *
 * Only the × button or Escape closes it — a tap beside the box must not throw
 * away a signature being drawn.
 */

function hr_receipt_dialog(): void
{
    ?>
<style>
.hr-rcd{position:fixed;inset:0;z-index:2000;background:rgba(15,23,42,.55);display:flex;align-items:center;justify-content:center;padding:16px}
.hr-rcd[hidden]{display:none}
.hr-rcd-box{position:relative;width:min(660px,100%);height:min(94vh,1000px);background:#f4f6f8;border-radius:16px;overflow:hidden;
    box-shadow:0 30px 80px -20px rgba(0,0,0,.5);display:flex;flex-direction:column}
.hr-rcd-top{display:flex;align-items:center;gap:8px;padding:8px 10px 8px 16px;background:#fff;border-bottom:1px solid #e2e8f0;font-weight:800;color:#0f172a}
.hr-rcd-top .x{margin-inline-start:auto;width:38px;height:38px;border-radius:10px;border:0;background:#f1f5f9;cursor:pointer;font-size:17px;color:#334155}
.hr-rcd-top .x:hover{background:#fee2e2;color:#b91c1c}
.hr-rcd iframe{flex:1;width:100%;border:0;background:#f4f6f8}
@media (max-width:600px){.hr-rcd{padding:0}.hr-rcd-box{height:100%;border-radius:0}}
</style>
<div class="hr-rcd" id="hrRcd" hidden role="dialog" aria-modal="true" aria-label="وەسڵی مووچەدان">
    <div class="hr-rcd-box">
        <div class="hr-rcd-top"><i class="fas fa-receipt" style="color:#0f766e"></i> وەسڵی مووچەدان
            <button type="button" class="x" data-hr-rcd-close aria-label="داخستن"><i class="fas fa-xmark"></i></button></div>
        <iframe id="hrRcdFrame" title="وەسڵی مووچەدان"></iframe>
    </div>
</div>
<script>
window.HrReceipt = (function () {
    var box = document.getElementById('hrRcd'), frame = document.getElementById('hrRcdFrame');
    function open(url) { frame.src = url; box.hidden = false; document.body.style.overflow = 'hidden'; }
    function close() { box.hidden = true; frame.src = 'about:blank'; document.body.style.overflow = ''; }
    function refresh() {
        return fetch(location.href, {credentials: 'same-origin'}).then(function (r) { return r.text(); }).then(function (html) {
            var doc = new DOMParser().parseFromString(html, 'text/html');
            document.querySelectorAll('[data-hr-refresh]').forEach(function (el) {
                var fresh = doc.querySelector('[data-hr-refresh="' + el.getAttribute('data-hr-refresh') + '"]');
                if (fresh) el.replaceWith(fresh);
            });
            // On a phone the clinic shows tables as cards (includes/footer.php);
            // swapped-in rows need their labels too.
            if (window.RioResponsive) {
                document.querySelectorAll('table[data-responsive-ready]').forEach(function (t) { t.removeAttribute('data-responsive-ready'); });
                RioResponsive.initTables();
            }
            document.dispatchEvent(new CustomEvent('hr:refreshed'));
        }).catch(function () { location.reload(); });
    }
    box.addEventListener('click', function (ev) { if (ev.target.closest('[data-hr-rcd-close]')) close(); });
    document.addEventListener('keydown', function (ev) { if (ev.key === 'Escape' && !box.hidden) close(); });
    document.addEventListener('click', function (ev) {
        var b = ev.target.closest('[data-hr-receipt]');
        if (!b) return;
        ev.preventDefault();
        open(b.getAttribute('data-hr-receipt'));
    });
    window.addEventListener('message', function (ev) {
        if (ev.origin !== location.origin || !ev.data || ev.data.type !== 'hr-receipt') return;
        (typeof window.hrOnReceipt === 'function' ? window.hrOnReceipt : refresh)(ev.data);
    });
    return {open: open, close: close, refresh: refresh};
})();
</script>
    <?php
}
