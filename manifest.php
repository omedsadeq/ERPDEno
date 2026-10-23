<?php
/**
 * PWA manifest with the centre's name, colour and logo (classes/Branding.php).
 * Replaces the static manifest.json, which still exists for clients that cached
 * the old link.
 */

require_once __DIR__ . '/config/config.php';

$iconDir = 'assets/images/icons/';
if (Branding::hasCustomLogo()) {
    $size  = @getimagesize(Branding::logoPath());
    $sizes = $size ? $size[0] . 'x' . $size[1] : 'any';
    $icons = [
        ['src' => Branding::logoUrl(), 'sizes' => $sizes, 'type' => Branding::logoMime(), 'purpose' => 'any'],
    ];
} else {
    $icons = [
        ['src' => $iconDir . 'icon-192.png',          'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'],
        ['src' => $iconDir . 'icon-512.png',          'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'],
        ['src' => $iconDir . 'icon-maskable-192.png', 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'maskable'],
        ['src' => $iconDir . 'icon-maskable-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
    ];
}
$shortcutIcon = [['src' => $icons[0]['src'], 'sizes' => $icons[0]['sizes']]];

header('Content-Type: application/manifest+json; charset=utf-8');
echo json_encode([
    'name'             => 'سیستەمی زیرەکی کلینیکی ددان - ' . Branding::nameEn(),
    'short_name'       => Branding::nameEn(),
    'description'      => 'سیستەمی بەڕێوەبردنی کلینیکی ددان - مەوعید، سەردان، نەخۆش، پارەدان',
    'lang'             => 'ku',
    'dir'              => 'rtl',
    'start_url'        => './index.php',
    'scope'            => './',
    'display'          => 'standalone',
    'orientation'      => 'portrait-primary',
    'theme_color'      => Branding::primaryColor(),
    'background_color' => '#f1f1f1',
    'icons'            => $icons,
    'shortcuts'        => [
        ['name' => 'ژووری چاوەڕوانی', 'url' => './waiting-room.php', 'icons' => $shortcutIcon],
        ['name' => 'مەوعیدەکان',      'url' => './appointments.php', 'icons' => $shortcutIcon],
        ['name' => 'نەخۆشی نوێ',      'url' => './patients/add.php', 'icons' => $shortcutIcon],
    ],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
