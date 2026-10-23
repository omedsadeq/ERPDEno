<?php
/**
 * Case-name picker for the "new case" dialogs
 * (patients/view.php and folders/view.php).
 *
 * render_case_name_field()   — the <select>, placed INSIDE the create_case form
 * render_case_name_manager() — the manage dialog + script, placed OUTSIDE the
 *                              form so its inputs never submit it
 *
 * Admins and doctors get the "+" button to add/rename names; deleting is
 * admin-only. api/case-names.php re-checks both rules server-side.
 *
 * Every name carries a scope (tooth / arch): choosing a name pre-checks the
 * matching scope_type radio in the same form. The radio can still be changed
 * by hand afterwards.
 */

require_once __DIR__ . '/../classes/CaseNamePreset.php';

function render_case_name_field($canManage) {
    $names = (new CaseNamePreset())->all();
    ?>
    <div class="form-group">
        <label class="form-label required">ناوی کەیس</label>
        <div style="display: flex; gap: 8px; align-items: stretch;">
            <select name="case_name" class="form-control js-case-name-select" required style="flex: 1;">
                <option value="">— ناوی کەیس هەڵبژێرە —</option>
                <?php foreach ($names as $n): ?>
                <option value="<?= htmlspecialchars($n['name']) ?>" data-scope="<?= CaseNamePreset::scope($n['scope_type'] ?? '') ?>"><?= htmlspecialchars($n['name']) ?></option>
                <?php endforeach; ?>
            </select>
            <?php if ($canManage): ?>
            <button type="button" class="btn btn-primary" onclick="openCaseNameManager()"
                    title="زیادکردن / دەستکاری ناوی کەیسەکان" style="padding: 0 14px;">
                <i class="fas fa-plus"></i>
            </button>
            <?php endif; ?>
        </div>
        <?php if (!$names): ?>
        <small style="color: #f44336; display: block; margin-top: 6px;">
            هیچ ناوێکی کەیس زیاد نەکراوە<?= $canManage ? ' — دوگمەی + بەکاربهێنە' : '، تکایە پەیوەندی بە ئەدمینەوە بکە' ?>
        </small>
        <?php endif; ?>
    </div>
    <script>
    (function () {
        if (window.applyCaseNameScope) return;

        // Check the scope_type radio that matches the chosen name's scope.
        window.applyCaseNameScope = function (select) {
            const opt = select.options[select.selectedIndex];
            const scope = opt && opt.dataset.scope;
            const form = select.form;
            if (!scope || !form) return;
            const radio = form.querySelector('input[name="scope_type"][value="' + scope + '"]');
            if (!radio) return;
            // folders/view.php styles its radios through a click handler on the
            // wrapping .scope-option label, so go through that when present.
            const styled = radio.closest('.scope-option');
            if (styled) {
                styled.click();
            } else {
                radio.checked = true;
                radio.dispatchEvent(new Event('change', { bubbles: true }));
            }
        };

        document.addEventListener('change', function (e) {
            if (e.target.matches && e.target.matches('.js-case-name-select')) {
                window.applyCaseNameScope(e.target);
            }
        });
    })();
    </script>
    <?php
}

