<?php
/**
 * Doctor Schedule Management Page
 * Manage working hours, breaks, and exceptions
 * سیستەمی زیرەکی کلینیکی ددان
 */

$pageTitle = 'ڕێکخستنی کاتەکانی دکتۆر';
$breadcrumb = [['title' => 'ڕێکخستنی کاتەکانی دکتۆر']];

// Role gate BEFORE header output, so the 403 redirect actually works
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../classes/Auth.php';
$__gate = new Auth();
$__gate->requireLogin();
if (!$__gate->isAdmin() && !$__gate->isReceptionist() && !$__gate->isDoctor()) {
    header('Location: ' . APP_URL . '/403.php');
    exit;
}

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../classes/DoctorSchedule.php';

// Check access - Admin, Receptionist, or Doctor
if (!$auth->isAdmin() && !$auth->isReceptionist() && !$auth->isDoctor()) {
    header('Location: ' . APP_URL . '/403.php');
    exit;
}

$scheduleManager = new DoctorSchedule();
$isAdmin = $auth->isAdmin();
$isReceptionist = $auth->isReceptionist();
$hasFullAccess = $isAdmin || $isReceptionist; // Receptionist sees all like admin

// Get doctor ID - admin/receptionist can select, doctor sees own
if ($hasFullAccess) {
    $doctors = $auth->getDoctors();
    $selectedDoctorId = intval($_GET['doctor_id'] ?? ($doctors[0]['id'] ?? 0));
} else {
    $doctors = [];
    $selectedDoctorId = $currentUser['id'];
}

// Get schedule data for selected doctor
$schedule = $scheduleManager->getSchedule($selectedDoctorId);
$breaks = $scheduleManager->getBreaks($selectedDoctorId);
$exceptions = $scheduleManager->getExceptions($selectedDoctorId, date('Y-m-01'), date('Y-m-t', strtotime('+3 months')));

// Day names for display
$dayNames = $scheduleManager->getOrderedDayNames();
?>

<style>
/* Schedule Management Styles */
.schedule-container {
    display: grid;
    grid-template-columns: 1fr 320px;
    gap: 16px;
}

@media (max-width: 1024px) {
    .schedule-container {
        grid-template-columns: 1fr;
    }
}

