/*
 * Notifications performance — badge counts had grown into the slowest query
 * on every page load.
 *
 * The table accumulated ~1,400 rows/day since 2026-07-10 and nothing ever
 * pruned it (Notification::cleanupExpired() existed but was never called).
 * At 31,273 rows the two badge counts in includes/header.php plus the 10s
 * poll in api/notifications.php were costing 50-115 ms each.
 *
 * Two separate problems, both fixed here:
 *
 * 1. Every count filters "(expires_at IS NULL OR expires_at > NOW())", but
 *    expires_at was not in idx_user_unread / idx_user_unseen. MySQL matched
 *    the index then did a row lookup per candidate to read expires_at —
 *    thousands of random reads for users holding ~3,500 unread rows.
 *    The replacement indexes carry expires_at, so the counts are answered
 *    from the index alone.
 *
 * 2. generateInstallmentReminders()'s throttle query filtered on `type`,
 *    which had no index at all: a full 30k-row scan on every single poll.
 *    idx_notif_type_created covers it (the PHP side is made sargable in the
 *    same change).
 */

/* Drop the 74% of rows that are already expired. These are invisible to the
   application either way — every read path filters them out — so this only
   removes dead weight the counts were still walking through. */
DELETE FROM notifications WHERE expires_at IS NOT NULL AND expires_at < NOW();

/* Covering indexes for the unread/unseen badge counts. */
ALTER TABLE notifications ADD INDEX idx_notif_user_read_exp (user_id, is_read, expires_at);
ALTER TABLE notifications ADD INDEX idx_notif_user_seen_exp (user_id, is_seen, expires_at);

/* Covers the daily-reminder throttle and the "new since last check" poll. */
ALTER TABLE notifications ADD INDEX idx_notif_type_created (type, created_at);
ALTER TABLE notifications ADD INDEX idx_notif_user_created (user_id, created_at);

/* The old indexes are now strict left-prefixes of the new ones — redundant
   for reads, pure write cost on every insert. */
ALTER TABLE notifications DROP INDEX idx_user_unread;
ALTER TABLE notifications DROP INDEX idx_user_unseen;
ALTER TABLE notifications DROP INDEX idx_notifications_user_id;
