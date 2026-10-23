<?php
/**
 * One navigation map for clinic finance. Existing pages keep their own access
 * checks and money handlers; this map only groups their entry points.
 */
require_once __DIR__ . '/../classes/Permission.php';
require_once __DIR__ . '/module_nav.php';

function finance_nav_definitions(): array
{
    return [
        'summary' => [
            'label' => 'پوختەی دارایی', 'icon' => 'fa-chart-pie', 'color' => '#0078d4',
            'items' => [
                ['page' => 'accountant/financial-summary', 'url' => 'accountant/financial-summary.php', 'label' => 'پوختەی دارایی', 'icon' => 'fa-chart-pie', 'can' => 'page.accountant_financial', 'roles' => ['admin', 'accountant']],
                ['page' => 'accountant/income', 'url' => 'accountant/income.php', 'label' => 'داهات', 'icon' => 'fa-cash-register', 'can' => 'page.accountant_financial'],
                ['page' => 'accountant/income-distribution', 'url' => 'accountant/income-distribution.php', 'label' => 'دابەشکردنی داهات', 'icon' => 'fa-scale-balanced', 'can' => 'page.accountant_financial'],
            ],
        ],
        'approvals' => [
            'label' => 'ناوەندی پەسەندکردن', 'icon' => 'fa-clipboard-check', 'color' => '#7c3aed',
            'items' => [
                ['page' => 'accountant/approval-center', 'url' => 'accountant/approval-center.php', 'label' => 'ناوەندی پەسەندکردن', 'icon' => 'fa-clipboard-check', 'can' => 'page.accountant_reports', 'roles' => ['admin', 'accountant']],
            ],
        ],
        'payments' => [
            'label' => 'وەرگرتنی پارە', 'icon' => 'fa-money-bill-wave', 'color' => '#15803d',
            'items' => [
                ['page' => 'accountant/payments', 'url' => 'accountant/payments.php', 'label' => 'وەرگرتنی پارە و مێژووی پارەدان', 'icon' => 'fa-money-bill-wave', 'can' => 'page.accountant_payments', 'roles' => ['admin', 'accountant']],
                ['page' => 'admin/payments', 'url' => 'admin/payments.php', 'label' => 'پارەدانەکان', 'icon' => 'fa-money-bill-wave', 'can' => 'page.payments', 'fallback' => true],
            ],
        ],
        'invoices' => [
            'label' => 'وەسڵەکان', 'icon' => 'fa-file-invoice', 'color' => '#0284c7',
            'items' => [
                ['page' => 'admin/invoices', 'url' => 'admin/invoices.php', 'label' => 'وەسڵەکان', 'icon' => 'fa-file-invoice', 'can' => 'page.invoices'],
            ],
        ],
        'cashboxes' => [
            'label' => 'سندوقەکان', 'icon' => 'fa-vault', 'color' => '#0f766e',
            'items' => [
                ['page'=>'admin/cashboxes','url'=>'admin/cashboxes.php','label'=>'سندوقی دکتۆرەکان و ئێکسڕەی','icon'=>'fa-vault','can'=>'page.cashboxes','roles'=>['admin']],
            ],
        ],
        'debts' => [
            'label' => 'قەرز و قیست', 'icon' => 'fa-hand-holding-usd', 'color' => '#d97706',
            'items' => [
                ['page' => 'debts', 'url' => 'debts.php', 'label' => 'قەرزەکان', 'icon' => 'fa-hand-holding-usd', 'can' => 'page.debts'],
                ['page' => 'installments', 'url' => 'installments.php', 'label' => 'قیستەکان', 'icon' => 'fa-credit-card', 'can' => 'page.installments'],
                ['page' => 'accountant/debt-collection', 'url' => 'accountant/debt-collection.php', 'label' => 'کۆکردنەوەی قەرز', 'icon' => 'fa-phone-volume', 'can' => 'page.debt_collection', 'roles' => ['admin', 'accountant', 'doctor']],
                ['page' => 'accountant/call-reports', 'url' => 'accountant/call-reports.php', 'label' => 'ڕاپۆرتی پەیوەندییەکان', 'icon' => 'fa-chart-bar', 'can' => 'page.debt_collection', 'roles' => ['admin', 'accountant', 'doctor']],
            ],
        ],
        'expenses' => [
            'label' => 'خەرجییەکان', 'icon' => 'fa-receipt', 'color' => '#dc2626',
            'items' => [
                ['page' => 'admin/expenses', 'url' => 'admin/expenses.php', 'label' => 'خەرجییەکان', 'icon' => 'fa-receipt', 'can' => 'page.expenses'],
                ['page' => 'admin/expenses-inventory', 'url' => 'admin/expenses-inventory.php', 'label' => 'خەرجی کۆگا', 'icon' => 'fa-warehouse', 'can' => 'page.expenses_inventory'],
                ['page' => 'admin/expenses-lab', 'url' => 'admin/expenses-lab.php', 'label' => 'خەرجی تاقیگە', 'icon' => 'fa-flask', 'can' => 'page.expenses_lab'],
                ['page' => 'admin/expenses-general', 'url' => 'admin/expenses-general.php', 'label' => 'خەرجی گشتی', 'icon' => 'fa-coins', 'can' => 'page.expenses_general'],
                ['page' => 'admin/expense-reports', 'url' => 'admin/expense-reports.php', 'label' => 'ڕاپۆرتی خەرجی', 'icon' => 'fa-chart-pie', 'can' => 'page.expenses', 'roles' => ['admin']],
                ['page' => 'admin/expense-groups', 'url' => 'admin/expense-groups.php', 'label' => 'ڕێکخستنی گرووپی خەرجی', 'icon' => 'fa-sliders', 'can' => 'page.expense_groups'],
            ],
        ],
    ];
}

