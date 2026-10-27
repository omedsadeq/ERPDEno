<?php
/**
 * Shared by the employee profile (hr/employee.php) and the employee form
 * (hr/employee-edit.php): the tabs, the photo avatar, and the «بەڵگەنامەکان»
 * section with its upload form.
 *
 * Expects hr/_bootstrap.php to be loaded.
 */

require_once __DIR__ . '/../classes/HrDocument.php';

/** URL of a stored document (inline). */
function hr_doc_url(int $docId, bool $download = false): string
{
    return APP_URL . '/hr/employee-doc.php?id=' . $docId . ($download ? '&dl=1' : '');
}

/** The round picture: the newest «وێنەی کەسی», else the initials. */
function hr_emp_avatar(array $e, ?int $photoId, string $class = ''): string
{
    if ($photoId) {
        return '<img class="hr-avatar ' . $class . '" src="' . hr_esc(hr_doc_url($photoId)) . '" alt="" loading="lazy">';
    }
    $words = preg_split('/\s+/u', trim((string)$e['full_name']));
    $initials = mb_substr($words[0] ?? '', 0, 1) . (count($words) > 1 ? mb_substr(end($words), 0, 1) : '');
    return '<span class="hr-avatar is-initials ' . $class . ' ' . ($e['employee_type'] === 'doctor' ? 'doctor' : 'staff') . '">'
        . hr_esc($initials) . '</span>';
}

/** «٢ ساڵ و ٣ مانگ» from the hire date to the end date (or today). */
function hr_emp_tenure(?string $hire, ?string $end = null): string
{
    if (!$hire) {
        return '';
    }
    $from = new DateTime($hire);
    $to = new DateTime($end ?: 'today');
    if ($to < $from) {
        return '';
    }
    $d = $from->diff($to);
    $parts = [];
    if ($d->y) {
        $parts[] = $d->y . ' ساڵ';
    }
    if ($d->m) {
        $parts[] = $d->m . ' مانگ';
    }
    if (!$parts) {
        $parts[] = $d->d . ' ڕۆژ';
    }
    return implode(' و ', $parts);
}

/** «بەسەرچووە» / «نزیکە» badge for a document's expiry date. */
function hr_doc_expiry_badge(array $doc): string
{
    if (empty($doc['expiry_date'])) {
        return '';
    }
    $state = HrDocument::expiryState($doc);
    $label = $state === 'expired' ? 'بەسەرچووە' : ($state === 'soon' ? 'بەم زووانە بەسەردەچێت' : 'بەسەردەچێت');
    return '<span class="hr-doc-exp ' . ($state ?: 'ok') . '"><i class="fas fa-calendar-xmark"></i> '
        . $label . ' <bdi>' . hr_esc($doc['expiry_date']) . '</bdi></span>';
}

/**
 * The documents section: what is missing, the upload form (with
 * hr.documents.manage) and every file as a card. $from is 'edit' or 'view' —
 * where to come back to after an upload or delete.
 */
