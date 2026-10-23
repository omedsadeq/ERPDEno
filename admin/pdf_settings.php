<?php
/**
 * PDF Invoice Settings Page (Admin Only)
 * ڕێکخستنەکانی PDF بۆ وەسڵەکان
 *
 * This page allows administrators to configure global PDF invoice settings
 * including headers, footers, margins, colors, and branding.
 */

$pageTitle = 'ڕێکخستنەکانی PDF';
$breadcrumb = [['title' => 'ڕێکخستنەکانی PDF']];

// Admin gate BEFORE header output, so the 403 redirect actually works
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../classes/Auth.php';
(new Auth())->requireAdmin();

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../classes/PdfSettings.php';

// Check admin access
if (!$auth->isAdmin()) {
    header('Location: ' . APP_URL . '/403.php');
    exit;
}

$pdfSettingsManager = new PdfSettings();

// Check if table exists
$tableExists = $pdfSettingsManager->tableExists();

if (!$tableExists) {
    // Show migration required message
    ?>
    <div class="alert alert-warning" style="margin: 20px;">
        <i class="fas fa-exclamation-triangle"></i>
        <strong>خشتەی داتابەیس نەدۆزرایەوە!</strong><br>
        پێویستە میگرەیشنەکە جێبەجێ بکەیت بۆ دروستکردنی خشتەی <code>pdf_invoice_settings</code>
    </div>
    <div style="margin: 20px; padding: 20px; background: #f5f5f5; border-radius: 8px;">
        <h4>چۆنیەتی جێبەجێکردن:</h4>
        <p>سەردانی ئەم بەستەرە بکە بۆ جێبەجێکردنی میگرەیشن:</p>
        <a href="<?= APP_URL ?>/run_pdf_settings_migration.php" class="btn btn-primary" target="_blank">
            <i class="fas fa-database"></i> جێبەجێکردنی میگرەیشن
        </a>
        <p style="margin-top: 15px; color: #666;">
            دوای جێبەجێکردن، ئەم لاپەڕەیە نوێ بکەوە.
        </p>
    </div>
    <?php
    require_once __DIR__ . '/../includes/footer.php';
    exit;
}

// Handle form submission
$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'save_settings') {
            $data = $_POST['pdf'] ?? [];

            // Handle header image upload (full header image)
            if (isset($_FILES['header_image']) && $_FILES['header_image']['error'] === UPLOAD_ERR_OK) {
                $headerPath = $pdfSettingsManager->uploadHeaderImage($_FILES['header_image']);
                if ($headerPath) {
                    $data['header_image_path'] = $headerPath;
                }
            }

            // Handle header logo upload (for manual design)
            if (isset($_FILES['header_logo']) && $_FILES['header_logo']['error'] === UPLOAD_ERR_OK) {
                $logoPath = $pdfSettingsManager->uploadHeaderImage($_FILES['header_logo'], 'logo_');
                if ($logoPath) {
                    $data['header_logo_path'] = $logoPath;
                }
            }

            // Handle footer image upload
            if (isset($_FILES['footer_image']) && $_FILES['footer_image']['error'] === UPLOAD_ERR_OK) {
                $footerPath = $pdfSettingsManager->uploadFooterImage($_FILES['footer_image']);
                if ($footerPath) {
                    $data['footer_image_path'] = $footerPath;
                }
            }

            // Get existing settings ID or create new
            $existingSettings = $pdfSettingsManager->getActiveSettings();

            if ($existingSettings) {
                $pdfSettingsManager->update($existingSettings['id'], $data);
            } else {
                $data['is_active'] = 1;
                $pdfSettingsManager->create($data);
            }

            $message = 'ڕێکخستنەکانی PDF پاشەکەوتکران';
            $messageType = 'success';

        } elseif ($action === 'delete_header_image') {
            $settings = $pdfSettingsManager->getActiveSettings();
            if ($settings) {
                $pdfSettingsManager->update($settings['id'], ['header_image_path' => '']);
                $message = 'وێنەی سەرپەڕە سڕایەوە';
                $messageType = 'success';
            }

        } elseif ($action === 'delete_footer_image') {
            $settings = $pdfSettingsManager->getActiveSettings();
            if ($settings) {
                $pdfSettingsManager->update($settings['id'], ['footer_image_path' => '']);
                $message = 'وێنەی ژێرپەڕە سڕایەوە';
                $messageType = 'success';
            }
        }

    } catch (Exception $e) {
        $message = 'هەڵە: ' . $e->getMessage();
        $messageType = 'danger';
    }

    // Reload settings after update
    PdfSettings::clearCache();
}

// Get current settings
$settings = $pdfSettingsManager->getActiveSettings();
if (!$settings) {
    $settings = $pdfSettingsManager->getDefaults();
}

$activeTab = $_GET['tab'] ?? 'header';
?>

<style>
/* PDF Settings Styles */
.pdf-settings-panel {
    background: white;
    border-radius: 10px;
    overflow: hidden;
    box-shadow: 0 2px 10px rgba(0, 0, 0, 0.08);
    border: 1px solid #e8e8e8;
}

