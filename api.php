<?php
declare(strict_types=1);

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST');

require_once __DIR__ . '/db.php';

function notifyAdminWithdrawal(string $token, string $chatId, string $message): void {
    if ($token === '' || $chatId === '') {
        error_log("SMART BINGO withdrawal notify: missing TELEGRAM_BOT_TOKEN or ADMIN_ID.");
        return;
    }

    $params = [
        'chat_id'    => $chatId,
        'text'       => $message,
        'parse_mode' => 'HTML',
    ];

    $url = "https://api.telegram.org/bot{$token}/sendMessage";
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $params,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
        ]);
        $res = curl_exec($ch);
        if ($res === false) {
            error_log("Telegram withdrawal cURL error: " . curl_error($ch));
        } else {
            error_log("Telegram withdrawal resp: " . $res);
        }
        curl_close($ch);
    } else {
        $ctx = stream_context_create([
            'http' => [
                'method'  => 'POST',
                'header'  => "Content-type: application/x-www-form-urlencoded\r\n",
                'content' => http_build_query($params),
                'timeout' => 10,
            ],
        ]);
        $res = @file_get_contents($url, false, $ctx);
        error_log("Telegram withdrawal fallback resp: " . var_export($res, true));
    }
}

try {
    $pdo = getBingoPdo();

    // Safe column migrations for existing SQLite files
    $existingCols = $pdo->query("PRAGMA table_info(rooms)")->fetchAll(PDO::FETCH_COLUMN, 1);
    $migrations = [
        'game_code'           => "ALTER TABLE rooms ADD COLUMN game_code TEXT",
        'starts_at'           => "ALTER TABLE rooms ADD COLUMN starts_at INTEGER",
        'started_at'          => "ALTER TABLE rooms ADD COLUMN started_at INTEGER",
        'draw_pool_json'      => "ALTER TABLE rooms ADD COLUMN draw_pool_json TEXT",
        'called_numbers_json' => "ALTER TABLE rooms ADD COLUMN called_numbers_json TEXT DEFAULT '[]'",
        'winners_json'        => "ALTER TABLE rooms ADD COLUMN winners_json TEXT DEFAULT '[]'",
    ];
    foreach ($migrations as $col => $sql) {
        if (!in_array($col, $existingCols, true)) {
            $pdo->exec($sql);
        }
    }

    $rpCols = $pdo->query("PRAGMA table_info(room_players)")->fetchAll(PDO::FETCH_COLUMN, 1);
    if (!in_array('first_name', $rpCols, true)) {
        $pdo->exec("ALTER TABLE room_players ADD COLUMN first_name TEXT");
    }
    if (!in_array('stake_paid', $rpCols, true)) {
        $pdo->exec("ALTER TABLE room_players ADD COLUMN stake_paid REAL DEFAULT 0");
    }
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'error' => 'Database connection failed: ' . $e->getMessage()]);
    exit;
}

