/**
 * Receipt money boxes — داشکاندن and وەرگیراو, edited in place.
 *
 * Shared by every page that renders cases/partials/invoice_pane.php:
 *   * cases/view.php               (the doctor's screen)
 *   * accountant/report-detail.php (the accountant's screen)
 *
 * Both boxes POST to the page they are on, so each page keeps its own
 * handler and its own permission rules; this file only knows the markup.
 *
 * A page may set window.onInvoicePaneSaved(data, invoiceId) for discount saves
 * and window.onInvoicePaidSaved(data, invoiceId) for an inline doctor payment
 * request. Accountant-side booked money still reloads its wider finance view.
 */
(function () {
'use strict';

var fmt = new Intl.NumberFormat('en-US');

function say(type, message) {
    if (typeof window.toast === 'function') { window.toast(type, message); return; }
    if (typeof window.showToast === 'function') { window.showToast(type, message); return; }
    window.alert(message);
}

function post(fields) {
    var fd = new FormData();
    fd.set('csrf_token', window.CSRF_TOKEN || '');
    fd.set('ajax', '1');
    Object.keys(fields).forEach(function (k) { fd.set(k, fields[k]); });
    return fetch(window.location.href, {
        method: 'POST',
        body: fd,
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    }).then(function (r) { return r.json(); });
}

/* ── داشکاندن ────────────────────────────────────────────────────────
   The value is typed into the box; the small button flips between a flat
   amount and a percentage of this receipt's total. Saves on blur/Enter. */

function discBox(invoiceId) {
    return document.querySelector('.vt-disc-box[data-disc-for="' + invoiceId + '"]');
}

// The discount in currency, whatever mode the box is in
function discAmount(box) {
    var total = parseFloat(box.dataset.total) || 0;
    var v = parseFloat(box.querySelector('.vt-disc-input').value) || 0;
    return box.dataset.mode === 'percent' ? total * Math.min(100, Math.max(0, v)) / 100 : Math.max(0, v);
}

function rtToggleDiscMode(invoiceId) {
    var box = discBox(invoiceId); if (!box) return;
    var input = box.querySelector('.vt-disc-input');
    var total = parseFloat(box.dataset.total) || 0;
    // Carry the current value across so the figure on screen stays equivalent
    var amount = discAmount(box);
    if (box.dataset.mode === 'percent') {
        box.dataset.mode = 'amount';
        box.classList.remove('is-percent');
        box.querySelector('.vt-disc-mode').textContent = 'IQD';
        input.value = amount ? Math.round(amount * 100) / 100 : '';
    } else {
        box.dataset.mode = 'percent';
        box.classList.add('is-percent');
        box.querySelector('.vt-disc-mode').textContent = '%';
        input.value = (total > 0 && amount) ? Math.round(amount / total * 10000) / 100 : '';
    }
    input.focus();
    input.select();
    rtDiscPreview(invoiceId);
}

// Live "دوای داشکاندن" while typing — no request until blur/Enter.
// In percent mode the money it comes to is shown beside the field; in amount
// mode there is no percentage to show, so the hint stays hidden.
function rtDiscPreview(invoiceId) {
    var box = discBox(invoiceId); if (!box) return;
    var total = parseFloat(box.dataset.total) || 0;
    var raw = discAmount(box);
    var amount = Math.min(raw, total);
    var newNet = Math.max(0, total - amount);
    var net = document.querySelector('[data-disc-net="' + invoiceId + '"]');
    if (net) net.textContent = fmt.format(newNet);
    // The وەرگیراو box is measured against the net, so both must move together
    syncRemaining(invoiceId, newNet);
    box.querySelector('.vt-disc-input').style.color = raw > total ? '#c62026' : '';

    var eq = document.querySelector('[data-disc-eq="' + invoiceId + '"]');
    if (eq) {
        var show = box.dataset.mode === 'percent' && amount > 0;
        eq.hidden = !show;
        if (show) {
            var val = eq.querySelector('[data-disc-eq-val]');
            if (val) val.textContent = fmt.format(Math.round(amount));
        }
    }
}

var discBusy = false;
function rtSaveDiscount(invoiceId) {
    var box = discBox(invoiceId); if (!box || discBusy) return;
    var total = parseFloat(box.dataset.total) || 0;
    if (discAmount(box) > total + 0.009) {
        say('error', 'داشکاندن ناتوانێت لە کۆی وەسڵەکە زیاتر بێت');
        rtDiscPreview(invoiceId);
        return;
    }
    discBusy = true;
    box.classList.add('is-saving');

    post({
        action: 'set_invoice_discount',
        invoice_id: invoiceId,
        discount_mode: box.dataset.mode,
        discount_value: box.querySelector('.vt-disc-input').value || 0
    }).then(function (d) {
        if (!d.success) { say('error', d.message || 'داشکاندن سەرنەکەوت'); return; }
        if (typeof window.onInvoicePaneSaved === 'function') { window.onInvoicePaneSaved(d, invoiceId); }
        else { window.location.reload(); }
    }).catch(function () {
        say('error', 'هەڵەیەک ڕوویدا لە پەیوەندیکردن');
    }).finally(function () {
        discBusy = false;
        box.classList.remove('is-saving');
    });
}

/* ── وەرگیراو ────────────────────────────────────────────────────────
   The number typed here is what the box will READ afterwards, not the
   payment amount: money fills a case's receipts oldest-first, so the
   server works backwards from it. Every figure in the confirm comes from
   the server — nothing here does money arithmetic. */

function paidBox(invoiceId) {
    return document.querySelector('[data-paid-for="' + invoiceId + '"]');
}

// What وەرگیراو currently reads: the typed value where the box is editable,
// otherwise the derived figure the server rendered.
function paidValue(invoiceId) {
    var box = paidBox(invoiceId);
    if (!box) return 0;
    var input = box.querySelector('.vt-paid-input');
    if (input) return Math.max(0, parseFloat(input.value) || 0);
    return parseFloat(box.dataset.covered) || 0;
}

// دراوی پێشوو (historical cases): the typed value where editable, otherwise
// the figure the server rendered. 0 on an ordinary case.
function priorBox(invoiceId) {
    return document.querySelector('[data-prior-for="' + invoiceId + '"]');
}
function priorValue(invoiceId) {
    var box = priorBox(invoiceId);
    if (!box) return 0;
    var input = box.querySelector('.vt-prior-input');
    if (input) return Math.max(0, parseFloat(input.value) || 0);
    return parseFloat(box.dataset.prior) || 0;
}

// ماوە = net − وەرگیراو − دراوی پێشوو. Called from every preview so the boxes
// can never disagree while any one of them is being typed into.
function syncRemaining(invoiceId, netOverride) {
    var rem = document.querySelector('[data-disc-rem="' + invoiceId + '"]');
    if (!rem) return;
    var net = netOverride;
    if (typeof net !== 'number') {
        var box = paidBox(invoiceId);
        net = box ? (parseFloat(box.dataset.net) || 0) : 0;
    }
    var left = Math.max(0, net - paidValue(invoiceId) - priorValue(invoiceId));
    rem.textContent = fmt.format(left);
    rem.classList.toggle('is-due', left > 0.009);
}

function rtPaidPreview(invoiceId) {
    var box = paidBox(invoiceId); if (!box) return;
    var input = box.querySelector('.vt-paid-input'); if (!input) return;
    var net = parseFloat(box.dataset.net) || 0;
    // Typing past this receipt's own net is refused on save; flag it early
    input.style.color = (parseFloat(input.value) || 0) > net - priorValue(invoiceId) + 0.009 ? '#c62026' : '';
    syncRemaining(invoiceId);
}

var paidBusy = false;
function rtSavePaid(invoiceId) {
    var box = paidBox(invoiceId); if (!box || paidBusy) return;
    var input = box.querySelector('.vt-paid-input'); if (!input) return;
    var typed = Math.max(0, parseFloat(input.value) || 0);
    var net = parseFloat(box.dataset.net) || 0;
    var covered = parseFloat(box.dataset.covered) || 0;

    if (typed > net - priorValue(invoiceId) + 0.009) {
        say('error', priorValue(invoiceId) > 0.009
            ? 'بڕی وەرگیراو و دراوی پێشوو پێکەوە ناتوانن لە بڕی دوای داشکاندن زیاتر بن'
            : 'بڕی وەرگیراو ناتوانێت لە بڕی دوای داشکاندن زیاتر بێت');
        resetPaid(invoiceId, covered);
        return;
    }
    if (Math.abs(typed - covered) < 0.009) { return; }   // nothing changed

    paidBusy = true;
    box.classList.add('is-saving');
    // Draft entries save on the invoice only, immediately and with no
    // confirmation dialog. Sending the invoice never promotes them to Payment.
    if (box.dataset.isRequest === '1') {
        post({ action: 'set_invoice_paid', invoice_id: invoiceId, paid_amount: typed, confirm: '1' })
            .then(function (d) {
                if (!d.success) {
                    say('error', d.message || 'سەرنەکەوت');
                    resetPaid(invoiceId, covered);
                    return;
                }
                if (typeof window.onInvoicePaidSaved === 'function') {
                    window.onInvoicePaidSaved(d, invoiceId);
                }
                if (d.draft_received_saved) {
                    say('success', typed > 0
                        ? 'بڕی وەرگیراو وەک درافت خەزن کرا — هێشتا بۆ ژمێریار نەنێردراوە'
                        : 'بڕی وەرگیراوی درافت لابرا');
                }
            })
            .catch(function () {
                say('error', 'هەڵەیەک ڕوویدا لە پەیوەندیکردن');
                resetPaid(invoiceId, covered);
            })
            .finally(function () {
                paidBusy = false;
                box.classList.remove('is-saving');
            });
        return;
    }
    // Dry run: the server computes the payment behind this figure and commits
    // nothing. Only the confirm below sends it again for real.
    post({ action: 'set_invoice_paid', invoice_id: invoiceId, paid_amount: typed, confirm: '0' })
        .then(function (d) {
            if (!d.success) {
                say('error', d.message || 'سەرنەکەوت');
                resetPaid(invoiceId, covered);
                return;
            }
            askConfirm(invoiceId, typed, covered, d.plan);
        })
        .catch(function () {
            say('error', 'هەڵەیەک ڕوویدا لە پەیوەندیکردن');
            resetPaid(invoiceId, covered);
        })
        .finally(function () {
            paidBusy = false;
            box.classList.remove('is-saving');
        });
}

/* ── دراوی پێشوو ─────────────────────────────────────────────────────
   Historical cases only: money the patient paid before the system existed.
   Saved on the draft receipt; the server keeps it out of every cash report. */

function rtPriorPreview(invoiceId) {
    var box = priorBox(invoiceId); if (!box) return;
    var input = box.querySelector('.vt-prior-input'); if (!input) return;
    var net = parseFloat(box.dataset.net) || 0;
    input.style.color = priorValue(invoiceId) > net - paidValue(invoiceId) + 0.009 ? '#c62026' : '';
    syncRemaining(invoiceId);
}

var priorBusy = false;
function rtSavePrior(invoiceId) {
    var box = priorBox(invoiceId); if (!box || priorBusy) return;
    var input = box.querySelector('.vt-prior-input'); if (!input) return;
    var typed = priorValue(invoiceId);
    var saved = parseFloat(box.dataset.prior) || 0;
    var net = parseFloat(box.dataset.net) || 0;
    function restore() {
        input.value = saved > 0.009 ? saved : '';
        rtPriorPreview(invoiceId);
    }
    if (typed > net - paidValue(invoiceId) + 0.009) {
        say('error', 'دراوی پێشوو و وەرگیراو پێکەوە ناتوانن لە بڕی دوای داشکاندن زیاتر بن');
        restore();
        return;
    }
    if (Math.abs(typed - saved) < 0.009) { return; }

    priorBusy = true;
    box.classList.add('is-saving');
    post({ action: 'set_invoice_prior_paid', invoice_id: invoiceId, prior_paid_amount: typed })
        .then(function (d) {
            if (!d.success) { say('error', d.message || 'سەرنەکەوت'); restore(); return; }
            box.dataset.prior = typed;
            say('success', typed > 0
                ? 'دراوی پێشوو خەزن کرا — ناچێتە ناو داهاتی سندوق'
                : 'دراوی پێشوو لابرا');
            if (typeof window.onInvoicePaneSaved === 'function') { window.onInvoicePaneSaved(d, invoiceId); }
            else { window.location.reload(); }
        })
        .catch(function () {
            say('error', 'هەڵەیەک ڕوویدا لە پەیوەندیکردن');
            restore();
        })
        .finally(function () {
            priorBusy = false;
            box.classList.remove('is-saving');
        });
}

// Put the box back to the figure the server last rendered
function resetPaid(invoiceId, covered) {
    var box = paidBox(invoiceId);
    var input = box && box.querySelector('.vt-paid-input');
    if (!input) return;
    input.value = covered > 0.009 ? covered : '';
    rtPaidPreview(invoiceId);
}

function esc(s) {
    return String(s).replace(/[&<>"']/g, function (c) {
        return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
}

// Everything shown here was computed server-side and shipped back in `plan`.
function askConfirm(invoiceId, typed, covered, plan) {
    var isRequest = !!plan.is_request;
    var prior = '';
    if (plan.prior && plan.prior.length) {
        prior = '<div class="vt-confirm-prior">'
              + '<div class="vt-confirm-prior-title">پێویستە ئەم وەسڵانە پێشتر تەواو بن:</div>'
              + plan.prior.map(function (p) {
                    return '<div class="vt-confirm-line"><span class="no" dir="ltr">' + esc(p.invoice_no)
                         + '</span><span>' + fmt.format(Math.round(p.net)) + '</span></div>';
                }).join('')
              + '</div>';
    }
    var warn = '';
    if (plan.sends_invoice) {
        warn = '<div class="vt-confirm-warn"><i class="fas fa-triangle-exclamation"></i><span>'
             + 'ئەم وەسڵە بۆ ژمێریار دەنێردرێت — دوای ئەوە ناتوانیت داشکاندن یان چارەسەرەکان دەستکاری بکەیت.'
             + '</span></div>';
    }
    var back = document.createElement('div');
    back.className = 'vt-confirm-backdrop';
    back.innerHTML =
        '<div class="vt-confirm" role="dialog" aria-modal="true">'
      +   '<div class="vt-confirm-head"><i class="fas fa-money-bill-wave"></i>'
      +     '<span>' + (isRequest ? 'داواکاری پارەدان' : 'وەرگرتنی پارە') + '</span></div>'
      +   '<div class="vt-confirm-body">'
      +     warn
      +     '<div class="vt-confirm-line"><span>وەرگیراوی <span dir="ltr">' + esc(plan.invoice_no) + '</span></span>'
      +       '<span class="v">' + fmt.format(Math.round(plan.target)) + '</span></div>'
      +     prior
      +     '<div class="vt-confirm-line vt-confirm-total">'
      +       '<span>' + (isRequest ? 'داواکاری پارەدان' : 'کۆی پارەی تۆماردەکرێت') + '</span>'
      +       '<span class="v">' + fmt.format(Math.round(plan.amount)) + '</span></div>'
      +   '</div>'
      +   '<div class="vt-confirm-foot">'
      +     '<button type="button" class="vt-confirm-ok"><i class="fas fa-check"></i> '
      +       (isRequest ? 'ناردن' : 'تۆمارکردن') + '</button>'
      +     '<button type="button" class="vt-confirm-no">پاشگەزبوونەوە</button>'
      +   '</div>'
      + '</div>';

    function close(restore) {
        back.remove();
        document.removeEventListener('keydown', onKey);
        if (restore) { resetPaid(invoiceId, covered); }
    }
    function onKey(e) { if (e.key === 'Escape') close(true); }

    back.querySelector('.vt-confirm-no').addEventListener('click', function () { close(true); });
    back.addEventListener('click', function (e) { if (e.target === back) close(true); });
    document.addEventListener('keydown', onKey);

    var ok = back.querySelector('.vt-confirm-ok');
    ok.addEventListener('click', function () {
        ok.disabled = true;
        ok.innerHTML = '<i class="fas fa-spinner fa-spin"></i> ...';
        post({ action: 'set_invoice_paid', invoice_id: invoiceId, paid_amount: typed, confirm: '1' })
            .then(function (d) {
                if (!d.success) { say('error', d.message || 'سەرنەکەوت'); close(true); return; }
                // Money (or a request) landed: the receipt status, the tab
                // badges, the payments list and the case totals all moved.
                // Reload rather than patch a subset of them — and come back
                // carrying the payment id, so the page offers the patient's
                // قەبز while they are still in the chair. Opening it from here
                // would be a popup fired long after the click; blockers eat it.
                // d.redirect_flag (payment_requested / payment_request_unchanged
                // / payment_request_updated / payment_added) picks WHICH
                // banner the reloaded page shows — a plain reload() would lose
                // it, since it carries no query string of its own.
                if (d.voucher_id || d.redirect_flag) {
                    var u = new URL(window.location.href);
                    if (d.voucher_id) u.searchParams.set('voucher', d.voucher_id);
                    if (d.redirect_flag) u.searchParams.set(d.redirect_flag, '1');
                    window.location.href = u.toString();
                    return;
                }
                window.location.reload();
            })
            .catch(function () { say('error', 'هەڵەیەک ڕوویدا لە پەیوەندیکردن'); close(true); });
    });

    document.body.appendChild(back);
    ok.focus();
}

/* ── ئامانەت ─────────────────────────────────────────────────────────
   Money reception took from this patient at the counter before the case
   existed. Putting it on a receipt moves no cash — it only decides which bill
   the already-collected money settles — so the server writes it to
   case_invoices.advance_paid_amount, which no cash report reads.

   Defaults to as much as will fit: the smaller of what the patient has left
   and what this receipt still owes. Editable, because the patient may have
   paid for something this receipt is not. */
var advanceBusy = false;
function rtUseAdvance(invoiceId, available, remaining, applied) {
    if (advanceBusy) return;
    if (applied > 0.009) {
        if (!confirm('ئامانەتی دانراو لەسەر ئەم وەسڵە لادەبرێت و دەگەڕێتەوە بۆ باڵانسی نەخۆش. دڵنیایت؟')) return;
        advanceBusy = true;
        post({ action: 'clear_invoice_advance', invoice_id: invoiceId })
            .then(function (d) {
                if (!d.success) { say('error', d.message || 'سەرنەکەوت'); return; }
                say('success', 'ئامانەت گەڕێندرایەوە بۆ باڵانسی نەخۆش');
                if (typeof window.onInvoicePaneSaved === 'function') { window.onInvoicePaneSaved(d, invoiceId); }
                else { window.location.reload(); }
            })
            .catch(function () { say('error', 'هەڵەیەک ڕوویدا لە پەیوەندیکردن'); })
            .finally(function () { advanceBusy = false; });
        return;
    }

    var suggested = Math.min(available, remaining);
    if (suggested <= 0.009) {
        say('error', remaining <= 0.009 ? 'ئەم وەسڵە هیچ بڕێکی ماوەی نییە' : 'ئامانەتی بەردەست نییە');
        return;
    }
    var typed = prompt('چەند لە ئامانەتی نەخۆش بخرێتە سەر ئەم وەسڵە؟\n'
        + 'بەردەست: ' + available + '  ·  ماوەی وەسڵ: ' + remaining, String(suggested));
    if (typed === null) return;
    var amount = parseFloat(String(typed).replace(/,/g, '').trim());
    if (!isFinite(amount) || amount <= 0) { say('error', 'بڕی نادروست'); return; }
    if (amount > suggested + 0.009) {
        say('error', 'بڕەکە لە ئامانەتی بەردەست یان ماوەی وەسڵ زیاترە');
        return;
    }

    advanceBusy = true;
    post({ action: 'use_invoice_advance', invoice_id: invoiceId, advance_amount: amount })
        .then(function (d) {
            if (!d.success) { say('error', d.message || 'سەرنەکەوت'); return; }
            say('success', 'ئامانەت دانرا لەسەر وەسڵەکە — پارەی نوێ تۆمار نەکرا');
            if (typeof window.onInvoicePaneSaved === 'function') { window.onInvoicePaneSaved(d, invoiceId); }
            else { window.location.reload(); }
        })
        .catch(function () { say('error', 'هەڵەیەک ڕوویدا لە پەیوەندیکردن'); })
        .finally(function () { advanceBusy = false; });
}

window.rtToggleDiscMode = rtToggleDiscMode;
window.rtDiscPreview    = rtDiscPreview;
window.rtSaveDiscount   = rtSaveDiscount;
window.rtPaidPreview    = rtPaidPreview;
window.rtSavePaid       = rtSavePaid;
window.rtPriorPreview   = rtPriorPreview;
window.rtSavePrior      = rtSavePrior;
window.rtUseAdvance     = rtUseAdvance;
})();
