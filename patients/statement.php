<?php
/**
 * Patient Financial Statement — کشف حسابی نەخۆش
 * Task 13: read-only aggregation from PatientFinance, cash-desk actions via
 * the shared api/finance-actions.php handlers. This page computes NO money
 * itself — every figure comes straight from PatientFinance::statement()'s
 * arrays; everything here is labels/formatting only.
 *
 * URL contract: patients/statement.php?patient_id=N[&case_id=M] — case_id
 * scrolls to #case-M.
 *
 * Access: admin/accountant see everything and the three cash-desk actions
 * (receive_payment w/ allocation picker, create_installment, reverse_payment
 * w/ required reason). Doctors get an AccessGuard-scoped read-only view of
 * their own folders/cases/X-rays — no action buttons, and the patient-level
 * credit resource is hidden entirely (design decision: credit belongs to
 * money roles only, and net_debt is derived from it, so that tile is held
 * back for doctors too — see task-13-report.md).
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../classes/Auth.php';
require_once __DIR__ . '/../classes/Patient.php';
require_once __DIR__ . '/../classes/PatientFinance.php';

if (!function_exists('ps_h')) {
    function ps_h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('ps_nf')) {
    function ps_nf($v) { return number_format((float)$v); }
}

$auth = new Auth();
$auth->requireLogin();
$currentUser = $auth->getCurrentUser();
$isAdmin = $auth->isAdmin();
$isAccountant = $auth->isAccountant();
$isDoctor = $auth->isDoctor();

$canAct = $isAdmin || $isAccountant;
if (!$canAct && !($isDoctor && !$isAdmin)) {
    header('Location: ' . APP_URL . '/403.php');
    exit;
}

$patientId = (int)($_GET['patient_id'] ?? $_GET['id'] ?? 0);
if (!$patientId) {
    header('Location: ' . APP_URL . '/patients/index.php');
    exit;
}

$doctorScopeUserId = null;
if ($isDoctor && !$isAdmin) {
    // GENERAL access: any doctor may look up any patient's demographics; the
    // actual case/financial scoping happens below via $doctorScopeUserId.
    AccessGuard::requirePatientGeneral($patientId);
    $doctorScopeUserId = (int)$currentUser['id'];
}

$patientManager = new Patient();
$patient = $patientManager->getById($patientId);
if (!$patient) {
    header('Location: ' . APP_URL . '/patients/index.php');
    exit;
}

$db = Database::getInstance();
$data = (new PatientFinance())->statement($patientId, $doctorScopeUserId);
$summary = $data['summary'];
$xray = $data['xray'];
$credit = $data['credit'];
$activity = $data['activity'];

// ── Ledger — the flat Date/Reference/Description/Debit/Credit/Balance view,
// same shape as erp/statement.php's account sheet. ─────────────────────────
require_once __DIR__ . '/../classes/PatientLedger.php';
function ps_valid_date($v)
{
    return is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) === 1;
}
$ledgerFrom = ps_valid_date($_GET['ledger_from'] ?? '') ? $_GET['ledger_from'] : '';
$ledgerTo = ps_valid_date($_GET['ledger_to'] ?? '') ? $_GET['ledger_to'] : '';
$ledger = (new PatientLedger())->statement($patientId, $doctorScopeUserId, $ledgerFrom ?: null, $ledgerTo ?: null);
$ledgerDocLabels = [
    'INVOICE'       => 'وەسڵی چارەسەر',
    'DISCOUNT'      => 'داشکاندن',
    'CHAIR_PAYMENT' => 'نەقدی لای دکتۆر',
    'PRIOR_PAYMENT' => 'دراوی پێشوو (کۆن)',
    'PAYMENT'       => 'پارەدان',
    'REFUND'        => 'گەڕاندنەوەی پارەدان',
    'XRAY'          => 'قەرزی ئیکسڕەی',
    'XRAY_PAYMENT'  => 'پارەدانی قەرزی ئیکسڕەی',
    'ADVANCE'         => 'ئامانەت (پێشوەختە)',
    'ADVANCE_REFUND'  => 'گەڕاندنەوەی ئامانەت',
    // A note, not a movement: the cash was already credited on the ADVANCE row
    // the day the counter took it. Its figure rides in note_amount so the
    // debit/credit columns stay zero and the running balance is untouched.
    'ADVANCE_APPLIED' => 'ئامانەت دانرا لەسەر وەسڵ',
];

// ---- Label maps (copied from accountant/report-detail.php:222-251) ----
$methodLabels = ['cash' => 'نەقد', 'card' => 'کارت', 'transfer' => 'گواستنەوە', 'other' => 'هی تر', 'advance' => 'لە ئامانەت'];
$eventLabels = [
    'invoice_created'                 => 'پسووڵە دروست کرا',
    'invoice_sent'                    => 'نێردرا بۆ ژمێریاری',
    'invoice_approved'                => 'پسووڵە پەسەند کرا',
    'invoice_auto_approved'           => 'پسووڵە خۆکار پەسەند کرا (شەوانە)',
    'invoice_approved_with_payment'   => 'پسووڵە لەگەڵ پارەدان پەسەند کرا',
    'invoice_returned'                => 'پسووڵە گەڕێندرایەوە',
    'invoice_discount_changed'        => 'داشکاندن گۆڕدرا',
    'invoice_accounting_note_changed' => 'تێبینی ژمێریاری نوێ کرایەوە',
    'invoice_paid'                    => 'پسووڵە بە تەواوی درا',
    'payment_requested'               => 'داواکاری پارەدان تۆمار کرا',
    'payment_received'                => 'پارە وەرگیرا',
    'payment_approved'                => 'پارەدان پەسەند کرا',
    'payment_rejected'                => 'پارەدان ڕەت کرایەوە',
    'payment_reversed'                => 'پارەدان هەڵوەشێندرایەوە',
    'payment_allocated'               => 'پارە بە پسووڵە بەسترا',
];
$xrayRequestStatusLabels = isset($XRAY_STATUS) && is_array($XRAY_STATUS) ? $XRAY_STATUS : [];
$xrayPaymentStatusLabels = [
    'pending'          => ['چاوەڕوان', 'neutral'],
    'awaiting_payment' => ['چاوەڕوانی پارە', 'warning'],
    'paid'             => ['دراوە', 'success'],
    'debt'             => ['قەرز', 'danger'],
    'debt_pending'     => ['چاوەڕوانی پەسەندکردنی قەرز', 'warning'],
    'debt_approved'    => ['قەرزی پەسەندکراو', 'danger'],
    'dont_cash'        => ['بەخۆڕایی', 'info'],
];
$xrayDebtStatusLabels = ['pending' => ['نەدراو', 'danger'], 'partial' => ['بەشێک دراوە', 'warning'], 'paid' => ['دراوە', 'success']];
$creditTypeLabels = ['credit' => ['متمانە', 'success'], 'debit' => ['بەکارهێنراو', 'danger']];

$pageTitle = 'کشف حساب — ' . ps_h($patient['full_name']);
$breadcrumb = [
    ['title' => 'نەخۆشەکان', 'url' => APP_URL . '/patients/index.php'],
    ['title' => ps_h($patient['full_name']), 'url' => APP_URL . '/patients/view.php?id=' . $patientId],
    ['title' => 'کشف حساب'],
];
require_once __DIR__ . '/../includes/header.php';
?>
<style>
.ps-shell{max-width:1460px;margin:0 auto;color:#172536;font-size:13px;direction:rtl}.ps-shell *{box-sizing:border-box}
.ps-header{display:flex;justify-content:space-between;gap:18px;align-items:flex-start;padding:18px 20px;background:#fff;border:1px solid #dbe3ec;border-radius:12px;margin-bottom:14px;flex-wrap:wrap}
.ps-identity{display:flex;gap:12px;align-items:center;min-width:0}.ps-avatar{width:44px;height:44px;border-radius:10px;background:#e8f2ff;color:#0877d1;display:grid;place-items:center;font-size:18px;flex:0 0 auto}
.ps-title{margin:0;font-size:19px;font-weight:800;color:#10263a}.ps-meta{display:flex;gap:8px 16px;flex-wrap:wrap;margin-top:5px;color:#607286;font-size:12px}.ps-meta b{color:#21384d}
.ps-actions{display:flex;gap:7px;flex-wrap:wrap}

.stat-tiles{display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:12px;margin-bottom:16px}
.stat-tile{background:#fff;border-radius:12px;padding:14px 16px;box-shadow:0 2px 12px rgba(0,0,0,.06);display:flex;align-items:center;gap:12px;border-right:4px solid transparent}
.stat-tile .st-icon{width:40px;height:40px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:16px}
.stat-tile .st-num{font-size:20px;font-weight:700;color:#1a1a1a;line-height:1;direction:ltr;text-align:right}
.stat-tile .st-label{font-size:11px;color:#888;margin-top:4px}

.fw-btn{border:1px solid #cfd9e5;background:#fff;color:#22384b;border-radius:7px;padding:7px 11px;font:600 12px inherit;cursor:pointer;text-decoration:none;display:inline-flex;align-items:center;gap:6px;white-space:nowrap}.fw-btn:hover{background:#f4f8fc}.fw-btn.primary{background:#0878d1;border-color:#0878d1;color:#fff}.fw-btn.success{background:#147d4f;border-color:#147d4f;color:#fff}.fw-btn.danger{color:#b4232c;border-color:#e6b9bd}.fw-btn:disabled{opacity:.5;cursor:not-allowed}
.fw-card{border:1px solid #dbe3ec;border-radius:9px;background:#fff;overflow:hidden;margin-bottom:14px}.fw-card-head{display:flex;justify-content:space-between;align-items:center;gap:10px;padding:10px 13px;border-bottom:1px solid #e4eaf1;background:#f8fafc;flex-wrap:wrap}.fw-card-head h3{margin:0;font-size:13px;font-weight:800}.fw-card-body{padding:13px}
.fw-table-wrap{overflow:auto;border-radius:0 0 8px 8px}.fw-table{width:100%;border-collapse:collapse;min-width:760px}.fw-table th{background:#eef3f8;color:#1c3449;font-size:11px;font-weight:800;padding:8px;border-bottom:1px solid #ccd7e2;text-align:right;white-space:nowrap}.fw-table td{padding:8px;border-bottom:1px solid #e8edf2;vertical-align:middle}.fw-table tr:last-child td{border-bottom:0}.fw-table .num{direction:ltr;text-align:right;font-variant-numeric:tabular-nums;white-space:nowrap}.fw-table .muted{color:#718195;font-size:11px}.fw-table-actions{display:flex;gap:5px;flex-wrap:wrap}
.fw-badge{display:inline-flex;align-items:center;gap:4px;border-radius:12px;padding:3px 8px;font-size:10px;font-weight:800;white-space:nowrap}.fw-badge.success{background:#e5f6ed;color:#12633e}.fw-badge.warning{background:#fff3d8;color:#885d00}.fw-badge.danger{background:#fdebed;color:#a11d28}.fw-badge.neutral{background:#edf1f5;color:#596b7e}.fw-badge.info{background:#e6f2fd;color:#075f9d}
.fw-timeline{display:grid;gap:0}.fw-event{display:grid;grid-template-columns:92px 14px 1fr;gap:8px;min-height:46px}.fw-event-time{font-size:10px;color:#728295;text-align:left;direction:ltr}.fw-event-line{position:relative;border-inline-start:1px solid #d7e0e9}.fw-event-line:before{content:'';position:absolute;width:8px;height:8px;border-radius:50%;background:#0878d1;inset-inline-start:-5px;top:4px}.fw-event-copy strong{display:block;font-size:12px}.fw-event-copy span{font-size:11px;color:#708195}
.fw-empty{text-align:center;color:#738397;padding:30px 15px}
.fw-modal{border:0;padding:0;border-radius:11px;width:min(900px,calc(100vw - 28px));max-height:90vh;box-shadow:0 24px 70px #10263a44;direction:rtl}.fw-modal::backdrop{background:#0d223666}.fw-modal-head{display:flex;justify-content:space-between;align-items:center;padding:13px 16px;border-bottom:1px solid #dbe3ec}.fw-modal-head h3{margin:0;font-size:15px}.fw-modal-body{padding:15px;overflow:auto;max-height:calc(90vh - 116px)}.fw-modal-foot{display:flex;justify-content:flex-start;gap:7px;padding:11px 15px;border-top:1px solid #dbe3ec;background:#f8fafc}.fw-modal-close{border:0;background:transparent;font-size:20px;cursor:pointer}
.fw-form-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:9px;margin-bottom:12px}.fw-field label{display:block;font-size:10px;color:#66788b;margin-bottom:4px}.fw-input{width:100%;border:1px solid #cbd6e2;border-radius:6px;padding:7px 8px;font:12px inherit;background:#fff;color:#172536}.fw-input:focus{outline:2px solid #b9dcfa;border-color:#0878d1}
.ps-section-title{font-size:13px;font-weight:800;margin:0 0 8px;display:flex;align-items:center;gap:6px;color:#1c3449}
.ps-sub{margin-top:14px}
@media(max-width:900px){.ps-header{flex-direction:column}.ps-actions{width:100%}.fw-form-grid{grid-template-columns:repeat(2,1fr)}}
@media(max-width:560px){.stat-tiles{grid-template-columns:1fr 1fr}.fw-form-grid{grid-template-columns:1fr}.fw-event{grid-template-columns:70px 12px 1fr}}

/* Ledger — the flat running-balance sheet, styled like erp/statement.php's
   account sheet so the two "کشف حساب" pages read as one product. */
