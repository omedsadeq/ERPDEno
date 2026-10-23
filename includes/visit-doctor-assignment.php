<?php
// $assignmentSourceSelection is supplied by the create/edit form.
?>
<div class="form-group" style="margin-bottom:14px;">
    <label for="doctor_assignment_source"><span class="required">*</span> چۆن دکتۆر دیاریکراوە؟</label>
    <select id="doctor_assignment_source" name="doctor_assignment_source" aria-describedby="doctor-assignment-help" required>
        <option value="">هەڵبژاردن...</option>
        <option value="requested" <?= $assignmentSourceSelection === 'requested' ? 'selected' : '' ?>>داواکراوی نەخۆش</option>
        <option value="reception" <?= $assignmentSourceSelection === 'reception' ? 'selected' : '' ?>>گواستنەوەی پێشوازی / دابەشکردن بۆ دکتۆر</option>
    </select>
    <small id="doctor-assignment-help" style="display:block;color:#607d8b;margin-top:5px;">ئەگەر نەخۆش دکتۆرێکی دیاریکراوی داوا کردووە، «داواکراوی نەخۆش» هەڵبژێرە. ئەگەر پێشوازی دکتۆر دیاری دەکات یان دەیگۆڕێت، «گواستنەوەی پێشوازی» هەڵبژێرە و دکتۆری بینراو دیاری بکە.</small>
    <?php foreach (['doctor_assignment_source', 'requested_doctor_id', 'assigned_doctor_id'] as $assignmentErrorKey): ?>
        <?php if (!empty($errors[$assignmentErrorKey])): ?>
            <div role="alert" style="color:#c62828;margin-top:5px;"><?= htmlspecialchars($errors[$assignmentErrorKey], ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>
    <?php endforeach; ?>
</div>
<script src="<?= APP_URL ?>/assets/js/visit-doctor-assignment.js?v=20261020"></script>
