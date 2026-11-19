<?php
// db.php - Central SQLite connection for Future Bingo

function getBingoDbPath(): string {
    $envPath = getenv('BINGO_DB_PATH');
    if ($envPath && trim($envPath) !== '') {
        return trim($envPath);
    }
    if (is_dir('/var/data') && is_writable('/var/data')) {
        return '/var/data/bingo.db';
    }
    return __DIR__ . '/bingo.db';
}

function getBingoPdo(): PDO {
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $dbPath = getBingoDbPath();
    $dir = dirname($dbPath);
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }

    $pdo = new PDO("sqlite:" . $dbPath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec("PRAGMA journal_mode = WAL;");
    $pdo->exec("PRAGMA busy_timeout = 5000;");

    $pdo->exec("CREATE TABLE IF NOT EXISTS players (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        telegram_id TEXT UNIQUE,
        first_name TEXT,
        phone TEXT,
        balance REAL DEFAULT 50.00,
        referred_by TEXT DEFAULT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS rooms (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        game_code TEXT,
        stake REAL,
        status TEXT DEFAULT 'waiting',
        created_at INTEGER,
        starts_at INTEGER,
        started_at INTEGER,
        draw_pool_json TEXT,
        called_numbers_json TEXT DEFAULT '[]',
        winners_json TEXT DEFAULT '[]'
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS room_players (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        room_id INTEGER,
        telegram_id TEXT,
        first_name TEXT,
        player_number INTEGER,
        stake_paid REAL DEFAULT 0,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS withdrawals (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        telegram_id TEXT,
        amount REAL,
        method TEXT,
        account TEXT,
        status TEXT DEFAULT 'pending',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS game_winners (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        room_id INTEGER,
        telegram_id TEXT,
        winner_name TEXT,
        prize REAL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS balance_ledger (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        telegram_id TEXT,
        room_id INTEGER DEFAULT NULL,
        type TEXT,
        amount REAL,
        balance_before REAL,
        balance_after REAL,
        note TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    return $pdo;
}
