<?php
// verify_payment.php - Submits deposit reference as 'pending' and alerts admin for approval
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$telegramId = trim((string)($_POST['telegram_id'] ?? $_GET['telegram_id'] ?? $input['telegram_id'] ?? ''));
$txRef = strtoupper(trim((string)($_POST['tx_ref'] ?? $_GET['tx_ref'] ?? $input['tx_ref'] ?? '')));
$amount = round((float)($_POST['amount'] ?? $_GET['amount'] ?? $input['amount'] ?? 0), 2);

if ($telegramId === '' || $txRef === '' || $amount <= 0) {
    echo json_encode(['success' => false, 'message' => 'Please enter a valid Transaction Reference and Amount.']);
    exit;
}

if (strlen($txRef) < 6 || !preg_match('/^[A-Z0-9_-]+$/', $txRef)) {
    echo json_encode(['success' => false, 'message' => 'Invalid transaction reference format.']);
    exit;
}

$token = getenv('TELEGRAM_BOT_TOKEN') ?: '';
$ADMIN_ID = "730967613";

try {
    $dbPath = __DIR__ . '/bingo.db';
    $pdo = new PDO("sqlite:" . $dbPath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec("PRAGMA busy_timeout = 5000;");

    $pdo->exec("CREATE TABLE IF NOT EXISTS players (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        telegram_id TEXT UNIQUE,
        first_name TEXT,
        phone TEXT,
        balance REAL DEFAULT 0.00,
        referred_by TEXT DEFAULT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS transactions (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        telegram_id TEXT,
        tx_reference TEXT UNIQUE,
        amount REAL,
        source TEXT,
        status TEXT DEFAULT 'pending',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    // Check if reference already exists (pending, claimed, or rejected)
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

    // Ensure player exists
    $insPlayer = $pdo->prepare("INSERT OR IGNORE INTO players (telegram_id, first_name, phone, balance) VALUES (?, 'Player', 'WebUser', 0.00)");
    $insPlayer->execute([$telegramId]);

    $stmtPlayer = $pdo->prepare("SELECT first_name, phone FROM players WHERE telegram_id = ?");
    $stmtPlayer->execute([$telegramId]);
    $player = $stmtPlayer->fetch(PDO::FETCH_ASSOC);
    $playerName = $player['first_name'] ?? 'Player';

    // Insert as 'pending' — DO NOT credit balance yet
    $insertTx = $pdo->prepare("INSERT INTO transactions (telegram_id, tx_reference, amount, source, status) VALUES (?, ?, ?, 'webapp_manual', 'pending')");
    $insertTx->execute([$telegramId, $txRef, $amount]);
    $txId = $pdo->lastInsertId();

    // Notify Admin with inline Approve / Reject buttons
    if ($token !== '') {
        $adminMsg = "🔔 <b>New Deposit Verification Request</b>\n\n" .
                    "👤 <b>Player:</b> {$playerName} (<code>{$telegramId}</code>)\n" .
                    "💰 <b>Amount:</b> <b>{$amount} ETB</b>\n" .
                    "🧾 <b>Ref ID:</b> <code>{$txRef}</code>\n\n" .
                    "Check your Telebirr/CBE app and confirm below:";

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => "✅ Approve ({$amount} ETB)", 'callback_data' => "approve_tx:{$txId}"],
                    ['text' => "❌ Reject", 'callback_data' => "reject_tx:{$txId}"]
                ]
            ]
        ];

        @file_get_contents("https://api.telegram.org/bot{$token}/sendMessage?" . http_build_query([
            'chat_id' => $ADMIN_ID,
            'text' => $adminMsg,
            'parse_mode' => 'HTML',
            'reply_markup' => json_encode($keyboard)
        ]));
    }

    echo json_encode([
        'success' => true,
        'pending' => true,
        'message' => 'Deposit submitted! Waiting for admin confirmation (usually within a few minutes).'
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}
