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

function getColumnType(PDO $pdo, string $table, string $column): string {
    if (!preg_match('/^[a-zA-Z0-9_]+$/', $table) || !preg_match('/^[a-zA-Z0-9_]+$/', $column)) {
        return '';
    }
    if (isPgsql($pdo)) {
        $stmt = $pdo->prepare("
            SELECT data_type
            FROM information_schema.columns
            WHERE table_schema = 'public' AND table_name = ? AND column_name = ?
            LIMIT 1
        ");
        $stmt->execute([$table, $column]);
        return strtolower((string)($stmt->fetchColumn() ?: ''));
    }
    return '';
}

function normalizeIdForColumn(string $id, string $colType): int|string {
    $trimmed = trim($id);
    if (in_array($colType, ['bigint', 'integer', 'smallint', 'numeric'], true)) {
        $digits = preg_replace('/\D+/', '', $trimmed) ?? '';
        return $digits !== '' ? (int)$digits : 0;
    }
    return $trimmed;
}

function resetAbortedTransaction(PDO $pdo): void {
    while ($pdo->inTransaction()) {
        try {
            $pdo->rollBack();
        } catch (Throwable $e) {
            break;
        }
    }
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

function getBotTokenEnv(): string {
    foreach (['TELEGRAM_BOT_TOKEN', 'BOT_TOKEN', 'TG_BOT_TOKEN'] as $key) {
        $val = trim((string)(getenv($key) ?: ($_ENV[$key] ?? $_SERVER[$key] ?? '')));
        if ($val !== '') {
            return trim($val, "\"'");
        }
    }
    return '';
}

function getAdminChatIdEnv(): string {
    foreach (['ADMIN_TELEGRAM_ID', 'ADMIN_ID', 'TELEGRAM_ADMIN_ID', 'ADMIN_CHAT_ID'] as $key) {
        $val = trim((string)(getenv($key) ?: ($_ENV[$key] ?? $_SERVER[$key] ?? '')));
        if ($val !== '') {
            return trim($val, "\"'");
        }
    }
    return '730967613';
}

function sendTelegramMessage(string $token, string $chatId, string $message): array {
    $token = trim($token);
    $chatId = trim($chatId);

    if ($token === '' || $chatId === '') {
        $err = 'Missing bot token or admin chat_id.';
        error_log("sendTelegramMessage: {$err}");
        return ['sent' => false, 'error' => $err];
    }

    $payload = [
        'chat_id'    => $chatId,
        'text'       => $message,
        'parse_mode' => 'HTML',
    ];

    $url = "https://api.telegram.org/bot{$token}/sendMessage";
    $rawBody = false;
    $httpCode = 0;
    $transportErr = '';

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 12,
            CURLOPT_CONNECTTIMEOUT => 6,
        ]);
        $rawBody = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($rawBody === false) {
            $transportErr = curl_error($ch);
        }
        curl_close($ch);
    } else {
        $ctx = stream_context_create([
            'http' => [
                'method'        => 'POST',
                'header'        => "Content-Type: application/x-www-form-urlencoded\r\n",
                'content'       => http_build_query($payload),
                'timeout'       => 12,
                'ignore_errors' => true,
            ],
        ]);
        $rawBody = @file_get_contents($url, false, $ctx);
        if (isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $m)) {
            $httpCode = (int)$m[1];
        }
        if ($rawBody === false) {
            $transportErr = 'file_get_contents failed';
        }
    }

    if ($rawBody === false || !is_string($rawBody)) {
        error_log("Telegram sendMessage transport error ({$chatId}): {$transportErr}");
        return ['sent' => false, 'error' => $transportErr !== '' ? $transportErr : 'Network error'];
    }

    $decoded = json_decode($rawBody, true);
    if ($httpCode === 200 && is_array($decoded) && !empty($decoded['ok'])) {
        return ['sent' => true, 'error' => ''];
    }

    $tgDesc = is_array($decoded) ? (string)($decoded['description'] ?? 'Unknown Telegram error') : $rawBody;
    error_log("Telegram sendMessage rejected ({$chatId}, HTTP {$httpCode}): {$tgDesc}");
    return ['sent' => false, 'error' => $tgDesc];
}