function finance_nav_groups(): array
{
    $permission = Permission::getInstance();
    $role = $_SESSION['role'] ?? '';
    $groups = finance_nav_definitions();
    foreach ($groups as $key => &$group) {
        $items = [];
        foreach ($group['items'] as $item) {
            if (!$permission->can($item['can'])
                || (isset($item['roles']) && !in_array($role, $item['roles'], true))
                || (!empty($item['fallback']) && $items)) {
                continue;
            }
            $items[] = $item;
        }
        if (!$items) {
            unset($groups[$key]);
        } else {
            $group['items'] = $items;
        }
    }
    unset($group);
    return $groups;
}

function finance_nav_page(): string
{
    $script = str_replace('\\', '/', (string)($_SERVER['PHP_SELF'] ?? ''));
    $page = basename($script, '.php');
    $dir = basename(dirname($script));
    return in_array($dir, ['admin', 'accountant'], true) ? $dir . '/' . $page : $page;
}

function finance_nav_canonical_page(string $page): string
{
    // Keep old bookmarks working, with the same finance group selected.
    $aliases = [
        'accountant/dashboard' => 'accountant/approval-center',
        'accountant/receiving' => 'accountant/approval-center',
        'accountant/pending-reports' => 'accountant/approval-center',
        'accountant/report-detail' => 'accountant/approval-center',
        'accountant/debts' => 'debts',
        'accountant/installments' => 'installments',
        'accountant/expenses' => 'admin/expenses',
    ];
    return $aliases[$page] ?? $page;
}

function finance_nav_group_of(string $page): ?string
{
    return module_nav_group_of(finance_nav_definitions(), finance_nav_canonical_page($page));
}

function finance_nav_badges(array $groups, int $approvals, int $followUps): array
{
    $badges = [];
    if (isset($groups['approvals'])) {
        $badges['group:approvals'] = $approvals;
    }
    foreach ($groups['debts']['items'] ?? [] as $item) {
        if ($item['page'] === 'accountant/debt-collection') {
            $badges['group:debts'] = $followUps;
            $badges['page:accountant/debt-collection'] = $followUps;
        }
    }
    return $badges;
}
