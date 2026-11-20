<?php
declare(strict_types=1);

function getDb(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    // Store SQLite in /tmp on Render so it is writable across requests
    $dbDir = is_dir('/tmp') ? '/tmp' : __DIR__;
    $dbPath = rtrim($dbDir, '/') . '/smart_bingo.sqlite';

    $pdo = new PDO('sqlite:' . $dbPath, null, null, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS users (
            telegram_id INTEGER PRIMARY KEY,
            chat_id     INTEGER NOT NULL,
            first_name  TEXT NOT NULL DEFAULT 'Player',
            username    TEXT DEFAULT '',
            balance     REAL NOT NULL DEFAULT 0.0,
            created_at  TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at  TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )
    ");

    return $pdo;
}

function registerOrGetUser(int $telegramId, int $chatId, string $firstName, string $username = ''): array
{
    $pdo = getDb();

    $stmt = $pdo->prepare("SELECT * FROM users WHERE telegram_id = :tid LIMIT 1");
    $stmt->execute([':tid' => $telegramId]);
    $existing = $stmt->fetch();

    if ($existing) {
        $upd = $pdo->prepare("
            UPDATE users
            SET chat_id = :cid,
                first_name = :fname,
                username = :uname,
                updated_at = CURRENT_TIMESTAMP
            WHERE telegram_id = :tid
        ");
        $upd->execute([
            ':cid'   => $chatId,
            ':fname' => $firstName !== '' ? $firstName : $existing['first_name'],
            ':uname' => $username,
            ':tid'   => $telegramId,
        ]);
    } else {
        $ins = $pdo->prepare("
            INSERT INTO users (telegram_id, chat_id, first_name, username, balance)
            VALUES (:tid, :cid, :fname, :uname, 0.0)
        ");
        $ins->execute([
            ':tid'   => $telegramId,
            ':cid'   => $chatId,
            ':fname' => $firstName !== '' ? $firstName : 'Player',
            ':uname' => $username,
        ]);
    }

    $stmt->execute([':tid' => $telegramId]);
    return $stmt->fetch() ?: [];
}