/* Doctor Selector */
.doctor-selector {
    background: linear-gradient(135deg, #0078d4 0%, #005a9e 100%);
    border-radius: 12px;
    padding: 16px 20px;
    margin-bottom: 16px;
    display: flex;
    align-items: center;
    gap: 16px;
    color: white;
}

.doctor-selector label {
    font-weight: 600;
    font-size: 14px;
}

.doctor-selector select {
    padding: 10px 16px;
    border-radius: 8px;
    border: none;
    font-size: 14px;
    font-family: inherit;
    min-width: 200px;
    background: white;
    cursor: pointer;
}

/* Schedule Grid */
.schedule-grid {
    background: white;
    border-radius: 12px;
    border: 1px solid #e0e0e0;
    overflow: hidden;
}

.schedule-header {
    background: linear-gradient(135deg, #0078d4 0%, #005a9e 100%);
    color: white;
    padding: 14px 20px;
    font-weight: 600;
    font-size: 15px;
    display: flex;
    align-items: center;
    gap: 10px;
}

.schedule-body {
    padding: 0;
}

.schedule-row {
    display: grid;
    grid-template-columns: 120px 80px 1fr 1fr auto;
    gap: 12px;
    padding: 12px 16px;
    border-bottom: 1px solid #f0f0f0;
    align-items: center;
    transition: background 0.2s;
}

.schedule-row:hover {
    background: #f8f9fa;
}

.schedule-row:last-child {
    border-bottom: none;
}

.schedule-row.off-day {
    background: #fff5f5;
}

.day-name {
    font-weight: 700;
    color: #2c3e50;
    font-size: 14px;
}

.day-name.friday {
    color: #e53935;
}

/* Toggle Switch */
.toggle-switch {
    position: relative;
    width: 50px;
    height: 26px;
}

.toggle-switch input {
    opacity: 0;
    width: 0;
    height: 0;
}

.toggle-slider {
    position: absolute;
    cursor: pointer;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background-color: #ccc;
    transition: 0.3s;
    border-radius: 26px;
}

.toggle-slider:before {
    position: absolute;
    content: "";
    height: 20px;
    width: 20px;
    left: 3px;
    bottom: 3px;
    background-color: white;
    transition: 0.3s;
    border-radius: 50%;
}

.toggle-switch input:checked + .toggle-slider {
    background-color: #4caf50;
}

.toggle-switch input:checked + .toggle-slider:before {
    transform: translateX(24px);
}

/* Time Inputs */
.time-input {
    padding: 8px 12px;
    border: 1.5px solid #e0e0e0;
    border-radius: 8px;
    font-size: 14px;
    font-family: var(--app-font-family);
    width: 100%;
    text-align: center;
    transition: all 0.2s;
}

.time-input:focus {
    border-color: #0078d4;
    box-shadow: 0 0 0 3px rgba(0, 120, 212, 0.1);
    outline: none;
}

.time-input:disabled {
    background: #f5f5f5;
    color: #9e9e9e;
    cursor: not-allowed;
}

/* Breaks Panel */
.breaks-panel {
    background: white;
    border-radius: 12px;
    border: 1px solid #e0e0e0;
    overflow: hidden;
    margin-bottom: 16px;
}

.breaks-header {
    background: linear-gradient(135deg, #ff9800 0%, #f57c00 100%);
    color: white;
    padding: 12px 16px;
    font-weight: 600;
    font-size: 14px;
    display: flex;
    align-items: center;
    justify-content: space-between;
}

.breaks-body {
    padding: 12px;
    max-height: 300px;
    overflow-y: auto;
}

.break-item {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 10px 12px;
    background: #fff3e0;
    border-radius: 8px;
    margin-bottom: 8px;
    font-size: 13px;
}

.break-item:last-child {
    margin-bottom: 0;
}

.break-day {
    background: #ff9800;
    color: white;
    padding: 2px 8px;
    border-radius: 4px;
    font-size: 11px;
    font-weight: 600;
}

.break-time {
    font-weight: 600;
    color: #e65100;
    font-family: var(--app-font-family);
}

.break-name {
    flex: 1;
    color: #5d4037;
}

.break-delete {
    background: none;
    border: none;
    color: #f44336;
    cursor: pointer;
    padding: 4px;
    border-radius: 4px;
    transition: background 0.2s;
}

.break-delete:hover {
    background: #ffebee;
}

/* Exceptions Panel */
.exceptions-panel {
    background: white;
    border-radius: 12px;
    border: 1px solid #e0e0e0;
    overflow: hidden;
}

.exceptions-header {
    background: linear-gradient(135deg, #9c27b0 0%, #7b1fa2 100%);
    color: white;
    padding: 12px 16px;
    font-weight: 600;
    font-size: 14px;
    display: flex;
    align-items: center;
    justify-content: space-between;
}

.exceptions-body {
    padding: 12px;
    max-height: 300px;
    overflow-y: auto;
}

.exception-item {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 10px 12px;
    background: #f3e5f5;
    border-radius: 8px;
    margin-bottom: 8px;
    font-size: 13px;
}

.exception-item.day-off {
    background: #ffebee;
}

.exception-date {
    font-weight: 700;
    color: #7b1fa2;
    font-family: var(--app-font-family);
}

.exception-type {
    padding: 2px 8px;
    border-radius: 4px;
    font-size: 11px;
    font-weight: 600;
}

.exception-type.off {
    background: #f44336;
    color: white;
}

.exception-type.custom {
    background: #2196f3;
    color: white;
}

.exception-reason {
    flex: 1;
    color: #5d4037;
    font-size: 12px;
}

.exception-delete {
    background: none;
    border: none;
    color: #f44336;
    cursor: pointer;
    padding: 4px;
    border-radius: 4px;
    transition: background 0.2s;
}

/* Add Button */
.btn-add-small {
    padding: 4px 10px;
    font-size: 12px;
    border-radius: 6px;
    background: rgba(255, 255, 255, 0.2);
    color: white;
    border: 1px solid rgba(255, 255, 255, 0.3);
    cursor: pointer;
    transition: all 0.2s;
}

.btn-add-small:hover {
    background: rgba(255, 255, 255, 0.3);
}

/* Save Button */
.save-schedule-btn {
    background: linear-gradient(135deg, #4caf50 0%, #388e3c 100%);
    color: white;
    border: none;
    padding: 12px 24px;
    border-radius: 8px;
    font-size: 14px;
    font-weight: 600;
    cursor: pointer;
    display: flex;
    align-items: center;
    gap: 8px;
    transition: all 0.2s;
    margin-top: 16px;
}

.save-schedule-btn:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(76, 175, 80, 0.3);
}

/* Empty State */
.empty-state-small {
    text-align: center;
    padding: 20px;
    color: #9e9e9e;
    font-size: 13px;
}

/* Dialog Styles */
.dialog-schedule {
    max-width: 400px;
}

.dialog-schedule .form-group {
    margin-bottom: 16px;
}

.dialog-schedule .form-label {
    display: block;
    margin-bottom: 6px;
    font-weight: 600;
    font-size: 13px;
    color: #2c3e50;
}

.dialog-schedule .form-control {
    width: 100%;
    padding: 10px 14px;
    border: 1.5px solid #e0e0e0;
    border-radius: 8px;
    font-size: 14px;
    font-family: inherit;
}

.dialog-schedule .form-control:focus {
    border-color: #0078d4;
    box-shadow: 0 0 0 3px rgba(0, 120, 212, 0.1);
    outline: none;
}

/* ──────────────────────────────────────────────────────────
   Responsive Layer
   ────────────────────────────────────────────────────────── */

/* Tablet (768–1024px) */
@media (min-width: 768px) and (max-width: 1024px) {
    .schedule-row {
        grid-template-columns: 100px 60px 1fr 1fr auto;
        gap: 8px;
        padding: 10px 12px;
    }
    .day-name { font-size: 13px; }
    .time-input { padding: 7px 8px; font-size: 13px; }
}

/* Mobile (≤767px) */
@media (max-width: 767px) {
    /* Doctor selector — label + select stack */
    .doctor-selector {
        flex-direction: column;
        align-items: stretch;
        gap: 8px;
        padding: 12px;
    }
    .doctor-selector label { font-size: 13px; }
    .doctor-selector select {
        width: 100%;
        min-width: 0;
        font-size: 16px; /* prevent iOS zoom */
        padding: 10px 12px;
        min-height: 44px;
    }

    /* Schedule grid: 2-row layout per day
       Row 1: day name + toggle + break button (header)
       Row 2: start/end time inputs */
    .schedule-row {
        grid-template-columns: 1fr auto auto;
        grid-template-areas:
            "name toggle break"
            "start start end";
        gap: 8px 10px;
        padding: 10px 12px;
    }
    .schedule-row > .day-name { grid-area: name; align-self: center; }
    .schedule-row > .toggle-switch { grid-area: toggle; }
    .schedule-row > .btn-add-small { grid-area: break; }
    .schedule-row > input[type="time"]:nth-of-type(1) { grid-area: start; }
    .schedule-row > input[type="time"]:nth-of-type(2) { grid-area: end; }

    .day-name { font-size: 14px; }
    .time-input {
        font-size: 16px; /* prevent iOS zoom */
        min-height: 42px;
        padding: 8px 10px;
    }
    .btn-add-small {
        min-width: 38px;
        min-height: 38px;
        padding: 6px 10px;
        font-size: 13px;
    }
    .schedule-header { padding: 12px; font-size: 14px; }

    /* Breaks / exceptions panels */
    .breaks-header,
    .exceptions-header {
        padding: 10px 12px;
        font-size: 13px;
    }
    .breaks-body,
    .exceptions-body {
        max-height: none; /* let the page scroll, not the panel */
        padding: 10px;
    }
    .break-item,
    .exception-item {
        flex-wrap: wrap;
        gap: 6px;
        font-size: 12px;
        padding: 8px 10px;
    }

    /* Save button — full width, larger tap */
    .save-schedule-btn {
        width: 100%;
        justify-content: center;
        padding: 14px 16px;
        font-size: 15px;
        min-height: 48px;
    }

    /* Schedule dialog */
    .dialog-schedule {
        max-width: calc(100vw - 16px);
    }
    .dialog-schedule .form-control {
        font-size: 16px;
        min-height: 44px;
    }
}

/* Small mobile (≤480px) */
@media (max-width: 480px) {
    /* Schedule row collapses to 3 stacked sections */
    .schedule-row {
        grid-template-columns: 1fr auto;
        grid-template-areas:
            "name toggle"
            "start start"
            "end end"
            "break break";
        gap: 6px 10px;
        padding: 10px;
    }
    .schedule-row > .btn-add-small {
        justify-self: stretch;
        width: 100%;
    }
    .day-name { font-size: 13px; }
}
</style>

<?php if (isset($_GET['saved'])): ?>
<div class="alert alert-success" data-auto-hide><i class="fas fa-check-circle alert-icon"></i> کاتەکان پاشەکەوتکران</div>
<?php endif; ?>

<!-- Doctor Selector (Admin/Receptionist) -->
<?php if ($hasFullAccess && count($doctors) > 0): ?>
<div class="doctor-selector">
    <label><i class="fas fa-user-md"></i> دکتۆر هەڵبژێرە:</label>
    <select id="doctorSelect" onchange="location='?doctor_id='+this.value">
        <?php foreach ($doctors as $doc): ?>
        <option value="<?= $doc['id'] ?>" <?= $selectedDoctorId == $doc['id'] ? 'selected' : '' ?>>
            د. <?= htmlspecialchars($doc['full_name']) ?>
        </option>
        <?php endforeach; ?>
    </select>
</div>
<?php endif; ?>

<div class="schedule-container">
    <!-- Main Schedule Grid -->
    <div>
        <form id="scheduleForm">
            <input type="hidden" name="doctor_id" value="<?= $selectedDoctorId ?>">

            <div class="schedule-grid">
                <div class="schedule-header">
                    <i class="fas fa-calendar-week"></i>
                    کاتی کارکردنی هەفتانە
                </div>
                <div class="schedule-body">
                    <?php foreach ($schedule as $day): ?>
                    <div class="schedule-row <?= !$day['is_working_day'] ? 'off-day' : '' ?>" data-day="<?= $day['day_of_week'] ?>">
                        <div class="day-name <?= $day['day_of_week'] == 5 ? 'friday' : '' ?>">
                            <?= $day['day_name'] ?>
                        </div>
                        <label class="toggle-switch">
                            <input type="checkbox"
                                   name="days[<?= $day['day_of_week'] ?>][is_working]"
                                   <?= $day['is_working_day'] ? 'checked' : '' ?>
                                   onchange="toggleDay(this, <?= $day['day_of_week'] ?>)">
                            <span class="toggle-slider"></span>
                        </label>
                        <input type="time"
                               class="time-input"
                               name="days[<?= $day['day_of_week'] ?>][start]"
                               value="<?= $day['start_time'] ? substr($day['start_time'], 0, 5) : '09:00' ?>"
                               <?= !$day['is_working_day'] ? 'disabled' : '' ?>>
                        <input type="time"
                               class="time-input"
                               name="days[<?= $day['day_of_week'] ?>][end]"
                               value="<?= $day['end_time'] ? substr($day['end_time'], 0, 5) : '21:00' ?>"
                               <?= !$day['is_working_day'] ? 'disabled' : '' ?>>
                        <button type="button" class="btn-add-small" style="background: #ff9800; border-color: #ff9800;"
                                onclick="openAddBreakDialog(<?= $day['day_of_week'] ?>, '<?= $day['day_name'] ?>')"
                                <?= !$day['is_working_day'] ? 'disabled' : '' ?>>
                            <i class="fas fa-coffee"></i>
                        </button>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <button type="submit" class="save-schedule-btn">
                <i class="fas fa-save"></i>
                پاشەکەوتکردن
            </button>
        </form>
    </div>

    <!-- Side Panels -->
    <div>
        <!-- Breaks Panel -->
        <div class="breaks-panel">
            <div class="breaks-header">
                <span><i class="fas fa-coffee"></i> کاتەکانی پشوو</span>
                <button class="btn-add-small" onclick="openAddBreakDialog()">
                    <i class="fas fa-plus"></i> زیادکردن
                </button>
            </div>
            <div class="breaks-body" id="breaksList">
                <?php if (empty($breaks)): ?>
                <div class="empty-state-small">
                    <i class="fas fa-coffee" style="font-size: 24px; margin-bottom: 8px; display: block;"></i>
                    هیچ کاتی پشووێک نییە
                </div>
                <?php else: ?>
                <?php foreach ($breaks as $break): ?>
                <div class="break-item" data-break-id="<?= $break['id'] ?>">
                    <span class="break-day"><?= $dayNames[$break['day_of_week']] ?></span>
                    <span class="break-time"><?= substr($break['break_start'], 0, 5) ?> - <?= substr($break['break_end'], 0, 5) ?></span>
                    <span class="break-name"><?= htmlspecialchars($break['break_name']) ?></span>
                    <button class="break-delete" onclick="deleteBreak(<?= $break['id'] ?>)" title="سڕینەوە">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
                <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- Exceptions Panel -->
        <div class="exceptions-panel">
            <div class="exceptions-header">
                <span><i class="fas fa-calendar-times"></i> ڕۆژە تایبەتەکان</span>
                <button class="btn-add-small" onclick="openAddExceptionDialog()">
                    <i class="fas fa-plus"></i> زیادکردن
                </button>
            </div>
            <div class="exceptions-body" id="exceptionsList">
                <?php if (empty($exceptions)): ?>
                <div class="empty-state-small">
                    <i class="fas fa-calendar-check" style="font-size: 24px; margin-bottom: 8px; display: block;"></i>
                    هیچ ڕۆژی تایبەتێک نییە
                </div>
                <?php else: ?>
                <?php foreach ($exceptions as $exc): ?>
                <div class="exception-item <?= $exc['exception_type'] === 'off' ? 'day-off' : '' ?>" data-exception-id="<?= $exc['id'] ?>">
                    <span class="exception-date"><?= $exc['exception_date'] ?></span>
                    <span class="exception-type <?= $exc['exception_type'] ?>">
                        <?= $exc['exception_type'] === 'off' ? 'پشوو' : 'تایبەت' ?>
                    </span>
                    <?php if ($exc['exception_type'] === 'custom'): ?>
                    <span class="break-time"><?= substr($exc['start_time'], 0, 5) ?> - <?= substr($exc['end_time'], 0, 5) ?></span>
                    <?php endif; ?>
                    <span class="exception-reason"><?= htmlspecialchars($exc['reason'] ?? '') ?></span>
                    <button class="exception-delete" onclick="deleteException(<?= $exc['id'] ?>)" title="سڕینەوە">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
                <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Add Break Dialog -->
<div class="dialog-overlay" id="addBreakDialog">
    <div class="dialog dialog-schedule">
        <div class="dialog-header" style="background: linear-gradient(135deg, #ff9800, #f57c00); color: white;">
            <h4 class="dialog-title" style="color: white;"><i class="fas fa-coffee"></i> زیادکردنی کاتی پشوو</h4>
            <button class="dialog-close" onclick="closeDialog('addBreakDialog')" style="color: white;"><i class="fas fa-times"></i></button>
        </div>
        <form id="addBreakForm">
            <input type="hidden" name="doctor_id" value="<?= $selectedDoctorId ?>">
            <div class="dialog-body">
                <div class="form-group">
                    <label class="form-label">ڕۆژ</label>
                    <select name="day_of_week" id="breakDaySelect" class="form-control" required>
                        <?php foreach ($dayNames as $dayNum => $dayName): ?>
                        <option value="<?= $dayNum ?>"><?= $dayName ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
                    <div class="form-group">
                        <label class="form-label">دەستپێکردن</label>
                        <input type="time" name="break_start" class="form-control" value="12:00" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">کۆتایی</label>
                        <input type="time" name="break_end" class="form-control" value="13:00" required>
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">ناوی پشوو</label>
                    <input type="text" name="break_name" class="form-control" value="نان" placeholder="نموونە: نان، نوێژ">
                </div>
            </div>
            <div class="dialog-footer">
                <button type="button" class="btn btn-secondary" onclick="closeDialog('addBreakDialog')">هەڵوەشاندنەوە</button>
                <button type="submit" class="btn btn-primary">زیادکردن</button>
            </div>
        </form>
    </div>
</div>

<!-- Add Exception Dialog -->
<div class="dialog-overlay" id="addExceptionDialog">
    <div class="dialog dialog-schedule">
        <div class="dialog-header" style="background: linear-gradient(135deg, #9c27b0, #7b1fa2); color: white;">
            <h4 class="dialog-title" style="color: white;"><i class="fas fa-calendar-times"></i> زیادکردنی ڕۆژی تایبەت</h4>
            <button class="dialog-close" onclick="closeDialog('addExceptionDialog')" style="color: white;"><i class="fas fa-times"></i></button>
        </div>
        <form id="addExceptionForm">
            <input type="hidden" name="doctor_id" value="<?= $selectedDoctorId ?>">
            <div class="dialog-body">
                <div class="form-group">
                    <label class="form-label">بەروار</label>
                    <input type="date" name="exception_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
                </div>
                <div class="form-group">
                    <label class="form-label">جۆر</label>
                    <select name="exception_type" id="exceptionTypeSelect" class="form-control" onchange="toggleExceptionTimes()" required>
                        <option value="off">ڕۆژی پشوو (نەخۆشی، پشوو، هتد)</option>
                        <option value="custom">کاتی تایبەت (ساعاتی جیاواز)</option>
                    </select>
                </div>
                <div id="exceptionTimesDiv" style="display: none;">
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
                        <div class="form-group">
                            <label class="form-label">دەستپێکردن</label>
                            <input type="time" name="start_time" class="form-control" value="10:00">
                        </div>
                        <div class="form-group">
                            <label class="form-label">کۆتایی</label>
                            <input type="time" name="end_time" class="form-control" value="14:00">
                        </div>
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">هۆکار (ئارەزوومەندانە)</label>
                    <input type="text" name="reason" class="form-control" placeholder="نموونە: پشووی فەرمی، نەخۆشی">
                </div>
            </div>
            <div class="dialog-footer">
                <button type="button" class="btn btn-secondary" onclick="closeDialog('addExceptionDialog')">هەڵوەشاندنەوە</button>
                <button type="submit" class="btn btn-primary">زیادکردن</button>
            </div>
        </form>
    </div>
</div>

<script>
const API_URL = '<?= APP_URL ?>/api/schedule.php';
const doctorId = <?= $selectedDoctorId ?>;
const dayNames = <?= json_encode($dayNames) ?>;

// Toggle day on/off
function toggleDay(checkbox, dayNum) {
    const row = checkbox.closest('.schedule-row');
    const timeInputs = row.querySelectorAll('.time-input');
    const breakBtn = row.querySelector('.btn-add-small');

    if (checkbox.checked) {
        row.classList.remove('off-day');
        timeInputs.forEach(input => {
            input.disabled = false;
            if (!input.value) {
                input.value = input.name.includes('start') ? '09:00' : '21:00';
            }
        });
        if (breakBtn) breakBtn.disabled = false;
    } else {
        row.classList.add('off-day');
        timeInputs.forEach(input => input.disabled = true);
        if (breakBtn) breakBtn.disabled = true;
    }
}

// Save schedule
document.getElementById('scheduleForm').addEventListener('submit', async function(e) {
    e.preventDefault();

    const formData = new FormData(this);
    const schedule = [];

    // Build schedule data
    for (let day = 0; day <= 6; day++) {
        const isWorking = formData.get(`days[${day}][is_working]`) === 'on';
        schedule.push({
            day_of_week: day,
            is_working_day: isWorking ? 1 : 0,
            start_time: isWorking ? formData.get(`days[${day}][start]`) + ':00' : null,
            end_time: isWorking ? formData.get(`days[${day}][end]`) + ':00' : null
        });
    }

    try {
        const response = await fetch(API_URL, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `action=save_schedule&doctor_id=${doctorId}&schedule=${encodeURIComponent(JSON.stringify(schedule))}`
        });

        const result = await response.json();

        if (result.success) {
            location.href = '?doctor_id=' + doctorId + '&saved=1';
        } else {
            alert('هەڵە: ' + (result.message || 'نەتوانرا پاشەکەوت بکرێت'));
        }
    } catch (error) {
        console.error('Error:', error);
        alert('هەڵەیەک ڕوویدا');
    }
});

// Open add break dialog
function openAddBreakDialog(dayNum, dayName) {
    if (dayNum !== undefined) {
        document.getElementById('breakDaySelect').value = dayNum;
    }
    openDialog('addBreakDialog');
}

// Add break
document.getElementById('addBreakForm').addEventListener('submit', async function(e) {
    e.preventDefault();

    const formData = new FormData(this);
    formData.append('action', 'add_break');

    try {
        const response = await fetch(API_URL, {
            method: 'POST',
            body: new URLSearchParams(formData)
        });

        const result = await response.json();

        if (result.success) {
            location.reload();
        } else {
            alert('هەڵە: ' + (result.message || 'نەتوانرا زیاد بکرێت'));
        }
    } catch (error) {
        console.error('Error:', error);
        alert('هەڵەیەک ڕوویدا');
    }
});

// Delete break
async function deleteBreak(breakId) {
    if (!confirm('دڵنیایت لە سڕینەوەی ئەم پشووە؟')) return;

    try {
        const response = await fetch(API_URL, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `action=delete_break&break_id=${breakId}`
        });

        const result = await response.json();

        if (result.success) {
            document.querySelector(`[data-break-id="${breakId}"]`).remove();
        } else {
            alert('هەڵە: ' + (result.message || 'نەتوانرا سڕدرێتەوە'));
        }
    } catch (error) {
        console.error('Error:', error);
        alert('هەڵەیەک ڕوویدا');
    }
}

// Toggle exception times visibility
function toggleExceptionTimes() {
    const type = document.getElementById('exceptionTypeSelect').value;
    document.getElementById('exceptionTimesDiv').style.display = type === 'custom' ? 'block' : 'none';
}

// Open add exception dialog
function openAddExceptionDialog() {
    openDialog('addExceptionDialog');
    toggleExceptionTimes();
}

// Add exception
document.getElementById('addExceptionForm').addEventListener('submit', async function(e) {
    e.preventDefault();

    const formData = new FormData(this);
    formData.append('action', 'add_exception');

    try {
        const response = await fetch(API_URL, {
            method: 'POST',
            body: new URLSearchParams(formData)
        });

        const result = await response.json();

        if (result.success) {
            location.reload();
        } else {
            alert('هەڵە: ' + (result.message || 'نەتوانرا زیاد بکرێت'));
        }
    } catch (error) {
        console.error('Error:', error);
        alert('هەڵەیەک ڕوویدا');
    }
});

// Delete exception
async function deleteException(exceptionId) {
    if (!confirm('دڵنیایت لە سڕینەوەی ئەم ڕۆژە تایبەتە؟')) return;

    try {
        const response = await fetch(API_URL, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `action=delete_exception&exception_id=${exceptionId}`
        });

        const result = await response.json();

        if (result.success) {
            document.querySelector(`[data-exception-id="${exceptionId}"]`).remove();
        } else {
            alert('هەڵە: ' + (result.message || 'نەتوانرا سڕدرێتەوە'));
        }
    } catch (error) {
        console.error('Error:', error);
        alert('هەڵەیەک ڕوویدا');
    }
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
