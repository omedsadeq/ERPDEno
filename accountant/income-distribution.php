<?php
/**
 * دابەشکردنی داهات — the tab beside داهات.
 *
 * For a period, every doctor's income split by his percentage (the same
 * figures as his daily summary and the income sheet), his cash in the box,
 * and whether the period is settled. «دابەشکردن» settles a doctor: the
 * centre's share moves from his box into قاسەی لێزان, his own share is paid
 * out (بڕی ڕاکێشان), and his full report opens for printing and signing.
 * All the rules live in classes/IncomeSettlement.php.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../classes/Auth.php';
require_once __DIR__ . '/../classes/Permission.php';
require_once __DIR__ . '/../classes/IncomeSettlement.php';

$auth = new Auth();
$auth->requireLogin();
if (!Permission::getInstance()->can('page.accountant_financial')) {
    header('Location: ' . APP_URL . '/403.php');
    exit;
}
$db = Database::getInstance();
$viewer = $auth->getCurrentUser();
$viewerId = (int)($viewer['id'] ?? 0);
$settlements = new IncomeSettlement();
$ledger = new CashboxLedger();
$canSettle = $settlements->canSettle($viewerId);
$isAdmin = $auth->isAdmin();

// ── Period: this month by default ───────────────────────────────────
$today = date('Y-m-d');
[$from, $to] = ds_range_from_request($_GET, date('Y-m-01'), date('Y-m-t'));
[$prevFrom, $prevTo] = ds_shift_range($from, $to, -1);
[$nextFrom, $nextTo] = ds_shift_range($from, $to, +1);
$rangeLabel = ds_range_label($from, $to);
$rangePresets = ds_range_presets();
$rangeQs = fn(string $f, string $t) => '?from=' . urlencode($f) . '&to=' . urlencode($t);
$isFuture = $from > $today;
$effTo = min($to, $today);   // a settlement never covers days still to come

// ── Actions ─────────────────────────────────────────────────────────
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::require();
    $action = (string)($_POST['action'] ?? '');
    try {
        if ($action === 'settle') {
            $res = $settlements->create(
                (int)($_POST['doctor_id'] ?? 0),
                (string)($_POST['from'] ?? ''),
                (string)($_POST['to'] ?? ''),
                (string)($_POST['transfer'] ?? '0'),
                (string)($_POST['payout'] ?? '0'),
                (string)($_POST['notes'] ?? ''),
                $viewerId
            );
            header('Location: ' . APP_URL . '/accountant/income-settlement-print.php?id=' . $res['id'] . '&new=1', true, 303);
            exit;
        }
        if ($action === 'cancel') {
            $settlements->cancel((int)($_POST['settlement_id'] ?? 0), (string)($_POST['reason'] ?? ''), $viewerId);
            $_SESSION['idist_flash'] = 'حیسابەکە هەڵوەشێنرایەوە و پارەکان گەڕێنرانەوە بۆ سندوقی دکتۆر';
        } elseif ($action === 'sign' || $action === 'unsign') {
            $settlements->markSigned((int)($_POST['settlement_id'] ?? 0), $viewerId, $action === 'sign');
            $_SESSION['idist_flash'] = $action === 'sign' ? 'وەک واژووکراو نیشانە کرا' : 'نیشانەی واژوو لابرا';
        }
        header('Location: ' . APP_URL . '/accountant/income-distribution.php' . $rangeQs($from, $to), true, 303);
        exit;
    } catch (DomainException | InvalidArgumentException $e) {
        $error = $e->getMessage();
    } catch (Throwable $e) {
        error_log('Income distribution failed: ' . $e->getMessage());
        $error = 'کردارەکە ئەنجام نەدرا؛ تکایە دووبارە هەوڵ بدەرەوە';
    }
}
$flash = $_SESSION['idist_flash'] ?? '';
unset($_SESSION['idist_flash']);

// ── Rows: every doctor, as on the income sheet ──────────────────────
$rows = [];
$totals = ['cash' => 0.0, 'exp' => 0.0, 'net' => 0.0, 'doc' => 0, 'ctr' => 0, 'due' => 0, 'box' => 0];
if (!$isFuture) {
    $doctorUsers = $db->fetchAll(
        "SELECT u.id, u.full_name, u.is_active
         FROM users u LEFT JOIN doctors d ON d.user_id = u.id
         WHERE u.role = 'doctor'
         ORDER BY d.id IS NULL, d.id, u.full_name"
    );
    foreach ($doctorUsers as $u) {
        $p = $settlements->preview((int)$u['id'], $from, $to);
        $ds = $p['ds'];
        if (!$u['is_active'] && abs($ds['cashIn']) < 0.5 && !$p['existing'] && $p['box'] === 0) {
            continue;
        }
        $rows[] = $p + ['active' => (bool)$u['is_active']];
        $totals['cash'] += $ds['cashIn'];
        $totals['exp'] += $ds['labExpense'] + $ds['storeExpense'];
        $totals['net'] += $ds['netAfterExpenses'];
        $totals['doc'] += $p['shares']['doctor'];
        $totals['ctr'] += $p['shares']['center'];
        $totals['due'] += $p['rate'] !== null ? $p['shares']['center_due'] : 0;
        $totals['box'] += $p['box'];
    }
}
$centerBox = $ledger->balanceOf(CashboxLedger::CENTER);
$history = $isFuture ? [] : $settlements->forPeriod($from, $effTo);
$settledCount = count(array_filter($rows, fn($r) => $r['existing']));

$c = fn(int $cents) => CashboxLedger::money(CashboxLedger::decimal($cents));
$m = fn(float $v) => $v < 0 ? '(' . dsMoney(abs($v)) . ')' : dsMoney($v);
$rateText = fn($r) => rtrim(rtrim(number_format((float)$r, 2, '.', ''), '0'), '.') . '%';

$pageTitle = 'دابەشکردنی داهات';
$breadcrumb = [
    ['title' => 'دارایی', 'url' => APP_URL . '/accountant/financial-summary.php'],
    ['title' => 'دابەشکردنی داهات'],
];
require_once __DIR__ . '/../includes/header.php';
?>

<style>
.idd{max-width:1240px;margin:0 auto 32px;color:#0f172a;direction:rtl}
.idd-bar{display:flex;flex-wrap:wrap;align-items:center;gap:8px;background:#fff;border-radius:14px;padding:10px 12px;
    box-shadow:0 1px 2px rgba(15,23,42,.05),0 6px 18px -10px rgba(15,23,42,.2)}
.idd-bar label{display:flex;align-items:center;gap:6px;font-size:12.5px;color:#475569}
.idd-bar input{height:36px;border:1px solid #e2e8f0;border-radius:9px;background:#f8fafc;padding:0 10px;font:inherit;font-size:13px}
.idd-bar button{height:36px;border:0;border-radius:9px;padding:0 16px;font:inherit;font-size:13px;font-weight:600;color:#fff;cursor:pointer;background:linear-gradient(135deg,#0f766e,#0d9488)}
.idd-days{display:flex;flex-wrap:wrap;gap:4px;margin-inline-start:auto}
.idd-days a{padding:6px 11px;border-radius:999px;font-size:12px;text-decoration:none;color:#0f766e;background:#f0fdfa;border:1px solid #ccfbf1}
.idd-days a.on{background:#0f766e;border-color:#0f766e;color:#fff}
.idd-alert{margin-top:12px;padding:11px 14px;border-radius:10px;font-size:13px}
.idd-alert.err{background:#fef2f2;color:#b91c1c;border:1px solid #fecaca}
.idd-alert.ok{background:#ecfdf5;color:#047857;border:1px solid #a7f3d0}
.idd-alert.info{background:#fffbeb;color:#92400e;border:1px solid #fde68a}

.idd-cards{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;margin-top:14px}
.idd-card{background:#fff;border:1px solid #e2e8f0;border-radius:14px;padding:14px 16px}
.idd-card .k{font-size:12px;color:#64748b;display:flex;align-items:center;gap:6px}
.idd-card .v{font-size:21px;font-weight:800;margin-top:4px;font-variant-numeric:tabular-nums;direction:ltr;text-align:right}
.idd-card .s{font-size:11px;color:#94a3b8;margin-top:2px}
.idd-card.lezan{background:linear-gradient(135deg,#0f766e,#115e59);border:0;color:#fff}
.idd-card.lezan .k,.idd-card.lezan .s{color:#ccfbf1}
.idd-card.ctr .v{color:#0f766e}.idd-card.doc .v{color:#5b21b6}

.idd-sheet{margin-top:14px;background:#fff;border-radius:16px;padding:18px 20px;box-shadow:0 1px 2px rgba(15,23,42,.06),0 10px 28px -14px rgba(15,23,42,.22)}
.idd-sheet h2{margin:0 0 12px;font-size:15px;display:flex;align-items:center;gap:8px;justify-content:space-between;flex-wrap:wrap}
.idd-sheet h2 small{font-size:12px;font-weight:600;color:#64748b}
.idd-scroll{overflow-x:auto}
.idd-tbl{width:100%;border-collapse:collapse;min-width:1040px}
.idd-tbl th{background:#f1f5f9;color:#334155;font-size:12px;font-weight:700;padding:9px 10px;border-bottom:1px solid #e2e8f0;white-space:nowrap;text-align:start}
.idd-tbl td{padding:9px 10px;border-bottom:1px solid #f1f5f9;font-size:13px;vertical-align:middle}
.idd-tbl .n{text-align:left;direction:ltr;white-space:nowrap;font-variant-numeric:tabular-nums}
.idd-tbl .doc{color:#5b21b6;font-weight:700}.idd-tbl .ctr{color:#0f766e;font-weight:700}
.idd-tbl th.doc{background:#ede9fe;color:#5b21b6}.idd-tbl th.ctr{background:#ccfbf1;color:#115e59}
.idd-tbl tr.tot td{background:#f8fafc;font-weight:800;border-top:2px solid #cbd5e1}
.idd-tbl .muted{color:#94a3b8}
.idd-tbl tr.off td{opacity:.6}
.idd-rate{display:inline-block;padding:1px 8px;border-radius:999px;font-size:11.5px;font-weight:700;background:#ede9fe;color:#6d28d9;direction:ltr;white-space:nowrap}
.idd-tbl td:nth-child(2) b{white-space:nowrap}
.idd-rate.none{background:#fef3c7;color:#b45309;direction:rtl}
.idd-btn{display:inline-flex;align-items:center;gap:6px;padding:6px 12px;border-radius:8px;border:0;font:inherit;font-size:12.5px;font-weight:700;cursor:pointer;text-decoration:none;white-space:nowrap}
.idd-btn.go{background:#0f766e;color:#fff}.idd-btn.go:hover{background:#115e59}
.idd-btn.ghost{background:#f1f5f9;color:#334155}.idd-btn.ghost:hover{background:#e2e8f0}
.idd-btn.warn{background:#fef2f2;color:#b91c1c}
.idd-done{display:inline-flex;flex-direction:column;gap:2px;font-size:12px}
.idd-done b{color:#047857}.idd-done small{color:#64748b}
.idd-chip{display:inline-block;padding:2px 8px;border-radius:999px;font-size:11px;font-weight:700}
.idd-chip.signed{background:#dcfce7;color:#166534}.idd-chip.wait{background:#fef3c7;color:#92400e}.idd-chip.cancel{background:#f1f5f9;color:#64748b}
.idd-acts{display:flex;gap:4px;flex-wrap:wrap}
.idd-acts form{margin:0}

/* settle dialog */
.idd-dlg{max-width:560px}
.idd-fig{display:grid;grid-template-columns:repeat(2,1fr);gap:6px 14px;padding:12px 14px;border-radius:12px;background:#f8fafc;border:1px solid #e2e8f0;margin-bottom:14px}
.idd-fig div{display:flex;justify-content:space-between;gap:8px;font-size:12.5px;color:#475569}
.idd-fig b{direction:ltr;font-variant-numeric:tabular-nums;color:#0f172a}
.idd-fig .doc b{color:#5b21b6}.idd-fig .ctr b{color:#0f766e}
.idd-fig .full{grid-column:1/-1;border-top:1px dashed #cbd5e1;padding-top:6px}
.idd-move{display:grid;grid-template-columns:1fr 1fr;gap:10px}
.idd-move label{display:flex;flex-direction:column;gap:5px;font-size:12.5px;font-weight:700;color:#334155}
.idd-move input{height:40px;border:1.5px solid #cbd5e1;border-radius:10px;padding:0 10px;font:inherit;font-size:15px;font-weight:700;direction:ltr;text-align:center}
.idd-move .ctr input{border-color:#5eead4;background:#f0fdfa}.idd-move .doc input{border-color:#c4b5fd;background:#faf8ff}
.idd-left{display:flex;justify-content:space-between;align-items:center;margin-top:12px;padding:10px 14px;border-radius:12px;background:#ecfdf5;border:1px solid #a7f3d0;font-size:13px;font-weight:700;color:#047857}
.idd-left b{font-size:18px;direction:ltr}
.idd-left.neg{background:#fef2f2;border-color:#fecaca;color:#b91c1c}
.idd-warn{margin-top:10px;font-size:12px;color:#b45309;line-height:1.7}
.idd-notes{width:100%;margin-top:12px;border:1px solid #cbd5e1;border-radius:10px;padding:8px 10px;font:inherit;font-size:13px;min-height:44px}
@media(max-width:900px){.idd-cards{grid-template-columns:repeat(2,1fr)}.idd-days{margin-inline-start:0}}
@media(max-width:560px){.idd-cards{grid-template-columns:1fr}.idd-fig,.idd-move{grid-template-columns:1fr}}
</style>

<div class="idd">

    <form method="get" class="idd-bar">
        <label>لە <input type="date" name="from" value="<?= dsEsc($from) ?>"></label>
        <label>بۆ <input type="date" name="to" value="<?= dsEsc($to) ?>"></label>
        <button type="submit">پیشاندان</button>
        <div class="idd-days">
            <a href="<?= dsEsc($rangeQs($prevFrom, $prevTo)) ?>">← پێشوو</a>
            <?php foreach ($rangePresets as [$pLabel, $pFrom, $pTo]): ?>
            <a href="<?= dsEsc($rangeQs($pFrom, $pTo)) ?>" class="<?= $pFrom === $from && $pTo === $to ? 'on' : '' ?>"><?= dsEsc($pLabel) ?></a>
            <?php endforeach; ?>
            <a href="<?= dsEsc($rangeQs($nextFrom, $nextTo)) ?>">داهاتوو →</a>
        </div>
    </form>

    <?php if ($error): ?><div class="idd-alert err"><i class="fas fa-exclamation-circle"></i> <?= dsEsc($error) ?></div><?php endif; ?>
    <?php if ($flash): ?><div class="idd-alert ok"><i class="fas fa-check-circle"></i> <?= dsEsc($flash) ?></div><?php endif; ?>
    <?php if ($isFuture): ?>
    <div class="idd-alert info"><i class="fas fa-calendar"></i> ئەم ماوەیە هێشتا نەهاتووە — حیساب تەنها بۆ ڕۆژە تێپەڕیوەکان دەکرێت.</div>
    <?php elseif ($effTo < $to): ?>
    <div class="idd-alert info"><i class="fas fa-info-circle"></i> ماوەکە تا ئەمڕۆ (<bdi><?= dsEsc($effTo) ?></bdi>) هەژمار دەکرێت؛ ڕۆژەکانی دواتر لە حیسابی داهاتوودا دێن.</div>
    <?php endif; ?>

    <div class="idd-cards">
        <div class="idd-card lezan">
            <div class="k"><i class="fas fa-building-columns"></i> قاسەی <?= htmlspecialchars(Branding::shortName()) ?></div>
            <div class="v"><?= dsEsc($c($centerBox)) ?></div>
            <div class="s">نەقدی ئێستای قاسەی سەنتەر</div>
        </div>
        <div class="idd-card ctr">
            <div class="k"><i class="fas fa-percent"></i> پشکی سەنتەر لەم ماوەیە</div>
            <div class="v"><?= dsEsc($c($totals['ctr'])) ?></div>
            <?php if (CashboxLedger::splitEnabled()): ?>
            <div class="s">ڕاستەوخۆ دەچێتە قاسەی <?= htmlspecialchars(Branding::shortName()) ?> — هەر پارەیەک کە وەردەگیرێت</div>
            <?php else: ?>
            <div class="s">+ خەرجی تاقیگە و کۆگا = <?= dsEsc($c($totals['due'])) ?> بۆ قاسەی <?= htmlspecialchars(Branding::shortName()) ?></div>
            <?php endif; ?>
        </div>
        <div class="idd-card doc">
            <div class="k"><i class="fas fa-user-doctor"></i> پشکی دکتۆران</div>
            <div class="v"><?= dsEsc($c($totals['doc'])) ?></div>
            <div class="s">کۆی پشکی هەموو دکتۆرەکان</div>
        </div>
        <div class="idd-card">
            <div class="k"><i class="fas fa-file-signature"></i> حیسابکراو</div>
            <div class="v"><?= (int)$settledCount ?> / <?= count($rows) ?></div>
            <div class="s">دکتۆر بۆ <bdi><?= dsEsc($rangeLabel) ?></bdi></div>
        </div>
    </div>

    <div class="idd-sheet">
        <h2><span><i class="fas fa-scale-balanced" style="color:#0f766e;"></i> دابەشکردنی داهاتی دکتۆرەکان</span>
            <small>سافی = کۆی نەقد − خەرجی تاقیگە و کۆگا · پشکی دکتۆر = سافی × ڕێژەی خۆی</small></h2>
        <div class="idd-scroll">
            <table class="idd-tbl">
                <thead><tr>
                    <th>#</th><th>دکتۆر</th><th>ڕێژە</th><th class="n">کۆی نەقد</th><th class="n">خەرجی</th><th class="n">سافی</th>
                    <th class="n doc">پشکی دکتۆر</th><th class="n ctr">پشکی سەنتەر</th><th class="n ctr" title="پشکی سەنتەر + خەرجی تاقیگە و کۆگا">بۆ قاسەی <?= htmlspecialchars(Branding::shortName()) ?></th>
                    <th class="n">نەقدی ناو سندوق</th><th>حیساب</th>
                </tr></thead>
                <tbody>
                <?php if (!$rows): ?>
                    <tr><td colspan="11" class="muted" style="text-align:center;padding:24px;">هیچ دکتۆرێک نییە.</td></tr>
                <?php endif; ?>
                <?php foreach ($rows as $i => $r): $ds = $r['ds']; ?>
                    <tr class="<?= $r['active'] ? '' : 'off' ?>">
                        <td class="muted"><?= $i + 1 ?></td>
                        <td><b><?= dsEsc($r['doctor_name']) ?></b></td>
                        <td><?= $r['rate'] !== null ? '<span class="idd-rate">' . dsEsc($rateText($r['rate'])) . '</span>' : '<span class="idd-rate none" title="لە ئەکاونتی دکتۆر دیاری دەکرێت">دیاری نەکراوە</span>' ?></td>
                        <td class="n"><?= dsEsc($m($ds['cashIn'])) ?></td>
                        <td class="n muted"><?= dsEsc($m($ds['labExpense'] + $ds['storeExpense'])) ?></td>
                        <td class="n"><b><?= dsEsc($m($ds['netAfterExpenses'])) ?></b></td>
                        <td class="n doc"><?= $r['rate'] !== null ? dsEsc($m((float)$ds['doctorShare'])) : '—' ?></td>
                        <td class="n ctr"><?= $r['rate'] !== null ? dsEsc($m((float)$ds['centerShare'])) : '—' ?></td>
                        <td class="n ctr"><?php if ($r['rate'] === null): ?>—<?php elseif (CashboxLedger::splitEnabled()): ?><span title="پشکی <?= htmlspecialchars(Branding::shortName()) ?> کاتی وەرگرتنی پارەکە ڕاستەوخۆ چووەتە قاسەی <?= htmlspecialchars(Branding::shortName()) ?>"><i class="fas fa-check"></i> ڕاستەوخۆ</span><?php else: ?><?= dsEsc($c($r['shares']['center_due'])) ?><?php endif; ?></td>
                        <td class="n"><?= dsEsc($c($r['box'])) ?></td>
                        <td>
                        <?php if ($r['existing']): $ex = $r['existing']; ?>
                            <span class="idd-done">
                                <b><i class="fas fa-check-circle"></i> حیسابکراوە</b>
                                <small><bdi><?= dsEsc($ex['settlement_no']) ?></bdi> · <?= $ex['signed_at'] ? 'واژووکراو' : 'چاوەڕوانی واژوو' ?></small>
                                <?php if ($ex['period_from'] !== $r['from'] || $ex['period_to'] !== $r['to']): ?>
                                <small>تەنها <bdi><?= dsEsc($ex['period_from']) ?></bdi> بۆ <bdi><?= dsEsc($ex['period_to']) ?></bdi></small>
                                <?php endif; ?>
                            </span>
                            <a class="idd-btn ghost" href="<?= APP_URL ?>/accountant/income-settlement-print.php?id=<?= (int)$ex['id'] ?>" target="_blank"><i class="fas fa-print"></i></a>
                        <?php elseif ($r['rate'] === null): ?>
                            <?php if ($isAdmin): ?><a class="idd-btn ghost" href="<?= APP_URL ?>/admin/users.php"><i class="fas fa-percent"></i> دیاریکردنی ڕێژە</a>
                            <?php else: ?><span class="muted">ڕێژە دیاری نەکراوە</span><?php endif; ?>
                        <?php elseif ($canSettle): ?>
                            <button type="button" class="idd-btn go" onclick='iddOpen(<?= json_encode([
                                'id' => $r['doctor_id'], 'name' => $r['doctor_name'], 'from' => $r['from'], 'to' => $r['to'],
                                'rate' => $rateText($r['rate']), 'cash' => $ds['cashIn'], 'exp' => $ds['labExpense'] + $ds['storeExpense'],
                                'net' => $ds['netAfterExpenses'], 'doc' => $r['shares']['doctor'] / 100, 'ctr' => $r['shares']['center'] / 100,
                                'due' => $r['shares']['center_due'] / 100,
                                'box' => $r['box'] / 100, 'transfer' => $r['defaults']['transfer'] / 100, 'payout' => $r['defaults']['payout'] / 100,
                            ], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>)'>
                                <i class="fas fa-scale-balanced"></i> دابەشکردن
                            </button>
                        <?php else: ?>
                            <span class="muted">حیساب نەکراوە</span>
                        <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if ($rows): ?>
                    <tr class="tot">
                        <td></td><td>کۆ</td><td></td>
                        <td class="n"><?= dsEsc($m($totals['cash'])) ?></td>
                        <td class="n"><?= dsEsc($m($totals['exp'])) ?></td>
                        <td class="n"><?= dsEsc($m($totals['net'])) ?></td>
                        <td class="n doc"><?= dsEsc($c($totals['doc'])) ?></td>
                        <td class="n ctr"><?= dsEsc($c($totals['ctr'])) ?></td>
                        <td class="n ctr"><?= dsEsc($c($totals['due'])) ?></td>
                        <td class="n"><?= dsEsc($c($totals['box'])) ?></td>
                        <td></td>
                    </tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="idd-sheet">
        <h2><span><i class="fas fa-file-signature" style="color:#0f766e;"></i> حیسابەکانی ئەم ماوەیە</span><small><?= count($history) ?> حیساب</small></h2>
        <div class="idd-scroll">
            <table class="idd-tbl">
                <thead><tr>
                    <th>ژمارە</th><th>دکتۆر</th><th>ماوە</th><th class="n">سافی</th><th class="n doc">پشکی دکتۆر</th><th class="n ctr">پشکی سەنتەر</th>
                    <th class="n">گواستراوە بۆ <?= htmlspecialchars(Branding::shortName()) ?></th><th class="n">ڕاکێشان (درا بە دکتۆر)</th><th class="n">ماوەی سندوق</th><th>دۆخ</th><th></th>
                </tr></thead>
                <tbody>
                <?php if (!$history): ?>
                    <tr><td colspan="11" class="muted" style="text-align:center;padding:22px;">هێشتا هیچ حیسابێک بۆ ئەم ماوەیە نەکراوە.</td></tr>
                <?php endif; ?>
                <?php foreach ($history as $h): $done = $h['status'] === 'done'; ?>
                    <tr class="<?= $done ? '' : 'off' ?>">
                        <td><bdi><b><?= dsEsc($h['settlement_no']) ?></b></bdi><br><small class="muted"><bdi><?= dsEsc(substr($h['created_at'], 0, 16)) ?></bdi> · <?= dsEsc($h['created_by_name'] ?? '') ?></small></td>
                        <td><?= dsEsc($h['doctor_name'] ?? '') ?></td>
                        <td><bdi><?= dsEsc($h['period_from']) ?></bdi> بۆ <bdi><?= dsEsc($h['period_to']) ?></bdi></td>
                        <td class="n"><?= dsEsc(CashboxLedger::money($h['net_amount'])) ?></td>
                        <td class="n doc"><?= dsEsc(CashboxLedger::money($h['doctor_share'])) ?></td>
                        <td class="n ctr"><?= dsEsc(CashboxLedger::money($h['center_share'])) ?></td>
                        <td class="n"><?= dsEsc(CashboxLedger::money($h['transferred_to_center'])) ?></td>
                        <td class="n"><?= dsEsc(CashboxLedger::money($h['paid_to_doctor'])) ?></td>
                        <td class="n"><?= dsEsc(CashboxLedger::money($h['box_after'])) ?></td>
                        <td>
                            <?php if (!$done): ?><span class="idd-chip cancel">هەڵوەشێنراوە</span>
                            <?php elseif ($h['signed_at']): ?><span class="idd-chip signed"><i class="fas fa-signature"></i> واژووکراو</span>
                            <?php else: ?><span class="idd-chip wait">چاوەڕوانی واژوو</span><?php endif; ?>
                        </td>
                        <td>
                            <div class="idd-acts">
                                <a class="idd-btn ghost" href="<?= APP_URL ?>/accountant/income-settlement-print.php?id=<?= (int)$h['id'] ?>" target="_blank" title="چاپکردنی حیساب"><i class="fas fa-print"></i> چاپ</a>
                                <?php if ($done && $canSettle): ?>
                                <form method="post">
                                    <input type="hidden" name="csrf_token" value="<?= dsEsc(Csrf::token()) ?>">
                                    <input type="hidden" name="action" value="<?= $h['signed_at'] ? 'unsign' : 'sign' ?>">
                                    <input type="hidden" name="settlement_id" value="<?= (int)$h['id'] ?>">
                                    <button type="submit" class="idd-btn ghost"><i class="fas fa-signature"></i> <?= $h['signed_at'] ? 'لابردنی واژوو' : 'واژووکرا' ?></button>
                                </form>
                                <?php endif; ?>
                                <?php if ($done && $isAdmin): ?>
                                <button type="button" class="idd-btn warn" onclick="iddCancel(<?= (int)$h['id'] ?>, <?= dsEsc(json_encode($h['settlement_no'])) ?>)"><i class="fas fa-undo"></i></button>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php if ($canSettle): ?>
<div class="dialog-overlay" id="iddDialog">
    <div class="dialog idd-dlg">
        <div class="dialog-header">
            <h4 class="dialog-title" id="iddTitle">دابەشکردنی داهات</h4>
            <button type="button" class="dialog-close" onclick="closeDialog('iddDialog')"><i class="fas fa-times"></i></button>
        </div>
        <form method="post" id="iddForm">
            <input type="hidden" name="csrf_token" value="<?= dsEsc(Csrf::token()) ?>">
            <input type="hidden" name="action" value="settle">
            <input type="hidden" name="doctor_id" id="iddDoctor">
            <input type="hidden" name="from" id="iddFrom">
            <input type="hidden" name="to" id="iddTo">
            <div class="dialog-body">
                <div class="idd-fig">
                    <div><span>کۆی نەقد</span><b id="iddCash"></b></div>
                    <div><span>خەرجی تاقیگە و کۆگا</span><b id="iddExp"></b></div>
                    <div><span>سافی</span><b id="iddNet"></b></div>
                    <div><span>ڕێژەی دکتۆر</span><b id="iddRate"></b></div>
                    <div class="doc"><span>پشکی دکتۆر</span><b id="iddDoc"></b></div>
                    <div class="ctr"><span>پشکی سەنتەر</span><b id="iddCtr"></b></div>
                    <div><span>خەرجی (دەگەڕێتەوە بۆ سەنتەر)</span><b id="iddExp2"></b></div>
                    <div class="ctr"><span>کۆی مافی سەنتەر</span><b id="iddDue"></b></div>
                    <div class="full"><span>نەقدی ئێستای ناو سندوقی دکتۆر</span><b id="iddBox"></b></div>
                </div>
                <?php if (CashboxLedger::splitEnabled()): ?>
                <div class="idd-left" style="background:#f0fdfa;border-color:#99f6e4;color:#0f766e;">
                    <span><i class="fas fa-check-circle"></i> پشکی <?= htmlspecialchars(Branding::shortName()) ?> و بەشی دکتۆر لە خەرجی، کاتی وەرگرتنی پارەکە ڕاستەوخۆ چووەتە قاسەی <?= htmlspecialchars(Branding::shortName()) ?> — سندوقی دکتۆر تەنها پشکی خۆی تێدایە.</span>
                </div>
                <?php endif; ?>
                <div class="idd-move">
                    <label class="ctr"<?= CashboxLedger::splitEnabled() ? ' hidden' : '' ?>>گواستنەوە بۆ قاسەی <?= htmlspecialchars(Branding::shortName()) ?>
                        <input type="number" name="transfer" id="iddTransfer" min="0" step="0.01" required>
                    </label>
                    <label class="doc">ڕاکێشان — دەدرێت بە دکتۆر
                        <input type="number" name="payout" id="iddPayout" min="0" step="0.01" required>
                    </label>
                </div>
                <div class="idd-left" id="iddLeftBox"><span>ماوەی ناو سندوق دوای حیساب</span><b id="iddLeft"></b></div>
                <div class="idd-warn" id="iddWarn" hidden></div>
                <textarea class="idd-notes" name="notes" maxlength="600" placeholder="تێبینی (ئارەزوومەندانە)"></textarea>
            </div>
            <div class="dialog-footer">
                <button type="button" class="btn btn-secondary" onclick="closeDialog('iddDialog')">پاشگەزبوونەوە</button>
                <button type="submit" class="btn btn-success" id="iddSubmit"><i class="fas fa-check"></i> دابەشکردن و چاپکردنی حیساب</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<?php if ($isAdmin): ?>
<div class="dialog-overlay" id="iddCancelDialog">
    <div class="dialog" style="max-width:440px;">
        <div class="dialog-header">
            <h4 class="dialog-title" id="iddCancelTitle">هەڵوەشاندنەوەی حیساب</h4>
            <button type="button" class="dialog-close" onclick="closeDialog('iddCancelDialog')"><i class="fas fa-times"></i></button>
        </div>
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= dsEsc(Csrf::token()) ?>">
            <input type="hidden" name="action" value="cancel">
            <input type="hidden" name="settlement_id" id="iddCancelId">
            <div class="dialog-body">
                <p style="font-size:12.5px;color:#64748b;line-height:1.8;margin-top:0;">پشکی سەنتەر لە قاسەی <?= htmlspecialchars(Branding::shortName()) ?>ەوە دەگەڕێتەوە بۆ سندوقی دکتۆر و ڕاکێشانەکەش دەگەڕێتەوە ناو سندوق. حیسابەکە وەک مێژوو دەمێنێتەوە.</p>
                <textarea name="reason" class="form-control" rows="3" minlength="3" maxlength="600" required placeholder="هۆکاری هەڵوەشاندنەوە"></textarea>
            </div>
            <div class="dialog-footer">
                <button type="button" class="btn btn-secondary" onclick="closeDialog('iddCancelDialog')">پاشگەزبوونەوە</button>
                <button type="submit" class="btn btn-danger"><i class="fas fa-undo"></i> هەڵوەشاندنەوە</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<script>
(function () {
    const nf = v => Number(v || 0).toLocaleString('en-US', { maximumFractionDigits: 2 });
    const signed = v => (v < 0 ? '(' + nf(-v) + ')' : nf(v));
    let cur = null;
    const $ = id => document.getElementById(id);

    function refresh() {
        if (!cur) return;
        const t = Math.max(0, Number($('iddTransfer').value) || 0);
        const p = Math.max(0, Number($('iddPayout').value) || 0);
        const left = Math.round((cur.box - t - p) * 100) / 100;
        $('iddLeft').textContent = signed(left);
        $('iddLeftBox').classList.toggle('neg', left < 0);
        const warn = [];
        if (t > cur.due) warn.push('گواستنەوە لە مافی سەنتەر زیاترە.');
        if (p > cur.doc) warn.push('ڕاکێشان لە پشکی دکتۆر زیاترە.');
        if (left < 0) warn.push('نەقدی ناو سندوق بەس نییە.');
        else if (cur.box < cur.due + cur.doc) warn.push('نەقدی ناو سندوق لە کۆی مافی سەنتەر و پشکی دکتۆر کەمترە — بڕەکان بەپێی نەقدی بەردەست دانراون.');
        $('iddWarn').hidden = !warn.length;
        $('iddWarn').textContent = warn.join(' ');
        $('iddSubmit').disabled = left < 0 || t > cur.due || p > cur.doc;
    }

    window.iddOpen = function (d) {
        cur = d;
        $('iddTitle').textContent = 'دابەشکردنی داهاتی ' + d.name;
        $('iddDoctor').value = d.id; $('iddFrom').value = d.from; $('iddTo').value = d.to;
        $('iddCash').textContent = signed(d.cash); $('iddExp').textContent = nf(d.exp);
        $('iddNet').textContent = signed(d.net); $('iddRate').textContent = d.rate;
        $('iddDoc').textContent = nf(d.doc); $('iddCtr').textContent = nf(d.ctr); $('iddBox').textContent = signed(d.box);
        $('iddExp2').textContent = nf(d.exp); $('iddDue').textContent = nf(d.due);
        $('iddTransfer').value = d.transfer; $('iddTransfer').max = d.due;
        $('iddPayout').value = d.payout; $('iddPayout').max = d.doc;
        refresh();
        openDialog('iddDialog');
    };
    if ($('iddForm')) {
        ['iddTransfer', 'iddPayout'].forEach(id => $(id).addEventListener('input', refresh));
        $('iddForm').addEventListener('submit', function () { $('iddSubmit').disabled = true; });
    }

    window.iddCancel = function (id, no) {
        $('iddCancelId').value = id;
        $('iddCancelTitle').textContent = 'هەڵوەشاندنەوەی حیسابی ' + no;
        openDialog('iddCancelDialog');
    };
})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