function notifyAdminWithdrawal(string $token, string $chatId, string $message): array {
    return sendTelegramMessage($token, $chatId, $message);
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
        $pdo->exec("ALTER TABLE room_players ADD COLUMN " . ($pgsql ? "IF NOT EXISTS " : "") . "first_name TEXT");
    }
    if (!in_array('stake_paid', $rpCols, true)) {
        $pdo->exec("ALTER TABLE room_players ADD COLUMN " . ($pgsql ? "IF NOT EXISTS " : "") . "stake_paid " . ($pgsql ? "DOUBLE PRECISION" : "REAL") . " DEFAULT 0");
    }
} catch (Throwable $e) {
    echo json_encode(['success' => false, 'error' => 'Database connection failed: ' . $e->getMessage()]);
    exit;
}

function logBalanceChange(PDO $pdo, string $telegramId, ?int $roomId, string $type, float $amount, float $before, float $after, string $note = ''): void {
    try {
        $stmt = $pdo->prepare("INSERT INTO balance_ledger (telegram_id, room_id, type, amount, balance_before, balance_after, note, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$telegramId, $roomId, $type, $amount, $before, $after, $note, gmdate('Y-m-d H:i:s')]);
    } catch (Throwable $e) {
        error_log("logBalanceChange skipped: " . $e->getMessage());
    }
}

function givePlayerBonus(PDO $pdo, string $telegramId, float $amount, string $note = 'Bonus from Admin', bool $notifyUser = true): array {
    $amount = round($amount, 2);
    $telegramId = preg_replace('/\D+/', '', trim($telegramId)) ?? '';

    if ($telegramId === '' || $amount <= 0) {
        return ['success' => false, 'error' => 'Invalid Telegram ID or bonus amount.'];
    }

    resetAbortedTransaction($pdo);

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

        $pdo->prepare("UPDATE players SET balance = ?, updated_at = ? WHERE CAST(telegram_id AS TEXT) = ?")
            ->execute([$after, gmdate('Y-m-d H:i:s'), $telegramId]);

        $pdo->commit();
        logBalanceChange($pdo, $telegramId, null, 'bonus', $amount, $before, $after, $note);

        $notified = false;
        if ($notifyUser) {
            $token = getBotTokenEnv();
            $safeName = htmlspecialchars((string)($player['first_name'] ?? 'Player'), ENT_QUOTES, 'UTF-8');
            $safeNote = htmlspecialchars($note, ENT_QUOTES, 'UTF-8');
            $formattedAmt = number_format($amount, 2);
            $formattedBal = number_format($after, 2);

            $userMsg = "🎁 <b>Bonus Deposited (SMART BINGO)!</b>\n\n" .
                       "• <b>Player:</b> {$safeName}\n" .
                       "• <b>Bonus Amount:</b> <b>+{$formattedAmt} ETB</b>\n" .
                       "• <b>New Balance:</b> <b>{$formattedBal} ETB</b>\n" .
                       "• <b>Note:</b> {$safeNote}";

            $sendRes = sendTelegramMessage($token, $telegramId, $userMsg);
            $notified = $sendRes['sent'];
        }

        return [
            'success'     => true,
            'telegram_id' => $telegramId,
            'bonus_added' => $amount,
            'new_balance' => $after,
            'notified'    => $notified,
        ];
    } catch (Throwable $e) {
        resetAbortedTransaction($pdo);
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
        [0,4,20,24], // 4 corners
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

function pickClosestLineWinner(PDO $pdo, array $entries, array $calledSet): ?array {
    $lines = [
        [0,1,2,3,4], [5,6,7,8,9], [10,11,12,13,14], [15,16,17,18,19], [20,21,22,23,24],
        [0,5,10,15,20], [1,6,11,16,21], [2,7,12,17,22], [3,8,13,18,23], [4,9,14,19,24],
        [0,6,12,18,24], [4,8,12,16,20],
        [0,4,20,24], // 4 corners
    ];

    $best = null;
    $bestScore = -1;

    foreach ($entries as $entry) {
        $tid = (string)($entry['telegram_id'] ?? '');
        $cn = (int)($entry['player_number'] ?? 0);
        if ($tid === '' || $cn <= 0) continue;

        $board = generateCartelaBoard($cn);
        foreach ($lines as $line) {
            $hits = 0;
            foreach ($line as $idx) {
                if ($idx === 12 || isset($calledSet[(int)($board[$idx] ?? -1)])) {
                    $hits++;
                }
            }
            if ($hits > $bestScore) {
                $bestScore = $hits;
                $best = [
                    'telegram_id'    => $tid,
                    'name'           => resolvePlayerDisplayName($pdo, $tid, (string)($entry['first_name'] ?? '')),
                    'cartela_number' => $cn,
                    'board'          => $board,
                    'winning_line'   => $line,
                ];
            }
        }
    }

    return $best;
}

function resolvePlayerDisplayName(PDO $pdo, string $telegramId, string $roomFirstName = ''): string {
    $name = trim($roomFirstName);
    if ($name !== '' && strcasecmp($name, 'Player') !== 0) {
        return $name;
    }

    if ($telegramId !== '') {
        try {
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
        } catch (Throwable $e) {
            // fallback below
        }
    }

    return $name !== '' ? $name : 'Player';
}

function recordNonWinningPlayers(PDO $pdo, int $roomId, string $gameLabel, array $winnerIds): void {
    try {
        $rpStmt = $pdo->prepare("SELECT telegram_id, COALESCE(stake_paid, 0) AS player_stake FROM room_players WHERE room_id = ?");
        $rpStmt->execute([$roomId]);
        $rows = $rpStmt->fetchAll(PDO::FETCH_ASSOC);

        $stakesByPlayer = [];
        foreach ($rows as $r) {
            $tid = (string)($r['telegram_id'] ?? '');
            if ($tid === '') continue;
            $stakesByPlayer[$tid] = ($stakesByPlayer[$tid] ?? 0.0) + (float)($r['player_stake'] ?? 0);
        }

        $winnerMap = array_flip(array_map('strval', $winnerIds));

        foreach ($stakesByPlayer as $tid => $stakeLost) {
            if (isset($winnerMap[$tid]) || $stakeLost <= 0) {
                continue;
            }
            $dupStmt = $pdo->prepare("SELECT id FROM balance_ledger WHERE CAST(telegram_id AS TEXT) = ? AND room_id = ? AND type = 'loss_settled' LIMIT 1");
            $dupStmt->execute([$tid, $roomId]);
            if ($dupStmt->fetchColumn()) {
                continue;
            }

            $balStmt = $pdo->prepare("SELECT balance FROM players WHERE CAST(telegram_id AS TEXT) = ? LIMIT 1");
            $balStmt->execute([$tid]);
            $curBal = round((float)($balStmt->fetchColumn() ?: 0), 2);
            logBalanceChange($pdo, $tid, $roomId, 'loss_settled', -$stakeLost, $curBal, $curBal, "Lost Game {$gameLabel}");
        }
    } catch (Throwable $e) {
        error_log("recordNonWinningPlayers skipped: " . $e->getMessage());
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

    $token = getBotTokenEnv();
    $adminId = getAdminChatIdEnv();
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
        ? "🎉 <b>Congratulations {$namesHeadline}!</b> You closed a line or 4 corners together in Game <b>#{$safeGame}</b> and share the <b>{$poolStr} ETB</b> (80% pool; 20% commission) equally: <b>{$shareStr} ETB each</b>!"
        : "🎉 <b>Congratulations {$namesHeadline}!</b> You closed a line or 4 corners first in Game <b>#{$safeGame}</b> and won <b>{$shareStr} ETB</b> (80% pool; 20% commission)!";

    foreach ($winners as $w) {
        $tid = trim((string)($w['telegram_id'] ?? ''));
        if ($tid === '') continue;
        $pName = htmlspecialchars((string)($w['name'] ?? 'Player'), ENT_QUOTES, 'UTF-8');
        $cartelaNo = (int)($w['cartela_number'] ?? 0);
        $cartelaLine = $cartelaNo > 0 ? "\n• <b>Winning Cartela:</b> #{$cartelaNo}" : "";
        $msg = "🏆 <b>BINGO CLOSED — CONGRATULATIONS!</b>\n\n"
             . "Congratulations, <b>{$pName}</b>! 🎉\n"
             . "You closed a winning line or 4 corners in Game <b>#{$safeGame}</b>!{$cartelaLine}\n"
             . "• <b>Prize Awarded:</b> <b>+{$shareStr} ETB</b>";
        sendTelegramMessage($token, $tid, $msg);
    }

    if ($adminId !== '') {
        sendTelegramMessage($token, $adminId, "📊 <b>Game #{$safeGame} Over</b>\n\n{$summaryLine}");
    }
}

function finalizeRoomWinners(PDO $pdo, array $room, array $winners): array {
    $roomId = (int)$room['id'];
    $gameLabel = (string)($room['game_code'] ?: $roomId);

    resetAbortedTransaction($pdo);

    $claimStmt = $pdo->prepare("UPDATE rooms SET status = 'finished' WHERE id = ? AND status <> 'finished'");
    $claimStmt->execute([$roomId]);
    if ($claimStmt->rowCount() === 0 && ($room['status'] ?? '') === 'finished') {
        $existingWinners = json_decode($room['winners_json'] ?? '[]', true);
        if (is_array($existingWinners) && count($existingWinners) > 0) {
            return $room;
        }
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

    $stakesStmt = $pdo->prepare("SELECT COALESCE(SUM(stake_paid), 0) AS total_stakes, COUNT(*) AS entry_count FROM room_players WHERE room_id = ?");
    $stakesStmt->execute([$roomId]);
    $stakeRow = $stakesStmt->fetch(PDO::FETCH_ASSOC);
    $totalStakes = (float)($stakeRow['total_stakes'] ?? 0);
    if ($totalStakes <= 0 && (int)($stakeRow['entry_count'] ?? 0) > 0) {
        $totalStakes = (float)$stakeRow['entry_count'] * (float)($room['stake'] ?? 10);
    }

    $totalDerash = round($totalStakes * 0.80, 2);
    $winnerCount = count($deduped);
    $sharePerWinner = ($winnerCount > 0) ? round($totalDerash / $winnerCount, 2) : 0.0;

    foreach ($deduped as &$w) {
        $w['prize'] = $sharePerWinner;
    }
    unset($w);

    $encodedWinners = json_encode($deduped);

    resetAbortedTransaction($pdo);
    try {
        $pdo->prepare("UPDATE rooms SET status = 'finished', winners_json = ? WHERE id = ?")
            ->execute([$encodedWinners, $roomId]);
    } catch (Throwable $e) {
        error_log("finalizeRoomWinners winners_json update error on room {$roomId}: " . $e->getMessage());
    }

    $winnerIds = [];
    foreach ($deduped as $w) {
        $wTid = (string)$w['telegram_id'];
        $wName = (string)($w['name'] ?? 'Player');
        $winnerIds[] = $wTid;

        try {
            $checkLog = $pdo->prepare("SELECT id FROM game_winners WHERE room_id = ? AND CAST(telegram_id AS TEXT) = ? LIMIT 1");
            $checkLog->execute([$roomId, $wTid]);
            if (!$checkLog->fetchColumn()) {
                $balStmt = $pdo->prepare("SELECT balance FROM players WHERE CAST(telegram_id AS TEXT) = ? LIMIT 1");
                $balStmt->execute([$wTid]);
                $before = round((float)($balStmt->fetchColumn() ?: 0), 2);
                $after = round($before + $sharePerWinner, 2);

                $pdo->prepare("UPDATE players SET balance = ?, updated_at = ? WHERE CAST(telegram_id AS TEXT) = ?")
                    ->execute([$after, gmdate('Y-m-d H:i:s'), $wTid]);
                $pdo->prepare("INSERT INTO game_winners (room_id, telegram_id, winner_name, prize) VALUES (?, ?, ?, ?)")
                    ->execute([$roomId, $wTid, $wName, $sharePerWinner]);
                logBalanceChange($pdo, $wTid, $roomId, 'win_payout', $sharePerWinner, $before, $after, "Won Game {$gameLabel}");
            }
        } catch (Throwable $payoutErr) {
            error_log("Winner payout step note on room {$roomId}: " . $payoutErr->getMessage());
        }
    }

    recordNonWinningPlayers($pdo, $roomId, $gameLabel, $winnerIds);
    sendWinnerCongratulations($pdo, $roomId, $gameLabel, $deduped, $totalDerash, $sharePerWinner);

    $room['status'] = 'finished';
    $room['winners_json'] = $encodedWinners;
    return $room;
}

function finishRoomWithNoWinner(PDO $pdo, array $room): array {
    $roomId = (int)$room['id'];
    $gameLabel = (string)($room['game_code'] ?: $roomId);

    resetAbortedTransaction($pdo);
    try {
        $pdo->prepare("UPDATE rooms SET status = 'finished', winners_json = '[]' WHERE id = ?")->execute([$roomId]);
    } catch (Throwable $e) {
        error_log("finishRoomWithNoWinner error: " . $e->getMessage());
    }
    recordNonWinningPlayers($pdo, $roomId, $gameLabel, []);
    $room['status'] = 'finished';
    $room['winners_json'] = '[]';
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

    $drawIntervalSec = 2.0;

    if ($status === 'waiting' && $now >= $startsAt) {
        $countStmt = $pdo->prepare("SELECT COUNT(*) FROM room_players WHERE room_id = ?");
        $countStmt->execute([$room['id']]);
        if ((int)$countStmt->fetchColumn() === 0) {
            return finishRoomWithNoWinner($pdo, $room);
        }

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

        $rpStmt = $pdo->prepare("SELECT telegram_id, first_name, player_number FROM room_players WHERE room_id = ? ORDER BY id ASC");
        $rpStmt->execute([$room['id']]);
        $entries = $rpStmt->fetchAll(PDO::FETCH_ASSOC);

        if (count($entries) === 0) {
            return finishRoomWithNoWinner($pdo, $room);
        }

        $elapsed = max(0, $now - $startedAt);
        $targetCalls = min(75, (int)floor($elapsed / $drawIntervalSec) + 1);
        $called = array_slice($pool, 0, $targetCalls);
        $calledSet = array_flip(array_map('intval', $called));

        $pdo->prepare("UPDATE rooms SET called_numbers_json = ? WHERE id = ?")
            ->execute([json_encode($called), $room['id']]);
        $room['called_numbers_json'] = json_encode($called);

        $autoWinners = [];
        $seenWinnerIds = [];
        foreach ($entries as $entry) {
            $tid = (string)($entry['telegram_id'] ?? '');
            if ($tid === '' || isset($seenWinnerIds[$tid])) {
                continue;
            }
            $cn = (int)($entry['player_number'] ?? 0);
            if ($cn <= 0) continue;
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

        if (count($called) >= 75) {
            $fallbackWinner = pickClosestLineWinner($pdo, $entries, $calledSet);
            if ($fallbackWinner !== null) {
                return finalizeRoomWinners($pdo, $room, [$fallbackWinner]);
            }
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
        $uniquePlayers[(string)$r['telegram_id']] = true;
        $takenCartelas[] = (int)$r['player_number'];
        $paid = (float)($r['stake_paid'] ?? 0);
        $totalStakes += ($paid > 0) ? $paid : (float)$room['stake'];
    }

    $playersCount = count($uniquePlayers);
    $derash = round($totalStakes * 0.80, 2);

    $now = time();
    $startsAt = (int)($room['starts_at'] ?? ($now + 15));
    $lobbySecondsLeft = max(0, $startsAt - $now);

    $startedAt = (int)($room['started_at'] ?? $now);
    $roundSecondsLeft = ($room['status'] === 'active') ? max(0, 150 - ($now - $startedAt)) : 0;

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
        $now = gmdate('Y-m-d H:i:s');
        $stmtIns = $pdo->prepare("INSERT INTO players (telegram_id, chat_id, first_name, username, phone, balance, created_at, updated_at) VALUES (?, ?, 'Player', '', 'WebUser', ?, ?, ?)");
        $stmtIns->execute([$telegramId, $telegramId, $initialBal, $now, $now]);
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
        $stmt = $pdo->prepare("
            SELECT r.*
            FROM rooms r
            WHERE r.stake = ?
              AND (
                r.status = 'waiting'
                OR (r.status = 'active' AND EXISTS (SELECT 1 FROM room_players rp WHERE rp.room_id = r.id))
              )
            ORDER BY r.id DESC
            LIMIT 1
        ");
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

    resetAbortedTransaction($pdo);

    try {
        $stepTag = 'check_active';
        $pdo->prepare("
            UPDATE rooms
            SET status = 'finished', winners_json = COALESCE(winners_json, '[]')
            WHERE stake = ?
              AND status = 'active'
              AND (
                COALESCE(started_at, created_at, 0) <= ?
                OR NOT EXISTS (SELECT 1 FROM room_players rp WHERE rp.room_id = rooms.id)
              )
        ")->execute([$stake, time() - 180]);

        $activeStmt = $pdo->prepare("
            SELECT r.*
            FROM rooms r
            WHERE r.stake = ?
              AND r.status = 'active'
              AND EXISTS (SELECT 1 FROM room_players rp WHERE rp.room_id = r.id)
            ORDER BY r.id DESC
            LIMIT 1
        ");
        $activeStmt->execute([$stake]);
        $activeRoom = $activeStmt->fetch(PDO::FETCH_ASSOC);

        if ($activeRoom) {
            $syncedActive = syncRoomLifecycle($pdo, $activeRoom);
            if (($syncedActive['status'] ?? '') === 'active') {
                $activePayload = buildRoomPayload($pdo, $syncedActive);
                echo json_encode([
                    'success'         => false,
                    'already_started' => true,
                    'room'            => $activePayload,
                    'error'           => 'Please wait until this game ends; you will start in the next round.',
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

        $nowTs = gmdate('Y-m-d H:i:s');

        if (!$player) {
            $stepTag = 'insert_player';
            $initialBal = 0.00;
            $ins = $pdo->prepare("INSERT INTO players (telegram_id, chat_id, first_name, username, phone, balance, created_at, updated_at) VALUES (?, ?, ?, ?, 'WebUser', ?, ?, ?)");
            $ins->execute([$telegramId, $telegramId, $effectiveName, $rawUsername, $initialBal, $nowTs, $nowTs]);
            $currentBalance = $initialBal;
        } else {
            $currentBalance = round((float)$player['balance'], 2);
            $existingFirst = trim((string)($player['first_name'] ?? ''));
            if ($rawFirstName !== '' && strcasecmp($rawFirstName, 'Player') !== 0 && $existingFirst !== $rawFirstName) {
                $stepTag = 'update_player_name';
                $pdo->prepare("UPDATE players SET first_name = ?, updated_at = ? WHERE CAST(telegram_id AS TEXT) = ?")->execute([$rawFirstName, $nowTs, $telegramId]);
                $effectiveName = $rawFirstName;
            } elseif ($existingFirst !== '' && strcasecmp($existingFirst, 'Player') !== 0) {
                $effectiveName = $existingFirst;
            } elseif ($rawUsername !== '') {
                $effectiveName = $rawUsername;
            }
            if ($rawUsername !== '') {
                $stepTag = 'update_player_username';
                $pdo->prepare("UPDATE players SET username = ?, updated_at = ? WHERE CAST(telegram_id AS TEXT) = ?")->execute([$rawUsername, $nowTs, $telegramId]);
            }
        }

        if ($currentBalance < $totalFee) {
            echo json_encode(['success' => false, 'error' => 'Insufficient balance! Please deposit first via Wallet -> Deposit.']);
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
            $now = time();
            $pdo->prepare("UPDATE rooms SET starts_at = ? WHERE id = ? AND status = 'waiting'")->execute([$now + 15, $roomId]);
            $room['starts_at'] = $now + 15;
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

        $rpTidType = getColumnType($pdo, 'room_players', 'telegram_id');
        $rpTidVal = normalizeIdForColumn($telegramId, $rpTidType);
        $newBalance = round($currentBalance - $totalFee, 2);

        $stepTag = 'begin_tx';
        resetAbortedTransaction($pdo);
        $pdo->beginTransaction();

        try {
            $stepTag = 'insert_room_player';
            $logRoom = $pdo->prepare("INSERT INTO room_players (room_id, telegram_id, first_name, player_number, stake_paid) VALUES (?, ?, ?, ?, ?)");
            foreach ($cartelaNumbers as $cn) {
                $logRoom->execute([$roomId, $rpTidVal, $effectiveName, (int)$cn, $stake]);
            }

            $stepTag = 'deduct_balance';
            $updBal = $pdo->prepare("UPDATE players SET balance = ?, updated_at = ? WHERE CAST(telegram_id AS TEXT) = ?");
            $updBal->execute([$newBalance, $nowTs, $telegramId]);

            $stepTag = 'commit_tx';
            $pdo->commit();
        } catch (Throwable $txErr) {
            resetAbortedTransaction($pdo);
            echo json_encode([
                'success' => false,
                'error'   => "Transaction error [{$stepTag}]: " . $txErr->getMessage(),
            ]);
            exit;
        }

        logBalanceChange($pdo, $telegramId, $roomId, 'stake_deduct', -$totalFee, $currentBalance, $newBalance, "Joined Game " . ($room['game_code'] ?? $roomId) . " (" . count($cartelaNumbers) . " cartela)");

        $refreshedRoomStmt = $pdo->prepare("SELECT * FROM rooms WHERE id = ? LIMIT 1");
        $refreshedRoomStmt->execute([$roomId]);
        $refreshedRoom = $refreshedRoomStmt->fetch(PDO::FETCH_ASSOC) ?: $room;

        $payload = buildRoomPayload($pdo, $refreshedRoom);
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
        resetAbortedTransaction($pdo);
        echo json_encode(['success' => false, 'error' => "Transaction error [{$stepTag}]: " . $e->getMessage()]);
    }
}
elseif ($action === 'request_withdrawal') {
    $telegramId = trim((string)($_POST['telegram_id'] ?? ''));
    $amount     = round((float)($_POST['amount'] ?? 0), 2);
    $method     = trim((string)($_POST['method'] ?? 'Telebirr'));
    $account    = trim((string)($_POST['account'] ?? ''));

    if ($telegramId === '' || $telegramId === 'undefined' || $telegramId === 'null') {
        echo json_encode(['success' => false, 'error' => 'Invalid Telegram ID.']);
        exit;
    }
    if ($account === '') {
        echo json_encode(['success' => false, 'error' => 'Please enter a valid phone or account number.']);
        exit;
    }
    if ($amount <= 0) {
        echo json_encode(['success' => false, 'error' => 'Please enter a valid withdrawal amount greater than 0.']);
        exit;
    }

    resetAbortedTransaction($pdo);

    try {
        $wCols    = getColumnNames($pdo, 'withdrawals');
        $wTidType = getColumnType($pdo, 'withdrawals', 'telegram_id');
        $wTidVal  = normalizeIdForColumn($telegramId, $wTidType);

        $stmt = $pdo->prepare("SELECT balance, first_name FROM players WHERE CAST(telegram_id AS TEXT) = ? LIMIT 1");
        $stmt->execute([$telegramId]);
        $player = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$player) {
            echo json_encode(['success' => false, 'error' => 'Player record not found.']);
            exit;
        }

        $currentBal = round((float)$player['balance'], 2);
        if ($currentBal < $amount) {
            echo json_encode(['success' => false, 'error' => 'Insufficient balance for this withdrawal.']);
            exit;
        }

        $newBalance = round($currentBal - $amount, 2);
        $nowStr     = gmdate('Y-m-d H:i:s');
        $playerName = (string)($player['first_name'] ?? 'Player');

        $pdo->beginTransaction();

        $updPlayer = $pdo->prepare("UPDATE players SET balance = ?, updated_at = ? WHERE CAST(telegram_id AS TEXT) = ?");
        $updPlayer->execute([$newBalance, $nowStr, $telegramId]);

        $insertCols = ['telegram_id', 'amount', 'method', 'account', 'status'];
        $insertVals = [$wTidVal, $amount, $method, $account, 'pending'];

        if (in_array('first_name', $wCols, true)) {
            $insertCols[] = 'first_name';
            $insertVals[] = $playerName;
        } elseif (in_array('player_name', $wCols, true)) {
            $insertCols[] = 'player_name';
            $insertVals[] = $playerName;
        }

        if (in_array('created_at', $wCols, true)) {
            $insertCols[] = 'created_at';
            $insertVals[] = $nowStr;
        }
        if (in_array('updated_at', $wCols, true)) {
            $insertCols[] = 'updated_at';
            $insertVals[] = $nowStr;
        }

        $placeholders = implode(', ', array_fill(0, count($insertCols), '?'));
        $colList      = implode(', ', $insertCols);
        $insWithdraw  = $pdo->prepare("INSERT INTO withdrawals ({$colList}) VALUES ({$placeholders})");
        $insWithdraw->execute($insertVals);

        $pdo->commit();

        logBalanceChange($pdo, $telegramId, null, 'withdrawal', -$amount, $currentBal, $newBalance, "Withdrawal via {$method} ({$account})");

        $ADMIN_ID       = getAdminChatIdEnv();
        $token          = getBotTokenEnv();
        $safePlayerName = htmlspecialchars($playerName, ENT_QUOTES, 'UTF-8');
        $safeMethod     = htmlspecialchars($method, ENT_QUOTES, 'UTF-8');
        $safeAccount    = htmlspecialchars($account, ENT_QUOTES, 'UTF-8');
        $formattedAmt   = number_format($amount, 2);

        $adminMsg = "💸 <b>New Withdrawal Request (SMART BINGO)!</b>\n\n" .
                    "• <b>Player:</b> {$safePlayerName}\n" .
                    "• <b>Telegram ID:</b> <code>{$telegramId}</code>\n" .
                    "• <b>Amount:</b> <b>{$formattedAmt} ETB</b>\n" .
                    "• <b>Method:</b> {$safeMethod}\n" .
                    "• <b>Account/Phone:</b> <code>{$safeAccount}</code>";

        $notifyRes = notifyAdminWithdrawal($token, $ADMIN_ID, $adminMsg);

        $msg = $notifyRes['sent']
            ? 'Withdrawal request submitted! Admin has been notified.'
            : 'Withdrawal request submitted, but Telegram admin alert could not be delivered (' . ($notifyRes['error'] ?: 'check bot token/chat ID') . ').';

        echo json_encode([
            'success'        => true,
            'new_balance'    => $newBalance,
            'admin_notified' => $notifyRes['sent'],
            'notify_error'   => $notifyRes['error'],
            'message'        => $msg,
        ]);
    } catch (Throwable $e) {
        resetAbortedTransaction($pdo);
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
