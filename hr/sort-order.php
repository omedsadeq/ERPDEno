<?php
/**
 * ڕیزبەندی کارمەندان — the column order of the monthly report.
 *
 * Drag a name (or use the arrows on a phone) and save. The WHOLE list is saved
 * every time, numbered 1…n, so two people can never share a place — Panjamor
 * saved only the visible, filtered part and left duplicates behind.
 * Inactive employees keep their number and follow at the end.
 */

require_once __DIR__ . '/_bootstrap.php';
hr_require('hr.employees.manage');

$db = Database::getInstance();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::require();
    $ids = array_values(array_unique(array_filter(array_map('intval', explode(',', (string)($_POST['order'] ?? ''))))));
    $active = array_map('intval', array_column(
        $db->fetchAll("SELECT id FROM hr_employees WHERE status = 'active'"), 'id'));
    sort($active);
    $posted = $ids;
    sort($posted);
    if ($posted !== $active) {
        hr_flash('error', 'لیستەکە گۆڕاوە لە کاتێکدا ڕیزت دەکرد. پەڕەکە نوێ بکەرەوە و دووبارە هەوڵ بدەرەوە.');
        hr_redirect();
    }
    $db->beginTransaction();
    try {
        $n = 0;
        foreach ($ids as $id) {
            $db->query('UPDATE hr_employees SET display_order = ? WHERE id = ?', [++$n, $id]);
        }
        // Inactive people go after everyone active, in their old order.
        foreach ($db->fetchAll("SELECT id FROM hr_employees WHERE status <> 'active' ORDER BY COALESCE(display_order, 9999), id") as $r) {
            $db->query('UPDATE hr_employees SET display_order = ? WHERE id = ?', [++$n, (int)$r['id']]);
        }
        $db->commit();
    } catch (Throwable $ex) {
        $db->rollBack();
        throw $ex;
    }
    AuditLog::log('hr_sort_order', 'hr_employees', null, null, ['order' => $ids]);
    hr_flash('success', 'ڕیزبەندی پاشەکەوت کرا');
    hr_redirect();
}

$employees = (new HrAttendance())->employees();

