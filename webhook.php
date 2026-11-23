<?php
declare(strict_types=1);

// webhook.php - Handles incoming Telegram Bot updates
header('Content-Type: application/json');

require_once __DIR__ . '/db.php';

$botToken = trim((string)(getenv('BOT_TOKEN') ?: getenv('TELEGRAM_BOT_TOKEN') ?: ''));
$appUrl   = rtrim((string)(getenv('APP_URL') ?: 'https://lali-bingo-bot.onrender.com'), '/');

if ($botToken === '') {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'BOT_TOKEN is not set']);
    exit;
}

$rawInput = file_get_contents('php://input');
$update   = json_decode($rawInput ?: '', true);

if (!is_array($update)) {
    http_response_code(200);
    echo json_encode(['ok' => true]);
    exit;
}

function sendTelegramMessage(string $botToken, int|string $chatId, string $text, ?array $replyMarkup = null): void
{
    $payload = [
        'chat_id'    => $chatId,
        'text'       => $text,
        'parse_mode' => 'Markdown',
    ];

    if ($replyMarkup !== null) {
        $payload['reply_markup'] = json_encode($replyMarkup);
    }

    $ch = curl_init("https://api.telegram.org/bot{$botToken}/sendMessage");
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
    ]);
    $result = curl_exec($ch);
    if ($result === false) {
        error_log('Telegram sendMessage error: ' . curl_error($ch));
    }
    curl_close($ch);
}

try {
    $pdo = getBingoPdo();
    $isPgsql = strtolower((string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)) === 'pgsql';

    if ($isPgsql) {
        $pdo->exec("CREATE TABLE IF NOT EXISTS users (
            id SERIAL PRIMARY KEY,
            telegram_id TEXT UNIQUE,
            first_name TEXT,
            username TEXT,
            balance DOUBLE PRECISION DEFAULT 0,
            created_at TEXT DEFAULT CURRENT_TIMESTAMP
        )");
    } else {
        $pdo->exec("CREATE TABLE IF NOT EXISTS users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            telegram_id TEXT UNIQUE,
            first_name TEXT,
            username TEXT,
            balance REAL DEFAULT 0,
            created_at TEXT DEFAULT CURRENT_TIMESTAMP
        )");
    }

    $message = $update['message'] ?? null;
    if (is_array($message)) {
        $chatId    = $message['chat']['id'] ?? null;
        $from      = $message['from'] ?? [];
        $userId    = isset($from['id']) ? (string)$from['id'] : null;
        $firstName = (string)($from['first_name'] ?? 'Player');
        $username  = (string)($from['username'] ?? '');
        $text      = trim((string)($message['text'] ?? ''));

        if ($userId !== null) {
            $stmt = $pdo->prepare("
                INSERT INTO users (telegram_id, first_name, username)
                VALUES (?, ?, ?)
                ON CONFLICT(telegram_id) DO UPDATE SET
                    first_name = EXCLUDED.first_name,
                    username = EXCLUDED.username
            ");
            $stmt->execute([$userId, $firstName, $username]);
        }

        if ($chatId !== null && ($text === '/start' || str_starts_with($text, '/start '))) {
            $keyboard = [
                'inline_keyboard' => [[
                    [
                        'text'    => '🎮 Play Smart Bingo',
                        'web_app' => ['url' => $appUrl . '/index.php'],
                    ],
                ]],
            ];

            sendTelegramMessage(
                $botToken,
                $chatId,
                "Welcome to *Smart Bingo*, {$firstName}!\n\nTap the button below to launch the game.",
                $keyboard
            );
        }
    }

    http_response_code(200);
    echo json_encode(['ok' => true]);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Webhook error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Internal server error']);
}
