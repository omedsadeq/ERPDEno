# Database Backup & Restore Module

Complete backup/restore system for the dentHisab database (MySQL & MariaDB).
Works on XAMPP / WAMP / Laragon / cPanel / shared hosting (InfinityFree) / VPS —
it auto-detects `mysqldump` and falls back to a pure-PHP engine when shell
commands are disabled.

## Files

| File | Purpose |
|---|---|
| `classes/BackupManager.php` | Backup engine (mysqldump + PHP fallback), verify, retention, uploads |
| `classes/RestoreManager.php` | Streaming restore, pre-restore backup, maintenance mode |
| `api/backup.php` | AJAX endpoint (admin only, CSRF-protected) |
| `admin/backups.php` | Admin UI — create / download / verify / restore / delete / settings |
| `admin/backup-download.php` | Protected download (by history id only, audited) |
| `cron/backup-cron.php` | Scheduled backup runner (CLI or web with secret token) |
| `database/migrations/2026_07_10_backup_module.sql` | `backup_history` table + settings seeds (auto-applied) |
| `storage/backups/` | Backup files — blocked from the web by `.htaccess` (auto-created) |

Also touched: `config/config.php` (maintenance-mode gate), `includes/sidebar.php`
(menu link بەڕێوەبردن → باکئەپی داتابەیس).

## Setup

Nothing to install. The migration runs automatically on the next page load
(MigrationRunner). Credentials come from the existing `config.php` / `.env`
constants — nothing is hard-coded.

On deploy to InfinityFree remember to bump `CACHE_VERSION` in `sw.js` as usual.
`ext-zlib` is required (always present); `ext-zip` only for the .zip format.

## Features

- **Types:** full / structure-only / data-only / selected tables
- **Covers:** tables, data, indexes, FKs, AUTO_INCREMENT, views (dependency-ordered),
  triggers, procedures, functions, events, charset/collation
- **Formats:** `.sql`, `.sql.gz` (default), `.zip`
- **Large DBs:** streamed row batching (~800 KB multi-row INSERTs), unbuffered
  reads, progress bar via polled progress files (session lock released first)
- **Integrity:** SHA-256 checksum stored per backup; *Verify* re-checks size,
  checksum, full readability (gzip CRC) and the end-of-dump marker
- **utf8mb4:** Kurdish/Arabic/emoji verified byte-identical; binary columns are
  hex-encoded; `DEFINER` clauses stripped for cross-host restores
- **Safe restore:** typed `RESTORE` confirmation, automatic pre-restore backup,
  maintenance mode (non-admins get 503; auto-clears after 1 h if a restore
  crashes), FK checks disabled during restore, migrations lock invalidated after
- **Security:** admin-only, CSRF on every mutating action, uploads validated by
  extension + magic bytes, downloads only by history id (no paths from users),
  `storage/backups/` denied by `.htaccess`, everything audit-logged to `activity_log`
- **Scheduling:** daily/weekly/monthly at a set time, retention by count and age,
  in-app notifications to admins on success/failure

## Cron setup

The runner exits instantly when nothing is due — schedule it every 15–30 min.

**Linux/VPS (crontab -e):**
```
*/20 * * * * php /path/to/dentHisab/cron/backup-cron.php >/dev/null 2>&1
```

**cPanel:** Cron Jobs → Add: `php /home/USER/public_html/dentHisab/cron/backup-cron.php`

**Shared hosting without cron (InfinityFree) / cron-job.org:** call the URL shown
on the admin page (Schedule tab):
```
https://YOUR-SITE/cron/backup-cron.php?token=SECRET
```
The token can be rotated from the admin page. `--force` / `&force=1` runs
immediately regardless of schedule.

**Windows Task Scheduler (local XAMPP):**
```
schtasks /Create /SC MINUTE /MO 20 /TN "dentHisab backup" /TR "C:\xampp\php\php.exe C:\xampp\htdocs\dentHisab\cron\backup-cron.php"
```

## Testing performed (2026-07-10, local XAMPP)

- PHP engine: full/structure/data/selected-tables in all three formats — verified ✔
- mysqldump engine: auto-fallback to PHP engine confirmed when the client can't
  connect (XAMPP's MariaDB `mysqldump.exe` vs MySQL 8 `caching_sha2_password`)
- Full restore round-trip into a scratch DB: 75/75 tables, all row counts equal,
  127 FKs, 2 views, Kurdish text byte-identical
- Synthetic objects round-trip (zip): trigger fires, function computes, event +
  dependent views recreated, emoji + raw binary byte-identical
- Web cron: 403 on bad token, 200 on real token; maintenance gate: 503 for
  non-admins, login.php reachable, auto-recovery on flag removal

## Manual test steps

1. Log in as admin → sidebar بەڕێوەبردن → **باکئەپی داتابەیس**.
2. Create a full gzip backup → watch the progress bar → row appears in history.
3. Click the shield icon → all five verify checks must be green.
4. Download the file; upload it back on the گەڕاندنەوە tab.
5. Restore it (type `RESTORE`, keep both checkboxes on) → page reloads after success,
   a `pre_restore` backup row appears.
6. Enable the schedule, set a time 2 minutes ahead, run the cron command → file
   appears with type «خۆکار», admins get a notification.
