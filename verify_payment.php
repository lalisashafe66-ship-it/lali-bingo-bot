<?php
// verify_payment.php - Strictly verifies existing unclaimed transactions and credits wallet
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

if ($telegramId === '' || $txRef === '') {
    echo json_encode(['success' => false, 'message' => 'Missing Telegram ID or Transaction Reference.']);
    exit;
}

// Optional sanity check: reject obviously fake/empty strings (adjust length/regex to match your bank/gateway)
if (strlen($txRef) < 6 || !preg_match('/^[A-Z0-9_-]+$/', $txRef)) {
    echo json_encode(['success' => false, 'message' => 'Invalid transaction reference format.']);
    exit;
}

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
        status TEXT DEFAULT 'unclaimed',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    $pdo->beginTransaction();

    // Look up the transaction reference (case-insensitive match recommended)
    $stmt = $pdo->prepare("SELECT * FROM transactions WHERE UPPER(tx_reference) = ? LIMIT 1");
    $stmt->execute([$txRef]);
    $tx = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$tx) {
        $pdo->rollBack();
        echo json_encode([
            'success' => false,
            'message' => 'Transaction reference not found. Please check your reference ID or wait a moment for confirmation.'
        ]);
        exit;
    }

    if ($tx['status'] !== 'unclaimed') {
        $pdo->rollBack();
        echo json_encode([
            'success' => false,
            'message' => 'This Transaction ID has already been used.'
        ]);
        exit;
    }

    $amount = (float)$tx['amount'];
    if ($amount <= 0) {
        $pdo->rollBack();
        echo json_encode([
            'success' => false,
            'message' => 'Invalid transaction amount recorded.'
        ]);
        exit;
    }

    // Atomically claim the transaction so it can never be claimed twice
    $claimTx = $pdo->prepare("UPDATE transactions SET status = 'claimed', telegram_id = ? WHERE id = ? AND status = 'unclaimed'");
    $claimTx->execute([$telegramId, $tx['id']]);

    if ($claimTx->rowCount() === 0) {
        $pdo->rollBack();
        echo json_encode([
            'success' => false,
            'message' => 'This Transaction ID was just claimed.'
        ]);
        exit;
    }

    // Ensure player row exists
    $insPlayer = $pdo->prepare("INSERT OR IGNORE INTO players (telegram_id, first_name, phone, balance) VALUES (?, 'Player', 'WebUser', 0.00)");
    $insPlayer->execute([$telegramId]);

    // Credit player balance
    $updateBalance = $pdo->prepare("UPDATE players SET balance = ROUND(balance + ?, 2) WHERE telegram_id = ?");
    $updateBalance->execute([$amount, $telegramId]);

    // Fetch updated balance
    $stmtPlayer = $pdo->prepare("SELECT balance FROM players WHERE telegram_id = ?");
    $stmtPlayer->execute([$telegramId]);
    $updatedPlayer = $stmtPlayer->fetch(PDO::FETCH_ASSOC);
    $newBal = $updatedPlayer ? (float)$updatedPlayer['balance'] : $amount;

    $pdo->commit();

    // Send Telegram Notification using environment variable
    $token = getenv('TELEGRAM_BOT_TOKEN') ?: '';
    if ($token !== '') {
        $msg = "🎉 <b>Automated Deposit Confirmed!</b>\n\n" .
               "<b>{$amount} ETB</b> has been added to your Future Bingo wallet.\n" .
               "Current Balance: <b>{$newBal} ETB</b>";

        @file_get_contents("https://api.telegram.org/bot{$token}/sendMessage?" . http_build_query([
            'chat_id' => $telegramId,
            'text' => $msg,
            'parse_mode' => 'HTML'
        ]));
    }

    echo json_encode([
        'success' => true,
        'message' => "Successfully credited {$amount} ETB!",
        'new_balance' => $newBal
    ]);

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}
