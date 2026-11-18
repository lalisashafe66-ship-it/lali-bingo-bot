<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST');

try {
    $dbPath = __DIR__ . '/bingo.db';
    $pdo = new PDO("sqlite:" . $dbPath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Enable WAL mode for concurrent write safety on Render
    $pdo->exec("PRAGMA journal_mode = WAL;");

    $pdo->exec("CREATE TABLE IF NOT EXISTS players (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        telegram_id TEXT UNIQUE,
        first_name TEXT,
        phone TEXT,
        balance REAL DEFAULT 0.00,
        referred_by TEXT DEFAULT NULL,
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

    $pdo->exec("CREATE TABLE IF NOT EXISTS game_winners (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        room_id INTEGER,
        telegram_id TEXT,
        winner_name TEXT,
        prize REAL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'error' => 'Database connection failed: ' . $e->getMessage()]);
    exit;
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';

if ($action === 'get_balance') {
    $telegramId = trim($_GET['telegram_id'] ?? '');
    
    if (empty($telegramId) || $telegramId === 'undefined' || $telegramId === 'null') {
        echo json_encode(['success' => false, 'balance' => 0.00, 'error' => 'Invalid Telegram ID']);
        exit;
    }

    // PERMANENT OVERRIDE: Lock balance for your Telegram ID so it never resets to 0.00
    if ($telegramId === '730967613') {
        echo json_encode(['success' => true, 'balance' => 50.00]);
        exit;
    }

    $stmt = $pdo->prepare("SELECT balance FROM players WHERE telegram_id = ?");
    $stmt->execute([$telegramId]);
    $player = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($player) {
        $balance = (float)$player['balance'];
    } else {
        $stmtIns = $pdo->prepare("INSERT OR IGNORE INTO players (telegram_id, first_name, phone, balance) VALUES (?, 'Player', 'WebUser', 0.00)");
        $stmtIns->execute([$telegramId]);
        
        $stmt->execute([$telegramId]);
        $playerCheck = $stmt->fetch(PDO::FETCH_ASSOC);
        $balance = $playerCheck ? (float)$playerCheck['balance'] : 0.00;
    }
    
    echo json_encode(['success' => true, 'balance' => $balance]);
}
elseif ($action === 'join_room') {
    $telegramId = trim($_POST['telegram_id'] ?? '');
    $fee = (float)($_POST['fee'] ?? 0);
    $playerNumber = (int)($_POST['player_number'] ?? 0);

    if ($playerNumber < 1 || $playerNumber > 400) {
        echo json_encode(['success' => false, 'error' => 'Please choose a valid cartela number between 1 and 400.']);
        exit;
    }

    // Bypass check for override account during game play deductions if needed, or let it deduct normally from db
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("SELECT balance FROM players WHERE telegram_id = ?");
        $stmt->execute([$telegramId]);
        $player = $stmt->fetch(PDO::FETCH_ASSOC);

        $currentBalance = ($telegramId === '730967613') ? 50.00 : ($player ? (float)$player['balance'] : 0.00);

        if ($currentBalance < $fee) {
            $pdo->rollBack();
            echo json_encode(['success' => false, 'error' => 'Insufficient balance! Please top up via Deposit.']);
            exit;
        }

        $newBalance = $currentBalance - $fee;
        
        // Ensure player exists in DB before updating
        $stmtIns = $pdo->prepare("INSERT OR IGNORE INTO players (telegram_id, first_name, phone, balance) VALUES (?, 'LÀLISHAA', 'WebUser', ?)");
        $stmtIns->execute([$telegramId, $newBalance]);

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

        $logRoom = $pdo->prepare("INSERT INTO room_players (room_id, telegram_id, player_number) VALUES (?, ?, ?)");
        $logRoom->execute([$roomId, $telegramId, $playerNumber]);

        $pdo->commit();

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
    } catch (Exception $e) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'error' => 'Transaction error: ' . $e->getMessage()]);
    }
}
elseif ($action === 'claim_win') {
    $telegramId = trim($_POST['telegram_id'] ?? '');
    $prize = (float)($_POST['prize'] ?? 0);
    $roomId = $_POST['room_id'] ?? 0;
    $winnerName = $_POST['winner_name'] ?? 'Player';

    $pdo->beginTransaction();
    try {
        $stmtIns = $pdo->prepare("INSERT OR IGNORE INTO players (telegram_id, first_name, phone, balance) VALUES (?, ?, 'WebUser', 0.00)");
        $stmtIns->execute([$telegramId, $winnerName]);

        $stmt = $pdo->prepare("UPDATE players SET balance = balance + ? WHERE telegram_id = ?");
        $stmt->execute([$prize, $telegramId]);

        $winLog = $pdo->prepare("INSERT INTO game_winners (room_id, telegram_id, winner_name, prize) VALUES (?, ?, ?, ?)");
        $winLog->execute([$roomId, $telegramId, $winnerName, $prize]);

        $roomUp = $pdo->prepare("UPDATE rooms SET status = 'finished' WHERE id = ?");
        $roomUp->execute([$roomId]);

        $pdo->commit();

        $balStmt = $pdo->prepare("SELECT balance FROM players WHERE telegram_id = ?");
        $balStmt->execute([$telegramId]);
        $updated = $balStmt->fetch(PDO::FETCH_ASSOC);

        echo json_encode([
            'success' => true,
            'new_balance' => $updated ? (float)$updated['balance'] : $prize
        ]);
    } catch (Exception $e) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'error' => 'Win claim error: ' . $e->getMessage()]);
    }
}
elseif ($action === 'request_withdrawal') {
    $telegramId = trim($_POST['telegram_id'] ?? '');
    $amount = (float)($_POST['amount'] ?? 0);
    $method = $_POST['method'] ?? 'Telebirr';
    $account = $_POST['account'] ?? '';

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("SELECT balance, first_name FROM players WHERE telegram_id = ?");
        $stmt->execute([$telegramId]);
        $player = $stmt->fetch(PDO::FETCH_ASSOC);

        $currentBal = ($telegramId === '730967613') ? 50.00 : ($player ? (float)$player['balance'] : 0.00);

        if ($currentBal < $amount || $amount <= 0) {
            $pdo->rollBack();
            echo json_encode(['success' => false, 'error' => 'Invalid balance or amount.']);
            exit;
        }

        $newBalance = $currentBal - $amount;
        
        $stmtIns = $pdo->prepare("INSERT OR IGNORE INTO players (telegram_id, first_name, phone, balance) VALUES (?, 'LÀLISHAA', 'WebUser', ?)");
        $stmtIns->execute([$telegramId, $newBalance]);

        $update = $pdo->prepare("UPDATE players SET balance = ? WHERE telegram_id = ?");
        $update->execute([$newBalance, $telegramId]);

        $log = $pdo->prepare("INSERT INTO withdrawals (telegram_id, amount, method, account, status) VALUES (?, ?, ?, ?, 'pending')");
        $log->execute([$telegramId, $amount, $method, $account]);

        $pdo->commit();

        $ADMIN_ID = "730967613";
        $token = getenv('TELEGRAM_BOT_TOKEN') ?: '9251047882:AAFbbYkqcqfPvhkXtK5cfTgm1Z5xghmqaq0';
        $adminMsg = "💸 <b>New Withdrawal Request (Future Bingo)!</b>\n\n" .
                    "• <b>Player:</b> " . ($player['first_name'] ?? 'LÀLISHAA') . "\n" .
                    "• <b>Telegram ID:</b> <code>{$telegramId}</code>\n" .
                    "• <b>Amount:</b> <b>{$amount} ETB</b>\n" .
                    "• <b>Method:</b> {$method}\n" .
                    "• <b>Account/Phone:</b> <code>{$account}</code>";

        @file_get_contents("https://api.telegram.org/bot{$token}/sendMessage?" . http_build_query([
            'chat_id' => $ADMIN_ID,
            'text' => $adminMsg,
            'parse_mode' => 'HTML'
        ]));

        echo json_encode([
            'success' => true,
            'new_balance' => $newBalance,
            'message' => 'Withdrawal request submitted! Admin has been notified.'
        ]);
    } catch (Exception $e) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'error' => 'Withdrawal error: ' . $e->getMessage()]);
    }
}
else {
    echo json_encode(['success' => false, 'error' => 'Invalid action']);
}
?>
