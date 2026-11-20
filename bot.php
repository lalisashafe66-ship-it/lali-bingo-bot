<?php
declare(strict_types=1);

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

$chatId = $message['chat']['id'] ?? null;
$text   = trim((string)($message['text'] ?? ''));
$userId = $message['from']['id'] ?? null;
$name   = trim((string)($message['from']['first_name'] ?? 'Player'));

if ($chatId === null) {
    http_response_code(200);
    echo "NO_CHAT_ID";
    exit;
}

$reply = null;
if ($text === '/start') {
    $reply = "Welcome to SMART BINGO ({$name})! Your chat is connected.\nSend /myid to view your Telegram ID.";
} elseif ($text === '/myid') {
    $reply = "Your Telegram User ID: {$userId}\nChat ID: {$chatId}";
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