function logBalanceChange(PDO $pdo, string $telegramId, ?int $roomId, string $type, float $amount, float $before, float $after, string $note = ''): void {
    $stmt = $pdo->prepare("INSERT INTO balance_ledger (telegram_id, room_id, type, amount, balance_before, balance_after, note) VALUES (?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([$telegramId, $roomId, $type, $amount, $before, $after, $note]);
}

function generateGameCode(int $length = 5): string {
    $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789';
    $code = '';
    for ($i = 0; $i < $length; $i++) {
        $code .= $chars[random_int(0, strlen($chars) - 1)];
    }
    return $code;
}

function generateCartelaBoard(int $cartelaNumber): array {
    $seed = ($cartelaNumber * 2654435761) & 0x7fffffff;
    if ($seed === 0) $seed = 1;

    $nextRand = function (int $maxExclusive) use (&$seed): int {
        $seed = ($seed * 1103515245 + 12345) & 0x7fffffff;
        return $seed % $maxExclusive;
    };

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
            $j = $nextRand($i + 1);
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
        [0,6,12,18,24], [4,8,12,16,20],
    ];

    foreach ($lines as $line) {
        $ok = true;
        foreach ($line as $idx) {
            if ($idx === 12) continue;
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

function finalizeRoomWinners(PDO $pdo, array $room, array $winners): array {
    $roomId = (int)$room['id'];

    // Cap at maximum 2 winners
    $winners = array_slice($winners, 0, 2);

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

    foreach ($winners as $w) {
        $wTid = (string)$w['telegram_id'];
        $wName = $w['name'];
        $checkLog = $pdo->prepare("SELECT id, prize FROM game_winners WHERE room_id = ? AND telegram_id = ? LIMIT 1");
        $checkLog->execute([$roomId, $wTid]);
        $prevWin = $checkLog->fetch(PDO::FETCH_ASSOC);

        if (!$prevWin) {
            $balStmt = $pdo->prepare("SELECT balance FROM players WHERE telegram_id = ?");
            $balStmt->execute([$wTid]);
            $before = (float)($balStmt->fetchColumn() ?: 0);
            $after = round($before + $sharePerWinner, 2);

            $pdo->prepare("UPDATE players SET balance = ? WHERE telegram_id = ?")
                ->execute([$after, $wTid]);
            $pdo->prepare("INSERT INTO game_winners (room_id, telegram_id, winner_name, prize) VALUES (?, ?, ?, ?)")
                ->execute([$roomId, $wTid, $wName, $sharePerWinner]);
            logBalanceChange($pdo, $wTid, $roomId, 'win_payout', $sharePerWinner, $before, $after, "Won Game " . ($room['game_code'] ?? $roomId));
        }
    }

    $pdo->prepare("UPDATE rooms SET status = 'finished', winners_json = ? WHERE id = ?")
        ->execute([json_encode($winners), $roomId]);

    $room['status'] = 'finished';
    $room['winners_json'] = json_encode($winners);
    return $room;
}

function syncRoomLifecycle(PDO $pdo, array $room): array {
    $now = time();
    $status = $room['status'];
    $startsAt = (int)($room['starts_at'] ?? (($room['created_at'] ?? $now) + 15));
    $startedAt = (int)($room['started_at'] ?? 0);
    $pool = json_decode($room['draw_pool_json'] ?? '[]', true);
    if (!is_array($pool) || count($pool) !== 75) {
        $pool = range(1, 75);
        shuffle($pool);
    }

    $drawIntervalSec = 0.8;
    $maxRoundSec = 60;

    // All players in the waiting lobby start together once countdown ends
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
        $calledSet = array_flip(array_map('intval', $called));

        $pdo->prepare("UPDATE rooms SET called_numbers_json = ? WHERE id = ?")
            ->execute([json_encode($called), $room['id']]);
        $room['called_numbers_json'] = json_encode($called);

        $rpStmt = $pdo->prepare("SELECT telegram_id, first_name, player_number FROM room_players WHERE room_id = ? ORDER BY id ASC");
        $rpStmt->execute([$room['id']]);
        $entries = $rpStmt->fetchAll(PDO::FETCH_ASSOC);

        $uniquePlayers = [];
        foreach ($entries as $e) {
            $uniquePlayers[(string)$e['telegram_id']] = true;
        }
        $targetWinners = (count($uniquePlayers) >= 2) ? 2 : 1;

        $autoWinners = [];
        $seenWinnerIds = [];
        foreach ($entries as $entry) {
            $tid = (string)$entry['telegram_id'];
            if (isset($seenWinnerIds[$tid])) {
                continue;
            }
            $cn = (int)$entry['player_number'];
            $board = generateCartelaBoard($cn);
            $winLine = hasWinningLine($board, $calledSet);
            if ($winLine !== null) {
                $seenWinnerIds[$tid] = true;
                $autoWinners[] = [
                    'telegram_id'    => $tid,
                    'name'           => $entry['first_name'] ?: 'Player',
                    'cartela_number' => $cn,
                    'board'          => $board,
                    'winning_line'   => $winLine,
                ];
            }
        }

        if (count($autoWinners) >= $targetWinners) {
            return finalizeRoomWinners($pdo, $room, $autoWinners);
        }

        if ($elapsed >= $maxRoundSec || count($called) >= 75) {
            if (count($autoWinners) > 0) {
                return finalizeRoomWinners($pdo, $room, $autoWinners);
            }
            $pdo->prepare("UPDATE rooms SET status = 'finished' WHERE id = ?")
                ->execute([$room['id']]);
            $room['status'] = 'finished';
        }
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
    $startsAt = (int)($room['starts_at'] ?? ($now + 15));
    $lobbySecondsLeft = max(0, $startsAt - $now);

    $startedAt = (int)($room['started_at'] ?? $now);
    $roundSecondsLeft = ($room['status'] === 'active') ? max(0, 60 - ($now - $startedAt)) : 0;

    return [
        'room_id'            => (int)$room['id'],
        'game_code'          => $room['game_code'] ?: '1T6BX',
        'stake'              => (float)$room['stake'],
        'status'             => $room['status'],
        'lobby_seconds_left' => $lobbySecondsLeft,
        'round_seconds_left' => $roundSecondsLeft,
        'players_count'      => $playersCount,
        'taken_cartelas'     => array_values(array_unique($takenCartelas)),
        'derash'             => $derash,
        'bonus'              => 0,
        'calls_count'        => count($called),
        'called_numbers'     => $called,
        'winners'            => $winners,
    ];
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';

if ($action === 'get_balance') {
    $telegramId = trim((string)($_GET['telegram_id'] ?? ''));

    if ($telegramId === '' || $telegramId === 'undefined' || $telegramId === 'null') {
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
        $stmtIns = $pdo->prepare("INSERT INTO players (telegram_id, first_name, phone, balance) VALUES (?, 'Player', 'WebUser', ?)");
        $stmtIns->execute([$telegramId, $initialBal]);
        logBalanceChange($pdo, $telegramId, null, 'welcome_bonus', $initialBal, 0.00, $initialBal, 'Initial player registration');
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

    $payload = buildRoomPayload($pdo, $room);

    // If the active round just finished, open a fresh waiting round with countdown so players can join next round
    if ($payload['status'] === 'finished' && $stake > 0) {
        $now = time();
        $code = generateGameCode(5);
        $pool = range(1, 75);
        shuffle($pool);
        $insRoom = $pdo->prepare("INSERT INTO rooms (game_code, stake, status, created_at, starts_at, draw_pool_json, called_numbers_json, winners_json) VALUES (?, ?, 'waiting', ?, ?, ?, '[]', '[]')");
        $insRoom->execute([$code, $stake, $now, $now + 15, json_encode($pool)]);
        $newRoomId = (int)$pdo->lastInsertId();
        $newStmt = $pdo->prepare("SELECT * FROM rooms WHERE id = ?");
        $newStmt->execute([$newRoomId]);
        $newRoom = $newStmt->fetch(PDO::FETCH_ASSOC);
        if ($newRoom) {
            echo json_encode([
                'success'           => true,
                'previous_finished' => true,
                'finished_room'     => $payload,
                'room'              => buildRoomPayload($pdo, $newRoom),
            ]);
            exit;
        }
    }

    echo json_encode(['success' => true, 'room' => $payload]);
}
elseif ($action === 'join_room') {
    $telegramId = trim((string)($_POST['telegram_id'] ?? ''));
    $firstName = trim((string)($_POST['first_name'] ?? 'Player'));
    $stake = (float)($_POST['stake'] ?? $_POST['fee'] ?? 0);
    $cartelasRaw = $_POST['cartela_numbers'] ?? '';

    if ($telegramId === '' || $telegramId === 'undefined' || $telegramId === 'null') {
        echo json_encode(['success' => false, 'error' => 'Invalid Telegram ID.']);
        exit;
    }

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

    if ($stake <= 0) {
        echo json_encode(['success' => false, 'error' => 'Invalid table stake.']);
        exit;
    }

    $totalFee = round($stake * count($cartelaNumbers), 2);

    $pdo->beginTransaction();
    try {
        // Check if a round for this stake is currently active (in progress)
        $activeStmt = $pdo->prepare("SELECT * FROM rooms WHERE stake = ? AND status = 'active' ORDER BY id DESC LIMIT 1");
        $activeStmt->execute([$stake]);
        $activeRoom = $activeStmt->fetch(PDO::FETCH_ASSOC);

        if ($activeRoom) {
            $payload = buildRoomPayload($pdo, $activeRoom);
            if ($payload['status'] === 'active') {
                $pdo->commit();
                echo json_encode([
                    'success'         => false,
                    'already_started' => true,
                    'room'            => $payload,
                    'error'           => 'you are visiting now, please wait for the next cartella to start the game',
                ]);
                exit;
            }
        }

        $stmt = $pdo->prepare("SELECT balance, first_name FROM players WHERE telegram_id = ?");
        $stmt->execute([$telegramId]);
        $player = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$player) {
            $ins = $pdo->prepare("INSERT INTO players (telegram_id, first_name, phone, balance) VALUES (?, ?, 'WebUser', 50.00)");
            $ins->execute([$telegramId, $firstName ?: 'Player']);
            $currentBalance = 50.00;
        } else {
            $currentBalance = (float)$player['balance'];
            if ($firstName !== '' && $player['first_name'] !== $firstName) {
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
            $insRoom->execute([$code, $stake, $now, $now + 15, json_encode($pool)]);
            $roomId = (int)$pdo->lastInsertId();
            $roomStmt->execute([$stake]);
            $room = $roomStmt->fetch(PDO::FETCH_ASSOC);
        } else {
            $roomId = (int)$room['id'];
        }

        // If countdown already reached zero right as this request arrived, start round and block late entry
        $preCheckPayload = buildRoomPayload($pdo, $room);
        if ($preCheckPayload['status'] === 'active') {
            $pdo->commit();
            echo json_encode([
                'success'         => false,
                'already_started' => true,
                'room'            => $preCheckPayload,
                'error'           => 'you are visiting now, please wait for the next cartella to start the game',
            ]);
            exit;
        }

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

        $newBalance = round($currentBalance - $totalFee, 2);
        $pdo->prepare("UPDATE players SET balance = ? WHERE telegram_id = ?")->execute([$newBalance, $telegramId]);
        logBalanceChange($pdo, $telegramId, $roomId, 'stake_deduct', -$totalFee, $currentBalance, $newBalance, "Joined Game " . ($room['game_code'] ?? $roomId) . " (" . count($cartelaNumbers) . " cartela)");

        $logRoom = $pdo->prepare("INSERT INTO room_players (room_id, telegram_id, first_name, player_number, stake_paid) VALUES (?, ?, ?, ?, ?)");
        foreach ($cartelaNumbers as $cn) {
            $logRoom->execute([$roomId, $telegramId, $firstName ?: 'Player', $cn, $stake]);
        }

        $pdo->commit();

        $payload = buildRoomPayload($pdo, $room);
        echo json_encode([
            'success'         => true,
            'room_id'         => $roomId,
            'cartela_numbers' => $cartelaNumbers,
            'total_players'   => $payload['players_count'],
            'prize_pool'      => $payload['derash'],
            'new_balance'     => $newBalance,
            'room'            => $payload,
        ]);
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        echo json_encode(['success' => false, 'error' => 'Transaction error: ' . $e->getMessage()]);
    }
}
elseif ($action === 'request_withdrawal') {
    $telegramId = trim((string)($_POST['telegram_id'] ?? ''));
    $amount = round((float)($_POST['amount'] ?? 0), 2);
    $method = trim((string)($_POST['method'] ?? 'Telebirr'));
    $account = trim((string)($_POST['account'] ?? ''));

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("SELECT balance, first_name FROM players WHERE telegram_id = ?");
        $stmt->execute([$telegramId]);
        $player = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$player) {
            $pdo->rollBack();
            echo json_encode(['success' => false, 'error' => 'Player record not found.']);
            exit;
        }

        $currentBal = (float)$player['balance'];

        if ($currentBal < $amount || $amount <= 0) {
            $pdo->rollBack();
            echo json_encode(['success' => false, 'error' => 'Invalid balance or amount.']);
            exit;
        }

        $newBalance = round($currentBal - $amount, 2);

        $pdo->prepare("UPDATE players SET balance = ? WHERE telegram_id = ?")->execute([$newBalance, $telegramId]);
        $pdo->prepare("INSERT INTO withdrawals (telegram_id, amount, method, account, status) VALUES (?, ?, ?, ?, 'pending')")->execute([$telegramId, $amount, $method, $account]);
        logBalanceChange($pdo, $telegramId, null, 'withdrawal', -$amount, $currentBal, $newBalance, "Withdrawal via {$method} ({$account})");

        $pdo->commit();

        $ADMIN_ID = trim((string)(getenv('ADMIN_TELEGRAM_ID') ?: '730967613'));
        $token = trim((string)(getenv('TELEGRAM_BOT_TOKEN') ?: ''));
        $safePlayerName = htmlspecialchars((string)($player['first_name'] ?? 'Player'), ENT_QUOTES, 'UTF-8');
        $safeMethod = htmlspecialchars($method, ENT_QUOTES, 'UTF-8');
        $safeAccount = htmlspecialchars($account, ENT_QUOTES, 'UTF-8');
        $adminMsg = "💸 <b>New Withdrawal Request (SMART BINGO)!</b>\n\n" .
                    "• <b>Player:</b> {$safePlayerName}\n" .
                    "• <b>Telegram ID:</b> <code>{$telegramId}</code>\n" .
                    "• <b>Amount:</b> <b>{$amount} ETB</b>\n" .
                    "• <b>Method:</b> {$safeMethod}\n" .
                    "• <b>Account/Phone:</b> <code>{$safeAccount}</code>";

        notifyAdminWithdrawal($token, $ADMIN_ID, $adminMsg);

        echo json_encode([
            'success'     => true,
            'new_balance' => $newBalance,
            'message'     => 'Withdrawal request submitted! Admin has been notified.',
        ]);
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log("request_withdrawal error: " . $e->getMessage());
        echo json_encode(['success' => false, 'error' => 'Withdrawal error: ' . $e->getMessage()]);
    }
}
else {
    echo json_encode(['success' => false, 'error' => 'Invalid action']);
}
