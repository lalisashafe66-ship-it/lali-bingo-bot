<?php
declare(strict_types=1);

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST');

require_once __DIR__ . '/db.php';

function isPgsql(PDO $pdo): bool {
    return strtolower((string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)) === 'pgsql';
}

function getColumnNames(PDO $pdo, string $table): array {
    if (!preg_match('/^[a-zA-Z0-9_]+$/', $table)) {
        return [];
    }

    if (isPgsql($pdo)) {
        $stmt = $pdo->prepare("
            SELECT column_name
            FROM information_schema.columns
            WHERE table_schema = 'public' AND table_name = ?
        ");
        $stmt->execute([$table]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN, 0) ?: [];
    }

    return $pdo->query("PRAGMA table_info({$table})")->fetchAll(PDO::FETCH_COLUMN, 1) ?: [];
}

function lastInsertIdCompat(PDO $pdo, string $sequenceOrTable): int {
    if (isPgsql($pdo)) {
        $val = $pdo->lastInsertId($sequenceOrTable);
        if ($val !== false && $val !== '') {
            return (int)$val;
        }
        return (int)$pdo->lastInsertId();
    }
    return (int)$pdo->lastInsertId();
}

function sendTelegramMessage(string $token, string $chatId, string $message): bool {
    if ($token === '' || $chatId === '') {
        error_log("SMART BINGO notify: missing TELEGRAM_BOT_TOKEN or chat_id.");
        return false;
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
            error_log("Telegram sendMessage cURL error: " . curl_error($ch));
            curl_close($ch);
            return false;
        }
        error_log("Telegram sendMessage resp: " . $res);
        curl_close($ch);
        return true;
    }

    $ctx = stream_context_create([
        'http' => [
            'method'  => 'POST',
            'header'  => "Content-type: application/x-www-form-urlencoded\r\n",
            'content' => http_build_query($params),
            'timeout' => 10,
        ],
    ]);
    $res = @file_get_contents($url, false, $ctx);
    error_log("Telegram sendMessage fallback resp: " . var_export($res, true));
    return $res !== false;
}

function notifyAdminWithdrawal(string $token, string $chatId, string $message): void {
    sendTelegramMessage($token, $chatId, $message);
}

try {
    $pdo = getBingoPdo();
    $pgsql = isPgsql($pdo);

    $existingCols = getColumnNames($pdo, 'rooms');
    $roomMigrations = $pgsql ? [
        'game_code'           => "ALTER TABLE rooms ADD COLUMN IF NOT EXISTS game_code TEXT",
        'starts_at'           => "ALTER TABLE rooms ADD COLUMN IF NOT EXISTS starts_at BIGINT",
        'started_at'          => "ALTER TABLE rooms ADD COLUMN IF NOT EXISTS started_at BIGINT",
        'draw_pool_json'      => "ALTER TABLE rooms ADD COLUMN IF NOT EXISTS draw_pool_json TEXT",
        'called_numbers_json' => "ALTER TABLE rooms ADD COLUMN IF NOT EXISTS called_numbers_json TEXT DEFAULT '[]'",
        'winners_json'        => "ALTER TABLE rooms ADD COLUMN IF NOT EXISTS winners_json TEXT DEFAULT '[]'",
    ] : [
        'game_code'           => "ALTER TABLE rooms ADD COLUMN game_code TEXT",
        'starts_at'           => "ALTER TABLE rooms ADD COLUMN starts_at INTEGER",
        'started_at'          => "ALTER TABLE rooms ADD COLUMN started_at INTEGER",
        'draw_pool_json'      => "ALTER TABLE rooms ADD COLUMN draw_pool_json TEXT",
        'called_numbers_json' => "ALTER TABLE rooms ADD COLUMN called_numbers_json TEXT DEFAULT '[]'",
        'winners_json'        => "ALTER TABLE rooms ADD COLUMN winners_json TEXT DEFAULT '[]'",
    ];

    foreach ($roomMigrations as $col => $sql) {
        if (!in_array($col, $existingCols, true)) {
            $pdo->exec($sql);
        }
    }

    $rpCols = getColumnNames($pdo, 'room_players');
    if (!in_array('first_name', $rpCols, true)) {
        $pdo->exec($pgsql
            ? "ALTER TABLE room_players ADD COLUMN IF NOT EXISTS first_name TEXT"
            : "ALTER TABLE room_players ADD COLUMN first_name TEXT"
        );
    }
    if (!in_array('stake_paid', $rpCols, true)) {
        $pdo->exec($pgsql
            ? "ALTER TABLE room_players ADD COLUMN IF NOT EXISTS stake_paid DOUBLE PRECISION DEFAULT 0"
            : "ALTER TABLE room_players ADD COLUMN stake_paid REAL DEFAULT 0"
        );
    }
} catch (Throwable $e) {
    echo json_encode(['success' => false, 'error' => 'Database connection failed: ' . $e->getMessage()]);
    exit;
}

