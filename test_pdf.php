<?php
/**
 * Test PDF Generator
 * Generates a sample invoice PDF using the InvoicePdfService
 * to verify that all settings are working correctly.
 */

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/classes/Auth.php';
require_once __DIR__ . '/services/InvoicePdfService.php';

// Admin-only test script (Auth has no getInstance(); the old static call was fatal)
$auth = new Auth();
$auth->requireAdmin();

// Initialize the service
$pdfService = new InvoicePdfService();
$pdfService->setTitle('وەسڵی تاقیکاری - Test Invoice');
$pdfService->setAuthor('DentHisab System');

// Build sample invoice content
$html = '
<div class="invoice-title">وەسڵی تاقیکاری</div>
<div class="invoice-subtitle">Test Invoice - بۆ تاقیکردنەوەی ڕێکخستنەکانی PDF</div>
';

// Patient info box
$html .= InvoicePdfService::buildInfoBox([
    'ناوی نەخۆش' => 'تاقیکاری ناو',
    'کۆدی نەخۆش' => 'P-2024-001',
    'ژمارەی تەلەفۆن' => '0750 000 0000',
    'بەرواری وەسڵ' => date('Y-m-d'),
    'ژمارەی کەیس' => 'C-2024-001'
], 'زانیاری نەخۆش');

// Sample treatments table
$headers = ['#', 'چارەسەر', 'ددان/ئەرک', 'نرخ', 'داشکان', 'کۆ'];
$rows = [
    ['1', 'پڕکردنەوەی مینا', 'ددانی 16', InvoicePdfService::formatCurrency(50000), InvoicePdfService::formatCurrency(0), InvoicePdfService::formatCurrency(50000)],
    ['2', 'کێشانی ددان', 'ددانی 36', InvoicePdfService::formatCurrency(75000), InvoicePdfService::formatCurrency(5000), InvoicePdfService::formatCurrency(70000)],
    ['3', 'پاککردنەوەی ددان', 'هەموو', InvoicePdfService::formatCurrency(30000), InvoicePdfService::formatCurrency(0), InvoicePdfService::formatCurrency(30000)],
    ['4', 'ڕەنگدانەوە', 'سەرەوە', InvoicePdfService::formatCurrency(100000), InvoicePdfService::formatCurrency(10000), InvoicePdfService::formatCurrency(90000)],
];
$totals = ['', '', '<strong>کۆی گشتی</strong>', '', '', '<strong class="total-amount">' . InvoicePdfService::formatCurrency(240000) . '</strong>'];

$html .= '<h3 style="margin-top: 20px;">چارەسەرەکان</h3>';
$html .= InvoicePdfService::buildTable($headers, $rows, $totals);

// Payment summary
$html .= '
<div class="info-box" style="margin-top: 20px;">
    <table width="100%" style="border: none;">
        <tr>
            <td style="text-align: right; border: none; padding: 5px 0;">
                <span class="info-label">کۆی گشتی:</span>
                <span class="info-value">' . InvoicePdfService::formatCurrency(240000) . '</span>
            </td>
        </tr>
        <tr>
            <td style="text-align: right; border: none; padding: 5px 0;">
                <span class="info-label">پارەی دراو:</span>
                <span class="info-value text-success">' . InvoicePdfService::formatCurrency(150000) . '</span>
            </td>
        </tr>
        <tr>
            <td style="text-align: right; border: none; padding: 5px 0; border-top: 1px solid #ddd; margin-top: 5px;">
                <span class="info-label">قەرز:</span>
                <span class="info-value text-danger" style="font-size: 14pt; font-weight: bold;">' . InvoicePdfService::formatCurrency(90000) . '</span>
            </td>
        </tr>
    </table>
</div>
';

// Badges demo
$html .= '
<h3 style="margin-top: 20px;">نموونەی بەیجەکان</h3>
<p>
    <span class="badge badge-success">پارەدراو</span>
    <span class="badge badge-warning">بەشێک دراو</span>
    <span class="badge badge-danger">نەدراو</span>
    <span class="badge badge-info">چاوەڕوان</span>
</p>
';

// Quadrant colors demo
$html .= '
<h3 style="margin-top: 20px;">ڕەنگی کوادرانتەکان</h3>
<table class="invoice-table" style="max-width: 400px;">
    <tr>
        <td class="quadrant-ul" style="text-align: center;">سەرەوە چەپ (UL)</td>
        <td class="quadrant-ur" style="text-align: center;">سەرەوە ڕاست (UR)</td>
    </tr>
    <tr>
        <td class="quadrant-ll" style="text-align: center;">خوارەوە چەپ (LL)</td>
        <td class="quadrant-lr" style="text-align: center;">خوارەوە ڕاست (LR)</td>
    </tr>
</table>
';

// Notes
$html .= '
<div class="info-box" style="margin-top: 20px; background: #fff5f5; border-color: #feb2b2;">
    <strong class="text-danger">تێبینی گرنگ:</strong><br>
    ئەم وەسڵە تەنها بۆ تاقیکردنەوەی ڕێکخستنەکانی PDF دروستکراوە.<br>
    This is a test invoice to verify PDF settings are working correctly.
</div>
';

// Second page demo
$html .= '<pagebreak />';
$html .= '
<div class="invoice-title">لاپەڕەی دووەم</div>
<div class="invoice-subtitle">Second Page - بۆ تاقیکردنەوەی سەرپەڕە و ژێرپەڕە</div>

<p style="margin-top: 20px;">
    ئەم لاپەڕەیە بۆ تاقیکردنەوەی ئەوەیە کە سەرپەڕە و ژێرپەڕە لە هەموو لاپەڕەکان پیشان دەدرێن.
</p>

<p>
    This page is to test that headers and footers appear on all pages correctly.
</p>

<h3>چەند خاڵی گرنگ:</h3>
<ul style="list-style: disc; padding-right: 20px;">
    <li>سەرپەڕە دەبێت لە سەرەوەی ئەم لاپەڕەیە پیشان بدرێت</li>
    <li>ژێرپەڕە دەبێت لە خوارەوەی ئەم لاپەڕەیە پیشان بدرێت</li>
    <li>ژمارەی لاپەڕە دەبێت "لاپەڕە ٢ لە ٢" بێت</li>
    <li>ڕەنگ و فۆنت دەبێت وەک لاپەڕەی یەکەم بن</li>
</ul>

<div class="preview-box" style="margin-top: 30px; padding: 20px; text-align: center;">
    <p style="font-size: 16pt; color: #2c5282;">
        ئەگەر هەموو شتەکان بە دروستی کاردەکەن، ڕێکخستنەکانی PDF سەرکەوتووانە کاردەکەن!
    </p>
    <p style="font-size: 12pt; color: #666; margin-top: 10px;">
        If everything appears correctly, PDF settings are working successfully!
    </p>
</div>
';

// Set content and output
$pdfService->setContent($html);
$pdfService->output('test_invoice_' . date('Ymd_His') . '.pdf', 'I');
