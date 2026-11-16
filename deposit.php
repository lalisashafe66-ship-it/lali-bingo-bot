<?php
// deposit.php - Handles deposit requests and transaction code submission
header('Content-Type: application/json');

$input = json_decode(file_get_contents('php://input'), true);
$telegramId = $_POST['telegram_id'] ?? $input['telegram_id'] ?? '';
$amount = (float)($_POST['amount'] ?? $input['amount'] ?? 0);
$txRef = trim($_POST['tx_ref'] ?? $input['tx_ref'] ?? '');

if (!$telegramId || $amount < 10) {
    echo json_encode(['success' => false, 'error' => 'Invalid amount or player ID. Minimum deposit is 10 ETB.']);
    exit;
}

try {
    $pdo = new PDO("sqlite:" . __DIR__ . "/bingo.db");
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Create transactions table if it doesn't exist
    $pdo->exec("CREATE TABLE IF NOT EXISTS transactions (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        telegram_id TEXT,
        tx_reference TEXT UNIQUE,
        amount REAL,
        status TEXT DEFAULT 'unclaimed',
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

        // Insert transaction as pending/unclaimed
        $stmt = $pdo->prepare("INSERT INTO transactions (telegram_id, tx_reference, amount, status) VALUES (?, ?, ?, 'unclaimed')");
        $stmt->execute([$telegramId, $txRef]);

        echo json_encode([
            'success' => true,
            'message' => 'Transaction reference submitted successfully! Tap Verify in your bot or app to credit your balance.'
        ]);
    } else {
        echo json_encode([
            'success' => true,
            'instructions' => 'Transfer money to CBE: 1000171689116 or Telebirr: 0917461538, then submit your Transaction ID.'
        ]);
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>
