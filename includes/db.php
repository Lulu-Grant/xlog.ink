<?php
// SQLite storage and schema management for xlog V2.

require_once __DIR__ . '/bootstrap.php';

function db() {
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;

    $path = xlog_config('data_dir') . '/xlog.db';
    $pdo = new PDO('sqlite:' . $path, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('PRAGMA journal_mode=WAL');
    $pdo->exec('PRAGMA foreign_keys=ON');
    $pdo->exec('PRAGMA busy_timeout=5000');
    db_init($pdo);
    return $pdo;
}

function db_init(PDO $pdo) {
    $pdo->exec("
CREATE TABLE IF NOT EXISTS users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    email TEXT NOT NULL UNIQUE,
    created_at TEXT NOT NULL,
    daily_quota INTEGER NOT NULL DEFAULT 50,
    credits INTEGER NOT NULL DEFAULT 0,
    status TEXT NOT NULL DEFAULT 'active'
);
CREATE TABLE IF NOT EXISTS login_codes (
    email TEXT NOT NULL,
    code_hash TEXT NOT NULL,
    expires_at TEXT NOT NULL,
    attempts INTEGER NOT NULL DEFAULT 0,
    created_at TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_login_codes_email ON login_codes(email);
CREATE TABLE IF NOT EXISTS pages (
    slug TEXT PRIMARY KEY,
    title TEXT NOT NULL,
    type TEXT NOT NULL,
    lang TEXT NOT NULL DEFAULT 'zh-CN',
    created_at TEXT NOT NULL,
    updated_at TEXT,
    owner_user_id INTEGER,
    email TEXT,
    editable INTEGER NOT NULL DEFAULT 0,
    token_hash TEXT,
    is_adult INTEGER NOT NULL DEFAULT 0,
    status TEXT NOT NULL DEFAULT 'live',
    cost_tokens INTEGER DEFAULT 0,
    session_id TEXT,
    html_path TEXT
);
CREATE INDEX IF NOT EXISTS idx_pages_token_hash ON pages(token_hash);
CREATE TABLE IF NOT EXISTS sessions (
    id TEXT PRIMARY KEY,
    user_id INTEGER,
    page_slug TEXT,
    edit_mode TEXT NOT NULL DEFAULT '',
    messages TEXT NOT NULL DEFAULT '[]',
    state TEXT NOT NULL DEFAULT 'chatting',
    ip TEXT NOT NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);
CREATE TABLE IF NOT EXISTS images (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    session_id TEXT NOT NULL,
    slug TEXT,
    path TEXT NOT NULL,
    caption TEXT DEFAULT '',
    width INTEGER,
    height INTEGER,
    created_at TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_images_session ON images(session_id);
CREATE TABLE IF NOT EXISTS quota_counters (
    key TEXT NOT NULL,
    date TEXT NOT NULL,
    kind TEXT NOT NULL,
    count INTEGER NOT NULL DEFAULT 0,
    PRIMARY KEY (key, date, kind)
);
CREATE TABLE IF NOT EXISTS credit_transactions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    delta INTEGER NOT NULL,
    reason TEXT NOT NULL,
    ref TEXT,
    created_at TEXT NOT NULL
);
CREATE TABLE IF NOT EXISTS orders (
    id TEXT PRIMARY KEY,
    user_id INTEGER NOT NULL,
    amount_cents INTEGER NOT NULL,
    credits INTEGER NOT NULL,
    status TEXT NOT NULL DEFAULT 'pending',
    pay_channel TEXT,
    created_at TEXT NOT NULL,
    paid_at TEXT
);
CREATE TABLE IF NOT EXISTS publish_events (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    session_id TEXT,
    slug TEXT,
    user_id INTEGER,
    kind TEXT NOT NULL,
    status TEXT NOT NULL,
    message TEXT,
    input_tokens INTEGER NOT NULL DEFAULT 0,
    output_tokens INTEGER NOT NULL DEFAULT 0,
    is_adult INTEGER NOT NULL DEFAULT 0,
    created_at TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_publish_events_created ON publish_events(created_at);
CREATE INDEX IF NOT EXISTS idx_publish_events_status ON publish_events(status);
CREATE TABLE IF NOT EXISTS mail_events (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    kind TEXT NOT NULL,
    event_key TEXT NOT NULL,
    created_at TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_mail_events_lookup ON mail_events(kind, event_key, created_at);
");
    db_ensure_column($pdo, 'sessions', 'edit_mode', "TEXT NOT NULL DEFAULT ''");
}

function db_ensure_column(PDO $pdo, $table, $column, $definition) {
    $stmt = $pdo->query('PRAGMA table_info(' . $table . ')');
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if (($row['name'] ?? '') === $column) return;
    }
    $pdo->exec('ALTER TABLE ' . $table . ' ADD COLUMN ' . $column . ' ' . $definition);
}

function db_one($sql, array $params = []) {
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch();
    return $row ?: null;
}

function db_all($sql, array $params = []) {
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function db_exec($sql, array $params = []) {
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt;
}

function session_messages($sessionId) {
    $row = db_one('SELECT messages FROM sessions WHERE id = ?', [$sessionId]);
    if (!$row) return null;
    $messages = json_decode($row['messages'], true);
    return is_array($messages) ? $messages : [];
}

function save_session_messages($sessionId, array $messages, $state = null) {
    $json = json_encode($messages, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($state === null) {
        db_exec('UPDATE sessions SET messages = ?, updated_at = ? WHERE id = ?', [$json, now_iso(), $sessionId]);
    } else {
        db_exec('UPDATE sessions SET messages = ?, state = ?, updated_at = ? WHERE id = ?', [$json, $state, now_iso(), $sessionId]);
    }
}

function append_session_message($sessionId, $role, $content) {
    $messages = session_messages($sessionId);
    if ($messages === null) return false;
    $messages[] = ['role' => $role, 'content' => (string)$content, 'ts' => now_iso()];
    save_session_messages($sessionId, $messages);
    return true;
}

function create_session($pageSlug = null, array $seedMessages = [], $editMode = '') {
    $id = bin2hex(random_bytes(16));
    $now = now_iso();
    db_exec(
        'INSERT INTO sessions (id, user_id, page_slug, edit_mode, messages, state, ip, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
        [$id, current_user_id(), $pageSlug, $editMode, json_encode($seedMessages, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'chatting', client_ip(), $now, $now]
    );
    return $id;
}

function generate_unique_slug() {
    $dir = xlog_config('site_dir');
    for ($i = 0; $i < 30; $i++) {
        $slug = random_name(10);
        $exists = db_one('SELECT slug FROM pages WHERE slug = ?', [$slug]);
        if (!$exists && !file_exists($dir . '/' . $slug . '.html')) return $slug;
    }
    throw new RuntimeException('Could not generate unique slug');
}
