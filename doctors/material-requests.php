<?php
/**
 * داواکاری مەواد — the doctor asks the store for materials: pick them from
 * the store's list (with its stock), send, and follow what the store decided.
 * The materials are the doctor's, not a case's; the store approves on
 * erp/material-requests.php, which charges them to the doctor
 * (ErpMaterialRequest). The admin files for a doctor they choose.
 */
$pageTitle = 'داواکاری مەواد';
$breadcrumb = [['title' => 'دکتۆرەکان'], ['title' => 'داواکاری مەواد']];

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../classes/ErpMaterialRequest.php';

if (!$permission->can(ErpMaterialRequest::CREATE_PERMISSION)) {
    echo '<div class="alert alert-danger"><i class="fas fa-ban alert-icon"></i> ڕێگەت پێنەدراوە داواکاری مەواد بکەیت</div>';
    require_once __DIR__ . '/../includes/footer.php';
    exit;
}

// A doctor asks in their own name; the admin chooses whose request it is.
$mrpOwn = $auth->isDoctor() && !$auth->isAdmin();
$mrpDoctors = $mrpOwn ? [] : $db->fetchAll(
    "SELECT id, full_name FROM users WHERE role = 'doctor' AND is_active = 1 ORDER BY full_name"
);
$mrpDoctorId = $mrpOwn ? (int)$currentUser['id'] : (int)($_GET['doctor_id'] ?? ($mrpDoctors[0]['id'] ?? 0));
?>