$pageTitle = 'ڕیزبەندی کارمەندان';
$customCSS = $hrBaseCss . <<<CSS
.hr-sort{max-width:640px;background:#fff;border:1px solid #e5e7eb;border-radius:10px;overflow:hidden}
.hr-sort-head{display:flex;justify-content:space-between;align-items:center;gap:10px;padding:12px 14px;border-bottom:1px solid #e5e7eb;background:#f8fafc}
.hr-sort-list{list-style:none;margin:0;padding:6px}
.hr-sort-item{display:flex;align-items:center;gap:10px;padding:8px 10px;margin:3px 0;border:1px solid #e5e7eb;border-radius:8px;background:#fff;cursor:grab;user-select:none}
.hr-sort-item:hover{border-color:#94a3b8}
.hr-sort-item.dragging{opacity:.45;border-style:dashed}
.hr-sort-item.over{border-color:#0f766e;box-shadow:inset 0 2px 0 #0f766e}
.hr-sort-pos{width:28px;height:28px;border-radius:7px;background:#f1f5f9;display:grid;place-items:center;font-weight:800;font-size:12px;color:#475569;flex-shrink:0}
.hr-sort-name{flex:1;min-width:0}
.hr-sort-name small{color:#9ca3af;margin-inline-start:6px}
.hr-sort-grip{color:#cbd5e1}
.hr-sort-btns{display:flex;gap:2px}
.hr-sort-btns button{border:0;background:#f1f5f9;border-radius:6px;width:28px;height:28px;cursor:pointer;color:#475569}
.hr-sort-btns button:hover{background:#e2e8f0}
.hr-sort-dirty{display:none;color:#b45309;font-size:12.5px;font-weight:700}
.hr-sort.is-dirty .hr-sort-dirty{display:inline}
CSS;
require_once __DIR__ . '/../includes/header.php';
hr_flash_render();
?>

<form method="POST" id="hrSortForm" class="hr-sort">
    <input type="hidden" name="order" id="hrSortOrder">
    <div class="hr-sort-head">
        <div>
            <b>ڕیزی ستوونەکانی ڕاپۆرتی مانگانە</b>
            <div class="hr-muted" style="font-size:12px">ناوێک ڕابکێشە بۆ شوێنی خۆی، یان تیرەکان بەکاربهێنە</div>
        </div>
        <div style="display:flex;align-items:center;gap:10px">
            <span class="hr-sort-dirty">پاشەکەوت نەکراوە</span>
            <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> پاشەکەوتکردن</button>
        </div>
    </div>
    <ol class="hr-sort-list" id="hrSortList">
        <?php foreach ($employees as $e): ?>
        <li class="hr-sort-item" draggable="true" data-id="<?= (int)$e['id'] ?>">
            <span class="hr-sort-grip"><i class="fas fa-grip-vertical"></i></span>
            <span class="hr-sort-pos"></span>
            <span class="hr-sort-name"><?= hr_esc($e['full_name']) ?><small>PIN <?= hr_esc(hr_pin($e)) ?></small></span>
            <span class="hr-badge <?= $e['employee_type'] ?>"><?= $e['employee_type'] === 'doctor' ? 'دکتۆر' : 'کارمەند' ?></span>
            <?php if ((int)($e['monthly_hidden'] ?? 0)): ?><span class="hr-badge off" title="لە ڕاپۆرتی مانگانە پیشان نادرێت"><i class="fas fa-eye-slash"></i> شاراوە</span><?php endif; ?>
            <span class="hr-sort-btns">
                <button type="button" title="بۆ سەرەوە" onclick="hrSortMove(this, -1)"><i class="fas fa-arrow-up"></i></button>
                <button type="button" title="بۆ خوارەوە" onclick="hrSortMove(this, 1)"><i class="fas fa-arrow-down"></i></button>
            </span>
        </li>
        <?php endforeach; ?>
    </ol>
</form>

<?php
$customJS = <<<JS
const hrSortList = document.getElementById('hrSortList');
const hrSortForm = document.getElementById('hrSortForm');
let hrDragged = null;

function hrSortRefresh(dirty) {
    const ids = [];
    hrSortList.querySelectorAll('.hr-sort-item').forEach((li, i) => {
        li.querySelector('.hr-sort-pos').textContent = i + 1;
        ids.push(li.dataset.id);
    });
    document.getElementById('hrSortOrder').value = ids.join(',');
    if (dirty) hrSortForm.classList.add('is-dirty');
}

function hrSortMove(btn, dir) {
    const li = btn.closest('.hr-sort-item');
    const other = dir < 0 ? li.previousElementSibling : li.nextElementSibling;
    if (!other) return;
    if (dir < 0) hrSortList.insertBefore(li, other); else hrSortList.insertBefore(other, li);
    hrSortRefresh(true);
}

hrSortList.addEventListener('dragstart', e => {
    hrDragged = e.target.closest('.hr-sort-item');
    if (!hrDragged) return;
    hrDragged.classList.add('dragging');
    e.dataTransfer.effectAllowed = 'move';
});
hrSortList.addEventListener('dragend', () => {
    if (hrDragged) hrDragged.classList.remove('dragging');
    hrSortList.querySelectorAll('.over').forEach(el => el.classList.remove('over'));
    hrDragged = null;
});
hrSortList.addEventListener('dragover', e => {
    e.preventDefault();
    const target = e.target.closest('.hr-sort-item');
    if (!target || target === hrDragged) return;
    hrSortList.querySelectorAll('.over').forEach(el => el.classList.remove('over'));
    target.classList.add('over');
});
hrSortList.addEventListener('drop', e => {
    e.preventDefault();
    const target = e.target.closest('.hr-sort-item');
    if (!target || !hrDragged || target === hrDragged) return;
    const items = [...hrSortList.children];
    if (items.indexOf(hrDragged) < items.indexOf(target)) target.after(hrDragged); else target.before(hrDragged);
    target.classList.remove('over');
    hrSortRefresh(true);
});
window.addEventListener('beforeunload', e => {
    if (hrSortForm.classList.contains('is-dirty')) { e.preventDefault(); e.returnValue = ''; }
});
hrSortForm.addEventListener('submit', () => hrSortForm.classList.remove('is-dirty'));
hrSortRefresh(false);
JS;
require_once __DIR__ . '/../includes/footer.php';