.ledger-filter{display:flex;gap:8px;align-items:flex-end;flex-wrap:wrap;margin-bottom:10px}
.ledger-filter label{display:block;font-size:11px;color:#666;margin-bottom:3px}
.ledger-filter input[type=date],.ledger-filter input[type=search]{height:32px;font-size:12px;padding:3px 8px;border:1px solid #cbd6e2;border-radius:6px;background:#fff}
.ledger-filter input[type=search]{min-width:200px}
table.ledger-t{width:100%;border-collapse:collapse;font-size:12.5px}
table.ledger-t th{background:#eef2f5;border:1px solid #c8d3da;padding:7px 8px;font-size:11.5px;font-weight:800;text-align:right}
table.ledger-t td{border:1px solid #e3e3e3;padding:6px 8px}
table.ledger-t .n{text-align:left;direction:ltr;font-variant-numeric:tabular-nums;white-space:nowrap}
.ledger-open td,.ledger-close td{background:#f5f7f9;font-weight:700}
.ledger-close td{border-top:2px solid #333}
.ledger-due{color:#b4232c;font-weight:700}
.ledger-ok{color:#147d4f;font-weight:700}
.ledger-sum{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:10px;margin-top:12px}
.ledger-sum div{border:1px solid #e3e3e3;border-radius:6px;padding:8px 10px}
.ledger-sum .l{font-size:11.5px;color:#666}
.ledger-sum .v{font-size:16px;font-weight:700;direction:ltr;text-align:left;font-variant-numeric:tabular-nums}
@media print{.ledger-filter,.ps-actions,.fw-table-actions{display:none!important}}
</style>

<div class="ps-shell">

    <header class="ps-header">
        <div class="ps-identity">
            <div class="ps-avatar"><i class="fas fa-user"></i></div>
            <div>
                <h1 class="ps-title"><?= ps_h($patient['full_name']) ?></h1>
                <div class="ps-meta">
                    <span>کۆدی نەخۆش: <b dir="ltr"><?= ps_h($patient['patient_code'] ?? '—') ?></b></span>
                    <span>مۆبایل: <b dir="ltr"><?= ps_h($patient['phone'] ?? '—') ?></b></span>
                    <?php if (!empty($patient['date_of_birth'])):
                        $age = (int)floor((strtotime('now') - strtotime($patient['date_of_birth'])) / 31557600);
                    ?>
                    <span>تەمەن: <b><?= $age ?> ساڵ</b></span>
                    <?php endif; ?>
                    <span>باڵانسی ئێستا:
                        <b class="<?= $ledger['closing'] > 0.009 ? 'ledger-due' : 'ledger-ok' ?>"><?= ps_nf($ledger['closing']) ?></b>
                    </span>
                    <?php if ($doctorScopeUserId): ?><span><b>دیمەنی سنووردار — تەنها کەیس/ئیکسڕەی خۆت</b></span><?php endif; ?>
                </div>
            </div>
        </div>
        <div class="ps-actions">
            <a class="fw-btn" href="<?= APP_URL ?>/patients/view.php?id=<?= $patientId ?>"><i class="fas fa-id-card"></i> پرۆفایلی نەخۆش</a>
        </div>
    </header>

    <!-- کشف حساب — flat running-balance ledger -->
    <div class="fw-card" id="ledger-card">
        <div class="fw-card-head">
            <h3><i class="fas fa-book"></i> کشف حساب</h3>
            <div class="fw-table-actions no-print">
                <?php if ($canAct): ?>
                <button class="fw-btn" type="button" onclick="document.getElementById('openingBalanceDialog').showModal()">
                    <i class="fas fa-edit"></i> باڵانسی سەرەتایی
                </button>
                <?php endif; ?>
                <button class="fw-btn" type="button" onclick="window.print()"><i class="fas fa-print"></i> چاپ</button>
                <a class="fw-btn"
                   href="<?= APP_URL ?>/patients/statement-export.php?patient_id=<?= $patientId ?><?= $ledgerFrom ? '&ledger_from=' . ps_h($ledgerFrom) : '' ?><?= $ledgerTo ? '&ledger_to=' . ps_h($ledgerTo) : '' ?>">
                    <i class="fas fa-file-excel"></i> Excel
                </a>
            </div>
        </div>
        <div class="fw-card-body">
            <form method="GET" class="ledger-filter no-print">
                <input type="hidden" name="patient_id" value="<?= $patientId ?>">
                <div><label>لە بەرواری</label><input type="date" name="ledger_from" value="<?= ps_h($ledgerFrom) ?>"></div>
                <div><label>بۆ بەرواری</label><input type="date" name="ledger_to" value="<?= ps_h($ledgerTo) ?>"></div>
                <div><label>گەڕان (مەبەست/ژمارە)</label><input type="search" id="ledgerSearch" placeholder="گەڕان لە ڕیزەکان…"></div>
                <button class="fw-btn primary" type="submit"><i class="fas fa-filter"></i> پاڵاوتن</button>
                <a class="fw-btn" href="?patient_id=<?= $patientId ?>">پاککردنەوە</a>
            </form>

            <div class="fw-table-wrap">
            <table class="ledger-t" id="ledgerTable">
                <thead>
                    <tr>
                        <th style="width:100px">بەروار</th>
                        <th style="width:140px">جۆر</th>
                        <th>ژمارە / وردەکاری</th>
                        <th style="width:110px">قەرز</th>
                        <th style="width:110px">دانەوە</th>
                        <th style="width:120px">باڵانس</th>
                        <th style="width:80px" class="no-print"></th>
                    </tr>
                </thead>
                <tbody>
                    <tr class="ledger-open">
                        <td><?= ps_h($ledger['opening_row']['as_of_date'] ?? '—') ?></td>
                        <td>باڵانسی سەرەتایی<?= !empty($ledger['opening_row']['notes']) ? ' — ' . ps_h($ledger['opening_row']['notes']) : '' ?></td>
                        <td></td>
                        <td class="n">—</td>
                        <td class="n">—</td>
                        <td class="n"><?= ps_nf($ledger['opening']) ?></td>
                        <td class="no-print"></td>
                    </tr>
                    <?php foreach ($ledger['rows'] as $r):
                        $viewHref = null;
                        if (!empty($r['case_id'])) {
                            if (in_array($r['doc_type'], ['INVOICE', 'DISCOUNT', 'CHAIR_PAYMENT', 'PRIOR_PAYMENT'], true)) {
                                $viewHref = APP_URL . '/cases/receipt.php?id=' . (int)$r['case_id'] . '&invoice=' . (int)$r['ref_id'] . '&autoprint=0';
                            } elseif (in_array($r['doc_type'], ['PAYMENT', 'REFUND'], true)) {
                                $viewHref = APP_URL . '/cases/voucher.php?payment=' . (int)$r['ref_id'] . '&autoprint=0';
                            }
                        }
                    ?>
                    <tr>
                        <td dir="ltr"><?= ps_h(substr($r['entry_date'], 0, 10)) ?></td>
                        <td><?= ps_h($ledgerDocLabels[$r['doc_type']] ?? $r['doc_type']) ?></td>
                        <td dir="ltr" style="text-align:right"><span dir="ltr"><?= ps_h($r['doc_number']) ?></span> <span style="color:#777;font-size:11px" dir="rtl"><?= ps_h($r['description']) ?></span>
                            <?php // A zero-amount note row (ئامانەت applied) still has to say how much. ?>
                            <?php if (!empty($r['note_amount'])): ?><span style="color:#7a4a12;font-size:11px" dir="rtl"> · <?= ps_nf($r['note_amount']) ?></span><?php endif; ?>
                        </td>
                        <td class="n"><?= $r['debit'] > 0.009 ? ps_nf($r['debit']) : '—' ?></td>
                        <td class="n"><?= $r['credit'] > 0.009 ? ps_nf($r['credit']) : '—' ?></td>
                        <td class="n"><?= ps_nf($r['balance']) ?></td>
                        <td class="no-print" style="white-space:nowrap">
                            <?php if ($viewHref): ?>
                            <a class="fw-btn" target="_blank" title="بینینی وەسڵ/قەبز" href="<?= ps_h($viewHref) ?>"><i class="fas fa-eye"></i></a>
                            <?php endif; ?>
                            <?php if (!empty($r['case_id'])): ?>
                            <a class="fw-btn" target="_blank" title="کەیسی پزیشکی" href="<?= APP_URL ?>/cases/view.php?id=<?= (int)$r['case_id'] ?>"><i class="fas fa-tooth"></i></a>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (!$ledger['rows']): ?>
                    <tr><td colspan="7" style="text-align:center;padding:22px;color:#999">هیچ جوڵەیەک لەم ماوەیەدا نییە</td></tr>
                    <?php endif; ?>
                    <tr class="ledger-close">
                        <td colspan="3">کۆتا باڵانس</td>
                        <td class="n"><?= ps_nf($ledger['totals']['debit']) ?></td>
                        <td class="n"><?= ps_nf($ledger['totals']['credit']) ?></td>
                        <td class="n <?= $ledger['closing'] > 0.009 ? 'ledger-due' : 'ledger-ok' ?>"><?= ps_nf($ledger['closing']) ?></td>
                        <td class="no-print"></td>
                    </tr>
                </tbody>
            </table>
            </div>

            <div class="ledger-sum">
                <div><div class="l">باڵانسی سەرەتایی</div><div class="v"><?= ps_nf($ledger['opening']) ?></div></div>
                <div><div class="l">کۆی قەرز</div><div class="v"><?= ps_nf($ledger['totals']['debit']) ?></div></div>
                <div><div class="l">کۆی دانەوە</div><div class="v"><?= ps_nf($ledger['totals']['credit']) ?></div></div>
                <div><div class="l">قەرزی ماوە</div><div class="v <?= $ledger['closing'] > 0.009 ? 'ledger-due' : 'ledger-ok' ?>"><?= ps_nf($ledger['closing']) ?></div></div>
            </div>

            <p class="muted" style="font-size:11px;margin-top:10px;line-height:1.7">
                ئێکسڕەی و پارەدانی نەقدی کە دەستبەجێ وەرگیراوە قەرزیان دروست نەکردووە، بۆیە لێرە دەرناکەون — ئەم لیستە تەنها پارەی
                قەرزار پیشان دەدات. پارەدانی گەڕاوە (refund) وەک دوو ڕیز دەردەکەوێت: پارەدانی سەرەتایی و گەڕاندنەوەکەی، بۆ ئەوەی
                مێژووی ڕاستەقینە بمێنێتەوە.
            </p>
        </div>
    </div>

    <?php if ($canAct): ?>
    <dialog class="fw-modal" id="openingBalanceDialog" style="width:min(480px,calc(100vw - 28px))">
        <form onsubmit="event.preventDefault(); psSubmit(this);">
            <?= Csrf::field() ?>
            <input type="hidden" name="action" value="set_opening_balance">
            <input type="hidden" name="patient_id" value="<?= $patientId ?>">
            <div class="fw-modal-head"><h3>باڵانسی سەرەتایی / قەرزی کۆن</h3><button type="button" class="fw-modal-close" onclick="this.closest('dialog').close()">×</button></div>
            <div class="fw-modal-body">
                <div class="fw-form-grid" style="grid-template-columns:1fr 1fr">
                    <div class="fw-field"><label>بڕی قەرزی کۆن</label><input class="fw-input" name="amount" type="number" min="0" step="0.01" value="<?= (float)($ledger['opening_row']['amount'] ?? 0) ?>" required></div>
                    <div class="fw-field"><label>بەرواری قەرز</label><input class="fw-input" name="as_of_date" type="date" value="<?= ps_h($ledger['opening_row']['as_of_date'] ?? date('Y-m-d')) ?>" required></div>
                </div>
                <div class="fw-field"><label>تێبینی</label><input class="fw-input" name="notes" value="<?= ps_h($ledger['opening_row']['notes'] ?? '') ?>" placeholder="سەرچاوەی قەرزی کۆن…"></div>
            </div>
            <div class="fw-modal-foot"><button class="fw-btn primary" type="submit"><i class="fas fa-check"></i> خەزنکردن</button><button class="fw-btn" type="button" onclick="this.closest('dialog').close()">پاشگەزبوونەوە</button></div>
        </form>
    </dialog>
    <?php endif; ?>

    <section class="stat-tiles" aria-label="پوختەی دارایی">
        <div class="stat-tile" style="border-right-color:var(--win-accent, #0078d4);"><div class="st-icon" style="background:#deecf9;color:var(--win-accent, #0078d4);"><i class="fas fa-file-invoice"></i></div><div><div class="st-num"><?= ps_nf($summary['total_invoiced']) ?></div><div class="st-label">کۆی پسووڵەکان</div></div></div>
        <div class="stat-tile" style="border-right-color:#8a5a00;"><div class="st-icon" style="background:#fff4ce;color:#8a5a00;"><i class="fas fa-percent"></i></div><div><div class="st-num"><?= ps_nf($summary['total_discount']) ?></div><div class="st-label">کۆی داشکاندن</div></div></div>
        <div class="stat-tile" style="border-right-color:var(--win-accent-deep, #0063b1);"><div class="st-icon" style="background:#e6f2fd;color:#075f9d;"><i class="fas fa-check-circle"></i></div><div><div class="st-num"><?= ps_nf($summary['approved']) ?></div><div class="st-label">پەسەندکراو</div></div></div>
        <div class="stat-tile" style="border-right-color:#107c10;"><div class="st-icon" style="background:#dff6dd;color:#107c10;"><i class="fas fa-money-bill-wave"></i></div><div><div class="st-num"><?= ps_nf($summary['paid']) ?></div><div class="st-label">دراو</div></div></div>
        <div class="stat-tile" style="border-right-color:#c50f1f;"><div class="st-icon" style="background:#fde7e9;color:#c50f1f;"><i class="fas fa-balance-scale"></i></div><div><div class="st-num"><?= ps_nf($summary['case_balance']) ?></div><div class="st-label">باڵانسی کەیسەکان</div></div></div>
        <div class="stat-tile" style="border-right-color:#ff8c00;"><div class="st-icon" style="background:#fff4ce;color:#ff8c00;"><i class="fas fa-hourglass-half"></i></div><div><div class="st-num"><?= ps_nf($summary['pending_requests']) ?></div><div class="st-label">داواکاری چاوەڕوان</div></div></div>
        <div class="stat-tile" style="border-right-color:#5c2d91;"><div class="st-icon" style="background:#eee6fa;color:#5c2d91;"><i class="fas fa-x-ray"></i></div><div><div class="st-num"><?= ps_nf($summary['xray_outstanding']) ?></div><div class="st-label">قەرزی ئیکسڕەی</div></div></div>
        <div class="stat-tile" style="border-right-color:#00695c;"><div class="st-icon" style="background:#e0f2f1;color:#00695c;"><i class="fas fa-calendar-alt"></i></div><div><div class="st-num"><?= ps_nf($summary['installment_remaining']) ?></div><div class="st-label">ماوەی قیستەکان</div></div></div>
        <?php if ($canAct): ?>
        <div class="stat-tile" style="border-right-color:#107c10;"><div class="st-icon" style="background:#dff6dd;color:#107c10;"><i class="fas fa-wallet"></i></div><div><div class="st-num"><?= ps_nf($summary['credit']) ?></div><div class="st-label">باڵانسی متمانە</div></div></div>
        <div class="stat-tile" style="border-right-color:#a11d28;"><div class="st-icon" style="background:#fdebed;color:#a11d28;"><i class="fas fa-exclamation-triangle"></i></div><div><div class="st-num"><?= ps_nf($summary['net_debt']) ?></div><div class="st-label">کۆی قەرزی خاڵص</div></div></div>
        <?php endif; ?>
    </section>

    <div class="fw-card">
        <div class="fw-card-head"><h3><i class="fas fa-x-ray"></i> ئیکسڕەی</h3></div>
        <div class="fw-card-body">
            <h3 class="ps-section-title">داواکارییەکان</h3>
            <?php if (!$xray['requests']): ?><div class="fw-empty">هیچ داواکاریی ئیکسڕەیێک نییە.</div><?php else: ?>
            <div class="fw-table-wrap"><table class="fw-table" style="min-width:700px"><thead><tr><th>ژمارە</th><th>جۆر</th><th>دۆخ</th><th>دۆخی پارەدان</th><th>بڕ</th><th>بەروار</th></tr></thead><tbody>
            <?php foreach ($xray['requests'] as $req):
                $reqStatusLabel = $xrayRequestStatusLabels[$req['request_status']] ?? $req['request_status'];
                $reqPayBadge = $xrayPaymentStatusLabels[$req['payment_status']] ?? [$req['payment_status'], 'neutral'];
            ?>
            <tr>
                <td dir="ltr"><?= ps_h($req['request_number'] ?? ('#' . $req['id'])) ?></td>
                <td><?= ps_h($req['xray_type'] ?? '—') ?></td>
                <td><?= ps_h($reqStatusLabel) ?></td>
                <td><span class="fw-badge <?= $reqPayBadge[1] ?>"><?= ps_h($reqPayBadge[0]) ?></span></td>
                <td class="num"><?= ps_nf($req['final_amount'] ?? $req['amount'] ?? 0) ?></td>
                <td dir="ltr"><?= ps_h(date('Y/m/d', strtotime($req['created_at']))) ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody></table></div>
            <?php endif; ?>

            <div class="ps-sub">
            <h3 class="ps-section-title">قەرزەکان</h3>
            <?php if (!$xray['debts']): ?><div class="fw-empty">هیچ قەرزێکی ئیکسڕەی نییە.</div><?php else: ?>
            <div class="fw-table-wrap"><table class="fw-table" style="min-width:600px"><thead><tr><th>بڕ</th><th>ماوە</th><th>دۆخ</th><th>بەرواری شایستە</th><th>بەروار</th></tr></thead><tbody>
            <?php foreach ($xray['debts'] as $debt):
                $debtBadge = $xrayDebtStatusLabels[$debt['status']] ?? [$debt['status'], 'neutral'];
            ?>
            <tr>
                <td class="num"><?= ps_nf($debt['amount']) ?></td>
                <td class="num"><?= ps_nf($debt['remaining_amount']) ?></td>
                <td><span class="fw-badge <?= $debtBadge[1] ?>"><?= ps_h($debtBadge[0]) ?></span></td>
                <td dir="ltr"><?= ps_h($debt['due_date'] ?? '—') ?></td>
                <td dir="ltr"><?= ps_h(date('Y/m/d', strtotime($debt['created_at']))) ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody></table></div>
            <?php endif; ?>
            </div>

            <div class="ps-sub">
            <h3 class="ps-section-title">پارەدانەکانی قەرزی ئیکسڕەی</h3>
            <?php if (!$xray['payments']): ?><div class="fw-empty">هیچ پارەدانێک نییە.</div><?php else: ?>
            <div class="fw-table-wrap"><table class="fw-table" style="min-width:500px"><thead><tr><th>بڕ</th><th>ڕێگا</th><th>بەروار</th></tr></thead><tbody>
            <?php foreach ($xray['payments'] as $xp): ?>
            <tr>
                <td class="num"><?= ps_nf($xp['amount']) ?></td>
                <td><?= ps_h($methodLabels[$xp['payment_method']] ?? $xp['payment_method']) ?></td>
                <td dir="ltr"><?= ps_h(date('Y/m/d', strtotime($xp['created_at']))) ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody></table></div>
            <?php endif; ?>
            </div>
        </div>
    </div>

    <?php if ($canAct): ?>
    <div class="fw-card">
        <div class="fw-card-head"><h3><i class="fas fa-wallet"></i> باڵانسی متمانە</h3><span class="fw-badge <?= $credit['balance'] > 0.009 ? 'success' : 'neutral' ?>"><?= ps_nf($credit['balance']) ?></span></div>
        <div class="fw-card-body">
            <?php if (!$credit['log']): ?><div class="fw-empty">هیچ تۆمارێکی متمانە نییە.</div><?php else: ?>
            <div class="fw-table-wrap"><table class="fw-table" style="min-width:500px"><thead><tr><th>بەروار</th><th>جۆر</th><th>بڕ</th><th>وردەکاری</th></tr></thead><tbody>
            <?php foreach ($credit['log'] as $log):
                $logBadge = $creditTypeLabels[$log['type']] ?? [$log['type'], 'neutral'];
            ?>
            <tr>
                <td dir="ltr"><?= ps_h(date('Y/m/d H:i', strtotime($log['created_at']))) ?></td>
                <td><span class="fw-badge <?= $logBadge[1] ?>"><?= ps_h($logBadge[0]) ?></span></td>
                <td class="num"><?= ps_nf($log['amount']) ?></td>
                <td><?= ps_h($log['description'] ?? '—') ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody></table></div>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <div class="fw-card">
        <div class="fw-card-head"><h3><i class="fas fa-history"></i> چالاکی</h3></div>
        <div class="fw-card-body">
            <?php if (!$activity): ?><div class="fw-empty">چالاکی تۆمار نەکراوە.</div><?php else: ?>
            <div class="fw-timeline">
            <?php foreach ($activity as $ev):
                $isLedgerEvent = array_key_exists('table_name', $ev);
                if ($isLedgerEvent) {
                    $label = $eventLabels[$ev['action']] ?? str_replace('_', ' ', (string)$ev['action']);
                    $sub = ps_h($ev['user_name'] ?? 'سیستەم') . ' · ' . ps_h($ev['table_name'] ?? '') . ' #' . (int)($ev['record_id'] ?? 0);
                } else {
                    $label = str_replace('_', ' ', (string)($ev['action_type'] ?? 'xray'));
                    $transition = trim(($ev['old_status'] ?? '') . ' → ' . ($ev['new_status'] ?? ''), ' →');
                    $sub = ps_h($ev['changed_by_role'] ?? 'سیستەم') . ($transition !== '' ? ' · ' . ps_h($transition) : '') . (!empty($ev['action_note']) ? ' · ' . ps_h($ev['action_note']) : '');
                }
            ?>
            <div class="fw-event">
                <div class="fw-event-time"><?= ps_h(date('Y/m/d H:i', strtotime($ev['created_at']))) ?></div>
                <div class="fw-event-line"></div>
                <div class="fw-event-copy"><strong><?= ps_h($label) ?></strong><span><?= $sub ?></span></div>
            </div>
            <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>

</div>

<?php if ($canAct): ?>
<script>
const PS_PATIENT_ID = <?= (int)$patientId ?>;
const PS_APP_URL = '<?= APP_URL ?>';

function psMoney(n) { return new Intl.NumberFormat('en-US', { maximumFractionDigits: 2 }).format(Number(n) || 0); }
function psEsc(s) { return String(s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c])); }
function psGoto(anchorCaseId) {
    let url = PS_APP_URL + '/patients/statement.php?patient_id=' + PS_PATIENT_ID;
    if (anchorCaseId) url += '&case_id=' + anchorCaseId + '#case-' + anchorCaseId;
    window.location.href = url;
}

// Double-submit guard: disable every submit button in the form while the
// fetch is in flight, same pattern cases/view.php uses for its own forms.
function psSetBusy(form, busy) {
    form.querySelectorAll('button[type="submit"]').forEach(function (b) {
        b.disabled = busy;
        if (busy) {
            b.dataset.origHtml = b.dataset.origHtml || b.innerHTML;
            b.innerHTML = '<i class="fas fa-spinner fa-spin"></i> چاوەڕوانبە...';
        } else if (b.dataset.origHtml) {
            b.innerHTML = b.dataset.origHtml;
        }
    });
}

async function psSubmit(form) {
    const fd = new FormData(form);
    psSetBusy(form, true);
    try {
        const res = await fetch(PS_APP_URL + '/api/finance-actions.php', { method: 'POST', body: fd });
        const out = await res.json();
        if (out.ok) {
            psGoto(form.dataset.anchor || 0);
            return;
        }
        psSetBusy(form, false);
        alert(out.error || 'هەڵەیەک ڕوویدا');
        if (out.stale) window.location.reload();
    } catch (e) {
        psSetBusy(form, false);
        alert('هەڵەی تەکنیکی ڕوویدا');
    }
}
</script>
<?php endif; ?>

<script>
// ---- Ledger search: client-side row filter, no reload needed. Only touches
// plain data rows (open/close rows stay put — they carry no useful text and
// removing them would make the totals look detached from the table). ----
(function () {
    const input = document.getElementById('ledgerSearch');
    const table = document.getElementById('ledgerTable');
    if (!input || !table) return;
    input.addEventListener('input', function () {
        const q = input.value.trim().toLowerCase();
        table.querySelectorAll('tbody tr').forEach(function (tr) {
            if (tr.classList.contains('ledger-open') || tr.classList.contains('ledger-close')) return;
            tr.hidden = q !== '' && !tr.textContent.toLowerCase().includes(q);
        });
    });
})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
