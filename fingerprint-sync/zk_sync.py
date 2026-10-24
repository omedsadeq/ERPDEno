"""
Lezan — fingerprint sync service (سەرچاوە مرۆییەکان).

Reads punches from the ZKTeco fingerprint device and writes them into Lezan's
hr_punches table, where the HR reports pick them up.

    device ──live──▶ local queue (queue.sqlite3) ──▶ Lezan database (hr_punches)

  * Real time: while connected, each punch arrives the moment a finger is
    placed (the device's live event stream).
  * Nothing is lost: every punch goes first into a local queue on disk, and
    only leaves it once Lezan's database has it. If the device or the network
    drops, the device keeps its own log; on reconnecting the whole log is read
    again and anything new is queued. If the database is down, the queue waits.
  * Always on: install.bat registers it with Windows Task Scheduler to start
    with the computer, plus a 5-minute watchdog that restarts it if it ever
    stops. A second copy never runs: the first one holds a local port.

Who: a punch's PIN is matched to the employee whose device_pin it is (or, for
a record without one, whose id it is). The device's user list (PIN + name) is
copied into hr_device_users, so hr/device-users.php can add people the
device knows and Lezan does not, with their device names, or link a PIN to an
existing employee.

IN or OUT: the device records every punch as "check-in", so — as the clinic's
data always did — the order decides: within one work day (08:00 → 08:00) the
1st punch is IN, the 2nd OUT, the 3rd IN, and so on. Two punches within
double_press_seconds count once. A PIN with no employee in Lezan waits in the
queue and goes in as soon as that employee is added with that PIN.

    python zk_sync.py            run (what the scheduled task does)
    python zk_sync.py --check    test the device and the database, then exit
    python zk_sync.py --once     read the device once, write, exit
"""

from __future__ import annotations

import argparse
import configparser
import datetime as dt
import logging
import socket
import sqlite3
import sys
import time
from logging.handlers import RotatingFileHandler
from pathlib import Path

import pymysql
from zk import ZK

VERSION = "1.0"
HERE = Path(__file__).resolve().parent
LEZAN_ROOT = HERE.parent
LOCK_PORT = 47011  # held by the running copy so a second one exits

log = logging.getLogger("zk_sync")


# ── Settings ───────────────────────────────────────────────────────────────

class Settings:
    def __init__(self, path: Path):
        cp = configparser.ConfigParser()
        # utf-8-sig: Notepad may save the file with a BOM.
        cp.read(path, encoding="utf-8-sig")
        g = lambda s, k, d="": (cp.get(s, k, fallback=d) or d).strip()

        self.device_name = g("device", "name", "Fingerprint")
        self.ip = g("device", "ip", "192.168.1.201")
        self.port = int(g("device", "port", "4370"))
        self.password = int(g("device", "password", "0") or 0)
        self.timeout = int(g("device", "timeout", "10"))
        self.sync_time = g("device", "sync_time", "yes").lower() in ("1", "yes", "true", "on")

        self.day_start_hour = int(g("rules", "day_start_hour", "8"))
        self.double_press = int(g("rules", "double_press_seconds", "60"))
        self.start_from = dt.datetime.strptime(g("rules", "start_from", "2026-10-01"), "%Y-%m-%d")

        self.health_seconds = int(g("sync", "health_seconds", "60"))
        self.reconcile_minutes = int(g("sync", "reconcile_minutes", "30"))
        self.unknown_retry_minutes = int(g("sync", "unknown_retry_minutes", "10"))

        env = read_env(LEZAN_ROOT / ".env")
        self.db = {
            "host": g("database", "host") or env.get("DB_HOST") or "localhost",
            "port": int(g("database", "port", "3306")),
            "database": g("database", "name") or env.get("DB_NAME") or "leza",
            "user": g("database", "user") or env.get("DB_USER") or "root",
            "password": g("database", "password") or env.get("DB_PASS") or "",
        }


