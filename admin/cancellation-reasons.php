<?php
/**
 * Cancellation Reasons Management Page (Admin Only)
 * بەڕێوەبردنی هۆکارەکانی هەڵوەشاندنەوە
 */

$pageTitle = 'هۆکارەکانی هەڵوەشاندنەوە';
$breadcrumb = [['title' => 'هۆکارەکانی هەڵوەشاندنەوە']];

// Admin gate BEFORE any output so the 403 redirect works
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../classes/Auth.php';
$authGate = new Auth();
$authGate->requireAdmin();

require_once __DIR__ . '/../includes/header.php';

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    Csrf::require();
    $action = $_POST['action'];

    if ($action === 'add') {
        // Get max sort order
        $maxOrder = $db->fetch("SELECT MAX(sort_order) as max_order FROM cancellation_reasons");
        $newOrder = ($maxOrder['max_order'] ?? 0) + 1;

        $db->insert('cancellation_reasons', [
            'reason_key' => 'custom_' . time(),
            'reason_text' => trim($_POST['reason_text']),
            'sort_order' => $newOrder,
            'is_active' => 1
        ]);
        header('Location: ' . APP_URL . '/admin/cancellation-reasons.php?added=1');
        exit;
    } elseif ($action === 'edit') {
        $db->update('cancellation_reasons', [
            'reason_text' => trim($_POST['reason_text']),
            'sort_order' => intval($_POST['sort_order'])
        ], 'id = ?', [intval($_POST['reason_id'])]);
        header('Location: ' . APP_URL . '/admin/cancellation-reasons.php?updated=1');
        exit;
    } elseif ($action === 'delete') {
        $db->delete('cancellation_reasons', 'id = ?', [intval($_POST['reason_id'])]);
        header('Location: ' . APP_URL . '/admin/cancellation-reasons.php?deleted=1');
        exit;
    } elseif ($action === 'toggle_status') {
        $reasonId = intval($_POST['reason_id']);
        $current = $db->fetch("SELECT is_active FROM cancellation_reasons WHERE id = ?", [$reasonId]);
        $newStatus = $current['is_active'] ? 0 : 1;
        $db->update('cancellation_reasons', ['is_active' => $newStatus], 'id = ?', [$reasonId]);
        header('Location: ' . APP_URL . '/admin/cancellation-reasons.php');
        exit;
    }
}

// Get all reasons
$reasons = $db->fetchAll("SELECT * FROM cancellation_reasons ORDER BY sort_order, id");
?>

<?php if (isset($_GET['added'])): ?>
<div class="alert alert-success" data-auto-hide><i class="fas fa-check-circle alert-icon"></i> هۆکار زیادکرا</div>
<?php endif; ?>

<?php if (isset($_GET['updated'])): ?>
<div class="alert alert-success" data-auto-hide><i class="fas fa-check-circle alert-icon"></i> هۆکار نوێکرایەوە</div>
<?php endif; ?>

<?php if (isset($_GET['deleted'])): ?>
<div class="alert alert-success" data-auto-hide><i class="fas fa-check-circle alert-icon"></i> هۆکار سڕایەوە</div>
<?php endif; ?>

