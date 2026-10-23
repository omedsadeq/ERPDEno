<?php
/**
 * <head> tags that carry the centre's identity: PWA manifest, browser theme
 * colour, home-screen title and icons. Shared by includes/header.php and
 * login.php. The accent colour itself is Branding::themeStyleTag(), printed
 * after the stylesheets.
 */

$brandIcon = static fn(string $file): string => APP_URL . '/assets/images/icons/' . $file;
if (Branding::hasCustomLogo()) {
    // An uploaded logo replaces Lezan's icon set; browsers scale it.
    $brandTouchIcon = $brandIcon32 = $brandIcon16 = Branding::logoUrl();
} else {
    $brandTouchIcon = $brandIcon('apple-touch-icon.png');
    $brandIcon32    = $brandIcon('favicon-32.png');
    $brandIcon16    = $brandIcon('favicon-16.png');
}
$brandIconType = Branding::hasCustomLogo() ? Branding::logoMime() : 'image/png';
?>
    <link rel="manifest" href="<?= APP_URL ?>/manifest.php">
    <meta name="theme-color" content="<?= htmlspecialchars(Branding::primaryColor()) ?>">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <meta name="apple-mobile-web-app-title" content="<?= htmlspecialchars(Branding::nameEn()) ?>">
    <link rel="apple-touch-icon" href="<?= htmlspecialchars($brandTouchIcon) ?>">
    <link rel="icon" type="<?= $brandIconType ?>" sizes="32x32" href="<?= htmlspecialchars($brandIcon32) ?>">
    <link rel="icon" type="<?= $brandIconType ?>" sizes="16x16" href="<?= htmlspecialchars($brandIcon16) ?>">
