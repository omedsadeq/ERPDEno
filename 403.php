<?php
/**
 * 403 Forbidden Page
 * سیستەمی زیرەکی کلینیکی ددان
 */

require_once __DIR__ . '/config/config.php';
?>
<!DOCTYPE html>
<html lang="ku" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>دەستگەیشتن ڕێپێنەدراوە - <?= APP_NAME ?></title>
    <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/fonts.css">
    <link rel="stylesheet" href="<?= APP_URL ?>/assets/vendor/fontawesome/css/all.min.css">
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: var(--app-font-family);
            background: linear-gradient(180deg, #F3F3F3 0%, #E8E8E8 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .error-container {
            text-align: center;
            background: rgba(255, 255, 255, 0.85);
            -webkit-backdrop-filter: blur(40px) saturate(150%);
            backdrop-filter: blur(40px) saturate(150%);
            padding: 60px 48px;
            border-radius: 8px;
            border: 1px solid rgba(0, 0, 0, 0.0578);
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.08);
            max-width: 480px;
        }
        .error-icon {
            width: 100px;
            height: 100px;
            border-radius: 50%;
            background: linear-gradient(180deg, #FFECEC 0%, #FFD9D9 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 28px;
        }
        .error-icon i {
            font-size: 48px;
            color: #C42B1C;
        }
        h1 {
            color: #1A1A1A;
            font-size: 24px;
            font-weight: 600;
            margin-bottom: 12px;
        }
        p {
            color: #5D5D5D;
            font-size: 14px;
            line-height: 1.6;
            margin-bottom: 32px;
        }
        .btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 10px 24px;
            background: var(--win-accent, #0078D4);
            color: white;
            text-decoration: none;
            border-radius: 6px;
            font-weight: 600;
            font-size: 14px;
            transition: all 0.15s ease;
            border: 1px solid transparent;
        }
        .btn:hover {
            background: #1683D8;
            box-shadow: 0 2px 8px rgba(var(--win-accent-rgb, 0, 120, 212), 0.3);
        }
        .btn:active {
            background: #106EBE;
            transform: scale(0.98);
        }
        .error-code {
            display: inline-block;
            background: rgba(196, 43, 28, 0.1);
            color: #C42B1C;
            padding: 4px 12px;
            border-radius: 4px;
            font-size: 13px;
            font-weight: 600;
            margin-bottom: 20px;
        }
    </style>
    <?= Branding::themeStyleTag() ?>
</head>
<body>
    <div class="error-container">
        <div class="error-icon">
            <i class="fas fa-shield-halved"></i>
        </div>
        <span class="error-code">هەڵە ٤٠٣</span>
        <h1>دەستگەیشتن ڕێپێنەدراوە</h1>
        <p>ببوورە، تۆ مۆڵەتی دەستگەیشتنت بەم لاپەڕەیە نییە. ئەگەر پێت وایە کێشەیەکی تەکنیکی هەیە، تکایە پەیوەندی بکە بە بەڕێوەبەرەوە.</p>
        <a href="<?= APP_URL ?>/index.php" class="btn">
            <i class="fas fa-home"></i>
            گەڕانەوە بۆ سەرەتا
        </a>
    </div>
</body>
</html>