<div class="page-grid" style="display: grid; grid-template-columns: 1fr 350px; gap: 20px;">
    <!-- Reasons List -->
    <div class="panel">
        <div class="panel-header">
            <h3 class="panel-title"><i class="fas fa-list"></i> لیستی هۆکارەکان</h3>
        </div>
        <div class="panel-body" style="padding: 0;">
            <table class="data-grid">
                <thead>
                    <tr>
                        <th style="width: 50px;">#</th>
                        <th>هۆکار</th>
                        <th style="width: 80px;">ڕیزبەندی</th>
                        <th style="width: 80px;">دۆخ</th>
                        <th style="width: 120px;">کردار</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($reasons)): ?>
                    <tr>
                        <td colspan="5" style="text-align: center; padding: 30px; color: #888;">
                            <i class="fas fa-inbox" style="font-size: 24px; display: block; margin-bottom: 10px;"></i>
                            هیچ هۆکارێک نییە
                        </td>
                    </tr>
                    <?php else: ?>
                    <?php foreach ($reasons as $reason): ?>
                    <tr>
                        <td><?= $reason['id'] ?></td>
                        <td>
                            <strong><?= htmlspecialchars($reason['reason_text']) ?></strong>
                            <small style="display: block; color: #888; font-size: 11px;"><?= $reason['reason_key'] ?></small>
                        </td>
                        <td style="text-align: center;"><?= $reason['sort_order'] ?></td>
                        <td style="text-align: center;">
                            <form method="POST" style="display: inline;">
                                <input type="hidden" name="action" value="toggle_status">
                                <input type="hidden" name="reason_id" value="<?= $reason['id'] ?>">
                                <button type="submit" class="btn btn-sm <?= $reason['is_active'] ? 'btn-success' : 'btn-secondary' ?>" style="padding: 4px 10px; font-size: 11px;">
                                    <?= $reason['is_active'] ? 'چالاک' : 'ناچالاک' ?>
                                </button>
                            </form>
                        </td>
                        <td>
                            <div style="display: flex; gap: 5px;">
                                <button type="button" class="btn btn-sm btn-primary" onclick="editReason(<?= $reason['id'] ?>, '<?= htmlspecialchars($reason['reason_text'], ENT_QUOTES) ?>', <?= $reason['sort_order'] ?>)" style="padding: 4px 8px;">
                                    <i class="fas fa-edit"></i>
                                </button>
                                <form method="POST" style="display: inline;" onsubmit="return confirm('دڵنیایت لە سڕینەوە؟')">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="reason_id" value="<?= $reason['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-danger" style="padding: 4px 8px;">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </form>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Add/Edit Form -->
    <div>
        <div class="panel">
            <div class="panel-header">
                <h3 class="panel-title" id="formTitle"><i class="fas fa-plus-circle"></i> زیادکردنی هۆکار</h3>
            </div>
            <div class="panel-body">
                <form method="POST" id="reasonForm">
                    <input type="hidden" name="action" id="formAction" value="add">
                    <input type="hidden" name="reason_id" id="reasonId">

                    <div class="form-group">
                        <label><i class="fas fa-tag"></i> ناوی هۆکار <span style="color: #f44336;">*</span></label>
                        <input type="text" name="reason_text" id="reasonText" class="form-control" required placeholder="نمونە: نەخۆش داوای هەڵوەشاندنەوەی کرد">
                    </div>

                    <div class="form-group" id="sortOrderGroup" style="display: none;">
                        <label><i class="fas fa-sort-numeric-up"></i> ڕیزبەندی</label>
                        <input type="number" name="sort_order" id="sortOrder" class="form-control" value="0" min="0">
                        <small style="color: #888;">ژمارەی بچووکتر = پێشتر دەردەکەوێت</small>
                    </div>

                    <div style="display: flex; gap: 10px; margin-top: 20px;">
                        <button type="submit" class="btn btn-primary" style="flex: 1;">
                            <i class="fas fa-save"></i> <span id="submitText">زیادکردن</span>
                        </button>
                        <button type="button" class="btn btn-secondary" onclick="resetForm()" id="cancelBtn" style="display: none;">
                            <i class="fas fa-times"></i> پاشگەزبوونەوە
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Info Box -->
        <div class="panel mt-3" style="border-left: 4px solid #17a2b8;">
            <div class="panel-body" style="background: #e8f4f8;">
                <h5 style="margin: 0 0 10px 0; color: #17a2b8;"><i class="fas fa-info-circle"></i> زانیاری</h5>
                <ul style="margin: 0; padding-right: 20px; font-size: 13px; color: #555; line-height: 1.8;">
                    <li>هۆکارەکان لە داڕشتنی مەوعید بەکاردێن</li>
                    <li>کاتێک مەوعیدێک هەڵوەشێنیتەوە، پێویستە هۆکار هەڵبژێریت</li>
                    <li>هۆکاری ناچالاک لە لیستدا نیشان نادرێت</li>
                </ul>
            </div>
        </div>
    </div>
</div>

<script>
function editReason(id, text, order) {
    document.getElementById('formAction').value = 'edit';
    document.getElementById('reasonId').value = id;
    document.getElementById('reasonText').value = text;
    document.getElementById('sortOrder').value = order;
    document.getElementById('formTitle').innerHTML = '<i class="fas fa-edit"></i> دەستکاریکردن';
    document.getElementById('submitText').textContent = 'نوێکردنەوە';
    document.getElementById('sortOrderGroup').style.display = 'block';
    document.getElementById('cancelBtn').style.display = 'block';
    document.getElementById('reasonText').focus();
}

function resetForm() {
    document.getElementById('reasonForm').reset();
    document.getElementById('formAction').value = 'add';
    document.getElementById('reasonId').value = '';
    document.getElementById('formTitle').innerHTML = '<i class="fas fa-plus-circle"></i> زیادکردنی هۆکار';
    document.getElementById('submitText').textContent = 'زیادکردن';
    document.getElementById('sortOrderGroup').style.display = 'none';
    document.getElementById('cancelBtn').style.display = 'none';
}
</script>

<style>
.page-grid {
    max-width: 1200px;
}

@media (max-width: 900px) {
    .page-grid {
        grid-template-columns: 1fr !important;
    }
}
</style>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