def read_env(path: Path) -> dict:
    """KEY=VALUE lines of Lezan's .env, so the service uses the same database."""
    out = {}
    try:
        for line in path.read_text(encoding="utf-8-sig").splitlines():
            line = line.strip()
            if line and not line.startswith("#") and "=" in line:
                k, v = line.split("=", 1)
                out[k.strip()] = v.strip().strip('"').strip("'")
    except OSError:
        pass
    return out


# ── Local queue: nothing leaves it until Lezan has it ──────────────────────

class Queue:
    def __init__(self, path: Path):
        self.db = sqlite3.connect(str(path), timeout=30)
        self.db.execute("""CREATE TABLE IF NOT EXISTS punches (
            pin TEXT NOT NULL, ts TEXT NOT NULL, seen_at TEXT NOT NULL,
            status TEXT NOT NULL DEFAULT 'new',      -- new | done | unknown
            result TEXT, tries INTEGER NOT NULL DEFAULT 0, last_try TEXT,
            PRIMARY KEY (pin, ts))""")
        self.db.execute("CREATE INDEX IF NOT EXISTS idx_status ON punches (status, ts)")
        self.db.commit()

    def add(self, items) -> int:
        """Queue (pin, datetime) pairs; returns how many were new."""
        now = dt.datetime.now().strftime("%Y-%m-%d %H:%M:%S")
        before = self.db.total_changes
        self.db.executemany(
            "INSERT OR IGNORE INTO punches (pin, ts, seen_at) VALUES (?, ?, ?)",
            [(str(pin).strip(), ts.strftime("%Y-%m-%d %H:%M:%S"), now) for pin, ts in items])
        self.db.commit()
        return self.db.total_changes - before

    def due(self, retry_minutes: int, known_pins: set, limit: int = 1000) -> list:
        """
        Waiting punches in time order. A PIN that had no employee is tried
        again at once when Lezan now has that employee, else every
        retry_minutes.
        """
        cutoff = (dt.datetime.now() - dt.timedelta(minutes=retry_minutes)).strftime("%Y-%m-%d %H:%M:%S")
        pins = sorted(known_pins) or [""]
        marks = ",".join("?" * len(pins))
        return self.db.execute(
            f"""SELECT pin, ts FROM punches
                WHERE status = 'new'
                   OR (status = 'unknown' AND (pin IN ({marks}) OR last_try IS NULL OR last_try <= ?))
                ORDER BY ts, pin LIMIT ?""", (*pins, cutoff, limit)).fetchall()

    def mark(self, pin: str, ts: str, status: str, result: str) -> None:
        self.db.execute(
            "UPDATE punches SET status = ?, result = ?, tries = tries + 1, last_try = ? WHERE pin = ? AND ts = ?",
            (status, result, dt.datetime.now().strftime("%Y-%m-%d %H:%M:%S"), pin, ts))
        self.db.commit()

    def waiting(self) -> int:
        return self.db.execute("SELECT COUNT(*) FROM punches WHERE status = 'new'").fetchone()[0]

    def unknown_pins(self) -> list:
        return [r[0] for r in self.db.execute(
            "SELECT DISTINCT pin FROM punches WHERE status = 'unknown' ORDER BY pin").fetchall()]


# ── Lezan database ─────────────────────────────────────────────────────────