function hr_emp_docs_section(array $emp, array $docs, bool $canManage, string $from): void
{
    $have = array_count_values(array_column($docs, 'doc_type'));
    $expected = ['photo', 'national_id', 'info_card', 'health_card', 'contract'];
    $missing = array_values(array_filter($expected, function ($t) use ($have) { return empty($have[$t]); }));
    $limit = HrDocument::uploadLimit();
    ?>
    <?php if ($missing): ?>
    <div class="hr-doc-missing">
        <i class="fas fa-circle-info"></i> هێشتا بار نەکراون:
        <?php foreach ($missing as $t): ?>
            <span><i class="fas <?= HrDocument::typeIcon($t) ?>"></i> <?= hr_esc(HrDocument::typeLabel($t)) ?></span>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php if ($canManage): ?>
    <form class="hr-doc-upload" id="hrDocUpload" method="POST" action="<?= APP_URL ?>/hr/employee-doc.php"
          enctype="multipart/form-data" data-limit="<?= $limit ?>" data-total="<?= HrDocument::requestLimit() ?>">
        <?= Csrf::field() ?>
        <input type="hidden" name="action" value="upload">
        <input type="hidden" name="employee_id" value="<?= (int)$emp['id'] ?>">
        <input type="hidden" name="from" value="<?= hr_esc($from) ?>">
        <div class="hr-doc-upload-h"><i class="fas fa-cloud-arrow-up"></i> بارکردنی بەڵگەنامە</div>
        <div class="hr-doc-upload-grid">
            <div class="form-group">
                <label class="form-label required">جۆر</label>
                <select name="doc_type" class="form-control" required>
                    <?php foreach (HrDocument::TYPES as $key => [$label]): ?>
                    <option value="<?= $key ?>" <?= $missing && $missing[0] === $key ? 'selected' : '' ?>><?= hr_esc($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label class="form-label">ناونیشان / تێبینی</label>
                <input type="text" name="title" class="form-control" maxlength="150" placeholder="نموونە: ڕووی پێشەوە">
            </div>
            <div class="form-group">
                <label class="form-label">ڕێکەوتی بەسەرچوون</label>
                <input type="date" name="expiry_date" class="form-control">
            </div>
        </div>
        <label class="hr-doc-drop" id="hrDocDrop">
            <input type="file" name="files[]" id="hrDocFiles" accept="image/jpeg,image/png,image/webp,application/pdf" multiple required>
            <i class="fas fa-file-arrow-up"></i>
            <span id="hrDocDropText">فایل هەڵبژێرە یان ڕایبکێشە ئێرە — وێنە یان PDF، یەک یان چەند فایل</span>
        </label>
        <div class="hr-doc-upload-foot">
            <span class="form-hint">وێنەکان پێش بارکردن بچووک دەکرێنەوە. PDF تا <?= HrDocument::formatSize($limit) ?>.</span>
            <button type="submit" class="btn btn-primary" id="hrDocSubmit"><i class="fas fa-upload"></i> بارکردن</button>
        </div>
    </form>
    <?php endif; ?>

    <?php if (!$docs): ?>
        <div class="hr-doc-empty"><i class="fas fa-folder-open"></i><br>هیچ بەڵگەنامەیەک بار نەکراوە</div>
    <?php else: ?>
    <div class="hr-doc-grid">
        <?php foreach ($docs as $d):
            $url = hr_doc_url((int)$d['id']);
            $state = HrDocument::expiryState($d);
        ?>
        <div class="hr-doc-card <?= $state ? 'is-' . $state : '' ?>">
            <a class="hr-doc-thumb" href="<?= hr_esc($url) ?>" target="_blank" rel="noopener" title="کردنەوە">
                <?php if (HrDocument::isImage($d)): ?>
                    <img src="<?= hr_esc($url) ?>" alt="" loading="lazy">
                <?php else: ?>
                    <span class="hr-doc-pdf"><i class="fas fa-file-pdf"></i><b>PDF</b></span>
                <?php endif; ?>
            </a>
            <div class="hr-doc-body">
                <div class="hr-doc-type"><i class="fas <?= HrDocument::typeIcon($d['doc_type']) ?>"></i> <?= hr_esc(HrDocument::typeLabel($d['doc_type'])) ?></div>
                <?php if ($d['title']): ?><div class="hr-doc-title"><?= hr_esc($d['title']) ?></div><?php endif; ?>
                <?= hr_doc_expiry_badge($d) ?>
                <div class="hr-doc-meta">
                    <bdi><?= hr_esc(substr((string)$d['created_at'], 0, 10)) ?></bdi> · <bdi><?= HrDocument::formatSize((int)$d['file_size']) ?></bdi>
                    <?php if ($d['uploaded_by_name']): ?> · <bdi><?= hr_esc($d['uploaded_by_name']) ?></bdi><?php endif; ?>
                </div>
            </div>
            <div class="hr-doc-actions">
                <a href="<?= hr_esc($url) ?>" target="_blank" rel="noopener" title="کردنەوە"><i class="fas fa-up-right-from-square"></i></a>
                <a href="<?= hr_esc(hr_doc_url((int)$d['id'], true)) ?>" title="داگرتن"><i class="fas fa-download"></i></a>
                <?php if ($canManage): ?>
                <form method="POST" action="<?= APP_URL ?>/hr/employee-doc.php"
                      onsubmit="return confirm('ئەم بەڵگەنامەیە بسڕدرێتەوە؟ ناگەڕێتەوە.')">
                    <?= Csrf::field() ?>
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="doc_id" value="<?= (int)$d['id'] ?>">
                    <input type="hidden" name="from" value="<?= hr_esc($from) ?>">
                    <button type="submit" class="is-danger" title="سڕینەوە"><i class="fas fa-trash"></i></button>
                </form>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
    <?php
}

/** Styles for the profile, the form and the documents. */
$hrEmpCss = <<<CSS
.hr-avatar{width:36px;height:36px;border-radius:50%;object-fit:cover;flex-shrink:0;background:#e2e8f0;display:inline-block;vertical-align:middle}
.hr-avatar.is-initials{display:inline-grid;place-items:center;font-weight:800;font-size:13px;color:#0369a1;background:#e0f2fe}
.hr-avatar.is-initials.doctor{color:#6d28d9;background:#ede9fe}
.hr-avatar.lg{width:96px;height:96px;font-size:32px;border:3px solid #fff;box-shadow:0 2px 10px rgba(15,23,42,.15)}

.hr-ehead{display:flex;gap:18px;align-items:center;flex-wrap:wrap;background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:18px 20px;margin-bottom:14px;border-top:4px solid #0f766e}
.hr-ehead-main{flex:1;min-width:220px}
.hr-ehead h2{margin:0 0 4px;font-size:21px;color:#0f172a;display:flex;gap:8px;align-items:center;flex-wrap:wrap}
.hr-ehead .pos{color:#475569;font-size:13.5px;margin-bottom:8px}
.hr-ehead .facts{display:flex;flex-wrap:wrap;gap:6px 16px;font-size:12.5px;color:#64748b}
.hr-ehead .facts i{color:#94a3b8;margin-inline-end:4px}
.hr-ehead .facts b{color:#334155;font-weight:700}
.hr-ehead-actions{display:flex;gap:8px;flex-wrap:wrap}

.hr-tabs{display:flex;gap:4px;border-bottom:2px solid #e5e7eb;margin-bottom:16px;overflow-x:auto;scrollbar-width:thin}
.hr-tab{appearance:none;border:0;background:none;padding:10px 16px;font:inherit;font-size:13.5px;font-weight:700;color:#64748b;cursor:pointer;border-bottom:3px solid transparent;margin-bottom:-2px;white-space:nowrap;display:inline-flex;align-items:center;gap:7px;border-radius:8px 8px 0 0}
.hr-tab:hover{color:#0f766e;background:#f0fdfa}
.hr-tab.is-on{color:#0f766e;border-bottom-color:#0f766e}
.hr-tab[disabled]{opacity:.45;cursor:not-allowed;background:none}
.hr-tab .cnt{background:#e2e8f0;color:#475569;border-radius:9px;padding:0 7px;font-size:11px;line-height:18px}
.hr-tab .cnt.warn{background:#fee2e2;color:#b91c1c}
.hr-tabpanel{display:none}
.hr-tabpanel.is-on{display:block}

.hr-sheet{background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:18px 20px;margin-bottom:14px}
.hr-sheet-h{font-size:13.5px;font-weight:800;color:#0f766e;margin:0 0 14px;display:flex;align-items:center;gap:8px}
.hr-sheet .hr-formgrid{grid-template-columns:repeat(auto-fit,minmax(230px,1fr));gap:12px 18px}
.hr-sheet .form-group{margin:0}
.hr-span{grid-column:1/-1}

.hr-dl{display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:0 28px}
.hr-dl > div{display:flex;justify-content:space-between;gap:12px;padding:9px 0;border-bottom:1px dashed #e5e7eb;font-size:13.5px}
.hr-dl dt{color:#64748b;margin:0}
.hr-dl dd{margin:0;color:#0f172a;font-weight:700;text-align:left}
.hr-dl dd.ltr{direction:ltr;font-variant-numeric:tabular-nums}
.hr-dl dd.empty{color:#cbd5e1;font-weight:400}
.hr-note{white-space:pre-wrap;font-size:13.5px;line-height:1.8;color:#334155}

.hr-savebar{position:sticky;bottom:0;z-index:5;display:flex;gap:8px;align-items:center;padding:12px 0;background:linear-gradient(to top,var(--bg-solid-base,#f3f3f3) 75%,transparent)}
.hr-savebar .spacer{flex:1}

.hr-doc-missing{display:flex;flex-wrap:wrap;gap:6px;align-items:center;font-size:12.5px;color:#92400e;background:#fffbeb;border:1px solid #fde68a;border-radius:10px;padding:9px 12px;margin-bottom:12px}
.hr-doc-missing span{background:#fff;border:1px solid #fde68a;border-radius:7px;padding:2px 8px}
.hr-doc-upload{background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:14px 16px;margin-bottom:14px}
.hr-doc-upload-h{font-weight:800;color:#0f766e;font-size:13.5px;margin-bottom:10px}
.hr-doc-upload-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:10px 14px;margin-bottom:10px}
.hr-doc-upload-grid .form-group{margin:0}
.hr-doc-drop{position:relative;display:flex;flex-direction:column;align-items:center;gap:6px;padding:18px;border:2px dashed #99f6e4;border-radius:10px;background:#f0fdfa;color:#0f766e;cursor:pointer;text-align:center;font-size:13px}
.hr-doc-drop i{font-size:24px}
.hr-doc-drop.is-over,.hr-doc-drop:hover{background:#ccfbf1;border-color:#14b8a6}
.hr-doc-drop input{position:absolute;inset:0;opacity:0;cursor:pointer}
.hr-doc-upload-foot{display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap;margin-top:10px}
.hr-doc-empty{text-align:center;color:#94a3b8;padding:40px 12px;background:#fff;border:1px dashed #e2e8f0;border-radius:12px;font-size:13.5px}
.hr-doc-empty i{font-size:30px;margin-bottom:8px}
.hr-doc-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(210px,1fr));gap:12px}
.hr-doc-card{background:#fff;border:1px solid #e5e7eb;border-radius:12px;overflow:hidden;display:flex;flex-direction:column}
.hr-doc-card.is-expired{border-color:#fca5a5}
.hr-doc-card.is-soon{border-color:#fcd34d}
.hr-doc-thumb{display:block;height:140px;background:#f1f5f9;border-bottom:1px solid #e5e7eb}
.hr-doc-thumb img{width:100%;height:100%;object-fit:cover;display:block}
.hr-doc-pdf{height:100%;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:4px;color:#dc2626;font-size:38px}
.hr-doc-pdf b{font-size:12px;color:#991b1b}
.hr-doc-body{padding:10px 12px;flex:1;display:flex;flex-direction:column;gap:5px}
.hr-doc-type{font-weight:800;font-size:13.5px;color:#0f172a}
.hr-doc-type i{color:#0f766e;margin-inline-end:4px}
.hr-doc-title{font-size:12.5px;color:#475569}
.hr-doc-meta{font-size:11.5px;color:#94a3b8;margin-top:auto}
.hr-doc-exp{align-self:flex-start;font-size:11.5px;font-weight:700;border-radius:7px;padding:2px 8px;background:#f1f5f9;color:#475569}
.hr-doc-exp.expired{background:#fee2e2;color:#b91c1c}
.hr-doc-exp.soon{background:#fef3c7;color:#b45309}
.hr-doc-actions{display:flex;border-top:1px solid #f1f5f9}
.hr-doc-actions > a,.hr-doc-actions button{flex:1;display:flex;align-items:center;justify-content:center;padding:8px;color:#475569;background:none;border:0;cursor:pointer;font-size:13px;text-decoration:none}
.hr-doc-actions form{flex:1;display:flex;margin:0}
.hr-doc-actions > *+*{border-inline-start:1px solid #f1f5f9}
.hr-doc-actions a:hover,.hr-doc-actions button:hover{background:#f8fafc;color:#0f766e}
.hr-doc-actions .is-danger:hover{color:#dc2626;background:#fef2f2}
@media (max-width:640px){.hr-ehead{padding:14px}.hr-avatar.lg{width:72px;height:72px;font-size:24px}.hr-tab{padding:9px 11px}}
CSS;

/**
 * Tabs (remembered in the URL hash, so a reload or a redirect to #docs opens
 * the right one) and the document upload: images are shrunk in the browser —
 * 800 px for a personal photo, 2000 px for a card or a page — so a phone photo
 * fits under the server's upload limit.
 */
$hrEmpJs = <<<'JS'
(function () {
    const tabs = Array.from(document.querySelectorAll('.hr-tab[data-tab]'));
    if (tabs.length) {
        window.hrShowTab = function (name, push) {
            const tab = tabs.find(t => t.dataset.tab === name && !t.disabled) || tabs[0];
            tabs.forEach(t => t.classList.toggle('is-on', t === tab));
            document.querySelectorAll('.hr-tabpanel').forEach(p => p.classList.toggle('is-on', p.id === 'tab-' + tab.dataset.tab));
            document.querySelectorAll('[data-for-tabs]').forEach(el => {
                el.hidden = !el.dataset.forTabs.split(' ').includes(tab.dataset.tab);
            });
            const field = document.getElementById('hrActiveTab');
            if (field) field.value = tab.dataset.tab;
            if (push) history.replaceState(null, '', '#' + tab.dataset.tab);
        };
        tabs.forEach(t => t.addEventListener('click', () => hrShowTab(t.dataset.tab, true)));
        hrShowTab((location.hash || '').slice(1) || (document.getElementById('hrActiveTab') || {}).value || tabs[0].dataset.tab, false);
        // A required field on a hidden tab: open that tab so the browser can point at it.
        document.addEventListener('invalid', function (ev) {
            const panel = ev.target.closest('.hr-tabpanel');
            if (panel && !panel.classList.contains('is-on')) hrShowTab(panel.id.replace('tab-', ''), true);
        }, true);
    }

    const form = document.getElementById('hrDocUpload');
    if (!form) return;
    const input = document.getElementById('hrDocFiles');
    const drop = document.getElementById('hrDocDrop');
    const text = document.getElementById('hrDocDropText');
    const btn = document.getElementById('hrDocSubmit');
    const limit = Number(form.dataset.limit) || 2097152;
    const totalLimit = Number(form.dataset.total) || limit;
    const idle = text.textContent;

    function showPicked() {
        const n = input.files.length;
        text.textContent = n === 0 ? idle : (n === 1 ? input.files[0].name : n + ' فایل هەڵبژێردران');
    }
    input.addEventListener('change', showPicked);
    ['dragenter', 'dragover'].forEach(e => drop.addEventListener(e, ev => { ev.preventDefault(); drop.classList.add('is-over'); }));
    ['dragleave', 'drop'].forEach(e => drop.addEventListener(e, () => drop.classList.remove('is-over')));
    drop.addEventListener('drop', ev => {
        ev.preventDefault();
        if (ev.dataTransfer && ev.dataTransfer.files.length) { input.files = ev.dataTransfer.files; showPicked(); }
    });

    function shrink(file, maxSide) {
        return new Promise(resolve => {
            if (!/^image\/(jpeg|png|webp)$/.test(file.type)) return resolve(file);
            const img = new Image();
            const url = URL.createObjectURL(file);
            img.onload = function () {
                URL.revokeObjectURL(url);
                const scale = Math.min(1, maxSide / Math.max(img.naturalWidth, img.naturalHeight));
                if (scale === 1 && file.size <= 1200000) return resolve(file);
                const c = document.createElement('canvas');
                c.width = Math.round(img.naturalWidth * scale);
                c.height = Math.round(img.naturalHeight * scale);
                const g = c.getContext('2d');
                g.fillStyle = '#fff';
                g.fillRect(0, 0, c.width, c.height);
                g.drawImage(img, 0, 0, c.width, c.height);
                c.toBlob(b => resolve(b && b.size < file.size
                    ? new File([b], file.name.replace(/\.\w+$/, '') + '.jpg', {type: 'image/jpeg'})
                    : file), 'image/jpeg', 0.86);
            };
            img.onerror = function () { URL.revokeObjectURL(url); resolve(file); };
            img.src = url;
        });
    }

    form.addEventListener('submit', async function (ev) {
        ev.preventDefault();
        if (!input.files.length) { alert('فایلێک هەڵبژێرە'); return; }
        btn.disabled = true;
        const busy = btn.innerHTML;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> ئامادە دەکرێت…';
        try {
            const maxSide = form.elements['doc_type'].value === 'photo' ? 800 : 2000;
            const files = await Promise.all(Array.from(input.files).map(f => shrink(f, maxSide)));
            const mb = n => Math.round(n / 1048576 * 10) / 10 + ' MB';
            const big = files.find(f => f.size > limit);
            if (big) throw new Error('«' + big.name + '» زۆر گەورەیە. زۆرترین بۆ هەر فایلێک: ' + mb(limit));
            const total = files.reduce((s, f) => s + f.size, 0);
            if (total > totalLimit) throw new Error('کۆی فایلەکان زۆرە (' + mb(total) + '). بە چەند جار باریان بکە، هەر جارێک تا ' + mb(totalLimit) + '.');
            const fd = new FormData(form);
            fd.delete('files[]');
            files.forEach(f => fd.append('files[]', f, f.name));
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> بار دەکرێت…';
            // getAttribute: the hidden <input name="action"> shadows form.action.
            const r = await fetch(form.getAttribute('action'), {method: 'POST', body: fd, headers: {'X-Requested-With': 'XMLHttpRequest'}});
            const res = await r.json().catch(() => ({success: false, message: 'هەڵە لە وەڵامی سێرڤەر (' + r.status + ')'}));
            if (!res.success) throw new Error(res.message || 'بارکردن سەرکەوتوو نەبوو');
            location.hash = 'docs';
            location.reload();
        } catch (e) {
            alert(e.message);
            btn.disabled = false;
            btn.innerHTML = busy;
        }
    });
})();
JS;
