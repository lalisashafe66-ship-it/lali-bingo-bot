<?php
// verify_payment.php - Verifies local transaction reference and credits wallet with exact amount
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle browser preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$telegramId = trim($_POST['telegram_id'] ?? $_GET['telegram_id'] ?? $input['telegram_id'] ?? '');
$txRef = trim($_POST['tx_ref'] ?? $_GET['tx_ref'] ?? $input['tx_ref'] ?? '');

if (!$telegramId || !$txRef) {
    echo json_encode(['success' => false, 'message' => 'Missing Telegram ID or Transaction Reference.']);
    exit;
}

try {
    $dbPath = __DIR__ . '/bingo.db';
    $pdo = new PDO("sqlite:" . $dbPath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Ensure tables exist
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
        status TEXT DEFAULT 'unclaimed',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    // Query for the transaction
    $stmt = $pdo->prepare("SELECT * FROM transactions WHERE tx_reference = ? AND status = 'unclaimed'");
    $stmt->execute([$txRef]);
    $tx = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$tx) {
        echo json_encode([
            'success' => false, 
            'message' => 'Transaction ID not found or waiting for SMS confirmation from your phone. Please wait a few seconds and try again!'
        ]);
        exit;
    }

    $amount = (float)$tx['amount'];

    // Mark transaction as claimed
    $updateTx = $pdo->prepare("UPDATE transactions SET status = 'claimed', telegram_id = ? WHERE id = ?");
    $updateTx->execute([$telegramId, $tx['id']]);

    // Ensure player row exists using INSERT OR IGNORE pattern
    $stmtPlayer = $pdo->prepare("SELECT balance FROM players WHERE telegram_id = ?");
    $stmtPlayer->execute([$telegramId]);
    $player = $stmtPlayer->fetch(PDO::FETCH_ASSOC);

    if (!$player) {
        $insPlayer = $pdo->prepare("INSERT OR IGNORE INTO players (telegram_id, first_name, phone, balance) VALUES (?, 'Player', 'WebUser', 0.00)");
        $insPlayer->execute([$telegramId]);
    }

    // Safely update player balance
    $updateBalance = $pdo->prepare("UPDATE players SET balance = balance + ? WHERE telegram_id = ?");
    $updateBalance->execute([$amount, $telegramId]);

    // Fetch final updated balance
    $stmtPlayer->execute([$telegramId]);
    $updatedPlayer = $stmtPlayer->fetch(PDO::FETCH_ASSOC);
    $newBal = $updatedPlayer ? (float)$updatedPlayer['balance'] : $amount;

    // Send Telegram Notification
    $token = getenv('TELEGRAM_BOT_TOKEN') ?: '9251047882:AAFbbYkqcqfPvhkXtK5cfTgm1Z5xghmqaq0';
    $msg = "🎉 <b>Automated Deposit Confirmed!</b>\n\n" .
           "<b>{$amount} ETB</b> has been added to your Future Bingo wallet.\n" .
           "Current Balance: <b>{$newBal} ETB</b>";

    @file_get_contents("https://api.telegram.org/bot{$token}/sendMessage?" . http_build_query([
        'chat_id' => $telegramId,
        'text' => $msg,
        'parse_mode' => 'HTML'
    ]));

    echo json_encode([
        'success' => true,
        'message' => "Successfully credited {$amount} ETB!",
        'new_balance' => $newBal
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}
?>