class Lezan:
    def __init__(self, s: Settings):
        self.s = s
        self.conn = None
        self.pins: dict = {}   # device PIN → hr_employees.id

    def connect(self):
        """The open connection, or a new one when it was lost (MySQL restarted …)."""
        if self.conn is not None:
            try:
                self.conn.ping()
                return self.conn
            except pymysql.MySQLError:
                self.close()
        self.conn = pymysql.connect(charset="utf8mb4", autocommit=True, connect_timeout=10, **self.s.db)
        return self.conn

    def close(self):
        try:
            if self.conn:
                self.conn.close()
        finally:
            self.conn = None

    def refresh_employees(self):
        """
        Which employee each device PIN belongs to: hr_employees.device_pin, or
        the employee's id when device_pin is empty (or before Lezan has the
        column). An explicit device_pin wins over an id that happens to match.
        """
        pins = {}
        with self.connect().cursor() as c:
            try:
                c.execute("SELECT id, device_pin FROM hr_employees")
                rows = c.fetchall()
            except pymysql.MySQLError as e:
                if e.args and e.args[0] != 1054:   # 1054: no device_pin column yet
                    raise
                c.execute("SELECT id, NULL FROM hr_employees")
                rows = c.fetchall()
        for emp_id, device_pin in rows:
            if not device_pin:
                pins.setdefault(str(emp_id), emp_id)
        for emp_id, device_pin in rows:
            if device_pin:
                pins[str(device_pin).strip()] = emp_id
        self.pins = pins

    def save_users(self, ip: str, serial: str | None, users) -> None:
        """The device's user list (PIN + name typed on it) into hr_device_users."""
        with self.connect().cursor() as c:
            for u in users:
                c.execute("""INSERT INTO hr_device_users (device_ip, device_serial, pin, name, uid, privilege, card, last_seen_at)
                             VALUES (%s, %s, %s, %s, %s, %s, %s, NOW())
                             ON DUPLICATE KEY UPDATE device_serial = VALUES(device_serial), name = VALUES(name), uid = VALUES(uid),
                                 privilege = VALUES(privilege), card = VALUES(card), last_seen_at = NOW()""",
                          (ip, serial, str(u.user_id).strip(), (u.name or "").strip() or None, u.uid, u.privilege, str(u.card or "") or None))
            pins = [str(u.user_id).strip() for u in users]
            if pins:
                marks = ",".join(["%s"] * len(pins))
                c.execute(f"DELETE FROM hr_device_users WHERE device_ip = %s AND pin NOT IN ({marks})", (ip, *pins))
            else:
                c.execute("DELETE FROM hr_device_users WHERE device_ip = %s", (ip,))

    def insert(self, pin: str, ts: dt.datetime, serial: str | None) -> str:
        """Write one punch. Returns in | out | duplicate | double | unknown."""
        emp = self.pins.get(pin.strip())
        if emp is None:
            return "unknown"
        c = self.connect().cursor()
        try:
            c.execute("SELECT 1 FROM hr_punches WHERE employee_id = %s AND punch_time = %s", (emp, ts))
            if c.fetchone():
                return "duplicate"
            gap = dt.timedelta(seconds=self.s.double_press)
            c.execute("SELECT 1 FROM hr_punches WHERE employee_id = %s AND punch_time BETWEEN %s AND %s LIMIT 1",
                      (emp, ts - gap, ts + gap))
            if c.fetchone():
                return "double"
            # The work day this punch belongs to, and how many punches it already has before it.
            work_date = (ts - dt.timedelta(hours=self.s.day_start_hour)).date()
            day_start = dt.datetime.combine(work_date, dt.time(self.s.day_start_hour))
            c.execute("SELECT COUNT(*) FROM hr_punches WHERE employee_id = %s AND punch_time >= %s AND punch_time < %s",
                      (emp, day_start, ts))
            ptype = "in" if c.fetchone()[0] % 2 == 0 else "out"
            c.execute("""INSERT IGNORE INTO hr_punches (employee_id, punch_time, punch_type, source, device_id)
                         VALUES (%s, %s, %s, 'device', %s)""", (emp, ts, ptype, serial))
            return ptype if c.rowcount else "duplicate"
        finally:
            c.close()

    def heartbeat(self, st: dict):
        with self.connect().cursor() as c:
            c.execute("""INSERT INTO hr_devices
                (ip, port, name, serial, model, is_connected, last_seen_at, last_connected_at, last_punch_at,
                 device_users, device_records, pending, unknown_pins, time_offset_seconds, last_error, service_version)
                VALUES (%(ip)s, %(port)s, %(name)s, %(serial)s, %(model)s, %(connected)s, NOW(), %(last_connected)s,
                        %(last_punch)s, %(users)s, %(records)s, %(pending)s, %(unknown)s, %(offset)s, %(error)s, %(version)s)
                ON DUPLICATE KEY UPDATE name = VALUES(name), serial = COALESCE(VALUES(serial), serial),
                    model = COALESCE(VALUES(model), model), is_connected = VALUES(is_connected), last_seen_at = NOW(),
                    last_connected_at = COALESCE(VALUES(last_connected_at), last_connected_at),
                    last_punch_at = NULLIF(GREATEST(COALESCE(last_punch_at, '1970-01-01 00:00:00'),
                                                    COALESCE(VALUES(last_punch_at), '1970-01-01 00:00:00')), '1970-01-01 00:00:00'),
                    device_users = COALESCE(VALUES(device_users), device_users),
                    device_records = COALESCE(VALUES(device_records), device_records),
                    pending = VALUES(pending), unknown_pins = VALUES(unknown_pins),
                    time_offset_seconds = COALESCE(VALUES(time_offset_seconds), time_offset_seconds),
                    last_error = VALUES(last_error), service_version = VALUES(service_version)""", st)
            # The device under another address: an old IP of this same device, or
            # an address that never reached any device (a wrong ip in config.ini,
            # left for 10 minutes). It would stay on the dashboard as "not running".
            c.execute("""DELETE FROM hr_devices WHERE NOT (ip = %(ip)s AND port = %(port)s)
                         AND (serial = %(serial)s OR (serial IS NULL AND last_seen_at < NOW() - INTERVAL 10 MINUTE))""", st)
            if st.get("serial"):
                c.execute("DELETE FROM hr_device_users WHERE device_serial = %s AND device_ip <> %s", (st["serial"], st["ip"]))


