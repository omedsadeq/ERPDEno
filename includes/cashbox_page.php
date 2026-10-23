<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../classes/Auth.php';
require_once __DIR__ . '/../classes/CashboxLedger.php';
$auth = new Auth();
$auth->requireLogin();
$cashboxUser = $auth->getCurrentUser();
if (!$cashboxUser || $cashboxUser['role'] !== ($cashboxAudience ?? '') || !Permission::getInstance()->can('page.cashboxes')) {
    http_response_code(403);
    require __DIR__ . '/../403.php';
    exit;
}
$cashboxAdmin = $cashboxAudience === 'admin';
$cashboxLedger = new CashboxLedger();
$cashboxActor = (int)$cashboxUser['id'];
$cashboxStr = function ($key, $default = '') { return is_string($_GET[$key] ?? null) ? $_GET[$key] : $default; };
$cashboxKey = $cashboxAdmin ? $cashboxStr('box','*') : ($cashboxAudience === 'doctor' ? 'doctor:' . $cashboxActor : 'xray');
$cashboxError = '';
$cashboxRoute = $cashboxAdmin ? '/admin/cashboxes.php' : ($cashboxAudience === 'doctor' ? '/doctors/cashbox.php' : '/xray/cashbox.php');
$cashboxBaseUrl = APP_URL . $cashboxRoute;
$cashboxCanMove = $cashboxKey !== '*' && in_array($cashboxAudience,['admin','doctor'],true);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::require();
    try {
        $postedKey = is_string($_POST['account_key'] ?? null) ? $_POST['account_key'] : '';
        $action = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';
        if ($postedKey !== $cashboxKey && !($cashboxAdmin && $action === 'reverse')) throw new DomainException('سندوقی جوڵەکە نادروستە');
        $reason = is_string($_POST['reason'] ?? null) ? $_POST['reason'] : '';
        if ($action === 'reverse') {
            $cashboxLedger->reverse((int)($_POST['entry_id'] ?? 0),$reason,$cashboxActor);
        } else {
            $cashboxLedger->move($cashboxKey,$action,is_string($_POST['amount'] ?? null) ? $_POST['amount'] : '',$reason,is_string($_POST['request_key'] ?? null) ? $_POST['request_key'] : '',$cashboxActor);
        }
        $_SESSION['cashbox_flash'] = 'جوڵەکە بە سەرکەوتوویی تۆمار کرا';
        header('Location: ' . $cashboxBaseUrl . ($cashboxAdmin ? '?' . http_build_query(['box'=>$cashboxKey]) : ''), true, 303);
        exit;
    } catch (DomainException | InvalidArgumentException $e) {
        $cashboxError = $e->getMessage();
    } catch (Throwable $e) {
        error_log('Cashbox movement failed: ' . $e->getMessage());
        $cashboxError = 'جوڵەکە تۆمار نەکرا؛ تکایە دووبارە هەوڵ بدەرەوە';
    }
}
$cashboxFrom = $cashboxStr('from','2026-10-01');
$cashboxTo = $cashboxStr('to',date('Y-m-d'));
$cashboxDirection = $cashboxStr('direction');
try {
    // Check scope before any potentially clinic-wide read or reconciliation.
    $cashboxLedger->authorize($cashboxActor,$cashboxKey === '*' ? 'xray' : $cashboxKey);
    $cashboxAccounts = $cashboxAdmin ? $cashboxLedger->accounts($cashboxActor) : [];
    $cashboxStatement = $cashboxLedger->statement($cashboxKey,$cashboxActor,$cashboxFrom,$cashboxTo,$cashboxDirection,max(1,(int)$cashboxStr('page','1')));
} catch (DomainException $e) {
    http_response_code(400);
    $cashboxError = $e->getMessage();
    $cashboxStatement = null;
    $cashboxAccounts = [];
}
$pageTitle = $cashboxAdmin ? 'سندوقەکان' : ($cashboxAudience === 'doctor' ? 'سندوقی من' : 'سندوقی ئێکسڕەی');
$breadcrumb = [['title'=>$pageTitle]];
require __DIR__ . '/header.php';
function cbEsc($value) { return htmlspecialchars((string)$value,ENT_QUOTES,'UTF-8'); }
function cbName(array $account): string { return $account['owner_type'] === 'amanat' ? 'قاسەی ئامانەت' : ($account['owner_type'] === 'xray' ? 'سندوقی ئێکسڕەی' : ($account['owner_type'] === 'center' ? 'قاسەی لێزان' : ($account['owner_type'] === 'all' ? 'هەموو سندوقەکان' : 'د. ' . ($account['full_name'] ?? '—')))); }
$cashboxFlash = $_SESSION['cashbox_flash'] ?? '';
unset($_SESSION['cashbox_flash']);
$cashboxKinds = ['receipt'=>'نەقدی وەسڵ','refund'=>'گەڕاندنەوە / ڕاستکردنەوە','deposit'=>'دانانی نەقد','withdrawal'=>'ڕاکێشانی نەقد','reversal'=>'پێچەوانەکردنەوەی جوڵە',
    // دابەشکردنی داهات (accountant/income-distribution.php)
    'transfer_out'=>'گواستنەوە بۆ قاسەی لێزان','transfer_in'=>'پشکی سەنتەر لە سندوقی دکتۆر','payout'=>'پێدانی پشکی دکتۆر',
    'center_share'=>'پشکی لێزان','expense_share'=>'بەشی دکتۆر لە خەرجی',
    'amanat_move'=>'ڕاستکردنەوەی ئامانەتی کۆن','amanat_in'=>'گەڕاندنەوە بۆ ئامانەت','amanat_out'=>'گواستنەوە لە ئامانەت بۆ پارەدان'];
