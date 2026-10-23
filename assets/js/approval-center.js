/**
 * Approval Center client — accountant/approval-center.php
 * ناوەندی پەسەندکردن — لایەنی کلاینت
 *
 * Server renders the shell + initial ApprovalQueue::counts() (window.AC_INIT).
 * Everything else — row lists, the detail drawer, and the write actions —
 * is fetched here from api/approval-center.php (GET, read-only) and
 * api/finance-actions.php (POST, the write actions). Response envelopes match
 * those two files exactly: list -> {ok,rows,counts,server_time}; detail ->
 * {ok,kind,...}; reversal_preview -> {ok,lines,reopen,remove,...};
 * actions -> {ok,error?,stale?}.
 *
 * Polling/sound pattern copied from accountant/receiving.php's inline script
 * (POLL_MS, the <audio> unlock-on-gesture trick, playSound()); table/status-
 * chip CSS classes copied the same way (kind-badge/status-chip/chip-*).
 */
(function () {
    'use strict';

    const INIT = window.AC_INIT || {};
    const APP_URL = INIT.appUrl || '';

    const AC = {
        listUrl: APP_URL + '/api/approval-center.php',
        actionsUrl: APP_URL + '/api/finance-actions.php',
        statementUrl: APP_URL + '/patients/statement.php',
        POLL_MS: 8000,
        tab: INIT.tab || 'all',  // all | approved
        scope: 'open',          // open | history — only meaningful on the 'all' tab
        q: INIT.q || '',
        kind: INIT.kind || '', // جۆر combobox — '' = every source
        approval: INIT.approval || '', // پەسەندکراو only: '' | 'auto' (🤖) | 'manual' (✋)
        doctorId: '',
        dateFrom: '',
        dateTo: '',
        // ⇅ ڕیزبەندی. Sent to the server as ?sort= — see buildListUrl.
        sort: localStorage.getItem('acSort') === 'asc' ? 'asc' : 'desc',
        page: 1,
        rows: [],
        since: null,
        timer: null,
        newTotal: 0,
        baseTitle: document.title,
        soundEnabled: localStorage.getItem('acSound') !== '0',
        audioUnlocked: false,
        currentDrawer: null,
        // The APPROVED record ({kind, id}) the accountant switched into edit
        // mode with «تەعدیل». An approved invoice / receipt / plan / X-ray
        // always opens locked; this keeps edit mode across the re-render a
        // save triggers, and is dropped when the window closes or anything
        // else is opened (see isEditingApproved / setEditingApproved).
        editApproved: null,
        // Set by the پەسەندکراو tab's ✏️ so the opened record puts focus on
        // its «تەعدیل» button (one Enter away from editing).
        focusEditBtn: false,
    };

    /** The kinds corrected in place behind «تەعدیل» instead of being reopened
     *  — the same set as ApprovalReversal::EDIT_IN_PLACE_KINDS. */
    const EDIT_IN_PLACE_KINDS = ['invoice', 'payment', 'installment', 'xray'];

    function isEditingApproved(kind, id) {
        return !!AC.editApproved && AC.editApproved.kind === kind && AC.editApproved.id === String(id);
    }

    function setEditingApproved(kind, id) {
        AC.editApproved = kind ? { kind: kind, id: String(id) } : null;
    }

    const $ = (id) => document.getElementById(id);

    function esc(v) {
        const d = document.createElement('div');
        d.textContent = v === null || v === undefined ? '' : String(v);
        return d.innerHTML;
    }

    function nf(n) {
        if (n === null || n === undefined || n === '') return '—';
        return new Intl.NumberFormat('en-US').format(Math.round(Number(n) || 0));
    }

    function fmtDate(s) {
        if (!s) return '—';
        return String(s).replace('T', ' ').slice(0, 16);
    }

    // Idempotency token — same shape as patients/statement.php's psToken():
    // prefix + wall-clock ms + a short random suffix, so a retried fetch of
    // the SAME click reuses one key while a fresh وەرگیراو attempt (drawer
    // reopened, or a second click after the first one finished) always gets
    // its own. Generated once per drawer render (see wireInvoicePane below),
    // never regenerated per keystroke.
    function acToken(prefix) {
        return prefix + '-' + Date.now() + '-' + Math.random().toString(36).slice(2, 10);
    }

    // ---------------------------------------------------------------
    // Label maps
    // ---------------------------------------------------------------

    const KIND_META = {
        invoice:     { icon: 'fa-file-invoice',  bg: '#deecf9', color: '#0063b1', label: 'پسووڵە' },
        archwork:    { icon: 'fa-clipboard-list', bg: '#e8f0fe', color: '#1a56a8', label: 'کارە ئەنجامدراوەکان' },
        payment:     { icon: 'fa-money-bill-wave', bg: '#dff6dd', color: '#107c10', label: 'پارەدان' },
        installment: { icon: 'fa-calendar-alt',  bg: '#f0e6fa', color: '#8764b8', label: 'قیست' },
        xray:        { icon: 'fa-x-ray',         bg: '#fff4ce', color: '#b56a00', label: 'ئێکسڕەی' },
        station:     { icon: 'fa-chair',         bg: '#e0f2f1', color: '#00695c', label: 'ژووری چاوەڕوانی' },
        advance:     { icon: 'fa-hand-holding-usd', bg: '#e8f5e9', color: '#1b5e20', label: 'قەرزی کۆن' },
    };

    const INTENT_LABELS = { cash: 'نەقد', debt: 'قەرز', installment: 'قیست' };
    const ARCH_LABELS = { upper: 'سەرەوە', lower: 'خوارەوە', both: 'هەردووک' };
    const PAYMENT_TYPE_LABELS = { cash: 'نەقد', credit: 'قەرز', installment: 'قیست' };
    const METHOD_LABELS = { cash: 'نەقد', card: 'کارت', transfer: 'گواستنەوە', other: 'هی تر', advance: 'لە ئامانەت' };
    const XRAY_TYPE_LABELS = { cash: 'کاش', dont_cash: 'کاش نییە (بەخۆڕایی)', debt: 'قەرز' };
    const XRAY_EXAM_LABELS = {
        panoramic: 'پانۆرامیک',
        cbct: 'CBCT (3D)',
        periapical: 'پەریئەپیکاڵ',
        cephalometric: 'سێفالۆمەتریک',
    };
    const XRAY_REASON_LABELS = {
        implant: 'چاندنی ددان',
        pathology: 'پاتۆلۆجی',
        impaction: 'ددانی شاراوە',
        trauma: 'تراوما / برین',
        endo: 'ڕیشەی ددان',
        other: 'هی تر',
    };

    // X-ray detail is small and is frequently opened straight from the list.
    // A short-lived promise cache lets hover/focus prefetch finish before the
    // eye button is clicked without letting old accounting state linger.
    const XRAY_DETAIL_TTL = 10000;
    const xrayDetailCache = new Map();
    let drawerRequestSeq = 0;
    let drawerPreviousFocus = null;

    const STATUS_MAPS = {
        invoice: {
            draft: ['ڕەشنووس', 'chip-neutral'],
            sent: ['چاوەڕوانی پەسەند', 'chip-open'],
            approved: ['پەسەندکراو', 'chip-done'],
            returned: ['گەڕێندراوەتەوە', 'chip-bad'],
            cancelled: ['هەڵوەشاوە', 'chip-neutral'],
        },
        archwork: {
            sent: ['چاوەڕوانی پەسەند', 'chip-open'],
            approved: ['پەسەندکراو', 'chip-done'],
            returned: ['گەڕێندراوەتەوە', 'chip-bad'],
        },
        payment: {
            pending: ['چاوەڕوان', 'chip-open'],
            approved: ['پەسەندکراو', 'chip-done'],
            rejected: ['ڕەتکراوە', 'chip-bad'],
            refunded: ['هەڵوەشاوە', 'chip-neutral'],
        },
        installment: {
            pending: ['چاوەڕوان', 'chip-open'],
            active: ['چالاک', 'chip-done'],
            completed: ['تەواوبوو', 'chip-done'],
            cancelled: ['هەڵوەشاوە', 'chip-neutral'],
            rejected: ['ڕەتکراوە', 'chip-bad'],
        },
        xray: {
            pending_accountant: ['چاوەڕوانی ژمێریار', 'chip-open'],
            returned_to_accountant: ['گەڕاوەتەوە بۆ ژمێریار', 'chip-bad'],
            pending_xray: ['چاوەڕوانی ئێکسڕەی', 'chip-done'],
            in_progress: ['لە پێشکردن', 'chip-seen'],
            in_xray: ['لە ئێکسڕەی', 'chip-seen'],
            completed: ['تەواوبوو', 'chip-done'],
            cancelled: ['هەڵوەشاوە', 'chip-neutral'],
        },
        station: {
            at_accounting: ['لە ژووری چاوەڕوانی', 'chip-open'],
        },
        // What the doctor still has to do with it, not an approval state:
        // the money was taken the moment the receipt was written.
        advance: {
            open:      ['دانەنراوە', 'chip-open'],
            partial:   ['بەشی دانراوە', 'chip-seen'],
            allocated: ['تەواو دانراوە', 'chip-done'],
            refunded:  ['گەڕێندراوەتەوە', 'chip-bad'],
        },
    };

    function statusInfoFor(kind, status) {
        const map = STATUS_MAPS[kind] || {};
        return map[status] || [status || '—', 'chip-neutral'];
    }

    // ---------------------------------------------------------------
    // Sound (copied pattern from accountant/receiving.php)
    // ---------------------------------------------------------------

    const sound = new Audio(APP_URL + '/assets/sounds/notification.wav');
    sound.preload = 'auto';

    function renderSoundToggle() {
        const btn = $('acSoundToggle');
        if (!btn) return;
        btn.classList.toggle('muted', !AC.soundEnabled);
        btn.innerHTML = AC.soundEnabled ? '<i class="fas fa-volume-up"></i>' : '<i class="fas fa-volume-mute"></i>';
        btn.title = AC.soundEnabled ? 'دەنگ کوژاندنەوە' : 'دەنگ هەڵکردن';
    }

    function playSound() {
        if (!AC.soundEnabled) return;
        try { sound.currentTime = 0; sound.play().catch(() => {}); } catch (e) { /* ignore */ }
    }

    if ($('acSoundToggle')) {
        $('acSoundToggle').addEventListener('click', () => {
            AC.soundEnabled = !AC.soundEnabled;
            localStorage.setItem('acSound', AC.soundEnabled ? '1' : '0');
            renderSoundToggle();
            if (AC.soundEnabled) playSound();
        });
    }

    const unlockAudio = () => {
        if (AC.audioUnlocked) return;
        AC.audioUnlocked = true;
        sound.muted = true;
        sound.play().then(() => { sound.pause(); sound.currentTime = 0; sound.muted = false; })
                    .catch(() => { sound.muted = false; });
        document.removeEventListener('click', unlockAudio);
        document.removeEventListener('keydown', unlockAudio);
    };
    document.addEventListener('click', unlockAudio);
    document.addEventListener('keydown', unlockAudio);

    function announceNew() {
        const badge = $('acNewBadge');
        if (badge) {
            badge.textContent = AC.newTotal + ' نوێ';
            badge.style.display = 'inline-flex';
        }
        document.title = '(' + AC.newTotal + ') ' + AC.baseTitle;
        playSound();
    }

    function clearNewFlag() {
        AC.newTotal = 0;
        if ($('acNewBadge')) $('acNewBadge').style.display = 'none';
        document.title = AC.baseTitle;
    }
    document.addEventListener('click', clearNewFlag);

    // ---------------------------------------------------------------
    // Toast (uses footer.php's global showToast/Swal helper — defined by
    // the time any of these callbacks actually run, since they only fire
    // after a user interaction long after the page finished loading)
    // ---------------------------------------------------------------

    function toast(type, message) {
        if (typeof window.showToast === 'function') { window.showToast(type, message); return; }
        // eslint-disable-next-line no-alert
        alert(message);
    }

    // ---------------------------------------------------------------
    // List fetch + row rendering
    // ---------------------------------------------------------------

    function buildListUrl() {
        // 'approved' is its own decided-rows query and ignores the open/history
        // scope entirely (the toggle is hidden while that tab is active).
        // 'approved' and 'advances' are each their own query and ignore the
        // open/history scope entirely (the toggle is hidden on both).
        const effectiveTab = (AC.tab === 'approved' || AC.tab === 'advances')
            ? AC.tab
            : (AC.scope === 'history' ? 'history' : AC.tab);
        const p = new URLSearchParams({ action: 'list', tab: effectiveTab, sort: AC.sort });
        if (AC.q) p.set('q', AC.q);
        if (AC.kind) p.set('kind', AC.kind);
        if (AC.approval && AC.tab === 'approved') p.set('approval', AC.approval);
        if (AC.doctorId) p.set('doctor_id', AC.doctorId);
        if (AC.dateFrom) p.set('date_from', AC.dateFrom);
        if (AC.dateTo) p.set('date_to', AC.dateTo);
        return AC.listUrl + '?' + p.toString();
    }

    async function load(newUids) {
        newUids = newUids || new Set();
        try {
            const res = await fetch(buildListUrl());
            const data = await res.json();
            if (!data.ok) return;
            renderCounts(data.counts);
            renderApprovedCount(data.rows);
            renderRows(data.rows, newUids);
            AC.since = data.server_time;
        } catch (e) {
            console.error('approval-center load error:', e);
        }
    }

    function renderCounts(counts) {
        if (!counts) return;
        if ($('acCountAll')) $('acCountAll').textContent = counts.all ?? 0;
    }

    /** پەسەندکراو has no server-side count (see approval-center.php) — its badge
     * is the size of the list actually on screen, and goes back to — while the
     * tab is not the one being viewed. */
    function renderApprovedCount(rows) {
        const badge = $('acCountApproved');
        if (!badge) return;
        badge.textContent = AC.tab === 'approved' ? String((rows || []).length) : '—';
    }

    /** The sources whose approved rows can be taken back — the same set
     *  classes/ApprovalReversal.php accepts. ژووری چاوەڕوانی is never a
     *  decision and قەرزی کۆن is money already taken against the patient, so
     *  neither has an approval to reverse. */
    const REVERSIBLE_KINDS = ['invoice', 'archwork', 'payment', 'installment', 'xray'];

    /** بینین always; دەستکاری + سڕینەوە only on the پەسەندکراو tab, where every
     *  row is by definition an already-granted decision. */
    function rowActionsHtml(r) {
        let html = '<div class="ac-row-actions">'
            + '<button type="button" class="btn-eye-sm" title="' + esc(r.action || 'بینین') + '"><i class="fas fa-eye"></i></button>';
        if (AC.tab === 'approved' && Number(r.case_id) > 0) {
            const caseUrl = APP_URL + '/cases/view.php?id=' + encodeURIComponent(r.case_id)
                + '&tab=treatments' + (r.kind === 'invoice' ? '&inv=' + encodeURIComponent(r.id) : '');
            html += '<a class="btn-eye-sm ac-edit-case" href="' + esc(caseUrl) + '" target="_blank" rel="noopener" title="کردنەوەی کەیسی تەواو بۆ دەستکاری">'
                + '<i class="fas fa-edit"></i> دەستکاری کەیس</a>';
        }
        if (AC.tab === 'approved' && REVERSIBLE_KINDS.indexOf(r.kind) !== -1) {
            const editTitle = EDIT_IN_PLACE_KINDS.indexOf(r.kind) !== -1
                ? 'کردنەوە بۆ تەعدیل — پەسەندکراو دەمێنێتەوە و بەروارەکەی ناگۆڕێت'
                : 'کردنەوە بۆ دەستکاری';
            html += '<button type="button" class="btn-row-act is-edit" data-rev="reopen" title="' + esc(editTitle) + '"><i class="fas fa-pen"></i></button>'
                + '<button type="button" class="btn-row-act is-del" data-rev="remove" title="سڕینەوە و گەڕاندنەوەی هەموو تۆمارەکان"><i class="fas fa-trash-alt"></i></button>';
        }
        if (AC.tab === 'advances' && r.kind === 'advance') {
            html += '<button type="button" class="btn-row-act is-del" data-adv-del title="ڕەشکردنەوەی ئەم قەبزە"><i class="fas fa-trash-alt"></i></button>';
        }
        return html + '</div>';
    }

    /** 🗑 on a قەرزی کۆن row — asks for a reason, then deletes the receipt. */
    async function deleteAdvance(id, btn) {
        const row = AC.rows.find((r) => r.kind === 'advance' && String(r.id) === String(id)) || {};
        const ask = await Swal.fire({
            icon: 'warning',
            title: 'ڕەشکردنەوەی قەبزی قەرزی کۆن',
            html: '<div style="line-height:1.9">'
                + '<b dir="ltr">' + esc(row.number || '') + '</b> — ' + esc(row.patient_name || '')
                + '<br>بڕ: <b>' + nf(row.amount) + '</b></div>',
            input: 'textarea',
            inputPlaceholder: 'هۆکاری ڕەشکردنەوە...',
            inputValidator: (v) => (!v || !v.trim()) ? 'هۆکار بنووسە' : undefined,
            showCancelButton: true,
            confirmButtonText: '<i class="fas fa-trash-alt"></i> ڕەشکردنەوە',
            cancelButtonText: 'پاشگەزبوونەوە',
            confirmButtonColor: '#b3101e',
            focusCancel: true,
        });
        if (!ask.isConfirmed) return;
        const res = await postFinanceAction('advance_delete', { advance_id: id, reason: ask.value.trim() }, btn);
        if (res && res.ok) {
            toast('success', 'قەبزی ' + (res.advance_no || '') + ' ڕەشکرایەوە');
            load();
        } else {
            toast('error', (res && res.error) || 'سڕینەوە سەرنەکەوت');
        }
    }

    /** Who approved an approved invoice: the nightly run (AutoApproval) or a person. */
    function approvalTagHtml(r) {
        if (r.kind !== 'invoice' || r.approved_auto === null || r.approved_auto === undefined) return '';
        return Number(r.approved_auto) === 1
            ? '<div><span class="ac-approval-tag is-auto" title="پەسەندکردنی خۆکاری شەوانە">🤖 خۆکار</span></div>'
            : '<div><span class="ac-approval-tag is-manual" title="' + esc(r.approved_by_name || '') + '">✋ دەستی'
                + (r.approved_by_name ? ' · ' + esc(r.approved_by_name) : '') + '</span></div>';
    }

    function renderRow(r, newUids, grouped) {
        const km = KIND_META[r.kind] || KIND_META.invoice;
        const st = statusInfoFor(r.kind, r.status);

        let payCell = '—';
        if (r.kind === 'invoice') {
            payCell = r.intent
                ? '<span class="kind-badge" style="background:#eef2f6;color:#333;">' + esc(INTENT_LABELS[r.intent] || r.intent) + '</span>'
                : '—';
            payCell += '<div class="ac-promise" style="color:#147d4f;"><i class="fas fa-file-invoice-dollar"></i> وەرگیراو: ' + nf(r.received_amount || 0) + '</div>';
            if (Number(r.prior_paid_amount || 0) > 0.009) {
                payCell += '<div class="ac-promise" style="color:#7a4a12;"><i class="fas fa-history"></i> دراوی پێشوو (کۆن): ' + nf(r.prior_paid_amount) + '</div>';
            }
            if (r.intent === 'debt' && (r.promised_date || r.promised_amount !== null)) {
                payCell += '<div class="ac-promise">📅 ' + esc(r.promised_date || '—')
                    + (r.promised_amount !== null && r.promised_amount !== undefined ? ' · ' + nf(r.promised_amount) : '') + '</div>';
            }
        } else if (r.kind === 'archwork') {
            // An arch visit bills nothing, so this row has no amount of its own
            // — what it carries is the work done, and the money is settled
            // against the patient's existing debt inside the drawer.
            payCell = '<span class="kind-badge" style="background:#eef2f6;color:#333;">'
                + esc((r.work_count || 0) + ' کار') + '</span>';
        } else if (r.kind === 'payment' && r.payment_type) {
            payCell = esc(PAYMENT_TYPE_LABELS[r.payment_type] || r.payment_type);
        } else if (r.kind === 'xray' && r.payment_type) {
            payCell = esc(XRAY_TYPE_LABELS[r.payment_type] || r.payment_type);
        }

        return '<tr class="ac-row-clickable ' + (grouped ? 'ac-group-child ' : '') + (newUids.has(r.uid) ? 'row-new' : '') + '" data-kind="' + esc(r.kind) + '" data-id="' + esc(r.id) + '">'
            + '<td><span class="kind-badge" style="background:' + km.bg + ';color:' + km.color + ';"><i class="fas ' + km.icon + '"></i> ' + esc(km.label) + '</span></td>'
            + '<td><strong>' + esc(r.patient_name || '—') + '</strong></td>'
            + '<td>' + esc(r.doctor_name || '—')
                // Filed for this doctor by someone else — an admin, say.
                + (r.made_by_name ? '<div class="ac-made-by" title="ئەم وەسڵە دکتۆر خۆی دروستی نەکردووە"><i class="fas fa-user-pen"></i> لەلایەن ' + esc(r.made_by_name) + '</div>' : '')
                + '</td>'
            + '<td dir="ltr" style="font-size:12px;color:#555;">' + esc(r.number || '—') + '</td>'
            + '<td style="font-weight:600;">' + nf(r.amount) + '</td>'
            + '<td>' + payCell + '</td>'
            + '<td dir="ltr" style="font-size:11px;color:#888;">' + esc(fmtDate(r.created_at)) + '</td>'
            + '<td><span class="status-chip ' + st[1] + '">' + esc(st[0]) + '</span>' + approvalTagHtml(r) + '</td>'
            + '<td>' + rowActionsHtml(r) + '</td>'
            + '</tr>';
    }

    /** The all-requests tab groups every case/request for one patient together.
     * Rows arrive in the order the server sorted them (newest first by default,
     * oldest first when the ⇅ button asks); the Map keeps first-seen patient
     * order, so a patient's group sits where their newest request does and the
     * rows inside it read the same way round as the rest of the list. */
    function groupByPatient(rows) {
        const groups = new Map();
        rows.forEach((row) => {
            const key = Number(row.patient_id) > 0 ? 'patient:' + row.patient_id : 'request:' + row.uid;
            if (!groups.has(key)) groups.set(key, []);
            groups.get(key).push(row);
        });
        return Array.from(groups.values());
    }

    function renderPatientGroups(rows, newUids) {
        let html = '';
        groupByPatient(rows).forEach((patientRows) => {
            const first = patientRows[0];
            // The header span still reads oldest → newest whichever way the rows
            // are sorted, so the two ends are taken from the values, not from
            // the first and last positions in the list.
            const times = patientRows.map((r) => r.created_at).filter(Boolean).sort();
            const dateRange = times.length > 1
                ? fmtDate(times[0]) + ' → ' + fmtDate(times[times.length - 1])
                : fmtDate(times[0] || first.created_at);
            html += '<tr class="ac-patient-group-row"><td colspan="9"><div class="ac-patient-group-head">'
                + '<div class="ac-patient-group-person"><i class="fas fa-user-circle"></i><strong>' + esc(first.patient_name || '—') + '</strong>'
                + (first.patient_phone ? '<span class="ac-patient-group-phone">' + esc(first.patient_phone) + '</span>' : '') + '</div>'
                + '<div class="ac-patient-group-meta"><span class="ac-patient-group-count">' + nf(patientRows.length) + ' داواکاری</span>'
                + '<span dir="ltr"><i class="fas fa-clock"></i> ' + esc(dateRange) + '</span></div>'
                + '</div></td></tr>';
            html += patientRows.map((row) => renderRow(row, newUids, true)).join('');
        });
        return html;
    }

    // ---------------------------------------------------------------
    // Pagination
    //
    // Client-side on purpose: one request already brings back the whole list
    // (the decided views are capped at 200 rows server-side), so paging here
    // costs no round trip and never re-orders anything — the SERVER decides the
    // order, this only decides where the page breaks fall.
    // ---------------------------------------------------------------

    const PAGE_SIZE = 25;

    /** Cut the current list into pages. On the grouped feed the unit is a
     *  PATIENT, not a row, so one patient's requests are never split across two
     *  pages; a patient with more requests than a page fills a page of their
     *  own rather than being cut in half. Clamps AC.page into range, which is
     *  what keeps the pager honest after rows appear or disappear under it. */
    function paginate(rows, grouped) {
        const units = grouped ? groupByPatient(rows) : rows.map((row) => [row]);
        const pages = [];
        let current = [];
        units.forEach((unit) => {
            if (current.length && current.length + unit.length > PAGE_SIZE) {
                pages.push(current);
                current = [];
            }
            current = current.concat(unit);
        });
        if (current.length || !pages.length) pages.push(current);

        const page = Math.min(Math.max(1, AC.page), pages.length);
        AC.page = page;
        return { rows: pages[page - 1] || [], page: page, pages: pages.length, total: rows.length };
    }

    function renderPager(state) {
        const bar = $('acPager');
        if (!bar) return;
        if (!state || !state.total) { bar.hidden = true; return; }
        bar.hidden = false;
        $('acPagerInfo').textContent = 'لاپەڕە ' + state.page + ' لە ' + state.pages
            + ' · ' + nf(state.total) + ' تۆمار';
        $('acPagerPrev').disabled = state.page <= 1;
        $('acPagerNext').disabled = state.page >= state.pages;
    }

    function renderRows(rows, newUids) {
        AC.rows = rows || [];
        const body = $('acBody');
        if (!body) return;
        if (!AC.rows.length) {
            body.innerHTML = '<tr><td colspan="9" class="ac-empty"><i class="fas fa-inbox"></i> هیچ نییە</td></tr>';
            renderPager(null);
            return;
        }
        const grouped = AC.scope === 'open' && AC.tab === 'all';
        const state = paginate(AC.rows, grouped);
        body.innerHTML = grouped
            ? renderPatientGroups(state.rows, newUids)
            : state.rows.map((r) => renderRow(r, newUids, false)).join('');
        renderPager(state);
    }

    /** Any change to WHAT is listed starts at page one again; a background
     *  refresh (the 8s poll, a completed action) deliberately does not, so the
     *  accountant is not yanked back to the top while they work. */
    function goToPage(page) {
        AC.page = Math.max(1, page);
        renderRows(AC.rows, new Set());
        const card = document.querySelector('.table-card');
        if (card) card.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }

    if ($('acPagerPrev')) $('acPagerPrev').addEventListener('click', () => goToPage(AC.page - 1));
    if ($('acPagerNext')) $('acPagerNext').addEventListener('click', () => goToPage(AC.page + 1));

    if ($('acBody')) {
        $('acBody').addEventListener('click', (e) => {
            const tr = e.target.closest('tr[data-kind]');
            if (!tr) return;
            if (e.target.closest('.ac-edit-case')) return;
            const advDel = e.target.closest('[data-adv-del]');
            if (advDel) { deleteAdvance(tr.dataset.id, advDel); return; }
            // دەستکاری / سڕینەوە open their own confirm dialog. The whole row is
            // clickable, so they have to be taken before the detail drawer.
            const revBtn = e.target.closest('[data-rev]');
            if (revBtn) {
                // An approved invoice / receipt / plan / X-ray is corrected in
                // place and never sent back to awaiting review. Its ✏️ opens
                // the record LOCKED with focus on «تەعدیل» — nothing becomes
                // editable until that button is pressed.
                if (revBtn.dataset.rev === 'reopen' && EDIT_IN_PLACE_KINDS.indexOf(tr.dataset.kind) !== -1) {
                    AC.focusEditBtn = true;
                    openDrawer(tr.dataset.kind, tr.dataset.id);
                    return;
                }
                openReversalDialog(revBtn.dataset.rev, tr.dataset.kind, tr.dataset.id);
                return;
            }
            openDrawer(tr.dataset.kind, tr.dataset.id);
        });
        const prefetchXrayRow = (e) => {
            const tr = e.target.closest('tr[data-kind="xray"]');
            if (!tr) return;
            fetchDrawerDetail('xray', tr.dataset.id).catch(() => {});
        };
        $('acBody').addEventListener('pointerover', prefetchXrayRow);
        $('acBody').addEventListener('focusin', prefetchXrayRow);
    }

    // ---------------------------------------------------------------
    // Polling
    // ---------------------------------------------------------------

    async function pollNewSince() {
        if (!AC.since) return;
        try {
            const res = await fetch(AC.listUrl + '?action=new_since&since=' + encodeURIComponent(AC.since));
            const data = await res.json();
            if (data.ok && data.count > 0) {
                AC.newTotal += data.count;
                announceNew();
                await load(new Set(data.uids || []));
            }
        } catch (e) { /* best-effort */ }
    }

    function startPolling() {
        if (AC.timer) return;
        AC.timer = setInterval(pollNewSince, AC.POLL_MS);
    }
    function stopPolling() {
        clearInterval(AC.timer);
        AC.timer = null;
    }
    document.addEventListener('visibilitychange', () => {
        if (document.hidden) { stopPolling(); }
        else { pollNewSince(); startPolling(); }
    });

    // ---------------------------------------------------------------
    // Filters
    // ---------------------------------------------------------------

    /** The open/history toggle only has meaning on the داواکاریەکان feed. */
    function syncScopeToggle() {
        const hide = AC.tab === 'approved' || AC.tab === 'advances';
        if ($('acScopeToggle')) $('acScopeToggle').style.display = hide ? 'none' : '';
        // 🤖 خۆکار / ✋ دەستی only means something on پەسەندکراو.
        if ($('acApprovalFilter')) $('acApprovalFilter').style.display = AC.tab === 'approved' ? '' : 'none';
    }

    /** ⇅ — the label says what the list is showing now, not what pressing it
     *  would do, which is how the scope toggle next to it already reads. */
    function renderSortToggle() {
        const label = $('acSortLabel');
        const icon = $('acSortIcon');
        const newestFirst = AC.sort !== 'asc';
        if (label) label.textContent = newestFirst ? 'نوێ ← کۆن' : 'کۆن ← نوێ';
        if (icon) icon.className = newestFirst ? 'fas fa-sort-amount-down' : 'fas fa-sort-amount-up';
    }

    if ($('acSortToggle')) {
        $('acSortToggle').addEventListener('click', () => {
            AC.sort = AC.sort === 'asc' ? 'desc' : 'asc';
            localStorage.setItem('acSort', AC.sort);
            renderSortToggle();
            AC.page = 1;
            load();
        });
    }

    /** Move to a tab without fetching — the caller decides when to load(), so
     *  a flow that changes tab AND then opens a row (see the reversal dialog's
     *  کردنەوە بۆ دەستکاری) does not fetch the list twice. */
    function activateTab(tab) {
        AC.tab = tab;
        AC.page = 1;
        if (AC.scope === 'history') {
            AC.scope = 'open';
            document.querySelectorAll('.scope-toggle button').forEach((b) => b.classList.toggle('active', b.dataset.scope === 'open'));
        }
        document.querySelectorAll('#acTabs .kind-tab').forEach((t) => t.classList.toggle('active', t.dataset.tab === tab));
        syncScopeToggle();
    }

    document.querySelectorAll('#acTabs .kind-tab').forEach((tab) => {
        tab.addEventListener('click', () => {
            activateTab(tab.dataset.tab);
            load();
        });
    });

    document.querySelectorAll('.scope-toggle button').forEach((btn) => {
        btn.addEventListener('click', () => {
            AC.scope = btn.dataset.scope;
            AC.page = 1;
            document.querySelectorAll('.scope-toggle button').forEach((b) => b.classList.remove('active'));
            btn.classList.add('active');
            document.querySelectorAll('#acTabs .kind-tab').forEach((t) => t.classList.toggle('active', AC.scope === 'open' && t.dataset.tab === AC.tab));
            load();
        });
    });

    // Every filter narrows WHAT is listed, so each one starts again at page 1 —
    // page 3 of the old list means nothing once the list has changed.
    let acSearchTimer = null;
    if ($('acSearch')) {
        $('acSearch').addEventListener('input', function () {
            clearTimeout(acSearchTimer);
            acSearchTimer = setTimeout(() => { AC.q = this.value.trim(); AC.page = 1; load(); }, 300);
        });
    }
    if ($('acKindFilter')) $('acKindFilter').addEventListener('change', function () { AC.kind = this.value; AC.page = 1; load(); });
    if ($('acApprovalFilter')) $('acApprovalFilter').addEventListener('change', function () { AC.approval = this.value; AC.page = 1; load(); });
    if ($('acDoctorFilter')) $('acDoctorFilter').addEventListener('change', function () { AC.doctorId = this.value; AC.page = 1; load(); });
    if ($('acDateFrom')) $('acDateFrom').addEventListener('change', function () { AC.dateFrom = this.value; AC.page = 1; load(); });
    if ($('acDateTo')) $('acDateTo').addEventListener('change', function () { AC.dateTo = this.value; AC.page = 1; load(); });

    // ---------------------------------------------------------------
    // Actions (POST api/finance-actions.php) — double-submit guard: the
    // triggering button is disabled + swapped to a spinner for the
    // duration of the fetch, same pattern cases/view.php uses.
    // ---------------------------------------------------------------

    async function postFinanceAction(action, params, btn) {
        if (btn) {
            btn.disabled = true;
            btn.dataset.origHtml = btn.dataset.origHtml || btn.innerHTML;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> چاوەڕوانبە...';
        }
        const body = new URLSearchParams();
        body.set('action', action);
        Object.keys(params).forEach((k) => body.append(k, params[k] === null || params[k] === undefined ? '' : params[k]));
        try {
            const res = await fetch(AC.actionsUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: body.toString(),
            });
            return await res.json();
        } catch (e) {
            return { ok: false, error: 'کێشەی پەیوەندی ڕوویدا' };
        } finally {
            if (btn) {
                btn.disabled = false;
                if (btn.dataset.origHtml) { btn.innerHTML = btn.dataset.origHtml; delete btn.dataset.origHtml; }
            }
        }
    }

    function showDrawerErr(msg) {
        const el = $('acDrawerErr');
        if (el) { el.textContent = msg; el.style.display = 'block'; }
        else toast('error', msg);
    }

    /** ok:true -> toast + (close, or reopen the same row for an in-place
     *  edit) + refetch list/counts. stale:true -> the "already acted on"
     *  toast, close, refetch. Plain error -> show inline, keep the drawer
     *  open so the accountant can fix and retry. */
    function handleActionResult(res, keepOpen) {
        if (res && res.ok) {
            toast('success', 'سەرکەوتوو بوو');
            if (AC.currentDrawer && AC.currentDrawer.kind === 'xray') {
                xrayDetailCache.delete(String(AC.currentDrawer.id));
            }
            if (keepOpen && AC.currentDrawer) {
                openDrawer(AC.currentDrawer.kind, AC.currentDrawer.id);
            } else {
                closeDrawer();
            }
            load();
            return;
        }
        if (res && res.stale) {
            toast('warning', 'پێشتر جێبەجێکراوە');
            if (AC.currentDrawer && AC.currentDrawer.kind === 'xray') {
                xrayDetailCache.delete(String(AC.currentDrawer.id));
            }
            closeDrawer();
            load();
            return;
        }
        showDrawerErr((res && res.error) || 'کردارەکە سەرنەکەوت');
    }

    // ---------------------------------------------------------------
    // Print dialog
    // ---------------------------------------------------------------
    // Every printable document in this page — invoice پسوولە, payment قەبز,
    // arch work وەسڵ, X-ray فۆڕم — opens here instead of in a new tab, so the
    // accountant never leaves the queue and never has to hunt a tab back.
    // `url` must point at a receipt page that understands ?embed=1 (it drops
    // its own toolbar and page chrome); printing then calls print() on the
    // frame's own window, which prints only the sheet.

    let printPreviousFocus = null;
    let printDialogUrl = '';

    function printDialogOpen() {
        const overlay = $('acPrintOverlay');
        return !!(overlay && overlay.classList.contains('open'));
    }

    function openPrintDialog(url, title, autoPrint) {
        const overlay = $('acPrintOverlay');
        const frame = $('acPrintFrame');
        // No dialog in the DOM (an older cached page) — the tab is still
        // better than nothing.
        if (!overlay || !frame) { window.open(url, '_blank', 'noopener'); return; }

        if (!printDialogOpen()) printPreviousFocus = document.activeElement;
        printDialogUrl = url;
        if ($('acPrintTitle')) {
            $('acPrintTitle').innerHTML = '<i class="fas fa-print"></i> ' + esc(title || 'چاپکردن');
        }
        // The escape-hatch link is the same document standalone — not embedded,
        // and not throwing a print dialog at whoever only wanted to look at it.
        if ($('acPrintNewTab')) {
            $('acPrintNewTab').href = url.replace(/&autoprint=1\b/, '&autoprint=0');
        }

        const loading = $('acPrintLoading');
        if (loading) loading.hidden = false;
        frame.addEventListener('load', function onLoad() {
            frame.removeEventListener('load', onLoad);
            if (loading) loading.hidden = true;
            // "پەسەندکردن و چاپکردن" — the sheet is on screen, now raise the
            // browser's print dialog for it. Embedded pages never auto-print
            // themselves, so this is the only trigger.
            if (autoPrint) setTimeout(printDialogSheet, 150);
        });
        // Cache-buster: the figures may have changed a second ago.
        frame.src = url + '&embed=1&t=' + Date.now();

        overlay.classList.add('open');
        overlay.setAttribute('aria-hidden', 'false');
        requestAnimationFrame(() => { if ($('acPrintDo')) $('acPrintDo').focus(); });
    }

    function closePrintDialog() {
        if (!printDialogOpen()) return false;
        const overlay = $('acPrintOverlay');
        overlay.classList.remove('open');
        overlay.setAttribute('aria-hidden', 'true');
        // Drop the document so the next open never flashes the previous one.
        if ($('acPrintFrame')) $('acPrintFrame').src = 'about:blank';
        printDialogUrl = '';
        if (printPreviousFocus && typeof printPreviousFocus.focus === 'function') {
            printPreviousFocus.focus();
        }
        printPreviousFocus = null;
        return true;
    }

    function printDialogSheet() {
        const frame = $('acPrintFrame');
        const win = frame && frame.contentWindow;
        if (!win) { toast('error', 'بەڵگەنامەکە هێشتا ئامادە نییە'); return; }
        try {
            // focus() first: without it Safari prints the parent page instead.
            win.focus();
            win.print();
        } catch (e) {
            // A browser that refuses to print a frame — fall back to the
            // standalone page. The raw url keeps its ?autoprint=1, so the tab
            // opens the print dialog itself and the click still prints.
            if (printDialogUrl) window.open(printDialogUrl, '_blank', 'noopener');
        }
    }

    if ($('acPrintDo')) $('acPrintDo').addEventListener('click', printDialogSheet);
    if ($('acPrintClose')) $('acPrintClose').addEventListener('click', closePrintDialog);
    if ($('acPrintOverlay')) {
        $('acPrintOverlay').addEventListener('click', (e) => {
            if (e.target.id === 'acPrintOverlay') closePrintDialog();
        });
    }

    // ---------------------------------------------------------------
    // Drawer
    // ---------------------------------------------------------------

    function drawerHeader(patient, patientId) {
        const name = patient ? (patient.full_name || '—') : '—';
        const phone = patient ? (patient.phone || '') : '';
        const code = patient ? (patient.patient_code || '') : '';
        let html = '<div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:8px;margin-bottom:12px;">'
            + '<div><strong style="font-size:14px;">' + esc(name) + '</strong> <span style="color:#999;font-size:11px;">' + esc(code) + '</span>'
            + '<div style="font-size:11px;color:#888;">' + esc(phone) + '</div></div>';
        if (patientId) {
            html += '<a class="ac-statement-link" href="' + AC.statementUrl + '?patient_id=' + encodeURIComponent(patientId) + '" target="_blank">'
                + '<i class="fas fa-file-invoice-dollar"></i> کشف حساب</a>';
        }
        html += '</div>';
        return html;
    }

    function headGrid(pairs) {
        let html = '<div class="ac-head-grid">';
        pairs.forEach(([lbl, val]) => {
            html += '<div class="ac-head-item"><div class="lbl">' + esc(lbl) + '</div><div class="val">' + esc(val === null || val === undefined || val === '' ? '—' : val) + '</div></div>';
        });
        html += '</div>';
        return html;
    }

    // `live` (invoice modal, invoice still awaiting review only) stamps an id on
    // each number so wireInvoicePane()'s recompute() can keep the chips in step
    // with the داشکاندن / وەرگیراو boxes — see updateCaseChips() there for why
    // the numbers need adjusting at all. Every other caller passes nothing and
    // renders exactly the static block it always did.
    function finBlock(summary, live) {
        if (!summary) return '';
        const boxes = [
            ['کۆی پسووڵە', summary.total_invoiced, '#deecf9', '#0063b1', 'Invoiced'],
            ['داشکاندن', summary.total_discount, '#fff4ce', '#8a5a00', 'Discount'],
            ['پەسەندکراو', summary.approved, '#e6f2fd', '#075f9d', 'Approved'],
            ['دراو', summary.paid, '#dff6dd', '#107c10', 'Paid'],
            ['ماوە', summary.balance, '#fde7e9', '#c50f1f', 'Balance'],
        ];
        let html = '<div class="ac-section-title"><i class="fas fa-chart-pie"></i> پوختەی دارایی کەیس</div><div class="ac-fin">';
        boxes.forEach(([l, v, bg, c, key]) => {
            html += '<div class="ac-fin-box" style="background:' + bg + ';">'
                + '<div class="n"' + (live ? ' id="acFin' + key + '"' : '') + ' style="color:' + c + ';">' + nf(v || 0) + '</div>'
                + '<div class="l" style="color:' + c + ';">' + esc(l) + '</div></div>';
        });
        html += '</div>';
        if (live) {
            html += '<div class="ac-fin-hint"><i class="fas fa-hourglass-half"></i> '
                + 'ئەم پسووڵەیە هێشتا پەسەند نەکراوە — لە ژێر «دراو» و «ماوە» بە مەرجەکانی سەر ئەم شاشەیە دەژمێردرێت، '
                + 'بەڵام «پەسەندکراو» تەنها پسووڵە پەسەندکراوەکان لەخۆ دەگرێت.</div>';
        }
        return html;
    }

    // ---------------------------------------------------------------
    // Invoice drawer — wide modal, receipt-pane look ported from
    // cases/view.php's doctor-side tab (cases/partials/invoice_pane.php +
    // assets/css/invoice-pane.css: .vt-head/.rcpt-table/.tooth-cross/
    // .vt-foot/.vt-total-box classes, reused verbatim so a receipt reads the
    // same on both sides of the desk). Rebuilt here from the detail() JSON,
    // the same way every other kind's drawer already works, rather than
    // server-rendering the PHP partial — this file owns the accountant-only
    // editable bits (داشکاندن / وەرگیراو), which the doctor-side partial
    // does not have.
    //
    // IMPORTANT: this does NOT reuse assets/js/invoice-pane.js. That file
    // posts to cases/view.php's own inline AJAX handler using ITS action
    // names (set_invoice_discount / set_invoice_paid) and its own FIFO
    // back-solve semantics for وەرگیراو (the typed figure is a target the
    // server works backwards from). Here وەرگیراو posts the EXISTING
    // api/finance-actions.php `receive_payment` action instead — a plain
    // "receive this much against this invoice" allocation — so the two
    // money boxes look identical but are wired to different, both already
    // shipped, endpoints. Business logic is unchanged either way.
    // ---------------------------------------------------------------

    // Invoice-only header subrow — patient identity (right) + کشف حساب link
    // and مەبەست line (left) — rendered into the persistent #acDrawerSub slot
    // (see accountant/approval-center.php's drawer markup) so it sits beside
    // the title row, OUTSIDE the scrolling #acDrawerBody. It used to be the
    // first thing inside that scrolling body (built by the generic
    // drawerHeader() below, still used unchanged by every other kind here),
    // which is what produced the reported title/patient-info/کشف-حساب
    // collision in the wide modal — this is a layout fix only, same fields.
    function invoiceSubheaderHtml(patient, patientId, intent) {
        const name = patient ? (patient.full_name || '—') : '—';
        const phone = patient ? (patient.phone || '') : '';
        const code = patient ? (patient.patient_code || '') : '';

        let html = '<div class="ac-dsub-patient"><strong>' + esc(name) + '</strong>'
            + (code ? ' <span class="ac-dsub-code">' + esc(code) + '</span>' : '')
            + (phone ? '<div class="ac-dsub-phone">' + esc(phone) + '</div>' : '')
            + '</div>';

        html += '<div class="ac-dsub-side">';
        if (patientId) {
            html += '<a class="ac-statement-link" href="' + AC.statementUrl + '?patient_id=' + encodeURIComponent(patientId) + '" target="_blank">'
                + '<i class="fas fa-file-invoice-dollar"></i> کشف حساب</a>';
        }
        if (intent && intent.payment_intent) {
            html += '<div class="ac-dsub-intent">مەبەست: ' + esc(INTENT_LABELS[intent.payment_intent] || intent.payment_intent);
            if (intent.payment_intent === 'debt') {
                html += ' — چاوەڕوان: ' + esc(intent.promised_date || '—') + (intent.promised_amount !== null && intent.promised_amount !== undefined ? ' · ' + nf(intent.promised_amount) : '');
            }
            html += '</div>';
        }
        html += '</div>';

        return html;
    }

    function ivBadge(cls, label, icon, title) {
        return '<span class="vt-head-badge ' + cls + '"' + (title ? ' title="' + esc(title) + '"' : '') + '>'
            + (icon ? '<i class="fas ' + icon + '"></i> ' : '') + esc(label) + '</span>';
    }

    // Ported 1:1 from cases/partials/invoice_pane.php's badge tree (:72-95)
    // so a receipt shows the identical badge in the identical state on both
    // screens, quirks included (e.g. an approved-but-not-fully-paid invoice
    // still reads "لای ژمێریار" there today — not something introduced here).
    function invoiceHeadBadges(inv, editableApproved) {
        const accountingStatus = inv.accounting_status;
        const isDraft = accountingStatus === 'draft' || accountingStatus === 'returned';
        // An approved invoice the admin switch lets us correct in place is not
        // locked — saying قوفڵدراو over live money boxes would be a lie.
        const lockBadge = editableApproved
            ? ivBadge('is-edit-open', 'دەستکاری دەکرێت', 'fa-pen', 'پەسەندکراو دەمێنێتەوە و بەرواری پەسەندکردنەکەی ناگۆڕێت')
            : ivBadge('is-locked', 'قوفڵدراو', 'fa-lock', 'دوای ناردن ناتوانرێت بگۆڕدرێت');
        let html = '';
        if (accountingStatus === 'returned') {
            html += ivBadge('is-part', 'گەڕێندراوەتەوە بۆ ڕاستکردنەوە', 'fa-rotate-left');
            if (inv.return_reason) {
                const short = String(inv.return_reason).length > 48 ? String(inv.return_reason).slice(0, 48) + '…' : inv.return_reason;
                html += ivBadge('is-pending', short, null, inv.return_reason);
            }
        } else if (isDraft) {
            html += ivBadge('is-draft', 'نەنێردراو', 'fa-pen-ruler');
        } else if (inv.status === 'processed') {
            html += ivBadge('is-done', 'پارە وەرگیراوە', 'fa-check-double');
            html += lockBadge;
        } else {
            html += ivBadge('is-sent', 'لای ژمێریار', 'fa-paper-plane');
            html += lockBadge;
            const remaining = Number(inv.remaining_amount) || 0;
            html += '<span class="vt-head-badge is-part" id="acInvHeadRemaining"' + (remaining > 0.009 ? '' : ' hidden') + '>ماوە ' + nf(remaining) + '</span>';
            const pending = Number(inv.pending_amount) || 0;
            if (pending > 0.009) html += ivBadge('is-pending', 'چاوەڕوانی ژمێریار ' + nf(pending), 'fa-hourglass-half');
        }
        return html;
    }

    // Match cases/partials/invoice_pane.php exactly: equal treatment names at
    // the same unit price share one receipt row, with their Palmer tooth labels
    // collected into the four quadrants. The accountant must review the same
    // document the doctor sent, not a differently expanded representation.
    function receiptTreatmentGroups(treatments) {
        const groups = new Map();
        const quadrants = {
            1: 'upper_right', 2: 'upper_left', 3: 'lower_left', 4: 'lower_right',
            5: 'upper_right', 6: 'upper_left', 7: 'lower_left', 8: 'lower_right'
        };
        const primaryPalmer = ['', 'A', 'B', 'C', 'D', 'E'];

        treatments.forEach(t => {
            const unitPrice = Number(t.unit_price) || 0;
            const archType = ['upper', 'lower'].includes(t.arch_type) ? t.arch_type : 'none';
            // Any treatment may be saved with or without a tooth; the row decides.
            const requiresTooth = archType === 'none' && Number(t.tooth_number) > 0;
            const key = String(t.treatment_id || 0) + '\u0000' + String(t.treatment_name || '')
                + '\u0000' + unitPrice + '\u0000' + archType + '\u0000' + (requiresTooth ? 'tooth' : 'service');
            if (!groups.has(key)) {
                groups.set(key, {
                    treatment_name: t.treatment_name || '',
                    unit_price: unitPrice,
                    arch_type: archType,
                    requires_tooth: requiresTooth,
                    upper_right: [], upper_left: [], lower_right: [], lower_left: [],
                    total_quantity: 0,
                    total_price: 0
                });
            }

            const group = groups.get(key);
            const fdi = Number(t.tooth_number);
            if (requiresTooth && Number.isInteger(fdi) && fdi > 0) {
                const fdiQuadrant = Math.floor(fdi / 10);
                const position = fdi % 10;
                const quadrant = quadrants[fdiQuadrant];
                const palmer = fdiQuadrant >= 5 ? primaryPalmer[position] : String(position);
                if (quadrant && palmer) group[quadrant].push(palmer);
            }

            group.total_quantity += Number(t.quantity) || 0;
            group.total_price += Number(t.final_price) || 0;
        });

        return Array.from(groups.values());
    }

    function groupedTeethCell(group) {
        if (group.arch_type === 'upper') {
            return '<span class="rt-location-badge is-upper"><i class="fas fa-teeth-open"></i> ماکزیلا / سەرەوە</span>';
        }
        if (group.arch_type === 'lower') {
            return '<span class="rt-location-badge is-lower"><i class="fas fa-teeth-open"></i> ماندیبل / خوارەوە</span>';
        }
        if (group.requires_tooth === false) {
            return '<span class="rt-location-badge is-service"><i class="fas fa-briefcase-medical"></i> بێ ژمارەی ددان</span>';
        }
        const hasTeeth = group.upper_right.length || group.upper_left.length
            || group.lower_right.length || group.lower_left.length;
        if (!hasTeeth) return '';
        return '<div class="tooth-cross">'
            + '<span class="tc-ur">' + esc(group.upper_right.join(',')) + '</span>'
            + '<span class="tc-ul">' + esc(group.upper_left.join(',')) + '</span>'
            + '<span class="tc-lr">' + esc(group.lower_right.join(',')) + '</span>'
            + '<span class="tc-ll">' + esc(group.lower_left.join(',')) + '</span>'
            + '</div>';
    }

    const invoiceAdvanceDrafts = new Map();

    function invoiceAdvanceDraft(inv) {
        return inv.accounting_status === 'sent' ? invoiceAdvanceDrafts.get(String(inv.id)) : null;
    }

    function inlineAdvanceHtml(id, available, amount) {
        if (!(available > 0.009)) return '';
        return '<div class="ac-inline-advance" data-inline-advance="' + id + '">'
            + '<label for="' + id + 'Amount">ئامانەت <small>(بەردەست: ' + nf(available) + ')</small></label>'
            + '<div class="ac-inline-advance-controls"><output id="' + id + 'Amount" data-amount="' + amount + '" aria-label="بڕی ئامانەت">' + nf(amount) + '</output>'
            + '<button type="button" class="btn" id="' + id + 'Use">بخە وەرگیراو</button>'
            + '<button type="button" class="btn" id="' + id + 'Clear" hidden title="لابردنی ئامانەتی هەڵبژێردراو">×</button></div>'
            + '<div class="ac-inline-advance-note" id="' + id + 'Note"></div></div>';
    }

    // Existing payment allocations are included in the displayed received
    // total, but only new invoice cash may be saved back to the invoice.
    function invoiceReceivedAmounts(inv) {
        const declared = Math.max(0, Number(inv.declared_received_amount || inv.draft_received_amount || 0));
        const prior = Math.max(0, Number(inv.prior_paid_amount || 0));
        const draft = invoiceAdvanceDraft(inv);
        const total = inv.accounting_status === 'approved'
            ? Math.max(0, Number(inv.paid_amount || 0) - prior) : (draft ? draft.received : declared);
        return { declared, prior, total, allocated: Math.max(0, total - declared) };
    }

    /** @param {null|'locked'|'editing'} approvedEditState */
    function invoicePaneHtml(inv, treatments, approvedEditState, advanceAvailable = 0) {
        const treatmentGroups = receiptTreatmentGroups(treatments);
        const editableApproved = approvedEditState === 'editing';
        let html = '<div class="vt-pane" style="margin-top:2px;">';

        let editBtn = '';
        if (approvedEditState === 'locked') {
            editBtn = '<button type="button" class="vt-btn vt-btn-edit" id="acInvEditBtn" title="کردنەوەی خانەکان بۆ دەستکاری — وەسڵەکە پەسەندکراو دەمێنێتەوە">'
                + '<i class="fas fa-pen"></i> تەعدیل</button>';
        } else if (approvedEditState === 'editing') {
            editBtn = '<button type="button" class="vt-btn vt-btn-done" id="acInvEditBtn" title="تەواوبوونی دەستکاری و قوفڵکردنەوەی خانەکان">'
                + '<i class="fas fa-check"></i> تەواو</button>';
        }

        html += '<div class="vt-head vt-head--' + esc(inv.status || 'sent') + '">'
            + '<div class="vt-head-main">'
            + '<span class="vt-head-no" dir="ltr">' + esc(inv.invoice_no || '') + '</span>'
            + '<span class="vt-head-date"><i class="fas fa-calendar-day"></i> ' + esc(inv.work_date || '') + '</span>'
            + invoiceHeadBadges(inv, editableApproved)
            + '</div>'
            + '<div class="vt-head-actions">'
            + '<span class="vt-head-total">' + nf(inv.total_amount) + '</span>'
            + editBtn
            + '<button type="button" class="vt-btn" id="acInvPrintBtn" title="چاپکردنی ئەم وەسڵە"><i class="fas fa-print"></i> چاپ</button>'
            + '</div></div>';

        html += '<div class="table-container"><table class="table rcpt-table"><thead><tr>'
            + '<th class="rt-c-no">ژ</th>'
            + '<th class="rt-c-desc">ناوەڕۆک<span class="en">Description</span></th>'
            + '<th class="rt-c-teeth">چارتی ددان<span class="en">Teeth</span></th>'
            + '<th class="rt-c-qty">ژمارە<span class="en">Quantity</span></th>'
            + '<th class="rt-c-unit">نرخی تاک<span class="en">Unit Price</span></th>'
            + '<th class="rt-c-total">نرخی کۆ<span class="en">Total Price</span></th>'
            + '<th class="rt-c-act">کردارەکان</th>'
            + '</tr></thead><tbody>';
        if (treatmentGroups.length) {
            treatmentGroups.forEach((group, i) => {
                html += '<tr>'
                    + '<td class="rt-c-no">' + (i + 1) + '</td>'
                    + '<td class="rt-c-desc">' + esc(group.treatment_name) + '</td>'
                    + '<td class="rt-c-teeth">' + groupedTeethCell(group) + '</td>'
                    + '<td class="rt-c-qty">' + nf(group.total_quantity) + '</td>'
                    + '<td class="rt-c-unit">' + nf(group.unit_price) + '</td>'
                    + '<td class="rt-c-total">' + nf(group.total_price) + '</td>'
                    + '<td class="rt-c-act"><i class="fas fa-lock" style="color:#a9b6c3;" title="وەسڵی بۆ ژمێریار نێردراوە"></i></td>'
                    + '</tr>';
            });

            // The doctor-side receipt is a six-line paper pad. Keep those
            // ruled blank rows here too so both tables have the same shape.
            for (let rowNo = treatmentGroups.length + 1; rowNo <= 6; rowNo += 1) {
                html += '<tr class="rt-blank-row">'
                    + '<td class="rt-c-no">' + rowNo + '</td>'
                    + '<td class="rt-c-desc">&nbsp;</td>'
                    + '<td class="rt-c-teeth">&nbsp;</td>'
                    + '<td class="rt-c-qty">&nbsp;</td>'
                    + '<td class="rt-c-unit">&nbsp;</td>'
                    + '<td class="rt-c-total">&nbsp;</td>'
                    + '<td class="rt-c-act">&nbsp;</td>'
                    + '</tr>';
            }
        } else {
            html += '<tr><td colspan="7" class="ac-empty-drawer">چارەسەر نییە</td></tr>';
        }
        html += '</tbody></table></div>';

        const discMode = inv.discount_mode === 'percent' ? 'percent' : 'amount';
        const discValue = Number(inv.discount_value || 0);
        const discShown = discMode === 'percent' ? discValue : Number(inv.discount_amount || 0);
        // Financial terms are editable while the invoice awaits review, and on
        // an APPROVED invoice when the admin switch allows correcting it in
        // place (data.can_edit_approved) — it stays approved either way.
        const isApproved = inv.accounting_status === 'approved';
        const canEditMoney = inv.accounting_status === 'sent' || (isApproved && !!editableApproved);
        const alreadyPaid = Number(inv.paid_amount || 0);
        const received = invoiceReceivedAmounts(inv);
        const bundledReceived = received.declared;
        // دراوی پێشوو — paid before the system (historical cases). Settles the
        // invoice but is not cash; an approved paid_amount already includes it.
        const priorPaid = Math.max(0, Number(inv.prior_paid_amount || 0));
        const netAmount = Number(inv.net_amount || 0);
        const displayPaid = isApproved ? alreadyPaid : bundledReceived + priorPaid;
        const remainingCap = Math.max(0, netAmount - displayPaid);
        // Show every actual receipt in one received total. Allocated payments
        // remain separate records and cannot be edited as new invoice cash.

        // RTL two-column footer: right column (.vt-foot-side, first in DOM)
        // is تێبینی/تێبینی ژمێریاری; left column (.vt-foot-totals) is the
        // totals stack. Page-scoped CSS in approval-center.php constrains
        // .vt-foot-totals's width instead of letting the shared
        // invoice-pane.css flex-basis math stretch it across half this
        // (up to 1100px) modal.
        html += '<div class="vt-foot">'
            + '<div class="vt-foot-side">'
            + '<div class="ac-inv-note"><span class="ac-inv-note-label">تێبینی :</span> '
            + '<span class="ac-inv-note-val">' + (inv.notes ? esc(inv.notes) : '—') + '</span></div>';

        // Private doctor -> accounting remark. Rendered only when the doctor
        // actually wrote one, so the pane does not grow an empty box on the
        // majority of invoices. Visually distinct from the invoice note above
        // because this one is internal and never appears on a printed receipt.
        if (inv.doctor_note_to_accounting) {
            html += '<div class="ac-inv-docnote">'
                + '<span class="ac-inv-docnote-label"><i class="fas fa-user-md"></i> تێبینی دکتۆر بۆ ژمێریار :</span> '
                + '<span class="ac-inv-docnote-val">' + esc(inv.doctor_note_to_accounting) + '</span></div>';
        }

        // canEditMoney only, matching the original (pre-polish) behavior —
        // no read-only display of accounting_notes otherwise (review finding
        // #2: showing it unconditionally was an undisclosed behavior change).
        if (canEditMoney) {
            html += '<div class="ac-inv-acc-notes"><label for="acDiscNotes">تێبینی ژمێریاری <span class="ac-inv-acc-notes-hint">(لەگەڵ داشکاندن پاشەکەوت دەکرێت)</span></label>'
                + '<textarea id="acDiscNotes" rows="5">' + esc(inv.accounting_notes || '') + '</textarea></div>';
        }

        html += '</div>'; // .vt-foot-side

        html += '<div class="vt-foot-totals">'
            + '<div class="vt-total-row"><span class="vt-total-label">کۆی گشتی :</span>'
            + '<span class="vt-total-box">' + nf(inv.total_amount) + '</span></div>';

        if (canEditMoney) {
            html += '<div class="vt-total-row"><span class="vt-total-label">داشکاندن :</span>'
                + '<span class="vt-total-box vt-disc-box' + (discMode === 'percent' ? ' is-percent' : '') + '" id="acDiscBox" data-total="' + Number(inv.total_amount || 0) + '" data-mode="' + discMode + '">'
                + '<input type="number" class="vt-disc-input" id="acDiscInput" min="0" step="0.01" value="' + (discShown > 0.009 ? discShown : '') + '" placeholder="0">'
                + '<button type="button" class="vt-disc-mode" id="acDiscModeBtn">' + (discMode === 'percent' ? '%' : 'IQD') + '</button>'
                + '</span></div>';
        } else if (Number(inv.discount_amount || 0) > 0) {
            html += '<div class="vt-total-row"><span class="vt-total-label">داشکاندن' + (discMode === 'percent' ? ' (' + discValue + '%)' : '') + ' :</span>'
                + '<span class="vt-total-box is-cut">' + nf(inv.discount_amount) + '</span></div>';
        }

        html += '<div class="vt-total-row"><span class="vt-total-label">پوختە :</span>'
            + '<span class="vt-total-box" id="acInvNetBox">' + nf(netAmount) + '</span></div>';

        // The accountant settles the invoice, so while it is awaiting review
        // they own the received figure. It used to be read-only here and only
        // editable by the doctor before sending, which left the accountant
        // unable to correct an invoice that arrived saying "paid 0".
        if (canEditMoney) {
            html += '<div class="vt-total-row"><span class="vt-total-label">وەرگیراو :</span>'
                + '<span class="vt-total-box vt-disc-box">'
                + '<input type="number" class="vt-disc-input" id="acRecvInput" min="' + received.allocated + '" step="0.01" '
                + 'max="' + Math.max(0, netAmount - priorPaid) + '" value="' + (received.total > 0.009 ? received.total : '') + '" placeholder="0">'
                + '</span></div>';
        } else {
            html += '<div class="vt-total-row"><span class="vt-total-label">وەرگیراو :</span>'
                + '<span class="vt-total-box is-paid" id="acInvPaidBox">' + nf(received.total) + '</span></div>';
        }
        if (inv.accounting_status === 'sent') {
            html += inlineAdvanceHtml('acInvoiceCustody', advanceAvailable, Math.min(advanceAvailable, Math.max(0, netAmount - priorPaid)));
        }
        if (Number(inv.advance_received_amount || 0) > 0.009) {
            html += '<div class="vt-received-source">لەوانە ' + nf(inv.advance_received_amount)
                + ' لە ئەمانەت گوازراوەتەوە</div>';
        }

        if (priorPaid > 0.009) {
            html += '<div class="vt-total-row"><span class="vt-total-label is-prior">دراوی پێشوو :</span>'
                + '<span class="vt-total-box is-prior">' + nf(priorPaid) + '</span>'
                + '<span class="vt-prior-note" title="پارەی پێش سیستەم — ناچێتە ناو داهاتی سندوق"><i class="fas fa-history"></i> کۆن</span></div>';
        }

        html += '<div class="vt-total-row"><span class="vt-total-label">ماوە :</span>'
            + '<span class="vt-total-box' + (remainingCap > 0.009 ? ' is-due' : '') + '" id="acInvRemainingBox">' + nf(remainingCap) + '</span></div>';

        // Cash / debt / installment. "نەقد" is disabled whenever a balance
        // remains: the server derives the same rule, so offering it here would
        // only let the accountant pick something that silently becomes debt.
        if (canEditMoney) {
            const curIntent = inv.payment_intent || (remainingCap > 0.009 ? 'debt' : 'cash');
            const cashBlocked = remainingCap > 0.009;
            html += '<div class="vt-total-row"><span class="vt-total-label">جۆری پارەدان :</span>'
                + '<span class="vt-total-box vt-disc-box">'
                + '<select id="acIntentSel" class="vt-disc-input">'
                + '<option value="cash"' + (curIntent === 'cash' ? ' selected' : '') + (cashBlocked ? ' disabled' : '') + '>نەقد</option>'
                + '<option value="debt"' + (curIntent === 'debt' ? ' selected' : '') + '>قەرز</option>'
                + '<option value="installment"' + (curIntent === 'installment' ? ' selected' : '') + '>قیست</option>'
                + '</select></span></div>';
            if (cashBlocked) {
                html += '<div class="vt-total-row"><span class="ac-intent-hint">'
                    + 'ماوە هەیە — ناتوانرێت نەقد بێت</span></div>';
            }
        } else if (inv.payment_intent) {
            const labels = { cash: 'نەقد', debt: 'قەرز', installment: 'قیست' };
            html += '<div class="vt-total-row"><span class="vt-total-label">جۆری پارەدان :</span>'
                + '<span class="vt-total-box">' + esc(labels[inv.payment_intent] || inv.payment_intent) + '</span></div>';
        }

        html += '</div>'; // .vt-foot-totals
        html += '</div>'; // .vt-foot

        html += '</div>'; // .vt-pane
        return html;
    }

    // ---------------------------------------------------------------
    // وەرگرتنی پارە confirm — a static fw-modal <dialog> (markup in
    // accountant/approval-center.php, styled with the .fw-modal shell copied
    // from patients/statement.php:169/171 — the codebase's actual convention
    // for a money-action confirm, e.g. its reverse-payment dialog at
    // statement.php:540-549). Wired ONCE here, not per drawer render —
    // wireInvoicePane() below just calls openReceiveConfirm() with the
    // current invoice's context each time the trigger button is clicked.
    // ---------------------------------------------------------------

    let RECV_CTX = null; // { caseId, invoiceId, invoiceNo, amount, keyFor }

    function receiveConfirmErr(msg) {
        const el = $('acReceiveConfirmErr');
        if (el) { el.textContent = msg || ''; el.style.display = msg ? 'block' : 'none'; }
    }

    function openReceiveConfirm(ctx) {
        RECV_CTX = ctx;
        const dlg = $('acReceiveConfirmDialog');
        if (!dlg || typeof dlg.showModal !== 'function') return;
        if ($('acReceiveConfirmAmount')) $('acReceiveConfirmAmount').textContent = nf(ctx.amount);
        if ($('acReceiveConfirmPatient')) $('acReceiveConfirmPatient').textContent = ctx.patientName || '—';
        if ($('acReceiveConfirmInvoice')) $('acReceiveConfirmInvoice').textContent = ctx.invoiceNo || '';
        if ($('acReceiveConfirmMethod')) $('acReceiveConfirmMethod').value = 'cash';
        if ($('acReceiveConfirmNotes')) $('acReceiveConfirmNotes').value = '';
        receiveConfirmErr('');
        dlg.showModal();
    }

    function closeReceiveConfirm() {
        const dlg = $('acReceiveConfirmDialog');
        if (dlg && dlg.open) dlg.close();
        RECV_CTX = null;
    }

    if ($('acReceiveConfirmClose')) $('acReceiveConfirmClose').addEventListener('click', closeReceiveConfirm);
    if ($('acReceiveConfirmCancel')) $('acReceiveConfirmCancel').addEventListener('click', closeReceiveConfirm);
    if ($('acReceiveConfirmDialog')) {
        // Native Esc-close (the <dialog> 'cancel' event) — just drop the context.
        $('acReceiveConfirmDialog').addEventListener('cancel', () => { RECV_CTX = null; });
    }
    if ($('acReceiveConfirmOk')) {
        $('acReceiveConfirmOk').addEventListener('click', async () => {
            const ctx = RECV_CTX;
            if (!ctx) return;
            receiveConfirmErr('');
            const method = $('acReceiveConfirmMethod') ? $('acReceiveConfirmMethod').value : 'cash';
            const notes = $('acReceiveConfirmNotes') ? $('acReceiveConfirmNotes').value : '';
            // Amount-aware idempotency key: ctx.keyFor(amount) returns the SAME
            // token for a retry of this identical amount (so a genuine
            // double-submit — double-click, slow network, this very button
            // clicked twice — dedupes server-side via Payment::findIdempotent())
            // but mints a fresh one the moment the amount changes, so an
            // "attempt A appeared to fail, I edited the box to B and retried"
            // sequence can never silently re-book A under B's confirmation.
            const key = ctx.keyFor(ctx.amount);
            const params = {
                case_id: ctx.caseId,
                amount: ctx.amount,
                payment_method: method,
                notes: notes,
                idempotency_key: key,
            };
            params['allocations[' + ctx.invoiceId + ']'] = ctx.amount;
            const res = await postFinanceAction('receive_payment', params, $('acReceiveConfirmOk'));
            if (res && res.ok && res.duplicate) {
                // findIdempotent() matched an EXISTING payment for this exact
                // key — this attempt booked nothing new. Say so honestly
                // instead of the generic success toast, then refetch same as
                // a real success would (the earlier attempt's money is real).
                closeReceiveConfirm();
                toast('warning', 'ئەم پارەدانە پێشتر تۆمارکراوە — داتاکان نوێ کرانەوە');
                if (AC.currentDrawer) openDrawer(AC.currentDrawer.kind, AC.currentDrawer.id);
                load();
                return;
            }
            if (res && (res.ok || res.stale)) {
                closeReceiveConfirm();
                handleActionResult(res, true);
                return;
            }
            // Real rejection (capacity changed, validation, etc.) — keep the
            // dialog open over the drawer so the accountant can adjust and
            // retry; showDrawerErr would render behind this modal, unseen.
            receiveConfirmErr((res && res.error) || 'کردارەکە سەرنەکەوت');
        });
    }

    // ---------------------------------------------------------------
    // ئامانەت — money the counter already took from this patient
    // (patient_advances). Shown as one banner at the top of the invoice and
    // payment windows. Its inline control marks which part of received came
    // from custody, so that part cannot also be recorded as new cash.
    // ---------------------------------------------------------------

    /** Does this figure equal the patient's ئامانەت, or one receipt of it? */
    function amanatMatches(data, amount) {
        const a = Math.round(Number(amount) || 0);
        if (a <= 0) return false;
        if (Math.round(Number(data.advance_available || 0)) === a) return true;
        return (data.advance_receipts || []).some((r) =>
            Math.round(Number(r.amount || 0)) === a || Math.round(Number(r.available || 0)) === a);
    }

    /**
     * The banner. Empty when the patient holds no ئامانەت.
     * @param {Object} data detail payload (advance_available, advance_receipts)
     * @param {{warn?:string, note?:string, actionHtml?:string}} o
     *        warn — a red line (already escaped-safe: built from numbers);
     *        note — the grey guidance line; actionHtml — controls below it.
     */
    function amanatBannerHtml(data, o) {
        const available = Number(data.advance_available || 0);
        if (available <= 0.009) return '';
        o = o || {};
        let html = '<div class="ac-amanat' + (o.warn ? ' is-warn' : '') + '">'
            + '<div class="ac-amanat-head"><i class="fas fa-hand-holding-usd"></i>'
            + '<span>ئەم نەخۆشە ئامانەتی هەیە</span><strong>' + nf(available) + '</strong></div>';
        const receipts = data.advance_receipts || [];
        if (receipts.length) {
            html += '<ul class="ac-amanat-list">';
            receipts.forEach((r) => {
                const partly = Math.abs(Number(r.amount || 0) - Number(r.available || 0)) > 0.009;
                html += '<li><b>' + nf(r.available) + '</b>'
                    + (partly ? '<small>(لە ' + nf(r.amount) + ')</small>' : '')
                    + '<span dir="ltr">' + esc(String(r.received_at || '').slice(0, 10)) + '</span>'
                    + '<bdi dir="ltr">' + esc(r.advance_no || '') + '</bdi>'
                    + (r.received_by_name ? '<small>' + esc(r.received_by_name) + '</small>' : '')
                    + '</li>';
            });
            html += '</ul>';
        }
        if (o.warn) html += '<div class="ac-amanat-warn"><i class="fas fa-exclamation-triangle"></i> ' + o.warn + '</div>';
        if (o.note) html += '<div class="ac-amanat-note">' + o.note + '</div>';
        if (o.actionHtml) html += o.actionHtml;
        return html + '</div>';
    }

    /** Wires the print button + live discount/received previews + their save
     * actions. Pure display math client-side (recompute()); every actual
     * write still goes through postFinanceAction -> the existing
     * update_invoice_accounting / receive_payment endpoints, unchanged. */
    function wireInvoicePane(inv, patient, summary, advanceAvailable = 0) {
        const total = Number(inv.total_amount) || 0;
        // دراوی پێشوو (historical cases): part of what covers the invoice, never
        // part of the editable وەرگیراو box.
        const priorPaid = Math.max(0, Number(inv.prior_paid_amount) || 0);
        const alreadyPaid = inv.accounting_status === 'approved'
            ? (Number(inv.paid_amount) || 0)
            : Math.max(0, Number(inv.declared_received_amount || inv.draft_received_amount || 0)) + priorPaid;
        const receivedSnapshot = invoiceReceivedAmounts(inv);
        const allocatedReceived = inv.accounting_status === 'approved' ? receivedSnapshot.allocated : 0;
        const stagedAmount = () => Number((invoiceAdvanceDraft(inv) || {}).amount || 0);

        const printBtn = $('acInvPrintBtn');
        if (printBtn) {
            printBtn.addEventListener('click', () => {
                const url = APP_URL + '/cases/receipt.php?id=' + encodeURIComponent(inv.case_id)
                    + '&invoice=' + encodeURIComponent(inv.id) + '&autoprint=0';
                openPrintDialog(url, 'پسوولەی وەرگرتنی پارە');
            });
        }

        const discBox = $('acDiscBox');
        const discInput = $('acDiscInput');
        const discNotes = $('acDiscNotes');
        // Read up here (not down with the rest of the settlement wiring) because
        // recompute() below needs it: ماوە is measured against the وەرگیراو box
        // while that box is editable, so the live preview has to see it.
        const recvInput = $('acRecvInput');

        // Server-confirmed snapshot this render was built from — saveDiscount()
        // reverts to exactly this (mode + value) on a rejected save, same role
        // invoice-pane.js's resetPaid()/rtSavePaid() play for its own box.
        const savedMode = discBox ? discBox.dataset.mode : 'amount';
        const savedValue = discInput ? discInput.value : '';

        // Re-entrancy guard — ported from assets/js/invoice-pane.js's own
        // module-level discBusy: without it a blur-triggered save can race the
        // %/IQD mode-toggle click and clobber whichever one loses.
        let discBusy = false;
        let discountSave = Promise.resolve();
        let settlementSave = Promise.resolve();
        let discountSaved = true;
        let settlementSaved = true;

        function discountAmount() {
            if (!discBox || !discInput) return Number(inv.discount_amount) || 0;
            const v = Number(discInput.value) || 0;
            return discBox.dataset.mode === 'percent' ? total * Math.min(100, Math.max(0, v)) / 100 : Math.max(0, v);
        }

        // پوختە = کۆی گشتی − داشکاندن. Live, so the totals stack tracks the
        // discount box keystroke by keystroke.
        function netAmount() {
            return Math.max(0, total - Math.min(total, discountAmount()));
        }

        // While the invoice is editable the وەرگیراو box IS the received
        // figure, so the preview must read what is currently typed there
        // rather than the server snapshot it was rendered from — otherwise
        // ماوە only catches up after the blur-save re-renders the drawer.
        // On a locked invoice there is no box and the snapshot is the truth.
        function receivedAmount() {
            if (!recvInput) return alreadyPaid;
            return Math.max(0, Number(recvInput.value) || 0) + priorPaid;
        }

        // ── پوختەی دارایی کەیس chips ────────────────────────────────────
        // AccountingLedger::getSummary() counts an invoice under
        // پەسەندکراو / دراو / ماوە only once accounting_status = 'approved'
        // (classes/AccountingLedger.php:200-207). That is deliberate and
        // test-locked — tests/cases/EndToEndTest.php:81 asserts "sending books
        // nothing — balance still 0" — and the same figure feeds the patient
        // statement (PatientFinance) and Payment::getCaseBalance(), so the
        // server formula is left exactly as it is.
        //
        // The cost is that THIS screen — the one place whose whole job is the
        // not-yet-approved invoice — renders a case with 60,000 outstanding as
        // ماوە 0, and never moves while the accountant edits داشکاندن or
        // وەرگیراو. So the invoice being reviewed is folded back in here, in
        // the modal only, using the figures currently on screen. Payment.php's
        // own getCaseBalance($allowSent = true) is the same idea server-side:
        // "including sent invoices" is a separate, legitimate question, not a
        // redefinition of the approved-only one.
        //
        // پەسەندکراو and کۆی پسووڵە are NOT adjusted: the first would then be
        // lying about its own label, and the second already counts every sent
        // invoice (AccountingLedger.php:198).
        const chipsLive = inv.accounting_status === 'sent';
        // The case's OTHER invoices — this one's stale server contribution
        // subtracted out so the live figures below can replace it. Under a
        // 'sent' status getSummary() contributed nothing to paid/balance for
        // this invoice, so those two need no subtraction; total_discount does
        // (AccountingLedger.php:199 counts it for every non-draft invoice).
        const caseDiscountOther = Math.max(0, (Number(summary && summary.total_discount) || 0) - (Number(inv.discount_amount) || 0));
        const casePaidOther = Number(summary && summary.paid) || 0;
        const caseBalanceOther = Number(summary && summary.balance) || 0;
        // Approved receipts already allocated to this invoice. Separate money
        // from the وەرگیراو box (that one is the chair-side cash written on the
        // invoice itself) and additive with it — the same sum decorateInvoice()
        // will compute the moment this invoice is approved.
        const chipAllocated = Math.max(0, Number(inv.paid_amount) || 0);

        function updateCaseChips(net, received) {
            if (!chipsLive) return;
            const set = (id, v) => { const el = $(id); if (el) el.textContent = nf(v); };
            const thisPaid = Math.min(net, chipAllocated + received);
            set('acFinDiscount', caseDiscountOther + Math.min(total, discountAmount()));
            set('acFinPaid', casePaidOther + thisPaid);
            set('acFinBalance', caseBalanceOther + Math.max(0, net - thisPaid));
        }

        // ماوە = پوختە − وەرگیراو. Display only — nothing here writes anything.
        function recompute() {
            const net = netAmount();
            if ($('acInvNetBox')) $('acInvNetBox').textContent = nf(net);

            // A discount lowers پوختە, so the ceiling on وەرگیراو moves with it.
            if (recvInput) recvInput.max = Math.max(0, net - priorPaid);

            const received = receivedAmount();
            if ($('acInvPaidBox')) $('acInvPaidBox').textContent = nf(Math.max(0, received - priorPaid));
            const remaining = Math.max(0, net - received);
            const remBox = $('acInvRemainingBox');
            if (remBox) { remBox.textContent = nf(remaining); remBox.classList.toggle('is-due', remaining > 0.009); }
            if ($('acInvHeadRemaining')) {
                $('acInvHeadRemaining').textContent = 'ماوە ' + nf(remaining);
                $('acInvHeadRemaining').hidden = remaining <= 0.009;
            }

            updateCaseChips(net, received);
        }

        const custodyInput = $('acInvoiceCustodyAmount');
        function updateCustodySelection() {
            const amount = stagedAmount();
            if ($('acInvoiceCustodyClear')) $('acInvoiceCustodyClear').hidden = amount <= 0.009;
            if ($('acInvoiceCustodyNote')) $('acInvoiceCustodyNote').textContent = amount > 0.009
                ? nf(amount) + ' لە ئامانەت — لە کاتی پەسەندکردن دەبڕدرێت' : '';
            if ($('acInvoiceCustodyUse')) $('acInvoiceCustodyUse').classList.toggle('is-selected', amount > 0.009);
            if (recvInput) recvInput.min = allocatedReceived + amount;
            recompute();
        }
        if ($('acInvoiceCustodyUse') && recvInput) {
            $('acInvoiceCustodyUse').addEventListener('click', () => {
                const amount = Number(custodyInput.dataset.amount);
                const current = Number(recvInput.value || 0);
                const previous = invoiceAdvanceDraft(inv);
                const received = previous ? Math.max(0, current - previous.amount) + amount : Math.max(current, amount);
                if (!Number.isFinite(amount) || amount <= 0 || amount > advanceAvailable + 0.009
                    || !Number.isFinite(received) || received > netAmount() - priorPaid + 0.009) {
                    showDrawerErr('بڕی ئامانەت لە بەردەست یان ماوەی وەسڵ زیاترە'); return;
                }
                invoiceAdvanceDrafts.set(String(inv.id), { amount, received });
                recvInput.value = received;
                updateCustodySelection();
            });
            $('acInvoiceCustodyClear').addEventListener('click', () => {
                invoiceAdvanceDrafts.delete(String(inv.id));
                recvInput.value = receivedSnapshot.declared || '';
                updateCustodySelection();
            });
            updateCustodySelection();
        }

        const discModeBtn = $('acDiscModeBtn');

        // Put the box back to the last server-confirmed figures — mirrors
        // invoice-pane.js's resetPaid(), just covering both mode and value.
        function revertDiscount() {
            if (!discBox || !discInput) return;
            discBox.dataset.mode = savedMode;
            discBox.classList.toggle('is-percent', savedMode === 'percent');
            if (discModeBtn) discModeBtn.textContent = savedMode === 'percent' ? '%' : 'IQD';
            discInput.value = savedValue;
            recompute();
        }

        function saveDiscount() {
            if (!discBox || !discInput || discBusy) return;
            const amount = discountAmount();
            // Client-side pre-validation, mirroring CaseInvoice::setDiscount()'s
            // own two guards — server stays authoritative either way, this just
            // avoids a round trip for the two ways it can already be told no.
            if (amount > total + 0.009) {
                showDrawerErr('داشکاندن ناتوانێت لە کۆی وەسڵەکە زیاتر بێت');
                revertDiscount();
                return;
            }
            if (total - amount < alreadyPaid - 0.009) {
                showDrawerErr('داشکاندن ناتوانێت بڕی پارەی پێشتر وەرگیراو کەم بکاتەوە');
                revertDiscount();
                return;
            }
            discBusy = true;
            discBox.classList.add('is-saving');
            discountSave = postFinanceAction('update_invoice_accounting', {
                invoice_id: inv.id,
                discount_mode: discBox.dataset.mode,
                discount_value: discInput.value || 0,
                accounting_notes: discNotes ? discNotes.value : (inv.accounting_notes || ''),
            }, null).then((res) => {
                if (!(res && res.ok)) {
                    discountSaved = false;
                    showDrawerErr((res && res.error) || 'داشکاندن سەرنەکەوت');
                    revertDiscount();
                    return;
                }
                discountSaved = true;
                handleActionResult(res, true);
            }).finally(() => {
                discBusy = false;
                discBox.classList.remove('is-saving');
            });
        }

        if (discInput) {
            discInput.addEventListener('input', recompute);
            discInput.addEventListener('blur', saveDiscount);
            discInput.addEventListener('keydown', (e) => { if (e.key === 'Enter') { e.preventDefault(); discInput.blur(); } });
        }
        if (discModeBtn && discBox && discInput) {
            discModeBtn.addEventListener('click', () => {
                if (discBusy) return; // a save is in flight — do not clobber it
                const amount = discountAmount();
                if (discBox.dataset.mode === 'percent') {
                    discBox.dataset.mode = 'amount';
                    discBox.classList.remove('is-percent');
                    discModeBtn.textContent = 'IQD';
                    discInput.value = amount ? Math.round(amount * 100) / 100 : '';
                } else {
                    discBox.dataset.mode = 'percent';
                    discBox.classList.add('is-percent');
                    discModeBtn.textContent = '%';
                    discInput.value = (total > 0 && amount) ? Math.round(amount / total * 10000) / 100 : '';
                }
                recompute();
                discInput.focus();
            });
        }
        if (discNotes) discNotes.addEventListener('blur', saveDiscount);

        // ---- settlement: received figure + cash/debt/installment ----------
        // Saved through their own action rather than piggybacking on
        // saveDiscount, so correcting the received amount cannot accidentally
        // re-post a discount the accountant did not touch.
        // recvInput is read at the top of this function — recompute() needs it.
        const intentSel = $('acIntentSel');
        let settleBusy = false;

        const settleReceived = receivedSnapshot.total;

        function saveSettlement(fields) {
            if (settleBusy) return;
            settleBusy = true;
            settlementSave = postFinanceAction('adjust_invoice_settlement',
                Object.assign({ invoice_id: inv.id }, fields), null
            ).then((res) => {
                if (!(res && res.ok)) {
                    settlementSaved = false;
                    showDrawerErr((res && res.error) || 'نوێکردنەوە سەرنەکەوت');
                    return;
                }
                settlementSaved = true;
                // The server owns the rule, so reflect what it decided rather
                // than what was clicked: choosing نەقد with a balance comes
                // back as قەرز, and the dropdown must show that.
                if (intentSel && res.payment_intent) intentSel.value = res.payment_intent;
                handleActionResult(res, true);
            }).finally(() => { settleBusy = false; });
        }

        if (recvInput) {
            // Same contract as داشکاندن above: type → ماوە follows immediately
            // (display only), leave the box → the figure is saved.
            recvInput.addEventListener('input', recompute);
            recvInput.addEventListener('blur', () => {
                const v = Number(recvInput.value || 0);
                // Measured against the net the accountant is actually looking
                // at, which the discount box may have just moved.
                const minimum = allocatedReceived + stagedAmount();
                if (!Number.isFinite(v) || v < minimum - 0.009 || v > netAmount() - priorPaid + 0.009) {
                    showDrawerErr(v < minimum - 0.009
                        ? 'ئەم بڕە قەبزی پارەدانی پەسەندکراوی هەیە؛ بۆ کەمکردنەوە قەبزەکە دەستکاری بکە'
                        : 'بڕی وەرگیراو لە کۆی وەسڵ زیاترە');
                    const restore = invoiceAdvanceDraft(inv) ? invoiceAdvanceDraft(inv).received : settleReceived;
                    recvInput.value = restore > 0.009 ? restore : '';
                    recompute();
                    return;
                }
                const draft = invoiceAdvanceDraft(inv);
                if (draft) {
                    draft.received = v;
                    return; // Custody and its cash remainder are saved together on approval.
                }
                // The input includes existing receipts, including custody.
                // Only its invoice-cash remainder may be written back.
                saveSettlement({ received_amount: Math.round(Math.max(0, v - allocatedReceived) * 100) / 100 });
            });
            recvInput.addEventListener('keydown', (e) => {
                if (e.key === 'Enter') { e.preventDefault(); recvInput.blur(); }
            });
        }
        if (intentSel) {
            intentSel.addEventListener('change', () => saveSettlement({ payment_intent: intentSel.value }));
        }

        recompute();
        return {
            async approvalFields() {
                // A blur-save must finish before the invoice becomes approved,
                // so it cannot arrive afterwards as an approved cash edit.
                await Promise.all([discountSave, settlementSave]);
                if (!discountSaved || !settlementSaved) return null;
                const received = Number(recvInput ? recvInput.value : receivedSnapshot.total) || 0;
                const advance = stagedAmount();
                if (!Number.isFinite(received) || received < advance - 0.009 || received > netAmount() - priorPaid + 0.009) {
                    showDrawerErr('بڕی وەرگیراو یان ئامانەت نادروستە'); return null;
                }
                return { advance_amount: advance, received_cash: Math.round((received - advance) * 100) / 100 };
            },
        };
    }

    function renderInvoiceDrawer(data) {
        const inv = data.invoice || {};
        const treatments = data.treatments || [];
        const summary = data.summary || {};
        const intent = data.intent || {};

        $('acDrawerTitle').innerHTML = '<i class="fas fa-file-invoice" style="color:#0063b1;"></i> پسووڵە ' + esc(inv.invoice_no || '');

        // Patient identity + کشف حساب + مەبەست now render into the persistent
        // #acDrawerSub slot (outside the scrolling body) instead of being the
        // first line of body content — see invoiceSubheaderHtml() above.
        if ($('acDrawerSub')) $('acDrawerSub').innerHTML = invoiceSubheaderHtml(data.patient, data.patient_id, intent);

        // An approved invoice ALWAYS opens locked. When the admin switch allows
        // correcting it (data.can_edit_approved, from ApprovalQueue::
        // detailInvoice) a «تەعدیل» button is drawn; only pressing it makes
        // the money boxes live (AC.editApproved), each saved on its own
        // through CaseInvoice::editApproved. «تەواو» locks them again. The
        // invoice never leaves پەسەندکراو, and its approval date and approver
        // are never touched.
        const isApproved = inv.accounting_status === 'approved';
        const canEditApproved = isApproved && !!data.can_edit_approved;
        const editing = canEditApproved && isEditingApproved('invoice', inv.id);
        const approvedEditState = canEditApproved ? (editing ? 'editing' : 'locked') : null;

        // Unapproved invoices select custody beside received. Approved ones
        // can transfer additional custody straight from the banner.
        const advanceAvailable = Number(data.advance_available || 0);
        const advanceRoom = Number(inv.remaining_amount || 0);
        const advanceCap = Math.min(advanceAvailable, advanceRoom);
        const canApplyAdvance = isApproved && advanceCap > 0.009;
        const declaredReceived = Math.max(0, Number(inv.declared_received_amount || inv.draft_received_amount || 0));
        let html = amanatBannerHtml(data, {
            warn: inv.accounting_status === 'sent' && declaredReceived > 0.009
                ? 'لەم وەسڵەدا ' + nf(declaredReceived) + ' وەک «وەرگیراو» (پارەی نوێ) نووسراوە'
                  + (amanatMatches(data, declaredReceived) ? ' — هەمان بڕی ئامانەتەکەیە' : '')
                  + '. دڵنیابە هەمان ئامانەت نییە.'
                : '',
            note: inv.accounting_status === 'sent'
                ? 'بڕی ئامانەت خۆکار دیارە؛ «بخە وەرگیراو» دابگرە و پاشان پەسەندی بکە.'
                : (canApplyAdvance ? 'ماوەی ئەم وەسڵە ' + nf(advanceRoom) + 'ـە؛ دەتوانیت لە ئامانەت بیبڕیت.' : ''),
            actionHtml: canApplyAdvance
                ? '<div class="ac-amanat-act">'
                  + '<input type="number" class="ac-amanat-input" id="acInvoiceAdvanceAmount" min="0.01" step="0.01" max="' + advanceCap + '" value="' + advanceCap + '" aria-label="بڕی لە ئامانەت">'
                  + '<button type="button" class="btn btn-primary" id="acInvoiceAdvanceApply"><i class="fas fa-hand-holding-usd"></i> لە ئامانەت ببڕە</button>'
                  + '</div>'
                : '',
        }) + invoicePaneHtml(inv, treatments, approvedEditState, advanceAvailable);

        if (isApproved) {
            const trail = data.approved_edits || {};
            const trailHtml = Number(trail.count || 0) > 0
                ? '<span class="ae-trail"><i class="fas fa-history"></i> ئەم وەسڵە ' + nf(trail.count)
                  + ' جار دوای پەسەندکردن دەستکاریکراوە — دوایین: ' + esc(fmtDate(trail.last_at))
                  + (trail.last_by ? ' (' + esc(trail.last_by) + ')' : '') + '</span>'
                : '';
            const approvedOn = inv.approved_at
                ? ' <b dir="ltr">' + esc(fmtDate(inv.approved_at)) + '</b>'
                  + (inv.approved_by_name ? ' — ' + esc(inv.approved_by_name) : '')
                : '';
            if (editing) {
                html += '<div class="ac-approved-edit-hint is-editing"><i class="fas fa-pen"></i> <b>دۆخی تەعدیل</b> — '
                    + 'داشکاندن، وەرگیراو، جۆری پارەدان و تێبینی ژمێریاری بگۆڕە؛ هەر خانەیەک کە لێی دەرچیت پاشەکەوت دەبێت. '
                    + 'وەسڵەکە <b>پەسەندکراو دەمێنێتەوە</b> و بەرواری پەسەندکردنەکەی' + approvedOn + ' ناگۆڕێت. '
                    + 'کە تەواو بوویت، «تەواو» دابگرە.'
                    + trailHtml + '</div>';
            } else if (canEditApproved) {
                html += '<div class="ac-approved-edit-hint is-locked"><i class="fas fa-lock"></i> ئەم وەسڵە <b>پەسەندکراوە</b>'
                    + (approvedOn ? ' (' + approvedOn.trim() + ')' : '')
                    + '. بۆ گۆڕینی داشکاندن، وەرگیراو، جۆری پارەدان یان تێبینی، دوگمەی <b>«تەعدیل»</b> دابگرە.'
                    + trailHtml + '</div>';
            } else {
                html += '<div class="ac-approved-edit-hint is-locked"><i class="fas fa-lock"></i> وەسڵی پەسەندکراو قوفڵە'
                    + (approvedOn ? ' (پەسەندکراوە لە' + approvedOn + ')' : '')
                    + ' — بۆ دەستکاریکردن، بەڕێوەبەر لە ڕێکخستنەکان «دەستکاریکردنی وەسڵ دوای پەسەندکردن» چالاک بکات.'
                    + trailHtml + '</div>';
            }
        }

        const canAct = inv.accounting_status === 'sent';

        // Live chips only while this invoice is still under review — that is
        // the only state whose figures the boxes above can still move. (An
        // approved invoice's chips are already the ledger's own; they refresh
        // from the server after each in-place save.)
        html += finBlock(summary, canAct);

        if (canAct) {
            html += '<div class="ac-form"><div class="ac-form-title"><i class="fas fa-check-circle" style="color:#107c10;"></i> پەسەندکردن</div>'
                + '<div class="ac-fld"><label>تێبینی ژمێریاری (ئارەزوومەندانە)</label><textarea id="acApproveNotes" rows="2">' + esc(inv.accounting_notes || '') + '</textarea></div>'
                + '<div class="ac-form-actions">'
                + '<button type="button" class="btn btn-primary" id="acApprovePrintBtn"><i class="fas fa-print"></i> پەسەندکردن و چاپکردن</button>'
                + '<button type="button" class="btn" id="acApproveBtn"><i class="fas fa-check"></i> پەسەندکردن</button>'
                + '</div></div>'
                + '<div class="ac-form"><div class="ac-form-title"><i class="fas fa-undo" style="color:#c50f1f;"></i> گەڕاندنەوە</div>'
                + '<div class="ac-fld"><label>هۆکار (پێویستە)</label><textarea id="acReturnReason" rows="2" placeholder="هۆکاری گەڕاندنەوە بنووسە..."></textarea></div>'
                + '<div class="ac-form-actions"><button type="button" class="btn btn-danger" id="acReturnBtn"><i class="fas fa-undo"></i> گەڕاندنەوە</button></div></div>';
        }

        html += '<div class="ac-err" id="acDrawerErr"></div>';

        $('acDrawerBody').innerHTML = html;

        const invoiceControls = wireInvoicePane(inv, data.patient, summary, advanceAvailable);
        if ($('acInvoiceAdvanceApply')) {
            const advanceKeys = new Map();
            $('acInvoiceAdvanceApply').addEventListener('click', async () => {
                const amount = Number($('acInvoiceAdvanceAmount').value);
                if (!Number.isFinite(amount) || amount <= 0 || amount > advanceCap + 0.009) {
                    showDrawerErr('بڕی گواستنەوە لە ئامانەت یان ماوەی وەسڵ زیاترە'); return;
                }
                if (!advanceKeys.has(amount)) advanceKeys.set(amount, 'amanat-' + inv.id + '-' + Date.now() + '-' + Math.random().toString(36).slice(2));
                const res = await postFinanceAction('advance_apply', {
                    invoice_id: inv.id, amount: amount, idempotency_key: advanceKeys.get(amount),
                }, $('acInvoiceAdvanceApply'));
                handleActionResult(res);
            });
        }

        // «تەعدیل» / «تەواو» — the only switch between the locked and the
        // editable view of an approved invoice.
        const invEditBtn = $('acInvEditBtn');
        if (invEditBtn) {
            invEditBtn.addEventListener('click', () => {
                if (editing) {
                    // A box left by this very click has already started its
                    // own save; refetching (not redrawing this payload) makes
                    // the locked view show what the server actually kept.
                    setEditingApproved(null);
                    openDrawer('invoice', inv.id);
                    return;
                }
                setEditingApproved('invoice', inv.id);
                renderInvoiceDrawer(data);
            });
        }
        settleEditFocus(invEditBtn);

        if (canAct) {
            const approvePayload = (fields) => Object.assign({
                case_id: inv.case_id, invoice_id: inv.id,
                accounting_notes: $('acApproveNotes').value,
            }, fields);
            // Two A5 copies of this receipt — patient and clinic file. A page
            // cannot set the print dialog's copy count, so ?copies=2 puts two
            // real pages in the job.
            const receiptUrl = () => APP_URL + '/cases/receipt.php?id=' + encodeURIComponent(inv.case_id)
                + '&invoice=' + encodeURIComponent(inv.id) + '&copies=2&autoprint=1';

            // The inline received controls already hold the source choice.
            // Approval and custody consumption are one server transaction.
            let approving = false;
            async function approveInvoice(btn, printAfter) {
                if (approving) return;
                approving = true;
                try {
                    const fields = await invoiceControls.approvalFields();
                    if (!fields) return;
                    const res = await postFinanceAction('approve_invoice', approvePayload(fields), btn);
                    if (res && res.ok) invoiceAdvanceDrafts.delete(String(inv.id));
                    // `stale` means someone approved it a moment earlier — the
                    // receipt is still the right thing to print.
                    const printIt = printAfter && res && (res.ok || res.stale);
                    // Settle first: handleActionResult() closes the detail and
                    // refreshes the queue, and the print dialog has to survive
                    // that — so it is opened after, on top of what is left.
                    handleActionResult(res);
                    if (printIt) openPrintDialog(receiptUrl(), 'پسوولەی وەرگرتنی پارە', true);
                } finally {
                    approving = false;
                }
            }
            $('acApproveBtn').addEventListener('click', () => approveInvoice($('acApproveBtn'), false));
            $('acApprovePrintBtn').addEventListener('click', () => approveInvoice($('acApprovePrintBtn'), true));
            $('acReturnBtn').addEventListener('click', async () => {
                const reason = $('acReturnReason').value.trim();
                if (!reason) { showDrawerErr('هۆکار پێویستە'); return; }
                const res = await postFinanceAction('return_invoice', { case_id: inv.case_id, invoice_id: inv.id, reason }, $('acReturnBtn'));
                handleActionResult(res);
            });
        }
    }

    /**
     * The «تەعدیل» strip of an approved receipt / plan / X-ray window (the
     * invoice draws its own in the invoice header). It always says which
     * state the record is in and keeps its approval date in sight:
     *   locked  — «تەعدیل» switches it to editing;
     *   editing — its form is live below, saved by that form's «پاشەکەوت»,
     *             and this button becomes «پاشگەزبوونەوە» (leave, nothing saved);
     *   blocked — a reason instead of a button (the server would refuse, or
     *             the admin switch is off).
     * @param {{canEdit:boolean, editing:boolean, blockReason:?string,
     *          approvedOn:string, trail:?Object}} o
     */
    function approvedEditBarHtml(o) {
        const trail = o.trail || {};
        const trailHtml = Number(trail.count || 0) > 0
            ? '<span class="ae-trail"><i class="fas fa-history"></i> ' + nf(trail.count)
              + ' جار دوای پەسەندکردن دەستکاریکراوە — دوایین: ' + esc(fmtDate(trail.last_at))
              + (trail.last_by ? ' (' + esc(trail.last_by) + ')' : '') + '</span>'
            : '';
        const on = o.approvedOn ? ' <b dir="ltr">' + esc(o.approvedOn) + '</b>' : '';
        if (o.editing) {
            return '<div class="ac-approved-bar"><div class="ac-approved-edit-hint is-editing"><i class="fas fa-pen"></i> <b>دۆخی تەعدیل</b> — '
                + 'گۆڕانکارییەکان بکە و لە خوارەوە «پاشەکەوت» دابگرە. تۆمارەکە <b>پەسەندکراو دەمێنێتەوە</b> و بەروارەکەی' + on + ' ناگۆڕێت.'
                + trailHtml + '</div>'
                + '<button type="button" class="btn" id="acRecEditBtn"><i class="fas fa-times"></i> پاشگەزبوونەوە</button></div>';
        }
        if (o.canEdit) {
            return '<div class="ac-approved-bar"><div class="ac-approved-edit-hint is-locked"><i class="fas fa-lock"></i> ئەم تۆمارە <b>پەسەندکراوە</b>'
                + (on ? ' (' + on.trim() + ')' : '') + '. بۆ گۆڕانکاری دوگمەی <b>«تەعدیل»</b> دابگرە.'
                + trailHtml + '</div>'
                + '<button type="button" class="btn btn-primary" id="acRecEditBtn"><i class="fas fa-pen"></i> تەعدیل</button></div>';
        }
        return '<div class="ac-approved-edit-hint is-locked"><i class="fas fa-lock"></i> '
            + esc(o.blockReason || 'دەستکاریکردنی تۆماری پەسەندکراو لە ڕێکخستنەکان ناچالاکە — بەڕێوەبەر دەتوانێت چالاکی بکات')
            + trailHtml + '</div>';
    }

    /** After a ✏️ open: focus «تەعدیل» when the record offers it, otherwise
     *  the window's close button — openDrawer() leaves the focus to this
     *  call in that case, so the two can never race each other. */
    function settleEditFocus(btn) {
        if (!AC.focusEditBtn) return;
        AC.focusEditBtn = false;
        // openDrawer() made the window visible (display, no transition)
        // before it fetched, so the element can take focus right now — a
        // deferred focus could wait on a frame that a background or busy tab
        // never paints, leaving focus behind the window.
        const target = btn || $('acDrawerClose');
        if (target) target.focus();
    }

    /** Wires the strip's button. Leaving edit mode redraws from the same
     *  payload — nothing was saved, so there is nothing to refetch. */
    function wireApprovedEditBar(kind, id, editing, redraw) {
        const btn = $('acRecEditBtn');
        if (btn) {
            btn.addEventListener('click', () => {
                setEditingApproved(editing ? null : kind, id);
                redraw();
            });
        }
        settleEditFocus(btn);
    }

    /** A «پاشەکەوت» that worked ends edit mode, then the record is refetched
     *  (locked) so the window shows what the server actually kept. */
    function finishApprovedEdit(res) {
        if (res && res.ok) setEditingApproved(null);
        handleActionResult(res, true);
    }

    function renderPaymentDrawer(data) {
        const p = data.payment || {};
        const summary = data.summary || {};
        const st = statusInfoFor('payment', p.status);
        const canAct = p.status === 'pending';
        // An approved receipt opens locked; «تەعدیل» makes its amount live
        // (Payment::editApproved — same number, same date, still approved).
        const isApproved = p.status === 'approved';
        const editing = isApproved && !!data.can_edit_approved && isEditingApproved('payment', p.id);

        $('acDrawerTitle').innerHTML = '<i class="fas fa-money-bill-wave" style="color:#107c10;"></i> قەبزی وەرگرتنی پارە ' + esc(p.payment_number || '');

        const printUrl = APP_URL + '/cases/voucher.php?payment=' + encodeURIComponent(p.id)
            + '&copies=2&autoprint=0';
        const inputState = (canAct || editing) ? '' : ' readonly';
        let html = '<div class="ac-payment-card">'
            + '<div class="ac-payment-toolbar">'
            + '<span class="status-chip ' + st[1] + '">' + esc(st[0]) + '</span>'
            + '<button type="button" class="btn" id="acPayPrintBtn"><i class="fas fa-print"></i> چاپکردنی قەبز</button>'
            + '</div>'
            + (isApproved ? approvedEditBarHtml({
                canEdit: !!data.can_edit_approved,
                editing: editing,
                blockReason: data.edit_block_reason,
                approvedOn: fmtDate(p.payment_date),
                trail: data.approved_edits,
            }) : '')
            + drawerHeader(data.patient, data.patient_id)
            + amanatBannerHtml(data, canAct ? {
                warn: amanatMatches(data, p.amount)
                    ? 'ئەم داواکارییە ' + nf(p.amount) + 'ـە — هەمان بڕی ئامانەتەکەیە. دڵنیابە هەمان پارە نییە.'
                    : '',
                note: 'بڕی ئامانەت خۆکار دیارە؛ بۆ بەکارهێنانی «بخە وەرگیراو» دابگرە.',
            } : {})
            + '<div class="ac-payment-form-grid">'
            + '<div class="ac-payment-field"><label>ژمارەی قەبز</label><input type="text" dir="ltr" value="' + esc(p.payment_number || '') + '" readonly></div>'
            + '<div class="ac-payment-field is-amount"><label>بڕی پارە</label><input type="number" id="acAdjAmount" min="0.01" step="0.01" value="' + Number(p.amount || 0) + '"' + inputState + '>'
            + (canAct ? inlineAdvanceHtml('acPaymentCustody', Number(data.advance_available || 0), Math.min(Number(data.advance_available || 0), Number(p.amount || 0))) : '') + '</div>'
            + '<div class="ac-payment-field"><label>دۆخ</label><input type="text" value="' + esc(st[0]) + '" readonly></div>'
            + '<div class="ac-payment-field"><label>جۆری پارەدان</label><input type="text" value="' + esc(PAYMENT_TYPE_LABELS[p.payment_type] || p.payment_type || '—') + '" readonly></div>'
            + '<div class="ac-payment-field"><label>ڕێگای پارەدان</label><input type="text" value="' + esc(METHOD_LABELS[p.payment_method] || p.payment_method || '—') + '" readonly></div>'
            + '<div class="ac-payment-field"><label>بەروار</label><input type="text" dir="ltr" value="' + esc(p.payment_date || '—') + '" readonly></div>'
            + '<div class="ac-payment-field is-full"><label>وەرگیراو لەلایەن</label><input type="text" value="' + esc(p.received_by_name || '—') + '" readonly></div>';
        if (p.notes) {
            html += '<div class="ac-payment-field is-full"><label>تێبینی دکتۆر</label><textarea rows="2" readonly>' + esc(p.notes) + '</textarea></div>';
        }
        const hasDiscount = Number(p.discount_amount || 0) > 0;
        if (hasDiscount) {
            html += '<div class="ac-payment-field"><label>داشکاندنی زیاتر' + (canAct ? ' (داواکراو)' : '') + '</label><input type="text" readonly value="'
                + nf(p.discount_amount) + (p.discount_mode === 'percent' ? ' (' + nf(p.discount_value) + '%)' : ' IQD') + '"></div>'
                + '<div class="ac-payment-field is-full"><label>هۆکاری داشکاندن</label><textarea rows="2" readonly>' + esc(p.discount_reason || '') + '</textarea></div>'
                + '<div class="ac-fld-hint">' + (canAct ? 'پەسەندکردن پارە و داشکاندن پێکەوە تۆمار دەکات. ڕەتکردنەوە هیچ گۆڕانکارییەک لە قەرز ناکات.' : 'داشکاندن لە بڕی نەقد جیاوازە و لە وەسڵەکان هەژمارکراوە.') + '</div>';
        }
        html += '</div>';

        html += '<div class="ac-payment-section"><div class="ac-payment-section-title"><i class="fas fa-receipt"></i> دابەشکردن بەسەر پسووڵەکان</div>';
        const allocations = p.allocations || [];
        if (allocations.length) {
            html += '<table class="ac-table-mini"><thead><tr><th>پسووڵە</th><th>بڕی دابەشکراو</th></tr></thead><tbody>';
            allocations.forEach((a) => {
                html += '<tr><td dir="ltr">' + esc(a.invoice_no) + '</td><td class="ac-num">' + nf(a.allocated_amount) + '</td></tr>';
            });
            html += '</tbody></table>';
        } else {
            html += '<div class="ac-empty-drawer">دابەشنەکراوە</div>';
        }
        html += '</div>';

        if (hasDiscount && (p.discount_allocations || []).length) {
            html += '<div class="ac-payment-section"><div class="ac-payment-section-title">دابەشکردنی داشکاندن</div>'
                + '<table class="ac-table-mini"><thead><tr><th>پسووڵە</th><th>داشکاندن</th></tr></thead><tbody>';
            p.discount_allocations.forEach(a => {
                html += '<tr><td dir="ltr">' + esc(a.invoice_no) + '</td><td class="ac-num">' + nf(a.amount) + '</td></tr>';
            });
            html += '</tbody></table></div>';
        }

        const summaryItems = [
            ['کۆی پسووڵە', summary.total_invoiced, ''],
            ['داشکاندن', summary.total_discount, ''],
            ['پەسەندکراو', summary.approved, ''],
            ['دراو', summary.paid, 'is-paid'],
            ['ماوە', summary.balance, 'is-due'],
        ];
        html += '<div class="ac-payment-summary">';
        summaryItems.forEach(([label, value, cls]) => {
            html += '<div class="' + cls + '"><span>' + esc(label) + '</span><strong>' + nf(value || 0) + '</strong></div>';
        });
        html += '</div>';

        if (canAct) {
            html += '<div class="ac-payment-edit">'
                + '<div class="ac-payment-field"><label>تێبینی گۆڕانکاری بڕ (ئارەزوومەندانە)</label><input type="text" id="acAdjNote" placeholder="هۆکاری گۆڕانکاری..."></div>'
                + '<div class="ac-form-actions"><button type="button" class="btn btn-primary" id="acApprovePayBtn"><i class="fas fa-print"></i> پاشەکەوت، پەسەندکردن و چاپکردن (٢ کۆپی)</button></div>'
                + '<div class="ac-payment-reject"><div class="ac-payment-field"><label>هۆکاری ڕەتکردنەوە</label><input type="text" id="acRejectReason" placeholder="هۆکار پێویستە..."></div>'
                + '<button type="button" class="btn btn-danger" id="acRejectBtn"><i class="fas fa-times"></i> ڕەتکردنەوە</button></div>'
                + '</div>';
        }
        if (editing) {
            html += '<div class="ac-payment-edit">'
                + '<div class="ac-payment-field"><label>هۆکاری تەعدیل (ئارەزوومەندانە — لە تۆماری چالاکی هەڵدەگیرێت)</label><input type="text" id="acAdjNote" placeholder="بۆچی دەگۆڕدرێت..."></div>'
                + '<div class="ac-fld-hint">بڕی نوێ لەسەر هەمان پسووڵەکان دەمێنێتەوە: کەمکردنەوە لە نوێترین پسووڵە دەست پێدەکات، '
                + 'زیادکردنیش سەرەتا ماوەی هەمان پسووڵەکان پڕ دەکاتەوە، پاشان کۆنترین پسووڵەی تری کەیسەکە.</div>'
                + '<div class="ac-form-actions"><button type="button" class="btn btn-primary" id="acPayEditSave"><i class="fas fa-print"></i> پاشەکەوت و چاپکردن (٢ کۆپی)</button></div>'
                + '</div>';
        }
        html += '<div class="ac-err" id="acDrawerErr"></div></div>';

        $('acDrawerBody').innerHTML = html;

        if ($('acPayPrintBtn')) {
            $('acPayPrintBtn').addEventListener('click', () => {
                openPrintDialog(printUrl, 'قەبزی وەرگرتنی پارە');
            });
        }

        wireApprovedEditBar('payment', p.id, editing, () => renderPaymentDrawer(data));
        if (editing) {
            $('acPayEditSave').addEventListener('click', async () => {
                const amount = Number($('acAdjAmount').value || 0);
                if (!(amount > 0)) { showDrawerErr('بڕ نادروستە'); return; }
                const res = await postFinanceAction('edit_approved_payment', {
                    payment_id: p.id,
                    amount: amount,
                    note: $('acAdjNote').value,
                }, $('acPayEditSave'));
                finishApprovedEdit(res);
                if (res && res.ok) {
                    openPrintDialog(printUrl.replace('&autoprint=0', '&autoprint=1'), 'قەبزی وەرگرتنی پارە — ٢ کۆپی', true);
                }
            });
        }

        if (canAct) {
            let useAdvance = false;
            const available = Number(data.advance_available || 0);
            if ($('acPaymentCustodyUse')) {
                const markSource = () => {
                    $('acPaymentCustodyClear').hidden = !useAdvance;
                    $('acPaymentCustodyUse').classList.toggle('is-selected', useAdvance);
                    $('acPaymentCustodyNote').textContent = useAdvance
                        ? nf($('acAdjAmount').value) + ' لە ئامانەت — لە کاتی پەسەندکردن دەبڕدرێت' : '';
                };
                $('acPaymentCustodyUse').addEventListener('click', () => {
                    const amount = Number($('acPaymentCustodyAmount').dataset.amount);
                    if (!Number.isFinite(amount) || amount <= 0 || amount > available + 0.009) {
                        showDrawerErr('بڕی ئامانەت لە بەردەست زیاترە'); return;
                    }
                    $('acAdjAmount').value = amount;
                    useAdvance = true; markSource();
                });
                $('acPaymentCustodyClear').addEventListener('click', () => {
                    useAdvance = false; $('acAdjAmount').value = p.amount; markSource();
                });
                $('acAdjAmount').addEventListener('input', markSource);
            }
            $('acApprovePayBtn').addEventListener('click', async () => {
                const enteredAmount = Number($('acAdjAmount').value || 0);
                if (!Number.isFinite(enteredAmount) || enteredAmount <= 0) { showDrawerErr('بڕ نادروستە'); return; }
                if (useAdvance && enteredAmount > available + 0.009) {
                    showDrawerErr('ئامانەتی بەردەست بەشی ئەم بڕە ناکات'); return;
                }
                const res = await postFinanceAction('approve_payment', {
                    payment_id: p.id,
                    amount: enteredAmount,
                    note: $('acAdjNote').value,
                    use_advance: useAdvance ? 1 : 0,
                }, $('acApprovePayBtn'));
                handleActionResult(res);
                if (res && res.ok) {
                    openPrintDialog(printUrl.replace('&autoprint=0', '&autoprint=1'), 'قەبزی وەرگرتنی پارە — ٢ کۆپی', true);
                }
            });
            $('acRejectBtn').addEventListener('click', async () => {
                const reason = $('acRejectReason').value.trim();
                if (!reason) { showDrawerErr('هۆکار پێویستە'); return; }
                const res = await postFinanceAction('reject_payment', { payment_id: p.id, reason }, $('acRejectBtn'));
                handleActionResult(res);
            });
        }
    }

    function renderAdvanceDrawer(data) {
        const a = data.advance || {};
        const st = statusInfoFor('advance', a.status);
        $('acDrawerTitle').innerHTML = '<i class="fas fa-hand-holding-usd" style="color:#1b5e20;"></i> قەبزی وەرگرتنی قەرزی کۆن ' + esc(a.advance_no || '');
        let html = '<div class="ac-payment-card"><div class="ac-payment-toolbar">'
            + '<span class="status-chip ' + st[1] + '">' + esc(st[0]) + '</span>'
            + '<button type="button" class="btn" id="acOldDebtPrintBtn"><i class="fas fa-print"></i> چاپکردنی قەبز</button></div>'
            + drawerHeader(data.patient, data.patient_id)
            + headGrid([
                ['ژمارەی قەبز', a.advance_no],
                ['بڕی وەرگیراو', nf(a.amount)],
                ['ڕێگای پارەدان', METHOD_LABELS[a.payment_method] || a.payment_method],
                ['بەروار', a.received_at],
                ['دکتۆر', a.doctor_name || '—'],
                ['وەرگیراو لەلایەن', a.received_by_name || '—'],
                ['دانراو لەسەر وەسڵ', nf(a.allocated_amount)],
                ['گەڕێندراوەتەوە', nf(a.refunded_amount)],
                ['ماوە (بەردەست)', nf(a.available_amount)],
            ]);
        if (a.notes) {
            html += '<div class="ac-payment-field"><label>تێبینی</label><textarea rows="2" readonly>' + esc(a.notes) + '</textarea></div>';
        }
        if (a.refund_reason) {
            html += '<div class="ac-payment-field"><label>هۆکاری گەڕاندنەوە</label><textarea rows="2" readonly>' + esc(a.refund_reason) + '</textarea></div>';
        }
        const allocations = data.allocations || [];
        if (allocations.length) {
            html += '<div class="ac-payment-section"><div class="ac-payment-section-title">دانراو لەسەر وەسڵەکان</div>'
                + '<table class="ac-table-mini"><thead><tr><th>وەسڵ</th><th>کەیس</th><th>بڕ</th><th>دۆخ</th></tr></thead><tbody>';
            allocations.forEach((allocation) => {
                html += '<tr><td dir="ltr">' + esc(allocation.invoice_no || '—') + '</td>'
                    + '<td dir="ltr">' + esc(allocation.case_number || '—') + '</td>'
                    + '<td class="ac-num">' + nf(allocation.amount) + '</td>'
                    + '<td>' + (allocation.reversed_at ? 'هەڵوەشاوەتەوە' : 'دانراوە') + '</td></tr>';
            });
            html += '</tbody></table></div>';
        }
        $('acDrawerBody').innerHTML = html + '</div>';
        $('acOldDebtPrintBtn').addEventListener('click', () => {
            openPrintDialog(APP_URL + '/patients/advance-receipt.php?id=' + encodeURIComponent(a.id)
                + '&autoprint=0', 'قەبزی وەرگرتنی قەرزی کۆن');
        });
    }

    function renderInstallmentDrawer(data) {
        const plan = data.plan || {};
        const summary = data.summary || {};
        const elig = data.invoice_remaining;
        const proposedTerms = data.proposed_terms || {};
        const st = statusInfoFor('installment', plan.status);

        // proposePlan() stores the doctor's real intent in the SEPARATE
        // proposed_down_payment column and leaves plan.down_payment at 0
        // until activation actually books it — so both the read-only
        // summary and the editable field's prefill must read
        // proposed_terms.proposed_down_payment, never plan.down_payment
        // directly (that's always 0 for a still-pending plan).
        const proposedDown = (proposedTerms.proposed_down_payment !== null && proposedTerms.proposed_down_payment !== undefined)
            ? proposedTerms.proposed_down_payment
            : (plan.down_payment || 0);

        // An approved (active/completed) plan opens locked; «تەعدیل» makes its
        // terms live (Installment::editApprovedPlan — still approved, same
        // approval date, schedule rebuilt, every collection kept).
        const isApprovedPlan = plan.status === 'active' || plan.status === 'completed';
        const editing = isApprovedPlan && !!data.can_edit_approved && isEditingApproved('installment', plan.id);

        $('acDrawerTitle').innerHTML = '<i class="fas fa-calendar-alt" style="color:#8764b8;"></i> پلانی قیست P-' + esc(plan.id);

        let html = drawerHeader(data.patient, data.patient_id);
        html += headGrid([
            ['کۆی پلان', nf(plan.total_amount)],
            // Once approved the real down payment is booked in down_payment;
            // before that only the doctor's proposal exists.
            isApprovedPlan ? ['پێشەکی', nf(plan.down_payment || 0)] : ['پێشەکی داواکراو', nf(proposedDown)],
            ['ژمارەی قیست', plan.number_of_installments],
            ['دەستپێک', plan.start_date],
        ]);
        html += '<div style="margin-bottom:10px;"><span class="status-chip ' + st[1] + '">' + esc(st[0]) + '</span></div>';
        if (isApprovedPlan) {
            html += approvedEditBarHtml({
                canEdit: !!data.can_edit_approved,
                editing: editing,
                blockReason: null,
                approvedOn: fmtDate(plan.approved_at),
                trail: data.approved_edits,
            });
        }
        if (plan.notes) html += '<div class="ac-fld-hint">تێبینی: ' + esc(plan.notes) + '</div>';
        if (plan.reject_reason) html += '<div class="ac-err" style="display:block;">هۆکاری ڕەتکردنەوە: ' + esc(plan.reject_reason) + '</div>';
        if (elig) html += '<div class="ac-fld-hint">' + (elig.allowed ? 'ماوەی وەسڵ: ' + nf(elig.remaining) : esc(elig.message || '')) + '</div>';

        const schedule = (summary && summary.schedule) || [];
        if (schedule.length) {
            html += '<div class="ac-section-title"><i class="fas fa-calendar-alt"></i> خشتەی قیست</div>';
            html += '<table class="ac-table-mini"><thead><tr><th>#</th><th>بەروار</th><th>بڕ</th><th>دراو</th><th>ماوە</th></tr></thead><tbody>';
            schedule.forEach((s) => {
                html += '<tr><td>' + esc(s.installment_number) + '</td><td dir="ltr">' + esc(s.due_date) + '</td><td class="ac-num">' + nf(s.amount) + '</td><td class="ac-num">' + nf(s.dynamic_paid) + '</td><td class="ac-num">' + nf(s.dynamic_remaining) + '</td></tr>';
            });
            html += '</tbody></table>';
        }

        const canAct = plan.status === 'pending';
        if (canAct) {
            html += '<div class="ac-form"><div class="ac-form-title"><i class="fas fa-edit"></i> ڕێکخستنی مەرجەکان (دەتوانرێت بگۆڕدرێت)</div>'
                + '<div class="ac-fld"><label>کۆی گشتی</label><input type="number" id="acPlanTotal" min="0" step="0.01" value="' + Number(plan.total_amount) + '"></div>'
                + '<div class="ac-fld"><label>پێشەکی</label><input type="number" id="acPlanDown" min="0" step="0.01" value="' + Number(proposedDown) + '"></div>'
                + '<div class="ac-fld"><label>ژمارەی قیست</label><input type="number" id="acPlanCount" min="1" max="60" value="' + Number(plan.number_of_installments) + '"></div>'
                + '<div class="ac-fld"><label>بەرواری دەستپێک</label><input type="date" id="acPlanStart" value="' + esc(plan.start_date) + '"></div>'
                + '<div class="ac-fld-hint" id="acPlanComputed"></div>'
                + '<div class="ac-form-actions"><button type="button" class="btn btn-primary" id="acActivateBtn"><i class="fas fa-check"></i> چالاککردن</button></div></div>'
                + '<div class="ac-form"><div class="ac-form-title"><i class="fas fa-times-circle" style="color:#c50f1f;"></i> ڕەتکردنەوە</div>'
                + '<div class="ac-fld"><label>هۆکار (پێویستە)</label><textarea id="acPlanRejectReason" rows="2"></textarea></div>'
                + '<div class="ac-form-actions"><button type="button" class="btn btn-danger" id="acPlanRejectBtn"><i class="fas fa-times"></i> ڕەتکردنەوە</button></div></div>'
                + '<div class="ac-err" id="acDrawerErr"></div>';
        }

        // The same four terms as activation, on the approved plan. The limits
        // come from the server (Installment::approvedEditLimits) so the form
        // says them before the server has to refuse them.
        const limits = data.edit_limits || {};
        const downEditable = limits.down_payment_max !== null && limits.down_payment_max !== undefined;
        if (editing) {
            html += '<div class="ac-form"><div class="ac-form-title"><i class="fas fa-edit"></i> تەعدیلی مەرجەکانی پلان</div>'
                + '<div class="ac-fld"><label>کۆی گشتی</label><input type="number" id="acPlanTotal" min="' + Number(limits.paid_so_far || 0) + '" step="0.01" value="' + Number(plan.total_amount) + '"></div>'
                + '<div class="ac-fld"><label>پێشەکی</label><input type="number" id="acPlanDown" min="0" step="0.01" value="' + Number(plan.down_payment || 0) + '"' + (downEditable ? ' max="' + Number(limits.down_payment_max) + '"' : ' readonly') + '></div>'
                + '<div class="ac-fld"><label>ژمارەی قیست</label><input type="number" id="acPlanCount" min="1" max="120" value="' + Number(plan.number_of_installments) + '"></div>'
                + '<div class="ac-fld"><label>بەرواری دەستپێک</label><input type="date" id="acPlanStart" value="' + esc(plan.start_date) + '"></div>'
                + '<div class="ac-fld-hint">پارەی دراو تا ئێستا: <b>' + nf(limits.paid_so_far || 0) + '</b> — کۆی نوێ لەمە کەمتر نابێت. '
                + (downEditable
                    ? 'پێشەکی لە ' + nf(limits.down_payment_max) + ' (وەرگیراوی ناو وەسڵ) زیاتر نابێت.'
                    : 'پێشەکی بە قەبزێکی جیا وەرگیراوە و لێرە ناگۆڕدرێت.')
                + '</div>'
                + '<div class="ac-fld-hint" id="acPlanComputed"></div>'
                + '<div class="ac-fld-hint">خشتەکە بە مەرجە نوێکان دووبارە دروست دەکرێتەوە؛ هەموو پارە دراوەکان وەک خۆیان دەمێننەوە و دووبارە بەسەر خشتە نوێیەکەدا دابەش دەکرێنەوە.</div>'
                + '<div class="ac-form-actions"><button type="button" class="btn btn-primary" id="acPlanEditSave"><i class="fas fa-save"></i> پاشەکەوت</button></div></div>'
                + '<div class="ac-err" id="acDrawerErr"></div>';
        }

        $('acDrawerBody').innerHTML = html;

        wireApprovedEditBar('installment', plan.id, editing, () => renderInstallmentDrawer(data));

        if (canAct || editing) {
            // installment_amount is dropped server-side (activatePlan and
            // editApprovedPlan always recompute it); shown here read-only /
            // live computed only, never posted.
            const recompute = () => {
                const total = Number($('acPlanTotal').value) || 0;
                const down = Number($('acPlanDown').value) || 0;
                const count = Math.max(1, Number($('acPlanCount').value) || 1);
                const per = Math.max(0, total - down) / count;
                $('acPlanComputed').textContent = 'بڕی هەر قیستێک (خۆکار دەژمێردرێت): ' + nf(per);
            };
            ['acPlanTotal', 'acPlanDown', 'acPlanCount'].forEach((id) => $(id).addEventListener('input', recompute));
            recompute();
        }

        if (editing) {
            $('acPlanEditSave').addEventListener('click', async () => {
                const payload = {
                    plan_id: plan.id,
                    'edits[total_amount]': $('acPlanTotal').value,
                    'edits[number_of_installments]': $('acPlanCount').value,
                    'edits[start_date]': $('acPlanStart').value,
                };
                // A down payment booked as its own receipt is never posted, so
                // a stale page cannot even ask to change it.
                if (downEditable) payload['edits[down_payment]'] = $('acPlanDown').value;
                const res = await postFinanceAction('edit_approved_installment', payload, $('acPlanEditSave'));
                finishApprovedEdit(res);
            });
        }

        if (canAct) {

            $('acActivateBtn').addEventListener('click', async () => {
                // Always send edits[down_payment] rather than omitting it to
                // let Installment::activatePlan()'s own proposed_down_payment
                // fallback govern — the #acPlanDown field is now correctly
                // prefilled with the proposed amount (see proposedDown
                // above), so an unedited submit already carries the right
                // value. Sending it explicitly (vs. conditionally omitting
                // on "untouched" state) needs no dirty-tracking and is
                // exactly equivalent whether the accountant changed it or
                // not.
                const res = await postFinanceAction('activate_installment', {
                    plan_id: plan.id,
                    'edits[total_amount]': $('acPlanTotal').value,
                    'edits[number_of_installments]': $('acPlanCount').value,
                    'edits[start_date]': $('acPlanStart').value,
                    'edits[down_payment]': $('acPlanDown').value,
                }, $('acActivateBtn'));
                handleActionResult(res);
            });
            $('acPlanRejectBtn').addEventListener('click', async () => {
                const reason = $('acPlanRejectReason').value.trim();
                if (!reason) { showDrawerErr('هۆکار پێویستە'); return; }
                const res = await postFinanceAction('reject_installment', { plan_id: plan.id, reason }, $('acPlanRejectBtn'));
                handleActionResult(res);
            });
        }
    }

    function renderXrayDrawer(data) {
        const r = data.request || {};
        const history = data.history || [];
        const st = statusInfoFor('xray', r.request_status);
        const requestNo = r.request_number || ('X-' + r.id);
        const originalAmount = r.original_amount !== null && r.original_amount !== undefined
            ? r.original_amount
            : r.amount;
        const finalAmount = r.final_amount !== null && r.final_amount !== undefined
            ? r.final_amount
            : r.amount;
        const patientMeta = [r.patient_code, r.patient_phone].filter(Boolean).map(esc).join(' · ');
        const teeth = Number(r.full_mouth) === 1
            ? 'تەواوی دەم'
            : (r.selected_teeth || r.determine_teeth || r.determine_details || '—');
        // Same convention as the invoice/voucher receipts: the plain "چاپ"
        // button just opens the sheet, ?copies=2&autoprint=1 is reserved for
        // the approve-then-print button below.
        const printUrl = APP_URL + '/xray/receipt.php?id=' + encodeURIComponent(r.id) + '&copies=2&autoprint=0';

        $('acDrawerTitle').innerHTML = '<i class="fas fa-x-ray" style="color:#b56a00;"></i> داواکاری ئێکسڕەی'
            + ' <span class="ac-xray-title-no">' + esc(requestNo) + '</span>';

        let html = '<div class="ac-xray-patient">'
            + '<div class="ac-xray-avatar"><i class="fas fa-user"></i></div>'
            + '<div class="ac-xray-patient-main"><strong>' + esc(r.patient_name || '—') + '</strong>'
            + (patientMeta ? '<div class="ac-xray-patient-meta">' + patientMeta + '</div>' : '') + '</div>'
            + '<div class="ac-xray-patient-side"><span class="status-chip ' + st[1] + '">' + esc(st[0]) + '</span>'
            + (data.patient_id ? '<a class="ac-statement-link" href="' + AC.statementUrl + '?patient_id=' + encodeURIComponent(data.patient_id) + '" target="_blank"><i class="fas fa-file-invoice-dollar"></i> کشف حساب</a>' : '')
            + '<button type="button" class="ac-xray-print" id="acXrayPrintBtn" title="چاپکردنی فۆڕمی ئێکسڕەی"><i class="fas fa-print"></i> چاپ</button>'
            + '</div></div>';

        const infoItems = [
            ['جۆری پشکنین', XRAY_EXAM_LABELS[r.xray_type] || r.xray_type || '—'],
            ['هۆکاری پشکنین', XRAY_REASON_LABELS[r.xray_reason] || r.xray_reason || '—'],
            ['ددان / ناوچە', teeth],
            // requested_by_name already resolves to the outside doctor when the
            // request is a referral; the suffix says so, since requested_by is
            // then the staff member who typed it rather than this doctor.
            ['دکتۆر', (r.requested_by_name || '—') + (r.external_doctor_name ? ' (دکتۆری دەرەوە)' : '')],
            ['کات', fmtDate(r.created_at)],
            ['پلەی گرنگی', r.priority === 'urgent' ? 'پەلەدار' : 'ئاسایی'],
            ['شێوازی پارە', XRAY_TYPE_LABELS[r.payment_type] || r.payment_type || '—'],
            ['ژمارەی کەیس', r.case_number || '—'],
        ];
        html += '<div class="ac-xray-info">';
        infoItems.forEach((item) => {
            html += '<div class="ac-xray-info-item"><span>' + esc(item[0]) + '</span><strong>' + esc(item[1]) + '</strong></div>';
        });
        html += '</div>';

        html += '<div class="ac-xray-money">'
            + '<div><span>کۆی گشتی</span><strong>' + nf(originalAmount) + '</strong></div>'
            + '<div class="is-discount"><span>داشکاندن</span><strong>' + nf(r.discount_amount || 0) + '</strong></div>'
            + '<div class="is-final"><span>کۆی کۆتایی</span><strong>' + nf(finalAmount) + '</strong></div>';
        // Present only once the request went through the debt channel — a
        // settlement that is part cash, part debt, or a debt being paid off.
        if (data.settlement) {
            html += '<div class="is-received"><span>بڕی وەرگیراوە</span><strong>' + nf(data.settlement.paid) + '</strong></div>'
                + '<div class="is-remaining"><span>ماوە</span><strong>' + nf(data.settlement.remaining) + '</strong></div>';
        }
        html += '</div>';

        if (r.reason_details) html += '<div class="ac-xray-note"><strong>وردەکاری داواکاری:</strong> ' + esc(r.reason_details) + '</div>';
        if (r.payment_reason) html += '<div class="ac-xray-note"><strong>هۆکاری شێوازی پارە:</strong> ' + esc(r.payment_reason) + '</div>';
        if (r.notes) html += '<div class="ac-xray-note"><strong>تێبینی:</strong> ' + esc(r.notes) + '</div>';
        if (r.return_reason) html += '<div class="ac-err" style="display:block;">هۆکاری گەڕاندنەوە: ' + esc(r.return_reason) + '</div>';

        const canAct = ['pending_accountant', 'returned_to_accountant'].includes(r.request_status);
        if (canAct) {
            const initialDecision = r.payment_type === 'debt' ? 'approve_debt' : 'approve_payment';
            // The four figures the accountant actually works with. بڕی وەرگیراوە
            // is the one that decides the settlement (see syncSettle below):
            // full → نەقد, 0 → قەرز, anything between → part cash now and the
            // rest as x-ray debt. ماوە is derived, never typed.
            const editDiscount = Number(r.discount_amount || 0);
            const editNet = Math.max(0, Number(r.amount || 0) - editDiscount);
            const initialReceived = initialDecision === 'approve_debt' ? 0 : editNet;
            html += '<div class="ac-xray-action"><div class="ac-xray-action-head"><span><i class="fas fa-cash-register"></i> پەسەندکردن و ناردن بۆ ئێکسڕەی</span><small>کۆی گشتی، داشکاندن و بڕی وەرگیراو لێرە ڕاستبکەرەوە</small></div>'
                + '<input type="hidden" id="xsAction" value="' + initialDecision + '">'
                + '<div class="ac-xray-choice" role="radiogroup" aria-label="شێوازی پارەدان">'
                + '<button type="button" class="ac-xray-choice-btn' + (initialDecision === 'approve_payment' ? ' is-selected' : '') + '" data-decision="approve_payment" aria-pressed="' + (initialDecision === 'approve_payment' ? 'true' : 'false') + '"><i class="fas fa-money-bill-wave"></i> نەقد / پارەدان</button>'
                + '<button type="button" class="ac-xray-choice-btn' + (initialDecision === 'approve_debt' ? ' is-selected' : '') + '" data-decision="approve_debt" aria-pressed="' + (initialDecision === 'approve_debt' ? 'true' : 'false') + '"><i class="fas fa-hand-holding-usd"></i> قەرز</button>'
                + '</div>'
                + '<div class="ac-xray-edit-grid">'
                + '<div class="ac-fld"><label for="xsAmount">کۆی گشتی</label><input type="number" id="xsAmount" step="1000" min="0" value="' + Number(r.amount || 0) + '"></div>'
                + '<div class="ac-fld"><label for="xsDiscount">داشکاندن</label><input type="number" id="xsDiscount" step="1000" min="0" value="' + editDiscount + '"></div>'
                + '<div class="ac-fld"><label for="xsReceived">بڕی وەرگیراوە</label><input type="number" id="xsReceived" step="1000" min="0" value="' + initialReceived + '"></div>'
                + '<div class="ac-fld"><label for="xsRemaining">ماوە</label><input type="number" id="xsRemaining" readonly tabindex="-1" value="' + Math.max(0, editNet - initialReceived) + '"></div>'
                // The stored reason is set as a .value after render, not as an
                // attribute: esc() does not escape a double quote, which would
                // break out of value="…".
                + '<div class="ac-fld is-wide"><label>هۆکاری داشکاندن</label><input type="text" id="xsReason" placeholder="تەنها ئەگەر داشکاندن هەیە"></div>'
                + '<div class="ac-fld is-wide"><label>تێبینی ژمێریار</label><textarea id="xsNote" rows="2" placeholder="تێبینی ئارەزوومەندانە..."></textarea></div>'
                + '</div>'
                + '<div class="ac-xray-settle-hint" id="xsHint"></div>'
                + '<div class="ac-form-actions">'
                + '<button type="button" class="btn btn-primary" id="xsSubmitPrint"><i class="fas fa-print"></i> پەسەندکردن و چاپکردن (٢ کۆپی)</button>'
                + '<button type="button" class="btn" id="xsSubmit"><i class="fas fa-check"></i> پەسەندکردن و ناردن</button>'
                + '</div>'
                + '<div class="ac-err" id="acDrawerErr"></div></div>';
        }

        // An approved X-ray opens locked; «تەعدیل» makes its money live
        // (XrayManager::editApprovedSettlement — still approved, same approval
        // date, the X-ray room's own status untouched). The form starts from
        // data.approved_money, the same reading the server edits from.
        const isApprovedX = !!data.is_approved;
        const editingX = isApprovedX && !!data.can_edit_approved && isEditingApproved('xray', r.id);
        const money = data.approved_money || {};
        const later = Number(money.later || 0);
        if (isApprovedX) {
            html += approvedEditBarHtml({
                canEdit: !!data.can_edit_approved,
                editing: editingX,
                blockReason: data.edit_block_reason,
                approvedOn: fmtDate(r.accountant_approved_at),
                trail: data.approved_edits,
            });
        }
        if (editingX) {
            html += '<div class="ac-xray-action"><div class="ac-xray-action-head"><span><i class="fas fa-pen"></i> تەعدیلی پارەی ئێکسڕەی</span><small>پەسەندکراو دەمێنێتەوە و بەرواری پەسەندکردن ناگۆڕێت</small></div>'
                + '<div class="ac-xray-edit-grid">'
                + '<div class="ac-fld"><label for="xeAmount">کۆی گشتی</label><input type="number" id="xeAmount" step="1000" min="0" value="' + Number(r.amount || 0) + '"></div>'
                + '<div class="ac-fld"><label for="xeDiscount">داشکاندن</label><input type="number" id="xeDiscount" step="1000" min="0" value="' + Number(r.discount_amount || 0) + '"></div>'
                + '<div class="ac-fld"><label for="xeReceived">وەرگیراو لە کاتی پەسەندکردن</label><input type="number" id="xeReceived" step="1000" min="0" value="' + Number(money.received || 0) + '"></div>'
                + '<div class="ac-fld"><label for="xeRemaining">ماوە</label><input type="number" id="xeRemaining" readonly tabindex="-1" value="' + Number(money.remaining || 0) + '"></div>'
                + (later > 0.009
                    ? '<div class="ac-fld is-wide"><label>پارەی دواتر کۆکراوە (ناگۆڕدرێت)</label><input type="number" readonly tabindex="-1" value="' + later + '"></div>'
                    : '')
                // Reason set as .value after render — see xsReason above.
                + '<div class="ac-fld is-wide"><label>هۆکاری داشکاندن</label><input type="text" id="xeReason" placeholder="تەنها ئەگەر داشکاندن هەیە"></div>'
                + '<div class="ac-fld is-wide"><label>هۆکاری تەعدیل (ئارەزوومەندانە)</label><textarea id="xeNote" rows="2" placeholder="بۆچی دەگۆڕدرێت..."></textarea></div>'
                + '</div>'
                + '<div class="ac-xray-settle-hint" id="xeHint"></div>'
                + '<div class="ac-form-actions"><button type="button" class="btn btn-primary" id="xeSave"><i class="fas fa-save"></i> پاشەکەوت</button></div>'
                + '<div class="ac-err" id="acDrawerErr"></div></div>';
        }

        if (history.length) {
            html += '<details class="ac-xray-history"><summary><i class="fas fa-history"></i> مێژووی گۆڕانکاری (' + nf(history.length) + ')</summary>';
            html += '<table class="ac-table-mini"><thead><tr><th>کردار</th><th>لەلایەن</th><th>کات</th></tr></thead><tbody>';
            history.forEach((h) => {
                html += '<tr><td>' + esc(String(h.action_type || '').replace(/_/g, ' ')) + '</td><td>' + esc(h.changed_by_name || '—') + '</td><td dir="ltr" style="font-size:11px;color:#888;">' + esc(fmtDate(h.created_at)) + '</td></tr>';
            });
            html += '</tbody></table></details>';
        }

        $('acDrawerBody').innerHTML = html;

        if ($('acXrayPrintBtn')) {
            $('acXrayPrintBtn').addEventListener('click', () => {
                openPrintDialog(printUrl, 'فۆڕمی داواکاری ئێکسڕەی');
            });
        }

        wireApprovedEditBar('xray', r.id, editingX, () => renderXrayDrawer(data));
        if (editingX) {
            $('xeReason').value = r.discount_reason || '';
            // The same one-source-of-truth rule as the settle form below, plus
            // what was collected after approval, which is fixed money: ماوە =
            // پوختە − وەرگیراو − کۆکراوەی دواتر, and وەرگیراو can never push
            // past what is left for it.
            const syncEdit = () => {
                const net = Math.max(0, Number($('xeAmount').value || 0) - Number($('xeDiscount').value || 0));
                const room = Math.max(0, net - later);
                let received = Number($('xeReceived').value || 0);
                if (!(received >= 0)) received = 0;
                if (received > room) { received = room; $('xeReceived').value = room; }
                const remaining = Math.round((room - received) * 100) / 100;
                $('xeRemaining').value = remaining;
                const hint = $('xeHint');
                if (net + 0.009 < later) {
                    hint.className = 'ac-xray-settle-hint is-debt';
                    hint.innerHTML = '<i class="fas fa-exclamation-triangle"></i> کۆی کۆتایی لە پارەی دواتر کۆکراوە (' + nf(later) + ') کەمترە — پاشەکەوت ناکرێت';
                } else if (remaining <= 0.009) {
                    hint.className = 'ac-xray-settle-hint is-cash';
                    hint.innerHTML = '<i class="fas fa-money-bill-wave"></i> تەواو دراوە' + (later > 0.009 ? ' (لەگەڵ ' + nf(later) + ' ی دواتر)' : '');
                } else if (received <= 0.009 && later <= 0.009) {
                    hint.className = 'ac-xray-settle-hint is-debt';
                    hint.innerHTML = '<i class="fas fa-hand-holding-usd"></i> هەمووی قەرزە: ' + nf(remaining);
                } else {
                    hint.className = 'ac-xray-settle-hint is-partial';
                    hint.innerHTML = '<i class="fas fa-coins"></i> دراو: ' + nf(received + later) + ' — ماوە بۆ قەرز: ' + nf(remaining);
                }
            };
            ['xeAmount', 'xeDiscount', 'xeReceived'].forEach((id) => $(id).addEventListener('input', syncEdit));
            syncEdit();

            $('xeSave').addEventListener('click', async () => {
                syncEdit();
                const disc = Number($('xeDiscount').value || 0);
                const reason = $('xeReason').value.trim();
                if (disc > Number($('xeAmount').value || 0) + 0.009) { showDrawerErr('داشکاندن ناتوانێت لە کۆی گشتی زیاتر بێت'); return; }
                if (disc > 0 && reason === '') { showDrawerErr('تکایە هۆکاری داشکاندن بنووسە'); return; }
                const res = await postFinanceAction('edit_approved_xray', {
                    id: r.id,
                    new_amount: $('xeAmount').value,
                    discount_amount: $('xeDiscount').value,
                    discount_reason: reason,
                    received_amount: $('xeReceived').value,
                    note: $('xeNote').value,
                }, $('xeSave'));
                finishApprovedEdit(res);
            });
        }

        if (canAct) {
            if ($('xsReason')) $('xsReason').value = r.discount_reason || '';

            const netNow = () => Math.max(0, Number($('xsAmount').value || 0) - Number($('xsDiscount').value || 0));

            // One source of truth: بڕی وەرگیراوە. The نەقد/قەرز buttons only
            // set it to the two obvious values, and everything else — ماوە,
            // which button reads as selected, and the decision actually posted
            // — is derived from it, so the screen can never claim one
            // settlement while the server performs another. api/finance-actions
            // .php runs the same three-way rule on the posted figure.
            function syncSettle() {
                const net = netNow();
                let received = Number($('xsReceived').value || 0);
                if (!(received >= 0)) received = 0;
                if (received > net) { received = net; $('xsReceived').value = net; }
                const remaining = Math.round((net - received) * 100) / 100;
                $('xsRemaining').value = remaining;

                const decision = remaining <= 0.009 ? 'approve_payment' : 'approve_debt';
                $('xsAction').value = decision;
                document.querySelectorAll('.ac-xray-choice-btn[data-decision]').forEach((choice) => {
                    const selected = choice.dataset.decision === decision;
                    choice.classList.toggle('is-selected', selected);
                    choice.setAttribute('aria-pressed', selected ? 'true' : 'false');
                });

                const hint = $('xsHint');
                if (!hint) return;
                if (net <= 0.009) {
                    hint.className = 'ac-xray-settle-hint';
                    hint.innerHTML = '<i class="fas fa-gift"></i> بێ بەرامبەر — هیچ بڕێک وەرناگیرێت';
                } else if (remaining <= 0.009) {
                    hint.className = 'ac-xray-settle-hint is-cash';
                    hint.innerHTML = '<i class="fas fa-money-bill-wave"></i> هەموو بڕەکە بە نەقد وەردەگیرێت: ' + nf(received);
                } else if (received <= 0.009) {
                    hint.className = 'ac-xray-settle-hint is-debt';
                    hint.innerHTML = '<i class="fas fa-hand-holding-usd"></i> هەموو بڕەکە دەچێتە سەر قەرزی نەخۆش: ' + nf(remaining);
                } else {
                    hint.className = 'ac-xray-settle-hint is-partial';
                    hint.innerHTML = '<i class="fas fa-coins"></i> نەقد: ' + nf(received) + ' — ماوە بۆ قەرز: ' + nf(remaining);
                }
            }

            document.querySelectorAll('.ac-xray-choice-btn[data-decision]').forEach((btn) => {
                btn.addEventListener('click', () => {
                    $('xsReceived').value = btn.dataset.decision === 'approve_debt' ? 0 : netNow();
                    syncSettle();
                });
            });
            ['xsAmount', 'xsDiscount', 'xsReceived'].forEach((id) => {
                if ($(id)) $(id).addEventListener('input', syncSettle);
            });
            syncSettle();

            // Both buttons settle the same way; only one of them also prints.
            const settlePayload = () => {
                syncSettle();
                const decision = $('xsAction').value;
                if (!decision) { showDrawerErr('تکایە کردارێک هەڵبژێرە'); return null; }
                const discVal = Number($('xsDiscount').value || 0);
                const reason = $('xsReason').value.trim();
                if (discVal > 0 && reason === '') { showDrawerErr('تکایە هۆکاری داشکاندن بنووسە'); return null; }
                if (discVal > Number($('xsAmount').value || 0) + 0.009) {
                    showDrawerErr('داشکاندن ناتوانێت لە کۆی گشتی زیاتر بێت');
                    return null;
                }
                return {
                    id: r.id,
                    decision: decision,
                    new_amount: $('xsAmount').value,
                    discount_amount: $('xsDiscount').value,
                    discount_reason: reason,
                    received_amount: $('xsReceived').value,
                    note: $('xsNote').value,
                };
            };

            $('xsSubmit').addEventListener('click', async () => {
                const payload = settlePayload();
                if (!payload) return;
                const res = await postFinanceAction('xray_settle', payload, $('xsSubmit'));
                handleActionResult(res);
            });

            // Two identical A5 pages in one print job: patient and clinic copies.
            const receiptUrl = () => APP_URL + '/xray/receipt.php?id=' + encodeURIComponent(r.id)
                + '&copies=2&autoprint=1';
            $('xsSubmitPrint').addEventListener('click', async () => {
                const payload = settlePayload();
                if (!payload) return;
                const res = await postFinanceAction('xray_settle', payload, $('xsSubmitPrint'));
                // `stale` means someone settled it a moment earlier — the form
                // is still the right thing to print.
                const printIt = res && (res.ok || res.stale);
                // Settle first, then print on top of what handleActionResult left.
                handleActionResult(res);
                if (printIt) openPrintDialog(receiptUrl(), 'فۆڕمی داواکاری ئێکسڕەی', true);
            });
        }
    }

    function renderStationDrawer(data) {
        const s = data.station || {};
        const patient = { full_name: s.patient_name, phone: s.patient_phone, patient_code: s.patient_code };

        $('acDrawerTitle').innerHTML = '<i class="fas fa-chair" style="color:#00695c;"></i> ژووری چاوەڕوانی';

        let html = drawerHeader(patient, data.patient_id);
        html += headGrid([
            ['دکتۆر', s.doctor_name],
            ['کاتی گەیشتن', fmtDate(s.moved_at || s.check_in_time)],
            ['قەرزی نەخۆش', nf(data.debt || 0)],
        ]);
        if (s.flow_note) html += '<div class="ac-fld-hint">تێبینی: ' + esc(s.flow_note) + '</div>';
        // No dedicated action here by design — the queue empties by acting
        // (an invoice/payment/x-ray request being created for this patient
        // elsewhere), and "take payment" happens on the patient statement
        // page's own receive-payment dialog, not a duplicated picker here.
        if (data.patient_id) {
            html += '<div class="ac-form-actions" style="margin-top:14px;">'
                + '<a class="btn btn-primary" href="' + AC.statementUrl + '?patient_id=' + encodeURIComponent(data.patient_id) + '" target="_blank">'
                + '<i class="fas fa-money-bill-wave"></i> کردنەوەی کشف حساب بۆ وەرگرتنی پارە</a></div>';
        }
        $('acDrawerBody').innerHTML = html;
    }

    // ---------------------------------------------------------------
    // وەسڵی کارە ئەنجامدراوەکان (arch work receipt)
    // ---------------------------------------------------------------

    /**
     * An arch visit adds no charge — the job was priced once when the case was
     * opened — so this drawer shows the WORK as a plain list with no prices,
     * and settles money against the patient's existing debt: قەرزی پێشوو, a
     * دراو the accountant types, and the ماوە that leaves. Approving prints one
     * document; no separate payment voucher is produced.
     */
    function renderArchWorkDrawer(data) {
        const rec = data.receipt || {};
        const works = data.works || [];
        const money = data.money || {};
        const st = statusInfoFor('archwork', rec.status);
        const canAct = rec.status === 'sent';
        const debt = Number(money.debt) || 0;

        $('acDrawerTitle').innerHTML = '<i class="fas fa-clipboard-list" style="color:#1a56a8;"></i> وەسڵی کارە ئەنجامدراوەکان '
            + esc(rec.receipt_no || '');
        if ($('acDrawerSub')) {
            $('acDrawerSub').innerHTML = invoiceSubheaderHtml(data.patient, data.patient_id, {});
        }

        const printUrl = APP_URL + '/cases/arch-work-receipt.php?receipt=' + encodeURIComponent(rec.id)
            + '&autoprint=0';

        let html = '<div class="ac-payment-card">'
            + '<div class="ac-payment-toolbar">'
            + '<span class="status-chip ' + st[1] + '">' + esc(st[0]) + '</span>'
            + '<button type="button" class="btn" id="acAwPrintBtn"><i class="fas fa-print"></i> چاپکردنی وەسڵ</button>'
            + '</div>';

        html += '<div class="ac-fin" style="margin-top:10px;">'
            + '<div class="ac-fin-box" style="background:#eef2f6;color:#333;"><span class="n">' + esc(rec.case_number || '—') + '</span><span class="l">کەیس</span></div>'
            + '<div class="ac-fin-box" style="background:#e8f0fe;color:#1a56a8;"><span class="n">' + works.length + '</span><span class="l">کار</span></div>'
            + '<div class="ac-fin-box" style="background:#fdf3f4;color:#b42318;"><span class="n">' + nf(debt) + '</span><span class="l">قەرزی پێشوو</span></div>'
            + '</div>';

        // The works themselves — no price column anywhere on this document.
        html += '<table class="ac-aw-table">'
            + '<thead><tr>'
            + '<th style="width:34px;">ژ</th><th style="width:90px;">بەروار</th>'
            + '<th>کاری ئەنجامدراو</th><th style="width:80px;">ئەرک</th><th>تێبینی</th>'
            + '</tr></thead><tbody>';
        if (!works.length) {
            html += '<tr><td colspan="5" style="text-align:center;color:#999;padding:10px;">هیچ کارێک نییە</td></tr>';
        }
        works.forEach((w, i) => {
            html += '<tr>'
                + '<td>' + (i + 1) + '</td>'
                + '<td dir="ltr">' + esc((w.procedure_date || '').slice(0, 10)) + '</td>'
                + '<td style="text-align:start;font-weight:600;">' + esc(w.display_name || '—') + '</td>'
                + '<td>' + esc(ARCH_LABELS[w.arch_affected] || w.arch_affected || '—') + '</td>'
                + '<td style="text-align:start;color:#667;">' + esc(w.notes || '') + '</td>'
                + '</tr>';
        });
        html += '</tbody></table>';

        if (data.doctor_note) {
            html += '<div class="ac-inv-docnote"><span class="ac-inv-docnote-label">تێبینی دکتۆر</span>'
                + '<span class="ac-inv-docnote-val">' + esc(data.doctor_note) + '</span></div>';
        }

        // Settlement block: قەرزی پێشوو / دراو / ماوە
        // Same footer wrapper the invoice pane uses, so the settlement stack
        // sits at the same width and reads identically on both dialogs.
        html += '<div class="vt-foot ac-aw-foot"><div class="vt-foot-totals">'
            + '<div class="vt-total-row"><span class="vt-total-label">قەرزی پێشوو :</span>'
            + '<span class="vt-total-box">' + nf(debt) + '</span></div>';
        if (canAct) {
            html += '<div class="vt-total-row"><span class="vt-total-label">دراو :</span>'
                + '<span class="vt-total-box vt-disc-box">'
                + '<input type="number" class="vt-disc-input" id="acAwPaid" min="0" step="0.01" max="' + debt + '" '
                + 'value="" placeholder="0"></span></div>'
                + '<div class="vt-total-row"><span class="vt-total-label">ماوە :</span>'
                + '<span class="vt-total-box' + (debt > 0.009 ? ' is-due' : '') + '" id="acAwRemaining">' + nf(debt) + '</span></div>'
                + '<div class="vt-total-row"><span class="vt-total-label">جۆری پارەدان :</span>'
                + '<span class="vt-total-box vt-disc-box"><select id="acAwMethod" class="vt-disc-input">'
                + '<option value="cash">نەقد</option><option value="card">کارت</option>'
                + '<option value="transfer">گواستنەوە</option><option value="other">هی تر</option>'
                + '</select></span></div>';
        } else {
            html += '<div class="vt-total-row"><span class="vt-total-label">دراو :</span>'
                + '<span class="vt-total-box is-paid">' + nf(money.paid) + '</span></div>'
                + '<div class="vt-total-row"><span class="vt-total-label">ماوە :</span>'
                + '<span class="vt-total-box' + ((Number(money.remaining) || 0) > 0.009 ? ' is-due' : '') + '">'
                + nf(money.remaining) + '</span></div>';
        }
        html += '</div></div>';   // .vt-foot-totals, .vt-foot
        html += '</div>';         // .ac-payment-card

        if (canAct) {
            html += '<div class="ac-form"><div class="ac-form-title"><i class="fas fa-check-circle" style="color:#107c10;"></i> پەسەندکردن</div>'
                + '<div class="ac-fld"><label>تێبینی ژمێریاری (ئارەزوومەندانە)</label><textarea id="acAwNotes" rows="2"></textarea></div>'
                + '<div class="ac-form-actions">'
                + '<button type="button" class="btn btn-primary" id="acAwApprovePrintBtn"><i class="fas fa-print"></i> پەسەندکردن و چاپکردن</button>'
                + '<button type="button" class="btn" id="acAwApproveBtn"><i class="fas fa-check"></i> پەسەندکردن</button>'
                + '</div></div>'
                + '<div class="ac-form"><div class="ac-form-title"><i class="fas fa-undo" style="color:#c50f1f;"></i> گەڕاندنەوە</div>'
                + '<div class="ac-fld"><label>هۆکار (پێویستە)</label><textarea id="acAwReason" rows="2" placeholder="هۆکاری گەڕاندنەوە بنووسە..."></textarea></div>'
                + '<div class="ac-form-actions"><button type="button" class="btn btn-danger" id="acAwReturnBtn"><i class="fas fa-undo"></i> گەڕاندنەوە</button></div></div>';
        }
        html += '<div class="ac-err" id="acDrawerErr"></div>';

        $('acDrawerBody').innerHTML = html;

        if ($('acAwPrintBtn')) {
            $('acAwPrintBtn').addEventListener('click', () => {
                openPrintDialog(printUrl, 'وەسڵی کارە ئەنجامدراوەکان');
            });
        }

        if (!canAct) return;

        // ماوە follows the typed دراو live, so the accountant sees what the
        // patient will still owe before committing.
        const paidInput = $('acAwPaid');
        const remainingBox = $('acAwRemaining');
        const recompute = () => {
            const paid = Math.min(debt, Math.max(0, Number(paidInput.value) || 0));
            const left = Math.max(0, debt - paid);
            remainingBox.textContent = nf(left);
            remainingBox.classList.toggle('is-due', left > 0.009);
        };
        paidInput.addEventListener('input', recompute);

        const payload = () => ({
            receipt_id: rec.id,
            paid_amount: Math.min(debt, Math.max(0, Number(paidInput.value) || 0)),
            payment_method: $('acAwMethod').value,
            accounting_notes: $('acAwNotes').value,
        });
        // Two A5 copies — patient and clinic file — of the ONE document.
        const receiptUrl = () => APP_URL + '/cases/arch-work-receipt.php?receipt='
            + encodeURIComponent(rec.id) + '&copies=2&autoprint=1';

        $('acAwApproveBtn').addEventListener('click', async () => {
            const res = await postFinanceAction('approve_arch_work', payload(), $('acAwApproveBtn'));
            handleActionResult(res);
        });
        $('acAwApprovePrintBtn').addEventListener('click', async () => {
            const res = await postFinanceAction('approve_arch_work', payload(), $('acAwApprovePrintBtn'));
            const printIt = res && (res.ok || res.stale);
            // Settle first, then print on top of what handleActionResult left.
            handleActionResult(res);
            if (printIt) openPrintDialog(receiptUrl(), 'وەسڵی کارە ئەنجامدراوەکان', true);
        });
        $('acAwReturnBtn').addEventListener('click', async () => {
            const reason = $('acAwReason').value.trim();
            if (!reason) { showDrawerErr('هۆکار پێویستە'); return; }
            const res = await postFinanceAction('return_arch_work', { receipt_id: rec.id, reason }, $('acAwReturnBtn'));
            handleActionResult(res);
        });
    }

    const RENDERERS = {
        invoice: renderInvoiceDrawer,
        archwork: renderArchWorkDrawer,
        payment: renderPaymentDrawer,
        advance: renderAdvanceDrawer,
        installment: renderInstallmentDrawer,
        xray: renderXrayDrawer,
        station: renderStationDrawer,
    };

    function fetchDrawerDetail(kind, id) {
        const request = () => fetch(AC.listUrl + '?action=detail&kind=' + encodeURIComponent(kind) + '&id=' + encodeURIComponent(id))
            .then((res) => res.json());

        if (kind !== 'xray') return request();

        const key = String(id);
        const cached = xrayDetailCache.get(key);
        if (cached && Date.now() - cached.at < XRAY_DETAIL_TTL) return cached.promise;

        const promise = request().then((data) => {
            if (!data || !data.ok) xrayDetailCache.delete(key);
            return data;
        }).catch((error) => {
            xrayDetailCache.delete(key);
            throw error;
        });
        xrayDetailCache.set(key, { at: Date.now(), promise: promise });
        return promise;
    }

    function renderXrayOpening(row, id) {
        const number = row && row.number ? row.number : ('X-' + id);
        $('acDrawerTitle').innerHTML = '<i class="fas fa-x-ray" style="color:#b56a00;"></i> داواکاری ئێکسڕەی'
            + ' <span class="ac-xray-title-no">' + esc(number) + '</span>';

        let patient = '';
        if (row) {
            const meta = [row.patient_phone, row.doctor_name].filter(Boolean).map(esc).join(' · ');
            patient = '<div class="ac-xray-patient">'
                + '<div class="ac-xray-avatar"><i class="fas fa-user"></i></div>'
                + '<div class="ac-xray-patient-main"><strong>' + esc(row.patient_name || '—') + '</strong>'
                + (meta ? '<div class="ac-xray-patient-meta">' + meta + '</div>' : '') + '</div>'
                + '<strong class="ac-num" style="color:#14713b;">' + nf(row.amount) + '</strong></div>';
        }
        $('acDrawerBody').innerHTML = '<div class="ac-xray-dialog-loading">' + patient
            + '<div class="ac-xray-loading-line"></div><div class="ac-xray-loading-line is-short"></div></div>';
    }

    async function openDrawer(kind, id) {
        const requestSeq = ++drawerRequestSeq;
        const overlay = $('acDrawerOverlay');
        const drawer = $('acDrawer');
        const wasOpen = overlay.classList.contains('open');
        if (!wasOpen) drawerPreviousFocus = document.activeElement;
        // Edit mode belongs to one approved record: a refetch of that same
        // record (after a save) keeps it, opening anything else ends it.
        if (!isEditingApproved(kind, id)) AC.editApproved = null;
        AC.currentDrawer = { kind: kind, id: id };
        if (kind === 'xray') {
            const row = AC.rows.find((item) => item.kind === 'xray' && String(item.id) === String(id));
            renderXrayOpening(row, id);
        } else {
            const km = KIND_META[kind];
            $('acDrawerTitle').textContent = km ? km.label : 'وردەکاری';
            $('acDrawerBody').innerHTML = '<div class="ac-loading"><i class="fas fa-spinner fa-spin"></i> بارکردن...</div>';
        }
        // Reset the persistent subheader slot on every open — only
        // renderInvoiceDrawer() (via invoiceSubheaderHtml()) ever writes into
        // it; every other kind leaves it empty, and CSS (.ac-drawer-sub:empty)
        // hides it so no invoice identity row leaks into another detail view.
        if ($('acDrawerSub')) $('acDrawerSub').innerHTML = '';
        // Invoice, arch work, payment, and X-ray are centered dialogs. X-ray has
        // its own compact form width; installment/station keep the side drawer.
        // The arch work receipt is read like an invoice — a works table plus a
        // settlement block — so it belongs in the same wide centered dialog.
        const isModal = kind === 'invoice' || kind === 'archwork' || kind === 'payment' || kind === 'advance' || kind === 'xray';
        overlay.classList.toggle('is-modal', isModal);
        drawer.classList.toggle('is-modal', isModal);
        drawer.classList.toggle('is-payment-modal', kind === 'payment' || kind === 'advance');
        drawer.classList.toggle('is-xray-modal', kind === 'xray');
        overlay.classList.add('open');
        overlay.setAttribute('aria-hidden', 'false');
        drawer.setAttribute('aria-busy', 'true');
        // A ✏️ open hands focus to settleEditFocus() once the record is drawn
        // («تەعدیل», or this close button when there is none).
        if (!wasOpen && !AC.focusEditBtn) requestAnimationFrame(() => $('acDrawerClose').focus());
        try {
            const data = await fetchDrawerDetail(kind, id);
            if (requestSeq !== drawerRequestSeq || !AC.currentDrawer
                || AC.currentDrawer.kind !== kind || String(AC.currentDrawer.id) !== String(id)) return;
            drawer.setAttribute('aria-busy', 'false');
            if (!data.ok) {
                $('acDrawerBody').innerHTML = '<div class="ac-empty-drawer">هەڵە: ' + esc(data.error || 'نەدۆزرایەوە') + '</div>';
                return;
            }
            const renderer = RENDERERS[kind];
            if (renderer) renderer(data);
            else $('acDrawerBody').innerHTML = '<div class="ac-empty-drawer">جۆرێکی نەزانراو</div>';
        } catch (e) {
            if (requestSeq !== drawerRequestSeq) return;
            drawer.setAttribute('aria-busy', 'false');
            $('acDrawerBody').innerHTML = '<div class="ac-empty-drawer">هەڵەی پەیوەندی ڕوویدا</div>';
        }
    }

    function closeDrawer() {
        drawerRequestSeq += 1;
        const overlay = $('acDrawerOverlay');
        overlay.classList.remove('open');
        overlay.setAttribute('aria-hidden', 'true');
        $('acDrawer').setAttribute('aria-busy', 'false');
        AC.currentDrawer = null;
        AC.editApproved = null; // an approved record always reopens locked
        AC.focusEditBtn = false;
        if (drawerPreviousFocus && typeof drawerPreviousFocus.focus === 'function') {
            drawerPreviousFocus.focus();
        }
        drawerPreviousFocus = null;
    }

    if ($('acDrawerClose')) $('acDrawerClose').addEventListener('click', closeDrawer);
    if ($('acDrawerOverlay')) {
        $('acDrawerOverlay').addEventListener('click', (e) => {
            if (e.target.id === 'acDrawerOverlay') closeDrawer();
        });
    }
    document.addEventListener('keydown', (e) => {
        if (e.key !== 'Escape') return;
        // The print dialog stacks on top of the detail it was printed from, so
        // Escape closes that first and leaves the detail open behind it.
        if (closePrintDialog()) return;
        if ($('acDrawerOverlay') && $('acDrawerOverlay').classList.contains('open')) closeDrawer();
    });

    // ---------------------------------------------------------------
    // ئامانەت — taking money before a case exists
    //
    // The queue's own search filters ROWS of existing requests, so a patient
    // who has never had a request cannot be found with it. This searches
    // patients directly, takes the money against the patient, and prints them
    // a numbered receipt. Which case it eventually settles is the doctor's
    // decision, made later from the invoice's «ئامانەت» line in cases/view.php.
    //
    // Placed down here, after postFinanceAction/openPrintDialog, so it reads in
    // the order it depends on rather than relying on hoisting.
    // ---------------------------------------------------------------

    const advanceState = { patient: null, timer: null };

    function advanceErr(msg) {
        const box = $('acAdvanceErr');
        if (box) { box.textContent = msg || ''; box.style.display = msg ? 'block' : 'none'; }
    }

    function advanceSelect(patient) {
        advanceState.patient = patient;
        $('acAdvanceResults').innerHTML = '';
        $('acAdvanceSearch').value = patient.full_name || '';
        $('acAdvancePicked').style.display = '';
        $('acAdvancePatientName').textContent = patient.full_name || '—';
        const bal = Number(patient.advance_available || 0);
        $('acAdvancePatientBal').textContent = bal > 0.009
            ? 'ئامانەتی بەردەستی ئێستا: ' + nf(bal)
            : 'ئێستا هیچ ئامانەتێکی نییە';
        $('acAdvanceOk').disabled = false;
        // دکتۆر is the first thing still to fill in once a patient is picked.
        if ($('acAdvanceDoctor')) $('acAdvanceDoctor').focus();
        else $('acAdvanceAmount').focus();
    }

    async function advanceSearch(q) {
        const box = $('acAdvanceResults');
        if (!box) return;
        if (q.length < 2) { box.innerHTML = ''; return; }
        try {
            const res = await fetch(AC.listUrl + '?action=patient_search&q=' + encodeURIComponent(q));
            const data = await res.json();
            if (!data.ok) { box.innerHTML = ''; return; }
            if (!data.rows.length) {
                box.innerHTML = '<div class="ac-advance-note">هیچ نەخۆشێک نەدۆزرایەوە</div>';
                return;
            }
            box.innerHTML = data.rows.map((r, i) => {
                const bal = Number(r.advance_available || 0);
                return '<button type="button" class="ac-advance-hit" data-hit="' + i + '">'
                    + '<span class="hit-main"><span class="hit-name">' + esc(r.full_name || '—') + '</span>'
                    + '<span class="hit-meta">' + esc([r.patient_code, r.phone].filter(Boolean).join(' · ') || '—') + '</span></span>'
                    + (bal > 0.009 ? '<span class="hit-bal">' + nf(bal) + '</span>' : '')
                    + '</button>';
            }).join('');
            box.querySelectorAll('[data-hit]').forEach((btn) => {
                btn.addEventListener('click', () => advanceSelect(data.rows[Number(btn.dataset.hit)]));
            });
        } catch (e) {
            box.innerHTML = '';
        }
    }

    function openAdvanceDialog() {
        advanceState.patient = null;
        $('acAdvanceSearch').value = '';
        $('acAdvanceResults').innerHTML = '';
        $('acAdvancePicked').style.display = 'none';
        if ($('acAdvanceDoctor')) $('acAdvanceDoctor').value = '';
        $('acAdvanceAmount').value = '';
        $('acAdvanceNotes').value = '';
        $('acAdvanceMethod').value = 'cash';
        $('acAdvanceOk').disabled = true;
        advanceErr('');
        const dlg = $('acAdvanceDialog');
        if (dlg && typeof dlg.showModal === 'function') dlg.showModal();
        $('acAdvanceSearch').focus();
    }

    function closeAdvanceDialog() {
        const dlg = $('acAdvanceDialog');
        if (dlg && typeof dlg.close === 'function' && dlg.open) dlg.close();
    }

    if ($('acAdvanceOpen')) $('acAdvanceOpen').addEventListener('click', openAdvanceDialog);
    if ($('acAdvanceClose')) $('acAdvanceClose').addEventListener('click', closeAdvanceDialog);
    if ($('acAdvanceCancel')) $('acAdvanceCancel').addEventListener('click', closeAdvanceDialog);
    if ($('acAdvanceSearch')) {
        $('acAdvanceSearch').addEventListener('input', function () {
            // Typing again means the earlier pick no longer matches what is on
            // screen — drop it rather than take money against a stale patient.
            advanceState.patient = null;
            $('acAdvancePicked').style.display = 'none';
            $('acAdvanceOk').disabled = true;
            clearTimeout(advanceState.timer);
            const q = this.value.trim();
            advanceState.timer = setTimeout(() => advanceSearch(q), 250);
        });
    }
    if ($('acAdvanceOk')) {
        $('acAdvanceOk').addEventListener('click', async () => {
            if (!advanceState.patient) { advanceErr('سەرەتا نەخۆشێک هەڵبژێرە'); return; }
            const doctorId = $('acAdvanceDoctor') ? $('acAdvanceDoctor').value : '';
            if (!doctorId) { advanceErr('دکتۆر هەڵبژێرە'); $('acAdvanceDoctor').focus(); return; }
            const amount = Number($('acAdvanceAmount').value || 0);
            if (!(amount > 0)) { advanceErr('بڕێکی دروست بنووسە'); return; }
            advanceErr('');
            const res = await postFinanceAction('advance_receive', {
                patient_id: advanceState.patient.id,
                doctor_id: doctorId,
                amount: amount,
                payment_method: $('acAdvanceMethod').value,
                notes: $('acAdvanceNotes').value,
            }, $('acAdvanceOk'));
            if (!res || !res.ok) { advanceErr((res && res.error) || 'تۆمارکردن سەرنەکەوت'); return; }
            closeAdvanceDialog();
            toast('success', 'ئامانەت تۆمارکرا — ' + (res.advance_no || ''));
            openPrintDialog(
                APP_URL + '/patients/advance-receipt.php?id=' + encodeURIComponent(res.advance_id) + '&autoprint=1',
                'وەسڵی ئامانەت',
                true
            );
            load();
        });
    }

    // ---------------------------------------------------------------
    // کردنەوە بۆ دەستکاری / سڕینەوە — taking an approved decision back
    //
    // Both buttons on a پەسەندکراو row open ONE dialog, and it opens empty: its
    // body is whatever api/approval-center.php?action=reversal_preview answers
    // for that row, so the accountant reads the list of transactions that are
    // about to be reversed BEFORE confirming, instead of discovering them in a
    // report afterwards. The write is reopen_approved / delete_approved, and
    // the reversal itself is entirely server side
    // (classes/ApprovalReversal.php) — nothing here decides what money moves.
    // ---------------------------------------------------------------

    const revState = { mode: null, kind: null, id: null, allowed: false };

    function revErr(msg) {
        const box = $('acRevErr');
        if (box) { box.textContent = msg || ''; box.style.display = msg ? 'block' : 'none'; }
    }

    function revLineHtml(line) {
        const amount = (line.amount === null || line.amount === undefined)
            ? ''
            : '<div class="rl-amt">' + nf(line.amount) + '</div>';
        return '<div class="ac-rev-line is-' + esc(line.effect || 'doc') + '">'
            + '<div class="rl-main"><div class="rl-label">' + esc(line.label) + '</div>'
            + (line.note ? '<div class="rl-note">' + esc(line.note) + '</div>' : '')
            + '</div>' + amount + '</div>';
    }

    function closeReversalDialog() {
        revState.mode = null;
        revState.allowed = false;
        const dlg = $('acReversalDialog');
        if (dlg && typeof dlg.close === 'function' && dlg.open) dlg.close();
    }

    async function openReversalDialog(mode, kind, id) {
        const dlg = $('acReversalDialog');
        if (!dlg) return;
        const remove = mode === 'remove';
        revState.mode = mode;
        revState.kind = kind;
        revState.id = id;
        revState.allowed = false;

        $('acRevTitle').innerHTML = remove
            ? '<i class="fas fa-trash-alt" style="color:#c50f1f;"></i> سڕینەوە و گەڕاندنەوەی تۆمارەکان'
            : '<i class="fas fa-lock-open" style="color:#b56a00;"></i> کردنەوە بۆ دەستکاری';
        $('acRevOk').className = remove ? 'btn btn-danger' : 'btn btn-primary';
        $('acRevOk').innerHTML = remove
            ? '<i class="fas fa-trash-alt"></i> سڕینەوە'
            : '<i class="fas fa-lock-open"></i> کردنەوە';
        $('acRevOk').disabled = true;
        $('acRevReasonLabel').textContent = remove
            ? 'هۆکاری سڕینەوە (پێویستە — لە تۆماری چالاکی هەڵدەگیرێت)'
            : 'هۆکار (ئارەزوومەندانە — لە تۆماری چالاکی هەڵدەگیرێت)';
        $('acRevReason').value = '';
        $('acRevContent').style.display = 'none';
        $('acRevLoading').style.display = '';
        revErr('');
        if (typeof dlg.showModal === 'function') dlg.showModal();

        let data;
        try {
            const res = await fetch(AC.listUrl + '?action=reversal_preview'
                + '&kind=' + encodeURIComponent(kind) + '&id=' + encodeURIComponent(id));
            data = await res.json();
        } catch (e) {
            data = { ok: false, error: 'کێشەی پەیوەندی ڕوویدا' };
        }
        // A second button may have been pressed while this was in flight.
        if (revState.mode !== mode || revState.kind !== kind || String(revState.id) !== String(id)) return;

        $('acRevLoading').style.display = 'none';
        if (!data || !data.ok) { revErr((data && data.error) || 'نەدۆزرایەوە'); return; }

        const gate = (remove ? data.remove : data.reopen) || {};
        const warnings = (data.warnings || []).slice();
        if (gate.message) warnings.unshift(gate.message);

        $('acRevKind').textContent = (KIND_META[kind] || {}).label || kind;
        $('acRevNumber').textContent = data.number || '—';
        $('acRevPatient').textContent = data.patient_name || '—';
        $('acRevNote').textContent = gate.note || '';
        $('acRevWarn').innerHTML = warnings
            .map((w) => '<div><i class="fas fa-exclamation-triangle"></i> ' + esc(w) + '</div>').join('');
        $('acRevLines').innerHTML = (data.lines || []).length
            ? data.lines.map(revLineHtml).join('')
            : '<div class="ac-empty-drawer">هیچ تۆمارێکی دارایی بەم داواکارییە نەبەستراوە</div>';
        // The server says whether THIS button actually moves cash out of the
        // reports (reopening an invoice does not — its receipts stay attached),
        // so the total is only claimed where it is true.
        $('acRevTotal').innerHTML = (gate.reverses_cash && Number(data.cash_total || 0) > 0.009)
            ? '<span><i class="fas fa-cash-register"></i> پارەی وەرگیراو کە لە ڕاپۆرتەکان لادەچێت</span>'
              + '<strong>' + nf(data.cash_total) + '</strong>'
            : '';
        $('acRevContent').style.display = '';

        revState.allowed = !!gate.allowed;
        $('acRevOk').disabled = !revState.allowed;
    }

    if ($('acRevClose')) $('acRevClose').addEventListener('click', closeReversalDialog);
    if ($('acRevCancel')) $('acRevCancel').addEventListener('click', closeReversalDialog);
    // A <dialog> also closes itself on Escape, which never runs the handlers
    // above — so the state is dropped here instead, for both routes.
    if ($('acReversalDialog')) {
        $('acReversalDialog').addEventListener('close', () => {
            revState.mode = null;
            revState.allowed = false;
        });
    }
    if ($('acRevOk')) {
        $('acRevOk').addEventListener('click', async () => {
            if (!revState.allowed) return;
            const remove = revState.mode === 'remove';
            const kind = revState.kind;
            const id = revState.id;
            const reason = $('acRevReason').value.trim();
            if (remove && !reason) { revErr('هۆکاری سڕینەوە پێویستە'); return; }
            revErr('');

            const res = await postFinanceAction(
                remove ? 'delete_approved' : 'reopen_approved',
                { kind: kind, id: id, reason: reason },
                $('acRevOk')
            );
            if (!res || !res.ok) { revErr((res && res.error) || 'کردارەکە سەرنەکەوت'); return; }

            closeReversalDialog();
            toast('success', remove ? 'سڕدرایەوە و تۆمارەکان گەڕێندرانەوە' : 'کرایەوە بۆ دەستکاری');
            if (kind === 'xray') xrayDetailCache.delete(String(id));
            if (remove) {
                load();
                return;
            }
            // The row has left پەسەندکراو for the live queue — that is where it
            // can be corrected, so go there and open it straight away, which is
            // the whole point of having pressed دەستکاری.
            activateTab('all');
            await load();
            openDrawer(kind, id);
        });
    }

    // ---------------------------------------------------------------
    // Boot
    // ---------------------------------------------------------------

    if ($('acSearch') && AC.q) $('acSearch').value = AC.q;
    // Read the box rather than write to it: the server already marked the
    // ?kind= option selected (so this is a no-op on a fresh load), and on a
    // back/bfcache restore the browser puts the user's own last choice back —
    // taking it from the DOM keeps what is on screen and what is queried in
    // agreement instead of silently querying something else.
    if ($('acKindFilter')) AC.kind = $('acKindFilter').value || '';
    document.querySelectorAll('#acTabs .kind-tab').forEach((t) => t.classList.toggle('active', t.dataset.tab === AC.tab));
    syncScopeToggle();
    renderSortToggle();
    renderSoundToggle();
    load().then(() => { AC.newTotal = 0; });
    startPolling();
})();