# ── The service ────────────────────────────────────────────────────────────

class Sync:
    def __init__(self, s: Settings):
        self.s = s
        self.queue = Queue(HERE / "queue.sqlite3")
        self.lezan = Lezan(s)
        self.state = {
            "ip": s.ip, "port": s.port, "name": s.device_name, "serial": None, "model": None,
            "connected": 0, "last_connected": None, "last_punch": None, "users": None, "records": None,
            "pending": 0, "unknown": None, "offset": None, "error": None, "version": VERSION,
        }
        self.known_records = None   # device log size after the last full read + punches seen live since
        self.known_users = None     # device user count when the user list was last read
        self.last_time_check = 0.0

    # Queue → Lezan ----------------------------------------------------------
    def flush(self) -> None:
        try:
            self.lezan.refresh_employees()
        except pymysql.MySQLError as e:
            log.warning("Lezan database not reachable, punches stay queued: %s", e)
            self.lezan.close()
            return
        rows = self.queue.due(self.s.unknown_retry_minutes, set(self.lezan.pins))
        if not rows:
            return
        try:
            counts = {}
            for pin, ts in rows:
                when = dt.datetime.strptime(ts, "%Y-%m-%d %H:%M:%S")
                result = self.lezan.insert(pin, when, self.state["serial"])
                self.queue.mark(pin, ts, "unknown" if result == "unknown" else "done", result)
                counts[result] = counts.get(result, 0) + 1
                if result in ("in", "out"):
                    log.info("PIN %s %s %s", pin, ts, "IN" if result == "in" else "OUT")
            if set(counts) - {"duplicate"}:
                log.info("written to Lezan: %s", counts)
            self.state["error"] = None if self.state["connected"] else self.state["error"]
        except pymysql.MySQLError as e:
            log.warning("Lezan database not reachable, punches stay queued: %s", e)
            self.lezan.close()

    def heartbeat(self) -> None:
        self.state["pending"] = self.queue.waiting()
        unknown = self.queue.unknown_pins()
        self.state["unknown"] = ",".join(unknown)[:255] or None
        try:
            self.lezan.heartbeat(self.state)
        except pymysql.MySQLError as e:
            log.warning("could not report status to Lezan: %s", e)
            self.lezan.close()

    # Device → queue ---------------------------------------------------------
    def read_all(self, conn) -> None:
        """The device's whole log, from start_from on, into the queue."""
        logs = conn.get_attendance() or []
        conn.read_sizes()
        self.state["users"], self.state["records"] = conn.users, conn.records
        self.known_records = conn.records
        self.read_users(conn)
        items = [(a.user_id, a.timestamp) for a in logs if a.timestamp >= self.s.start_from]
        new = self.queue.add(items)
        if new:
            log.info("read %d punches from the device, %d new", len(items), new)
            self.note_last_punch(max(ts for _, ts in items))
        self.flush()

    def read_users(self, conn) -> None:
        """
        The device's people (PIN + name) into Lezan, so hr/device-users.php can
        offer to add the ones Lezan does not know, with their device names.
        """
        users = conn.get_users() or []
        self.known_users = len(users)
        try:
            self.lezan.save_users(self.s.ip, self.state["serial"], users)
        except pymysql.MySQLError as e:
            log.warning("could not save the device's user list: %s", e)
            self.lezan.close()

    def note_last_punch(self, ts: dt.datetime) -> None:
        stamp = ts.strftime("%Y-%m-%d %H:%M:%S")
        if not self.state["last_punch"] or stamp > self.state["last_punch"]:
            self.state["last_punch"] = stamp

    def check_time(self, conn, force: bool = False) -> None:
        """Device clock vs this computer's; corrected when sync_time is on."""
        if not force and time.time() - self.last_time_check < 6 * 3600:
            return
        self.last_time_check = time.time()
        offset = round((conn.get_time() - dt.datetime.now()).total_seconds())
        self.state["offset"] = offset
        if abs(offset) > 60:
            if self.s.sync_time:
                conn.set_time(dt.datetime.now())
                log.warning("device clock was %+d s off - set to this computer's time", offset)
                self.state["offset"] = 0
            else:
                log.warning("device clock is %+d s off (sync_time = no)", offset)

    def session(self) -> None:
        """One connection: read everything, then follow punches live until it drops."""
        conn = ZK(self.s.ip, port=self.s.port, timeout=self.s.timeout, password=self.s.password,
                  force_udp=False, ommit_ping=True).connect()
        try:
            self.state["serial"] = conn.get_serialnumber()
            try:
                self.state["model"] = conn.get_device_name()
            except Exception:
                pass
            self.state["connected"] = 1
            self.state["error"] = None
            self.state["last_connected"] = dt.datetime.now().strftime("%Y-%m-%d %H:%M:%S")
            log.info("connected to %s %s (%s)", self.state["model"] or "device", self.state["serial"], self.s.ip)
            self.check_time(conn, force=True)
            self.read_all(conn)
            self.heartbeat()
            last_full = time.time()
            while True:
                seen = self.follow(conn)
                # Health check: a dropped connection fails here and reconnects.
                self.check_time(conn)
                conn.read_sizes()
                self.state["users"], self.state["records"] = conn.users, conn.records
                if conn.users != self.known_users:   # someone enrolled or removed on the device
                    log.info("device has %s users, was %s - reading the list", conn.users, self.known_users)
                    self.read_users(conn)
                if self.known_records is not None:
                    self.known_records += seen
                missed = conn.records != self.known_records
                if missed or time.time() - last_full >= self.s.reconcile_minutes * 60:
                    if missed:
                        log.info("device log is %s, expected %s - reading it all", conn.records, self.known_records)
                    self.read_all(conn)
                    last_full = time.time()
                self.flush()
                self.heartbeat()
        finally:
            self.state["connected"] = 0
            try:
                conn.disconnect()
            except Exception:
                pass

    def follow(self, conn) -> int:
        """Live punches for health_seconds; returns how many arrived."""
        seen = 0
        until = time.time() + self.s.health_seconds
        conn.end_live_capture = False
        for att in conn.live_capture(new_timeout=5):
            if att is not None:
                seen += 1
                if self.queue.add([(att.user_id, att.timestamp)]):
                    log.info("live punch: PIN %s at %s", att.user_id, att.timestamp)
                    self.note_last_punch(att.timestamp)
                    self.flush()
            if time.time() >= until:
                conn.end_live_capture = True   # the generator stops at its next turn
        return seen

    def run_forever(self) -> None:
        wait = 5
        while True:
            try:
                self.session()
                wait = 5
            except Exception as e:  # device off, cable out, network down …
                self.state["connected"] = 0
                self.state["error"] = (type(e).__name__ + ": " + str(e))[:255]
                log.warning("device %s: %s - retrying in %d s", self.s.ip, self.state["error"], wait)
            self.flush()
            self.heartbeat()
            time.sleep(wait)
            wait = min(wait * 2, 60)

    def once(self) -> None:
        conn = ZK(self.s.ip, port=self.s.port, timeout=self.s.timeout, password=self.s.password,
                  force_udp=False, ommit_ping=True).connect()
        try:
            self.state["serial"] = conn.get_serialnumber()
            try:
                self.state["model"] = conn.get_device_name()
            except Exception:
                pass
            self.state["connected"] = 1
            self.check_time(conn, force=True)
            self.read_all(conn)
        finally:
            conn.disconnect()
            self.state["connected"] = 0
        self.heartbeat()


