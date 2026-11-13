<?php
// PDO-Verbindung + Schema (SQLite, WAL).
declare(strict_types=1);

function db_close(): void
{
    $GLOBALS['__rm_pdo'] = null;
}

function db(): PDO
{
    $pdo = $GLOBALS['__rm_pdo'] ?? null;
    if ($pdo) {
        return $pdo;
    }
    if (!is_dir(DATA_DIR)) {
        mkdir(DATA_DIR, 0770, true);
    }
    $pdo = new PDO('sqlite:' . DATA_DIR . '/monitor.sqlite', null, null, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('PRAGMA journal_mode = WAL');
    $pdo->exec('PRAGMA busy_timeout = 8000');
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec('PRAGMA synchronous = NORMAL');
    db_migrate($pdo);
    return $GLOBALS['__rm_pdo'] = $pdo;
}

function db_migrate(PDO $pdo): void
{
    $ver = (int) $pdo->query('PRAGMA user_version')->fetchColumn();
    if ($ver >= 1) {
        return;
    }
    $pdo->exec(<<<SQL
    CREATE TABLE IF NOT EXISTS users (
        id INTEGER PRIMARY KEY,
        username TEXT NOT NULL UNIQUE,
        password_hash TEXT NOT NULL,
        role TEXT NOT NULL DEFAULT 'admin',
        created_at INTEGER NOT NULL
    );
    CREATE TABLE IF NOT EXISTS settings (
        key TEXT PRIMARY KEY,
        value TEXT
    );
    -- Gruppen bilden den Baum (Root -> Probe -> Gruppen ...). parent_id NULL = Root.
    CREATE TABLE IF NOT EXISTS groups (
        id INTEGER PRIMARY KEY,
        parent_id INTEGER REFERENCES groups(id) ON DELETE CASCADE,
        name TEXT NOT NULL,
        kind TEXT NOT NULL DEFAULT 'group',          -- root | probe | group
        sort INTEGER NOT NULL DEFAULT 0,
        paused INTEGER NOT NULL DEFAULT 0,
        collapsed INTEGER NOT NULL DEFAULT 0
    );
    CREATE TABLE IF NOT EXISTS devices (
        id INTEGER PRIMARY KEY,
        group_id INTEGER NOT NULL REFERENCES groups(id) ON DELETE CASCADE,
        name TEXT NOT NULL,
        host TEXT NOT NULL DEFAULT '',
        icon TEXT NOT NULL DEFAULT 'server',
        notes TEXT NOT NULL DEFAULT '',
        sort INTEGER NOT NULL DEFAULT 0,
        paused INTEGER NOT NULL DEFAULT 0,
        depends_on INTEGER REFERENCES sensors(id) ON DELETE SET NULL
    );
    CREATE TABLE IF NOT EXISTS sensors (
        id INTEGER PRIMARY KEY,
        device_id INTEGER NOT NULL REFERENCES devices(id) ON DELETE CASCADE,
        name TEXT NOT NULL,
        type TEXT NOT NULL,
        config TEXT NOT NULL DEFAULT '{}',
        interval_s INTEGER NOT NULL DEFAULT 60,
        sort INTEGER NOT NULL DEFAULT 0,
        paused INTEGER NOT NULL DEFAULT 0,
        warn_max REAL, err_max REAL, warn_min REAL, err_min REAL,
        status TEXT NOT NULL DEFAULT 'unknown',
        status_since INTEGER,
        acked INTEGER NOT NULL DEFAULT 0,
        ack_msg TEXT,
        fail_count INTEGER NOT NULL DEFAULT 0,
        last_value REAL,
        last_unit TEXT,
        last_message TEXT,
        last_check INTEGER,
        next_check INTEGER NOT NULL DEFAULT 0,
        push_token TEXT UNIQUE
    );
    CREATE TABLE IF NOT EXISTS results (
        sensor_id INTEGER NOT NULL REFERENCES sensors(id) ON DELETE CASCADE,
        ts INTEGER NOT NULL,
        status TEXT NOT NULL,
        value REAL,
        extra TEXT
    );
    CREATE INDEX IF NOT EXISTS idx_results ON results(sensor_id, ts);
    CREATE TABLE IF NOT EXISTS events (
        id INTEGER PRIMARY KEY,
        ts INTEGER NOT NULL,
        type TEXT NOT NULL,                 -- status | system | notify | user
        sensor_id INTEGER,
        device_id INTEGER,
        status TEXT,
        message TEXT
    );
    CREATE INDEX IF NOT EXISTS idx_events_ts ON events(ts);
    CREATE TABLE IF NOT EXISTS notifications (
        id INTEGER PRIMARY KEY,
        name TEXT NOT NULL,
        type TEXT NOT NULL,                 -- email | telegram | webhook | ntfy
        config TEXT NOT NULL DEFAULT '{}',
        on_down INTEGER NOT NULL DEFAULT 1,
        on_warning INTEGER NOT NULL DEFAULT 0,
        on_up INTEGER NOT NULL DEFAULT 1,
        enabled INTEGER NOT NULL DEFAULT 1
    );
    CREATE TABLE IF NOT EXISTS maps (
        id INTEGER PRIMARY KEY,
        name TEXT NOT NULL,
        data TEXT NOT NULL DEFAULT '{"nodes":[],"edges":[]}',
        public_token TEXT UNIQUE,
        refresh_s INTEGER NOT NULL DEFAULT 30,
        updated_at INTEGER NOT NULL
    );
    INSERT INTO groups(id, parent_id, name, kind) VALUES (1, NULL, 'Root', 'root');
    INSERT INTO groups(id, parent_id, name, kind) VALUES (2, 1, 'Lokale Probe', 'probe');
    PRAGMA user_version = 1;
    SQL);
}
