<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

header('Content-Type: text/plain; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo "OK - SmartBingoHub_bot webhook endpoint";
    exit;
}

$raw = file_get_contents('php://input');
$update = json_decode($raw ?: '', true);

if (!is_array($update)) {
    http_response_code(200);
    echo "INVALID_JSON";
    exit;
}

$token = trim((string)(getenv('TELEGRAM_BOT_TOKEN') ?: ''));
if ($token === '') {
    error_log("SmartBingoHub_bot: TELEGRAM_BOT_TOKEN is empty");
    http_response_code(200);
    echo "MISSING_TOKEN";
    exit;
}

$message = $update['message'] ?? $update['edited_message'] ?? null;
if (!is_array($message)) {
    http_response_code(200);
    echo "NO_MESSAGE";
    exit;
}

$chatId    = isset($message['chat']['id']) ? (int)$message['chat']['id'] : 0;
$userId    = isset($message['from']['id']) ? (int)$message['from']['id'] : $chatId;
$text      = trim((string)($message['text'] ?? ''));
$firstName = trim((string)($message['from']['first_name'] ?? 'Player'));
$username  = trim((string)($message['from']['username'] ?? ''));

if ($chatId === 0 || $userId === 0) {
    http_response_code(200);
    echo "NO_CHAT_ID";
    exit;
}

$reply = null;
try {
    $player = registerOrGetUser($userId, $chatId, $firstName, $username);
    $balanceFormatted = number_format((float)($player['balance'] ?? 0.0), 2);

    if ($text === '/start') {
        $reply = "Welcome to SMART BINGO ({$firstName})!\n"
               . "Balance: {$balanceFormatted} ETB\n"
               . "Send /myid to view your ID and balance.";
    } elseif ($text === '/myid') {
        $reply = "Telegram User ID: {$userId}\n"
               . "Chat ID: {$chatId}\n"
               . "Balance: {$balanceFormatted} ETB";
    }
} catch (Throwable $e) {
    error_log("SmartBingoHub_bot DB error: " . $e->getMessage());
    $reply = "Temporary database error while saving your profile. Please try again.";
}

if ($reply !== null) {
    $payload = json_encode([
        'chat_id' => $chatId,
        'text'    => $reply,
    ]);

    $ch = curl_init("https://api.telegram.org/bot{$token}/sendMessage");
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
    ]);
    $resp = curl_exec($ch);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($err !== '') {
        error_log("SmartBingoHub_bot cURL error: {$err}");
    } else {
        error_log("SmartBingoHub_bot sendMessage ({$chatId}): {$resp}");
    }
}

http_response_code(200);
echo "OK";