<style>
/* ── داواکاری مەواد: the store's list beside the request, then its history ── */
.mrp { --mrp-line: #e5eaf1; --mrp-ink: #0f172a; --mrp-ink-2: #475569; --mrp-ink-3: #94a3b8; display: flex; flex-direction: column; gap: 16px; }
.mrp-hero {
    display: flex; align-items: center; gap: 16px; flex-wrap: wrap; padding: 20px 22px; border-radius: 18px; color: #fff;
    background: linear-gradient(135deg, #0f766e, #0d9488 60%, #14b8a6); box-shadow: 0 14px 30px rgba(13, 148, 136, .22);
}
.mrp-hero-ico { width: 52px; height: 52px; flex-shrink: 0; display: grid; place-items: center; border-radius: 16px; background: rgba(255, 255, 255, .18); font-size: 22px; }
.mrp-hero h2 { margin: 0; font-size: 21px; font-weight: 800; color: #fff; }
.mrp-hero p { margin: 4px 0 0; font-size: 13px; opacity: .9; }
.mrp-hero-text { flex: 1; min-width: 220px; }
.mrp-who { display: flex; align-items: center; gap: 8px; padding: 6px 6px 6px 12px; border-radius: 12px; background: rgba(255, 255, 255, .16); }
.mrp-who label { font-size: 13px; font-weight: 700; }
.mrp-who select { min-width: 200px; padding: 8px 10px; border: 0; border-radius: 9px; font: inherit; font-size: 13.5px; color: var(--mrp-ink); }

.mrp-grid { display: grid; grid-template-columns: minmax(0, 1fr) 380px; gap: 16px; align-items: start; }
@media (max-width: 1100px) { .mrp-grid { grid-template-columns: 1fr; } }
.mrp-card { background: #fff; border: 1px solid var(--mrp-line); border-radius: 16px; box-shadow: 0 1px 2px rgba(15, 23, 42, .04), 0 8px 22px rgba(15, 23, 42, .05); }
.mrp-card-head { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; padding: 14px 16px 10px; }
.mrp-card-head h3 { margin: 0; font-size: 15.5px; font-weight: 800; color: var(--mrp-ink); }
.mrp-card-head .mrp-count { padding: 2px 10px; border-radius: 999px; background: #ccfbf1; color: #0f766e; font-size: 12px; font-weight: 800; }

/* the store's list */
.mrp-find { position: relative; flex: 1; min-width: 200px; max-width: 360px; margin-inline-start: auto; }
.mrp-find i { position: absolute; inset-inline-start: 12px; top: 50%; transform: translateY(-50%); color: var(--mrp-ink-3); font-size: 13px; }
.mrp-find input { width: 100%; padding: 9px 12px; padding-inline-start: 34px; border: 1.5px solid #dbe3ea; border-radius: 11px; font: inherit; font-size: 13.5px; }
.mrp-find input:focus { outline: none; border-color: #0d9488; box-shadow: 0 0 0 3px rgba(13, 148, 136, .14); }
.mrp-legend { display: flex; align-items: center; gap: 6px; font-size: 12px; color: var(--mrp-ink-3); }
.mrp-list { padding: 0 16px 16px; }
.mrp-cat { margin-top: 10px; }
.mrp-cat h4 { display: flex; align-items: center; gap: 8px; margin: 0 0 8px; padding-bottom: 6px; border-bottom: 2px solid #eef2f6; font-size: 14px; font-weight: 800; color: var(--mrp-ink); }
.mrp-cat h4 small { font-size: 11.5px; font-weight: 600; color: var(--mrp-ink-3); }
.mrp-items { display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: 8px; }
.mrp-item {
    display: flex; align-items: center; gap: 8px; min-height: 52px; padding: 8px 10px; border: 1.5px solid #e2e8f0; border-radius: 12px;
    background: #fff; cursor: pointer; user-select: none; transition: border-color .12s, background .12s, box-shadow .12s;
}
.mrp-item:hover { border-color: #5eead4; background: #f0fdfa; }
.mrp-item:focus-visible { outline: 2px solid #0d9488; outline-offset: 1px; }
.mrp-item.on { border-color: #0d9488; background: #f0fdfa; box-shadow: 0 0 0 3px rgba(13, 148, 136, .12); }
.mrp-item.out .mrp-name { color: #64748b; }
.mrp-item-main { display: flex; flex-direction: column; flex: 1; min-width: 0; }
.mrp-name { font-size: 13.5px; font-weight: 700; color: var(--mrp-ink); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.mrp-sub { font-size: 11.5px; color: var(--mrp-ink-3); }
.mrp-q { padding: 1px 8px; border-radius: 999px; background: #dcfce7; color: #166534; font-size: 11.5px; font-weight: 800; white-space: nowrap; direction: ltr; }
.mrp-q.zero { background: #fef3c7; color: #92400e; }
.mrp-step { display: inline-flex; align-items: center; gap: 2px; padding: 2px; border-radius: 9px; background: #ccfbf1; }
.mrp-step button { width: 26px; height: 26px; border: 0; border-radius: 7px; background: #fff; color: #0f766e; font-size: 16px; font-weight: 800; line-height: 1; cursor: pointer; }
.mrp-step button:hover { background: #0d9488; color: #fff; }
.mrp-step b { min-width: 24px; text-align: center; font-size: 13px; color: #134e4a; }
.mrp-empty { padding: 26px 12px; text-align: center; color: var(--mrp-ink-3); font-size: 13px; }

/* the request being written */
.mrp-cart { position: sticky; top: 12px; }
.mrp-lines { padding: 0 12px; max-height: 46vh; overflow-y: auto; }
.mrp-line { display: flex; align-items: center; gap: 8px; padding: 8px 4px; border-bottom: 1px solid #f1f5f9; }
.mrp-line .mrp-item-main { min-width: 0; }
.mrp-line input { width: 74px; padding: 6px 8px; border: 1.5px solid #dbe3ea; border-radius: 8px; font: inherit; font-size: 13px; text-align: center; direction: ltr; }
.mrp-line input:focus { outline: none; border-color: #0d9488; }
.mrp-warn { color: #b45309; font-size: 12px; }
.mrp-del { width: 28px; height: 28px; border: 0; border-radius: 8px; background: #fef2f2; color: #dc2626; cursor: pointer; }
.mrp-del:hover { background: #fee2e2; }
.mrp-foot { display: flex; flex-direction: column; gap: 10px; padding: 12px 14px 14px; border-top: 1px solid var(--mrp-line); }
.mrp-foot textarea { width: 100%; min-height: 56px; padding: 9px 11px; border: 1.5px solid #dbe3ea; border-radius: 10px; font: inherit; font-size: 13px; resize: vertical; }
.mrp-foot textarea:focus { outline: none; border-color: #0d9488; }
.mrp-send {
    display: inline-flex; align-items: center; justify-content: center; gap: 8px; padding: 11px 16px; border: 0; border-radius: 12px;
    background: linear-gradient(135deg, #0d9488, #0f766e); color: #fff; font: inherit; font-size: 14.5px; font-weight: 800; cursor: pointer;
    box-shadow: 0 6px 16px rgba(15, 118, 110, .25);
}
.mrp-send:disabled { opacity: .45; cursor: not-allowed; box-shadow: none; }
.mrp-msg { font-size: 13px; font-weight: 600; }
.mrp-msg.ok { color: #15803d; }
.mrp-msg.err { color: #b91c1c; }

/* what was asked before */
.mrp-history { display: flex; flex-direction: column; gap: 10px; padding: 0 16px 16px; }
.mrp-req { padding: 12px 14px; border: 1px solid var(--mrp-line); border-inline-start: 4px solid #94a3b8; border-radius: 12px; background: #fff; }
.mrp-req.is-pending { border-inline-start-color: #f59e0b; }
.mrp-req.is-approved { border-inline-start-color: #16a34a; }
.mrp-req.is-rejected { border-inline-start-color: #dc2626; }
.mrp-req-head { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; font-size: 13px; }
.mrp-req-date { color: var(--mrp-ink-3); font-size: 12px; direction: ltr; }
.mrp-badge { padding: 2px 10px; border-radius: 999px; background: #f1f5f9; color: #475569; font-size: 11.5px; font-weight: 700; }
.mrp-req.is-pending .mrp-badge { background: #fef3c7; color: #92400e; }
.mrp-req.is-approved .mrp-badge { background: #dcfce7; color: #166534; }
.mrp-req.is-rejected .mrp-badge { background: #fee2e2; color: #991b1b; }
.mrp-req-issue { font-size: 12px; color: #0369a1; direction: ltr; }
.mrp-req-cancel { margin-inline-start: auto; padding: 4px 12px; border: 1px solid #fecaca; border-radius: 8px; background: #fff; color: #b91c1c; font: inherit; font-size: 12px; cursor: pointer; }
.mrp-req-items { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 8px; }
.mrp-req-items span { padding: 3px 10px; border-radius: 8px; background: #f8fafc; border: 1px solid #eef2f6; font-size: 12.5px; color: #1e293b; }
.mrp-req-note { margin-top: 6px; font-size: 12.5px; color: var(--mrp-ink-2); }
.mrp-req-note.is-decision { color: #991b1b; }
</style>

<div class="mrp">
    <section class="mrp-hero">
        <span class="mrp-hero-ico"><i class="fas fa-boxes-stacked"></i></span>
        <div class="mrp-hero-text">
            <h2>داواکاری مەواد لە کۆگا</h2>
            <p>مەوادەکان هەڵبژێرە و بینێرە — کۆگا پەسەندی دەکات و لەسەر ناوی <?= $mrpOwn ? 'تۆ' : 'دکتۆرەکە' ?> دەنووسرێت</p>
        </div>
        <?php if (!$mrpOwn): ?>
        <div class="mrp-who">
            <label for="mrpDoctor"><i class="fas fa-user-doctor"></i> بۆ دکتۆر</label>
            <select id="mrpDoctor">
                <?php foreach ($mrpDoctors as $d): ?>
                <option value="<?= (int)$d['id'] ?>" <?= (int)$d['id'] === $mrpDoctorId ? 'selected' : '' ?>><?= htmlspecialchars($d['full_name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php endif; ?>
    </section>

    <div class="mrp-grid">
        <section class="mrp-card">
            <div class="mrp-card-head">
                <h3><i class="fas fa-store" style="color:#0d9488"></i> مەوادەکانی کۆگا</h3>
                <span class="mrp-legend"><span class="mrp-q">12</span> بەردەست <span class="mrp-q zero">0</span> نییە</span>
                <div class="mrp-find">
                    <i class="fas fa-magnifying-glass" aria-hidden="true"></i>
                    <input type="search" id="mrpFind" autocomplete="off" placeholder="گەڕان بۆ مەواد…" aria-label="گەڕان بۆ مەواد">
                </div>
            </div>
            <div class="mrp-list" id="mrpGrid"><div class="mrp-empty">بارکردن…</div></div>
        </section>

        <aside class="mrp-card mrp-cart">
            <div class="mrp-card-head">
                <h3><i class="fas fa-clipboard-list" style="color:#0d9488"></i> داواکارییەکەم</h3>
                <span class="mrp-count" id="mrpCount">0</span>
            </div>
            <div class="mrp-lines" id="mrpLines"></div>
            <div class="mrp-empty" id="mrpLinesEmpty">مەوادێک لە لیستی کۆگا هەڵبژێرە</div>
            <div class="mrp-foot">
                <textarea id="mrpNotes" maxlength="1000" placeholder="تێبینی بۆ کۆگا (ئیختیاری)…"></textarea>
                <button type="button" class="mrp-send" id="mrpSend" disabled><i class="fas fa-paper-plane"></i> ناردن بۆ کۆگا</button>
                <div class="mrp-msg" id="mrpMsg" role="status"></div>
            </div>
        </aside>
    </div>

    <section class="mrp-card">
        <div class="mrp-card-head">
            <h3><i class="fas fa-clock-rotate-left" style="color:#0d9488"></i> داواکارییەکانی پێشوو</h3>
            <span class="mrp-count" id="mrpPending" title="چاوەڕوانی پەسەندکردن" hidden>0</span>
        </div>
        <div class="mrp-history" id="mrpHistory"><div class="mrp-empty">بارکردن…</div></div>
    </section>
</div>

<script>
// داواکاری مەواد (erp/api/material-requests.php): click a material to add it,
// − / + or type the quantity, send; the store's decision shows below.
(function () {
    const API = '<?= APP_URL ?>/erp/api/material-requests.php';
    const OWN = <?= $mrpOwn ? 'true' : 'false' ?>;
    const STATUS_CLASS = { pending: 'is-pending', approved: 'is-approved', rejected: 'is-rejected', cancelled: 'is-cancelled' };
    const $ = id => document.getElementById(id);
    const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    const nf = n => new Intl.NumberFormat('en-US', { maximumFractionDigits: 3 }).format(Number(n) || 0);
    let groups = [];
    let lines = [];   // [{product_id, name, unit, stock, quantity}]

    const doctorId = () => OWN ? 0 : Number(($('mrpDoctor') || {}).value || 0);
    function post(fields) {
        const fd = new FormData();
        const meta = document.querySelector('meta[name="csrf-token"]');
        if (meta) fd.set('csrf_token', meta.getAttribute('content'));
        Object.keys(fields).forEach(k => fd.set(k, fields[k]));
        return fetch(API, { method: 'POST', body: fd }).then(r => r.json());
    }
    function msg(text, kind) { const m = $('mrpMsg'); m.className = 'mrp-msg ' + (kind || ''); m.textContent = text || ''; }
    function product(id) { for (const g of groups) { const p = g.items.find(x => x.id === id); if (p) return p; } return null; }

    async function loadSheet() {
        try {
            const d = await (await fetch(API + '?action=sheet')).json();
            if (!d.ok) { $('mrpGrid').innerHTML = '<div class="mrp-empty">' + esc(d.error || 'نەتوانرا بارببرێت') + '</div>'; return; }
            groups = d.groups || [];
            renderGrid();
        } catch (e) { $('mrpGrid').innerHTML = '<div class="mrp-empty">نەتوانرا لیستی مەواد بارببرێت</div>'; }
    }

    function renderGrid() {
        const grid = $('mrpGrid');
        if (!groups.length) { grid.innerHTML = '<div class="mrp-empty">هیچ مەوادێک لە کۆگا تۆمار نەکراوە</div>'; return; }
        const q = $('mrpFind').value.trim().toLowerCase();
        const chosen = {};
        lines.forEach(l => { chosen[l.product_id] = l; });
        let html = '';
        groups.forEach(g => {
            const items = g.items.filter(p => !q || (p.name + ' ' + p.name_ku).toLowerCase().includes(q));
            if (!items.length) return;
            html += '<div class="mrp-cat"><h4>' + esc(g.name) + ' <small>' + items.length + '</small></h4><div class="mrp-items">'
                + items.map(p => {
                    const l = chosen[p.id];
                    return '<div class="mrp-item' + (l ? ' on' : '') + (p.stock <= 0 ? ' out' : '') + '" data-p="' + p.id + '" role="button" tabindex="0">'
                        + '<div class="mrp-item-main"><span class="mrp-name">' + esc(p.name) + '</span>'
                        + (p.name_ku ? '<span class="mrp-sub">' + esc(p.name_ku) + '</span>' : '') + '</div>'
                        + (l ? '<span class="mrp-step"><button type="button" data-dec="' + p.id + '" aria-label="کەمکردنەوە">−</button><b>' + nf(l.quantity)
                            + '</b><button type="button" data-inc="' + p.id + '" aria-label="زیادکردن">+</button></span>'
                            : '<span class="mrp-q' + (p.stock <= 0 ? ' zero' : '') + '" title="لە کۆگا">' + nf(p.stock) + (p.unit ? ' ' + esc(p.unit) : '') + '</span>')
                        + '</div>';
                }).join('') + '</div></div>';
        });
        grid.innerHTML = html || '<div class="mrp-empty">هیچ مەوادێک بەم ناوە نییە</div>';
    }

    function setQty(id, qty) {
        const at = lines.findIndex(l => l.product_id === id);
        if (qty <= 0) { if (at >= 0) lines.splice(at, 1); }
        else if (at >= 0) { lines[at].quantity = qty; }
        else {
            const p = product(id);
            if (!p) return;
            lines.push({ product_id: id, name: p.name, unit: p.unit, stock: p.stock, quantity: qty });
        }
        renderGrid();
        renderLines();
    }

    function renderLines() {
        $('mrpLines').innerHTML = lines.map(l =>
            '<div class="mrp-line"><div class="mrp-item-main"><span class="mrp-name">' + esc(l.name) + '</span>'
            + '<span class="mrp-sub">لە کۆگا: ' + (l.stock > 0 ? nf(l.stock) + ' ' + esc(l.unit) : 'نییە')
            + (l.quantity > l.stock ? ' <i class="fas fa-triangle-exclamation mrp-warn" title="لە کۆگا کەمترە"></i>' : '') + '</span></div>'
            + '<input type="number" min="0.001" step="any" value="' + l.quantity + '" data-row="' + l.product_id + '" aria-label="بڕ">'
            + '<button type="button" class="mrp-del" data-del="' + l.product_id + '" title="لابردن"><i class="fas fa-times"></i></button></div>'
        ).join('');
        $('mrpLinesEmpty').hidden = lines.length > 0;
        $('mrpCount').textContent = lines.length;
        $('mrpSend').disabled = !lines.length || (!OWN && !doctorId());
    }

    async function loadHistory() {
        const box = $('mrpHistory');
        try {
            const d = await (await fetch(API + '?action=mine' + (OWN ? '' : '&doctor_id=' + doctorId()))).json();
            if (!d.ok) { box.innerHTML = '<div class="mrp-empty">' + esc(d.error || '') + '</div>'; return; }
            const list = d.requests || [];
            const pending = list.filter(r => r.status === 'pending').length;
            $('mrpPending').hidden = !pending;
            $('mrpPending').textContent = pending + ' چاوەڕوان';
            box.innerHTML = list.length ? list.map(r =>
                '<div class="mrp-req ' + (STATUS_CLASS[r.status] || '') + '">'
                + '<div class="mrp-req-head"><b>#' + r.id + '</b><span class="mrp-req-date">' + esc(String(r.created_at).slice(0, 16)) + '</span>'
                + '<span class="mrp-badge">' + esc(r.status_label) + '</span>'
                + (r.issue_number ? '<span class="mrp-req-issue">وەسڵ ' + esc(r.issue_number) + '</span>' : '')
                + (r.status === 'pending' ? '<button type="button" class="mrp-req-cancel" data-cancel="' + r.id + '">هەڵوەشاندنەوە</button>' : '')
                + '</div><div class="mrp-req-items">' + r.items.map(it => {
                    const appr = it.approved_quantity !== null && Number(it.approved_quantity) !== Number(it.quantity)
                        ? ' <s>' + nf(it.quantity) + '</s> → ' + nf(it.approved_quantity) : ' × ' + nf(it.quantity);
                    return '<span>' + esc(it.product_name) + appr + (it.unit ? ' ' + esc(it.unit) : '') + '</span>';
                }).join('') + '</div>'
                + (r.notes ? '<div class="mrp-req-note"><i class="fas fa-comment"></i> ' + esc(r.notes) + '</div>' : '')
                + (r.decision_note ? '<div class="mrp-req-note is-decision"><i class="fas fa-reply"></i> ' + esc(r.decision_note) + '</div>' : '')
                + '</div>').join('') : '<div class="mrp-empty">هێشتا هیچ داواکارییەکی مەواد نییە</div>';
        } catch (e) { box.innerHTML = '<div class="mrp-empty">نەتوانرا بارببرێت</div>'; }
    }

    $('mrpSend').addEventListener('click', async function () {
        if (!lines.length) return;
        this.disabled = true;
        msg('');
        try {
            const d = await post({
                action: 'save', doctor_id: doctorId(), notes: $('mrpNotes').value.trim(),
                items: JSON.stringify(lines.map(l => ({ product_id: l.product_id, quantity: l.quantity })))
            });
            if (!d.ok) { msg(d.error || 'نەنێردرا', 'err'); this.disabled = false; return; }
            lines = [];
            $('mrpNotes').value = '';
            renderGrid();
            renderLines();
            msg('داواکاری #' + d.id + ' نێردرا بۆ کۆگا — چاوەڕوانی پەسەندکردنە', 'ok');
            loadHistory();
        } catch (e) { msg('هەڵەیەک ڕوویدا لە پەیوەندیکردن', 'err'); this.disabled = false; }
    });

    document.querySelector('.mrp').addEventListener('click', async e => {
        const inc = e.target.closest('[data-inc]');
        if (inc) { const id = +inc.dataset.inc; const l = lines.find(x => x.product_id === id); setQty(id, (l ? l.quantity : 0) + 1); return; }
        const dec = e.target.closest('[data-dec]');
        if (dec) { const id = +dec.dataset.dec; const l = lines.find(x => x.product_id === id); setQty(id, (l ? l.quantity : 1) - 1); return; }
        const del = e.target.closest('[data-del]');
        if (del) { setQty(+del.dataset.del, 0); return; }
        const cancel = e.target.closest('[data-cancel]');
        if (cancel) {
            if (!confirm('ئەم داواکارییە هەڵبوەشێنرێتەوە؟')) return;
            const d = await post({ action: 'cancel', id: cancel.dataset.cancel });
            if (!d.ok) { msg(d.error || '', 'err'); return; }
            loadHistory();
            return;
        }
        const card = e.target.closest('[data-p]');
        if (card && !e.target.closest('.mrp-step')) {
            // a click adds the material; a second click on a chosen one removes it
            const id = +card.dataset.p;
            setQty(id, lines.some(l => l.product_id === id) ? 0 : 1);
        }
    });
    document.querySelector('.mrp').addEventListener('keydown', e => {
        const card = e.target.closest('[data-p]');
        if (card && (e.key === 'Enter' || e.key === ' ')) { e.preventDefault(); card.click(); }
    });
    $('mrpFind').addEventListener('input', renderGrid);
    $('mrpLines').addEventListener('input', e => {
        const row = e.target.closest('[data-row]');
        if (!row) return;
        const l = lines.find(x => x.product_id === +row.dataset.row);
        const v = parseFloat(row.value);
        if (l && v > 0) { l.quantity = Math.round(v * 1000) / 1000; renderGrid(); }
    });
    $('mrpLines').addEventListener('change', renderLines);   // the stock warning, once the typing is done
    if ($('mrpDoctor')) $('mrpDoctor').addEventListener('change', () => { renderLines(); loadHistory(); });

    loadSheet();
    loadHistory();
    renderLines();
})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
