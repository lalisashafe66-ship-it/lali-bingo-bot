<?php
// verify_payment.php - Submits deposit reference as 'pending' and alerts admin for approval
declare(strict_types=1);

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/db.php';

function resolveBotToken(): string {
    foreach (['TELEGRAM_BOT_TOKEN', 'BOT_TOKEN'] as $key) {
        $val = getenv($key);
        if (is_string($val) && trim($val) !== '') {
            return trim($val);
        }
        if (!empty($_ENV[$key]) && is_string($_ENV[$key])) {
            return trim($_ENV[$key]);
        }
        if (!empty($_SERVER[$key]) && is_string($_SERVER[$key])) {
            return trim($_SERVER[$key]);
        }
    }
    return '';
}

function resolveAdminId(): string {
    foreach (['ADMIN_TELEGRAM_ID', 'ADMIN_ID'] as $key) {
        $val = getenv($key);
        if (is_string($val) && trim($val) !== '') {
            return trim($val);
        }
        if (!empty($_ENV[$key]) && is_string($_ENV[$key])) {
            return trim($_ENV[$key]);
        }
        if (!empty($_SERVER[$key]) && is_string($_SERVER[$key])) {
            return trim($_SERVER[$key]);
        }
    }
    return '730967613';
}

function sendAdminTelegramMessage(string $token, string $chatId, string $message, ?array $keyboard = null): void {
    if ($token === '' || $chatId === '') {
        error_log("Smart Bingo notify: missing TELEGRAM_BOT_TOKEN or ADMIN_ID.");
        return;
    }

    $params = [
        'chat_id'    => $chatId,
        'text'       => $message,
        'parse_mode' => 'HTML',
    ];
    if ($keyboard !== null) {
        $params['reply_markup'] = json_encode($keyboard);
    }

    $url = "https://api.telegram.org/bot{$token}/sendMessage";
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $params,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
        ]);
        $res = curl_exec($ch);
        if ($res === false) {
            error_log("Telegram admin notify cURL error: " . curl_error($ch));
        } else {
            error_log("Telegram admin notify resp: " . $res);
        }
        curl_close($ch);
    } else {
        $ctx = stream_context_create([
            'http' => [
                'method'  => 'POST',
                'header'  => "Content-type: application/x-www-form-urlencoded\r\n",
                'content' => http_build_query($params),
                'timeout' => 10,
            ],
        ]);
        $res = @file_get_contents($url, false, $ctx);
        error_log("Telegram admin notify fallback resp: " . var_export($res, true));
    }
}

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    $input = [];
}

$telegramId  = preg_replace('/\D+/', '', trim((string)($_POST['telegram_id'] ?? $_GET['telegram_id'] ?? $input['telegram_id'] ?? ''))) ?? '';
$txRef       = strtoupper(trim((string)($_POST['tx_ref'] ?? $_GET['tx_ref'] ?? $input['tx_ref'] ?? '')));
$amount      = round((float)($_POST['amount'] ?? $_GET['amount'] ?? $input['amount'] ?? 0), 2);
$fullMessage = trim((string)($_POST['full_message'] ?? $_GET['full_message'] ?? $input['full_message'] ?? ''));

if ($telegramId === '' || $txRef === '' || $amount <= 0) {
    echo json_encode(['success' => false, 'message' => 'Please enter a valid Transaction Reference and Amount.']);
    exit;
}

if (strlen($txRef) < 6 || !preg_match('/^[A-Z0-9_-]+$/', $txRef)) {
    echo json_encode(['success' => false, 'message' => 'Invalid transaction reference format.']);
    exit;
}

$token    = resolveBotToken();
$ADMIN_ID = resolveAdminId();

