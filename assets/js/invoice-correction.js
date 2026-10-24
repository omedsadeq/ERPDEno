(function () {
    'use strict';
    var active = null;
    var busy = false;
    var fmt = new Intl.NumberFormat('en-US', { maximumFractionDigits: 2 });
    var fields = ['treatment_name', 'tooth_number', 'arch_type', 'quantity', 'unit_price', 'discount', 'notes'];

    function cents(value) {
        var s = String(value).trim();
        if (!/^\d{1,10}(?:\.\d{1,2})?$/.test(s)) return NaN;
        var parts = s.split('.');
        return Number(parts[0]) * 100 + Number(((parts[1] || '') + '00').slice(0, 2));
    }
    function amount(form, name) { return cents(form.elements.namedItem(name).value); }
    function state(form) { return JSON.parse(form.querySelector('.ic-seed').textContent); }
    function error(form, message) {
        var el = form.querySelector('.ic-error');
        el.textContent = message;
        el.hidden = !message;
        if (message) el.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
    }
    // ددان: the same four-quadrant cross as the printed receipt. A row is one
    // tooth, so typing its Palmer number (1-8, or A-E for a primary tooth) into
    // a quadrant sets tooth_number (FDI) and clears the other three.
    var QUAD_FDI = { UR: [1, 5], UL: [2, 6], LL: [3, 7], LR: [4, 8] };
    var FDI_QUAD = { 1: 'UR', 2: 'UL', 3: 'LL', 4: 'LR', 5: 'UR', 6: 'UL', 7: 'LL', 8: 'LR' };
    var QUAD_NAME = { UR: 'سەرەوەی ڕاست', UL: 'سەرەوەی چەپ', LR: 'خوارەوەی ڕاست', LL: 'خوارەوەی چەپ' };
    var PRIMARY = 'ABCDE';
    function crossHtml() {
        return '<div class="tooth-cross is-input ic-cross">' + ['UR', 'UL', 'LR', 'LL'].map(function (q) {
            return '<input type="text" class="tc-' + q.toLowerCase() + ' ic-tooth-in" data-q="' + q + '" maxlength="1"'
                + ' placeholder="—" title="' + QUAD_NAME[q] + '" aria-label="ددان — ' + QUAD_NAME[q] + '">';
        }).join('') + '</div>';
    }
    function paintCross(tr) {
        var arch = tr.querySelector('[data-field="arch_type"]').value;
        var badge = tr.querySelector('.ic-arch-badge'), isJaw = arch === 'upper' || arch === 'lower';
        tr.querySelector('.ic-cross').hidden = isJaw;
        badge.hidden = !isJaw;
        badge.classList.toggle('is-lower', arch === 'lower');
        badge.innerHTML = isJaw ? '<i class="fas fa-teeth-open"></i> ' + (arch === 'upper' ? 'ماکزیلا / سەرەوە' : 'ماندیبل / خوارەوە') : '';
        var fdi = String(tr.querySelector('[data-field="tooth_number"]').value || '').trim();
        var valid = /^(?:[1-4][1-8]|[5-8][1-5])$/.test(fdi);
        var q = valid ? FDI_QUAD[fdi.charAt(0)] : null, n = valid ? Number(fdi.charAt(1)) : 0;
        tr.querySelectorAll('.ic-tooth-in').forEach(function (inp) {
            inp.setCustomValidity('');
            inp.classList.remove('is-bad');
            inp.value = inp.dataset.q === q ? (Number(fdi.charAt(0)) >= 5 ? PRIMARY.charAt(n - 1) : String(n)) : '';
        });
    }
    function crossInput(inp) {
        var tr = inp.closest('[data-ic-row]'), hidden = tr.querySelector('[data-field="tooth_number"]');
        var v = inp.value.trim().toUpperCase(), fdi = QUAD_FDI[inp.dataset.q];
        tr.querySelectorAll('.ic-tooth-in').forEach(function (other) {
            if (other !== inp) { other.value = ''; other.classList.remove('is-bad'); other.setCustomValidity(''); }
        });
        inp.value = v;
        var tooth = /^[1-8]$/.test(v) ? String(fdi[0]) + v
            : (/^[A-E]$/.test(v) ? String(fdi[1]) + (PRIMARY.indexOf(v) + 1) : null);
        // A bad entry blocks the save (reportValidity) instead of silently
        // dropping the tooth that was there.
        inp.classList.toggle('is-bad', v !== '' && tooth === null);
        inp.setCustomValidity(v !== '' && tooth === null ? 'ژمارەی ددان: ١-٨ یان A-E' : '');
        if (v === '' || tooth !== null) hidden.value = tooth || '';
    }
    function addRow(form, data) {
        var tr = document.createElement('tr');
        tr.dataset.icRow = String(data.id || 0);
        tr.innerHTML = '<td><input data-field="treatment_name" maxlength="100" required aria-label="ناوەڕۆک"></td>'
            // شوێن is not edited here any more; it travels unchanged in a hidden
            // field, and a whole-jaw row shows its badge instead of the cross.
            + '<td class="ic-teeth"><input type="hidden" data-field="tooth_number"><input type="hidden" data-field="arch_type">'
            + crossHtml() + '<span class="rt-location-badge ic-arch-badge" hidden></span></td>'
            + '<td><input type="number" data-field="quantity" min="1" max="9999" step="1" required aria-label="ژمارە"></td>'
            + '<td><input type="number" data-field="unit_price" min="0" max="9999999999.99" step="0.01" required aria-label="نرخی تاک"></td>'
            + '<td><input type="number" data-field="discount" min="0" step="0.01" required aria-label="داشکاندنی ڕیز"></td>'
            + '<td><b data-ic-line-total dir="ltr"></b></td><td><input data-field="notes" maxlength="10000" aria-label="تێبینی"></td>'
            + '<td><button type="button" class="vt-btn vt-btn--del" data-ic-remove title="لابردنی ڕیز" aria-label="لابردنی ڕیز"><i class="fas fa-trash"></i></button></td>';
        fields.forEach(function (key) {
            tr.querySelector('[data-field="' + key + '"]').value = data[key] == null ? (key === 'arch_type' ? 'none' : '') : data[key];
        });
        paintCross(tr);
        form.querySelector('[data-ic-rows]').appendChild(tr);
        preview(form);
    }
    function rows(form) {
        return Array.from(form.querySelectorAll('[data-ic-row]')).map(function (tr) {
            var row = { id: Number(tr.dataset.icRow), remove: tr.dataset.removed === '1' };
            fields.forEach(function (key) { row[key] = tr.querySelector('[data-field="' + key + '"]').value; });
            return row;
        });
    }
    function payments(form) {
        return Array.from(form.querySelectorAll('[data-ic-payment][data-ic-editable="1"]')).map(function (tr) {
            var payment = { id: Number(tr.dataset.icPayment) };
            tr.querySelectorAll('[data-p-field]').forEach(function (input) { payment[input.dataset.pField] = input.value; });
            return payment;
        });
    }
    function preview(form) {
        var seed = state(form), total = 0;
        form.querySelectorAll('[data-ic-row]').forEach(function (tr) {
            if (tr.dataset.removed === '1') return;
            var line = cents(tr.querySelector('[data-field="unit_price"]').value)
                * Number(tr.querySelector('[data-field="quantity"]').value)
                - cents(tr.querySelector('[data-field="discount"]').value);
            tr.querySelector('[data-ic-line-total]').textContent = Number.isFinite(line) ? fmt.format(line / 100) : '—';
            total += line;
        });
        var discount = amount(form, 'discount_value');
        if (form.elements.discount_mode.value === 'percent') {
            discount = Number.isSafeInteger(total) && Number.isSafeInteger(discount)
                ? Number((BigInt(total) * BigInt(discount) + 5000n) / 10000n) : NaN;
        }
        var net = total - discount;
        var paid = amount(form, 'received_amount') + amount(form, 'prior_paid_amount') + cents(seed.invoice.advance_paid_amount || '0');
        seed.payments.forEach(function (p) { if (p.status === 'approved') paid += cents(p.allocated_amount); });
        var receiptChanged = payments(form).some(function (p) {
            var old = seed.payments.find(function (o) { return Number(o.id) === p.id; });
            return cents(p.amount) !== cents(old.amount);
        });
        [['total', total], ['net', net], ['paid', receiptChanged ? NaN : paid], ['remaining', receiptChanged ? NaN : net - paid]].forEach(function (pair) {
            form.querySelector('[data-ic-' + pair[0] + ']').textContent = Number.isFinite(pair[1]) ? fmt.format(pair[1] / 100) : '—';
        });
        form.querySelector('.ic-balance').title = receiptChanged ? 'دوای پاشەکەوتکردن، قەبزەکان دابەش دەکرێنەوە و ماوەی ورد دیار دەبێت.' : '';
        form.querySelector('.ic-preview').classList.toggle('is-overpaid', !receiptChanged && net < paid);
    }
    function close() {
        if (!active || busy) return;
        var form = active, pane = form.closest('[data-invoice-pane]');
        form.hidden = true;
        pane.classList.remove('is-admin-editing');
        pane.querySelector('[data-admin-edit-invoice]').setAttribute('aria-expanded', 'false');
        active = null;
        window.invoiceCorrectionOpen = false;
    }
    function open(id) {
        if (active || busy) return;
        var form = document.querySelector('[data-correction-for="' + id + '"]');
        if (!form) return;
        form.reset();
        form.querySelector('[data-ic-rows]').replaceChildren();
        state(form).rows.forEach(function (row) { addRow(form, row); });
        error(form, '');
        active = form;
        window.invoiceCorrectionOpen = true;
        form.hidden = false;
        form.closest('[data-invoice-pane]').classList.add('is-admin-editing');
        form.closest('[data-invoice-pane]').querySelector('[data-admin-edit-invoice]').setAttribute('aria-expanded', 'true');
        preview(form);
        form.elements.invoice_no.focus({ preventScroll: true });
        form.scrollIntoView({ block: 'start', behavior: 'smooth' });
    }
    document.addEventListener('click', function (event) {
        var edit = event.target.closest('[data-admin-edit-invoice]');
        if (edit) { open(Number(edit.dataset.adminEditInvoice)); return; }
        var form = event.target.closest('.ic-editor');
        if (!form || busy) return;
        if (event.target.closest('[data-ic-cancel]')) {
            close();
            if (typeof window.refreshLiveCase === 'function') window.refreshLiveCase(true);
        } else if (event.target.closest('[data-ic-add]')) {
            addRow(form, { quantity: 1, unit_price: '0', discount: '0', arch_type: 'none' });
        } else if (event.target.closest('[data-ic-remove]')) {
            var tr = event.target.closest('[data-ic-row]');
            var removed = tr.dataset.removed !== '1';
            tr.dataset.removed = removed ? '1' : '0';
            tr.classList.toggle('is-removed', removed);
            tr.querySelectorAll('input,select').forEach(function (el) { el.disabled = removed; });
            var btn = tr.querySelector('[data-ic-remove]');
            btn.innerHTML = removed ? '<i class="fas fa-rotate-left"></i>' : '<i class="fas fa-trash"></i>';
            btn.title = removed ? 'گەڕاندنەوەی ڕیز' : 'لابردنی ڕیز';
            btn.setAttribute('aria-label', btn.title);
            preview(form);
        }
    });
    document.addEventListener('input', function (event) {
        var form = event.target.closest('.ic-editor');
        if (!form || busy) return;
        if (event.target.classList.contains('ic-tooth-in')) crossInput(event.target);
        preview(form);
    });
    document.addEventListener('submit', async function (event) {
        var form = event.target.closest('.ic-editor');
        if (!form) return;
        event.preventDefault();
        if (busy || !form.reportValidity()) return;
        var payload = { revision: state(form).revision, rows: rows(form), payments: payments(form) };
        ['invoice_no','work_date','notes','accounting_notes','discount_mode','discount_value','received_amount','prior_paid_amount','reason'].forEach(function (key) {
            payload[key] = form.elements.namedItem(key).value;
        });
        var fd = new FormData();
        fd.set('csrf_token', window.CSRF_TOKEN);
        fd.set('ajax', '1');
        fd.set('action', 'admin_correct_invoice');
        fd.set('invoice_id', form.dataset.correctionFor);
        fd.set('correction', JSON.stringify(payload));
        busy = true;
        form.dataset.busy = '1';
        form.querySelectorAll('button,input,select,textarea').forEach(function (el) {
            el.dataset.wasDisabled = el.disabled ? '1' : '0'; el.disabled = true;
        });
        var save = form.querySelector('[data-ic-save]'), original = save.innerHTML;
        save.innerHTML = '<i class="fas fa-spinner fa-spin"></i> پاشەکەوت دەکرێت…';
        error(form, '');
        try {
            var response = await fetch(window.location.href, { method: 'POST', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest' } });
            var result = await response.json();
            if (!result.success) { error(form, result.message || 'پاشەکەوتکردن سەرنەکەوت'); return; }
            busy = false;
            close();
            if (typeof window.onInvoicePaneSaved === 'function') window.onInvoicePaneSaved(result, Number(form.dataset.correctionFor));
            else window.location.reload();
            if (typeof window.showToast === 'function') window.showToast('success', 'گۆڕانکارییەکان پاشەکەوت کران');
        } catch (e) {
            error(form, 'وەڵامی پاشەکەوتکردن نەگەیشت. پەڕەکە نوێ بکەرەوە بۆ دڵنیابوونەوە لە داتاکە.');
        } finally {
            busy = false;
            delete form.dataset.busy;
            save.innerHTML = original;
            form.querySelectorAll('button,input,select,textarea').forEach(function (el) { el.disabled = el.dataset.wasDisabled === '1'; });
        }
    });
    window.addEventListener('beforeunload', function (event) {
        if (active) { event.preventDefault(); event.returnValue = ''; }
    });
})();