?>
<style>
.cb-wrap{max-width:1480px;margin:auto;color:#172b3a}.cb-hero{position:relative;overflow:hidden;border-radius:20px;background:linear-gradient(120deg,#102d40,#064e53);color:#fff;padding:30px;display:flex;align-items:center;justify-content:space-between;gap:24px;margin-bottom:20px}.cb-hero>div{min-width:0;max-width:100%}.cb-hero:after{content:'';position:absolute;width:280px;height:280px;border:45px solid #ffffff08;border-radius:50%;inset-inline-end:-85px;top:-105px;pointer-events:none}.cb-eyebrow{color:#b5e5df;font-size:12px;display:flex;gap:7px;align-items:center}.cb-hero h2{font-size:19px;margin:10px 0 6px;color:#fff}.cb-balance{font-size:clamp(32px,4vw,48px);font-weight:800;font-variant-numeric:tabular-nums;direction:ltr;text-align:right;line-height:1.3}.cb-balance{overflow-wrap:anywhere}.cb-balance small{font-size:13px;font-weight:400;color:#b5e5df;margin-inline-start:8px}.cb-caption{color:#c5dcdf;font-size:12px;margin-top:8px}.cb-buttons{display:flex;gap:9px;flex-wrap:wrap;position:relative;z-index:1}.cb-button{display:inline-flex;align-items:center;justify-content:center;gap:8px;padding:11px 18px;border-radius:10px;border:1px solid #cfdde3;background:#fff;color:#234453;font:inherit;font-size:13px;cursor:pointer;text-decoration:none;min-height:42px}.cb-button:hover{text-decoration:none;filter:brightness(.97)}.cb-button.primary{background:#12a888;border-color:#12a888;color:#fff}.cb-button.out{background:#ffffff12;color:#fff;border-color:#ffffff40}.cb-hero-icon{display:grid;place-items:center;width:76px;height:76px;border-radius:22px;background:#ffffff12;font-size:31px;color:#70e4c8;flex-shrink:0}.cb-metrics{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:14px;margin:18px 0}.cb-metric,.cb-panel,.cb-account{background:#fff;border:1px solid #e1e9ed;border-radius:14px}.cb-metric{padding:17px}.cb-metric span{font-size:12px;color:#647b88}.cb-metric b{display:block;font-size:22px;font-variant-numeric:tabular-nums;margin-top:8px}.cb-in{color:#087f65}.cb-out{color:#ba493c}.cb-note{font-size:12px;line-height:1.8;color:#627985;margin:14px 0}.cb-accounts{display:grid;grid-template-columns:repeat(auto-fill,minmax(210px,1fr));gap:12px;margin-bottom:22px}.cb-account{padding:16px;text-decoration:none;color:#233e4b;transition:border-color .15s,box-shadow .15s}.cb-account:hover,.cb-account.is-current{border-color:#17a589;box-shadow:0 4px 16px #0f766e12;text-decoration:none;color:#233e4b}.cb-account.xray{background:#f1f8ff;border-color:#c9dff3}.cb-account-head{display:flex;gap:10px;align-items:center}.cb-account-head i{display:grid;place-items:center;width:34px;height:34px;border-radius:10px;background:#e6f5f0;color:#147c66}.cb-account-head b{font-size:13px}.cb-account-value{font-size:25px;font-weight:800;margin:14px 0 5px;font-variant-numeric:tabular-nums}.cb-account small{font-size:11px;color:#738792}.cb-panel{overflow:hidden;margin-bottom:18px}.cb-panel-head{display:flex;justify-content:space-between;align-items:center;padding:17px 20px;border-bottom:1px solid #e9eff2;gap:15px;flex-wrap:wrap}.cb-panel-head h3{font-size:15px;margin:0}.cb-filters{padding:17px 20px;display:flex;align-items:end;gap:12px;flex-wrap:wrap;background:#fbfcfd;border-bottom:1px solid #e9eff2}.cb-field{display:flex;flex-direction:column;gap:6px;min-width:130px}.cb-field label{font-size:12px;color:#5d7480}.cb-field input,.cb-field select,.cb-field textarea{border:1px solid #d4e0e6;border-radius:8px;background:#fff;padding:9px 11px;font:inherit;font-size:13px;min-height:40px;color:#203e4d}.cb-table-scroll{overflow-x:auto}.cb-table{width:100%;border-collapse:collapse;white-space:nowrap;font-size:12px}.cb-table th{padding:13px 16px;background:#f7f9fb;color:#657e8b;font-weight:500;text-align:right;border-bottom:1px solid #e4ebef}.cb-table td{padding:14px 16px;border-bottom:1px solid #edf1f4;vertical-align:top}.cb-table tbody tr:hover{background:#fbfdfd}.cb-table small{display:block;color:#7c8d98;font-size:11px;margin-top:4px}.cb-table .cb-reason{white-space:normal;min-width:180px;max-width:300px;line-height:1.7}.cb-table .cb-number{font-variant-numeric:tabular-nums;font-weight:700;direction:ltr;text-align:right}.cb-chip{display:inline-flex;align-items:center;gap:6px;padding:4px 8px;border-radius:7px;font-size:11px;background:#eaf7f1;color:#13775f}.cb-chip.out{background:#fff0ed;color:#b34d3c}.cb-empty{text-align:center;padding:55px 20px;color:#6c8795}.cb-empty i{display:block;font-size:32px;color:#b1c8d1;margin-bottom:12px}.cb-pagination{padding:14px 20px;display:flex;justify-content:space-between;align-items:center;gap:12px;font-size:12px;color:#667d89}.cb-alert{padding:13px 17px;border-radius:10px;margin-bottom:15px;font-size:13px;line-height:1.7}.cb-alert.error{background:#fff0ed;color:#a03c32}.cb-alert.success{background:#e9f7f0;color:#116b51}.cb-inline-action{border:0;background:transparent;color:#8a5a4b;cursor:pointer;font:inherit;font-size:11px;text-decoration:underline}.cb-dialog{max-width:470px;width:calc(100vw - 32px)}.cb-dialog .cb-field{margin-bottom:15px}.cb-dialog .dialog-body p{font-size:12px;line-height:1.8;color:#647b88}.cb-links{display:flex;gap:12px;align-items:center}.cb-date{direction:ltr;display:inline-block}@media(max-width:700px){.cb-hero{padding:22px;align-items:flex-start;flex-direction:column;gap:18px}.cb-hero-icon{display:none}.cb-metrics{grid-template-columns:repeat(2,minmax(0,1fr));gap:9px}.cb-metric{padding:13px}.cb-metric b{font-size:19px}.cb-accounts{grid-template-columns:repeat(2,minmax(0,1fr));gap:9px}.cb-account{padding:12px}.cb-account-value{font-size:22px}.cb-account-head b{font-size:12px}.cb-field{min-width:0;flex:1 1 135px}.cb-filters{padding:14px;gap:9px}.cb-note{font-size:11px}}@media print{.cb-buttons,.cb-accounts,.cb-filters,.cb-pagination,.cb-inline-action{display:none!important}.cb-hero{background:#fff;color:#000;border:1px solid #bbb;box-shadow:none}.cb-hero h2,.cb-balance,.cb-caption,.cb-eyebrow{color:#000}.cb-panel,.cb-metric{break-inside:avoid}.cb-table-scroll{overflow:visible}.cb-table{font-size:10px}.cb-hero-icon{display:none}}
</style>
<div class="cb-wrap">
    <?php if ($cashboxError): ?><div class="cb-alert error" role="alert"><?= cbEsc($cashboxError) ?></div><?php endif; ?>
    <?php if ($cashboxFlash): ?><div class="cb-alert success" role="status"><?= cbEsc($cashboxFlash) ?></div><?php endif; ?>
    <?php if ($cashboxStatement): $cbAccount=$cashboxStatement['account']; $cbSummary=$cashboxStatement['summary']; ?>
    <section class="cb-hero">
        <div>
            <div class="cb-eyebrow"><i class="fas fa-circle-check" aria-hidden="true"></i> نەقدی بەردەستی ئێستا</div>
            <h2><?= cbEsc(cbName($cbAccount)) ?></h2>
            <strong class="cb-balance" style="display:block;"><?= cbEsc(CashboxLedger::money($cbAccount['balance'])) ?> <small>دینار</small></strong>
            <div class="cb-caption">نەقدی وەسڵە پەسەندکراوەکان + دانان − گەڕاندنەوە − ڕاکێشان</div>
        </div>
        <div class="cb-buttons">
            <?php if ($cashboxCanMove): ?>
            <button type="button" class="cb-button primary" onclick="cbOpenMove('deposit')"><i class="fas fa-plus"></i> دانانی نەقد</button>
            <button type="button" class="cb-button out" onclick="cbOpenMove('withdrawal')"><i class="fas fa-arrow-up"></i> ڕاکێشانی نەقد</button>
            <?php endif; ?>
            <span class="cb-hero-icon"><i class="fas <?= $cbAccount['owner_type']==='xray' ? 'fa-x-ray' : 'fa-vault' ?>" aria-hidden="true"></i></span>
        </div>
    </section>
    <?php if (CashboxLedger::cents($cbAccount['balance'])<0): ?><div class="cb-alert error">سندوق کەمیی نەقدی هەیە. گەڕاندنەوە یان ڕاستکردنەوەی وەسڵ باڵانسەکەی خستووەتە ژێر سفر؛ ڕاکێشانی زیاتر ڕێگەپێدراو نییە.</div><?php endif; ?>
    <?php if ($cashboxAdmin): ?>
    <div class="cb-links cb-note"><a href="<?= cbEsc($cashboxBaseUrl) ?>">هەموو سندوقەکان و جوڵەکان</a><span>هەر سندوقێک هەڵبژێرە بۆ وردەکاری، دانان و ڕاکێشان.</span></div>
    <div class="cb-accounts">
        <?php foreach ($cashboxAccounts as $account): ?>
        <a class="cb-account <?= $account['owner_type']==='xray' ? 'xray' : '' ?> <?= $cashboxKey===$account['account_key'] ? 'is-current' : '' ?>" href="<?= cbEsc($cashboxBaseUrl . '?' . http_build_query(['box'=>$account['account_key'],'from'=>$cashboxFrom,'to'=>$cashboxTo])) ?>">
            <div class="cb-account-head"><i class="fas <?= $account['owner_type']==='xray' ? 'fa-x-ray' : ($account['owner_type']==='center' ? 'fa-building-columns' : 'fa-user-doctor') ?>"></i><b><?= cbEsc(cbName($account)) ?></b></div>
            <strong class="cb-account-value <?= CashboxLedger::cents($account['balance'])<0 ? 'cb-out' : '' ?>" style="display:block;"><?= cbEsc(CashboxLedger::money($account['balance'])) ?></strong>
            <small>دینار · <?= $account['last_movement'] ? cbEsc(substr($account['last_movement'],0,10)) : 'تا ئێستا جوڵە نییە' ?></small>
        </a>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
    <div class="cb-metrics">
        <div class="cb-metric"><span>باڵانس لە سەرەتای ماوە</span><b><?= cbEsc(CashboxLedger::money($cashboxStatement['opening'])) ?></b></div>
        <div class="cb-metric"><span><i class="fas fa-arrow-down"></i> کۆی هاتن لە ماوەکە</span><b class="cb-in"><?= cbEsc(CashboxLedger::money($cbSummary['incoming'])) ?></b></div>
        <div class="cb-metric"><span><i class="fas fa-arrow-up"></i> کۆی چوون لە ماوەکە</span><b class="cb-out"><?= cbEsc(CashboxLedger::money($cbSummary['outgoing'])) ?></b></div>
        <div class="cb-metric"><span>باڵانس لە کۆتایی ماوە</span><b><?= cbEsc(CashboxLedger::money($cashboxStatement['closing'])) ?></b></div>
    </div>
    <p class="cb-note">دەستپێکی هەژمارکردن: <b>١/١٠/٢٠٢٦</b>. تەنها نەقدی پەسەندکراو هەژمار دەکرێت. دانان و ڕاکێشانی دەستی جوڵەی نەقدن و داهات و قەرزی نەخۆش ناگۆڕن. <?= $cbAccount['owner_type']==='doctor' ? 'پارەی ئێکسڕەی لە سندوقی جیاوازی ئێکسڕەیە.' : '' ?></p>
    <section class="cb-panel">
        <div class="cb-panel-head"><h3><i class="fas fa-clock-rotate-left"></i> مێژووی جوڵەکان <span class="text-muted">(<?= (int)$cashboxStatement['count'] ?>)</span></h3><span class="cb-note">بەروار، وەسڵ، هۆکار و تۆمارکەر</span></div>
        <form method="get" class="cb-filters">
            <?php if ($cashboxAdmin): ?><input type="hidden" name="box" value="<?= cbEsc($cashboxKey) ?>"><?php endif; ?>
            <div class="cb-field"><label for="cb-from">لە بەروار</label><input id="cb-from" name="from" type="date" value="<?= cbEsc($cashboxFrom) ?>" required></div>
            <div class="cb-field"><label for="cb-to">بۆ بەروار</label><input id="cb-to" name="to" type="date" value="<?= cbEsc($cashboxTo) ?>" required></div>
            <div class="cb-field"><label for="cb-direction">جۆری جوڵە</label><select id="cb-direction" name="direction"><option value="">هەموو جوڵەکان</option value="in" <?= $cashboxDirection==='in' ? 'selected' : '' ?>>هاتنی نەقد</option><option value="out" <?= $cashboxDirection==='out' ? 'selected' : '' ?>>چوونی نەقد</option></select></div>
            <button class="cb-button" type="submit"><i class="fas fa-filter"></i> پیشاندان</button>
        </form>
        <?php if (!$cashboxStatement['entries']): ?><div class="cb-empty"><i class="fas fa-receipt"></i>لەم ماوەیەدا هیچ جوڵەیەک تۆمار نەکراوە.</div><?php else: ?>
        <div class="cb-table-scroll"><table class="cb-table"><thead><tr><th>ژمارە / بەروار</th><?php if ($cashboxKey==='*'): ?><th>سندوق</th><?php endif; ?><th>جۆر / وەسڵ</th><th>هاتن</th><th>چوون</th><th>باڵانسی سندوق دوای جوڵە</th><th>هۆکار</th><th>تۆمارکەر</th></tr></thead><tbody>
        <?php foreach ($cashboxStatement['entries'] as $entry): $cbCents=CashboxLedger::cents($entry['amount']); ?>
        <tr>
            <td><b>#<?= (int)$entry['id'] ?></b><small class="cb-date"><?= cbEsc($entry['occurred_at']) ?></small></td>
            <?php if ($cashboxKey==='*'): ?><td><?= cbEsc($entry['account_key']==='amanat' ? 'قاسەی ئامانەت' : ($entry['account_key']==='xray' ? 'ئێکسڕەی' : ($entry['account_key']===CashboxLedger::CENTER ? 'قاسەی لێزان' : 'د. ' . ($entry['owner_name'] ?? '—')))) ?></td><?php endif; ?>
            <td><span class="cb-chip <?= $cbCents<0 ? 'out' : '' ?>"><?= cbEsc($cashboxKinds[$entry['entry_kind']] ?? $entry['entry_kind']) ?></span>
                <?php if ($entry['reference_no']): ?><small><?php if ($entry['case_id'] && $entry['source_type']==='invoice'): ?><a href="<?= APP_URL ?>/cases/view.php?id=<?= (int)$entry['case_id'] ?>"><?= cbEsc($entry['reference_no']) ?></a><?php else: ?><?= cbEsc($entry['reference_no']) ?><?php endif; ?></small><?php endif; ?>
                <?php if ($entry['reversed_by_entry']): ?><small>ڕاستکراوەتەوە بە #<?= (int)$entry['reversed_by_entry'] ?></small><?php endif; ?>
            </td>
            <td class="cb-number cb-in"><?= $cbCents>0 ? cbEsc(CashboxLedger::money($entry['amount'])) : '—' ?></td>
            <td class="cb-number cb-out"><?= $cbCents<0 ? cbEsc(CashboxLedger::money(CashboxLedger::decimal(-$cbCents))) : '—' ?></td>
            <td class="cb-number"><?= cbEsc(CashboxLedger::money($entry['running_balance'])) ?><small>دینار</small></td>
            <td class="cb-reason"><?= cbEsc($entry['reason']) ?></td>
            <td><?= cbEsc($entry['actor_name'] ?? 'تۆماری سیستەم') ?>
                <?php if ($cashboxAdmin && in_array($entry['entry_kind'],['deposit','withdrawal'],true) && !$entry['reversed_by_entry']): ?><small><button type="button" class="cb-inline-action" onclick="cbOpenReverse(<?= (int)$entry['id'] ?>,<?= cbEsc(json_encode($entry['account_key'])) ?>)">ڕاستکردنەوە</button></small><?php endif; ?>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody></table></div>
        <?php endif; ?>
        <div class="cb-pagination"><span>پەڕەی <?= (int)$cashboxStatement['page'] ?> لە <?= (int)$cashboxStatement['pages'] ?></span><div class="cb-links">
            <?php foreach ([-1=>'پێشوو',1=>'دواتر'] as $delta=>$label): $p=$cashboxStatement['page']+$delta; if ($p<1 || $p>$cashboxStatement['pages']) continue; ?><a href="<?= cbEsc($cashboxBaseUrl . '?' . http_build_query(['box'=>$cashboxKey,'from'=>$cashboxFrom,'to'=>$cashboxTo,'direction'=>$cashboxDirection,'page'=>$p])) ?>"><?= cbEsc($label) ?></a><?php endforeach; ?>
        </div></div>
    </section>
    <?php endif; ?>
</div>
<?php if ($cashboxCanMove || $cashboxAdmin): ?>
<div class="dialog-overlay" id="cashboxMoveDialog"><div class="dialog cb-dialog" role="dialog" aria-modal="true" aria-labelledby="cb-move-title">
    <div class="dialog-header"><h3 class="dialog-title" id="cb-move-title">دانانی نەقد</h3><button type="button" class="dialog-close" onclick="closeDialog('cashboxMoveDialog')" aria-label="داخستن"><i class="fas fa-times"></i></button></div>
    <form method="post" id="cb-move-form"><input type="hidden" name="csrf_token" value="<?= cbEsc(Csrf::token()) ?>"><input type="hidden" name="account_key" value="<?= cbEsc($cashboxKey) ?>"><input type="hidden" name="action" value="deposit"><input type="hidden" name="entry_id" value=""><input type="hidden" name="request_key" value="<?= bin2hex(random_bytes(24)) ?>">
        <div class="dialog-body">
            <p id="cb-move-help">بڕ و هۆکاری جوڵەکە بنووسە. جوڵەکە بە ناوی تۆ و کاتی ئێستا تۆمار دەکرێت.</p>
            <div class="cb-field" id="cb-amount-field"><label for="cb-amount">بڕی نەقد — دینار</label><input id="cb-amount" name="amount" type="number" step="0.01" min="0.01" max="9999999999999.99" required inputmode="decimal"></div>
            <div class="cb-field"><label for="cb-reason">هۆکار</label><textarea id="cb-reason" name="reason" rows="3" minlength="3" maxlength="600" required placeholder="بۆ نموونە: ڕادەستکردنی نەقد بە ئەدمین"></textarea></div>
        </div>
        <div class="dialog-footer"><button type="button" class="cb-button" onclick="closeDialog('cashboxMoveDialog')">پاشگەزبوونەوە</button><button type="submit" class="cb-button primary">تۆمارکردنی جوڵە</button></div>
    </form>
</div></div>
<script>
function cbOpenMove(kind){const form=document.getElementById('cb-move-form');form.reset();form.elements.action.value=kind;form.elements.account_key.value=<?= json_encode($cashboxKey) ?>;form.elements.entry_id.value='';document.getElementById('cb-move-title').textContent=kind==='withdrawal'?'ڕاکێشانی نەقد':'دانانی نەقد';document.getElementById('cb-amount-field').style.display='flex';form.elements.amount.required=true;openDialog('cashboxMoveDialog');setTimeout(()=>form.elements.amount.focus(),50);}
function cbOpenReverse(id,key){const form=document.getElementById('cb-move-form');form.reset();form.elements.action.value='reverse';form.elements.entry_id.value=id;form.elements.account_key.value=key;document.getElementById('cb-move-title').textContent='ڕاستکردنەوەی جوڵە #'+id;document.getElementById('cb-amount-field').style.display='none';form.elements.amount.required=false;openDialog('cashboxMoveDialog');setTimeout(()=>form.elements.reason.focus(),50);}
document.getElementById('cb-move-form').addEventListener('submit',function(){this.querySelector('[type="submit"]').disabled=true;});
</script>
<?php endif; ?>
<?php require __DIR__ . '/footer.php'; ?>