.tabs-pdf {
    display: flex;
    gap: 4px;
    background: linear-gradient(to bottom, #f8f9fa, #f1f3f5);
    padding: 8px;
    border-bottom: 2px solid #e0e0e0;
    flex-wrap: wrap;
}

.tab-pdf {
    flex: 1;
    min-width: 100px;
    padding: 10px 16px;
    background: transparent;
    border: none;
    border-radius: 6px;
    font-size: 13px;
    font-weight: 600;
    color: #546e7a;
    cursor: pointer;
    transition: all 0.2s ease;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
}

.tab-pdf:hover {
    background: rgba(255, 255, 255, 0.6);
    color: var(--win-accent, #0078d4);
}

.tab-pdf.active {
    background: linear-gradient(135deg, var(--win-accent, #0078d4), var(--win-accent-deep, #0063b1));
    color: white;
    box-shadow: 0 2px 8px rgba(var(--win-accent-rgb, 0, 120, 212), 0.3);
}

.tab-pdf i {
    font-size: 14px;
}

.settings-section {
    padding: 20px;
}

.settings-section-title {
    font-size: 15px;
    font-weight: 700;
    color: #2c3e50;
    margin-bottom: 15px;
    padding-bottom: 10px;
    border-bottom: 2px solid #f0f0f0;
    display: flex;
    align-items: center;
    gap: 8px;
}

.form-row-pdf {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 15px;
    margin-bottom: 15px;
}

.form-group-pdf {
    margin-bottom: 15px;
}

.form-label-pdf {
    display: block;
    font-size: 12px;
    font-weight: 600;
    margin-bottom: 6px;
    color: #37474f;
}

.form-help {
    font-size: 11px;
    color: #666;
    margin-top: 4px;
}

.color-preview {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    margin-top: 4px;
}

.color-preview-box {
    width: 30px;
    height: 20px;
    border-radius: 4px;
    border: 1px solid #ddd;
}

.image-preview-container {
    margin-top: 10px;
    padding: 15px;
    background: #f9f9f9;
    border-radius: 8px;
    text-align: center;
}

.image-preview-container img {
    max-width: 300px;
    max-height: 100px;
    border: 1px solid #ddd;
    border-radius: 4px;
}

.image-preview-container .delete-btn {
    display: block;
    margin-top: 10px;
}

.preview-box {
    background: #f7fafc;
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    padding: 15px;
    margin-top: 15px;
}

.preview-title {
    font-size: 12px;
    font-weight: 600;
    color: #4a5568;
    margin-bottom: 10px;
}

.margin-diagram {
    display: grid;
    grid-template-columns: 1fr auto 1fr;
    grid-template-rows: auto 1fr auto;
    gap: 5px;
    max-width: 300px;
    margin: 15px auto;
}

.margin-diagram .top { grid-column: 2; text-align: center; }
.margin-diagram .left { grid-row: 2; writing-mode: vertical-rl; text-align: center; }
.margin-diagram .right { grid-row: 2; writing-mode: vertical-lr; text-align: center; }
.margin-diagram .bottom { grid-column: 2; text-align: center; }
.margin-diagram .center {
    grid-column: 2;
    grid-row: 2;
    width: 150px;
    height: 200px;
    background: white;
    border: 2px solid #2c5282;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 10px;
    color: #666;
}

.margin-value {
    padding: 4px 8px;
    background: #e2e8f0;
    border-radius: 4px;
    font-size: 11px;
    font-weight: 600;
}

.test-pdf-btn {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 12px 24px;
    background: linear-gradient(135deg, #38a169, #2f855a);
    color: white;
    border: none;
    border-radius: 8px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s ease;
}

.test-pdf-btn:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(56, 161, 105, 0.3);
}

@media (max-width: 768px) {
    .tabs-pdf {
        flex-direction: column;
    }
    .tab-pdf {
        min-width: auto;
    }
}
</style>

<?php if ($message): ?>
<div class="alert alert-<?= $messageType ?>" data-auto-hide>
    <i class="fas fa-<?= $messageType === 'success' ? 'check-circle' : 'exclamation-circle' ?> alert-icon"></i>
    <?= htmlspecialchars($message) ?>
</div>
<?php endif; ?>

<div class="pdf-settings-panel">
    <div class="tabs-pdf">
        <button class="tab-pdf <?= $activeTab === 'header' ? 'active' : '' ?>" onclick="location='?tab=header'">
            <i class="fas fa-heading"></i> سەرپەڕە
        </button>
        <button class="tab-pdf <?= $activeTab === 'footer' ? 'active' : '' ?>" onclick="location='?tab=footer'">
            <i class="fas fa-shoe-prints"></i> ژێرپەڕە
        </button>
        <button class="tab-pdf <?= $activeTab === 'margins' ? 'active' : '' ?>" onclick="location='?tab=margins'">
            <i class="fas fa-expand"></i> بۆشایی
        </button>
        <button class="tab-pdf <?= $activeTab === 'styles' ? 'active' : '' ?>" onclick="location='?tab=styles'">
            <i class="fas fa-palette"></i> ستایل
        </button>
        <button class="tab-pdf <?= $activeTab === 'company' ? 'active' : '' ?>" onclick="location='?tab=company'">
            <i class="fas fa-building"></i> کۆمپانیا
        </button>
        <button class="tab-pdf <?= $activeTab === 'advanced' ? 'active' : '' ?>" onclick="location='?tab=advanced'">
            <i class="fas fa-cogs"></i> پێشکەوتوو
        </button>
    </div>

    <form method="POST" action="" enctype="multipart/form-data">
        <input type="hidden" name="action" value="save_settings">

        <!-- Header Settings Tab -->
        <?php if ($activeTab === 'header'): ?>
        <div class="settings-section">
            <h4 class="settings-section-title">
                <i class="fas fa-heading text-primary"></i>
                ڕێکخستنەکانی سەرپەڕە
            </h4>

            <div class="form-group-pdf">
                <label class="form-check">
                    <input type="checkbox" name="pdf[show_header]" value="1" <?= !empty($settings['show_header']) ? 'checked' : '' ?>>
                    <span class="form-check-label">پیشاندانی سەرپەڕە لە هەموو لاپەڕەکان</span>
                </label>
            </div>

            <div class="form-row-pdf">
                <div class="form-group-pdf">
                    <label class="form-label-pdf">بەرزی سەرپەڕە (mm)</label>
                    <input type="number" name="pdf[header_height_mm]" class="form-control"
                           value="<?= $settings['header_height_mm'] ?? 30 ?>" min="10" max="80">
                    <div class="form-help">بەرزی ناوچەی سەرپەڕە بە میلیمەتر</div>
                </div>
            </div>

            <!-- Header Type Selection -->
            <div class="form-group-pdf" style="background: #f8f9fa; padding: 15px; border-radius: 8px; margin-bottom: 20px;">
                <label class="form-label-pdf" style="font-size: 13px; margin-bottom: 10px;">جۆری سەرپەڕە</label>
                <div style="display: flex; gap: 20px; flex-wrap: wrap;">
                    <label class="form-check" style="flex: 1; min-width: 200px; padding: 12px; background: white; border-radius: 6px; border: 2px solid <?= ($settings['header_type'] ?? 'image') === 'image' ? '#0099cc' : '#ddd' ?>; cursor: pointer;">
                        <input type="radio" name="pdf[header_type]" value="image" <?= ($settings['header_type'] ?? 'image') === 'image' ? 'checked' : '' ?> onchange="toggleHeaderType()">
                        <span class="form-check-label" style="font-weight: 600;">
                            <i class="fas fa-image" style="color: #0099cc;"></i> وێنەی ئامادە
                        </span>
                        <div style="font-size: 11px; color: #666; margin-top: 4px;">وێنەیەک ئەپلۆد بکە بۆ سەرپەڕە</div>
                    </label>
                    <label class="form-check" style="flex: 1; min-width: 200px; padding: 12px; background: white; border-radius: 6px; border: 2px solid <?= ($settings['header_type'] ?? 'image') === 'manual' ? '#0099cc' : '#ddd' ?>; cursor: pointer;">
                        <input type="radio" name="pdf[header_type]" value="manual" <?= ($settings['header_type'] ?? 'image') === 'manual' ? 'checked' : '' ?> onchange="toggleHeaderType()">
                        <span class="form-check-label" style="font-weight: 600;">
                            <i class="fas fa-edit" style="color: #0099cc;"></i> دروستکردنی دەستی
                        </span>
                        <div style="font-size: 11px; color: #666; margin-top: 4px;">لۆگۆ + دەق بە شێوەی دەستی</div>
                    </label>
                </div>
            </div>

            <!-- Option 1: Image Header -->
            <div id="header-image-section" style="<?= ($settings['header_type'] ?? 'image') === 'manual' ? 'display:none;' : '' ?>">
                <div class="form-group-pdf">
                    <label class="form-label-pdf">وێنەی سەرپەڕە (تەواوی سەرپەڕە)</label>
                    <input type="file" name="header_image" class="form-control" accept="image/*">
                    <div class="form-help">وێنەیەک ئەپلۆد بکە کە تەواوی سەرپەڕەت تێدایە (JPEG, PNG - حەدی زۆر 5MB)</div>

                    <?php if (!empty($settings['header_image_path'])): ?>
                    <div class="image-preview-container">
                        <p style="font-size: 11px; color: #666; margin-bottom: 8px;">وێنەی ئێستا:</p>
                        <img src="<?= APP_URL . $settings['header_image_path'] ?>?v=<?= time() ?>" alt="Header Image">
                        <form method="POST" style="display: inline;">
                            <input type="hidden" name="action" value="delete_header_image">
                            <button type="submit" class="btn btn-sm btn-danger delete-btn" onclick="return confirm('دڵنیای لە سڕینەوە؟')">
                                <i class="fas fa-trash"></i> سڕینەوەی وێنە
                            </button>
                        </form>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Option 2: Manual Header Design -->
            <div id="header-manual-section" style="<?= ($settings['header_type'] ?? 'image') === 'image' ? 'display:none;' : '' ?>">
                <div style="background: #e8f4f8; padding: 15px; border-radius: 8px; margin-bottom: 15px;">
                    <div style="font-size: 12px; color: #006699; margin-bottom: 10px;">
                        <i class="fas fa-info-circle"></i> پێشبینی سەرپەڕە:
                    </div>
                    <div style="background: white; border: 1px solid #0099cc; padding: 10px; border-radius: 4px; display: flex; justify-content: space-between; align-items: center;">
                        <div style="text-align: left; font-size: 10px;">
                            <div style="font-weight: bold;" id="preview-name-en"><?= htmlspecialchars(($settings['header_company_name_en'] ?? '') ?: Branding::nameEn()) ?></div>
                            <div id="preview-phone1"><?= htmlspecialchars(($settings['header_phone1'] ?? '') ?: Branding::phone(1)) ?></div>
                            <div id="preview-phone2"><?= htmlspecialchars(($settings['header_phone2'] ?? '') ?: Branding::phone(2)) ?></div>
                        </div>
                        <div style="text-align: center;">
                            <img src="<?= htmlspecialchars(Branding::logoUrl()) ?>" style="height: 40px;" onerror="this.style.display='none'">
                        </div>
                        <div style="text-align: right; font-size: 10px;">
                            <div style="font-weight: bold;" id="preview-name-ku"><?= htmlspecialchars(($settings['header_company_name_ku'] ?? '') ?: Branding::printName()) ?></div>
                            <div id="preview-address"><?= htmlspecialchars(($settings['header_address'] ?? '') ?: Branding::address()) ?></div>
                        </div>
                    </div>
                </div>

                <div class="form-group-pdf">
                    <label class="form-label-pdf">وێنەی لۆگۆ (ناوەڕاست)</label>
                    <input type="file" name="header_logo" class="form-control" accept="image/*">
                    <div class="form-help">لۆگۆی کلینیک - لە ناوەڕاستی سەرپەڕە دەردەکەوێت</div>

                    <?php if (!empty($settings['header_logo_path'])): ?>
                    <div class="image-preview-container" style="margin-top: 10px;">
                        <img src="<?= APP_URL . $settings['header_logo_path'] ?>?v=<?= time() ?>" alt="Logo" style="max-height: 60px;">
                    </div>
                    <?php endif; ?>
                </div>

                <div class="form-row-pdf">
                    <div class="form-group-pdf">
                        <label class="form-label-pdf">ناوی کلینیک (کوردی - لای ڕاست)</label>
                        <input type="text" name="pdf[header_company_name_ku]" class="form-control"
                               value="<?= htmlspecialchars($settings['header_company_name_ku'] ?? '') ?>"
                               placeholder="<?= htmlspecialchars(Branding::printName()) ?>" oninput="document.getElementById('preview-name-ku').textContent=this.value">
                    </div>
                    <div class="form-group-pdf">
                        <label class="form-label-pdf">ناوی کلینیک (ئینگلیزی - لای چەپ)</label>
                        <input type="text" name="pdf[header_company_name_en]" class="form-control"
                               value="<?= htmlspecialchars($settings['header_company_name_en'] ?? '') ?>"
                               placeholder="<?= htmlspecialchars(Branding::nameEn()) ?>" oninput="document.getElementById('preview-name-en').textContent=this.value">
                    </div>
                </div>

                <div class="form-row-pdf">
                    <div class="form-group-pdf">
                        <label class="form-label-pdf">ژمارەی مۆبایل ١ (لای چەپ)</label>
                        <input type="text" name="pdf[header_phone1]" class="form-control"
                               value="<?= htmlspecialchars($settings['header_phone1'] ?? '') ?>"
                               placeholder="07506932626" oninput="document.getElementById('preview-phone1').textContent=this.value">
                    </div>
                    <div class="form-group-pdf">
                        <label class="form-label-pdf">ژمارەی مۆبایل ٢ (لای چەپ)</label>
                        <input type="text" name="pdf[header_phone2]" class="form-control"
                               value="<?= htmlspecialchars($settings['header_phone2'] ?? '') ?>"
                               placeholder="07506327575" oninput="document.getElementById('preview-phone2').textContent=this.value">
                    </div>
                </div>

                <div class="form-group-pdf">
                    <label class="form-label-pdf">ناونیشان (لای ڕاست)</label>
                    <input type="text" name="pdf[header_address]" class="form-control"
                           value="<?= htmlspecialchars($settings['header_address'] ?? '') ?>"
                           placeholder="سلێمانی - شەقامی..." oninput="document.getElementById('preview-address').textContent=this.value">
                </div>

                <!-- Advanced Styling Options -->
                <div style="background: #f0f8ff; padding: 15px; border-radius: 8px; margin-top: 15px; border: 1px dashed #0099cc;">
                    <h5 style="margin: 0 0 15px 0; color: #006699; font-size: 13px;">
                        <i class="fas fa-palette"></i> ڕێکخستنەکانی پێشکەوتوو (ڕەنگ و قەبارە)
                    </h5>

                    <!-- Box Border Options -->
                    <div class="form-row-pdf">
                        <div class="form-group-pdf">
                            <label class="form-check">
                                <input type="checkbox" name="pdf[header_show_box_border]" value="1" <?= !empty($settings['header_show_box_border']) ? 'checked' : '' ?>>
                                <span class="form-check-label">پیشاندانی سنووری چوارچێوە</span>
                            </label>
                        </div>
                        <div class="form-group-pdf">
                            <label class="form-check">
                                <input type="checkbox" name="pdf[header_show_phone_icon]" value="1" <?= !empty($settings['header_show_phone_icon']) ? 'checked' : '' ?>>
                                <span class="form-check-label">📞 پیشاندانی ئایکۆنی مۆبایل</span>
                            </label>
                        </div>
                    </div>

                    <!-- Border and Background -->
                    <div class="form-row-pdf">
                        <div class="form-group-pdf">
                            <label class="form-label-pdf">ڕەنگی سنوور</label>
                            <input type="color" name="pdf[header_border_color]" class="form-control" style="height: 35px; width: 80px;"
                                   value="<?= $settings['header_border_color'] ?? '#0099cc' ?>">
                        </div>
                        <div class="form-group-pdf">
                            <label class="form-label-pdf">ڕەنگی پاشبنەما</label>
                            <input type="color" name="pdf[header_box_bg_color]" class="form-control" style="height: 35px; width: 80px;"
                                   value="<?= $settings['header_box_bg_color'] ?? '#ffffff' ?>">
                        </div>
                        <div class="form-group-pdf">
                            <label class="form-label-pdf">ئەستووری سنوور (px)</label>
                            <input type="number" name="pdf[header_box_border_width]" class="form-control" style="width: 80px;"
                                   value="<?= $settings['header_box_border_width'] ?? 1 ?>" min="0" max="5">
                        </div>
                        <div class="form-group-pdf">
                            <label class="form-label-pdf">بۆشایی ناوەوە (px)</label>
                            <input type="number" name="pdf[header_box_padding]" class="form-control" style="width: 80px;"
                                   value="<?= $settings['header_box_padding'] ?? 10 ?>" min="0" max="30">
                        </div>
                    </div>

                    <!-- Text Colors -->
                    <div class="form-row-pdf">
                        <div class="form-group-pdf">
                            <label class="form-label-pdf">ڕەنگی ناوی کوردی</label>
                            <input type="color" name="pdf[header_name_ku_color]" class="form-control" style="height: 35px; width: 80px;"
                                   value="<?= $settings['header_name_ku_color'] ?? '#333333' ?>">
                        </div>
                        <div class="form-group-pdf">
                            <label class="form-label-pdf">ڕەنگی ناوی ئینگلیزی</label>
                            <input type="color" name="pdf[header_name_en_color]" class="form-control" style="height: 35px; width: 80px;"
                                   value="<?= $settings['header_name_en_color'] ?? '#333333' ?>">
                        </div>
                        <div class="form-group-pdf">
                            <label class="form-label-pdf">ڕەنگی مۆبایل</label>
                            <input type="color" name="pdf[header_phone_color]" class="form-control" style="height: 35px; width: 80px;"
                                   value="<?= $settings['header_phone_color'] ?? '#666666' ?>">
                        </div>
                        <div class="form-group-pdf">
                            <label class="form-label-pdf">ڕەنگی ناونیشان</label>
                            <input type="color" name="pdf[header_address_color]" class="form-control" style="height: 35px; width: 80px;"
                                   value="<?= $settings['header_address_color'] ?? '#666666' ?>">
                        </div>
                    </div>

                    <!-- Font Sizes -->
                    <div class="form-row-pdf">
                        <div class="form-group-pdf">
                            <label class="form-label-pdf">قەبارەی ناوی کوردی (pt)</label>
                            <input type="number" name="pdf[header_name_ku_size]" class="form-control" style="width: 80px;"
                                   value="<?= $settings['header_name_ku_size'] ?? 11 ?>" min="8" max="18">
                        </div>
                        <div class="form-group-pdf">
                            <label class="form-label-pdf">قەبارەی ناوی ئینگلیزی (pt)</label>
                            <input type="number" name="pdf[header_name_en_size]" class="form-control" style="width: 80px;"
                                   value="<?= $settings['header_name_en_size'] ?? 11 ?>" min="8" max="18">
                        </div>
                        <div class="form-group-pdf">
                            <label class="form-label-pdf">قەبارەی مۆبایل (pt)</label>
                            <input type="number" name="pdf[header_phone_size]" class="form-control" style="width: 80px;"
                                   value="<?= $settings['header_phone_size'] ?? 9 ?>" min="7" max="14">
                        </div>
                        <div class="form-group-pdf">
                            <label class="form-label-pdf">قەبارەی ناونیشان (pt)</label>
                            <input type="number" name="pdf[header_address_size]" class="form-control" style="width: 80px;"
                                   value="<?= $settings['header_address_size'] ?? 9 ?>" min="7" max="14">
                        </div>
                    </div>
                </div>
            </div>

            <div class="form-group-pdf" style="margin-top: 15px;">
                <label class="form-label-pdf">HTML ی تایبەتی سەرپەڕە (ئیختیاری - پێشکەوتوو)</label>
                <textarea name="pdf[header_html]" class="form-control" rows="4"
                          placeholder="HTML ی تایبەتی بۆ سەرپەڕە..."><?= htmlspecialchars($settings['header_html'] ?? '') ?></textarea>
                <div class="form-help">
                    گۆڕاوەکان:
                    <code>{COMPANY_NAME}</code>, <code>{COMPANY_PHONE}</code>, <code>{DATE}</code>, <code>{DATETIME}</code>
                </div>
            </div>

            <button type="submit" class="btn btn-primary">
                <i class="fas fa-save"></i> پاشەکەوتکردن
            </button>
        </div>

        <script>
        function toggleHeaderType() {
            const isManual = document.querySelector('input[name="pdf[header_type]"][value="manual"]').checked;
            document.getElementById('header-image-section').style.display = isManual ? 'none' : 'block';
            document.getElementById('header-manual-section').style.display = isManual ? 'block' : 'none';

            // Update border colors
            document.querySelectorAll('input[name="pdf[header_type]"]').forEach(radio => {
                radio.closest('label').style.borderColor = radio.checked ? '#0099cc' : '#ddd';
            });
        }
        </script>
        <?php endif; ?>

        <!-- Footer Settings Tab -->
        <?php if ($activeTab === 'footer'): ?>
        <div class="settings-section">
            <h4 class="settings-section-title">
                <i class="fas fa-shoe-prints text-primary"></i>
                ڕێکخستنەکانی ژێرپەڕە
            </h4>

            <div class="form-group-pdf">
                <label class="form-check">
                    <input type="checkbox" name="pdf[show_footer]" value="1" <?= !empty($settings['show_footer']) ? 'checked' : '' ?>>
                    <span class="form-check-label">پیشاندانی ژێرپەڕە لە هەموو لاپەڕەکان</span>
                </label>
            </div>

            <div class="form-row-pdf">
                <div class="form-group-pdf">
                    <label class="form-label-pdf">بەرزی ژێرپەڕە (mm)</label>
                    <input type="number" name="pdf[footer_height_mm]" class="form-control"
                           value="<?= $settings['footer_height_mm'] ?? 25 ?>" min="10" max="60">
                </div>
            </div>

            <!-- Footer Type Selection -->
            <div class="form-group-pdf" style="background: #f8f9fa; padding: 15px; border-radius: 8px; margin-bottom: 20px;">
                <label class="form-label-pdf" style="font-size: 13px; margin-bottom: 10px;">جۆری ژێرپەڕە</label>
                <div style="display: flex; gap: 20px; flex-wrap: wrap;">
                    <label class="form-check" style="flex: 1; min-width: 200px; padding: 12px; background: white; border-radius: 6px; border: 2px solid <?= ($settings['footer_type'] ?? 'image') === 'image' ? '#0099cc' : '#ddd' ?>; cursor: pointer;">
                        <input type="radio" name="pdf[footer_type]" value="image" <?= ($settings['footer_type'] ?? 'image') === 'image' ? 'checked' : '' ?> onchange="toggleFooterType()">
                        <span class="form-check-label" style="font-weight: 600;">
                            <i class="fas fa-image" style="color: #0099cc;"></i> وێنەی ئامادە
                        </span>
                        <div style="font-size: 11px; color: #666; margin-top: 4px;">وێنەیەک ئەپلۆد بکە بۆ ژێرپەڕە</div>
                    </label>
                    <label class="form-check" style="flex: 1; min-width: 200px; padding: 12px; background: white; border-radius: 6px; border: 2px solid <?= ($settings['footer_type'] ?? 'image') === 'manual' ? '#0099cc' : '#ddd' ?>; cursor: pointer;">
                        <input type="radio" name="pdf[footer_type]" value="manual" <?= ($settings['footer_type'] ?? 'image') === 'manual' ? 'checked' : '' ?> onchange="toggleFooterType()">
                        <span class="form-check-label" style="font-weight: 600;">
                            <i class="fas fa-edit" style="color: #0099cc;"></i> دروستکردنی دەستی
                        </span>
                        <div style="font-size: 11px; color: #666; margin-top: 4px;">دەق + ژمارەی لاپەڕە</div>
                    </label>
                </div>
            </div>

            <!-- Option 1: Image Footer -->
            <div id="footer-image-section" style="<?= ($settings['footer_type'] ?? 'image') === 'manual' ? 'display:none;' : '' ?>">
                <div class="form-group-pdf">
                    <label class="form-label-pdf">وێنەی ژێرپەڕە (تەواوی ژێرپەڕە)</label>
                    <input type="file" name="footer_image" class="form-control" accept="image/*">
                    <div class="form-help">وێنەیەک ئەپلۆد بکە کە تەواوی ژێرپەڕەت تێدایە</div>

                    <?php if (!empty($settings['footer_image_path'])): ?>
                    <div class="image-preview-container">
                        <p style="font-size: 11px; color: #666; margin-bottom: 8px;">وێنەی ئێستا:</p>
                        <img src="<?= APP_URL . $settings['footer_image_path'] ?>?v=<?= time() ?>" alt="Footer Image">
                        <form method="POST" style="display: inline;">
                            <input type="hidden" name="action" value="delete_footer_image">
                            <button type="submit" class="btn btn-sm btn-danger delete-btn" onclick="return confirm('دڵنیای لە سڕینەوە؟')">
                                <i class="fas fa-trash"></i> سڕینەوەی وێنە
                            </button>
                        </form>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Option 2: Manual Footer Design -->
            <div id="footer-manual-section" style="<?= ($settings['footer_type'] ?? 'image') === 'image' ? 'display:none;' : '' ?>">
                <div class="form-group-pdf">
                    <label class="form-check">
                        <input type="checkbox" name="pdf[show_page_numbers]" value="1" <?= !empty($settings['show_page_numbers']) ? 'checked' : '' ?>>
                        <span class="form-check-label">پیشاندانی ژمارەی لاپەڕە</span>
                    </label>
                </div>

                <div class="form-group-pdf">
                    <label class="form-label-pdf">شێوازی ژمارەی لاپەڕە</label>
                    <input type="text" name="pdf[page_number_format]" class="form-control"
                           value="<?= htmlspecialchars($settings['page_number_format'] ?? 'لاپەڕە {PAGENO} لە {nbpg}') ?>">
                    <div class="form-help">
                        <code>{PAGENO}</code> = ژمارەی لاپەڕە، <code>{nbpg}</code> = کۆی لاپەڕەکان
                    </div>
                </div>

                <div class="form-group-pdf">
                    <label class="form-label-pdf">دەقی ژێرپەڕە (لای ڕاست)</label>
                    <input type="text" name="pdf[footer_text_right]" class="form-control"
                           value="<?= htmlspecialchars($settings['footer_text_right'] ?? 'سیستەمی زیرەکی کلینیکی ددان') ?>"
                           placeholder="سیستەمی زیرەکی کلینیکی ددان">
                </div>

                <div class="form-group-pdf">
                    <label class="form-label-pdf">دەقی ژێرپەڕە (لای چەپ)</label>
                    <input type="text" name="pdf[footer_text_left]" class="form-control"
                           value="<?= htmlspecialchars($settings['footer_text_left'] ?? '') ?>"
                           placeholder="بەرواری چاپ: {DATETIME}">
                    <div class="form-help">گۆڕاوەکان: <code>{DATE}</code>, <code>{DATETIME}</code></div>
                </div>

                <!-- Footer Styling Options -->
                <div style="background: #f0f8ff; padding: 15px; border-radius: 8px; margin-top: 15px; border: 1px dashed #0099cc;">
                    <h5 style="margin: 0 0 15px 0; color: #006699; font-size: 13px;">
                        <i class="fas fa-palette"></i> ڕێکخستنەکانی ڕەنگ
                    </h5>
                    <div class="form-row-pdf">
                        <div class="form-group-pdf">
                            <label class="form-label-pdf">ڕەنگی سنوور</label>
                            <input type="color" name="pdf[footer_border_color]" class="form-control" style="height: 35px; width: 80px;"
                                   value="<?= $settings['footer_border_color'] ?? '#0099cc' ?>">
                        </div>
                        <div class="form-group-pdf">
                            <label class="form-label-pdf">ڕەنگی پاشبنەما</label>
                            <input type="color" name="pdf[footer_bg_color]" class="form-control" style="height: 35px; width: 80px;"
                                   value="<?= $settings['footer_bg_color'] ?? '#ffffff' ?>">
                        </div>
                        <div class="form-group-pdf">
                            <label class="form-label-pdf">ڕەنگی دەق</label>
                            <input type="color" name="pdf[footer_text_color]" class="form-control" style="height: 35px; width: 80px;"
                                   value="<?= $settings['footer_text_color'] ?? '#666666' ?>">
                        </div>
                    </div>
                </div>
            </div>

            <div class="form-group-pdf" style="margin-top: 15px;">
                <label class="form-label-pdf">HTML ی تایبەتی ژێرپەڕە (ئیختیاری - پێشکەوتوو)</label>
                <textarea name="pdf[footer_html]" class="form-control" rows="4"
                          placeholder="HTML ی تایبەتی بۆ ژێرپەڕە..."><?= htmlspecialchars($settings['footer_html'] ?? '') ?></textarea>
            </div>

            <button type="submit" class="btn btn-primary">
                <i class="fas fa-save"></i> پاشەکەوتکردن
            </button>
        </div>

        <script>
        function toggleFooterType() {
            const isManual = document.querySelector('input[name="pdf[footer_type]"][value="manual"]').checked;
            document.getElementById('footer-image-section').style.display = isManual ? 'none' : 'block';
            document.getElementById('footer-manual-section').style.display = isManual ? 'block' : 'none';

            document.querySelectorAll('input[name="pdf[footer_type]"]').forEach(radio => {
                radio.closest('label').style.borderColor = radio.checked ? '#0099cc' : '#ddd';
            });
        }
        </script>
        <?php endif; ?>

        <!-- Margins Settings Tab -->
        <?php if ($activeTab === 'margins'): ?>
        <div class="settings-section">
            <h4 class="settings-section-title">
                <i class="fas fa-expand text-primary"></i>
                ڕێکخستنەکانی بۆشایی (Margins)
            </h4>

            <div class="alert alert-info">
                <i class="fas fa-info-circle"></i>
                <strong>گرنگ:</strong> بۆشایی سەرەوە دەبێت لە بەرزی سەرپەڕە + ١٠ملم زیاتر بێت، و بۆشایی خوارەوە دەبێت لە بەرزی ژێرپەڕە + ١٠ملم زیاتر بێت.
            </div>

            <div class="form-row-pdf">
                <div class="form-group-pdf">
                    <label class="form-label-pdf">بۆشایی سەرەوە (mm)</label>
                    <input type="number" name="pdf[margin_top_mm]" class="form-control"
                           value="<?= $settings['margin_top_mm'] ?? 40 ?>" min="10" max="100" id="marginTop">
                    <div class="form-help">کەمترین: <?= ($settings['header_height_mm'] ?? 30) + 10 ?>mm (بەرزی سەرپەڕە + ١٠)</div>
                </div>

                <div class="form-group-pdf">
                    <label class="form-label-pdf">بۆشایی خوارەوە (mm)</label>
                    <input type="number" name="pdf[margin_bottom_mm]" class="form-control"
                           value="<?= $settings['margin_bottom_mm'] ?? 35 ?>" min="10" max="100" id="marginBottom">
                    <div class="form-help">کەمترین: <?= ($settings['footer_height_mm'] ?? 25) + 10 ?>mm (بەرزی ژێرپەڕە + ١٠)</div>
                </div>
            </div>

            <div class="form-row-pdf">
                <div class="form-group-pdf">
                    <label class="form-label-pdf">بۆشایی ڕاست (mm)</label>
                    <input type="number" name="pdf[margin_right_mm]" class="form-control"
                           value="<?= $settings['margin_right_mm'] ?? 15 ?>" min="5" max="50" id="marginRight">
                </div>

                <div class="form-group-pdf">
                    <label class="form-label-pdf">بۆشایی چەپ (mm)</label>
                    <input type="number" name="pdf[margin_left_mm]" class="form-control"
                           value="<?= $settings['margin_left_mm'] ?? 15 ?>" min="5" max="50" id="marginLeft">
                </div>
            </div>

            <!-- Margin Diagram -->
            <div class="preview-box">
                <div class="preview-title">پیشاندانی بۆشایی لاپەڕە</div>
                <div class="margin-diagram">
                    <div class="top"><span class="margin-value" id="topVal"><?= $settings['margin_top_mm'] ?? 40 ?>mm</span></div>
                    <div class="left"><span class="margin-value" id="rightVal"><?= $settings['margin_right_mm'] ?? 15 ?>mm</span></div>
                    <div class="center">ناوەڕۆکی وەسڵ</div>
                    <div class="right"><span class="margin-value" id="leftVal"><?= $settings['margin_left_mm'] ?? 15 ?>mm</span></div>
                    <div class="bottom"><span class="margin-value" id="bottomVal"><?= $settings['margin_bottom_mm'] ?? 35 ?>mm</span></div>
                </div>
            </div>

            <button type="submit" class="btn btn-primary">
                <i class="fas fa-save"></i> پاشەکەوتکردن
            </button>
        </div>
        <?php endif; ?>

        <!-- Styles Settings Tab -->
        <?php if ($activeTab === 'styles'): ?>
        <div class="settings-section">
            <h4 class="settings-section-title">
                <i class="fas fa-palette text-primary"></i>
                ڕێکخستنەکانی ستایل و ڕەنگ
            </h4>

            <div class="form-row-pdf">
                <div class="form-group-pdf">
                    <label class="form-label-pdf">جۆری فۆنت</label>
                    <select name="pdf[font_family]" class="form-control">
                        <option value="nrt" <?= in_array($settings['font_family'] ?? 'nrt', ['nrt', 'XBRiyaz'], true) ? 'selected' : '' ?>>NRT (کوردی — فۆنتی سیستەم)</option>
                        <option value="dejavusans" <?= ($settings['font_family'] ?? '') === 'dejavusans' ? 'selected' : '' ?>>DejaVu Sans</option>
                        <option value="arial" <?= ($settings['font_family'] ?? '') === 'arial' ? 'selected' : '' ?>>Arial</option>
                    </select>
                </div>

                <div class="form-group-pdf">
                    <label class="form-label-pdf">قەبارەی فۆنت (pt)</label>
                    <input type="number" name="pdf[font_size]" class="form-control"
                           value="<?= $settings['font_size'] ?? 12 ?>" min="8" max="18">
                </div>
            </div>

            <div class="form-row-pdf">
                <div class="form-group-pdf">
                    <label class="form-label-pdf">ڕەنگی سەرەکی</label>
                    <input type="color" name="pdf[primary_color]" class="form-control"
                           value="<?= $settings['primary_color'] ?? '#2c5282' ?>" style="height: 40px;">
                    <div class="color-preview">
                        <div class="color-preview-box" style="background: <?= $settings['primary_color'] ?? '#2c5282' ?>"></div>
                        <span><?= $settings['primary_color'] ?? '#2c5282' ?></span>
                    </div>
                </div>

                <div class="form-group-pdf">
                    <label class="form-label-pdf">ڕەنگی دووەم</label>
                    <input type="color" name="pdf[secondary_color]" class="form-control"
                           value="<?= $settings['secondary_color'] ?? '#4a5568' ?>" style="height: 40px;">
                    <div class="color-preview">
                        <div class="color-preview-box" style="background: <?= $settings['secondary_color'] ?? '#4a5568' ?>"></div>
                        <span><?= $settings['secondary_color'] ?? '#4a5568' ?></span>
                    </div>
                </div>
            </div>

            <div class="form-row-pdf">
                <div class="form-group-pdf">
                    <label class="form-label-pdf">ڕەنگی پاشبنەمای سەری خشتە</label>
                    <input type="color" name="pdf[table_header_bg]" class="form-control"
                           value="<?= $settings['table_header_bg'] ?? '#edf2f7' ?>" style="height: 40px;">
                </div>

                <div class="form-group-pdf">
                    <label class="form-label-pdf">ڕەنگی سنووری خشتە</label>
                    <input type="color" name="pdf[table_border_color]" class="form-control"
                           value="<?= $settings['table_border_color'] ?? '#cbd5e0' ?>" style="height: 40px;">
                </div>
            </div>

            <button type="submit" class="btn btn-primary">
                <i class="fas fa-save"></i> پاشەکەوتکردن
            </button>
        </div>
        <?php endif; ?>

        <!-- Company Settings Tab -->
        <?php if ($activeTab === 'company'): ?>
        <div class="settings-section">
            <h4 class="settings-section-title">
                <i class="fas fa-building text-primary"></i>
                زانیاری کۆمپانیا/کلینیک (بۆ سەرپەڕە)
            </h4>

            <div class="form-row-pdf">
                <div class="form-group-pdf">
                    <label class="form-label-pdf">ناوی کۆمپانیا/کلینیک</label>
                    <input type="text" name="pdf[company_name]" class="form-control"
                           value="<?= htmlspecialchars($settings['company_name'] ?? '') ?>"
                           placeholder="کلینیکی ددان">
                </div>

                <div class="form-group-pdf">
                    <label class="form-label-pdf">ناوی دووەم (ئینگلیزی)</label>
                    <input type="text" name="pdf[company_name_secondary]" class="form-control"
                           value="<?= htmlspecialchars($settings['company_name_secondary'] ?? '') ?>"
                           placeholder="Dental Clinic">
                </div>
            </div>

            <div class="form-group-pdf">
                <label class="form-label-pdf">ناونیشان</label>
                <textarea name="pdf[company_address]" class="form-control" rows="2"
                          placeholder="شەقامی سەرەکی، باڵەخانەی..."><?= htmlspecialchars($settings['company_address'] ?? '') ?></textarea>
            </div>

            <div class="form-row-pdf">
                <div class="form-group-pdf">
                    <label class="form-label-pdf">ژمارەی تەلەفۆن</label>
                    <input type="text" name="pdf[company_phone]" class="form-control"
                           value="<?= htmlspecialchars($settings['company_phone'] ?? '') ?>"
                           placeholder="0750 000 0000">
                </div>

                <div class="form-group-pdf">
                    <label class="form-label-pdf">ئیمەیڵ</label>
                    <input type="email" name="pdf[company_email]" class="form-control"
                           value="<?= htmlspecialchars($settings['company_email'] ?? '') ?>"
                           placeholder="info@clinic.com">
                </div>
            </div>

            <button type="submit" class="btn btn-primary">
                <i class="fas fa-save"></i> پاشەکەوتکردن
            </button>
        </div>
        <?php endif; ?>

        <!-- Advanced Settings Tab -->
        <?php if ($activeTab === 'advanced'): ?>
        <div class="settings-section">
            <h4 class="settings-section-title">
                <i class="fas fa-cogs text-primary"></i>
                ڕێکخستنەکانی پێشکەوتوو
            </h4>

            <div class="form-row-pdf">
                <div class="form-group-pdf">
                    <label class="form-label-pdf">قەبارەی لاپەڕە</label>
                    <select name="pdf[page_format]" class="form-control">
                        <option value="A4" <?= ($settings['page_format'] ?? 'A4') === 'A4' ? 'selected' : '' ?>>A4</option>
                        <option value="A5" <?= ($settings['page_format'] ?? 'A4') === 'A5' ? 'selected' : '' ?>>A5</option>
                        <option value="Letter" <?= ($settings['page_format'] ?? 'A4') === 'Letter' ? 'selected' : '' ?>>Letter</option>
                    </select>
                </div>

                <div class="form-group-pdf">
                    <label class="form-label-pdf">ئاڕاستەی لاپەڕە</label>
                    <select name="pdf[page_orientation]" class="form-control">
                        <option value="P" <?= ($settings['page_orientation'] ?? 'P') === 'P' ? 'selected' : '' ?>>ستوونی (Portrait)</option>
                        <option value="L" <?= ($settings['page_orientation'] ?? 'P') === 'L' ? 'selected' : '' ?>>ئاسۆیی (Landscape)</option>
                    </select>
                </div>
            </div>

            <div class="form-row-pdf">
                <div class="form-group-pdf">
                    <label class="form-label-pdf">ئاڕاستەی دەق</label>
                    <select name="pdf[direction]" class="form-control">
                        <option value="rtl" <?= ($settings['direction'] ?? 'rtl') === 'rtl' ? 'selected' : '' ?>>ڕاست بۆ چەپ (RTL - کوردی)</option>
                        <option value="ltr" <?= ($settings['direction'] ?? 'rtl') === 'ltr' ? 'selected' : '' ?>>چەپ بۆ ڕاست (LTR - ئینگلیزی)</option>
                    </select>
                </div>
            </div>

            <div class="form-group-pdf">
                <label class="form-label-pdf">واتەرمارک (نووسین لەسەر پاشبنەما)</label>
                <input type="text" name="pdf[watermark_text]" class="form-control"
                       value="<?= htmlspecialchars($settings['watermark_text'] ?? '') ?>"
                       placeholder="مەثەلەن: نووسخەی ڕەسمی">
            </div>

            <div class="form-group-pdf">
                <label class="form-label-pdf">ڕوونی واتەرمارک (0.01 - 1.00)</label>
                <input type="number" name="pdf[watermark_opacity]" class="form-control"
                       value="<?= $settings['watermark_opacity'] ?? 0.10 ?>" min="0.01" max="1" step="0.01">
            </div>

            <div class="form-group-pdf">
                <label class="form-label-pdf">CSS ی زیادە (پێشکەوتوو)</label>
                <textarea name="pdf[custom_css]" class="form-control" rows="6"
                          placeholder="/* CSS ی تایبەتی زیادە */"><?= htmlspecialchars($settings['custom_css'] ?? '') ?></textarea>
                <div class="form-help">CSS ی تایبەتی دەتوانیت لێرە زیاد بکەیت بۆ گۆڕینی ستایلی وەسڵەکان</div>
            </div>

            <button type="submit" class="btn btn-primary">
                <i class="fas fa-save"></i> پاشەکەوتکردن
            </button>

            <div class="preview-box" style="margin-top: 20px;">
                <div class="preview-title">تاقیکردنەوەی ڕێکخستنەکان</div>
                <p style="font-size: 12px; color: #666; margin-bottom: 15px;">
                    دوای پاشەکەوتکردن، دەتوانیت PDF ی تاقیکاری ببینیت بۆ دڵنیابوون لە ڕێکخستنەکان
                </p>
                <a href="<?= APP_URL ?>/test_pdf.php" target="_blank" class="test-pdf-btn">
                    <i class="fas fa-file-pdf"></i> بینینی PDF ی تاقیکاری
                </a>
            </div>
        </div>
        <?php endif; ?>
    </form>
</div>

<script>
// Update margin diagram on input change
document.querySelectorAll('#marginTop, #marginBottom, #marginLeft, #marginRight').forEach(input => {
    input.addEventListener('input', function() {
        const id = this.id.replace('margin', '').toLowerCase() + 'Val';
        const valElement = document.getElementById(id);
        if (valElement) {
            valElement.textContent = this.value + 'mm';
        }
    });
});

// Validate margins
document.querySelector('form').addEventListener('submit', function(e) {
    const headerHeight = <?= $settings['header_height_mm'] ?? 30 ?>;
    const footerHeight = <?= $settings['footer_height_mm'] ?? 25 ?>;
    const marginTop = parseInt(document.getElementById('marginTop')?.value || 40);
    const marginBottom = parseInt(document.getElementById('marginBottom')?.value || 35);

    if (marginTop < headerHeight + 10) {
        alert('بۆشایی سەرەوە دەبێت لانیکەم ' + (headerHeight + 10) + 'mm بێت');
        e.preventDefault();
        return false;
    }

    if (marginBottom < footerHeight + 10) {
        alert('بۆشایی خوارەوە دەبێت لانیکەم ' + (footerHeight + 10) + 'mm بێت');
        e.preventDefault();
        return false;
    }
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