function logBalanceChange(PDO $pdo, string $telegramId, ?int $roomId, string $type, float $amount, float $before, float $after, string $note = ''): void {
    $stmt = $pdo->prepare("INSERT INTO balance_ledger (telegram_id, room_id, type, amount, balance_before, balance_after, note) VALUES (?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([$telegramId, $roomId, $type, $amount, $before, $after, $note]);
}

function givePlayerBonus(PDO $pdo, string $telegramId, float $amount, string $note = 'Bonus from Admin', bool $notifyUser = true): array {
    $amount = round($amount, 2);
    $telegramId = preg_replace('/\D+/', '', trim($telegramId)) ?? '';

    if ($telegramId === '' || $amount <= 0) {
        return ['success' => false, 'error' => 'Invalid Telegram ID or bonus amount.'];
    }

    while ($pdo->inTransaction()) {
        try {
            $pdo->rollBack();
        } catch (Throwable $ignoreRb) {
            break;
        }
    }

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("SELECT balance, first_name FROM players WHERE CAST(telegram_id AS TEXT) = ? LIMIT 1");
        $stmt->execute([$telegramId]);
        $player = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$player) {
            $pdo->rollBack();
            return ['success' => false, 'error' => 'Player record not found.'];
        }

        $before = round((float)$player['balance'], 2);
        $after  = round($before + $amount, 2);

        $pdo->prepare("UPDATE players SET balance = ? WHERE CAST(telegram_id AS TEXT) = ?")
            ->execute([$after, $telegramId]);

        $pdo->commit();

        try {
            logBalanceChange($pdo, $telegramId, null, 'bonus', $amount, $before, $after, $note);
        } catch (Throwable $ledgerErr) {
            error_log("givePlayerBonus ledger skipped: " . $ledgerErr->getMessage());
        }

        $notified = false;
        if ($notifyUser) {
            $token = trim((string)(getenv('TELEGRAM_BOT_TOKEN') ?: ''));
            $safeName = htmlspecialchars((string)($player['first_name'] ?? 'Player'), ENT_QUOTES, 'UTF-8');
            $safeNote = htmlspecialchars($note, ENT_QUOTES, 'UTF-8');
            $formattedAmt = number_format($amount, 2);
            $formattedBal = number_format($after, 2);

            $userMsg = "🎁 <b>Bonus Deposited (SMART BINGO)!</b>\n\n" .
                       "• <b>Player:</b> {$safeName}\n" .
                       "• <b>Bonus Amount:</b> <b>+{$formattedAmt} ETB</b>\n" .
                       "• <b>New Balance:</b> <b>{$formattedBal} ETB</b>\n" .
                       "• <b>Note:</b> {$safeNote}";

            $notified = sendTelegramMessage($token, $telegramId, $userMsg);
        }

        return [
            'success'     => true,
            'telegram_id' => $telegramId,
            'bonus_added' => $amount,
            'new_balance' => $after,
            'notified'    => $notified,
        ];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            try {
                $pdo->rollBack();
            } catch (Throwable $rbErr) {
                error_log("givePlayerBonus rollback error: " . $rbErr->getMessage());
            }
        }
        error_log("givePlayerBonus error: " . $e->getMessage());
        return ['success' => false, 'error' => 'Bonus transaction error: ' . $e->getMessage()];
    }
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

