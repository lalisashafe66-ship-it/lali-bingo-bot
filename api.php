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

    $pdo->exec("CREATE TABLE IF NOT EXISTS rooms (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        stake REAL,
        status TEXT DEFAULT 'waiting',
        created_at INTEGER
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS room_players (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        room_id INTEGER,
        telegram_id TEXT,
        player_number INTEGER,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
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

    // Table to track game winners
    $pdo->exec("CREATE TABLE IF NOT EXISTS game_winners (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        room_id INTEGER,
        telegram_id TEXT,
        winner_name TEXT,
        prize REAL,
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
    
    if (!$player) {
        $stmtIns = $pdo->prepare("INSERT OR IGNORE INTO players (telegram_id, first_name, balance) VALUES (?, 'Player', 0.00)");
        $stmtIns->execute([$telegramId]);
        $balance = 0.00;
    } else {
        $balance = (float)$player['balance'];
    }
    
    echo json_encode(['success' => true, 'balance' => $balance]);
}
elseif ($action === 'join_room') {
    $telegramId = $_POST['telegram_id'] ?? '';
    $fee = (float)($_POST['fee'] ?? 0);
    $playerNumber = (int)($_POST['player_number'] ?? 0);

    if ($playerNumber < 1 || $playerNumber > 56) {
        echo json_encode(['success' => false, 'error' => 'Please choose a valid cartela number between 1 and 56.']);
        exit;
    }

    $stmt = $pdo->prepare("SELECT balance FROM players WHERE telegram_id = ?");
    $stmt->execute([$telegramId]);
    $player = $stmt->fetch(PDO::FETCH_ASSOC);

    $currentBalance = $player ? (float)$player['balance'] : 0.00;

    if ($currentBalance < $fee) {
        echo json_encode(['success' => false, 'error' => 'Insufficient balance! Please top up via Deposit.']);
        exit;
    }

    $newBalance = $currentBalance - $fee;
    $update = $pdo->prepare("UPDATE players SET balance = ? WHERE telegram_id = ?");
    $update->execute([$newBalance, $telegramId]);

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

    $logRoom = $pdo->prepare("INSERT OR REPLACE INTO room_players (room_id, telegram_id, player_number) VALUES (?, ?, ?)");
    $logRoom->execute([$roomId, $telegramId, $playerNumber]);

    $countStmt = $pdo->prepare("SELECT COUNT(DISTINCT telegram_id) as total_players FROM room_players WHERE room_id = ?");
    $countStmt->execute([$roomId]);
    $countRes = $countStmt->fetch(PDO::FETCH_ASSOC);
    $totalPlayers = $countRes ? (int)$countRes['total_players'] : 1;

    echo json_encode([
        'success' => true,
        'room_id' => $roomId,
        'player_number' => $playerNumber,
        'total_players' => $totalPlayers,
        'new_balance' => $newBalance
    ]);
}
elseif ($action === 'claim_win') {
    $telegramId = $_POST['telegram_id'] ?? '';
    $prize = (float)($_POST['prize'] ?? 0);
    $roomId = $_POST['room_id'] ?? 0;
    $winnerName = $_POST['winner_name'] ?? 'Player';

    // Add prize to player balance
    $stmt = $pdo->prepare("UPDATE players SET balance = balance + ? WHERE telegram_id = ?");
    $stmt->execute([$prize, $telegramId]);

    // Log winner name and ID
    $winLog = $pdo->prepare("INSERT INTO game_winners (room_id, telegram_id, winner_name, prize) VALUES (?, ?, ?, ?)");
    $winLog->execute([$roomId, $telegramId, $winnerName, $prize]);

    // Mark room as finished/playing ended
    $roomUp = $pdo->prepare("UPDATE rooms SET status = 'finished' WHERE id = ?");
    $roomUp->execute([$roomId]);

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

    $newBalance = $player['balance'] - $amount;
    $update = $pdo->prepare("UPDATE players SET balance = ? WHERE telegram_id = ?");
    $update->execute([$newBalance, $telegramId]);

    $log = $pdo->prepare("INSERT INTO withdrawals (telegram_id, amount, method, account, status) VALUES (?, ?, ?, ?, 'pending')");
    $log->execute([$telegramId, $amount, $method, $account]);

    echo json_encode([
        'success' => true,
        'new_balance' => $newBalance,
        'message' => 'Withdrawal request submitted!'
    ]);
}
else {
    echo json_encode(['success' => false, 'error' => 'Invalid action']);
}
?>
