<?php
/**
 * admin/settings.php?tab=clinic — «ناسنامە و ڕەنگ»
 * The centre's names, contacts, logo and colours on one page
 * (classes/Branding.php). Included by admin/settings.php only.
 */

if (!defined('APP_ROOT')) {
    http_response_code(403);
    exit;
}

$bv = static fn(string $key): string => htmlspecialchars(Branding::get($key), ENT_QUOTES, 'UTF-8');
$bk = static fn(string $key): string => 'brand[' . $key . ']';
$brandPrimary = Branding::primaryColor();
$brandPrint   = Branding::printColor();
$brandSwatches = [
    '#0078D4' => 'شینی لێزان', '#0F766E' => 'سەوزی دەریایی', '#107C10' => 'سەوز',
    '#5B4B8A' => 'مۆر', '#C239B3' => 'پەمەیی', '#CA5010' => 'پرتەقاڵی',
    '#A4262C' => 'سووری تۆخ', '#1F3B63' => 'شینی تۆخ', '#3B3A39' => 'خۆڵەمێشی',
];
?>
<style>
.brand-intro { color: var(--text-secondary); font-size: 13px; line-height: 1.9; margin: -4px 0 16px; }
.brand-layout { display: grid; grid-template-columns: minmax(0, 1fr) 360px; gap: 18px; align-items: start; }
@media (max-width: 1100px) { .brand-layout { grid-template-columns: 1fr; } }
.brand-section { border: 1px solid #e8e8e8; border-radius: 10px; padding: 14px 16px 4px; margin-bottom: 14px; background: #fff; }
.brand-section h5 { font-size: 14px; font-weight: 700; margin: 0 0 12px; display: flex; align-items: center; gap: 8px; color: var(--text-primary); }
.brand-section h5 i { color: var(--win-accent); width: 18px; text-align: center; }
.brand-section .form-hint { font-size: 11.5px; color: var(--text-tertiary); margin-top: 4px; line-height: 1.7; }
.brand-ltr { direction: ltr; text-align: left; }
.brand-logo-row { display: flex; gap: 16px; align-items: center; flex-wrap: wrap; }
.brand-logo-box { width: 96px; height: 96px; border: 1px dashed #cfcfcf; border-radius: 10px; display: flex; align-items: center; justify-content: center; background: #fafafa; flex: 0 0 auto; }
.brand-logo-box img { max-width: 84px; max-height: 84px; object-fit: contain; }
.brand-logo-meta { flex: 1 1 220px; min-width: 0; }
.brand-logo-tag { display: inline-block; font-size: 11px; padding: 2px 8px; border-radius: 10px; background: #f0f0f0; color: #555; margin-bottom: 8px; }
.brand-color-row { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
.brand-color-row input[type=color] { width: 46px; height: 36px; padding: 2px; border: 1px solid #d0d0d0; border-radius: 6px; background: #fff; cursor: pointer; }
.brand-color-row .form-control { width: 110px; font-family: monospace; }
.brand-swatches { display: flex; gap: 6px; flex-wrap: wrap; margin-top: 10px; }
.brand-swatch { width: 26px; height: 26px; border-radius: 50%; border: 2px solid #fff; box-shadow: 0 0 0 1px #d0d0d0; cursor: pointer; padding: 0; }
.brand-swatch.is-on { box-shadow: 0 0 0 2px var(--text-primary); }
.brand-warn { display: none; margin-top: 8px; font-size: 12px; color: #9D5D00; background: #FFF4CE; border-radius: 6px; padding: 6px 10px; }
.brand-warn.show { display: block; }
.brand-actions { display: flex; gap: 10px; flex-wrap: wrap; align-items: center; margin: 4px 0 18px; }

/* Preview column */
.brand-preview { position: sticky; top: 12px; }
.brand-preview .brand-section { padding-bottom: 14px; }
.brand-preview-note { font-size: 11.5px; color: var(--text-tertiary); line-height: 1.7; margin: 8px 0 0; }
.brand-app { display: flex; border: 1px solid #e5e5e5; border-radius: 8px; overflow: hidden; font-size: 12px; min-height: 170px; }
.brand-app-side { width: 128px; background: #f6f6f6; border-left: 1px solid #e5e5e5; padding: 8px 6px; flex: 0 0 auto; }
.brand-app-logo { display: flex; align-items: center; gap: 6px; padding: 4px 4px 10px; font-weight: 700; font-size: 10.5px; direction: ltr; overflow: hidden; white-space: nowrap; }
.brand-app-logo img { width: 22px; height: 22px; object-fit: contain; flex: 0 0 auto; }
.brand-app-nav { padding: 6px 8px; border-radius: 5px; margin-bottom: 3px; color: #444; position: relative; }
.brand-app-nav i { width: 14px; margin-left: 4px; color: #888; }
.brand-app-nav.on { background: rgba(var(--win-accent-rgb), 0.1); color: var(--win-accent); font-weight: 600; }
.brand-app-nav.on i { color: var(--win-accent); }
.brand-app-nav.on::before { content: ''; position: absolute; right: 0; top: 6px; bottom: 6px; width: 3px; border-radius: 2px; background: var(--win-accent); }
.brand-app-main { flex: 1; padding: 10px 12px; min-width: 0; }
.brand-app-title { font-weight: 700; margin-bottom: 8px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.brand-app-tabs { display: flex; gap: 12px; border-bottom: 1px solid #eee; margin-bottom: 10px; }
.brand-app-tabs span { padding: 4px 0; color: #666; }
.brand-app-tabs span.on { color: var(--win-accent); border-bottom: 2px solid var(--win-accent); font-weight: 600; }
.brand-app-banner { background: linear-gradient(135deg, var(--win-accent), var(--win-accent-deep)); color: #fff; border-radius: 6px; padding: 8px 10px; margin-bottom: 10px; font-weight: 600; }
.brand-app-row { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }
.brand-app-row .btn { font-size: 11.5px; padding: 4px 10px; }
.brand-app-row a { color: var(--text-accent); font-weight: 600; }
.brand-rc { border: 1px solid #e5e5e5; border-radius: 8px; padding: 12px; background: #fff; }
.brand-rc-head { display: flex; align-items: center; gap: 8px; padding-bottom: 6px; border-bottom: 2px solid <?= $brandPrint ?>; }
.brand-rc-head img { width: 64px; height: auto; max-height: 64px; object-fit: contain; flex: 0 0 auto; }
.brand-rc-text { flex: 1; text-align: right; line-height: 1.6; min-width: 0; }
.brand-rc-name { font-size: 15px; font-weight: 700; color: #1b2b3a; }
.brand-rc-contact { font-size: 11px; color: #3f4a56; direction: ltr; text-align: right; }
.brand-rc-addr { font-size: 10.5px; color: #4a5560; }
.brand-rc .ico { color: <?= $brandPrint ?>; font-weight: 700; }
.brand-rc-lines { margin-top: 10px; }
.brand-rc-lines div { height: 7px; border-radius: 4px; background: #f0f2f4; margin-bottom: 6px; }
.brand-rc-lines div:nth-child(2) { width: 70%; }
.brand-rc-total { margin-top: 8px; display: inline-block; padding: 3px 10px; border-radius: 4px; color: #fff; font-size: 11px; background: <?= $brandPrint ?>; }
</style>

<div class="panel-body">
    <h4 class="mb-3">ناسنامە و ڕەنگی سەنتەر</h4>
    <p class="brand-intro">
        هەموو زانیارییەکانی سەنتەر لێرە دەگۆڕدرێن: ناو، ژمارە، ناونیشان، لۆگۆ و ڕەنگ.
        پەڕەکانی سیستەم، پسوولە و قەبز و وەسڵەکان، PDF و ڕاپۆرتەکان هەموویان ئەم زانیارییانە بەکاردەهێنن.
        بنەڕەت زانیارییەکانی <?= htmlspecialchars(Branding::DEFAULTS[Branding::NAME]) ?>ە.
    </p>

    <div class="brand-layout">
        <div>
            <form method="POST" action="" enctype="multipart/form-data" id="brandForm">
                <input type="hidden" name="action" value="save_branding">

                <!-- Names -->
                <div class="brand-section">
                    <h5><i class="fas fa-signature"></i> ناوی سەنتەر</h5>
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label required" for="bName">ناوی سەنتەر (کوردی)</label>
                            <input type="text" id="bName" name="<?= $bk(Branding::NAME) ?>" class="form-control" maxlength="120" required
                                   value="<?= $bv(Branding::NAME) ?>">
                            <div class="form-hint">ناونیشانی پەڕەکان، پەڕەی چوونەژوورەوە و شریتی سەرەوە</div>
                        </div>
                        <div class="form-group">
                            <label class="form-label required" for="bNameEn">ناوی ئینگلیزی</label>
                            <input type="text" id="bNameEn" name="<?= $bk(Branding::NAME_EN) ?>" class="form-control brand-ltr" maxlength="120" required
                                   value="<?= $bv(Branding::NAME_EN) ?>">
                            <div class="form-hint">لیستی لاتەنیشت، ئەپی مۆبایل و وەسڵی ئێکسڕە</div>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label required" for="bShort">ناوی کورت</label>
                            <input type="text" id="bShort" name="<?= $bk(Branding::SHORT_NAME) ?>" class="form-control" maxlength="60" required
                                   value="<?= $bv(Branding::SHORT_NAME) ?>">
                            <div class="form-hint">لە ژمێریاریدا: «قاسەی <b class="js-short"><?= $bv(Branding::SHORT_NAME) ?></b>» و «پشکی <b class="js-short"><?= $bv(Branding::SHORT_NAME) ?></b>»</div>
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="bPrint">ناوی سەر وەسڵ</label>
                            <input type="text" id="bPrint" name="<?= $bk(Branding::PRINT_NAME) ?>" class="form-control" maxlength="120"
                                   value="<?= $bv(Branding::PRINT_NAME) ?>" placeholder="<?= $bv(Branding::NAME) ?>">
                            <div class="form-hint">سەری پسوولە، قەبز، وەسڵی ئێکسڕە، پلانی چارەسەر و ڕاپۆرتەکان. بەتاڵ بێت، ناوی سەنتەر بەکاردێت.</div>
                        </div>
                    </div>
                </div>

                <!-- Contacts -->
                <div class="brand-section">
                    <h5><i class="fas fa-phone"></i> پەیوەندی و ناونیشان</h5>
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label required" for="bPhone1">ژمارەی ١</label>
                            <input type="text" id="bPhone1" name="<?= $bk(Branding::PHONE_1) ?>" class="form-control brand-ltr" maxlength="40" required
                                   value="<?= $bv(Branding::PHONE_1) ?>">
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="bPhone2">ژمارەی ٢</label>
                            <input type="text" id="bPhone2" name="<?= $bk(Branding::PHONE_2) ?>" class="form-control brand-ltr" maxlength="40"
                                   value="<?= $bv(Branding::PHONE_2) ?>">
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="bPhone3">ژمارەی ٣</label>
                            <input type="text" id="bPhone3" name="<?= $bk(Branding::PHONE_3) ?>" class="form-control brand-ltr" maxlength="40"
                                   value="<?= $bv(Branding::PHONE_3) ?>">
                            <div class="form-hint">تەنها لە وەسڵی ئێکسڕەدا</div>
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="form-label required" for="bAddress">ناونیشان</label>
                        <textarea id="bAddress" name="<?= $bk(Branding::ADDRESS) ?>" class="form-control" rows="2" maxlength="300" required><?= $bv(Branding::ADDRESS) ?></textarea>
                        <div class="form-hint">لەسەر هەموو وەسڵ و ڕاپۆرتەکان (یەک دێڕ)</div>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="bEmail">ئیمەیڵ</label>
                        <input type="email" id="bEmail" name="<?= $bk(Branding::EMAIL) ?>" class="form-control brand-ltr" maxlength="120"
                               value="<?= $bv(Branding::EMAIL) ?>">
                    </div>
                </div>

                <!-- Social -->
                <div class="brand-section">
                    <h5><i class="fas fa-share-alt"></i> سۆشیاڵ میدیا</h5>
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label" for="bFb">📘 فەیسبووک</label>
                            <input type="text" id="bFb" name="<?= $bk(Branding::FACEBOOK) ?>" class="form-control brand-ltr" maxlength="120"
                                   value="<?= $bv(Branding::FACEBOOK) ?>">
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="bIg">📷 ئینستاگرام</label>
                            <input type="text" id="bIg" name="<?= $bk(Branding::INSTAGRAM) ?>" class="form-control brand-ltr" maxlength="120"
                                   value="<?= $bv(Branding::INSTAGRAM) ?>">
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label" for="bOther">👤 ئەکاونتی تر</label>
                            <input type="text" id="bOther" name="<?= $bk(Branding::SOCIAL_3) ?>" class="form-control brand-ltr" maxlength="120"
                                   value="<?= $bv(Branding::SOCIAL_3) ?>">
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="bWeb">🌐 ماڵپەڕ</label>
                            <input type="text" id="bWeb" name="<?= $bk(Branding::WEBSITE) ?>" class="form-control brand-ltr" maxlength="160"
                                   value="<?= $bv(Branding::WEBSITE) ?>">
                        </div>
                    </div>
                    <div class="form-hint" style="margin:-6px 0 12px;">لە خوارەوەی وەسڵی ئێکسڕە دەردەکەون. خانەی بەتاڵ نیشان نادرێت.</div>
                </div>

                <!-- Logo -->
                <div class="brand-section">
                    <h5><i class="fas fa-image"></i> لۆگۆ</h5>
                    <div class="brand-logo-row">
                        <div class="brand-logo-box"><img id="bLogoImg" src="<?= htmlspecialchars(Branding::logoUrl()) ?>" alt=""></div>
                        <div class="brand-logo-meta">
                            <span class="brand-logo-tag"><?= Branding::hasCustomLogo() ? 'لۆگۆی بارکراو' : 'لۆگۆی بنەڕەت' ?></span>
                            <input type="file" id="bLogoFile" name="brand_logo" class="form-control" accept=".png,.jpg,.jpeg,image/png,image/jpeg">
                            <div class="form-hint">PNG یان JPG، کەمتر لە ٣ مێگابایت. وێنەی چوارگۆشە بە پاشبنەمای سپی یان ڕوون باشترە.
                                لە لیستی لاتەنیشت، چوونەژوورەوە، ئایکۆنی وێبگەڕ و هەموو وەسڵ و PDFەکان بەکاردێت.</div>
                            <?php if (Branding::hasCustomLogo()): ?>
                            <label class="form-check" style="margin-top:8px;">
                                <input type="checkbox" name="brand_logo_reset" value="1" id="bLogoReset">
                                <span>گەڕانەوە بۆ لۆگۆی بنەڕەت</span>
                            </label>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div style="height:12px"></div>
                </div>

                <!-- Colours -->
                <div class="brand-section">
                    <h5><i class="fas fa-palette"></i> ڕەنگەکان</h5>
                    <div class="form-group">
                        <label class="form-label" for="bPrimary">ڕەنگی سەرەکی سیستەم</label>
                        <div class="brand-color-row">
                            <input type="color" id="bPrimary" name="<?= $bk(Branding::PRIMARY) ?>" value="<?= htmlspecialchars(strtolower($brandPrimary)) ?>">
                            <input type="text" class="form-control brand-ltr js-hex" data-for="bPrimary" value="<?= htmlspecialchars($brandPrimary) ?>" maxlength="7" aria-label="hex">
                        </div>
                        <div class="brand-swatches">
                            <?php foreach ($brandSwatches as $hex => $label): ?>
                            <button type="button" class="brand-swatch<?= strcasecmp($hex, $brandPrimary) === 0 ? ' is-on' : '' ?>"
                                    data-color="<?= $hex ?>" title="<?= htmlspecialchars($label) ?>" style="background:<?= $hex ?>"></button>
                            <?php endforeach; ?>
                        </div>
                        <div class="form-hint">دوگمەکان، لیستی لاتەنیشت، تابەکان، بەستەرەکان و ڕەنگە شینەکانی هەموو پەڕەکان</div>
                        <div class="brand-warn" id="bPrimaryWarn">ئەم ڕەنگە زۆر کاڵە — نووسینی سپی لەسەری بە باشی ناخوێندرێتەوە. ڕەنگێکی تۆختر هەڵبژێرە.</div>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="bPrintColor">ڕەنگی وەسڵ</label>
                        <div class="brand-color-row">
                            <input type="color" id="bPrintColor" name="<?= $bk(Branding::PRINT_COLOR) ?>" value="<?= htmlspecialchars(strtolower($brandPrint)) ?>">
                            <input type="text" class="form-control brand-ltr js-hex" data-for="bPrintColor" value="<?= htmlspecialchars($brandPrint) ?>" maxlength="7" aria-label="hex">
                        </div>
                        <div class="form-hint">هێڵ و نیشانەکانی سەر پسوولە، قەبز، وەسڵی کاری ئەرک و وەسڵی پێشەکی</div>
                    </div>
                </div>

                <div class="brand-actions">
                    <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> پاشەکەوتکردن</button>
                </div>
            </form>

            <form method="POST" action="" onsubmit="return confirm('هەموو ناو، ژمارە، لۆگۆ و ڕەنگەکان دەگەڕێنەوە بۆ زانیارییەکانی <?= htmlspecialchars(Branding::DEFAULTS[Branding::NAME], ENT_QUOTES) ?>. دڵنیایت؟')">
                <input type="hidden" name="action" value="reset_branding">
                <button type="submit" class="btn btn-secondary"><i class="fas fa-undo"></i> گەڕانەوە بۆ بنەڕەت (<?= htmlspecialchars(Branding::DEFAULTS[Branding::NAME]) ?>)</button>
            </form>
        </div>

        <!-- Live preview -->
        <div class="brand-preview">
            <div class="brand-section">
                <h5><i class="fas fa-desktop"></i> پێشبینینی سیستەم</h5>
                <div class="brand-app">
                    <div class="brand-app-side">
                        <div class="brand-app-logo"><img class="js-logo" src="<?= htmlspecialchars(Branding::logoUrl()) ?>" alt=""><span class="js-name-en"><?= $bv(Branding::NAME_EN) ?></span></div>
                        <div class="brand-app-nav on"><i class="fas fa-users"></i> نەخۆشەکان</div>
                        <div class="brand-app-nav"><i class="fas fa-calendar-alt"></i> مەوعیدەکان</div>
                        <div class="brand-app-nav"><i class="fas fa-chair"></i> چاوەڕوانی</div>
                    </div>
                    <div class="brand-app-main">
                        <div class="brand-app-title"><span class="js-name"><?= $bv(Branding::NAME) ?></span> - داشبۆرد</div>
                        <div class="brand-app-tabs"><span class="on">گشتی</span><span>ڕاپۆرت</span><span>دارایی</span></div>
                        <div class="brand-app-banner">نەخۆشی ئەمڕۆ: ٢٤</div>
                        <div class="brand-app-row">
                            <button type="button" class="btn btn-primary"><i class="fas fa-plus"></i> نوێ</button>
                            <a href="javascript:void(0)">بینینی هەموو</a>
                        </div>
                    </div>
                </div>
                <p class="brand-preview-note">لە کاتی هەڵبژاردنی ڕەنگ، ئەم پەڕەیە ڕاستەوخۆ دەگۆڕێت بۆ پێشبینین. تا پاشەکەوت نەکەیت هیچ شتێک ناگۆڕێت.</p>
            </div>

            <div class="brand-section">
                <h5><i class="fas fa-receipt"></i> پێشبینینی وەسڵ</h5>
                <div class="brand-rc" id="bReceipt">
                    <div class="brand-rc-head">
                        <img class="js-logo" src="<?= htmlspecialchars(Branding::logoUrl()) ?>" alt="">
                        <div class="brand-rc-text">
                            <div class="brand-rc-name js-print-name"><?= htmlspecialchars(Branding::printName()) ?></div>
                            <div class="brand-rc-contact"><span class="ico">✆</span> <span class="js-phones"><?= htmlspecialchars(implode(' - ', array_filter([Branding::phone(1), Branding::phone(2)], 'strlen'))) ?></span></div>
                            <div class="brand-rc-addr"><span class="ico">◉</span> <span class="js-address"><?= $bv(Branding::ADDRESS) ?></span></div>
                        </div>
                    </div>
                    <div class="brand-rc-lines"><div></div><div></div><div></div></div>
                    <span class="brand-rc-total">کۆی گشتی</span>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
(function () {
    var DEFAULT_PRIMARY = <?= json_encode(Branding::DEFAULTS[Branding::PRIMARY]) ?>;
    var DEFAULT_THEME   = <?= json_encode(Branding::DEFAULT_THEME) ?>;
    var CURRENT_LOGO    = <?= json_encode(Branding::logoUrl()) ?>;
    var DEFAULTS        = <?= json_encode([
        'name' => Branding::DEFAULTS[Branding::NAME], 'nameEn' => Branding::DEFAULTS[Branding::NAME_EN],
        'short' => Branding::DEFAULTS[Branding::SHORT_NAME], 'phone1' => Branding::DEFAULTS[Branding::PHONE_1],
        'address' => Branding::DEFAULTS[Branding::ADDRESS],
    ], JSON_UNESCAPED_UNICODE) ?>;
    var root = document.documentElement;
    var $ = function (id) { return document.getElementById(id); };
    var all = function (sel) { return Array.prototype.slice.call(document.querySelectorAll(sel)); };

    // Same shade rules as Branding::themeVars() (PHP), for the live preview.
    function rgb(hex) { hex = hex.replace('#', ''); return [0, 2, 4].map(function (i) { return parseInt(hex.substr(i, 2), 16); }); }
    function toHex(c) { return '#' + c.map(function (v) { v = Math.max(0, Math.min(255, Math.round(v))); return (v < 16 ? '0' : '') + v.toString(16).toUpperCase(); }).join(''); }
    function darken(hex, f) { return toHex(rgb(hex).map(function (v) { return v * f; })); }
    function mixWhite(hex, a) { return toHex(rgb(hex).map(function (v) { return v + (255 - v) * a; })); }
    function isHex(v) { return /^#[0-9a-f]{6}$/i.test(v); }
    function isLight(hex) { var c = rgb(hex); return (0.299 * c[0] + 0.587 * c[1] + 0.114 * c[2]) > 186; }
    function themeVars(hex) {
        if (hex.toUpperCase() === DEFAULT_PRIMARY.toUpperCase()) return DEFAULT_THEME;
        var c = rgb(hex);
        return {
            '--win-accent': hex.toUpperCase(), '--win-accent-hover': mixWhite(hex, 0.10),
            '--win-accent-pressed': darken(hex, 0.89), '--win-accent-dark': darken(hex, 0.75),
            '--win-accent-deep': darken(hex, 0.83), '--win-accent-light': mixWhite(hex, 0.45),
            '--text-accent': darken(hex, 0.88), '--win-accent-rgb': c.join(', ')
        };
    }

    function applyPrimary(hex) {
        var vars = themeVars(hex);
        Object.keys(vars).forEach(function (k) { root.style.setProperty(k, vars[k]); });
        var meta = document.querySelector('meta[name="theme-color"]');
        if (meta) meta.setAttribute('content', hex);
        $('bPrimaryWarn').classList.toggle('show', isLight(hex));
        all('.brand-swatch').forEach(function (s) { s.classList.toggle('is-on', s.dataset.color.toUpperCase() === hex.toUpperCase()); });
    }
    function applyPrint(hex) {
        all('#bReceipt .ico').forEach(function (el) { el.style.color = hex; });
        document.querySelector('#bReceipt .brand-rc-head').style.borderBottomColor = hex;
        document.querySelector('#bReceipt .brand-rc-total').style.background = hex;
    }
    var colorHandlers = { bPrimary: applyPrimary, bPrintColor: applyPrint };

    Object.keys(colorHandlers).forEach(function (id) {
        var picker = $(id), text = document.querySelector('.js-hex[data-for="' + id + '"]');
        picker.addEventListener('input', function () { text.value = picker.value.toUpperCase(); colorHandlers[id](picker.value); });
        text.addEventListener('input', function () {
            var v = text.value.trim();
            if (v.charAt(0) !== '#') v = '#' + v;
            if (isHex(v)) { picker.value = v.toLowerCase(); colorHandlers[id](v); }
        });
    });
    all('.brand-swatch').forEach(function (s) {
        s.addEventListener('click', function () {
            $('bPrimary').value = s.dataset.color.toLowerCase();
            $('bPrimary').dispatchEvent(new Event('input'));
        });
    });
    $('bPrimaryWarn').classList.toggle('show', isLight($('bPrimary').value));

    // Text fields → preview (required ones show the default while empty).
    function bind(id, sel, fallback, transform) {
        var input = $(id);
        var update = function () {
            var v = input.value.trim() || (typeof fallback === 'function' ? fallback() : fallback);
            all(sel).forEach(function (el) { el.textContent = transform ? transform(v) : v; });
        };
        input.addEventListener('input', update);
        return update;
    }
    var nameValue = function () { return $('bName').value.trim() || DEFAULTS.name; };
    var updatePrintName = bind('bPrint', '.js-print-name', nameValue);
    bind('bName', '.js-name', DEFAULTS.name);
    $('bName').addEventListener('input', function () { $('bPrint').placeholder = nameValue(); updatePrintName(); });
    bind('bNameEn', '.js-name-en', DEFAULTS.nameEn);
    bind('bShort', '.js-short', DEFAULTS.short);
    bind('bAddress', '.js-address', DEFAULTS.address);
    function updatePhones() {
        var list = [$('bPhone1').value.trim() || DEFAULTS.phone1, $('bPhone2').value.trim()].filter(Boolean);
        all('.js-phones').forEach(function (el) { el.textContent = list.join(' - '); });
    }
    $('bPhone1').addEventListener('input', updatePhones);
    $('bPhone2').addEventListener('input', updatePhones);

    // Logo → preview before upload.
    $('bLogoFile').addEventListener('change', function () {
        var file = this.files && this.files[0];
        if (!file) return;
        var reader = new FileReader();
        reader.onload = function (e) {
            $('bLogoImg').src = e.target.result;
            all('.js-logo').forEach(function (img) { img.src = e.target.result; });
        };
        reader.readAsDataURL(file);
        if ($('bLogoReset')) $('bLogoReset').checked = false;
    });
    if ($('bLogoReset')) {
        $('bLogoReset').addEventListener('change', function () {
            var src = this.checked ? <?= json_encode(APP_URL . '/' . Branding::DEFAULT_LOGO) ?> : CURRENT_LOGO;
            $('bLogoImg').src = src;
            all('.js-logo').forEach(function (img) { img.src = src; });
        });
    }
})();
</script>
