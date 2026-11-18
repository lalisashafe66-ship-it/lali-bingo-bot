<?php
// deposit.php - Handles deposit requests, logs transactions, and instantly credits player balance
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');

$input = json_decode(file_get_contents('php://input'), true);
$telegramId = trim($_POST['telegram_id'] ?? $input['telegram_id'] ?? '');
$amount = (float)($_POST['amount'] ?? $input['amount'] ?? 0);
$txRef = trim($_POST['tx_ref'] ?? $input['tx_ref'] ?? '');

if (!$telegramId || $amount < 10) {
    echo json_encode(['success' => false, 'error' => 'Invalid amount or player ID. Minimum deposit is 10 ETB.']);
    exit;
}

try {
    $dbPath = __DIR__ . "/bingo.db";
    $pdo = new PDO("sqlite:" . $dbPath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Enable WAL mode for concurrent write safety
    $pdo->exec("PRAGMA journal_mode = WAL;");

    // Create transactions table if it doesn't exist
    $pdo->exec("CREATE TABLE IF NOT EXISTS transactions (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        telegram_id TEXT,
        tx_reference TEXT UNIQUE,
        amount REAL,
        status TEXT DEFAULT 'verified',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    // Ensure players table exists
    $pdo->exec("CREATE TABLE IF NOT EXISTS players (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        telegram_id TEXT UNIQUE,
        first_name TEXT,
        phone TEXT,
        balance REAL DEFAULT 0.00,
        referred_by TEXT DEFAULT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    if (!empty($txRef)) {
        // Check if transaction code already exists
        $stmt = $pdo->prepare("SELECT * FROM transactions WHERE tx_reference = ?");
        $stmt->execute([$txRef]);
        if ($stmt->fetch()) {
            echo json_encode(['success' => false, 'error' => 'This Transaction ID has already been used or submitted.']);
            exit;
        }

        // Begin transaction to ensure data integrity
        $pdo->beginTransaction();

        // 1. Insert transaction as verified
        $stmt = $pdo->prepare("INSERT INTO transactions (telegram_id, tx_reference, amount, status) VALUES (?, ?, ?, 'verified')");
        $stmt->execute([$telegramId, $txRef, $amount]);

        // 2. Permanently credit player's balance in the players table (upsert/update)
        $updateStmt = $pdo->prepare("INSERT INTO players (telegram_id, balance) VALUES (?, ?) 
                                     ON CONFLICT(telegram_id) DO UPDATE SET balance = balance + ?");
        $updateStmt->execute([$telegramId, $amount, $amount]);

        $pdo->commit();

        echo json_encode([
            'success' => true,
            'message' => 'Deposit successful! Your balance has been updated instantly.',
            'amount' => $amount
        ]);
    } else {
        echo json_encode([
            'success' => true,
            'instructions' => 'Transfer money to CBE: 1000171689116 or Telebirr: 0917461538, then submit your Transaction ID.'
        ]);
    }
} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>