# ── Start ──────────────────────────────────────────────────────────────────

def setup_logging(console: bool) -> None:
    (HERE / "logs").mkdir(exist_ok=True)
    handlers = [RotatingFileHandler(HERE / "logs" / "sync.log", maxBytes=1_000_000, backupCount=5, encoding="utf-8")]
    if console and sys.stdout:
        handlers.append(logging.StreamHandler(sys.stdout))
    logging.basicConfig(level=logging.INFO, format="%(asctime)s %(levelname)s %(message)s", handlers=handlers)


def check(s: Settings) -> int:
    """Try the database and the device once, print what was found."""
    ok = True
    try:
        lz = Lezan(s)
        lz.refresh_employees()
        print(f"[OK]   Lezan database {s.db['database']}@{s.db['host']}: {len(lz.pins)} employees")
        lz.close()
    except Exception as e:
        ok = False
        print(f"[FAIL] Lezan database {s.db['database']}@{s.db['host']}: {e}")
    try:
        conn = ZK(s.ip, port=s.port, timeout=s.timeout, password=s.password, ommit_ping=True).connect()
        try:
            conn.read_sizes()
            offset = round((conn.get_time() - dt.datetime.now()).total_seconds())
            print(f"[OK]   device {s.ip}: {conn.get_device_name()} {conn.get_serialnumber()}, "
                  f"{conn.users} users, {conn.records} punches, clock {offset:+d} s")
        finally:
            conn.disconnect()
    except Exception as e:
        ok = False
        print(f"[FAIL] device {s.ip}:{s.port}: {e}")
    return 0 if ok else 1


def main() -> int:
    ap = argparse.ArgumentParser(description="Lezan fingerprint sync")
    ap.add_argument("--check", action="store_true", help="test the device and the database, then exit")
    ap.add_argument("--once", action="store_true", help="read the device once, write to Lezan, exit")
    ap.add_argument("--config", default=str(HERE / "config.ini"))
    args = ap.parse_args()
    s = Settings(Path(args.config))

    if args.check:
        return check(s)

    setup_logging(console=sys.stdout is not None and sys.stdout.isatty())
    if args.once:
        Sync(s).once()
        return 0

    # One copy only: the scheduled task and its watchdog both start this file.
    lock = socket.socket(socket.AF_INET, socket.SOCK_STREAM)
    try:
        lock.bind(("127.0.0.1", LOCK_PORT))
    except OSError:
        if sys.stdout is not None and sys.stdout.isatty():
            print("Another copy is already running (the background task). Nothing to do.")
        return 0
    log.info("fingerprint sync %s started - device %s:%s, database %s", VERSION, s.ip, s.port, s.db["database"])
    try:
        Sync(s).run_forever()
    except KeyboardInterrupt:
        log.info("stopped")
    return 0


if __name__ == "__main__":
    sys.exit(main())