function resolvePlayerDisplayName(PDO $pdo, string $telegramId, string $roomFirstName = ''): string {
    $name = trim($roomFirstName);
    if ($name !== '' && strcasecmp($name, 'Player') !== 0) {
        return $name;
    }

    if ($telegramId !== '') {
        $stmt = $pdo->prepare("SELECT first_name, username FROM players WHERE CAST(telegram_id AS TEXT) = ? LIMIT 1");
        $stmt->execute([$telegramId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $pFirst = trim((string)($row['first_name'] ?? ''));
            $pUser  = trim((string)($row['username'] ?? ''));
            if ($pFirst !== '' && strcasecmp($pFirst, 'Player') !== 0) {
                return $pFirst;
            }
            if ($pUser !== '') {
                return ltrim($pUser, '@');
            }
        }
    }

    return $name !== '' ? $name : 'Player';
}

function recordNonWinningPlayers(PDO $pdo, int $roomId, string $gameLabel, array $winnerIds): void {
    $rpStmt = $pdo->prepare("SELECT telegram_id, COALESCE(SUM(stake_paid), 0) AS player_stake FROM room_players WHERE room_id = ? GROUP BY telegram_id");
    $rpStmt->execute([$roomId]);
    $players = $rpStmt->fetchAll(PDO::FETCH_ASSOC);

    $winnerMap = array_flip(array_map('strval', $winnerIds));

    foreach ($players as $p) {
        $tid = (string)$p['telegram_id'];
        if (isset($winnerMap[$tid])) {
            continue;
        }
        $stakeLost = round((float)($p['player_stake'] ?? 0), 2);
        if ($stakeLost <= 0) {
            continue;
        }

        try {
            $dupStmt = $pdo->prepare("SELECT id FROM balance_ledger WHERE CAST(telegram_id AS TEXT) = ? AND room_id = ? AND type = 'loss_settled' LIMIT 1");
            $dupStmt->execute([$tid, $roomId]);
            if ($dupStmt->fetchColumn()) {
                continue;
            }

            $balStmt = $pdo->prepare("SELECT balance FROM players WHERE CAST(telegram_id AS TEXT) = ?");
            $balStmt->execute([$tid]);
            $curBal = round((float)($balStmt->fetchColumn() ?: 0), 2);
            logBalanceChange($pdo, $tid, $roomId, 'loss_settled', -$stakeLost, $curBal, $curBal, "Lost Game {$gameLabel} (stake {$stakeLost} ETB already deducted)");
        } catch (Throwable $ledgerErr) {
            error_log("recordNonWinningPlayers ledger skipped: " . $ledgerErr->getMessage());
        }
    }
}

function sendWinnerCongratulations(
    PDO $pdo,
    int $roomId,
    string $gameLabel,
    array $winners,
    float $totalDerash,
    float $sharePerWinner
): void {
    if (count($winners) === 0) {
        return;
    }

    $token = trim((string)(getenv('TELEGRAM_BOT_TOKEN') ?: ''));
    $adminId = trim((string)(getenv('ADMIN_TELEGRAM_ID') ?: '730967613'));
    if ($token === '') {
        return;
    }

    $safeGame = htmlspecialchars($gameLabel, ENT_QUOTES, 'UTF-8');
    $shareStr = number_format($sharePerWinner, 2);
    $poolStr  = number_format($totalDerash, 2);

    $winnerNamesEscaped = [];
    foreach ($winners as $w) {
        $winnerNamesEscaped[] = htmlspecialchars((string)($w['name'] ?? 'Player'), ENT_QUOTES, 'UTF-8');
    }
    $namesHeadline = implode(' &amp; ', $winnerNamesEscaped);

    $isSplit = count($winners) > 1;
    $summaryLine = $isSplit
        ? "🎉 <b>Congratulations {$namesHeadline}!</b> You closed a line together (max 2 co-winners) in Game <b>#{$safeGame}</b> and share the <b>{$poolStr} ETB</b> pool equally: <b>{$shareStr} ETB each</b>!"
        : "🎉 <b>Congratulations {$namesHeadline}!</b> You closed a line first in Game <b>#{$safeGame}</b> and won <b>{$shareStr} ETB</b>!";

    foreach ($winners as $w) {
        $tid = trim((string)($w['telegram_id'] ?? ''));
        if ($tid === '') {
            continue;
        }
        $pName = htmlspecialchars((string)($w['name'] ?? 'Player'), ENT_QUOTES, 'UTF-8');
        $cartelaNo = (int)($w['cartela_number'] ?? 0);
        $cartelaLine = $cartelaNo > 0 ? "\n• <b>Winning Cartela:</b> #{$cartelaNo}" : "";
        $splitLine = $isSplit ? "\n• <b>Shared Pool:</b> {$poolStr} ETB (split between 2 winners)" : "";

        $msg = "🏆 <b>BINGO LINE CLOSED — CONGRATULATIONS!</b>\n\n"
             . "Congratulations, <b>{$pName}</b>! 🎉\n"
             . "You completed a winning line in Game <b>#{$safeGame}</b>!{$cartelaLine}\n"
             . "• <b>Prize Won:</b> <b>+{$shareStr} ETB</b>{$splitLine}";

        sendTelegramMessage($token, $tid, $msg);
    }

    $rpStmt = $pdo->prepare("SELECT DISTINCT telegram_id FROM room_players WHERE room_id = ?");
    $rpStmt->execute([$roomId]);
    $roomPlayerIds = $rpStmt->fetchAll(PDO::FETCH_COLUMN, 0) ?: [];

    $winnerIdSet = array_flip(array_map(fn($w) => (string)($w['telegram_id'] ?? ''), $winners));
    $roomNotice = "🛑 <b>Round Ended — Line Closed!</b>\n\n"
                . "{$summaryLine}";

    foreach ($roomPlayerIds as $pid) {
        $pidStr = trim((string)$pid);
        if ($pidStr === '' || isset($winnerIdSet[$pidStr])) {
            continue;
        }
        sendTelegramMessage($token, $pidStr, $roomNotice);
    }

    if ($adminId !== '') {
        sendTelegramMessage($token, $adminId, "📊 <b>Game #{$safeGame} Finished</b>\n\n{$summaryLine}");
    }
}

function finalizeRoomWinners(PDO $pdo, array $room, array $winners): array {
    $roomId = (int)$room['id'];
    $gameLabel = (string)($room['game_code'] ?: $roomId);

    while ($pdo->inTransaction()) {
        try {
            $pdo->rollBack();
        } catch (Throwable $ignoreRb) {
            break;
        }
    }

    $pdo->beginTransaction();
    try {
        $curStmt = $pdo->prepare("SELECT status, winners_json FROM rooms WHERE id = ? LIMIT 1");
        $curStmt->execute([$roomId]);
        $curRow = $curStmt->fetch(PDO::FETCH_ASSOC);

        if ($curRow && $curRow['status'] === 'finished') {
            $existingWinners = json_decode((string)($curRow['winners_json'] ?? '[]'), true);
            if (!is_array($existingWinners)) $existingWinners = [];
            $pdo->commit();
            $room['status'] = 'finished';
            $room['winners_json'] = json_encode($existingWinners);
            return $room;
        }

        $deduped = [];
        $seenIds = [];
        foreach ($winners as $w) {
            $tid = (string)($w['telegram_id'] ?? '');
            if ($tid === '' || isset($seenIds[$tid])) {
                continue;
            }
            $seenIds[$tid] = true;
            $w['name'] = resolvePlayerDisplayName($pdo, $tid, (string)($w['name'] ?? ''));
            $deduped[] = $w;
            if (count($deduped) >= 2) {
                break;
            }
        }

        $stakesStmt = $pdo->prepare("SELECT COALESCE(SUM(stake_paid), 0) AS total_stakes FROM room_players WHERE room_id = ?");
        $stakesStmt->execute([$roomId]);
        $totalStakes = (float)($stakesStmt->fetchColumn() ?: 0);
        $totalDerash = round($totalStakes * 0.80, 2);

        $winnerCount = count($deduped);
        $sharePerWinner = ($winnerCount > 0) ? round($totalDerash / $winnerCount, 2) : 0.0;

        foreach ($deduped as &$w) {
            $w['prize'] = $sharePerWinner;
        }
        unset($w);

        $winnerIds = [];
        foreach ($deduped as $w) {
            $wTid = (string)$w['telegram_id'];
            $wName = (string)($w['name'] ?? 'Player');
            $winnerIds[] = $wTid;

            $checkLog = $pdo->prepare("SELECT id, prize FROM game_winners WHERE room_id = ? AND CAST(telegram_id AS TEXT) = ? LIMIT 1");
            $checkLog->execute([$roomId, $wTid]);
            $prevWin = $checkLog->fetch(PDO::FETCH_ASSOC);

            if (!$prevWin) {
                $balStmt = $pdo->prepare("SELECT balance FROM players WHERE CAST(telegram_id AS TEXT) = ?");
                $balStmt->execute([$wTid]);
                $before = round((float)($balStmt->fetchColumn() ?: 0), 2);
                $after = round($before + $sharePerWinner, 2);

                $pdo->prepare("UPDATE players SET balance = ? WHERE CAST(telegram_id AS TEXT) = ?")
                    ->execute([$after, $wTid]);
                $pdo->prepare("INSERT INTO game_winners (room_id, telegram_id, winner_name, prize) VALUES (?, ?, ?, ?)")
                    ->execute([$roomId, $wTid, $wName, $sharePerWinner]);
                try {
                    logBalanceChange($pdo, $wTid, $roomId, 'win_payout', $sharePerWinner, $before, $after, "Won Game {$gameLabel}");
                } catch (Throwable $ledgerErr) {
                    error_log("finalizeRoomWinners ledger skipped: " . $ledgerErr->getMessage());
                }
            }
        }

        recordNonWinningPlayers($pdo, $roomId, $gameLabel, $winnerIds);

        $encodedWinners = json_encode($deduped);
        $pdo->prepare("UPDATE rooms SET status = 'finished', winners_json = ? WHERE id = ?")
            ->execute([$encodedWinners, $roomId]);

        $pdo->commit();

        sendWinnerCongratulations($pdo, $roomId, $gameLabel, $deduped, $totalDerash, $sharePerWinner);

        $room['status'] = 'finished';
        $room['winners_json'] = $encodedWinners;
        return $room;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            try {
                $pdo->rollBack();
            } catch (Throwable $rbErr) {
                error_log("finalizeRoomWinners rollback error: " . $rbErr->getMessage());
            }
        }
        error_log("finalizeRoomWinners error on room {$roomId}: " . $e->getMessage());
        return $room;
    }
}

