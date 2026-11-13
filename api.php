<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST');

try {
    $pdo = new PDO("sqlite:" . __DIR__ . "/bingo.db");
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $pdo->exec("CREATE TABLE IF NOT EXISTS players (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        telegram_id TEXT UNIQUE,
        first_name TEXT,
        phone TEXT,
        balance REAL DEFAULT 0.00,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'error' => 'Database connection failed']);
    exit;
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';

if ($action === 'get_balance') {
    $telegramId = $_GET['telegram_id'] ?? '';
    $stmt = $pdo->prepare("SELECT balance FROM players WHERE telegram_id = ?");
    $stmt->execute([$telegramId]);
    $player = $stmt->fetch(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'balance' => $player ? (float)$player['balance'] : 0.00
    ]);
}
elseif ($action === 'join_room') {
    $telegramId = $_POST['telegram_id'] ?? '';
    $fee = (float)($_POST['fee'] ?? 0);

    $stmt = $pdo->prepare("SELECT balance FROM players WHERE telegram_id = ?");
    $stmt->execute([$telegramId]);
    $player = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$player || $player['balance'] < $fee) {
        echo json_encode(['success' => false, 'error' => 'Insufficient balance! Please top up via Deposit in the bot.']);
        exit;
    }

    $newBalance = $player['balance'] - $fee;
    $update = $pdo->prepare("UPDATE players SET balance = ? WHERE telegram_id = ?");
    $update->execute([$newBalance, $telegramId]);

    echo json_encode([
        'success' => true,
        'new_balance' => $newBalance
    ]);
}
elseif ($action === 'claim_win') {
    $telegramId = $_POST['telegram_id'] ?? '';
    $prize = (float)($_POST['prize'] ?? 0);

    $stmt = $pdo->prepare("UPDATE players SET balance = balance + ? WHERE telegram_id = ?");
    $stmt->execute([$prize, $telegramId]);

    $balStmt = $pdo->prepare("SELECT balance FROM players WHERE telegram_id = ?");
    $balStmt->execute([$telegramId]);
    $updated = $balStmt->fetch(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'new_balance' => $updated ? (float)$updated['balance'] : 0.00
    ]);
}
else {
    echo json_encode(['success' => false, 'error' => 'Invalid action']);
}
?>
