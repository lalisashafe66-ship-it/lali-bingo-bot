<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST');

try {
    $dbPath = __DIR__ . '/bingo.db';
    $pdo = new PDO("sqlite:" . $dbPath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec("PRAGMA journal_mode = WAL;");

    $pdo->exec("CREATE TABLE IF NOT EXISTS players (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        telegram_id TEXT UNIQUE,
        first_name TEXT,
        phone TEXT,
        balance REAL DEFAULT 50.00,
        referred_by TEXT DEFAULT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS rooms (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        game_code TEXT,
        stake REAL,
        status TEXT DEFAULT 'waiting',
        created_at INTEGER,
        starts_at INTEGER,
        started_at INTEGER,
        draw_pool_json TEXT,
        called_numbers_json TEXT DEFAULT '[]',
        winners_json TEXT DEFAULT '[]'
    )");

    // Safe column migrations for existing bingo.db files
    $existingCols = $pdo->query("PRAGMA table_info(rooms)")->fetchAll(PDO::FETCH_COLUMN, 1);
    $migrations = [
        'game_code' => "ALTER TABLE rooms ADD COLUMN game_code TEXT",
        'starts_at' => "ALTER TABLE rooms ADD COLUMN starts_at INTEGER",
        'started_at' => "ALTER TABLE rooms ADD COLUMN started_at INTEGER",
        'draw_pool_json' => "ALTER TABLE rooms ADD COLUMN draw_pool_json TEXT",
        'called_numbers_json' => "ALTER TABLE rooms ADD COLUMN called_numbers_json TEXT DEFAULT '[]'",
        'winners_json' => "ALTER TABLE rooms ADD COLUMN winners_json TEXT DEFAULT '[]'"
    ];
    foreach ($migrations as $col => $sql) {
        if (!in_array($col, $existingCols, true)) {
            $pdo->exec($sql);
        }
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS room_players (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        room_id INTEGER,
        telegram_id TEXT,
        first_name TEXT,
        player_number INTEGER,
        stake_paid REAL DEFAULT 0,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    $rpCols = $pdo->query("PRAGMA table_info(room_players)")->fetchAll(PDO::FETCH_COLUMN, 1);
    if (!in_array('first_name', $rpCols, true)) {
        $pdo->exec("ALTER TABLE room_players ADD COLUMN first_name TEXT");
    }
    if (!in_array('stake_paid', $rpCols, true)) {
        $pdo->exec("ALTER TABLE room_players ADD COLUMN stake_paid REAL DEFAULT 0");
    }

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

function generateGameCode(int $length = 5): string {
    $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789';
    $code = '';
    for ($i = 0; $i < $length; $i++) {
        $code .= $chars[random_int(0, strlen($chars) - 1)];
    }
    return $code;
}

/**
 * Deterministically generates a 5x5 Bingo cartela (row-major array of 25 ints, index 12 = 0/FREE)
 * for any cartela number from 1 to 400.
 */
function generateCartelaBoard(int $cartelaNumber): array {
    $seed = ($cartelaNumber * 2654435761) & 0x7fffffff;
    mt_srand($seed ?: 1);

    $ranges = [
        [1, 15],
        [16, 30],
        [31, 45],
        [46, 60],
        [61, 75],
    ];

    $cols = [];
    foreach ($ranges as [$min, $max]) {
        $pool = range($min, $max);
        for ($i = count($pool) - 1; $i > 0; $i--) {
            $j = mt_rand(0, $i);
            [$pool[$i], $pool[$j]] = [$pool[$j], $pool[$i]];
        }
        $cols[] = array_slice($pool, 0, 5);
    }

    $board = [];
    for ($r = 0; $r < 5; $r++) {
        for ($c = 0; $c < 5; $c++) {
            $board[] = ($r === 2 && $c === 2) ? 0 : $cols[$c][$r];
        }
    }
    return $board;
}

function hasWinningLine(array $board, array $calledSet): ?array {
    $lines = [
        [0,1,2,3,4], [5,6,7,8,9], [10,11,12,13,14], [15,16,17,18,19], [20,21,22,23,24],
        [0,5,10,15,20], [1,6,11,16,21], [2,7,12,17,22], [3,8,13,18,23], [4,9,14,19,24],
        [0,6,12,18,24], [4,8,12,16,20]
    ];

    foreach ($lines as $line) {
        $ok = true;
        foreach ($line as $idx) {
            if ($idx === 12) continue; // Free center
            $val = (int)($board[$idx] ?? -1);
            if (!isset($calledSet[$val])) {
                $ok = false;
                break;
            }
        }
        if ($ok) {
            return $line;
        }
    }
    return null;
}

/**
 * Advances room state based on server timestamps:
 * - waiting -> active once starts_at is reached (or after 20s)
 * - active -> draws 1 number every 2.5s (up to 75 numbers or 60s round)
 */
function syncRoomLifecycle(PDO $pdo, array $room): array {
    $now = time();
    $status = $room['status'];
    $startsAt = (int)($room['starts_at'] ?? (($room['created_at'] ?? $now) + 20));
    $startedAt = (int)($room['started_at'] ?? 0);
    $pool = json_decode($room['draw_pool_json'] ?? '[]', true);
    if (!is_array($pool) || count($pool) !== 75) {
        $pool = range(1, 75);
        shuffle($pool);
    }

    $drawIntervalSec = 2.5;
    $maxRoundSec = 90;

    if ($status === 'waiting' && $now >= $startsAt) {
        $status = 'active';
        $startedAt = $now;
        $stmt = $pdo->prepare("UPDATE rooms SET status = 'active', started_at = ?, draw_pool_json = ?, called_numbers_json = '[]' WHERE id = ?");
        $stmt->execute([$startedAt, json_encode($pool), $room['id']]);
        $room['status'] = 'active';
        $room['started_at'] = $startedAt;
        $room['draw_pool_json'] = json_encode($pool);
        $room['called_numbers_json'] = '[]';
    }

    if ($status === 'active') {
        $elapsed = max(0, $now - $startedAt);
        $targetCalls = min(75, (int)floor($elapsed / $drawIntervalSec) + 1);
        $called = array_slice($pool, 0, $targetCalls);

        if ($elapsed >= $maxRoundSec || count($called) >= 75) {
            $winners = json_decode($room['winners_json'] ?? '[]', true);
            if (!is_array($winners) || count($winners) === 0) {
                $pdo->prepare("UPDATE rooms SET status = 'finished', called_numbers_json = ? WHERE id = ?")
                    ->execute([json_encode($called), $room['id']]);
                $room['status'] = 'finished';
            }
        } else {
            $pdo->prepare("UPDATE rooms SET called_numbers_json = ? WHERE id = ?")
                ->execute([json_encode($called), $room['id']]);
        }
        $room['called_numbers_json'] = json_encode($called);
    }

    return $room;
}

function buildRoomPayload(PDO $pdo, array $room): array {
    $room = syncRoomLifecycle($pdo, $room);
    $called = json_decode($room['called_numbers_json'] ?? '[]', true);
    if (!is_array($called)) $called = [];

    $winners = json_decode($room['winners_json'] ?? '[]', true);
    if (!is_array($winners)) $winners = [];

    $playersStmt = $pdo->prepare("SELECT telegram_id, first_name, player_number, stake_paid FROM room_players WHERE room_id = ? ORDER BY id ASC");
    $playersStmt->execute([$room['id']]);
    $rows = $playersStmt->fetchAll(PDO::FETCH_ASSOC);

    $uniquePlayers = [];
    $takenCartelas = [];
    $totalStakes = 0.0;

    foreach ($rows as $r) {
        $uniquePlayers[$r['telegram_id']] = true;
        $takenCartelas[] = (int)$r['player_number'];
        $totalStakes += (float)($r['stake_paid'] ?? $room['stake']);
    }

    $playersCount = count($uniquePlayers);
    $derash = round($totalStakes * 0.80, 2);

    $now = time();
    $startsAt = (int)($room['starts_at'] ?? ($now + 20));
    $lobbySecondsLeft = max(0, $startsAt - $now);

    $startedAt = (int)($room['started_at'] ?? $now);
    $roundSecondsLeft = ($room['status'] === 'active') ? max(0, 90 - ($now - $startedAt)) : 0;

    return [
        'room_id' => (int)$room['id'],
        'game_code' => $room['game_code'] ?: '1T6BX',
        'stake' => (float)$room['stake'],
        'status' => $room['status'],
        'lobby_seconds_left' => $lobbySecondsLeft,
        'round_seconds_left' => $roundSecondsLeft,
        'players_count' => $playersCount,
        'taken_cartelas' => array_values(array_unique($takenCartelas)),
        'derash' => $derash,
        'bonus' => 0,
        'calls_count' => count($called),
        'called_numbers' => $called,
        'winners' => $winners,
    ];
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';

if ($action === 'get_balance') {
    $telegramId = trim($_GET['telegram_id'] ?? '');

    if (empty($telegramId) || $telegramId === 'undefined' || $telegramId === 'null') {
        echo json_encode(['success' => false, 'balance' => 0.00, 'error' => 'Invalid Telegram ID']);
        exit;
    }

    $stmt = $pdo->prepare("SELECT balance FROM players WHERE telegram_id = ?");
    $stmt->execute([$telegramId]);
    $player = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($player) {
        $balance = (float)$player['balance'];
    } else {
        $initialBal = 50.00;
        $stmtIns = $pdo->prepare("INSERT INTO players (telegram_id, first_name, phone, balance) VALUES (?, 'LÀLISHAA', 'WebUser', ?)");
        $stmtIns->execute([$telegramId, $initialBal]);
        $balance = $initialBal;
    }

    echo json_encode(['success' => true, 'balance' => $balance]);
}
elseif ($action === 'get_room_state') {
    $roomId = (int)($_GET['room_id'] ?? $_POST['room_id'] ?? 0);
    $stake = (float)($_GET['stake'] ?? $_POST['stake'] ?? 0);

    if ($roomId > 0) {
        $stmt = $pdo->prepare("SELECT * FROM rooms WHERE id = ?");
        $stmt->execute([$roomId]);
        $room = $stmt->fetch(PDO::FETCH_ASSOC);
    } elseif ($stake > 0) {
        $stmt = $pdo->prepare("SELECT * FROM rooms WHERE stake = ? AND status IN ('active', 'waiting') ORDER BY id DESC LIMIT 1");
        $stmt->execute([$stake]);
        $room = $stmt->fetch(PDO::FETCH_ASSOC);
    } else {
        $room = null;
    }

    if (!$room) {
        echo json_encode(['success' => true, 'room' => null]);
        exit;
    }

    echo json_encode(['success' => true, 'room' => buildRoomPayload($pdo, $room)]);
}
elseif ($action === 'join_room') {
    $telegramId = trim($_POST['telegram_id'] ?? '');
    $firstName = trim($_POST['first_name'] ?? 'Player');
    $stake = (float)($_POST['stake'] ?? $_POST['fee'] ?? 0);
    $cartelasRaw = $_POST['cartela_numbers'] ?? '';

    $cartelaNumbers = [];
    if (is_array($cartelasRaw)) {
        foreach ($cartelasRaw as $n) $cartelaNumbers[] = (int)$n;
    } elseif (is_string($cartelasRaw) && trim($cartelasRaw) !== '') {
        foreach (explode(',', $cartelasRaw) as $n) $cartelaNumbers[] = (int)trim($n);
    } else {
        $single = (int)($_POST['player_number'] ?? 0);
        if ($single > 0) $cartelaNumbers[] = $single;
    }

    $cartelaNumbers = array_values(array_unique(array_filter($cartelaNumbers, fn($n) => $n >= 1 && $n <= 400)));

    if (count($cartelaNumbers) < 1 || count($cartelaNumbers) > 3) {
        echo json_encode(['success' => false, 'error' => 'Please choose between 1 and 3 valid cartelas (1 - 400).']);
        exit;
    }

    // Check if an active round is already running for this stake
    $activeStmt = $pdo->prepare("SELECT * FROM rooms WHERE stake = ? AND status = 'active' ORDER BY id DESC LIMIT 1");
    $activeStmt->execute([$stake]);
    $activeRoom = $activeStmt->fetch(PDO::FETCH_ASSOC);

    if ($activeRoom) {
        $payload = buildRoomPayload($pdo, $activeRoom);
        if ($payload['status'] === 'active') {
            echo json_encode([
                'success' => false,
                'already_started' => true,
                'room' => $payload,
                'error' => 'Game is already in progress. Please wait until this game finishes!'
            ]);
            exit;
        }
    }

    $totalFee = $stake * count($cartelaNumbers);

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("SELECT balance, first_name FROM players WHERE telegram_id = ?");
        $stmt->execute([$telegramId]);
        $player = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$player) {
            $ins = $pdo->prepare("INSERT INTO players (telegram_id, first_name, phone, balance) VALUES (?, ?, 'WebUser', 50.00)");
            $ins->execute([$telegramId, $firstName ?: 'LÀLISHAA']);
            $currentBalance = 50.00;
        } else {
            $currentBalance = (float)$player['balance'];
            if (!empty($firstName) && $player['first_name'] !== $firstName) {
                $pdo->prepare("UPDATE players SET first_name = ? WHERE telegram_id = ?")->execute([$firstName, $telegramId]);
            }
        }

        if ($currentBalance < $totalFee) {
            $pdo->rollBack();
            echo json_encode(['success' => false, 'error' => 'Insufficient balance! Please top up via Deposit.']);
            exit;
        }

        $roomStmt = $pdo->prepare("SELECT * FROM rooms WHERE stake = ? AND status = 'waiting' ORDER BY id ASC LIMIT 1");
        $roomStmt->execute([$stake]);
        $room = $roomStmt->fetch(PDO::FETCH_ASSOC);

        if (!$room) {
            $now = time();
            $code = generateGameCode(5);
            $pool = range(1, 75);
            shuffle($pool);
            $insRoom = $pdo->prepare("INSERT INTO rooms (game_code, stake, status, created_at, starts_at, draw_pool_json, called_numbers_json, winners_json) VALUES (?, ?, 'waiting', ?, ?, ?, '[]', '[]')");
            $insRoom->execute([$code, $stake, $now, $now + 20, json_encode($pool)]);
            $roomId = (int)$pdo->lastInsertId();
            $roomStmt->execute([$stake]);
            $room = $roomStmt->fetch(PDO::FETCH_ASSOC);
        } else {
            $roomId = (int)$room['id'];
        }

        // Prevent duplicate cartela selection inside the same waiting room
        $takenStmt = $pdo->prepare("SELECT player_number FROM room_players WHERE room_id = ?");
        $takenStmt->execute([$roomId]);
        $takenList = $takenStmt->fetchAll(PDO::FETCH_COLUMN, 0);
        foreach ($cartelaNumbers as $cn) {
            if (in_array($cn, $takenList, false)) {
                $pdo->rollBack();
                echo json_encode(['success' => false, 'error' => "Cartela #{$cn} is already taken in this round."]);
                exit;
            }
        }

        $newBalance = $currentBalance - $totalFee;
        $pdo->prepare("UPDATE players SET balance = ? WHERE telegram_id = ?")->execute([$newBalance, $telegramId]);

        $logRoom = $pdo->prepare("INSERT INTO room_players (room_id, telegram_id, first_name, player_number, stake_paid) VALUES (?, ?, ?, ?, ?)");
        foreach ($cartelaNumbers as $cn) {
            $logRoom->execute([$roomId, $telegramId, $firstName ?: 'Player', $cn, $stake]);
        }

        $pdo->commit();

        $payload = buildRoomPayload($pdo, $room);
        echo json_encode([
            'success' => true,
            'room_id' => $roomId,
            'cartela_numbers' => $cartelaNumbers,
            'total_players' => $payload['players_count'],
            'prize_pool' => $payload['derash'],
            'new_balance' => $newBalance,
            'room' => $payload
        ]);
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        echo json_encode(['success' => false, 'error' => 'Transaction error: ' . $e->getMessage()]);
    }
}
elseif ($action === 'claim_win') {
    $telegramId = trim($_POST['telegram_id'] ?? '');
    $roomId = (int)($_POST['room_id'] ?? 0);
    $cartelaNumber = (int)($_POST['cartela_number'] ?? 0);
    $winnerName = trim($_POST['winner_name'] ?? 'Player');

    if ($roomId <= 0 || $cartelaNumber < 1 || $cartelaNumber > 400) {
        echo json_encode(['success' => false, 'error' => 'Invalid room or cartela number.']);
        exit;
    }

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("SELECT * FROM rooms WHERE id = ?");
        $stmt->execute([$roomId]);
        $room = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$room) {
            $pdo->rollBack();
            echo json_encode(['success' => false, 'error' => 'Room not found.']);
            exit;
        }

        $room = syncRoomLifecycle($pdo, $room);
        $called = json_decode($room['called_numbers_json'] ?? '[]', true);
        if (!is_array($called)) $called = [];
        $calledSet = array_flip(array_map('intval', $called));

        $board = generateCartelaBoard($cartelaNumber);
        $winningLine = hasWinningLine($board, $calledSet);

        if ($winningLine === null) {
            $pdo->rollBack();
            echo json_encode(['success' => false, 'error' => 'Not a valid Bingo line on the server called numbers yet!']);
            exit;
        }

        // Verify player actually holds this cartela in this room
        $rpStmt = $pdo->prepare("SELECT first_name FROM room_players WHERE room_id = ? AND telegram_id = ? AND player_number = ? LIMIT 1");
        $rpStmt->execute([$roomId, $telegramId, $cartelaNumber]);
        $rp = $rpStmt->fetch(PDO::FETCH_ASSOC);
        if (!$rp) {
            $pdo->rollBack();
            echo json_encode(['success' => false, 'error' => 'Cartela does not belong to you in this room.']);
            exit;
        }

        $winners = json_decode($room['winners_json'] ?? '[]', true);
        if (!is_array($winners)) $winners = [];

        $alreadyInWinners = false;
        foreach ($winners as $w) {
            if (($w['telegram_id'] ?? '') === $telegramId && (int)($w['cartela_number'] ?? 0) === $cartelaNumber) {
                $alreadyInWinners = true;
                break;
            }
        }

        if (!$alreadyInWinners) {
            $winners[] = [
                'telegram_id' => $telegramId,
                'name' => $winnerName ?: ($rp['first_name'] ?: 'Player'),
                'cartela_number' => $cartelaNumber,
                'board' => $board,
                'winning_line' => $winningLine,
            ];
        }

        // Calculate 80% pot and split evenly among all winners
        $stakesStmt = $pdo->prepare("SELECT COALESCE(SUM(stake_paid), 0) AS total_stakes FROM room_players WHERE room_id = ?");
        $stakesStmt->execute([$roomId]);
        $totalStakes = (float)($stakesStmt->fetchColumn() ?: 0);
        $totalDerash = round($totalStakes * 0.80, 2);
        $winnerCount = max(1, count($winners));
        $sharePerWinner = round($totalDerash / $winnerCount, 2);

        foreach ($winners as &$w) {
            $w['prize'] = $sharePerWinner;
        }
        unset($w);

        // Credit each winner's balance cleanly (idempotent per room_id + telegram_id)
        foreach ($winners as $w) {
            $wTid = $w['telegram_id'];
            $wName = $w['name'];
            $checkLog = $pdo->prepare("SELECT id, prize FROM game_winners WHERE room_id = ? AND telegram_id = ? LIMIT 1");
            $checkLog->execute([$roomId, $wTid]);
            $prevWin = $checkLog->fetch(PDO::FETCH_ASSOC);

            if (!$prevWin) {
                $pdo->prepare("UPDATE players SET balance = balance + ? WHERE telegram_id = ?")
                    ->execute([$sharePerWinner, $wTid]);
                $pdo->prepare("INSERT INTO game_winners (room_id, telegram_id, winner_name, prize) VALUES (?, ?, ?, ?)")
                    ->execute([$roomId, $wTid, $wName, $sharePerWinner]);
            } else {
                $oldPrize = (float)$prevWin['prize'];
                $diff = round($sharePerWinner - $oldPrize, 2);
                if (abs($diff) > 0.001) {
                    $pdo->prepare("UPDATE players SET balance = balance + ? WHERE telegram_id = ?")
                        ->execute([$diff, $wTid]);
                    $pdo->prepare("UPDATE game_winners SET prize = ? WHERE id = ?")
                        ->execute([$sharePerWinner, $prevWin['id']]);
                }
            }
        }

        $pdo->prepare("UPDATE rooms SET status = 'finished', winners_json = ? WHERE id = ?")
            ->execute([json_encode($winners), $roomId]);

        $pdo->commit();

        $balStmt = $pdo->prepare("SELECT balance FROM players WHERE telegram_id = ?");
        $balStmt->execute([$telegramId]);
        $updatedBal = (float)($balStmt->fetchColumn() ?: 0);

        $updatedRoomStmt = $pdo->prepare("SELECT * FROM rooms WHERE id = ?");
        $updatedRoomStmt->execute([$roomId]);
        $updatedRoom = $updatedRoomStmt->fetch(PDO::FETCH_ASSOC);

        echo json_encode([
            'success' => true,
            'new_balance' => $updatedBal,
            'share_prize' => $sharePerWinner,
            'winners' => $winners,
            'room' => buildRoomPayload($pdo, $updatedRoom)
        ]);
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        echo json_encode(['success' => false, 'error' => 'Win verification error: ' . $e->getMessage()]);
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

        $currentBal = $player ? (float)$player['balance'] : 50.00;

        if ($currentBal < $amount || $amount <= 0) {
            $pdo->rollBack();
            echo json_encode(['success' => false, 'error' => 'Invalid balance or amount.']);
            exit;
        }

        $newBalance = $currentBal - $amount;

        $update = $pdo->prepare("UPDATE players SET balance = ? WHERE telegram_id = ?");
        $update->execute([$newBalance, $telegramId]);

        $log = $pdo->prepare("INSERT INTO withdrawals (telegram_id, amount, method, account, status) VALUES (?, ?, ?, ?, 'pending')");
        $log->execute([$telegramId, $amount, $method, $account]);

        $pdo->commit();

        $ADMIN_ID = "730967613";
        $token = getenv('TELEGRAM_BOT_TOKEN') ?: '';
        if ($token !== '') {
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
        }

        echo json_encode([
            'success' => true,
            'new_balance' => $newBalance,
            'message' => 'Withdrawal request submitted! Admin has been notified.'
        ]);
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        echo json_encode(['success' => false, 'error' => 'Withdrawal error: ' . $e->getMessage()]);
    }
}
else {
    echo json_encode(['success' => false, 'error' => 'Invalid action']);
}