try {
    $pdo = getBingoPdo();
    $isPgsql = strtolower((string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)) === 'pgsql';

    if ($isPgsql) {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS transactions (
                id SERIAL PRIMARY KEY,
                telegram_id TEXT,
                tx_reference TEXT UNIQUE,
                amount DOUBLE PRECISION,
                source TEXT,
                full_message TEXT,
                status TEXT DEFAULT 'pending',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )
        ");
        $pdo->exec("ALTER TABLE transactions ADD COLUMN IF NOT EXISTS full_message TEXT");
    } else {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS transactions (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                telegram_id TEXT,
                tx_reference TEXT UNIQUE,
                amount REAL,
                source TEXT,
                full_message TEXT,
                status TEXT DEFAULT 'pending',
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        ");
        $txCols = $pdo->query("PRAGMA table_info(transactions)")->fetchAll(PDO::FETCH_COLUMN, 1);
        if (!in_array('full_message', $txCols, true)) {
            $pdo->exec("ALTER TABLE transactions ADD COLUMN full_message TEXT");
        }
    }

    $stmt = $pdo->prepare("SELECT status FROM transactions WHERE UPPER(tx_reference) = ? LIMIT 1");
    $stmt->execute([$txRef]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($existing) {
        $msg = $existing['status'] === 'pending'
            ? 'This Transaction ID is already pending admin verification.'
            : 'This Transaction ID has already been processed.';
        echo json_encode(['success' => false, 'message' => $msg]);
        exit;
    }

    $now = gmdate('Y-m-d H:i:s');
    if ($isPgsql) {
        $insPlayer = $pdo->prepare("
            INSERT INTO players (telegram_id, chat_id, first_name, username, phone, balance, created_at, updated_at)
            VALUES (?, ?, 'Player', '', 'WebUser', 0.00, ?, ?)
            ON CONFLICT (telegram_id) DO NOTHING
        ");
    } else {
        $insPlayer = $pdo->prepare("
            INSERT OR IGNORE INTO players (telegram_id, chat_id, first_name, username, phone, balance, created_at, updated_at)
            VALUES (?, ?, 'Player', '', 'WebUser', 0.00, ?, ?)
        ");
    }
    $insPlayer->execute([$telegramId, $telegramId, $now, $now]);

    $stmtPlayer = $pdo->prepare("SELECT first_name FROM players WHERE telegram_id = ? LIMIT 1");
    $stmtPlayer->execute([$telegramId]);
    $player = $stmtPlayer->fetch(PDO::FETCH_ASSOC);
    $playerName = htmlspecialchars((string)($player['first_name'] ?? 'Player'), ENT_QUOTES, 'UTF-8');

    $insertTx = $pdo->prepare("INSERT INTO transactions (telegram_id, tx_reference, amount, source, full_message, status) VALUES (?, ?, ?, 'webapp_manual', ?, 'pending')");
    $insertTx->execute([$telegramId, $txRef, $amount, $fullMessage !== '' ? $fullMessage : null]);
    $txId = $isPgsql ? (int)$pdo->lastInsertId('transactions_id_seq') : (int)$pdo->lastInsertId();

    $adminMsg = "🔔 <b>New Deposit Verification Request</b>\n\n" .
                "👤 <b>Player:</b> {$playerName} (<code>{$telegramId}</code>)\n" .
                "💰 <b>Amount:</b> <b>{$amount} ETB</b>\n" .
                "🧾 <b>Ref ID:</b> <code>{$txRef}</code>";

    if ($fullMessage !== '') {
        $safeMsg = htmlspecialchars(mb_substr($fullMessage, 0, 500), ENT_QUOTES, 'UTF-8');
        $adminMsg .= "\n📩 <b>Message:</b> <code>{$safeMsg}</code>";
    }

    $adminMsg .= "\n\nCheck your Telebirr/CBE app and confirm below:";

    $keyboard = [
        'inline_keyboard' => [
            [
                ['text' => "✅ Approve ({$amount} ETB)", 'callback_data' => "approve_tx:{$txId}"],
                ['text' => "❌ Reject", 'callback_data' => "reject_tx:{$txId}"],
            ],
        ],
    ];

    sendAdminTelegramMessage($token, $ADMIN_ID, $adminMsg, $keyboard);

    echo json_encode([
        'success' => true,
        'pending' => true,
        'message' => 'Deposit submitted! Waiting for admin confirmation (usually within a few minutes).',
    ]);
} catch (Throwable $e) {
    error_log("verify_payment error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}