function finishRoomWithNoWinner(PDO $pdo, array $room): array {
    $roomId = (int)$room['id'];
    $gameLabel = (string)($room['game_code'] ?: $roomId);

    while ($pdo->inTransaction()) {
        try {
            $pdo->rollBack();
        } catch (Throwable $ignoreRb) {
            break;
        }
    }

    $pdo->beginTransaction();
    try {
        recordNonWinningPlayers($pdo, $roomId, $gameLabel, []);
        $pdo->prepare("UPDATE rooms SET status = 'finished', winners_json = '[]' WHERE id = ?")
            ->execute([$roomId]);
        $pdo->commit();
        $room['status'] = 'finished';
        $room['winners_json'] = '[]';
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            try {
                $pdo->rollBack();
            } catch (Throwable $rbErr) {
                error_log("finishRoomWithNoWinner rollback error: " . $rbErr->getMessage());
            }
        }
        error_log("finishRoomWithNoWinner error on room {$roomId}: " . $e->getMessage());
    }
    return $room;
}

function syncRoomLifecycle(PDO $pdo, array $room): array {
    if (($room['status'] ?? '') === 'finished') {
        return $room;
    }

    $now = time();
    $status = $room['status'];
    $startsAt = (int)($room['starts_at'] ?? (($room['created_at'] ?? $now) + 15));
    $startedAt = (int)($room['started_at'] ?? 0);
    $pool = json_decode($room['draw_pool_json'] ?? '[]', true);
    if (!is_array($pool) || count($pool) !== 75) {
        $pool = range(1, 75);
        shuffle($pool);
    }

    $drawIntervalSec = 2.2;
    $maxRoundSec = 120;

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
        if ($startedAt <= 0) {
            $startedAt = (int)($room['created_at'] ?? $now);
            $pdo->prepare("UPDATE rooms SET started_at = ? WHERE id = ?")->execute([$startedAt, $room['id']]);
            $room['started_at'] = $startedAt;
        }

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
                $displayName = resolvePlayerDisplayName($pdo, $tid, (string)($entry['first_name'] ?? ''));
                $autoWinners[] = [
                    'telegram_id'    => $tid,
                    'name'           => $displayName,
                    'cartela_number' => $cn,
                    'board'          => $board,
                    'winning_line'   => $winLine,
                ];
            }
        }

        if (count($autoWinners) > 0) {
            return finalizeRoomWinners($pdo, $room, $autoWinners);
        }

        if ($elapsed >= $maxRoundSec || count($called) >= 75) {
            return finishRoomWithNoWinner($pdo, $room);
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
    $roundSecondsLeft = ($room['status'] === 'active') ? max(0, 120 - ($now - $startedAt)) : 0;

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

    $stmt = $pdo->prepare("SELECT balance FROM players WHERE CAST(telegram_id AS TEXT) = ? LIMIT 1");
    $stmt->execute([$telegramId]);
    $player = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($player) {
        $balance = round((float)$player['balance'], 2);
    } else {
        $initialBal = 0.00;
        $stmtIns = $pdo->prepare("INSERT INTO players (telegram_id, first_name, phone, balance) VALUES (?, 'Player', 'WebUser', ?)");
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

    $payload = buildRoomPayload($pdo, $room);

    if ($payload['status'] === 'finished' && $stake > 0) {
        $now = time();
        $code = generateGameCode(5);
        $pool = range(1, 75);
        shuffle($pool);
        $insRoom = $pdo->prepare("INSERT INTO rooms (game_code, stake, status, created_at, starts_at, draw_pool_json, called_numbers_json, winners_json) VALUES (?, ?, 'waiting', ?, ?, ?, '[]', '[]')");
        $insRoom->execute([$code, $stake, $now, $now + 15, json_encode($pool)]);
        $newRoomId = lastInsertIdCompat($pdo, 'rooms_id_seq');
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
    $rawFirstName = trim((string)($_POST['first_name'] ?? ''));
    $rawUsername = ltrim(trim((string)($_POST['username'] ?? '')), '@');
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
    $stepTag = 'init';

    while ($pdo->inTransaction()) {
        try {
            $pdo->rollBack();
        } catch (Throwable $ignoreRb) {
            break;
        }
    }

    try {
        $stepTag = 'check_active';
        $pdo->prepare("UPDATE rooms SET status = 'finished', winners_json = COALESCE(winners_json, '[]') WHERE stake = ? AND status = 'active' AND COALESCE(started_at, created_at, 0) <= ?")
            ->execute([$stake, time() - 120]);

        $activeStmt = $pdo->prepare("SELECT * FROM rooms WHERE stake = ? AND status = 'active' ORDER BY id DESC LIMIT 1");
        $activeStmt->execute([$stake]);
        $activeRoom = $activeStmt->fetch(PDO::FETCH_ASSOC);

        if ($activeRoom) {
            $syncedActive = syncRoomLifecycle($pdo, $activeRoom);
            if (($syncedActive['status'] ?? '') === 'active') {
                $now = time();
                $startedAt = (int)($syncedActive['started_at'] ?? $now);
                $elapsed = max(0, $now - $startedAt);
                $pool = json_decode($syncedActive['draw_pool_json'] ?? '[]', true);
                $targetCalls = min(75, (int)floor($elapsed / 2.2) + 1);
                $called = is_array($pool) ? array_slice($pool, 0, $targetCalls) : [];
                echo json_encode([
                    'success'         => false,
                    'already_started' => true,
                    'room'            => [
                        'room_id'        => (int)$syncedActive['id'],
                        'game_code'      => $syncedActive['game_code'] ?: '1T6BX',
                        'stake'          => (float)$syncedActive['stake'],
                        'status'         => 'active',
                        'derash'         => 0,
                        'calls_count'    => count($called),
                        'called_numbers' => $called,
                    ],
                    'error' => 'you are visiting now, please wait for the next cartella to start the game',
                ]);
                exit;
            }
        }

        $stepTag = 'select_player';
        $stmt = $pdo->prepare("SELECT balance, first_name, username FROM players WHERE CAST(telegram_id AS TEXT) = ? LIMIT 1");
        $stmt->execute([$telegramId]);
        $player = $stmt->fetch(PDO::FETCH_ASSOC);

        $effectiveName = ($rawFirstName !== '' && strcasecmp($rawFirstName, 'Player') !== 0)
            ? $rawFirstName
            : ($rawUsername !== '' ? $rawUsername : ($rawFirstName !== '' ? $rawFirstName : 'Player'));

        if (!$player) {
            $stepTag = 'insert_player';
            $initialBal = 0.00;
            $ins = $pdo->prepare("INSERT INTO players (telegram_id, first_name, username, phone, balance) VALUES (?, ?, ?, 'WebUser', ?)");
            $ins->execute([$telegramId, $effectiveName, $rawUsername, $initialBal]);
            $currentBalance = $initialBal;
        } else {
            $currentBalance = round((float)$player['balance'], 2);
            $existingFirst = trim((string)($player['first_name'] ?? ''));
            if ($rawFirstName !== '' && strcasecmp($rawFirstName, 'Player') !== 0 && $existingFirst !== $rawFirstName) {
                $stepTag = 'update_player_name';
                $pdo->prepare("UPDATE players SET first_name = ? WHERE CAST(telegram_id AS TEXT) = ?")->execute([$rawFirstName, $telegramId]);
                $effectiveName = $rawFirstName;
            } elseif ($existingFirst !== '' && strcasecmp($existingFirst, 'Player') !== 0) {
                $effectiveName = $existingFirst;
            } elseif ($rawUsername !== '') {
                $effectiveName = $rawUsername;
            }
            if ($rawUsername !== '') {
                $stepTag = 'update_player_username';
                $pdo->prepare("UPDATE players SET username = ? WHERE CAST(telegram_id AS TEXT) = ?")->execute([$rawUsername, $telegramId]);
            }
        }

        if ($currentBalance < $totalFee) {
            echo json_encode(['success' => false, 'error' => 'Insufficient balance! Please top up via Deposit.']);
            exit;
        }

        $stepTag = 'select_or_create_room';
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
            $roomId = lastInsertIdCompat($pdo, 'rooms_id_seq');
            $roomById = $pdo->prepare("SELECT * FROM rooms WHERE id = ? LIMIT 1");
            $roomById->execute([$roomId]);
            $room = $roomById->fetch(PDO::FETCH_ASSOC);
        } else {
            $roomId = (int)$room['id'];
        }

        $stepTag = 'check_taken_cartelas';
        $takenStmt = $pdo->prepare("SELECT player_number FROM room_players WHERE room_id = ?");
        $takenStmt->execute([$roomId]);
        $takenList = $takenStmt->fetchAll(PDO::FETCH_COLUMN, 0);
        foreach ($cartelaNumbers as $cn) {
            if (in_array((int)$cn, array_map('intval', $takenList), true)) {
                echo json_encode(['success' => false, 'error' => "Cartela #{$cn} is already taken in this round."]);
                exit;
            }
        }

        $pdo->beginTransaction();

        $stepTag = 'deduct_balance';
        $newBalance = round($currentBalance - $totalFee, 2);
        $pdo->prepare("UPDATE players SET balance = ? WHERE CAST(telegram_id AS TEXT) = ?")->execute([$newBalance, $telegramId]);

        $stepTag = 'insert_room_player';
        $logRoom = $pdo->prepare("INSERT INTO room_players (room_id, telegram_id, first_name, player_number, stake_paid) VALUES (?, ?, ?, ?, ?)");
        foreach ($cartelaNumbers as $cn) {
            $logRoom->execute([$roomId, $telegramId, $effectiveName, $cn, $stake]);
        }

        $pdo->commit();

        try {
            logBalanceChange($pdo, $telegramId, $roomId, 'stake_deduct', -$totalFee, $currentBalance, $newBalance, "Joined Game " . ($room['game_code'] ?? $roomId) . " (" . count($cartelaNumbers) . " cartela)");
        } catch (Throwable $ledgerErr) {
            error_log("join_room ledger skipped: " . $ledgerErr->getMessage());
        }

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
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            try {
                $pdo->rollBack();
            } catch (Throwable $rbErr) {
                error_log("join_room rollback error: " . $rbErr->getMessage());
            }
        }
        echo json_encode(['success' => false, 'error' => "Transaction error [{$stepTag}]: " . $e->getMessage()]);
    }
}
elseif ($action === 'request_withdrawal') {
    $telegramId = trim((string)($_POST['telegram_id'] ?? ''));
    $amount = round((float)($_POST['amount'] ?? 0), 2);
    $method = trim((string)($_POST['method'] ?? 'Telebirr'));
    $account = trim((string)($_POST['account'] ?? ''));

    while ($pdo->inTransaction()) {
        try {
            $pdo->rollBack();
        } catch (Throwable $ignoreRb) {
            break;
        }
    }

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("SELECT balance, first_name FROM players WHERE CAST(telegram_id AS TEXT) = ? LIMIT 1");
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

        $pdo->prepare("UPDATE players SET balance = ? WHERE CAST(telegram_id AS TEXT) = ?")->execute([$newBalance, $telegramId]);
        $pdo->prepare("INSERT INTO withdrawals (telegram_id, amount, method, account, status) VALUES (?, ?, ?, ?, 'pending')")->execute([$telegramId, $amount, $method, $account]);
        $pdo->commit();

        try {
            logBalanceChange($pdo, $telegramId, null, 'withdrawal', -$amount, $currentBal, $newBalance, "Withdrawal via {$method} ({$account})");
        } catch (Throwable $ledgerErr) {
            error_log("request_withdrawal ledger skipped: " . $ledgerErr->getMessage());
        }

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
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            try {
                $pdo->rollBack();
            } catch (Throwable $rbErr) {
                error_log("request_withdrawal rollback error: " . $rbErr->getMessage());
            }
        }
        error_log("request_withdrawal error: " . $e->getMessage());
        echo json_encode(['success' => false, 'error' => 'Withdrawal error: ' . $e->getMessage()]);
    }
}
elseif ($action === 'give_bonus') {
    $telegramId = trim((string)($_POST['telegram_id'] ?? $_GET['telegram_id'] ?? ''));
    $amount     = (float)($_POST['amount'] ?? $_GET['amount'] ?? 0);
    $note       = trim((string)($_POST['note'] ?? $_GET['note'] ?? 'Bonus from Admin'));
    $notifyUser = filter_var($_POST['notify_user'] ?? $_GET['notify_user'] ?? true, FILTER_VALIDATE_BOOLEAN);

    $result = givePlayerBonus($pdo, $telegramId, $amount, $note, $notifyUser);
    echo json_encode($result);
}
else {
    echo json_encode(['success' => false, 'error' => 'Invalid action']);
}
