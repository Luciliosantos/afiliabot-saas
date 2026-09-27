<?php
declare(strict_types=1);

final class Migrator
{
    public static function up(Database $db): void
    {
        $sql = <<<SQL
CREATE TABLE IF NOT EXISTS migrations (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    version TEXT UNIQUE NOT NULL,
    applied_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    telegram_id INTEGER UNIQUE NOT NULL,
    username TEXT DEFAULT '',
    first_name TEXT DEFAULT '',
    last_name TEXT DEFAULT '',
    role TEXT DEFAULT 'customer',
    status TEXT DEFAULT 'active',
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS plans (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    slug TEXT UNIQUE NOT NULL,
    name TEXT NOT NULL,
    price REAL NOT NULL,
    duration_days INTEGER NOT NULL,
    max_destinations INTEGER NOT NULL DEFAULT 1,
    max_daily_offers INTEGER NOT NULL DEFAULT 1000,
    active INTEGER NOT NULL DEFAULT 1,
    created_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS subscriptions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    plan_id INTEGER NOT NULL,
    status TEXT NOT NULL DEFAULT 'pending',
    starts_at TEXT,
    expires_at TEXT,
    mp_payment_id TEXT DEFAULT '',
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY(plan_id) REFERENCES plans(id)
);

CREATE TABLE IF NOT EXISTS affiliate_accounts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    platform TEXT NOT NULL,
    affiliate_id TEXT DEFAULT '',
    link_template TEXT DEFAULT '',
    active INTEGER NOT NULL DEFAULT 1,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    UNIQUE(user_id, platform),
    FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS destinations (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    chat_id TEXT NOT NULL,
    title TEXT DEFAULT '',
    active INTEGER NOT NULL DEFAULT 1,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    UNIQUE(user_id, chat_id),
    FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS source_offers (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    source_chat_id TEXT NOT NULL,
    source_message_id INTEGER NOT NULL,
    fingerprint TEXT UNIQUE NOT NULL,
    text TEXT DEFAULT '',
    media_type TEXT DEFAULT '',
    media_file_id TEXT DEFAULT '',
    raw_update TEXT DEFAULT '',
    created_at TEXT NOT NULL,
    UNIQUE(source_chat_id, source_message_id)
);

CREATE TABLE IF NOT EXISTS deliveries (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    offer_id INTEGER NOT NULL,
    user_id INTEGER NOT NULL,
    destination_id INTEGER NOT NULL,
    status TEXT NOT NULL DEFAULT 'pending',
    attempts INTEGER NOT NULL DEFAULT 0,
    next_attempt_at TEXT NOT NULL,
    last_error TEXT DEFAULT '',
    telegram_message_id INTEGER,
    sent_at TEXT,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    UNIQUE(offer_id, destination_id),
    FOREIGN KEY(offer_id) REFERENCES source_offers(id) ON DELETE CASCADE,
    FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY(destination_id) REFERENCES destinations(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS payments (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    plan_id INTEGER NOT NULL,
    external_id TEXT UNIQUE NOT NULL,
    amount REAL NOT NULL,
    status TEXT NOT NULL DEFAULT 'pending',
    pix_code TEXT DEFAULT '',
    qr_code_base64 TEXT DEFAULT '',
    mp_payment_id TEXT DEFAULT '',
    raw_response TEXT DEFAULT '',
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY(plan_id) REFERENCES plans(id)
);

CREATE TABLE IF NOT EXISTS webhook_events (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    provider TEXT NOT NULL,
    event_key TEXT UNIQUE NOT NULL,
    payload TEXT NOT NULL,
    processed INTEGER NOT NULL DEFAULT 0,
    created_at TEXT NOT NULL,
    processed_at TEXT
);

CREATE TABLE IF NOT EXISTS bot_states (
    telegram_id INTEGER PRIMARY KEY,
    state TEXT NOT NULL,
    payload TEXT DEFAULT '',
    updated_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS settings (
    key TEXT PRIMARY KEY,
    value TEXT NOT NULL,
    updated_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS audit_logs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER,
    action TEXT NOT NULL,
    context TEXT DEFAULT '',
    created_at TEXT NOT NULL,
    FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE SET NULL
);

CREATE INDEX IF NOT EXISTS idx_deliveries_queue
ON deliveries(status, next_attempt_at, attempts, id);

CREATE INDEX IF NOT EXISTS idx_source_created
ON source_offers(created_at);

CREATE INDEX IF NOT EXISTS idx_subscriptions_user
ON subscriptions(user_id, status, expires_at);

CREATE INDEX IF NOT EXISTS idx_payments_status
ON payments(status, created_at);

CREATE INDEX IF NOT EXISTS idx_affiliates_user
ON affiliate_accounts(user_id, active);
SQL;

        $db->pdo()->exec($sql);

        $exists = $db->scalar("SELECT COUNT(*) FROM migrations WHERE version = '001_initial'");
        if ((int)$exists === 0) {
            $now = now();
            $db->execute(
                "INSERT INTO migrations(version, applied_at) VALUES(?, ?)",
                ['001_initial', $now]
            );
        }

        $planCount = (int)$db->scalar("SELECT COUNT(*) FROM plans");
        if ($planCount === 0) {
            $now = now();
            $db->execute(
                "INSERT INTO plans(slug,name,price,duration_days,max_destinations,max_daily_offers,active,created_at)
                 VALUES(?,?,?,?,?,?,1,?)",
                ['pro', 'Plano Pro', app_config()['default_plan_price'], app_config()['default_plan_days'], 3, 2000, $now]
            );
        }
    }
}