function render_case_name_manager($canManage, $canDelete) {
    if (!$canManage) {
        return;
    }
    ?>
<style>
.cnm-list { max-height: 320px; overflow-y: auto; border: 1px solid #e0e0e0; border-radius: 8px; margin-top: 12px; }
.cnm-row { display: flex; align-items: center; gap: 6px; padding: 8px 10px; border-bottom: 1px solid #f0f0f0; }
.cnm-row:last-child { border-bottom: none; }
.cnm-row .cnm-name { flex: 1; }
.cnm-row input[type="text"] { flex: 1; }
.cnm-icon-btn { border: none; background: transparent; cursor: pointer; padding: 6px 8px; border-radius: 6px; color: #607d8b; }
.cnm-icon-btn:hover { background: #f0f4f8; }
.cnm-icon-btn.danger { color: #f44336; }
.cnm-empty { padding: 16px; text-align: center; color: #9e9e9e; }
.cnm-badge { display: inline-flex; align-items: center; gap: 5px; font-size: 12px; padding: 3px 9px; border-radius: 12px; white-space: nowrap; }
.cnm-badge.tooth { background: #e8f5e9; color: #2e7d32; }
.cnm-badge.arch { background: #f3e5f5; color: #7b1fa2; }
.cnm-scope { display: inline-flex; border: 1.5px solid #e0e0e0; border-radius: 8px; overflow: hidden; flex-shrink: 0; }
.cnm-scope label { display: flex; align-items: center; gap: 5px; padding: 0 10px; cursor: pointer; font-size: 13px; margin: 0; white-space: nowrap; min-height: 36px; }
.cnm-scope input { display: none; }
.cnm-scope label + label { border-right: 1.5px solid #e0e0e0; }
.cnm-scope label.tooth.on { background: #e8f5e9; color: #2e7d32; }
.cnm-scope label.arch.on { background: #f3e5f5; color: #7b1fa2; }
</style>
<div class="dialog-overlay" id="caseNameManagerDialog">
    <div class="dialog" style="max-width: 560px;">
        <div class="dialog-header">
            <h4 class="dialog-title">ناوی کەیسەکان</h4>
            <button type="button" class="dialog-close" onclick="closeDialog('caseNameManagerDialog')">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <div class="dialog-body">
            <div style="display: flex; gap: 8px;">
                <input type="text" id="cnmNewName" class="form-control" maxlength="255" style="flex: 1;"
                       placeholder="ناوی نوێ، بۆ نموونە: پڕکردنەوەی ددان"
                       onkeydown="if (event.key === 'Enter') { event.preventDefault(); cnmAdd(); }">
                <div class="cnm-scope" id="cnmNewScope"></div>
                <button type="button" class="btn btn-primary" onclick="cnmAdd()" style="white-space: nowrap;">
                    <i class="fas fa-plus"></i> زیادکردن
                </button>
            </div>
            <div id="cnmError" style="color: #f44336; font-size: 13px; margin-top: 6px; display: none;"></div>
            <div class="cnm-list" id="cnmList"></div>
        </div>
        <div class="dialog-footer">
            <button type="button" class="btn btn-secondary" onclick="closeDialog('caseNameManagerDialog')">داخستن</button>
        </div>
    </div>
</div>
<script>
(function () {
    const API = '<?= APP_URL ?>/api/case-names.php';
    const CAN_DELETE = <?= $canDelete ? 'true' : 'false' ?>;
    const SCOPES = {
        tooth: { label: 'ددان', icon: 'fa-tooth' },
        arch:  { label: 'ئەرک (جاو)', icon: 'fa-teeth' }
    };
    let names = [];
    let scopeGroupSeq = 0;

    function showError(msg) {
        const el = document.getElementById('cnmError');
        el.textContent = msg || '';
        el.style.display = msg ? 'block' : 'none';
    }

    function call(payload) {
        return fetch(API, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
            body: JSON.stringify(payload)
        }).then(r => r.json()).then(data => {
            if (!data.success) throw new Error(data.message || 'هەڵەیەک ڕوویدا');
            return data;
        });
    }

    function load() {
        return fetch(API + '?action=list', { headers: { 'Accept': 'application/json' } })
            .then(r => r.json())
            .then(data => {
                if (!data.success) throw new Error(data.message || 'هەڵەیەک ڕوویدا');
                names = data.names || [];
                renderList();
                syncSelects();
            })
            .catch(e => showError(e.message));
    }

    // Fill a .cnm-scope box with a tooth/arch toggle; returns a getter.
    function buildScopeToggle(box, value) {
        const group = 'cnm_scope_' + (++scopeGroupSeq);
        box.innerHTML = '';
        Object.keys(SCOPES).forEach(key => {
            const label = document.createElement('label');
            label.className = key;
            const radio = document.createElement('input');
            radio.type = 'radio';
            radio.name = group;
            radio.value = key;
            radio.checked = (value || 'tooth') === key;
            const icon = document.createElement('i');
            icon.className = 'fas ' + SCOPES[key].icon;
            label.append(radio, icon, document.createTextNode(SCOPES[key].label));
            box.appendChild(label);
        });
        const paint = () => box.querySelectorAll('label').forEach(l => {
            l.classList.toggle('on', l.querySelector('input').checked);
        });
        box.onchange = paint;
        paint();
        return () => (box.querySelector('input:checked') || {}).value || 'tooth';
    }

    let getNewScope = () => 'tooth';

    // Rebuild every case-name <select> on the page, keeping the current choice
    // when it still exists (or following it through a rename).
    function syncSelects(renamedFrom, renamedTo) {
        document.querySelectorAll('.js-case-name-select').forEach(select => {
            let current = select.value;
            if (renamedFrom !== undefined && current === renamedFrom) current = renamedTo;
            select.innerHTML = '';
            const placeholder = document.createElement('option');
            placeholder.value = '';
            placeholder.textContent = '— ناوی کەیس هەڵبژێرە —';
            select.appendChild(placeholder);
            names.forEach(n => {
                const opt = document.createElement('option');
                opt.value = n.name;
                opt.textContent = n.name;
                opt.dataset.scope = n.scope_type === 'arch' ? 'arch' : 'tooth';
                select.appendChild(opt);
            });
            select.value = names.some(n => n.name === current) ? current : '';
        });
    }

    function scopeBadge(scope) {
        const key = scope === 'arch' ? 'arch' : 'tooth';
        const badge = document.createElement('span');
        badge.className = 'cnm-badge ' + key;
        badge.innerHTML = '<i class="fas ' + SCOPES[key].icon + '"></i>';
        badge.appendChild(document.createTextNode(SCOPES[key].label));
        return badge;
    }

    function renderList() {
        const list = document.getElementById('cnmList');
        list.innerHTML = '';
        if (!names.length) {
            list.innerHTML = '<div class="cnm-empty">هیچ ناوێک نییە</div>';
            return;
        }
        names.forEach(n => {
            const row = document.createElement('div');
            row.className = 'cnm-row';

            const label = document.createElement('span');
            label.className = 'cnm-name';
            label.textContent = n.name;

            const edit = document.createElement('button');
            edit.type = 'button';
            edit.className = 'cnm-icon-btn';
            edit.title = 'دەستکاری';
            edit.innerHTML = '<i class="fas fa-pen"></i>';
            edit.onclick = () => startEdit(row, n);

            row.append(label, scopeBadge(n.scope_type), edit);

            if (CAN_DELETE) {
                const del = document.createElement('button');
                del.type = 'button';
                del.className = 'cnm-icon-btn danger';
                del.title = 'سڕینەوە';
                del.innerHTML = '<i class="fas fa-trash"></i>';
                del.onclick = () => remove(n);
                row.appendChild(del);
            }

            list.appendChild(row);
        });
    }

    function startEdit(row, n) {
        row.innerHTML = '';
        const input = document.createElement('input');
        input.type = 'text';
        input.className = 'form-control';
        input.maxLength = 255;
        input.value = n.name;

        const scopeBox = document.createElement('div');
        scopeBox.className = 'cnm-scope';
        const getScope = buildScopeToggle(scopeBox, n.scope_type);

        const save = document.createElement('button');
        save.type = 'button';
        save.className = 'cnm-icon-btn';
        save.title = 'پاشەکەوت';
        save.innerHTML = '<i class="fas fa-check" style="color:#4caf50;"></i>';

        const cancel = document.createElement('button');
        cancel.type = 'button';
        cancel.className = 'cnm-icon-btn';
        cancel.title = 'هەڵوەشاندنەوە';
        cancel.innerHTML = '<i class="fas fa-times"></i>';
        cancel.onclick = () => { showError(''); renderList(); };

        const doSave = () => {
            const value = input.value.trim();
            const scope = getScope();
            if (!value) { showError('تکایە ناوی کەیس بنووسە'); return; }
            if (value === n.name && scope === n.scope_type) { renderList(); return; }
            showError('');
            call({ action: 'update', id: n.id, name: value, scope_type: scope })
                .then(data => {
                    const oldName = n.name;
                    n.name = data.name;
                    n.scope_type = data.scope_type;
                    renderList();
                    syncSelects(oldName, data.name);
                })
                .catch(e => showError(e.message));
        };
        save.onclick = doSave;
        input.onkeydown = e => {
            if (e.key === 'Enter') { e.preventDefault(); doSave(); }
            if (e.key === 'Escape') { e.preventDefault(); renderList(); }
        };

        row.append(input, scopeBox, save, cancel);
        input.focus();
        input.select();
    }

    function remove(n) {
        if (!confirm('دڵنیایت لە سڕینەوەی "' + n.name + '"؟\n\nکەیسە کراوەکان ناوەکەیان وەک خۆی دەمێنێتەوە.')) return;
        showError('');
        call({ action: 'delete', id: n.id })
            .then(() => {
                names = names.filter(x => x.id !== n.id);
                renderList();
                syncSelects();
            })
            .catch(e => showError(e.message));
    }

    window.cnmAdd = function () {
        const input = document.getElementById('cnmNewName');
        const value = input.value.trim();
        if (!value) { showError('تکایە ناوی کەیس بنووسە'); return; }
        showError('');
        call({ action: 'add', name: value, scope_type: getNewScope() })
            .then(data => {
                names.push({ id: data.id, name: data.name, scope_type: data.scope_type });
                input.value = '';
                renderList();
                syncSelects();
                // Pre-select the name just added — it is usually added because
                // it is about to be used — and apply its scope to the form.
                document.querySelectorAll('.js-case-name-select').forEach(s => {
                    s.value = data.name;
                    if (window.applyCaseNameScope) window.applyCaseNameScope(s);
                });
                input.focus();
            })
            .catch(e => showError(e.message));
    };

    window.openCaseNameManager = function () {
        showError('');
        document.getElementById('cnmNewName').value = '';
        getNewScope = buildScopeToggle(document.getElementById('cnmNewScope'), 'tooth');
        openDialog('caseNameManagerDialog');
        load().then(() => document.getElementById('cnmNewName').focus());
    };
})();
</script>
    <?php
}
