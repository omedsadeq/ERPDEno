<?php
/**
 * admin/expense-reports.php — its two dialogs: editing one expense in full,
 * and «ڕێکخستنی پەڕە» (the list's columns: order, name, shown; the parts of
 * the page shown). Included by the page, which answers their POSTs.
 * Uses $layout, $editCats, $editSubs, $editDoctors from the page.
 */
$erJson = fn($v) => json_encode($v, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
?>
<style>
.er-dlg { max-width: 640px; width: calc(100vw - 24px); }
.er-dlg .dialog-body { max-height: 72vh; overflow-y: auto; }
.er-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 10px 12px; }
.er-grid label { display: flex; flex-direction: column; gap: 4px; margin: 0; font-size: 12px; font-weight: 700; color: #475569; }
.er-grid label.full { grid-column: 1 / -1; }
.er-grid input, .er-grid select, .er-grid textarea { width: 100%; padding: 8px 10px; border: 1.5px solid #e2e8f0; border-radius: 8px; font: inherit; font-size: 13px; background: #fafafa; }
.er-grid input:focus, .er-grid select:focus, .er-grid textarea:focus { border-color: #0284c7; background: #fff; outline: none; }
.er-grid textarea { min-height: 64px; resize: vertical; }
.er-kindtag { display: inline-flex; align-items: center; gap: 6px; padding: 4px 10px; border-radius: 999px; font-size: 12px; font-weight: 700; margin-bottom: 10px; }
.er-msg { margin-top: 10px; padding: 8px 12px; border-radius: 8px; font-size: 12.5px; font-weight: 600; display: none; }
.er-msg.err { display: block; background: #fef2f2; color: #b91c1c; border: 1px solid #fecaca; }
.er-arr-note { margin: 0 0 10px; font-size: 12px; color: #64748b; line-height: 1.7; }
.er-arr { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 6px; }
.er-arr li { display: grid; grid-template-columns: auto auto 1fr auto auto; align-items: center; gap: 8px; padding: 6px 8px; border: 1px solid #e2e8f0; border-radius: 9px; background: #fff; }
.er-arr li.off { background: #f8fafc; opacity: .7; }
.er-arr li.drag { opacity: .4; }
.er-arr li.over { border-color: #0284c7; box-shadow: 0 0 0 2px #bae6fd; }
.er-arr .h { cursor: grab; color: #94a3b8; padding: 0 2px; }
.er-arr input[type=text] { width: 100%; padding: 6px 8px; border: 1.5px solid #e2e8f0; border-radius: 7px; font: inherit; font-size: 12.5px; }
.er-arr small { font-size: 11px; color: #94a3b8; white-space: nowrap; }
.er-arr .mv { display: inline-flex; gap: 2px; }
.er-arr .mv button { width: 24px; height: 24px; padding: 0; border: 1px solid #e2e8f0; border-radius: 6px; background: #fff; color: #475569; cursor: pointer; font-size: 10px; }
.er-arr .mv button:hover { background: #0284c7; border-color: #0284c7; color: #fff; }
.er-arr .sw { display: inline-flex; align-items: center; gap: 4px; font-size: 11.5px; font-weight: 700; color: #475569; }
.er-panels { margin-top: 12px; padding-top: 10px; border-top: 1px dashed #e2e8f0; display: flex; flex-direction: column; gap: 6px; font-size: 12.5px; font-weight: 600; color: #334155; }
.er-panels label { display: flex; align-items: center; gap: 8px; margin: 0; }
@media (max-width: 560px) { .er-grid { grid-template-columns: 1fr; } .er-arr li { grid-template-columns: auto 1fr auto; } .er-arr li small { display: none; } }
</style>

<!-- one expense, in full -->
<div class="dialog-overlay" id="erEditDialog"><div class="dialog er-dlg" role="dialog" aria-modal="true" aria-labelledby="erEditTitle">
    <div class="dialog-header"><h3 class="dialog-title" id="erEditTitle"><i class="fas fa-pen"></i> دەستکاریکردنی خەرجی</h3>
        <button type="button" class="dialog-close" onclick="closeDialog('erEditDialog')" aria-label="داخستن"><i class="fas fa-times"></i></button></div>
    <form id="erEditForm" class="dialog-body" onsubmit="return erSave(event)">
        <span class="er-kindtag" id="erKindTag"></span>
        <div class="er-grid">
            <label>بەروار<input type="date" name="expense_date" required></label>
            <label>بڕ<input type="text" name="amount" inputmode="decimal" required dir="ltr"></label>
            <label class="full">وەسف<input type="text" name="description" maxlength="255"></label>
            <label>گرووپ<select name="category_id" id="erCatSel"></select></label>
            <label>لق<select name="subgroup_id" id="erSubSel"></select></label>
            <label>دکتۆر / بەش<select name="doctor_id" id="erDocSel"></select></label>
            <label id="erVendorL">شوێن / فرۆشیار<input type="text" name="vendor" maxlength="150"></label>
            <label>ژمارەی وەسڵ<input type="text" name="receipt" maxlength="50" dir="ltr"></label>
            <label id="erMethodL">شێوازی پارەدان<select name="payment_method"><option value="cash">کاش</option><option value="card">کارت</option><option value="transfer">گواستنەوە</option><option value="check">چەک</option></select></label>
            <label id="erXkindL">ئاسایی / ئیمپلانت<select name="expense_kind"><option value="normal">ئاسایی</option><option value="implant">ئیمپلانت</option></select></label>
            <label class="full">تێبینی<textarea name="notes" maxlength="2000"></textarea></label>
        </div>
        <div class="er-msg" id="erEditMsg"></div>
    </form>
    <div class="dialog-footer">
        <button type="submit" form="erEditForm" class="btn btn-primary" id="erSaveBtn"><i class="fas fa-save"></i> پاشەکەوتکردن</button>
        <button type="button" class="btn btn-secondary" onclick="closeDialog('erEditDialog')">هەڵوەشاندنەوە</button>
    </div>
</div></div>

<!-- ڕێکخستنی پەڕە -->
<div class="dialog-overlay" id="erArrangeDialog"><div class="dialog er-dlg" role="dialog" aria-modal="true" aria-labelledby="erArrTitle">
    <div class="dialog-header"><h3 class="dialog-title" id="erArrTitle"><i class="fas fa-sliders"></i> ڕێکخستنی پەڕە</h3>
        <button type="button" class="dialog-close" onclick="closeDialog('erArrangeDialog')" aria-label="داخستن"><i class="fas fa-times"></i></button></div>
    <div class="dialog-body">
        <p class="er-arr-note">کۆڵۆمەکانی لیستەکە: ڕاکێشە (یان ▲▼) بۆ گۆڕینی ڕیز، ناوەکەیان بگۆڕە، یان بیانشارەوە. PDF ـەکەش هەمان کۆڵۆمەکان چاپ دەکات. بۆ ڕیزکردنی خەرجییەکان کلیک لەسەر ناوی کۆڵۆمێک بکە لە لیستەکە.</p>
        <ul class="er-arr" id="erArrList"></ul>
        <div class="er-panels" id="erPanels"></div>
        <div class="er-msg" id="erArrMsg"></div>
    </div>
    <div class="dialog-footer">
        <button type="button" class="btn btn-primary" onclick="erArrangeSave()"><i class="fas fa-save"></i> پاشەکەوت</button>
        <button type="button" class="btn btn-secondary" onclick="erArrangeReset()" title="هەموو کۆڵۆمەکان وەک سەرەتا"><i class="fas fa-rotate-left"></i> گەڕانەوە بۆ بنەڕەت</button>
        <button type="button" class="btn btn-secondary" onclick="closeDialog('erArrangeDialog')">داخستن</button>
    </div>
</div></div>

<script>
(function () {
    var CATS = <?= $erJson($editCats) ?>, SUBS = <?= $erJson($editSubs) ?>, DOCS = <?= $erJson($editDoctors) ?>;
    var LAYOUT = <?= $erJson($layout) ?>, PANELS = <?= $erJson(EXP_REPORT_PANELS) ?>;
    var KINDS = { general: ['خەرجی گشتی', '#2e7d32', '#e8f5e9'], lab: ['خەرجی تاقیگە', '#7b1fa2', '#f3e5f5'], store: ['خەرجی کۆگا', '#ef6c00', '#fff3e0'] };
    var cur = null;
    function $(id) { return document.getElementById(id); }
    function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
    function post(data) {
        var fd = new FormData();
        Object.keys(data).forEach(function (k) { fd.append(k, data[k]); });
        var tok = document.querySelector('meta[name="csrf-token"]');
        if (tok) fd.append('csrf_token', tok.content);
        return fetch(location.pathname, { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(function (r) { return r.json().catch(function () { return { ok: false, message: 'وەڵامی سێرڤەر نەخوێندرایەوە' }; }); });
    }
    function rowRef(btn) { return JSON.parse(btn.closest('tr').dataset.exp); }

    function fillSubs(kind, cat, sel) {
        var s = $('erSubSel'), list = SUBS.filter(function (x) { return x.kind === kind && x.cat === Number(cat); });
        s.innerHTML = '<option value="">—</option>' + list.map(function (x) { return '<option value="' + x.id + '">' + esc(x.name) + '</option>'; }).join('');
        s.value = list.some(function (x) { return x.id === Number(sel); }) ? String(sel) : '';
        s.disabled = !list.length;
    }
    window.erEdit = function (btn) {
        var e = cur = rowRef(btn), f = $('erEditForm'), k = KINDS[e.kind];
        $('erKindTag').textContent = k[0] + ' · #' + e.id;
        $('erKindTag').style.cssText = 'color:' + k[1] + ';background:' + k[2];
        f.expense_date.value = e.date; f.amount.value = e.amount; f.description.value = e.desc;
        $('erCatSel').innerHTML = (e.kind === 'general' ? '' : '<option value="">بێ گرووپ</option>')
            + (CATS[e.kind] || []).map(function (c) { return '<option value="' + c.id + '">' + esc(c.name) + '</option>'; }).join('');
        $('erCatSel').value = e.cat ? String(e.cat) : ($('erCatSel').options[0] ? $('erCatSel').options[0].value : '');
        fillSubs(e.kind, $('erCatSel').value, e.sub);
        $('erDocSel').innerHTML = '<option value="">سەنتەر (بێ دکتۆر)</option>'
            + (e.kind === 'general' ? '<option value="xray">X-ray (بەشی ئێکسڕەی)</option>' : '')
            + DOCS.map(function (d) { return '<option value="' + d.id + '">' + esc(d.name) + '</option>'; }).join('');
        $('erDocSel').value = e.doctor;
        f.vendor.value = e.vendor; f.receipt.value = e.receipt; f.notes.value = e.notes;
        $('erVendorL').firstChild.textContent = e.kind === 'lab' ? 'تاقیگە' : (e.kind === 'store' ? 'دابینکەر' : 'شوێن / فرۆشیار');
        $('erMethodL').style.display = e.kind === 'general' ? '' : 'none';
        f.payment_method.value = e.method || 'cash';
        $('erXkindL').style.display = e.kind === 'store' ? '' : 'none';
        f.expense_kind.value = e.xkind === 'implant' ? 'implant' : 'normal';
        $('erEditMsg').className = 'er-msg';
        openDialog('erEditDialog');
    };
    $('erCatSel').addEventListener('change', function () { if (cur) fillSubs(cur.kind, this.value, 0); });
    window.erSave = function (ev) {
        ev.preventDefault();
        var f = $('erEditForm'), data = { action: 'expense_save', kind: cur.kind, id: cur.id };
        ['expense_date', 'amount', 'description', 'category_id', 'subgroup_id', 'doctor_id', 'vendor', 'receipt', 'notes'].forEach(function (n) { data[n] = f[n].value; });
        if (cur.kind === 'general') data.payment_method = f.payment_method.value;
        if (cur.kind === 'store') data.expense_kind = f.expense_kind.value;
        $('erSaveBtn').disabled = true;
        post(data).then(function (r) {
            $('erSaveBtn').disabled = false;
            if (r.ok) { location.reload(); return; }
            $('erEditMsg').textContent = r.message || 'هەڵە'; $('erEditMsg').className = 'er-msg err';
        });
        return false;
    };
    window.erDelete = function (btn) {
        var e = rowRef(btn);
        if (!confirm('ئەم خەرجییە بسڕیتەوە؟\n' + e.date + ' — ' + Number(e.amount).toLocaleString('en-US') + (e.desc ? '\n' + e.desc : ''))) return;
        post({ action: 'expense_delete', kind: e.kind, id: e.id }).then(function (r) {
            if (r.ok) location.reload(); else alert(r.message || 'هەڵە');
        });
    };

    // ── ڕێکخستنی پەڕە ──
    function li(c) {
        var el = document.createElement('li');
        el.draggable = true; el.dataset.key = c.key;
        if (c.hidden) el.classList.add('off');
        el.innerHTML = '<span class="h" title="ڕاکێشە"><i class="fas fa-grip-vertical"></i></span>'
            + '<span class="mv"><button type="button" data-mv="-1" title="سەرەوە">▲</button><button type="button" data-mv="1" title="خوارەوە">▼</button></span>'
            + '<input type="text" maxlength="60" value="' + esc(c.label) + '" placeholder="' + esc(c.default) + '" aria-label="ناوی کۆڵۆم">'
            + '<small>' + esc(c.default) + '</small>'
            + '<label class="sw"><input type="checkbox"' + (c.hidden ? '' : ' checked') + (c.key === 'amount' ? ' disabled title="بڕ هەمیشە دەردەکەوێت"' : '') + '> نیشان بدە</label>';
        el.querySelector('input[type=checkbox]').addEventListener('change', function () { el.classList.toggle('off', !this.checked); });
        el.querySelectorAll('[data-mv]').forEach(function (b) {
            b.addEventListener('click', function () {
                var sib = b.dataset.mv === '-1' ? el.previousElementSibling : el.nextElementSibling;
                if (sib) el.parentNode.insertBefore(el, b.dataset.mv === '-1' ? sib : sib.nextElementSibling);
            });
        });
        el.addEventListener('dragstart', function (e) { el.classList.add('drag'); e.dataTransfer.effectAllowed = 'move'; e.dataTransfer.setData('text/plain', c.key); });
        el.addEventListener('dragend', function () { el.classList.remove('drag'); document.querySelectorAll('.er-arr li.over').forEach(function (x) { x.classList.remove('over'); }); });
        el.addEventListener('dragover', function (e) { e.preventDefault(); el.classList.add('over'); });
        el.addEventListener('dragleave', function () { el.classList.remove('over'); });
        el.addEventListener('drop', function (e) {
            e.preventDefault(); el.classList.remove('over');
            var from = document.querySelector('.er-arr li.drag');
            if (!from || from === el) return;
            var r = el.getBoundingClientRect();
            el.parentNode.insertBefore(from, e.clientY > r.top + r.height / 2 ? el.nextElementSibling : el);
        });
        return el;
    }
    window.erArrangeOpen = function () {
        var ul = $('erArrList'); ul.innerHTML = '';
        LAYOUT.cols.forEach(function (c) { ul.appendChild(li(c)); });
        $('erPanels').innerHTML = Object.keys(PANELS).map(function (k) {
            return '<label><input type="checkbox" data-panel="' + k + '"' + (LAYOUT.panels[k] ? ' checked' : '') + '> ' + esc(PANELS[k]) + '</label>';
        }).join('');
        $('erArrMsg').className = 'er-msg';
        openDialog('erArrangeDialog');
    };
    window.erArrangeSave = function () {
        var cols = [].map.call($('erArrList').children, function (el) {
            return { key: el.dataset.key, label: el.querySelector('input[type=text]').value.trim(), hidden: !el.querySelector('input[type=checkbox]').checked };
        });
        var panels = {};
        document.querySelectorAll('#erPanels [data-panel]').forEach(function (x) { panels[x.dataset.panel] = x.checked; });
        post({ action: 'layout_save', layout: JSON.stringify({ cols: cols, panels: panels }) }).then(function (r) {
            if (r.ok) { location.reload(); return; }
            $('erArrMsg').textContent = r.message || 'هەڵە'; $('erArrMsg').className = 'er-msg err';
        });
    };
    window.erArrangeReset = function () {
        if (!confirm('هەموو کۆڵۆمەکان و بەشەکانی پەڕە بگەڕێنەوە بۆ شێوەی بنەڕەت؟')) return;
        post({ action: 'layout_reset' }).then(function (r) { if (r.ok) location.reload(); else alert(r.message || 'هەڵە'); });
    };
})();
</script>
