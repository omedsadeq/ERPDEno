/**
 * «کەیس» (includes/case-button.php): straight into the patient's case.
 *
 * A doctor goes straight in (cases/open.php opens theirs, made now if there is
 * none). The admin and reception go straight in when the page names the doctor
 * (an appointment's, a plan's) or the patient has one open case; with several
 * they pick the case, with none the doctor it is made for.
 * window.CASE_OPEN = { url, direct } comes from includes/footer.php.
 */
(function () {
    const cfg = window.CASE_OPEN || {};

    function submit(fields) {
        const f = document.createElement('form');
        f.method = 'POST';
        f.action = cfg.url;
        f.style.display = 'none';
        const meta = document.querySelector('meta[name="csrf-token"]');
        if (meta) fields.csrf_token = meta.getAttribute('content');
        Object.keys(fields).forEach(k => {
            const i = document.createElement('input');
            i.type = 'hidden'; i.name = k; i.value = fields[k];
            f.appendChild(i);
        });
        document.body.appendChild(f);
        f.submit();
    }

    function fail(btn) {
        if (btn) btn.classList.remove('is-busy');
        if (typeof showToast === 'function') showToast('error', 'کەیسەکە نەکرایەوە — دووبارە هەوڵ بدەرەوە');
    }

    window.openPatientCase = function (patientId, btn, doctorId) {
        if (btn && btn.classList.contains('is-busy')) return;
        const go = extra => {
            if (btn) btn.classList.add('is-busy');
            submit(Object.assign({ action: 'open', patient_id: patientId }, extra || {}));
        };
        if (cfg.direct) { go(); return; }

        if (btn) btn.classList.add('is-busy');
        const body = new FormData();
        body.append('action', 'options');
        body.append('patient_id', patientId);
        body.append('doctor_id', doctorId || 0);
        fetch(cfg.url, { method: 'POST', body, headers: { Accept: 'application/json' } })
            .then(r => r.json())
            .then(d => {
                if (btn) btn.classList.remove('is-busy');
                if (!d || !d.success) { fail(btn); return; }
                if (d.doctor_ok) { go({ doctor_id: doctorId }); return; }
                if (d.cases.length === 1) { go({ case_id: d.cases[0].id }); return; }
                if (d.cases.length > 1) {
                    Swal.fire({
                        title: 'کام کەیس؟', input: 'select',
                        inputOptions: new Map(d.cases.map(c => [String(c.id), c.label])),   // a Map keeps the newest first
                        inputValue: String(d.cases[0].id),
                        showCancelButton: true, confirmButtonText: 'بڕۆ بۆ کەیس', cancelButtonText: 'نەخێر', confirmButtonColor: '#6d28d9'
                    }).then(r => { if (r.isConfirmed && r.value) go({ case_id: r.value }); });
                    return;
                }
                const docs = new Map(d.doctors.map(x => [String(x.id), x.name]));
                Swal.fire({
                    title: 'کەیس بۆ کام دکتۆر؟', text: 'ئەم نەخۆشە کەیسی کراوەی نییە — یەکێکی نوێ دروست دەبێت',
                    input: 'select', inputOptions: docs, inputPlaceholder: 'دکتۆر هەڵبژێرە',
                    inputValue: docs.has(String(d.doctor_id)) ? String(d.doctor_id) : '',
                    showCancelButton: true, confirmButtonText: 'کەیس', cancelButtonText: 'نەخێر', confirmButtonColor: '#6d28d9',
                    inputValidator: v => v ? null : 'دکتۆر هەڵبژێرە'
                }).then(r => { if (r.isConfirmed && r.value) go({ doctor_id: r.value }); });
            })
            .catch(() => fail(btn));
    };

    // cases/open.php sends a failed open back here with ?case_err=1
    if (/[?&]case_err=1(&|$)/.test(location.search)) {
        window.addEventListener('load', () => fail(null));
        history.replaceState(null, '', location.pathname + location.search.replace(/([?&])case_err=1(&|$)/, '$1').replace(/[?&]$/, '') + location.hash);
    }
})();
