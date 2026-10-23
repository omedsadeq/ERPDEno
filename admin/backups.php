<?php
/**
 * Database Backup & Restore Page (Admin Only)
 * باکئەپ و گەڕاندنەوەی داتابەیس
 */

$pageTitle = 'باکئەپی داتابەیس';
$breadcrumb = [['title' => 'باکئەپی داتابەیس']];

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../classes/Auth.php';
require_once __DIR__ . '/../classes/Database.php';
require_once __DIR__ . '/../classes/BackupManager.php';
require_once __DIR__ . '/../classes/RestoreManager.php';

$auth = new Auth();
$auth->requireAdmin();

$backupManager = new BackupManager();
$capabilities  = $backupManager->capabilities();
$settings      = $backupManager->getSettings();
$activeTab     = preg_replace('/[^a-z_]/', '', $_GET['tab'] ?? 'backups') ?: 'backups';

$cronUrl = APP_URL . '/cron/backup-cron.php?token=' . urlencode($settings['backup_cron_token']);
$cronCli = 'php ' . str_replace('\\', '/', APP_ROOT) . '/cron/backup-cron.php';

require_once __DIR__ . '/../includes/header.php';
?>

<style>
.bk-panel { background:#fff; border-radius:10px; overflow:hidden; box-shadow:0 2px 10px rgba(0,0,0,.08); border:1px solid #e8e8e8; }
.bk-tabs { display:flex; gap:4px; background:linear-gradient(to bottom,#f8f9fa,#f1f3f5); padding:8px; border-bottom:2px solid #e0e0e0; }
.bk-tab { flex:1; padding:10px 16px; background:transparent; border:none; border-radius:6px; font-size:13px; font-weight:600; color:#546e7a; cursor:pointer; transition:all .2s; display:flex; align-items:center; justify-content:center; gap:7px; font-family:inherit; }
.bk-tab:hover { background:rgba(0,120,212,.08); color:#0078D4; }
.bk-tab.active { background:#0078D4; color:#fff; box-shadow:0 2px 6px rgba(0,120,212,.35); }
.bk-tab-content { display:none; padding:18px; }
.bk-tab-content.active { display:block; }

.bk-stats { display:grid; grid-template-columns:repeat(auto-fit,minmax(170px,1fr)); gap:12px; margin-bottom:16px; }
.bk-stat { background:#fff; border:1px solid #e8e8e8; border-radius:10px; padding:14px 16px; display:flex; align-items:center; gap:12px; box-shadow:0 1px 4px rgba(0,0,0,.05); }
.bk-stat-icon { width:42px; height:42px; border-radius:9px; display:flex; align-items:center; justify-content:center; font-size:18px; color:#fff; flex-shrink:0; }
.bk-stat-value { font-size:17px; font-weight:700; color:#222; }
.bk-stat-label { font-size:11.5px; color:#777; }

.bk-caps { display:flex; flex-wrap:wrap; gap:8px; margin-bottom:14px; }
.bk-cap { font-size:11.5px; padding:5px 11px; border-radius:20px; display:inline-flex; align-items:center; gap:6px; }
.bk-cap.ok { background:#e7f6ec; color:#107c41; }
.bk-cap.warn { background:#fdf3e7; color:#b25e09; }

.bk-form-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(210px,1fr)); gap:14px; margin-bottom:14px; }
.bk-field label { display:block; font-size:12.5px; font-weight:600; color:#444; margin-bottom:5px; }
.bk-field select, .bk-field input[type=text], .bk-field input[type=time], .bk-field input[type=number], .bk-field input[type=file] {
  width:100%; padding:8px 10px; border:1px solid #d5d5d5; border-radius:6px; font-size:13px; font-family:inherit; background:#fff; }
.bk-field select:focus, .bk-field input:focus { outline:none; border-color:#0078D4; box-shadow:0 0 0 2px rgba(0,120,212,.15); }

.bk-tables-box { max-height:230px; overflow:auto; border:1px solid #e0e0e0; border-radius:8px; padding:10px; display:none; margin-bottom:14px; background:#fafafa; }
.bk-tables-box.visible { display:grid; grid-template-columns:repeat(auto-fill,minmax(220px,1fr)); gap:4px; }
.bk-table-item { display:flex; align-items:center; gap:7px; font-size:12.5px; padding:4px 6px; border-radius:5px; }
.bk-table-item:hover { background:#eef6fd; }
.bk-table-item .meta { color:#999; font-size:11px; margin-right:auto; }

.bk-btn { border:none; border-radius:6px; padding:9px 18px; font-size:13px; font-weight:600; cursor:pointer; display:inline-flex; align-items:center; gap:7px; font-family:inherit; transition:all .15s; }
.bk-btn:disabled { opacity:.55; cursor:not-allowed; }
.bk-btn-primary { background:#0078D4; color:#fff; } .bk-btn-primary:hover:not(:disabled) { background:#106ebe; }
.bk-btn-danger { background:#d83b01; color:#fff; } .bk-btn-danger:hover:not(:disabled) { background:#b83301; }
.bk-btn-light { background:#f1f3f5; color:#333; border:1px solid #ddd; } .bk-btn-light:hover:not(:disabled) { background:#e7eaee; }
.bk-btn-sm { padding:5px 9px; font-size:12px; border-radius:5px; }

.bk-progress { display:none; margin:14px 0; background:#f4f9fe; border:1px solid #cde5f7; border-radius:8px; padding:13px 15px; }
.bk-progress.visible { display:block; }
.bk-progress-msg { font-size:12.5px; color:#0b5394; margin-bottom:8px; display:flex; justify-content:space-between; }
.bk-progress-bar { height:9px; background:#dbe9f5; border-radius:6px; overflow:hidden; }
.bk-progress-fill { height:100%; width:0%; background:linear-gradient(90deg,#0078D4,#4aa3e8); border-radius:6px; transition:width .5s ease; }

.bk-table { width:100%; border-collapse:collapse; font-size:12.5px; }
.bk-table th { background:#f7f8fa; color:#555; font-weight:700; text-align:right; padding:9px 10px; border-bottom:2px solid #e5e5e5; white-space:nowrap; }
.bk-table td { padding:8px 10px; border-bottom:1px solid #f0f0f0; vertical-align:middle; }
.bk-table tr:hover td { background:#fafcff; }
.bk-badge { display:inline-block; padding:3px 9px; border-radius:12px; font-size:11px; font-weight:600; }
.bk-badge.completed { background:#e7f6ec; color:#107c41; }
.bk-badge.failed { background:#fde7e9; color:#c42b1c; }
.bk-badge.running { background:#fff4ce; color:#9d5d00; }
.bk-badge.deleted { background:#f1f1f1; color:#888; }
.bk-badge.type { background:#eef2fa; color:#3b5bab; }
.bk-mono { font-family:Consolas,monospace; font-size:11px; color:#888; direction:ltr; display:inline-block; }
.bk-actions { display:flex; gap:5px; justify-content:flex-start; }
.bk-empty { text-align:center; color:#999; padding:34px 10px; font-size:13px; }

.bk-cron-box { background:#f8f9fb; border:1px solid #e3e6ea; border-radius:8px; padding:13px 15px; margin-top:10px; }
.bk-cron-box code { display:block; direction:ltr; text-align:left; background:#23292f; color:#8fd3ff; padding:9px 12px; border-radius:6px; font-size:12px; overflow-x:auto; margin:7px 0; font-family:Consolas,monospace; white-space:nowrap; }
.bk-note { font-size:12px; color:#777; line-height:1.9; }
.bk-switch-row { display:flex; align-items:center; gap:10px; padding:8px 0; }

@media (max-width:640px) {
  .bk-tab { font-size:12px; padding:9px 6px; }
  .bk-tab .tab-txt { display:none; }
  .bk-actions { flex-wrap:wrap; }
  .bk-table-wrap { overflow-x:auto; }
}
</style>

<!-- Stats -->
<div class="bk-stats">
  <div class="bk-stat"><div class="bk-stat-icon" style="background:#0078D4"><i class="fas fa-database"></i></div>
    <div><div class="bk-stat-value" id="statCount">—</div><div class="bk-stat-label">کۆی باکئەپەکان</div></div></div>
  <div class="bk-stat"><div class="bk-stat-icon" style="background:#107c41"><i class="fas fa-hdd"></i></div>
    <div><div class="bk-stat-value" id="statSize">—</div><div class="bk-stat-label">قەبارەی گشتی</div></div></div>
  <div class="bk-stat"><div class="bk-stat-icon" style="background:#8661c5"><i class="fas fa-clock"></i></div>
    <div><div class="bk-stat-value" id="statLast" style="font-size:14px">—</div><div class="bk-stat-label">دوایین باکئەپ</div></div></div>
  <div class="bk-stat"><div class="bk-stat-icon" style="background:#b25e09"><i class="fas fa-robot"></i></div>
    <div><div class="bk-stat-value" id="statAuto" style="font-size:14px">—</div><div class="bk-stat-label">باکئەپی خۆکار</div></div></div>
</div>

<div class="bk-panel">
  <div class="bk-tabs">
    <button class="bk-tab <?= $activeTab === 'backups' ? 'active' : '' ?>" data-tab="backups"><i class="fas fa-database"></i><span class="tab-txt">باکئەپەکان</span></button>
    <button class="bk-tab <?= $activeTab === 'restore' ? 'active' : '' ?>" data-tab="restore"><i class="fas fa-undo-alt"></i><span class="tab-txt">گەڕاندنەوە</span></button>
    <button class="bk-tab <?= $activeTab === 'schedule' ? 'active' : '' ?>" data-tab="schedule"><i class="fas fa-calendar-check"></i><span class="tab-txt">خشتە و ڕێکخستن</span></button>
  </div>

  <!-- ══ Tab 1: create + history ══ -->
  <div class="bk-tab-content <?= $activeTab === 'backups' ? 'active' : '' ?>" id="tab-backups">

    <div class="bk-caps" id="capsRow">
      <?php if ($capabilities['mysqldump_path']): ?>
        <span class="bk-cap ok"><i class="fas fa-check-circle"></i> mysqldump بەردەستە</span>
      <?php else: ?>
        <span class="bk-cap warn"><i class="fas fa-info-circle"></i> mysqldump بەردەست نییە — بزوێنەری PHP بەکاردێت</span>
      <?php endif; ?>
      <span class="bk-cap ok"><i class="fas fa-server"></i> <?= htmlspecialchars($capabilities['server_version']) ?></span>
      <span class="bk-cap ok"><i class="fas fa-table"></i> <?= htmlspecialchars($capabilities['db_name']) ?></span>
      <?php if (!$capabilities['zip_available']): ?>
        <span class="bk-cap warn"><i class="fas fa-info-circle"></i> ext-zip نییە — gzip بەکاربهێنە</span>
      <?php endif; ?>
    </div>

    <div class="bk-form-grid">
      <div class="bk-field">
        <label><i class="fas fa-layer-group"></i> جۆری باکئەپ</label>
        <select id="bkType">
          <option value="full">تەواو (پێکهاتە + داتا)</option>
          <option value="structure">تەنها پێکهاتە</option>
          <option value="data">تەنها داتا</option>
          <option value="tables">خشتە هەڵبژێردراوەکان</option>
        </select>
      </div>
      <div class="bk-field">
        <label><i class="fas fa-compress-alt"></i> پەستاندن</label>
        <select id="bkCompression">
          <option value="gzip">gzip ‏(.sql.gz)</option>
          <?php if ($capabilities['zip_available']): ?><option value="zip">zip ‏(.zip)</option><?php endif; ?>
          <option value="none">بێ پەستاندن (.sql)</option>
        </select>
      </div>
      <div class="bk-field">
        <label><i class="fas fa-cogs"></i> بزوێنەر</label>
        <select id="bkMethod">
          <option value="auto">خۆکار (باشترین بەردەست)</option>
          <?php if ($capabilities['mysqldump_path']): ?><option value="mysqldump">mysqldump</option><?php endif; ?>
          <option value="php">PHP (بۆ هۆستی هاوبەش)</option>
        </select>
      </div>
      <div class="bk-field" style="display:flex;align-items:flex-end">
        <button class="bk-btn bk-btn-primary" id="btnCreate" style="width:100%;justify-content:center">
          <i class="fas fa-plus-circle"></i> دروستکردنی باکئەپ
        </button>
      </div>
    </div>

    <div class="bk-tables-box" id="tablesBox"></div>

    <div class="bk-progress" id="createProgress">
      <div class="bk-progress-msg"><span id="createMsg">...</span><span id="createPct">0%</span></div>
      <div class="bk-progress-bar"><div class="bk-progress-fill" id="createFill"></div></div>
    </div>

    <div class="bk-table-wrap">
      <table class="bk-table">
        <thead><tr>
          <th>#</th><th>فایل</th><th>جۆر</th><th>بزوێنەر</th><th>قەبارە</th>
          <th>بەروار</th><th>بەکارهێنەر</th><th>دۆخ</th><th>SHA-256</th><th>کردارەکان</th>
        </tr></thead>
        <tbody id="historyBody"><tr><td colspan="10" class="bk-empty"><i class="fas fa-spinner fa-spin"></i> باردەکرێت...</td></tr></tbody>
      </table>
    </div>
  </div>

  <!-- ══ Tab 2: restore ══ -->
  <div class="bk-tab-content <?= $activeTab === 'restore' ? 'active' : '' ?>" id="tab-restore">
    <div style="background:#fdf3e7;border:1px solid #f0d9b8;border-radius:8px;padding:12px 15px;margin-bottom:16px;font-size:12.5px;color:#8a5a0b;line-height:1.9">
      <i class="fas fa-exclamation-triangle"></i>
      <strong>ئاگاداری:</strong> گەڕاندنەوە هەموو داتای ئێستای داتابەیس دەگۆڕێت بە ناوەڕۆکی فایلی باکئەپەکە.
      پێش گەڕاندنەوە باکئەپێکی خۆکاری پاراستن دەگیرێت و سیستەم بۆ بەکارهێنەرانی تر دادەخرێت تا تەواو دەبێت.
    </div>

    <h4 style="font-size:14px;margin:0 0 10px;color:#333"><i class="fas fa-upload"></i> بارکردنی فایلی باکئەپ</h4>
    <div class="bk-form-grid" style="grid-template-columns:1fr auto">
      <div class="bk-field">
        <input type="file" id="uploadFile" accept=".sql,.gz,.zip">
        <div class="bk-note">فایلی ڕێگەپێدراو: .sql ، .sql.gz ، .zip — دوای بارکردن لە لیستی باکئەپەکان دەردەکەوێت و لەوێوە دەگەڕێندرێتەوە.</div>
      </div>
      <div style="display:flex;align-items:flex-start">
        <button class="bk-btn bk-btn-light" id="btnUpload"><i class="fas fa-cloud-upload-alt"></i> بارکردن</button>
      </div>
    </div>

    <div class="bk-progress" id="restoreProgress">
      <div class="bk-progress-msg"><span id="restoreMsg">...</span><span id="restorePct">0%</span></div>
      <div class="bk-progress-bar"><div class="bk-progress-fill" id="restoreFill" style="background:linear-gradient(90deg,#d83b01,#f2865e)"></div></div>
    </div>

    <div class="bk-note" style="margin-top:14px">
      <i class="fas fa-info-circle"></i>
      بۆ گەڕاندنەوە، لە خشتەی باکئەپەکان دوگمەی <span style="color:#d83b01"><i class="fas fa-undo-alt"></i></span> بەکاربهێنە.
      دوای گەڕاندنەوە پەڕەکە خۆکارانە نوێ دەبێتەوە.
    </div>
  </div>

  <!-- ══ Tab 3: schedule + retention ══ -->
  <div class="bk-tab-content <?= $activeTab === 'schedule' ? 'active' : '' ?>" id="tab-schedule">
    <div class="bk-switch-row">
      <label style="font-size:13.5px;font-weight:700;color:#333;display:flex;align-items:center;gap:8px;cursor:pointer">
        <input type="checkbox" id="setEnabled" style="width:17px;height:17px"> چالاککردنی باکئەپی خۆکار
      </label>
    </div>

    <div class="bk-form-grid">
      <div class="bk-field">
        <label>ماوە</label>
        <select id="setFrequency">
          <option value="daily">ڕۆژانە</option>
          <option value="weekly">هەفتانە</option>
          <option value="monthly">مانگانە</option>
        </select>
      </div>
      <div class="bk-field">
        <label>کاتژمێر</label>
        <input type="time" id="setTime" value="02:00">
      </div>
      <div class="bk-field" id="rowWeekday" style="display:none">
        <label>ڕۆژی هەفتە</label>
        <select id="setWeekday">
          <option value="0">یەکشەممە</option><option value="1">دووشەممە</option>
          <option value="2">سێشەممە</option><option value="3">چوارشەممە</option>
          <option value="4">پێنجشەممە</option><option value="5">هەینی</option>
          <option value="6">شەممە</option>
        </select>
      </div>
      <div class="bk-field" id="rowMonthday" style="display:none">
        <label>ڕۆژی مانگ (١–٢٨)</label>
        <input type="number" id="setMonthday" min="1" max="28" value="1">
      </div>
      <div class="bk-field">
        <label>جۆری باکئەپ</label>
        <select id="setType">
          <option value="full">تەواو</option>
          <option value="structure">تەنها پێکهاتە</option>
          <option value="data">تەنها داتا</option>
        </select>
      </div>
      <div class="bk-field">
        <label>پەستاندن</label>
        <select id="setCompression">
          <option value="gzip">gzip</option>
          <?php if ($capabilities['zip_available']): ?><option value="zip">zip</option><?php endif; ?>
          <option value="none">هیچ</option>
        </select>
      </div>
    </div>

    <h4 style="font-size:14px;margin:16px 0 10px;color:#333"><i class="fas fa-broom"></i> هەڵگرتن (Retention)</h4>
    <div class="bk-form-grid">
      <div class="bk-field">
        <label>زۆرترین ژمارەی باکئەپ (٠ = بێسنوور)</label>
        <input type="number" id="setRetCount" min="0" max="500" value="10">
      </div>
      <div class="bk-field">
        <label>سڕینەوەی کۆنتر لە (ڕۆژ، ٠ = هەرگیز)</label>
        <input type="number" id="setRetDays" min="0" max="3650" value="30">
      </div>
      <div class="bk-field" style="display:flex;align-items:flex-end">
        <button class="bk-btn bk-btn-light" id="btnRetentionNow" style="width:100%;justify-content:center">
          <i class="fas fa-broom"></i> جێبەجێکردنی هەڵگرتن ئێستا
        </button>
      </div>
    </div>

    <div class="bk-switch-row">
      <label style="font-size:13px;color:#333;display:flex;align-items:center;gap:8px;cursor:pointer">
        <input type="checkbox" id="setNotify" style="width:16px;height:16px"> ئاگادارکردنەوەی بەڕێوەبەران لە سەرکەوتن/شکستی باکئەپی خۆکار
      </label>
    </div>

    <div style="margin-top:14px">
      <button class="bk-btn bk-btn-primary" id="btnSaveSettings"><i class="fas fa-save"></i> پاشەکەوتکردنی ڕێکخستنەکان</button>
    </div>

    <div class="bk-cron-box">
      <div style="font-size:13px;font-weight:700;color:#333;margin-bottom:4px"><i class="fas fa-terminal"></i> ڕێکخستنی Cron</div>
      <div class="bk-note">ئەم فەرمانە هەر ١٥–٣٠ خولەک جارێک ڕایبکە؛ خۆی دەزانێت کەی کاتی باکئەپە:</div>
      <code id="cronCliCmd"><?= htmlspecialchars($cronCli) ?></code>
      <div class="bk-note">یان بۆ هۆستی هاوبەش (cPanel → Cron Jobs یان خزمەتگوزاری وەک cron-job.org) ئەم لینکە بەکاربهێنە:</div>
      <code id="cronWebUrl"><?= htmlspecialchars($cronUrl) ?></code>
      <div style="display:flex;gap:8px;margin-top:6px">
        <button class="bk-btn bk-btn-light bk-btn-sm" id="btnCopyCron"><i class="fas fa-copy"></i> کۆپیکردنی لینک</button>
        <button class="bk-btn bk-btn-light bk-btn-sm" id="btnRegenToken"><i class="fas fa-sync-alt"></i> گۆڕینی تۆکن</button>
      </div>
      <div class="bk-note" style="margin-top:6px">دوایین باکئەپی خۆکار: <span id="lastAutoRun">—</span></div>
    </div>
  </div>
</div>

<script>
(function () {
  'use strict';
  var API = '<?= APP_URL ?>/api/backup.php';
  var DOWNLOAD = '<?= APP_URL ?>/admin/backup-download.php';
  var busy = false;
  var tablesLoaded = false;

  function $(id) { return document.getElementById(id); }
  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }
  function post(action, data) {
    var fd = data instanceof FormData ? data : new FormData();
    if (!(data instanceof FormData) && data) {
      Object.keys(data).forEach(function (k) {
        if (Array.isArray(data[k])) { data[k].forEach(function (v) { fd.append(k + '[]', v); }); }
        else { fd.append(k, data[k]); }
      });
    }
    fd.append('action', action);
    return fetch(API, { method: 'POST', body: fd }).then(function (r) { return r.json(); });
  }
  function get(qs) {
    return fetch(API + '?' + qs).then(function (r) { return r.json(); });
  }
  function toast(icon, title) {
    Swal.fire({ toast: true, position: 'top', icon: icon, title: title, showConfirmButton: false, timer: 2800 });
  }
  function newToken() {
    var bytes = new Uint8Array(16);
    (window.crypto || window.msCrypto).getRandomValues(bytes);
    return Array.prototype.map.call(bytes, function (b) { return ('0' + b.toString(16)).slice(-2); }).join('');
  }

  // ── Tabs ──
  document.querySelectorAll('.bk-tab').forEach(function (btn) {
    btn.addEventListener('click', function () {
      document.querySelectorAll('.bk-tab').forEach(function (b) { b.classList.remove('active'); });
      document.querySelectorAll('.bk-tab-content').forEach(function (c) { c.classList.remove('active'); });
      btn.classList.add('active');
      $('tab-' + btn.dataset.tab).classList.add('active');
      history.replaceState(null, '', '?tab=' + btn.dataset.tab);
    });
  });

  // ── History + stats ──
  function typeLabel(t) {
    return { full: 'تەواو', structure: 'پێکهاتە', data: 'داتا', tables: 'هەڵبژێردراو' }[t] || t;
  }
  function statusLabel(s) {
    return { completed: 'تەواو', failed: 'شکستخواردوو', running: 'کاردەکات', deleted: 'سڕاوەتەوە' }[s] || s;
  }
  function triggerLabel(t) {
    return { manual: '', scheduled: ' (خۆکار)', pre_restore: ' (پێش گەڕاندنەوە)', upload: ' (بارکراو)' }[t] || '';
  }

  function loadHistory() {
    get('action=history').then(function (res) {
      if (!res.success) { return; }
      $('statCount').textContent = res.stats.total;
      $('statSize').textContent = humanSize(res.stats.total_size);
      $('statLast').textContent = res.stats.last_backup || 'هیچ';
      $('statAuto').textContent = res.settings.backup_auto_enabled === '1'
        ? ({ daily: 'ڕۆژانە', weekly: 'هەفتانە', monthly: 'مانگانە' }[res.settings.backup_auto_frequency] + ' ' + res.settings.backup_auto_time)
        : 'ناچالاکە';
      $('lastAutoRun').textContent = res.settings.backup_last_auto_run || 'هەرگیز';
      fillSettings(res.settings);
      renderHistory(res.history);
    }).catch(function () { toast('error', 'بارکردنی مێژوو سەرکەوتوو نەبوو'); });
  }

  function humanSize(b) {
    b = Number(b) || 0;
    var u = ['B', 'KB', 'MB', 'GB', 'TB'], i = 0;
    while (b >= 1024 && i < u.length - 1) { b /= 1024; i++; }
    return (i === 0 ? b : b.toFixed(1)) + ' ' + u[i];
  }

  function renderHistory(rows) {
    var tb = $('historyBody');
    if (!rows.length) {
      tb.innerHTML = '<tr><td colspan="10" class="bk-empty"><i class="far fa-folder-open"></i> هێشتا هیچ باکئەپێک نییە</td></tr>';
      return;
    }
    tb.innerHTML = rows.map(function (r) {
      var canFile = r.status === 'completed';
      var actions = '';
      if (canFile) {
        actions += '<a class="bk-btn bk-btn-light bk-btn-sm" title="داگرتن" href="' + DOWNLOAD + '?id=' + r.id + '"><i class="fas fa-download"></i></a>';
        actions += '<button class="bk-btn bk-btn-light bk-btn-sm" title="پشکنین" data-act="verify" data-id="' + r.id + '"><i class="fas fa-shield-alt"></i></button>';
        actions += '<button class="bk-btn bk-btn-light bk-btn-sm" title="گەڕاندنەوە" data-act="restore" data-id="' + r.id + '" data-file="' + esc(r.file_name) + '" style="color:#d83b01"><i class="fas fa-undo-alt"></i></button>';
      }
      if (r.status !== 'running' && r.status !== 'deleted') {
        actions += '<button class="bk-btn bk-btn-light bk-btn-sm" title="سڕینەوە" data-act="delete" data-id="' + r.id + '" style="color:#c42b1c"><i class="fas fa-trash-alt"></i></button>';
      }
      var err = r.status === 'failed' && r.error_message
        ? '<div style="color:#c42b1c;font-size:11px;margin-top:3px">' + esc(r.error_message) + '</div>' : '';
      return '<tr>'
        + '<td>' + r.id + '</td>'
        + '<td><span class="bk-mono" style="color:#333;font-size:11.5px">' + esc(r.file_name) + '</span>' + err + '</td>'
        + '<td><span class="bk-badge type">' + typeLabel(r.backup_type) + triggerLabel(r.trigger_source) + '</span></td>'
        + '<td>' + esc(r.method || '—') + '</td>'
        + '<td>' + (r.file_size > 0 ? r.file_size_human : '—') + '</td>'
        + '<td style="white-space:nowrap">' + esc(r.started_at) + '</td>'
        + '<td>' + esc(r.created_by_name || 'سیستەم') + '</td>'
        + '<td><span class="bk-badge ' + r.status + '">' + statusLabel(r.status) + '</span></td>'
        + '<td><span class="bk-mono" title="' + esc(r.checksum_sha256 || '') + '">' + (r.checksum_sha256 ? esc(r.checksum_sha256.slice(0, 12)) + '…' : '—') + '</span></td>'
        + '<td><div class="bk-actions">' + actions + '</div></td>'
        + '</tr>';
    }).join('');
  }

  $('historyBody').addEventListener('click', function (e) {
    var btn = e.target.closest('button[data-act]');
    if (!btn || busy) { return; }
    var id = btn.dataset.id;
    if (btn.dataset.act === 'verify') { verifyBackup(id); }
    if (btn.dataset.act === 'delete') { deleteBackup(id); }
    if (btn.dataset.act === 'restore') { restoreBackup(id, btn.dataset.file); }
  });

  // ── Selected-tables box ──
  $('bkType').addEventListener('change', function () {
    var box = $('tablesBox');
    if (this.value === 'tables') {
      box.classList.add('visible');
      if (!tablesLoaded) {
        get('action=tables').then(function (res) {
          if (!res.success) { return; }
          tablesLoaded = true;
          box.innerHTML = res.tables.filter(function (t) { return t.type === 'BASE TABLE'; }).map(function (t) {
            return '<label class="bk-table-item"><input type="checkbox" name="bkTables" value="' + esc(t.name) + '"> '
              + '<span>' + esc(t.name) + '</span>'
              + '<span class="meta">' + Number(t.approx_rows).toLocaleString() + ' ڕیز · ' + humanSize(t.size_bytes) + '</span></label>';
          }).join('');
        });
      }
    } else {
      box.classList.remove('visible');
    }
  });

  // ── Progress polling ──
  function pollProgress(token, msgEl, pctEl, fillEl, onDone) {
    var timer = setInterval(function () {
      get('action=progress&token=' + token).then(function (res) {
        var p = res.progress;
        if (!p) { return; }
        msgEl.textContent = p.message || '...';
        pctEl.textContent = p.percent + '%';
        fillEl.style.width = p.percent + '%';
        if (p.done) { clearInterval(timer); onDone(p); }
      }).catch(function () { /* keep polling; the long request may still finish */ });
    }, 900);
    return timer;
  }

  // ── Create backup ──
  $('btnCreate').addEventListener('click', function () {
    if (busy) { return; }
    var type = $('bkType').value;
    var tables = Array.prototype.map.call(document.querySelectorAll('input[name=bkTables]:checked'), function (c) { return c.value; });
    if (type === 'tables' && !tables.length) { toast('warning', 'هیچ خشتەیەک هەڵنەبژێردراوە'); return; }

    busy = true;
    $('btnCreate').disabled = true;
    var token = newToken();
    $('createProgress').classList.add('visible');
    $('createMsg').textContent = 'دەستپێکردن...';
    $('createFill').style.width = '0%';
    var timer = pollProgress(token, $('createMsg'), $('createPct'), $('createFill'), function () {});

    post('create', { type: type, compression: $('bkCompression').value, method: $('bkMethod').value, token: token, tables: tables })
      .then(function (res) {
        clearInterval(timer);
        busy = false;
        $('btnCreate').disabled = false;
        $('createProgress').classList.remove('visible');
        if (res.success) {
          toast('success', 'باکئەپ دروستکرا: ' + res.file_name);
        } else {
          Swal.fire({ icon: 'error', title: 'باکئەپ شکستی هێنا', text: res.message || '' });
        }
        loadHistory();
      })
      .catch(function () {
        clearInterval(timer);
        busy = false;
        $('btnCreate').disabled = false;
        $('createProgress').classList.remove('visible');
        Swal.fire({ icon: 'error', title: 'هەڵە', text: 'پەیوەندی بە سێرڤەر پچڕا' });
        loadHistory();
      });
  });

  // ── Verify ──
  function verifyBackup(id) {
    busy = true;
    Swal.fire({ title: 'پشکنین...', didOpen: function () { Swal.showLoading(); }, allowOutsideClick: false });
    post('verify', { id: id }).then(function (res) {
      busy = false;
      function row(ok, label) {
        return '<div style="display:flex;justify-content:space-between;padding:5px 0;border-bottom:1px solid #f0f0f0">'
          + '<span>' + label + '</span><span style="color:' + (ok ? '#107c41' : '#c42b1c') + '">'
          + (ok ? '✔ دروستە' : '✘ نادروستە') + '</span></div>';
      }
      Swal.fire({
        icon: res.success ? 'success' : 'error',
        title: res.success ? 'باکئەپەکە ساغە' : 'کێشە هەیە',
        html: '<div style="text-align:right;font-size:13px">'
          + row(res.file_exists, 'فایل لەسەر دیسک')
          + row(res.size_ok, 'قەبارە')
          + row(res.checksum_ok, 'SHA-256')
          + row(res.readable, 'خوێندنەوەی تەواو')
          + row(res.marker_ok, 'نیشانەی کۆتایی دەمپ')
          + (res.message ? '<div style="color:#c42b1c;margin-top:8px">' + esc(res.message) + '</div>' : '')
          + '</div>'
      });
    }).catch(function () { busy = false; toast('error', 'پشکنین سەرکەوتوو نەبوو'); });
  }

  // ── Delete ──
  function deleteBackup(id) {
    Swal.fire({
      icon: 'warning', title: 'سڕینەوەی باکئەپ؟',
      text: 'فایلەکە لەسەر دیسک دەسڕدرێتەوە. ناگەڕێتەوە.',
      showCancelButton: true, confirmButtonText: 'بسڕەوە', cancelButtonText: 'هەڵوەشاندنەوە',
      confirmButtonColor: '#c42b1c'
    }).then(function (r) {
      if (!r.isConfirmed) { return; }
      post('delete', { id: id }).then(function (res) {
        if (res.success) { toast('success', 'سڕایەوە'); } else { toast('error', res.message || 'شکستی هێنا'); }
        loadHistory();
      });
    });
  }

  // ── Restore ──
  function restoreBackup(id, fileName) {
    Swal.fire({
      icon: 'warning',
      title: 'گەڕاندنەوەی داتابەیس',
      html: '<div style="text-align:right;font-size:13px;line-height:2">'
        + '<div style="background:#fde7e9;color:#c42b1c;border-radius:6px;padding:8px 10px;margin-bottom:10px">'
        + 'هەموو داتای ئێستا دەگۆڕدرێت بە ناوەڕۆکی:<br><b style="direction:ltr;display:inline-block">' + esc(fileName) + '</b></div>'
        + '<label style="display:flex;gap:7px;align-items:center"><input type="checkbox" id="swPreBackup" checked> باکئەپی پاراستن پێش گەڕاندنەوە (پێشنیارکراو)</label>'
        + '<label style="display:flex;gap:7px;align-items:center"><input type="checkbox" id="swMaint" checked> داخستنی سیستەم بۆ بەکارهێنەران لە کاتی گەڕاندنەوە</label>'
        + '<div style="margin-top:10px">بۆ دڵنیابوونەوە بنووسە: <b>RESTORE</b></div>'
        + '<input type="text" id="swConfirm" class="swal2-input" style="direction:ltr;margin:6px 0 0" placeholder="RESTORE">'
        + '</div>',
      showCancelButton: true,
      confirmButtonText: 'گەڕاندنەوە',
      cancelButtonText: 'هەڵوەشاندنەوە',
      confirmButtonColor: '#d83b01',
      preConfirm: function () {
        if (document.getElementById('swConfirm').value.trim().toUpperCase() !== 'RESTORE') {
          Swal.showValidationMessage('دەبێت وشەی RESTORE بنووسیت');
          return false;
        }
        return {
          pre: document.getElementById('swPreBackup').checked ? '1' : '0',
          maint: document.getElementById('swMaint').checked ? '1' : '0'
        };
      }
    }).then(function (r) {
      if (!r.isConfirmed) { return; }
      busy = true;
      // switch to the restore tab so the progress bar is visible
      document.querySelector('.bk-tab[data-tab=restore]').click();
      var token = newToken();
      $('restoreProgress').classList.add('visible');
      $('restoreMsg').textContent = 'دەستپێکردن...';
      $('restoreFill').style.width = '0%';
      var timer = pollProgress(token, $('restoreMsg'), $('restorePct'), $('restoreFill'), function () {});

      post('restore', { id: id, confirm: 'RESTORE', pre_backup: r.value.pre, maintenance: r.value.maint, token: token })
        .then(function (res) {
          clearInterval(timer);
          busy = false;
          $('restoreProgress').classList.remove('visible');
          if (res.success) {
            Swal.fire({
              icon: 'success', title: 'گەڕاندنەوە تەواو بوو',
              text: res.statements + ' فەرمانی SQL جێبەجێکرا. پەڕەکە نوێ دەبێتەوە.',
              allowOutsideClick: false, confirmButtonText: 'باشە'
            }).then(function () { window.location.reload(); });
          } else {
            Swal.fire({ icon: 'error', title: 'گەڕاندنەوە شکستی هێنا', text: res.message || '' });
            loadHistory();
          }
        })
        .catch(function () {
          clearInterval(timer);
          busy = false;
          $('restoreProgress').classList.remove('visible');
          Swal.fire({ icon: 'error', title: 'هەڵە', text: 'پەیوەندی بە سێرڤەر پچڕا — دۆخی داتابەیس بپشکنە' });
        });
    });
  }

  // ── Upload ──
  $('btnUpload').addEventListener('click', function () {
    var f = $('uploadFile').files[0];
    if (!f) { toast('warning', 'فایلێک هەڵبژێرە'); return; }
    var fd = new FormData();
    fd.append('backup_file', f);
    $('btnUpload').disabled = true;
    post('upload', fd).then(function (res) {
      $('btnUpload').disabled = false;
      if (res.success) {
        toast('success', 'بارکرا: ' + res.file_name);
        $('uploadFile').value = '';
        loadHistory();
        document.querySelector('.bk-tab[data-tab=backups]').click();
      } else {
        Swal.fire({ icon: 'error', title: 'بارکردن شکستی هێنا', text: res.message || '' });
      }
    }).catch(function () {
      $('btnUpload').disabled = false;
      toast('error', 'بارکردن سەرکەوتوو نەبوو');
    });
  });

  // ── Settings ──
  function fillSettings(s) {
    $('setEnabled').checked = s.backup_auto_enabled === '1';
    $('setFrequency').value = s.backup_auto_frequency;
    $('setTime').value = s.backup_auto_time;
    $('setWeekday').value = s.backup_auto_weekday;
    $('setMonthday').value = s.backup_auto_monthday;
    $('setType').value = s.backup_auto_type;
    $('setCompression').value = s.backup_auto_compression;
    $('setRetCount').value = s.backup_retention_count;
    $('setRetDays').value = s.backup_retention_days;
    $('setNotify').checked = s.backup_notify === '1';
    toggleFreqRows();
  }
  function toggleFreqRows() {
    var f = $('setFrequency').value;
    $('rowWeekday').style.display = f === 'weekly' ? '' : 'none';
    $('rowMonthday').style.display = f === 'monthly' ? '' : 'none';
  }
  $('setFrequency').addEventListener('change', toggleFreqRows);

  $('btnSaveSettings').addEventListener('click', function () {
    var s = {
      'settings[backup_auto_enabled]': $('setEnabled').checked ? '1' : '0',
      'settings[backup_auto_frequency]': $('setFrequency').value,
      'settings[backup_auto_time]': $('setTime').value,
      'settings[backup_auto_weekday]': $('setWeekday').value,
      'settings[backup_auto_monthday]': $('setMonthday').value,
      'settings[backup_auto_type]': $('setType').value,
      'settings[backup_auto_compression]': $('setCompression').value,
      'settings[backup_retention_count]': $('setRetCount').value,
      'settings[backup_retention_days]': $('setRetDays').value,
      'settings[backup_notify]': $('setNotify').checked ? '1' : '0'
    };
    post('save_settings', s).then(function (res) {
      if (res.success) { toast('success', 'پاشەکەوتکرا'); loadHistory(); }
      else { toast('error', res.message || 'شکستی هێنا'); }
    });
  });

  $('btnRetentionNow').addEventListener('click', function () {
    post('apply_retention', {}).then(function (res) {
      toast(res.success ? 'success' : 'error', res.success ? (res.deleted + ' باکئەپی کۆن سڕایەوە') : 'شکستی هێنا');
      loadHistory();
    });
  });

  $('btnCopyCron').addEventListener('click', function () {
    var text = $('cronWebUrl').textContent;
    (navigator.clipboard ? navigator.clipboard.writeText(text) : Promise.reject()).then(
      function () { toast('success', 'کۆپیکرا'); },
      function () { toast('info', 'کۆپی خۆکار نەکرا — دەستی کۆپی بکە'); }
    );
  });

  $('btnRegenToken').addEventListener('click', function () {
    Swal.fire({
      icon: 'question', title: 'گۆڕینی تۆکنی Cron؟',
      text: 'لینکی کۆن ئیتر کار ناکات و دەبێت لە cron نوێی بکەیتەوە.',
      showCancelButton: true, confirmButtonText: 'بیگۆڕە', cancelButtonText: 'هەڵوەشاندنەوە'
    }).then(function (r) {
      if (!r.isConfirmed) { return; }
      post('regen_cron_token', {}).then(function (res) {
        if (res.success) {
          $('cronWebUrl').textContent = '<?= APP_URL ?>/cron/backup-cron.php?token=' + res.token;
          toast('success', 'تۆکن نوێکرایەوە');
        }
      });
    });
  });

  // init
  document.addEventListener('DOMContentLoaded', loadHistory);
  if (document.readyState !== 'loading') { loadHistory(); }
})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
