<?php
/**
 * Reports Hub
 * ناوەندی ڕاپۆرتەکان — لیستی هەموو ڕاپۆرتەکانی سیستەم
 */

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/classes/Database.php';
require_once __DIR__ . '/classes/Auth.php';

$auth = new Auth();
if (!$auth->isLoggedIn()) {
    header('Location: ' . APP_URL . '/login.php');
    exit;
}

$currentUser    = $auth->getCurrentUser();
$isAdmin        = $auth->isAdmin();
$isReceptionist = $auth->isReceptionist();
$isAccountant   = $auth->isAccountant();
$isDoctor       = $auth->isDoctor();

// Catalog of reports. Each entry: [url, title, desc, icon, color, roles...]
$reports = [
    // ── Appointments ──
    [
        'group' => 'مەوعیدەکان',
        'url'   => APP_URL . '/appointments-analytics.php',
        'title' => 'ئامار و شیکاری مەوعیدەکان',
        'desc'  => 'ڕاپۆرتی ئامارێکی تەواو بەپێی کات و دکتۆر لەگەڵ چارتی ڕێژە',
        'icon'  => 'fa-chart-pie',
        'color' => '#7c3aed',
        'roles' => ['admin','receptionist','accountant','doctor'],
    ],
    [
        'group' => 'مەوعیدەکان',
        'url'   => APP_URL . '/appointments-by-type.php',
        'title' => 'مەوعیدەکان بەپێی جۆر',
        'desc'  => 'فلتەرکردنی لیستی مەوعیدەکان بەپێی جۆر و دۆخ و دکتۆر',
        'icon'  => 'fa-filter',
        'color' => '#0ea5e9',
        'roles' => ['admin','receptionist','accountant','doctor'],
    ],
    [
        'group' => 'مەوعیدەکان',
        'url'   => APP_URL . '/today-appointments.php',
        'title' => 'مەوعیدەکانی ئەمڕۆ',
        'desc'  => 'لیستی مەوعیدەکانی ئەمڕۆ لەگەڵ زانیاری قەرز و پارەی بەڵێندراو',
        'icon'  => 'fa-calendar-day',
        'color' => '#16a34a',
        'roles' => ['admin','receptionist','accountant','doctor'],
    ],

    // ── Visits ──
    [
        'group' => 'سەردانەکان',
        'url'   => APP_URL . '/visits-report.php',
        'title' => 'ڕاپۆرتی سەردانەکان',
        'desc'  => 'شیکاری سەردانی ڕۆژانە، نەخۆشی نوێ، بێ مەوعید، پەیتای کاتژمێر',
        'icon'  => 'fa-user-clock',
        'color' => '#7c3aed',
        // Clinic-wide per-doctor visit matrix — not for individual doctors
        // (they use visits.php, which is scoped to their own visits).
        'roles' => ['admin','receptionist','accountant'],
    ],

    // ── Doctors ──
    [
        'group' => 'دکتۆرەکان',
        'url'   => APP_URL . '/doctors/daily-summary.php',
        'title' => 'پوختەی ڕۆژانەی دکتۆر',
        'desc'  => 'کار و پارەی ڕۆژانە — سەردان، جۆری ئیش، کات، لەگەڵ ڕاپۆرتی PDF',
        'icon'  => 'fa-user-md',
        'color' => '#0078d4',
        // Only the roles that hold page.doctor_report can open it; listing
        // others here would just show them a card that ends on 403.
        'roles' => ['admin','doctor'],
    ],
    [
        'group' => 'دکتۆرەکان',
        'url'   => APP_URL . '/doctors/general-report.php',
        'title' => 'ڕاپۆرتی گشتی دکتۆرەکان',
        'desc'  => 'کۆی گشتی، داشکاندن، پارەی وەرگیراو و کۆمیسیۆن بۆ هەر دکتۆرێک',
        'icon'  => 'fa-file-invoice-dollar',
        'color' => '#16a34a',
        'roles' => ['admin','receptionist','accountant'],
    ],

    // ── Patients ──
    [
        'group' => 'نەخۆشەکان',
        'url'   => APP_URL . '/patients/report.php',
        'title' => 'ڕاپۆرتی ڕۆژانەی نەخۆش',
        'desc'  => 'نەخۆشە نوێیەکان، فایلە نوێیەکان، کەیسەکان، چارەسەر، پارەدان',
        'icon'  => 'fa-user-injured',
        'color' => '#0ea5e9',
        'roles' => ['admin','receptionist','accountant','doctor'],
    ],

    // ── Treatments ──
    [
        'group' => 'چارەسەرەکان',
        'url'   => APP_URL . '/treatments-report.php',
        'title' => 'ڕاپۆرتی شیکاری چارەسەرەکان',
        'desc'  => 'چارەسەرەکان بەپێی جۆر (چاندن، پڕکردنەوە...) · فلتەری دکتۆر و نەخۆش',
        'icon'  => 'fa-tooth',
        'color' => '#16a34a',
        'roles' => ['admin','receptionist','accountant','doctor'],
    ],

    // ── Financial ──
    [
        'group' => 'دارایی',
        'url'   => APP_URL . '/admin/reports.php',
        'title' => 'ڕاپۆرتی دارایی',
        'desc'  => 'کۆی گشتی داهات و خەرجی، شیکاری دارایی',
        'icon'  => 'fa-chart-line',
        'color' => '#16a34a',
        'roles' => ['admin','accountant'],
    ],
    [
        'group' => 'دارایی',
        'url'   => APP_URL . '/admin/expense-reports.php',
        'title' => 'ڕاپۆرتی خەرجی',
        'desc'  => 'شیکاری خەرجییەکانی کلینیک بەپێی جۆر و ماوە',
        'icon'  => 'fa-receipt',
        'color' => '#dc2626',
        'roles' => ['admin','accountant'],
    ],
    [
        'group' => 'دارایی',
        'url'   => APP_URL . '/cancellation-report.php',
        'title' => 'ڕاپۆرتی هەڵوەشاندنەوەکان',
        'desc'  => 'ئاماری ئەو مەوعیدانەی کە هەڵوەشێندراونەتەوە',
        'icon'  => 'fa-calendar-times',
        'color' => '#ea580c',
        'roles' => ['admin','accountant','receptionist'],
    ],
];

