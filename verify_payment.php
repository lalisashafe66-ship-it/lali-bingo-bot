<?php
// verify_payment.php - Verifies local transaction reference and credits wallet
header('Content-Type: application/json');

$input = json_decode(file_get_contents('php://input'), true);
$telegramId = $_POST['telegram_id'] ?? $_GET['telegram_id'] ?? $input['telegram_id'] ?? '';
$txRef = trim($_POST['tx_ref'] ?? $_GET['tx_ref'] ?? $input['tx_ref'] ?? '');

if (!$telegramId || !$txRef) {
    echo json_encode(['success' => false, 'message' => 'Missing Telegram ID or Transaction Reference.']);
    exit;
}

try {
    $pdo = new PDO("sqlite:" . __DIR__ . "/bingo.db");
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Ensure transactions table exists to prevent SQLSTATE errors
    $pdo->exec("CREATE TABLE IF NOT EXISTS transactions (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        telegram_id TEXT,
        tx_reference TEXT UNIQUE,
        amount REAL,
        source TEXT,
        status TEXT DEFAULT 'unclaimed',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    $stmt = $pdo->prepare("SELECT * FROM transactions WHERE tx_reference = ? AND status = 'unclaimed'");
    $stmt->execute([$txRef]);
    $tx = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$tx) {
        echo json_encode(['success' => false, 'message' => 'Transaction ID not found, already claimed, or waiting for SMS confirmation from your SMS forwarder.']);
        exit;
    }

    $amount = (float)$tx['amount'];

    $updateTx = $pdo->prepare("UPDATE transactions SET status = 'claimed', telegram_id = ? WHERE id = ?");
    $updateTx->execute([$telegramId, $tx['id']]);

    $updateBalance = $pdo->prepare("UPDATE players SET balance = balance + ? WHERE telegram_id = ?");
    $updateBalance->execute([$amount, $telegramId]);

    $stmt = $pdo->prepare("SELECT balance FROM players WHERE telegram_id = ?");
    $stmt->execute([$telegramId]);
    $player = $stmt->fetch(PDO::FETCH_ASSOC);
    $newBal = $player ? $player['balance'] : $amount;

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
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
