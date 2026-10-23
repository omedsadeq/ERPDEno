<?php
/**
 * دەرمانخانە — دۆز و ڕێنمایی.
 *
 * The admin sets, once per medicine, how much is taken and how: «1 دانە، 3 جار
 * لە ڕۆژێکدا — دوای خواردن». When a doctor ticks that medicine on the
 * prescription sheet (cases/view.php → دەرمان) the line starts with the
 * default one, and the others are a pick away (an adult and a child dose).
 *
 * Medicines on one side, grouped as on the paper sheet; the chosen medicine's
 * doses and the form on the other. Everything goes through api/dosages.php.
 */

require_once __DIR__ . '/_bootstrap.php';
erp2_require('erp2.dosages.manage');

$pageTitle  = 'دۆز و ڕێنمایی';
$breadcrumb = [
    ['title' => 'دەرمانخانە', 'url' => APP_URL . '/erp2/index.php'],
    ['title' => 'دۆز و ڕێنمایی'],
];

$db     = Database::getInstance();
$groups = $db->fetchAll("SELECT id, name FROM erp2_categories WHERE is_active = 1 ORDER BY sort_order, name");

$customCSS = <<<CSS
.dz{display:grid;grid-template-columns:minmax(300px,380px) minmax(0,1fr);gap:16px;align-items:start}
.dz-card{background:#fff;border:1px solid #e3e9ef;border-radius:16px;box-shadow:0 1px 2px rgba(15,23,42,.04),0 10px 26px -22px rgba(15,23,42,.45)}
.dz-list{display:flex;flex-direction:column;max-height:calc(100vh - 210px);position:sticky;top:12px}
.dz-list-head{padding:14px 14px 8px;border-bottom:1px solid #eef2f6}
.dz-search{position:relative}
.dz-search i{position:absolute;top:50%;inset-inline-start:12px;transform:translateY(-50%);color:#94a3b8;font-size:13px}
.dz-search input{width:100%;height:40px;border:1.5px solid #dbe3ea;border-radius:11px;padding-inline:34px 10px;font:14px inherit;background:#f8fafc}
.dz-search input:focus{outline:none;border-color:#0f766e;background:#fff;box-shadow:0 0 0 3px rgba(15,118,110,.12)}
.dz-chips{display:flex;gap:5px;flex-wrap:wrap;margin-top:9px}
.dz-chip{border:1px solid #dbe3ea;background:#fff;border-radius:999px;padding:3px 11px;font:12px inherit;font-weight:700;color:#475569;cursor:pointer}
.dz-chip:hover{border-color:#0f766e;color:#0f766e}
.dz-chip.on{background:#0f766e;border-color:#0f766e;color:#fff}
.dz-chip.miss.on{background:#b45309;border-color:#b45309}
.dz-count{display:flex;justify-content:space-between;font-size:11.5px;color:#94a3b8;font-weight:600;margin-top:8px}
.dz-items{overflow:auto;padding:6px 8px 10px;flex:1}
.dz-grp{position:sticky;top:-6px;z-index:1;margin:8px 0 3px;padding:4px 10px;border-radius:7px;background:#e6f3fa;color:#0c4a6e;font-size:12px;font-weight:800}
.dz-it{display:flex;align-items:center;gap:8px;width:100%;padding:8px 10px;border:1.5px solid transparent;border-radius:11px;background:none;text-align:start;cursor:pointer;font:inherit}
.dz-it:hover{background:#f6f9fb}
.dz-it.on{background:#f0fdfa;border-color:#5eead4}
.dz-it .nm{flex:1;min-width:0;font-size:13.5px;font-weight:700;color:#0f172a;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.dz-it .tp{font-size:10.5px;font-weight:700;color:#0369a1;background:#e0f2fe;border-radius:999px;padding:1px 7px;white-space:nowrap}
.dz-it .ct{min-width:22px;height:22px;padding:0 7px;border-radius:999px;display:inline-grid;place-items:center;font-size:11px;font-weight:800;background:#dcfce7;color:#166534}
.dz-it .ct.no{background:#fff7ed;color:#c2410c;font-weight:700}
.dz-edit{padding:18px 20px 20px;min-height:420px}
.dz-empty{display:grid;place-items:center;text-align:center;color:#94a3b8;min-height:380px;font-size:13.5px}
.dz-empty .ill{width:86px;height:86px;border-radius:26px;display:grid;place-items:center;margin:0 auto 12px;font-size:34px;color:#0f766e;background:linear-gradient(135deg,#f0fdfa,#e0f2fe)}
.dz-prod{display:flex;align-items:center;gap:12px;flex-wrap:wrap;padding-bottom:14px;border-bottom:1px solid #eef2f6;margin-bottom:14px}
.dz-prod .ic{width:46px;height:46px;border-radius:14px;display:grid;place-items:center;font-size:19px;color:#fff;background:linear-gradient(135deg,#14b8a6,#0f766e);flex:0 0 auto}
.dz-prod h3{margin:0;font-size:19px;color:#0f172a}
.dz-prod .sub{display:flex;gap:6px;align-items:center;margin-top:3px;font-size:12px;color:#64748b}
.dz-prod .sub .tp{font-weight:700;color:#0369a1;background:#e0f2fe;border-radius:999px;padding:1px 8px}
.dz-prod .sub .gp{font-weight:700;color:#334155;background:#eef2f5;border-radius:999px;padding:1px 8px}
.dz-sec{font-size:12.5px;font-weight:800;color:#334155;margin:4px 0 8px;display:flex;align-items:center;gap:7px}
.dz-doses{display:grid;gap:8px;margin-bottom:16px}
.dz-dose{display:flex;align-items:center;gap:12px;padding:11px 12px;border:1.5px solid #e3e9ef;border-radius:13px;background:#fbfdfe}
.dz-dose.def{border-color:#99f6e4;background:#f0fdfa}
.dz-star{width:34px;height:34px;flex:0 0 auto;border-radius:10px;border:1px solid #e3e9ef;background:#fff;color:#cbd5e1;cursor:pointer;font-size:15px}
.dz-star:hover{color:#f59e0b;border-color:#fde68a}
.dz-dose.def .dz-star{color:#f59e0b;background:#fffbeb;border-color:#fde68a;cursor:default}
.dz-dose .tx{flex:1;min-width:0}
.dz-dose .tx b{display:block;font-size:14.5px;color:#0f172a}
.dz-dose .tx small{display:block;font-size:12.5px;color:#64748b;margin-top:2px}
.dz-dose .lb{display:inline-block;font-size:11px;font-weight:800;padding:1px 8px;border-radius:999px;background:#ede9fe;color:#5b21b6;margin-inline-end:6px;vertical-align:1px}
.dz-dose .df{font-size:10.5px;font-weight:800;color:#0f766e;margin-inline-start:6px}
.dz-act{display:flex;gap:6px}
.dz-act button{width:34px;height:34px;border-radius:10px;border:1px solid #e3e9ef;background:#fff;color:#64748b;cursor:pointer}
.dz-act button:hover{border-color:#94a3b8;color:#0f172a}
.dz-act button.del:hover{border-color:#fecdd3;color:#e11d48;background:#fff1f2}
.dz-none{padding:14px;border:1.5px dashed #e3e9ef;border-radius:13px;text-align:center;color:#94a3b8;font-size:13px}
.dz-form{border:1.5px solid #e3e9ef;border-radius:14px;padding:14px;background:#fff}
.dz-form.edit{border-color:#93c5fd;background:#f8fbff}
.dz-row{display:grid;grid-template-columns:150px minmax(0,1fr);gap:10px;align-items:start;margin-bottom:12px}
.dz-row>label{font-size:12.5px;font-weight:800;color:#334155;padding-top:9px}
.dz-in{width:100%;height:40px;border:1.5px solid #dbe3ea;border-radius:11px;padding:0 12px;font:14px inherit;background:#fff}
.dz-in:focus{outline:none;border-color:#0f766e;box-shadow:0 0 0 3px rgba(15,118,110,.12)}
.dz-quick{display:flex;gap:5px;flex-wrap:wrap;margin-top:6px}
.dz-q{border:1px dashed #cbd5e1;background:#f8fafc;border-radius:999px;padding:3px 10px;font:12px inherit;font-weight:600;color:#475569;cursor:pointer}
.dz-q:hover{border-style:solid;border-color:#0f766e;color:#0f766e;background:#f0fdfa}
.dz-preview{margin:4px 0 12px;padding:10px 12px;border-radius:11px;background:#f1f5f9;font-size:13px;color:#334155}
.dz-preview b{color:#0f172a}
.dz-foot{display:flex;align-items:center;gap:10px;flex-wrap:wrap}
.dz-foot label{display:flex;align-items:center;gap:7px;font-size:13px;font-weight:700;color:#334155;cursor:pointer}
.dz-foot .sp{flex:1}
.dz-msg{font-size:12.5px;font-weight:700;color:#c62828}
@media (max-width:900px){.dz{grid-template-columns:1fr}.dz-list{position:static;max-height:420px}.dz-row{grid-template-columns:1fr}.dz-row>label{padding-top:0}}
CSS;

require_once __DIR__ . '/../includes/header.php';
?>

<div class="dz">
    <aside class="dz-card dz-list" aria-label="دەرمانەکان">
        <div class="dz-list-head">
            <div class="dz-search">
                <i class="fas fa-magnifying-glass" aria-hidden="true"></i>
                <input type="search" id="dzQ" placeholder="گەڕان بە ناوی دەرمان…" autocomplete="off" aria-label="گەڕانی دەرمان">
            </div>
            <div class="dz-chips" id="dzGroups">
                <button type="button" class="dz-chip on" data-g="0">هەموو</button>
                <?php foreach ($groups as $g): ?>
                <button type="button" class="dz-chip" data-g="<?= (int)$g['id'] ?>"><?= erp2_esc($g['name']) ?></button>
                <?php endforeach; ?>
                <button type="button" class="dz-chip miss" id="dzMissing" title="تەنها ئەوانەی هێشتا دۆزیان بۆ دانەنراوە">بێ دۆز</button>
            </div>
            <div class="dz-count"><span id="dzCount"></span><span id="dzDone"></span></div>
        </div>
        <div class="dz-items" id="dzItems"><div class="dz-none" style="margin:10px">بارکردن…</div></div>
    </aside>

    <section class="dz-card dz-edit" id="dzEdit" aria-live="polite">
        <div class="dz-empty">
            <div>
                <div class="ill"><i class="fas fa-prescription" aria-hidden="true"></i></div>
                <b style="color:#334155;font-size:15px">دەرمانێک هەڵبژێرە</b>
                <div style="margin-top:4px">لە لیستەکە دەرمانێک دیاری بکە بۆ دانانی دۆز و ڕێنمایییەکەی.<br>کاتێک دکتۆر ئەو دەرمانە لە پەڕەی ڕەچەتە دیاری دەکات، خۆکار پڕ دەبێتەوە.</div>
            </div>
        </div>
    </section>
</div>

<?php
$api = APP_URL . '/erp2/api/dosages.php';
$customJS = <<<JS
const DZ_API = '{$api}';
const esc = s => String(s == null ? '' : s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
const doseText = d => [d.dose, d.instructions].filter(Boolean).join(' — ');

// Quick picks for the form: tapping one fills the field (doses, labels) or
// adds to it (durations, instructions), so a usual entry is two or three taps.
const Q_LABEL = ['گەورە', 'منداڵ', 'منداڵی خوار ٦ ساڵ'];
const Q_DOSE  = ['1 دانە، 3 جار لە ڕۆژێکدا', '1 دانە، 2 جار لە ڕۆژێکدا', '1 دانە، ڕۆژی 1 جار',
                 '5 مل، 3 جار لە ڕۆژێکدا', '10 مل، 3 جار لە ڕۆژێکدا', 'بە پێی پێویست', '2 جار لە ڕۆژێکدا بەکاری بهێنە'];
const Q_DAYS  = ['بۆ 3 ڕۆژ', 'بۆ 5 ڕۆژ', 'بۆ 7 ڕۆژ'];
const Q_INSTR = ['دوای خواردن', 'پێش خواردن', 'لەگەڵ خواردن', 'پێش خەوتن', 'لەگەڵ ئاوێکی زۆر',
                 'مەزمەزە بکە و قووتی مەدە', 'دوو خولەک ددان بشۆ', 'ئەگەر ئازار هەبوو'];

let products = [], sel = 0, grp = 0, onlyMissing = false, editId = 0;

async function api(action, data) {
    try {
        if (!data) {
            const r = await fetch(DZ_API + '?action=' + action);
            return await r.json();
        }
        const fd = new FormData();
        fd.append('action', action);
        Object.keys(data).forEach(k => fd.append(k, data[k]));
        const r = await fetch(DZ_API, { method: 'POST', body: fd });
        return await r.json();
    } catch (e) { return { ok: false, error: 'کێشەی پەیوەندی ڕوویدا' }; }
}

/* ---------- the list ---------- */
function renderList() {
    const q = document.getElementById('dzQ').value.trim().toLowerCase();
    const shown = products.filter(p =>
        (!grp || Number(p.category_id) === grp) &&
        (!onlyMissing || !p.doses.length) &&
        (!q || (p.name + ' ' + (p.name_ku || '')).toLowerCase().includes(q)));
    let h = '', last = null;
    shown.forEach(p => {
        const g = p.category_name || 'بێ گرووپ';
        if (g !== last) { h += '<div class="dz-grp">' + esc(g) + '</div>'; last = g; }
        h += '<button type="button" class="dz-it' + (p.id === sel ? ' on' : '') + '" data-id="' + p.id + '">'
           + '<bdi class="nm">' + esc(p.name) + '</bdi>'
           + (p.type_name ? '<span class="tp">' + esc(p.type_name) + '</span>' : '')
           + (p.doses.length ? '<span class="ct" title="دۆزی پاشەکەوتکراو">' + p.doses.length + '</span>' : '<span class="ct no">بێ دۆز</span>')
           + '</button>';
    });
    document.getElementById('dzItems').innerHTML = h || '<div class="dz-none" style="margin:10px">هیچ دەرمانێک نەدۆزرایەوە</div>';
    document.getElementById('dzCount').textContent = shown.length + ' دەرمان';
    const done = products.filter(p => p.doses.length).length;
    document.getElementById('dzDone').textContent = done + ' لە ' + products.length + ' دۆزیان هەیە';
}

/* ---------- the editor ---------- */
function current() { return products.find(p => p.id === sel); }

function renderEdit() {
    const p = current(), box = document.getElementById('dzEdit');
    if (!p) return;
    const doses = p.doses.length
        ? p.doses.map(d =>
            '<div class="dz-dose' + (Number(d.is_default) ? ' def' : '') + '">'
            + '<button type="button" class="dz-star" data-default="' + d.id + '" title="' + (Number(d.is_default) ? 'دۆزی بنەڕەت' : 'بیکە بە بنەڕەت') + '"><i class="fas fa-star"></i></button>'
            + '<div class="tx"><b>' + (d.label ? '<span class="lb">' + esc(d.label) + '</span>' : '') + esc(d.dose)
            + (Number(d.is_default) ? '<span class="df">· بنەڕەت</span>' : '') + '</b>'
            + (d.instructions ? '<small>' + esc(d.instructions) + '</small>' : '') + '</div>'
            + '<div class="dz-act"><button type="button" data-edit="' + d.id + '" title="دەستکاری"><i class="fas fa-pen"></i></button>'
            + '<button type="button" class="del" data-del="' + d.id + '" title="سڕینەوە"><i class="fas fa-trash-can"></i></button></div>'
            + '</div>').join('')
        : '<div class="dz-none">هێشتا هیچ دۆزێک بۆ ئەم دەرمانە دانەنراوە — یەکەمیان دەبێتە بنەڕەت.</div>';
    const chips = (list, field, mode) => '<div class="dz-quick">' + list.map(t =>
        '<button type="button" class="dz-q" data-f="' + field + '" data-m="' + mode + '">' + esc(t) + '</button>').join('') + '</div>';

    box.innerHTML =
        '<div class="dz-prod"><span class="ic"><i class="fas fa-pills"></i></span><div>'
        + '<h3 dir="auto">' + esc(p.name) + '</h3>'
        + '<div class="sub">' + (p.category_name ? '<span class="gp">' + esc(p.category_name) + '</span>' : '')
        + (p.type_name ? '<span class="tp">' + esc(p.type_name) + '</span>' : '')
        + '<span dir="ltr">' + esc(p.product_code) + '</span></div></div></div>'
        + '<div class="dz-sec"><i class="fas fa-list-check"></i> دۆزە پاشەکەوتکراوەکان</div>'
        + '<div class="dz-doses">' + doses + '</div>'
        + '<div class="dz-sec" id="dzFormTitle"><i class="fas fa-plus"></i> دۆزی نوێ</div>'
        + '<form class="dz-form" id="dzForm" onsubmit="return false">'
        +   '<div class="dz-row"><label for="dzLabel">بۆ کێ <span style="color:#94a3b8;font-weight:600">(ئیختیاری)</span></label><div>'
        +     '<input class="dz-in" id="dzLabel" maxlength="60" placeholder="گەورە، منداڵ…">' + chips(Q_LABEL, 'dzLabel', 'set') + '</div></div>'
        +   '<div class="dz-row"><label for="dzDose">دۆز</label><div>'
        +     '<input class="dz-in" id="dzDose" maxlength="160" placeholder="بۆ نموونە: 1 دانە، 3 جار لە ڕۆژێکدا">'
        +     chips(Q_DOSE, 'dzDose', 'set') + chips(Q_DAYS, 'dzDose', 'add') + '</div></div>'
        +   '<div class="dz-row"><label for="dzInstr">ڕێنمایی</label><div>'
        +     '<input class="dz-in" id="dzInstr" maxlength="255" placeholder="بۆ نموونە: دوای خواردن">' + chips(Q_INSTR, 'dzInstr', 'add') + '</div></div>'
        +   '<div class="dz-preview" id="dzPreview"></div>'
        +   '<div class="dz-foot">'
        +     '<label><input type="checkbox" id="dzDefault"> بیکە بە دۆزی بنەڕەت</label><span class="sp"></span>'
        +     '<span class="dz-msg" id="dzMsg"></span>'
        +     '<button type="button" class="btn btn-secondary" id="dzCancel" hidden>پاشگەزبوونەوە</button>'
        +     '<button type="button" class="btn btn-primary" id="dzSave"><i class="fas fa-save"></i> پاشەکەوتکردن</button>'
        +   '</div>'
        + '</form>';
    resetForm();
}

function resetForm() {
    editId = 0;
    const p = current();
    ['dzLabel', 'dzDose', 'dzInstr'].forEach(id => { document.getElementById(id).value = ''; });
    document.getElementById('dzDefault').checked = !p || !p.doses.length;
    document.getElementById('dzForm').classList.remove('edit');
    document.getElementById('dzFormTitle').innerHTML = '<i class="fas fa-plus"></i> دۆزی نوێ';
    document.getElementById('dzCancel').hidden = true;
    document.getElementById('dzMsg').textContent = '';
    preview();
}

function preview() {
    const d = { dose: document.getElementById('dzDose').value.trim(), instructions: document.getElementById('dzInstr').value.trim() };
    const lb = document.getElementById('dzLabel').value.trim();
    document.getElementById('dzPreview').innerHTML = d.dose
        ? 'لای دکتۆر وا پڕ دەبێتەوە: <b>' + esc(doseText(d)) + '</b>' + (lb ? ' <span style="color:#7c3aed">(' + esc(lb) + ')</span>' : '')
        : '<span style="color:#94a3b8">دۆز بنووسە یان لە پێشنیارەکان هەڵبژێرە…</span>';
}

function startEdit(id) {
    const d = current().doses.find(x => Number(x.id) === id);
    if (!d) return;
    editId = id;
    document.getElementById('dzLabel').value = d.label || '';
    document.getElementById('dzDose').value = d.dose || '';
    document.getElementById('dzInstr').value = d.instructions || '';
    document.getElementById('dzDefault').checked = Number(d.is_default) === 1;
    document.getElementById('dzForm').classList.add('edit');
    document.getElementById('dzFormTitle').innerHTML = '<i class="fas fa-pen"></i> دەستکاریکردنی دۆز';
    document.getElementById('dzCancel').hidden = false;
    preview();
    document.getElementById('dzDose').focus();
}

function setDoses(doses) {
    const p = current();
    if (p) p.doses = doses || [];
    renderList();
    renderEdit();
}

async function save() {
    const p = current();
    const dose = document.getElementById('dzDose').value.trim();
    if (!dose) { document.getElementById('dzMsg').textContent = 'دۆز پێویستە'; document.getElementById('dzDose').focus(); return; }
    const btn = document.getElementById('dzSave');
    btn.disabled = true;
    const d = await api('save', {
        id: editId, product_id: p.id, dose,
        label: document.getElementById('dzLabel').value.trim(),
        instructions: document.getElementById('dzInstr').value.trim(),
        is_default: document.getElementById('dzDefault').checked ? 1 : 0
    });
    btn.disabled = false;
    if (!d || !d.ok) { document.getElementById('dzMsg').textContent = (d && (d.error || d.message)) || 'هەڵە'; return; }
    setDoses(d.doses);
    showToast('success', 'دۆز پاشەکەوتکرا');
    document.getElementById('dzDose').focus();
}

/* ---------- wiring ---------- */
document.getElementById('dzItems').addEventListener('click', e => {
    const b = e.target.closest('.dz-it');
    if (!b) return;
    sel = Number(b.dataset.id);
    renderList();
    renderEdit();
    history.replaceState(null, '', '?product=' + sel);
    document.getElementById('dzDose').focus();
});
document.getElementById('dzGroups').addEventListener('click', e => {
    const b = e.target.closest('.dz-chip');
    if (!b) return;
    if (b.id === 'dzMissing') { onlyMissing = !onlyMissing; b.classList.toggle('on', onlyMissing); }
    else {
        grp = Number(b.dataset.g);
        document.querySelectorAll('#dzGroups .dz-chip:not(.miss)').forEach(x => x.classList.toggle('on', x === b));
    }
    renderList();
});
document.getElementById('dzQ').addEventListener('input', renderList);

document.getElementById('dzEdit').addEventListener('click', async e => {
    const q = e.target.closest('.dz-q');
    if (q) {
        const el = document.getElementById(q.dataset.f), v = el.value.trim(), t = q.textContent;
        if (q.dataset.m === 'set' || !v) el.value = t;
        else if (!v.includes(t)) el.value = v + (q.dataset.f === 'dzDose' ? ' — ' : '، ') + t;
        preview();
        el.focus();
        return;
    }
    if (e.target.closest('#dzSave')) { save(); return; }
    if (e.target.closest('#dzCancel')) { resetForm(); return; }
    const ed = e.target.closest('[data-edit]');
    if (ed) { startEdit(Number(ed.dataset.edit)); return; }
    const st = e.target.closest('[data-default]');
    if (st && !st.closest('.dz-dose').classList.contains('def')) {
        const d = await api('default', { id: st.dataset.default });
        if (d && d.ok) { setDoses(d.doses); showToast('success', 'کرا بە دۆزی بنەڕەت'); }
        return;
    }
    const del = e.target.closest('[data-del]');
    if (del) {
        const d0 = current().doses.find(x => String(x.id) === del.dataset.del);
        if (!confirm('ئەم دۆزە بسڕدرێتەوە؟\\n' + doseText(d0 || {}))) return;
        const d = await api('delete', { id: del.dataset.del });
        if (d && d.ok) { setDoses(d.doses); showToast('success', 'سڕایەوە'); }
        else showToast('error', (d && d.error) || 'هەڵە');
    }
});
document.getElementById('dzEdit').addEventListener('input', e => {
    if (['dzLabel', 'dzDose', 'dzInstr'].includes(e.target.id)) preview();
});
document.getElementById('dzEdit').addEventListener('keydown', e => {
    if (e.key === 'Enter' && e.target.classList.contains('dz-in')) { e.preventDefault(); save(); }
    if (e.key === 'Escape' && editId) resetForm();
});

(async function boot() {
    const d = await api('list');
    if (!d || !d.ok) { document.getElementById('dzItems').innerHTML = '<div class="dz-none" style="margin:10px">' + esc((d && d.error) || 'نەتوانرا بارببرێت') + '</div>'; return; }
    products = (d.products || []).map(p => Object.assign(p, { id: Number(p.id) }));
    const want = Number(new URLSearchParams(location.search).get('product') || 0);
    if (want && products.some(p => p.id === want)) { sel = want; }
    renderList();
    if (sel) { renderEdit(); const on = document.querySelector('.dz-it.on'); if (on) on.scrollIntoView({ block: 'center' }); }
})();
JS;

require_once __DIR__ . '/../includes/footer.php';
