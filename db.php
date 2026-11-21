<?php
declare(strict_types=1);

function resolveBingoDbPath(): string
{
    $candidates = [];

    $envPath = trim((string)(getenv('BINGO_DB_PATH') ?: ''));
    if ($envPath !== '') {
        $candidates[] = $envPath;
    }

    if (is_dir('/var/data')) {
        $candidates[] = '/var/data/smart_bingo.sqlite';
    }

    if (is_dir('/tmp')) {
        $candidates[] = '/tmp/smart_bingo.sqlite';
    }

    $candidates[] = __DIR__ . '/smart_bingo.sqlite';

    $chosenPath = '';
    foreach ($candidates as $candidate) {
        $dir = dirname($candidate);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        if (is_dir($dir) && is_writable($dir)) {
            $chosenPath = $candidate;
            break;
        }
    }

    if ($chosenPath === '') {
        $chosenPath = __DIR__ . '/smart_bingo.sqlite';
    }

    $tmpDb = '/tmp/smart_bingo.sqlite';
    if ($chosenPath !== $tmpDb && is_file($tmpDb) && !is_file($chosenPath)) {
        @copy($tmpDb, $chosenPath);
    }

    return $chosenPath;
}

function getBingoPdo(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $dbHost = trim((string)(getenv('DB_HOST') ?: ''));
    $dbUrl  = trim((string)(getenv('DATABASE_URL') ?: ''));

    if ($dbHost !== '' || $dbUrl !== '') {
        if ($dbHost !== '') {
            $host    = $dbHost;
            $port    = trim((string)(getenv('DB_PORT') ?: '5432'));
            $dbName  = trim((string)(getenv('DB_NAME') ?: 'neondb'));
            $user    = trim((string)(getenv('DB_USER') ?: ''));
            $pass    = (string)(getenv('DB_PASS') ?: '');
            $sslmode = trim((string)(getenv('DB_SSLMODE') ?: 'require'));
        } else {
            $parts   = parse_url($dbUrl) ?: [];
            $host    = (string)($parts['host'] ?? '');
            $port    = (string)($parts['port'] ?? '5432');
            $dbName  = ltrim((string)($parts['path'] ?? '/neondb'), '/');
            $user    = urldecode((string)($parts['user'] ?? ''));
            $pass    = urldecode((string)($parts['pass'] ?? ''));
            $sslmode = 'require';
        }

        $dsn = sprintf(
            'pgsql:host=%s;port=%s;dbname=%s;sslmode=%s',
            $host,
            $port,
            $dbName,
            $sslmode
        );

        $pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS players (
                telegram_id TEXT PRIMARY KEY,
                chat_id     TEXT DEFAULT '',
                first_name  TEXT NOT NULL DEFAULT 'Player',
                username    TEXT DEFAULT '',
                phone       TEXT DEFAULT '',
                balance     DOUBLE PRECISION NOT NULL DEFAULT 0.0,
                created_at  TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at  TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS rooms (
                id                  SERIAL PRIMARY KEY,
                game_code           TEXT,
                stake               DOUBLE PRECISION NOT NULL DEFAULT 10,
                status              TEXT NOT NULL DEFAULT 'waiting',
                created_at          BIGINT,
                starts_at           BIGINT,
                started_at          BIGINT,
                draw_pool_json      TEXT,
                called_numbers_json TEXT DEFAULT '[]',
                winners_json        TEXT DEFAULT '[]'
            )
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS room_players (
                id            SERIAL PRIMARY KEY,
                room_id       INTEGER NOT NULL,
                telegram_id   TEXT NOT NULL,
                first_name    TEXT DEFAULT 'Player',
                player_number INTEGER NOT NULL,
                stake_paid    DOUBLE PRECISION DEFAULT 0,
                created_at    TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS balance_ledger (
                id             SERIAL PRIMARY KEY,
                telegram_id    TEXT NOT NULL,
                room_id        INTEGER,
                type           TEXT NOT NULL,
                amount         DOUBLE PRECISION NOT NULL,
                balance_before DOUBLE PRECISION NOT NULL,
                balance_after  DOUBLE PRECISION NOT NULL,
                note           TEXT DEFAULT '',
                created_at     TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS game_winners (
                id          SERIAL PRIMARY KEY,
                room_id     INTEGER NOT NULL,
                telegram_id TEXT NOT NULL,
                winner_name TEXT DEFAULT 'Player',
                prize       DOUBLE PRECISION NOT NULL DEFAULT 0,
                created_at  TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS withdrawals (
                id          SERIAL PRIMARY KEY,
                telegram_id TEXT NOT NULL,
                amount      DOUBLE PRECISION NOT NULL,
                method      TEXT NOT NULL,
                account     TEXT NOT NULL,
                status      TEXT NOT NULL DEFAULT 'pending',
                created_at  TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        ");

        return $pdo;
    }

    $dbPath = resolveBingoDbPath();

    $pdo = new PDO('sqlite:' . $dbPath, null, null, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    $pdo->exec("PRAGMA journal_mode = WAL;");
    $pdo->exec("PRAGMA busy_timeout = 5000;");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS players (
            telegram_id TEXT PRIMARY KEY,
            chat_id     TEXT DEFAULT '',
            first_name  TEXT NOT NULL DEFAULT 'Player',
            username    TEXT DEFAULT '',
            phone       TEXT DEFAULT '',
            balance     REAL NOT NULL DEFAULT 0.0,
            created_at  TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at  TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS rooms (
            id                  INTEGER PRIMARY KEY AUTOINCREMENT,
            game_code           TEXT,
            stake               REAL NOT NULL DEFAULT 10,
            status              TEXT NOT NULL DEFAULT 'waiting',
            created_at          INTEGER,
            starts_at           INTEGER,
            started_at          INTEGER,
            draw_pool_json      TEXT,
            called_numbers_json TEXT DEFAULT '[]',
            winners_json        TEXT DEFAULT '[]'
        )
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS room_players (
            id            INTEGER PRIMARY KEY AUTOINCREMENT,
            room_id       INTEGER NOT NULL,
            telegram_id   TEXT NOT NULL,
            first_name    TEXT DEFAULT 'Player',
            player_number INTEGER NOT NULL,
            stake_paid    REAL DEFAULT 0,
            created_at    TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS balance_ledger (
            id             INTEGER PRIMARY KEY AUTOINCREMENT,
            telegram_id    TEXT NOT NULL,
            room_id        INTEGER,
            type           TEXT NOT NULL,
            amount         REAL NOT NULL,
            balance_before REAL NOT NULL,
            balance_after  REAL NOT NULL,
            note           TEXT DEFAULT '',
            created_at     TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS game_winners (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            room_id     INTEGER NOT NULL,
            telegram_id TEXT NOT NULL,
            winner_name TEXT DEFAULT 'Player',
            prize       REAL NOT NULL DEFAULT 0,
            created_at  TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS withdrawals (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            telegram_id TEXT NOT NULL,
            amount      REAL NOT NULL,
            method      TEXT NOT NULL,
            account     TEXT NOT NULL,
            status      TEXT NOT NULL DEFAULT 'pending',
            created_at  TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )
    ");

    return $pdo;
}

function getDb(): PDO
{
    return getBingoPdo();
}

function registerOrGetUser(int $telegramId, int $chatId, string $firstName, string $username = ''): array
{
    $pdo = getBingoPdo();
    $tid = (string)$telegramId;
    $cid = (string)$chatId;

    $stmt = $pdo->prepare("SELECT * FROM players WHERE telegram_id = :tid LIMIT 1");
    $stmt->execute([':tid' => $tid]);
    $existing = $stmt->fetch();

    if ($existing) {
        $upd = $pdo->prepare("
            UPDATE players
            SET chat_id = :cid,
                first_name = :fname,
                username = :uname,
                updated_at = CURRENT_TIMESTAMP
            WHERE telegram_id = :tid
        ");
        $upd->execute([
            ':cid'   => $cid,
            ':fname' => $firstName !== '' ? $firstName : $existing['first_name'],
            ':uname' => $username,
            ':tid'   => $tid,
        ]);
    } else {
        $ins = $pdo->prepare("
            INSERT INTO players (telegram_id, chat_id, first_name, username, phone, balance)
            VALUES (:tid, :cid, :fname, :uname, '', 0.0)
        ");
        $ins->execute([
            ':tid'   => $tid,
            ':cid'   => $cid,
            ':fname' => $firstName !== '' ? $firstName : 'Player',
            ':uname' => $username,
        ]);
    }

    $stmt->execute([':tid' => $tid]);
    return $stmt->fetch() ?: [];
}
