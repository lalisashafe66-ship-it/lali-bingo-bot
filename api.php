<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST');

// REPLACE WITH YOUR ACTUAL CHAPA SECRET KEY FROM YOUR DASHBOARD
define('CHAPA_SECRET_KEY', 'YOUR_CHASECK_SECRET_KEY_HERE');

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

    $pdo->exec("CREATE TABLE IF NOT EXISTS rooms (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        stake REAL,
        status TEXT DEFAULT 'waiting',
        created_at INTEGER
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS withdrawals (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        telegram_id TEXT,
        amount REAL,
        method TEXT,
        account TEXT,
        status TEXT DEFAULT 'pending',
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
        $stmtIns = $pdo->prepare("INSERT OR IGNORE INTO players (telegram_id, first_name, balance) VALUES (?, 'Player', 0.00)");
        $stmtIns->execute([$telegramId]);
        echo json_encode(['success' => false, 'error' => 'Insufficient balance! Please top up via Deposit in the Wallet tab.']);
        exit;
    }

    $newBalance = $player['balance'] - $fee;
    $update = $pdo->prepare("UPDATE players SET balance = ? WHERE telegram_id = ?");
    $update->execute([$newBalance, $telegramId]);

    // Find or create shared multiplayer room for this stake
    $roomStmt = $pdo->prepare("SELECT * FROM rooms WHERE stake = ? AND status = 'waiting' ORDER BY id ASC LIMIT 1");
    $roomStmt->execute([$fee]);
    $room = $roomStmt->fetch(PDO::FETCH_ASSOC);

    if (!$room) {
        $insRoom = $pdo->prepare("INSERT INTO rooms (stake, status, created_at) VALUES (?, 'waiting', ?)");
        $insRoom->execute([$fee, time()]);
        $roomId = $pdo->lastInsertId();
    } else {
        $roomId = $room['id'];
    }

    echo json_encode([
        'success' => true,
        'room_id' => $roomId,
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
elseif ($action === 'request_withdrawal') {
    $telegramId = $_POST['telegram_id'] ?? '';
    $amount = (float)($_POST['amount'] ?? 0);
    $method = $_POST['method'] ?? 'Telebirr';
    $account = $_POST['account'] ?? '';

    $stmt = $pdo->prepare("SELECT balance FROM players WHERE telegram_id = ?");
    $stmt->execute([$telegramId]);
    $player = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$player || $player['balance'] < $amount || $amount <= 0) {
        echo json_encode(['success' => false, 'error' => 'Invalid balance or amount.']);
        exit;
    }

    // Call Chapa Transfer / Payout API for automated delivery
    $chapaBankCode = (strtolower($method) === 'cbe') ? 'CBE' : 'Telebirr';
    
    $payload = json_encode([
        "account_name" => "Lali Bingo Player",
        "account_number" => $account,
        "amount" => $amount,
        "currency" => "ETB",
        "bank_code" => $chapaBankCode
    ]);

    $ch = curl_init('https://api.chapa.co/v1/transfers');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Authorization: Bearer " . CHAPA_SECRET_KEY,
        "Content-Type: application/json"
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $chapaRes = json_decode($response, true);

    if ($httpCode === 200 || ($chapaRes['status'] ?? '') === 'success') {
        $newBalance = $player['balance'] - $amount;
        $update = $pdo->prepare("UPDATE players SET balance = ? WHERE telegram_id = ?");
        $update->execute([$newBalance, $telegramId]);

        $log = $pdo->prepare("INSERT INTO withdrawals (telegram_id, amount, method, account, status) VALUES (?, ?, ?, ?, 'success')");
        $log->execute([$telegramId, $amount, $method, $account]);

        echo json_encode([
            'success' => true,
            'new_balance' => $newBalance,
            'message' => 'Withdrawal sent successfully to your account!'
        ]);
    } else {
        $newBalance = $player['balance'] - $amount;
        $update = $pdo->prepare("UPDATE players SET balance = ? WHERE telegram_id = ?");
        $update->execute([$newBalance, $telegramId]);

        $log = $pdo->prepare("INSERT INTO withdrawals (telegram_id, amount, method, account, status) VALUES (?, ?, ?, ?, 'pending_gateway')");
        $log->execute([$telegramId, $amount, $method, $account]);

        echo json_encode([
            'success' => true,
            'new_balance' => $newBalance,
            'message' => 'Withdrawal request submitted successfully.'
        ]);
    }
}
else {
    echo json_encode(['success' => false, 'error' => 'Invalid action']);
}
?>