// Filter by current user role
$currentRole = $currentUser['role'] ?? '';
$visibleReports = array_filter($reports, fn($r) => in_array($currentRole, $r['roles'], true) || $isAdmin);

// Group by category
$grouped = [];
foreach ($visibleReports as $r) { $grouped[$r['group']][] = $r; }

$pageTitle = 'ڕاپۆرتەکان';
$breadcrumb = [['title' => 'ڕاپۆرتەکان']];
$currentPage = 'reports';
require_once __DIR__ . '/includes/header.php';
?>

<style>
.rh-header { margin-bottom: 16px; }
.rh-header h3 { margin: 0 0 4px; color: #0f172a; font-size: 22px; display: flex; align-items: center; gap: 10px; }
.rh-header h3 i { color: #7c3aed; }
.rh-header .sub { color: #607d8b; font-size: 13px; }

.rh-group { margin-bottom: 22px; }
.rh-group-title { font-size: 14px; font-weight: 700; color: #64748b; margin: 0 0 10px; padding-inline-start: 4px; display: flex; align-items: center; gap: 8px; }
.rh-group-title::before { content: ''; display: inline-block; width: 4px; height: 16px; background: linear-gradient(180deg, #7c3aed, #5b21b6); border-radius: 2px; }
.rh-group-title .count { background: #e0e7ff; color: #4338ca; padding: 1px 8px; border-radius: 10px; font-size: 11px; font-weight: 700; }

.rh-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 12px; }

.rh-card {
    background: #fff;
    border: 1px solid #e3e8ee;
    border-radius: 12px;
    padding: 16px;
    text-decoration: none;
    display: flex;
    gap: 12px;
    align-items: flex-start;
    transition: transform .18s, box-shadow .18s, border-color .18s;
    position: relative;
    overflow: hidden;
}
.rh-card::before {
    content: '';
    position: absolute; inset-block: 0; inset-inline-end: 0;
    width: 4px; background: var(--accent, #7c3aed);
    opacity: .7; transition: opacity .18s;
}
.rh-card:hover { transform: translateY(-2px); box-shadow: 0 10px 25px rgba(0,0,0,.08); border-color: var(--accent, #7c3aed); }
.rh-card:hover::before { opacity: 1; }

.rh-icon {
    width: 44px; height: 44px; flex-shrink: 0;
    border-radius: 12px;
    background: color-mix(in srgb, var(--accent, #7c3aed) 15%, white);
    color: var(--accent, #7c3aed);
    display: flex; align-items: center; justify-content: center;
    font-size: 18px;
}

.rh-body { flex: 1; min-width: 0; }
.rh-title { font-size: 14px; font-weight: 700; color: #0f172a; margin: 0 0 4px; display: flex; align-items: center; gap: 6px; justify-content: space-between; }
.rh-title .arrow { color: var(--accent, #7c3aed); font-size: 12px; opacity: .6; transition: transform .18s; }
.rh-card:hover .arrow { opacity: 1; transform: translateX(-3px); }
.rh-desc { font-size: 12px; color: #64748b; line-height: 1.5; margin: 0; }

.rh-empty { padding: 60px; text-align: center; color: #9aa7b3; background: #fff; border: 1px dashed #e3e8ee; border-radius: 12px; }
.rh-empty i { font-size: 36px; display: block; margin-bottom: 10px; color: #cbd5e1; }

@media (max-width: 640px) {
    .rh-header h3 { font-size: 18px; }
    .rh-grid { grid-template-columns: 1fr; }
    .rh-card { padding: 12px; }
    .rh-icon { width: 38px; height: 38px; font-size: 16px; }
}
</style>

<div class="rh-header">
    <h3><i class="fas fa-chart-pie"></i> ناوەندی ڕاپۆرتەکان</h3>
    <div class="sub">هەموو ڕاپۆرتە شیکاریەکانی سیستەم لە یەک شوێندا</div>
</div>

<?php if (empty($grouped)): ?>
<div class="rh-empty">
    <i class="fas fa-folder-open"></i>
    هیچ ڕاپۆرتێک بۆ تۆ بەردەست نییە
</div>
<?php else: ?>
<?php foreach ($grouped as $groupName => $items): ?>
<div class="rh-group">
    <h4 class="rh-group-title">
        <?= htmlspecialchars($groupName) ?>
        <span class="count"><?= count($items) ?></span>
    </h4>
    <div class="rh-grid">
        <?php foreach ($items as $r): ?>
        <a href="<?= htmlspecialchars($r['url']) ?>" class="rh-card" style="--accent: <?= $r['color'] ?>;">
            <div class="rh-icon"><i class="fas <?= $r['icon'] ?>"></i></div>
            <div class="rh-body">
                <div class="rh-title">
                    <span><?= htmlspecialchars($r['title']) ?></span>
                    <i class="fas fa-arrow-left arrow"></i>
                </div>
                <p class="rh-desc"><?= htmlspecialchars($r['desc']) ?></p>
            </div>
        </a>
        <?php endforeach; ?>
    </div>
</div>
<?php endforeach; ?>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
