<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

header('Content-Type: text/plain; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo "OK - Smart_Bingo_Bot webhook endpoint";
    exit;
}

$raw = file_get_contents('php://input');
$update = json_decode($raw ?: '', true);

if (!is_array($update)) {
    http_response_code(200);
    echo "INVALID_JSON";
    exit;
}

$token = trim((string)(getenv('TELEGRAM_BOT_TOKEN') ?: getenv('BOT_TOKEN') ?: ''));
$adminId = trim((string)(getenv('ADMIN_TELEGRAM_ID') ?: getenv('ADMIN_ID') ?: '730967613'));
$webAppUrl = rtrim((string)(getenv('APP_URL') ?: 'https://lali-bingo-bot.onrender.com'), '/') . '/index.php';
$referralBonusEtb = round((float)(getenv('REFERRAL_BONUS_ETB') ?: '5.00'), 2);
const PROMO_POST_COOLDOWN_SECONDS = 7200; // 2 hours

if ($token === '') {
    error_log("Smart_Bingo_Bot: TELEGRAM_BOT_TOKEN is empty");
    http_response_code(200);
    echo "MISSING_TOKEN";
    exit;
}

function nowTimestampText(): string {
    return gmdate('Y-m-d H:i:s');
}

function tgPost(string $token, string $method, array $payload): array {
    $ch = curl_init("https://api.telegram.org/bot{$token}/{$method}");
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
    ]);
    $resp = curl_exec($ch);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($err !== '') {
        error_log("Telegram {$method} cURL error: {$err}");
        return ['ok' => false, 'error' => $err];
    }
    $decoded = json_decode((string)$resp, true);
    return is_array($decoded) ? $decoded : ['ok' => false, 'raw' => $resp];
}

function resolveBotUsername(string $token, string $fallback = 'Smart_Bingo_Bot'): string {
    static $resolved = null;
    if ($resolved !== null) {
        return $resolved;
    }

    $envUsername = trim((string)(getenv('TELEGRAM_BOT_USERNAME') ?: getenv('BOT_USERNAME') ?: ''));
    if ($envUsername !== '') {
        $resolved = ltrim($envUsername, '@');
        return $resolved;
    }

    $me = tgPost($token, 'getMe', []);
    if (!empty($me['ok']) && !empty($me['result']['username'])) {
        $resolved = ltrim((string)$me['result']['username'], '@');
        return $resolved;
    }

    $resolved = ltrim($fallback, '@');
    return $resolved;
}

$botUsername = resolveBotUsername($token, 'Smart_Bingo_Bot');

function isPostgresPdo(PDO $pdo): bool {
    return strtolower((string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)) === 'pgsql';
}

function clearAbortedPdo(PDO $pdo): void {
    while ($pdo->inTransaction()) {
        try {
            $pdo->rollBack();
        } catch (Throwable $e) {
            break;
        }
    }
    if (isPostgresPdo($pdo)) {
        try {
            $pdo->exec("ROLLBACK");
        } catch (Throwable $e) {
        }
    }
}

function tableExists(PDO $pdo, string $table): bool {
    if (isPostgresPdo($pdo)) {
        $stmt = $pdo->prepare("
            SELECT 1
            FROM information_schema.tables
            WHERE table_schema = 'public' AND table_name = ?
            LIMIT 1
        ");
        $stmt->execute([$table]);
        return (bool)$stmt->fetchColumn();
    }

    $stmt = $pdo->prepare("SELECT name FROM sqlite_master WHERE type = 'table' AND name = ?");
    $stmt->execute([$table]);
    return (bool)$stmt->fetchColumn();
}

function getTableColumns(PDO $pdo, string $table): array {
    if (!tableExists($pdo, $table)) {
        return [];
    }
    if (isPostgresPdo($pdo)) {
        $stmt = $pdo->prepare("
            SELECT column_name
            FROM information_schema.columns
            WHERE table_schema = 'public' AND table_name = ?
        ");
        $stmt->execute([$table]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN, 0) ?: [];
    }

    $stmt = $pdo->query("PRAGMA table_info({$table})");
    return $stmt ? ($stmt->fetchAll(PDO::FETCH_COLUMN, 1) ?: []) : [];
}

function extractWithdrawAccount(string $detailsText): string {
    if (preg_match('/(\+?\d{9,15})/', $detailsText, $m)) {
        return trim((string)$m[1]);
    }
    return trim($detailsText);
}

function ensureWithdrawalsSchema(PDO $pdo): void {
    static $checked = false;
    if ($checked) {
        return;
    }
    $checked = true;

    try {
        clearAbortedPdo($pdo);
        if (!tableExists($pdo, 'withdrawals')) {
            if (isPostgresPdo($pdo)) {
                $pdo->exec("
                    CREATE TABLE IF NOT EXISTS withdrawals (
                        id SERIAL PRIMARY KEY,
                        telegram_id TEXT NOT NULL,
                        amount DOUBLE PRECISION NOT NULL DEFAULT 0,
                        method TEXT NOT NULL DEFAULT '',
                        account TEXT NOT NULL DEFAULT '',
                        status TEXT NOT NULL DEFAULT 'pending',
                        created_at TEXT NOT NULL
                    )
                ");
            } else {
                $pdo->exec("
                    CREATE TABLE IF NOT EXISTS withdrawals (
                        id INTEGER PRIMARY KEY AUTOINCREMENT,
                        telegram_id TEXT NOT NULL,
                        amount REAL NOT NULL DEFAULT 0,
                        method TEXT NOT NULL DEFAULT '',
                        account TEXT NOT NULL DEFAULT '',
                        status TEXT NOT NULL DEFAULT 'pending',
                        created_at TEXT NOT NULL
                    )
                ");
            }
            return;
        }

        $cols = getTableColumns($pdo, 'withdrawals');
        foreach (['method' => "''", 'account' => "''", 'status' => "'pending'", 'created_at' => "''"] as $colName => $defaultSql) {
            if (!in_array($colName, $cols, true)) {
                $pdo->exec("ALTER TABLE withdrawals ADD COLUMN {$colName} TEXT DEFAULT {$defaultSql}");
            }
        }
        if (isPostgresPdo($pdo) && in_array('account', $cols, true)) {
            $pdo->exec("ALTER TABLE withdrawals ALTER COLUMN account SET DEFAULT ''");
        }
    } catch (Throwable $e) {
        error_log("ensureWithdrawalsSchema warning: " . $e->getMessage());
        clearAbortedPdo($pdo);
    }
}

function insertWithdrawalRecord(PDO $pdo, string $telegramId, string $methodLabel, string $detailsText, float $amount, string $ts): int {
    $cols = getTableColumns($pdo, 'withdrawals');
    if (!$cols) {
        return 0;
    }

    $fields = [];
    $params = [];

    if (in_array('telegram_id', $cols, true)) {
        $fields[] = 'telegram_id';
        $params[] = $telegramId;
    } elseif (in_array('user_id', $cols, true)) {
        $fields[] = 'user_id';
        $params[] = $telegramId;
    }

    if (in_array('amount', $cols, true)) {
        $fields[] = 'amount';
        $params[] = $amount;
    }

    $methodCol = in_array('method', $cols, true)
        ? 'method'
        : (in_array('payment_method', $cols, true) ? 'payment_method' : null);
    if ($methodCol !== null) {
        $fields[] = $methodCol;
        $params[] = $methodLabel;
    }

    $accountVal = extractWithdrawAccount($detailsText);
    if (in_array('account', $cols, true)) {
        $fields[] = 'account';
        $params[] = $accountVal;
    }

    $detailsCol = in_array('details', $cols, true)
        ? 'details'
        : (in_array('account_details', $cols, true) ? 'account_details'
        : (in_array('phone', $cols, true) ? 'phone' : null));
    if ($detailsCol !== null && $detailsCol !== 'account') {
        $fields[] = $detailsCol;
        $params[] = $detailsText;
    }

    if (in_array('status', $cols, true)) {
        $fields[] = 'status';
        $params[] = 'pending';
    }

    if (in_array('created_at', $cols, true)) {
        $fields[] = 'created_at';
        $params[] = $ts;
    }

    if (!$fields) {
        return 0;
    }

    $placeholders = implode(', ', array_fill(0, count($fields), '?'));
    $colList = implode(', ', $fields);
    $sql = "INSERT INTO withdrawals ({$colList}) VALUES ({$placeholders})";

    if (isPostgresPdo($pdo) && in_array('id', $cols, true)) {
        $stmt = $pdo->prepare($sql . " RETURNING id");
        $stmt->execute($params);
        return (int)($stmt->fetchColumn() ?: 0);
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return (int)$pdo->lastInsertId();
}

function ensureDepositStateSchema(PDO $pdo): void {
    static $checked = false;
    if ($checked) {
        return;
    }
    $checked = true;

    try {
        clearAbortedPdo($pdo);
        if (isPostgresPdo($pdo)) {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS user_bot_states (
                    telegram_id TEXT PRIMARY KEY,
                    state_key TEXT NOT NULL DEFAULT '',
                    updated_at TEXT NOT NULL
                )
            ");
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS transactions (
                    id SERIAL PRIMARY KEY,
                    telegram_id TEXT,
                    tx_reference TEXT UNIQUE,
                    amount DOUBLE PRECISION,
                    source TEXT DEFAULT 'tg_deposit',
                    status TEXT DEFAULT 'pending',
                    created_at TEXT DEFAULT CURRENT_TIMESTAMP,
                    full_message TEXT
                )
            ");
            $pdo->exec("ALTER TABLE transactions ADD COLUMN IF NOT EXISTS source TEXT DEFAULT 'tg_deposit'");
            $pdo->exec("ALTER TABLE transactions ADD COLUMN IF NOT EXISTS full_message TEXT");
            return;
        }

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS user_bot_states (
                telegram_id TEXT PRIMARY KEY,
                state_key TEXT NOT NULL DEFAULT '',
                updated_at TEXT NOT NULL
            )
        ");
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS transactions (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                telegram_id TEXT,
                tx_reference TEXT UNIQUE,
                amount REAL,
                source TEXT DEFAULT 'tg_deposit',
                status TEXT DEFAULT 'pending',
                created_at TEXT DEFAULT CURRENT_TIMESTAMP,
                full_message TEXT
            )
        ");
        $txCols = $pdo->query("PRAGMA table_info(transactions)")->fetchAll(PDO::FETCH_COLUMN, 1);
        if (!in_array('source', $txCols, true)) {
            $pdo->exec("ALTER TABLE transactions ADD COLUMN source TEXT DEFAULT 'tg_deposit'");
        }
        if (!in_array('full_message', $txCols, true)) {
            $pdo->exec("ALTER TABLE transactions ADD COLUMN full_message TEXT");
        }
    } catch (Throwable $e) {
        error_log("ensureDepositStateSchema warning: " . $e->getMessage());
        clearAbortedPdo($pdo);
    }
}

function setUserBotState(PDO $pdo, string $telegramId, string $stateKey): void {
    ensureDepositStateSchema($pdo);
    $ts = nowTimestampText();
    if (isPostgresPdo($pdo)) {
        $pdo->prepare("
            INSERT INTO user_bot_states (telegram_id, state_key, updated_at)
            VALUES (?, ?, ?)
            ON CONFLICT (telegram_id) DO UPDATE SET state_key = EXCLUDED.state_key, updated_at = EXCLUDED.updated_at
        ")->execute([$telegramId, $stateKey, $ts]);
    } else {
        $pdo->prepare("
            INSERT OR REPLACE INTO user_bot_states (telegram_id, state_key, updated_at)
            VALUES (?, ?, ?)
        ")->execute([$telegramId, $stateKey, $ts]);
    }
}

function getUserBotState(PDO $pdo, string $telegramId): string {
    ensureDepositStateSchema($pdo);
    $stmt = $pdo->prepare("SELECT state_key FROM user_bot_states WHERE telegram_id = ? LIMIT 1");
    $stmt->execute([$telegramId]);
    return (string)($stmt->fetchColumn() ?: '');
}

function buildDepositMethodKeyboard(): array {
    return [
        'inline_keyboard' => [[
            ['text' => '📱 Telebirr', 'callback_data' => 'select_dep_method:telebirr'],
            ['text' => '🏦 Bank Account', 'callback_data' => 'select_dep_method:bank'],
        ]],
    ];
}

function buildWithdrawMethodKeyboard(): array {
    return [
        'inline_keyboard' => [[
            ['text' => '📱 Telebirr', 'callback_data' => 'select_wd_method:telebirr'],
            ['text' => '🏦 Bank Account', 'callback_data' => 'select_wd_method:bank'],
        ]],
    ];
}

function parseWithdrawAmountFromText(string $input): float {
    if (preg_match('/(\d+(?:\.\d{1,2})?)\s*(?:ብር|ETB)\b/i', $input, $m)) {
        return round((float)$m[1], 2);
    }

    preg_match_all('/\b(\d+(?:\.\d{1,2})?)\b/', $input, $allNums);
    $candidates = [];
    foreach (($allNums[1] ?? []) as $numStr) {
        $digitsOnly = preg_replace('/\D+/', '', (string)$numStr) ?? '';
        if (strlen($digitsOnly) < 9) {
            $val = round((float)$numStr, 2);
            if ($val > 0) {
                $candidates[] = $val;
            }
        }
    }

    return count($candidates) > 0 ? (float)end($candidates) : 0.00;
}

function ensureReferralSchema(PDO $pdo): void {
    static $checked = false;
    if ($checked) {
        return;
    }
    $checked = true;

    try {
        clearAbortedPdo($pdo);
        if (isPostgresPdo($pdo)) {
            $pdo->exec("ALTER TABLE players ADD COLUMN IF NOT EXISTS referred_by TEXT");
            $pdo->exec("ALTER TABLE players ADD COLUMN IF NOT EXISTS invites_count INTEGER NOT NULL DEFAULT 0");
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS referrals (
                    id SERIAL PRIMARY KEY,
                    referrer_id TEXT NOT NULL,
                    referred_id TEXT NOT NULL UNIQUE,
                    bonus_amount DOUBLE PRECISION NOT NULL DEFAULT 0,
                    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
                )
            ");
            return;
        }

        $cols = $pdo->query("PRAGMA table_info(players)")->fetchAll(PDO::FETCH_COLUMN, 1);
        if (!in_array('referred_by', $cols, true)) {
            $pdo->exec("ALTER TABLE players ADD COLUMN referred_by TEXT");
        }
        if (!in_array('invites_count', $cols, true)) {
            $pdo->exec("ALTER TABLE players ADD COLUMN invites_count INTEGER NOT NULL DEFAULT 0");
        }

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS referrals (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                referrer_id TEXT NOT NULL,
                referred_id TEXT NOT NULL UNIQUE,
                bonus_amount REAL NOT NULL DEFAULT 0,
                created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        ");
    } catch (Throwable $schemaErr) {
        error_log("ensureReferralSchema warning: " . $schemaErr->getMessage());
        clearAbortedPdo($pdo);
    }
}

function buildRecentInvitesText(PDO $pdo, int $limit = 10): string {
    ensureReferralSchema($pdo);
    $limit = max(1, min(25, $limit));

    $sql = "
        SELECT
            r.referrer_id,
            r.referred_id,
            r.bonus_amount,
            r.created_at,
            COALESCE(NULLIF(TRIM(p_ref.first_name), ''), 'Player') AS referrer_name,
            COALESCE(NULLIF(TRIM(p_new.first_name), ''), 'Player') AS referred_name
        FROM referrals r
        LEFT JOIN players p_ref ON CAST(p_ref.telegram_id AS TEXT) = CAST(r.referrer_id AS TEXT)
        LEFT JOIN players p_new ON CAST(p_new.telegram_id AS TEXT) = CAST(r.referred_id AS TEXT)
        ORDER BY r.id DESC
        LIMIT {$limit}
    ";
    $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

    if (!$rows) {
        return "SMART BINGO — Recent Invites\n\nNo invites recorded yet.";
    }

    $lines = ["SMART BINGO — Recent Invites (Last " . count($rows) . ")\n"];
    foreach ($rows as $idx => $r) {
        $num = $idx + 1;
        $refName = (string)($r['referrer_name'] ?? 'Player');
        $refId   = (string)($r['referrer_id'] ?? '');
        $newName = (string)($r['referred_name'] ?? 'Player');
        $newId   = (string)($r['referred_id'] ?? '');
        $bonus   = number_format((float)($r['bonus_amount'] ?? 0), 2);
        $created = (string)($r['created_at'] ?? '');

        $lines[] = "{$num}. {$refName} ({$refId}) -> {$newName} ({$newId}) | +{$bonus} ETB"
                 . ($created !== '' ? " [{$created}]" : '');
    }

    return implode("\n", $lines);
}

function addLedger(PDO $pdo, string $telegramId, ?int $roomId, string $type, float $amount, float $before, float $after, string $note): void {
    try {
        if (!tableExists($pdo, 'balance_ledger')) return;
        $stmt = $pdo->prepare("
            INSERT INTO balance_ledger (telegram_id, room_id, type, amount, balance_before, balance_after, note, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$telegramId, $roomId, $type, $amount, $before, $after, $note, nowTimestampText()]);
    } catch (Throwable $ledgerErr) {
        error_log("addLedger warning ({$telegramId}): " . $ledgerErr->getMessage());
        clearAbortedPdo($pdo);
    }
}

function getPlayerChatTarget(PDO $pdo, string $telegramId): string {
    $stmt = $pdo->prepare("SELECT chat_id FROM players WHERE CAST(telegram_id AS TEXT) = ? LIMIT 1");
    $stmt->execute([$telegramId]);
    $chatId = trim((string)($stmt->fetchColumn() ?: ''));
    return $chatId !== '' ? $chatId : $telegramId;
}

function sendPlayerNotification(PDO $pdo, string $token, string $targetPlayerId, string $messageText, ?array $replyMarkup = null): bool {
    if ($targetPlayerId === '' || $messageText === '') {
        return false;
    }

    $candidates = [];
    $savedChat = getPlayerChatTarget($pdo, $targetPlayerId);
    if ($savedChat !== '') {
        $candidates[] = $savedChat;
    }
    if (!in_array($targetPlayerId, $candidates, true)) {
        $candidates[] = $targetPlayerId;
    }

    foreach ($candidates as $chatTarget) {
        $payload = [
            'chat_id' => $chatTarget,
            'text'    => $messageText,
        ];
        if ($replyMarkup !== null) {
            $payload['reply_markup'] = $replyMarkup;
        }
        $res = tgPost($token, 'sendMessage', $payload);
        if (!empty($res['ok'])) {
            return true;
        }
        error_log("Smart_Bingo_Bot notify failed for chat {$chatTarget} (player {$targetPlayerId}): " . json_encode($res));
    }

    return false;
}

function notifyAdminReferral(
    PDO $pdo,
    string $token,
    string $adminId,
    string $referrerId,
    string $referrerName,
    string $newUserId,
    string $newUserName,
    float $bonusAmount,
    float $refNewBalance,
    int $newInvites
): void {
    if ($adminId === '') {
        return;
    }

    $msg = "SMART BINGO — New Player Invite!\n\n"
         . "• Inviter: {$referrerName} (ID: {$referrerId})\n"
         . "• New Friend: {$newUserName} (ID: {$newUserId})\n"
         . "• Inviter Total Invites: {$newInvites}\n"
         . "• Referral Bonus: +" . number_format($bonusAmount, 2) . " ETB\n"
         . "• Inviter New Balance: " . number_format($refNewBalance, 2) . " ETB";

    sendPlayerNotification($pdo, $token, $adminId, $msg);
}

function normalizeBonusTarget(string $targetSpec): string {
    $clean = strtolower(trim($targetSpec));
    if (in_array($clean, ['all', 'new', 'newcomers', 'zero'], true)) {
        return $clean === 'newcomers' ? 'new' : $clean;
    }
    $digits = preg_replace('/\D+/', '', $clean) ?? '';
    return $digits !== '' ? $digits : 'all';
}

function formatBonusTargetLabel(string $target): string {
    return match ($target) {
        'all'   => 'All Registered Players',
        'new'   => 'Newcomers (0.00 Bal & No Prior Rounds)',
        'zero'  => 'Zero-Balance Players (0.00 ETB)',
        default => "Specific Player ID: {$target}",
    };
}

function resolveBonusTargetPlayerIds(PDO $pdo, string $target): array {
    if ($target === 'all') {
        return $pdo->query("SELECT telegram_id FROM players")->fetchAll(PDO::FETCH_COLUMN, 0) ?: [];
    }

    $zeroExpr = isPostgresPdo($pdo)
        ? "ROUND(COALESCE(balance, 0)::numeric, 2) = 0.00"
        : "ROUND(COALESCE(balance, 0), 2) = 0.00";

    if ($target === 'zero') {
        $stmt = $pdo->query("SELECT telegram_id FROM players WHERE {$zeroExpr}");
        return $stmt->fetchAll(PDO::FETCH_COLUMN, 0) ?: [];
    }

    if ($target === 'new') {
        $hasRoomPlayers = tableExists($pdo, 'room_players');
        $hasLedger      = tableExists($pdo, 'balance_ledger');
        $pZeroExpr      = isPostgresPdo($pdo)
            ? "ROUND(COALESCE(p.balance, 0)::numeric, 2) = 0.00"
            : "ROUND(COALESCE(p.balance, 0), 2) = 0.00";

        $sql = "SELECT p.telegram_id FROM players p WHERE {$pZeroExpr}";
        if ($hasRoomPlayers) {
            $sql .= " AND NOT EXISTS (SELECT 1 FROM room_players rp WHERE CAST(rp.telegram_id AS TEXT) = CAST(p.telegram_id AS TEXT))";
        }
        if ($hasLedger) {
            $sql .= " AND NOT EXISTS (SELECT 1 FROM balance_ledger bl WHERE CAST(bl.telegram_id AS TEXT) = CAST(p.telegram_id AS TEXT))";
        }

        $ids = $pdo->query($sql)->fetchAll(PDO::FETCH_COLUMN, 0) ?: [];
        if (!$ids) {
            $orderCol = isPostgresPdo($pdo) ? 'telegram_id DESC' : 'rowid DESC';
            $fb = $pdo->query("SELECT telegram_id FROM players WHERE {$zeroExpr} ORDER BY {$orderCol} LIMIT 5");
            $ids = $fb->fetchAll(PDO::FETCH_COLUMN, 0) ?: [];
        }
        return $ids;
    }

    $digits = preg_replace('/\D+/', '', $target) ?? '';
    return $digits !== '' ? [$digits] : [];
}

function buildBonusTargetPickerKeyboard(): array {
    return [
        'inline_keyboard' => [
            [
                ['text' => '👥 All Players', 'callback_data' => 'bonus_pick_amt:all'],
                ['text' => '🆕 Newcomers', 'callback_data' => 'bonus_pick_amt:new'],
                ['text' => '0️⃣ 0.00 Bal', 'callback_data' => 'bonus_pick_amt:zero'],
            ],
        ],
    ];
}

function buildBonusAlertKeyboard(string $targetSpec = 'all'): array {
    $cleanTarget = normalizeBonusTarget($targetSpec);

    return [
        'inline_keyboard' => [
            [
                ['text' => '+10 ETB Bonus', 'callback_data' => "bonus_alert:10:{$cleanTarget}"],
                ['text' => '+20 ETB Bonus', 'callback_data' => "bonus_alert:20:{$cleanTarget}"],
                ['text' => '+50 ETB Bonus', 'callback_data' => "bonus_alert:50:{$cleanTarget}"],
            ],
            [
                ['text' => '⬅️ Change Criteria', 'callback_data' => 'open_bonus_menu:picker'],
            ],
        ],
    ];
}

function ensureDepositPromoSchema(PDO $pdo): void {
    static $checked = false;
    if ($checked) {
        return;
    }
    $checked = true;

    try {
        clearAbortedPdo($pdo);
        if (isPostgresPdo($pdo)) {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS deposit_promos (
                    id INTEGER PRIMARY KEY,
                    target_group TEXT NOT NULL DEFAULT 'all',
                    active INTEGER NOT NULL DEFAULT 0,
                    last_posted_at TEXT NOT NULL DEFAULT '',
                    last_posted_group TEXT NOT NULL DEFAULT '',
                    updated_at TEXT NOT NULL
                )
            ");
            $pdo->exec("ALTER TABLE deposit_promos ADD COLUMN IF NOT EXISTS last_posted_at TEXT NOT NULL DEFAULT ''");
            $pdo->exec("ALTER TABLE deposit_promos ADD COLUMN IF NOT EXISTS last_posted_group TEXT NOT NULL DEFAULT ''");
            $pdo->exec("
                INSERT INTO deposit_promos (id, target_group, active, last_posted_at, last_posted_group, updated_at)
                VALUES (1, 'all', 0, '', '', CURRENT_TIMESTAMP)
                ON CONFLICT (id) DO NOTHING
            ");
            return;
        }

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS deposit_promos (
                id INTEGER PRIMARY KEY,
                target_group TEXT NOT NULL DEFAULT 'all',
                active INTEGER NOT NULL DEFAULT 0,
                last_posted_at TEXT NOT NULL DEFAULT '',
                last_posted_group TEXT NOT NULL DEFAULT '',
                updated_at TEXT NOT NULL
            )
        ");
        $cols = $pdo->query("PRAGMA table_info(deposit_promos)")->fetchAll(PDO::FETCH_COLUMN, 1);
        if (!in_array('last_posted_at', $cols, true)) {
            $pdo->exec("ALTER TABLE deposit_promos ADD COLUMN last_posted_at TEXT NOT NULL DEFAULT ''");
        }
        if (!in_array('last_posted_group', $cols, true)) {
            $pdo->exec("ALTER TABLE deposit_promos ADD COLUMN last_posted_group TEXT NOT NULL DEFAULT ''");
        }
        $pdo->exec("
            INSERT OR IGNORE INTO deposit_promos (id, target_group, active, last_posted_at, last_posted_group, updated_at)
            VALUES (1, 'all', 0, '', '', CURRENT_TIMESTAMP)
        ");
    } catch (Throwable $e) {
        error_log("ensureDepositPromoSchema warning: " . $e->getMessage());
        clearAbortedPdo($pdo);
    }
}

function getActiveDepositPromo(PDO $pdo): array {
    ensureDepositPromoSchema($pdo);
    $row = $pdo->query("SELECT target_group, active, last_posted_at, last_posted_group, updated_at FROM deposit_promos WHERE id = 1 LIMIT 1")
               ->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return [
            'active'            => false,
            'target_group'      => 'all',
            'last_posted_at'    => '',
            'last_posted_group' => '',
            'updated_at'        => '',
        ];
    }
    return [
        'active'            => ((int)($row['active'] ?? 0)) === 1,
        'target_group'      => normalizeBonusTarget((string)($row['target_group'] ?? 'all')),
        'last_posted_at'    => trim((string)($row['last_posted_at'] ?? '')),
        'last_posted_group' => trim((string)($row['last_posted_group'] ?? '')),
        'updated_at'        => (string)($row['updated_at'] ?? ''),
    ];
}

function setDepositPromoConfig(PDO $pdo, bool $active, string $targetGroup): array {
    ensureDepositPromoSchema($pdo);
    $cur = getActiveDepositPromo($pdo);
    $cleanTarget = normalizeBonusTarget($targetGroup);
    $actInt = $active ? 1 : 0;
    $ts = nowTimestampText();
    $lastPostedAt = (string)$cur['last_posted_at'];
    $lastPostedGroup = (string)$cur['last_posted_group'];

    if (isPostgresPdo($pdo)) {
        $pdo->prepare("
            INSERT INTO deposit_promos (id, target_group, active, last_posted_at, last_posted_group, updated_at)
            VALUES (1, ?, ?, ?, ?, ?)
            ON CONFLICT (id) DO UPDATE
            SET target_group = EXCLUDED.target_group,
                active = EXCLUDED.active,
                updated_at = EXCLUDED.updated_at
        ")->execute([$cleanTarget, $actInt, $lastPostedAt, $lastPostedGroup, $ts]);
    } else {
        $pdo->prepare("
            INSERT OR REPLACE INTO deposit_promos (id, target_group, active, last_posted_at, last_posted_group, updated_at)
            VALUES (1, ?, ?, ?, ?, ?)
        ")->execute([$cleanTarget, $actInt, $lastPostedAt, $lastPostedGroup, $ts]);
    }

    return getActiveDepositPromo($pdo);
}

function markDepositPromoPosted(PDO $pdo, string $targetGroup): void {
    ensureDepositPromoSchema($pdo);
    $cur = getActiveDepositPromo($pdo);
    $ts = nowTimestampText();
    $cleanGroup = normalizeBonusTarget($targetGroup);

    if (isPostgresPdo($pdo)) {
        $pdo->prepare("
            UPDATE deposit_promos
            SET last_posted_at = ?, last_posted_group = ?, updated_at = ?
            WHERE id = 1
        ")->execute([$ts, $cleanGroup, $ts]);
    } else {
        $pdo->prepare("
            INSERT OR REPLACE INTO deposit_promos (id, target_group, active, last_posted_at, last_posted_group, updated_at)
            VALUES (1, ?, ?, ?, ?, ?)
        ")->execute([
            (string)$cur['target_group'],
            !empty($cur['active']) ? 1 : 0,
            $ts,
            $cleanGroup,
            $ts,
        ]);
    }
}

function getPromoPostCooldownRemaining(array $promoCfg, string $targetGroup): int {
    $lastAtStr = trim((string)($promoCfg['last_posted_at'] ?? ''));
    $lastGroup = normalizeBonusTarget((string)($promoCfg['last_posted_group'] ?? ''));
    $cleanGroup = normalizeBonusTarget($targetGroup);

    if ($lastAtStr === '' || $lastGroup !== $cleanGroup) {
        return 0;
    }

    $lastTs = strtotime($lastAtStr . ' UTC');
    if ($lastTs === false || $lastTs <= 0) {
        return 0;
    }

    $elapsed = time() - $lastTs;
    $remaining = PROMO_POST_COOLDOWN_SECONDS - $elapsed;
    return $remaining > 0 ? $remaining : 0;
}

function formatCooldownDuration(int $seconds): string {
    $seconds = max(1, $seconds);
    $hours = intdiv($seconds, 3600);
    $mins = intdiv($seconds % 3600, 60);
    $secs = $seconds % 60;

    $parts = [];
    if ($hours > 0) {
        $parts[] = "{$hours}h";
    }
    if ($mins > 0) {
        $parts[] = "{$mins}m";
    }
    if ($hours === 0 && $mins === 0) {
        $parts[] = "{$secs}s";
    }
    return implode(' ', $parts);
}

function buildDepositPromoTargetKeyboard(): array {
    return [
        'inline_keyboard' => [
            [
                ['text' => '👥 All Players', 'callback_data' => 'dep_promo_target:all'],
                ['text' => '🆕 Newcomers', 'callback_data' => 'dep_promo_target:new'],
                ['text' => '0️⃣ 0.00 Bal', 'callback_data' => 'dep_promo_target:zero'],
            ],
            [
                ['text' => '📢 Post Promo (2h)', 'callback_data' => 'post_dep_promo_now'],
                ['text' => '⛔ Turn Off Promo', 'callback_data' => 'dep_promo_off'],
            ],
        ],
    ];
}

function buildDepositPromoConfirmKeyboard(string $targetSpec): array {
    $cleanTarget = normalizeBonusTarget($targetSpec);
    return [
        'inline_keyboard' => [
            [
                ['text' => '✅ Enable Promo (50→+20 / 100→+30)', 'callback_data' => "dep_promo_enable:{$cleanTarget}"],
            ],
            [
                ['text' => '📢 Enable & Post Once (2h)', 'callback_data' => "dep_promo_enable_post:{$cleanTarget}"],
            ],
            [
                ['text' => '⬅️ Change Group', 'callback_data' => 'open_dep_promo_menu'],
            ],
        ],
    ];
}

function calculateTieredDepositBonus(float $depositAmount): float {
    $amt = round($depositAmount, 2);
    if ($amt >= 100.00) {
        return 30.00;
    }
    if ($amt >= 50.00) {
        return 20.00;
    }
    return 0.00;
}

function isPlayerEligibleForPromo(PDO $pdo, string $playerId, string $targetGroup, ?float $preDepositBalance = null): bool {
    $cleanGroup = normalizeBonusTarget($targetGroup);
    if ($cleanGroup === 'all') {
        return true;
    }

    $matchedIds = array_map('strval', resolveBonusTargetPlayerIds($pdo, $cleanGroup));
    if (in_array((string)$playerId, $matchedIds, true)) {
        return true;
    }

    if ($preDepositBalance !== null && round($preDepositBalance, 2) === 0.00) {
        if ($cleanGroup === 'zero') {
            return true;
        }
        if ($cleanGroup === 'new') {
            $hasRoomPlayers = tableExists($pdo, 'room_players');
            $hasLedger      = tableExists($pdo, 'balance_ledger');
            if ($hasRoomPlayers) {
                $rpStmt = $pdo->prepare("SELECT 1 FROM room_players WHERE CAST(telegram_id AS TEXT) = ? LIMIT 1");
                $rpStmt->execute([(string)$playerId]);
                if ($rpStmt->fetchColumn()) {
                    return false;
                }
            }
            if ($hasLedger) {
                $blStmt = $pdo->prepare("SELECT 1 FROM balance_ledger WHERE CAST(telegram_id AS TEXT) = ? LIMIT 1");
                $blStmt->execute([(string)$playerId]);
                if ($blStmt->fetchColumn()) {
                    return false;
                }
            }
            return true;
        }
    }

    return false;
}

function buildPromoAnnouncementText(string $targetGroup): string {
    $groupLabel = formatBonusTargetLabel($targetGroup);
    return "🎁 SMART BINGO — DEPOSIT & BONUS PROMO! 🎁\n\n"
         . "Deposit now and get an extra bonus on your balance:\n"
         . "• Deposit 50+ ETB → Get +20.00 ETB Bonus!\n"
         . "• Deposit 100+ ETB → Get +30.00 ETB Bonus!\n"
         . "• Eligible Group: {$groupLabel}\n\n"
         . "Tap Deposit below to pick Telebirr or Bank Account, send your payment SMS, and start playing!";
}

function broadcastPromoToPlayers(PDO $pdo, string $token, string $targetGroup, string $webAppUrl): array {
    $cleanGroup = normalizeBonusTarget($targetGroup);
    $curPromo = getActiveDepositPromo($pdo);
    $remaining = getPromoPostCooldownRemaining($curPromo, $cleanGroup);

    if ($remaining > 0) {
        return [
            'ok'            => false,
            'throttled'     => true,
            'remaining_sec' => $remaining,
            'wait_text'     => formatCooldownDuration($remaining),
            'group_label'   => formatBonusTargetLabel($cleanGroup),
        ];
    }

    markDepositPromoPosted($pdo, $cleanGroup);

    $playerIds = resolveBonusTargetPlayerIds($pdo, $cleanGroup);
    $msg = buildPromoAnnouncementText($cleanGroup);
    $playKeyboard = [
        'inline_keyboard' => [[
            ['text' => '🎮 Play Bingo', 'web_app' => ['url' => $webAppUrl]],
        ]],
    ];

    $sentCount = 0;
    foreach ($playerIds as $pid) {
        if (sendPlayerNotification($pdo, $token, (string)$pid, $msg, $playKeyboard)) {
            $sentCount++;
        }
    }

    return [
        'ok'            => true,
        'throttled'     => false,
        'total_matched' => count($playerIds),
        'sent_count'    => $sentCount,
        'group_label'   => formatBonusTargetLabel($cleanGroup),
    ];
}

function notifyAndDepositBonus(
    PDO $pdo,
    string $token,
    string $targetPlayerId,
    float $amount,
    string $note = 'Admin Bonus Alert',
    string $customMessage = 'Hello dear today we have a bonus'
): array {
    $targetPlayerId = trim($targetPlayerId);
    $amount = round($amount, 2);

    if ($targetPlayerId === '' || $amount <= 0) {
        return ['ok' => false, 'error' => 'Invalid player ID or bonus amount'];
    }

    clearAbortedPdo($pdo);

    try {
        $stmt = $pdo->prepare("SELECT telegram_id, balance, first_name FROM players WHERE CAST(telegram_id AS TEXT) = ? LIMIT 1");
        $stmt->execute([$targetPlayerId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        $canonicalId = $row ? (string)$row['telegram_id'] : $targetPlayerId;
        $playerName = $row
            ? (trim((string)($row['first_name'] ?? 'Player')) ?: 'Player')
            : 'Player';

        $ts = nowTimestampText();
        if (!$row) {
            $pdo->prepare("INSERT INTO players (telegram_id, chat_id, first_name, balance, created_at, updated_at) VALUES (?, ?, 'Player', 0.00, ?, ?)")
                ->execute([$canonicalId, $canonicalId, $ts, $ts]);
            $before = 0.00;
        } else {
            $before = round((float)($row['balance'] ?? 0.00), 2);
        }

        $after = round($before + $amount, 2);
        $pdo->prepare("UPDATE players SET balance = ?, updated_at = ? WHERE CAST(telegram_id AS TEXT) = ?")
            ->execute([$after, $ts, $canonicalId]);

        $amtStr   = number_format($amount, 2);
        $afterStr = number_format($after, 2);
        $headline = trim($customMessage) !== '' ? trim($customMessage) : 'Hello dear today we have a bonus';

        addLedger($pdo, $canonicalId, null, 'bonus_deposit', $amount, $before, $after, "{$note} (+{$amtStr} ETB bonus alert)");

        $alertMsg = "{$headline}\n\n"
                  . "Player: {$playerName}\n"
                  . "Bonus Amount: +{$amtStr} ETB\n"
                  . "New Balance: {$afterStr} ETB";

        $notified = sendPlayerNotification($pdo, $token, $canonicalId, $alertMsg);

        return [
            'ok'          => true,
            'player_id'   => $canonicalId,
            'bonus'       => $amount,
            'new_balance' => $after,
            'notified'    => $notified,
        ];
    } catch (Throwable $e) {
        error_log("notifyAndDepositBonus error ({$targetPlayerId}): " . $e->getMessage());
        clearAbortedPdo($pdo);
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

function sendBonusAlertToTarget(
    PDO $pdo,
    string $token,
    string $targetSpec,
    float $amount,
    string $note = 'Hello dear today we have a bonus'
): array {
    $allowedAmounts = [10.00, 20.00, 50.00];
    $amount = round($amount, 2);
    if (!in_array($amount, $allowedAmounts, true)) {
        return ['ok' => false, 'error' => 'Bonus alert amount must be 10, 20, or 50 ETB.'];
    }

    $normalizedTarget = normalizeBonusTarget($targetSpec);
    $playerIds = resolveBonusTargetPlayerIds($pdo, $normalizedTarget);

    if (!$playerIds) {
        return ['ok' => false, 'error' => 'No matching players found for selection: ' . formatBonusTargetLabel($normalizedTarget)];
    }

    if (count($playerIds) === 1 && !in_array($normalizedTarget, ['all', 'new', 'zero'], true)) {
        $res = notifyAndDepositBonus($pdo, $token, (string)$playerIds[0], $amount, 'Admin Bonus Alert', $note);
        $res['mode'] = 'single';
        return $res;
    }

    $successCount = 0;
    $notifiedCount = 0;
    $lastError = '';
    foreach ($playerIds as $pid) {
        $res = notifyAndDepositBonus($pdo, $token, (string)$pid, $amount, 'Admin Bonus Alert', $note);
        if (!empty($res['ok'])) {
            $successCount++;
            if (!empty($res['notified'])) {
                $notifiedCount++;
            }
        } else {
            $lastError = (string)($res['error'] ?? '');
        }
    }

    return [
        'ok'             => true,
        'mode'           => 'group',
        'criteria'       => formatBonusTargetLabel($normalizedTarget),
        'amount'         => $amount,
        'credited_count' => $successCount,
        'notified_count' => $notifiedCount,
        'last_error'     => $lastError,
    ];
}

function applyJoinReferralBonus(
    PDO $pdo,
    string $token,
    string $adminId,
    string $newUserId,
    string $newUserName,
    string $referrerId,
    float $bonusAmount
): array {
    $referrerId = preg_replace('/\D+/', '', $referrerId) ?? '';
    if ($referrerId === '' || $referrerId === $newUserId) {
        return ['awarded' => false, 'reason' => 'invalid_or_self'];
    }

    ensureReferralSchema($pdo);
    clearAbortedPdo($pdo);

    try {
        $chk = $pdo->prepare("SELECT id FROM referrals WHERE CAST(referred_id AS TEXT) = ? LIMIT 1");
        $chk->execute([$newUserId]);
        if ($chk->fetchColumn()) {
            return ['awarded' => false, 'reason' => 'already_referred'];
        }

        $pNewStmt = $pdo->prepare("SELECT referred_by FROM players WHERE CAST(telegram_id AS TEXT) = ? LIMIT 1");
        $pNewStmt->execute([$newUserId]);
        $existingRef = trim((string)($pNewStmt->fetchColumn() ?: ''));
        if ($existingRef !== '') {
            return ['awarded' => false, 'reason' => 'existing_referrer'];
        }

        $pRefStmt = $pdo->prepare("SELECT balance, invites_count, first_name FROM players WHERE CAST(telegram_id AS TEXT) = ? LIMIT 1");
        $pRefStmt->execute([$referrerId]);
        $refRow = $pRefStmt->fetch(PDO::FETCH_ASSOC);
        $ts = nowTimestampText();
        if (!$refRow) {
            $pdo->prepare("INSERT INTO players (telegram_id, chat_id, first_name, balance, invites_count, created_at, updated_at) VALUES (?, ?, 'Player', 0.00, 0, ?, ?)")
                ->execute([$referrerId, $referrerId, $ts, $ts]);
            $refBefore = 0.00;
            $refInvites = 0;
            $referrerName = 'Player';
        } else {
            $refBefore = round((float)($refRow['balance'] ?? 0.00), 2);
            $refInvites = (int)($refRow['invites_count'] ?? 0);
            $referrerName = trim((string)($refRow['first_name'] ?? 'Player')) ?: 'Player';
        }

        $pdo->prepare("UPDATE players SET referred_by = ?, updated_at = ? WHERE CAST(telegram_id AS TEXT) = ?")
            ->execute([$referrerId, $ts, $newUserId]);

        $actualBonus = ($bonusAmount > 0) ? $bonusAmount : 0.00;
        $refAfter = round($refBefore + $actualBonus, 2);
        $newInvites = $refInvites + 1;

        $pdo->prepare("UPDATE players SET balance = ?, invites_count = ?, updated_at = ? WHERE CAST(telegram_id AS TEXT) = ?")
            ->execute([$refAfter, $newInvites, $ts, $referrerId]);

        $pdo->prepare("INSERT INTO referrals (referrer_id, referred_id, bonus_amount, created_at) VALUES (?, ?, ?, ?)")
            ->execute([$referrerId, $newUserId, $actualBonus, $ts]);

        if ($actualBonus > 0) {
            addLedger(
                $pdo,
                $referrerId,
                null,
                'referral_bonus',
                $actualBonus,
                $refBefore,
                $refAfter,
                "Join referral bonus for inviting {$newUserId}"
            );
        }

        if ($actualBonus > 0) {
            $msg = "SMART BINGO — Join Referral Bonus!\n\n"
                 . "New Friend Joined: {$newUserName} (ID: {$newUserId})\n"
                 . "Bonus Credited: +" . number_format($actualBonus, 2) . " ETB\n"
                 . "Total Invites: {$newInvites}\n"
                 . "New Balance: " . number_format($refAfter, 2) . " ETB";
            sendPlayerNotification($pdo, $token, $referrerId, $msg);
        }

        notifyAdminReferral(
            $pdo,
            $token,
            $adminId,
            $referrerId,
            $referrerName,
            $newUserId,
            $newUserName,
            $actualBonus,
            $refAfter,
            $newInvites
        );

        return ['awarded' => true, 'bonus' => $actualBonus, 'new_balance' => $refAfter];
    } catch (Throwable $e) {
        clearAbortedPdo($pdo);
        error_log("Referral bonus error ({$referrerId} -> {$newUserId}): " . $e->getMessage());
        return ['awarded' => false, 'reason' => 'db_error'];
    }
}

function buildMainMenuKeyboard(string $webAppUrl, bool $isAdmin): array {
    $rows = [
        [
            ['text' => 'Play Bingo', 'web_app' => ['url' => $webAppUrl]],
            ['text' => 'My Balance'],
        ],
        [
            ['text' => 'Deposit'],
            ['text' => 'Withdraw'],
        ],
        [
            ['text' => 'Share & Invite'],
            ['text' => 'How to Play'],
        ],
        [
            ['text' => 'My ID'],
        ],
    ];

    if ($isAdmin) {
        $rows[] = [
            ['text' => 'Admin Stats'],
        ];
    }

    return [
        'keyboard'                => $rows,
        'resize_keyboard'         => true,
        'is_persistent'           => true,
        'input_field_placeholder' => 'Choose a menu or type /start...',
    ];
}

function parseAdminCallback(string $data, string $fallbackText = ''): ?array {
    $trimmed = trim($data);
    if ($trimmed === '') return null;

    if ($trimmed === 'admin_recent_invites') {
        return ['kind' => 'recent_invites'];
    }

    if ($trimmed === 'open_dep_promo_menu') {
        return ['kind' => 'open_dep_promo_menu'];
    }

    if ($trimmed === 'dep_promo_off') {
        return ['kind' => 'dep_promo_off'];
    }

    if ($trimmed === 'post_dep_promo_now') {
        return ['kind' => 'post_dep_promo_now'];
    }

    if (preg_match('/^dep_promo_target:(all|new|newcomers|zero|\d+)$/i', $trimmed, $dpTarget)) {
        return [
            'kind'   => 'dep_promo_target',
            'target' => normalizeBonusTarget((string)$dpTarget[1]),
        ];
    }

    if (preg_match('/^dep_promo_enable:(all|new|newcomers|zero|\d+)$/i', $trimmed, $dpEnable)) {
        return [
            'kind'   => 'dep_promo_enable',
            'target' => normalizeBonusTarget((string)$dpEnable[1]),
        ];
    }

    if (preg_match('/^dep_promo_enable_post:(all|new|newcomers|zero|\d+)$/i', $trimmed, $dpEnablePost)) {
        return [
            'kind'   => 'dep_promo_enable_post',
            'target' => normalizeBonusTarget((string)$dpEnablePost[1]),
        ];
    }

    if (preg_match('/^open_bonus_menu:([a-zA-Z0-9_-]+)$/i', $trimmed, $openMatch)) {
        return [
            'kind'   => 'open_bonus_menu',
            'target' => strtolower((string)$openMatch[1]),
        ];
    }

    if (preg_match('/^bonus_pick_amt:(all|new|newcomers|zero|\d+)$/i', $trimmed, $pickMatch)) {
        return [
            'kind'   => 'bonus_pick_amt',
            'target' => normalizeBonusTarget((string)$pickMatch[1]),
        ];
    }

    if (preg_match('/^bonus_alert:(10|20|50):([a-zA-Z0-9_-]+)$/i', $trimmed, $bMatch)) {
        return [
            'kind'   => 'bonus_alert',
            'amount' => round((float)$bMatch[1], 2),
            'target' => normalizeBonusTarget((string)$bMatch[2]),
        ];
    }

    if (preg_match('/^(approve_tx|reject_tx):(\d+)$/i', $trimmed, $txMatch)) {
        return [
            'kind'       => 'deposit',
            'is_approve' => strtolower((string)$txMatch[1]) === 'approve_tx',
            'record_id'  => (int)$txMatch[2],
            'player_id'  => '',
            'amount'     => 0.0,
        ];
    }

    $lower = strtolower($trimmed);
    $isApprove = str_contains($lower, 'approve') || str_starts_with($lower, 'app');
    $isReject  = str_contains($lower, 'reject')  || str_starts_with($lower, 'rej');
    if (!$isApprove && !$isReject) return null;

    $isWithdraw = str_contains($lower, 'wd') || str_contains($lower, 'withdraw');
    $kind = $isWithdraw ? 'withdrawal' : 'deposit';

    $tokens = preg_split('/[:|_ -]+/', $trimmed) ?: [];
    $recordId = 0;
    $playerId = '';
    $amount = 0.0;

    if (str_contains($trimmed, ':') && count($tokens) >= 4) {
        $recordId = (int)$tokens[1];
        $playerId = preg_replace('/\D+/', '', (string)$tokens[2]) ?? '';
        $amount   = round((float)$tokens[3], 2);
    } else {
        if (str_contains($trimmed, ':') && count($tokens) >= 2 && ctype_digit((string)$tokens[1])) {
            $recordId = (int)$tokens[1];
        }
        if ($fallbackText !== '' && preg_match('/Amount[:\s*]*(\d+(?:\.\d{1,2})?)\s*ETB/i', $fallbackText, $m)) {
            $amount = round((float)$m[1], 2);
        }

        $numericTokens = [];
        foreach ($tokens as $tok) {
            if (preg_match('/^\d+(?:\.\d+)?$/', $tok)) {
                $numericTokens[] = $tok;
            }
        }
        foreach ($numericTokens as $n) {
            if (strlen((string)$n) >= 6 && $playerId === '') {
                $playerId = (string)$n;
            }
        }
        if ($amount <= 0 && count($numericTokens) > 0) {
            $lastNum = (float)end($numericTokens);
            if ($lastNum > 0 && $recordId === 0) {
                $amount = round($lastNum, 2);
            }
        }

        if ($playerId === '' && $fallbackText !== '') {
            if (preg_match('/\((\d{5,})\)/', $fallbackText, $m) || preg_match('/ID[:\s*]*(\d{5,})/i', $fallbackText, $m)) {
                $playerId = (string)$m[1];
            }
        }
    }

    return [
        'kind'       => $kind,
        'is_approve' => $isApprove,
        'record_id'  => $recordId,
        'player_id'  => $playerId,
        'amount'     => $amount,
    ];
}

/* =========================================================================
- 1. HANDLE INLINE BUTTON CLICKS (DEPOSIT/WITHDRAW METHOD, PROMO, BONUS, APPROVALS)
- ========================================================================= */
if (isset($update['callback_query']) && is_array($update['callback_query'])) {
    $cb         = $update['callback_query'];
    $cbId       = (string)($cb['id'] ?? '');
    $fromId     = (string)($cb['from']['id'] ?? '');
    $data       = (string)($cb['data'] ?? '');
    $adminChat  = (string)($cb['message']['chat']['id'] ?? $adminId);
    $adminMsgId = isset($cb['message']['message_id']) ? (int)$cb['message']['message_id'] : 0;
    $origText   = (string)($cb['message']['text'] ?? '');

    //if (preg_match('/^select_dep_method:(telebirr|bank)$/i', trim($data), $depPick)) {
      //  $pdo = getBingoPdo();
       // clearAbortedPdo($pdo);

//$methodKey = strtolower((string)$depPick[1]);
      //  $isBank = ($methodKey === 'bank');
      //  $methodLabel = $isBank ? 'Bank Account (CBE)' : 'Telebirr';
      //  $accountNum = $isBank ? '1000171689116' : '0917461538';

       // if ($fromId !== '') {
       //     setUserBotState($pdo, $fromId, 'awaiting_deposit_msg:' . $methodKey);
//}

//$promoCfg = getActiveDepositPromo($pdo);
     //   $promoLine = '';
      //  if (!empty($promoCfg['active']) && $fromId !== '' && isPlayerEligibleForPromo($pdo, $fromId, (string)$promoCfg['target_group'])) {
       //     $promoLine = "\n🎁 *Active Promo:* Deposit 50+ ETB → *+20 ETB* bonus | 100+ ETB → *+30 ETB* bonus!\n";
//}

      //  tgPost($token, 'answerCallbackQuery', [
      //      'callback_query_id' => $cbId,
       //     'text'              => "{$methodLabel} selected",
       //     'show_alert'        => false,
      //  ]);

      //  if ($adminMsgId > 0) {
      //      tgPost($token, 'editMessageText', [
//'chat_id'      => $adminChat,
              //  'message_id'   => $adminMsgId,
              //  'text'         => "💳 *{$methodLabel}* (Min: *10 ETB*)\n"
                  //              . "Account: `{$accountNum}` (LALISA SHAFE)\n"
                  //              . $promoLine . "\n"
                   //             . "Send your payment SMS / confirmation message next to submit for approval.",
             //   'parse_mode'   => 'Markdown',
//'reply_markup' => ['inline_keyboard' => []],
     //       ]);
    //    }

  //      http_response_code(200);
  //      echo "OK";
   //     exit;
  //  }


if (preg_match('/^select_dep_method:(telebirr|bank)$/i', trim($data), $depPick)) {
        tgPost($token, 'answerCallbackQuery', [
            'callback_query_id' => $cbId,
            'text'              => 'Please use the main Deposit option.',
            'show_alert'        => true,
        ]);

        if ($adminMsgId > 0) {
            tgPost($token, 'editMessageText', [
                'chat_id'      => $adminChat,
                'message_id'   => $adminMsgId,
                'text'         => 'This deposit option is disabled. Please use the main Deposit button.',
                'reply_markup' => ['inline_keyboard' => []],
            ]);
        }

        http_response_code(200);
        echo "OK";
        exit;
    }


    
    if (preg_match('/^select_wd_method:(telebirr|bank)$/i', trim($data), $wdPick)) {
        $pdo = getBingoPdo();
        clearAbortedPdo($pdo);

        $methodKey = strtolower((string)$wdPick[1]);
        $isBank = ($methodKey === 'bank');
        $methodLabel = $isBank ? 'Bank Account (CBE)' : 'Telebirr';
        $exampleText = $isBank
            ? "Example: `1000123456789 50` or `1000123456789 - 50 ETB`"
            : "Example: `0917461538 50` or `0917461538 - 50 ETB`";

        if ($fromId !== '') {
            setUserBotState($pdo, $fromId, 'awaiting_withdraw_msg:' . $methodKey);
        }

        tgPost($token, 'answerCallbackQuery', [
            'callback_query_id' => $cbId,
            'text'              => "{$methodLabel} selected",
            'show_alert'        => false,
        ]);

        if ($adminMsgId > 0) {
            tgPost($token, 'editMessageText', [
                'chat_id'      => $adminChat,
                'message_id'   => $adminMsgId,
                'text'         => "💸 *Withdraw to {$methodLabel}* (Min: *10 ETB*)\n"
                                . "Send your *{$methodLabel}* account/phone number and the *amount* next.\n"
                                . "{$exampleText}",
                'parse_mode'   => 'Markdown',
                'reply_markup' => ['inline_keyboard' => []],
            ]);
        }

        http_response_code(200);
        echo "OK";
        exit;
    }

    if ($adminId !== '' && $fromId !== $adminId) {
        tgPost($token, 'answerCallbackQuery', [
            'callback_query_id' => $cbId,
            'text'              => 'Only the admin can perform this action.',
            'show_alert'        => true,
        ]);
        http_response_code(200);
        echo "OK";
        exit;
    }

    $parsed = parseAdminCallback($data, $origText);
    $pdo = getBingoPdo();

    try {
        clearAbortedPdo($pdo);

        if ($parsed === null) {
            throw new RuntimeException('Unrecognized callback action: ' . $data);
        }

        if ($parsed['kind'] === 'recent_invites') {
            $invitesText = buildRecentInvitesText($pdo, 10);
            tgPost($token, 'answerCallbackQuery', [
                'callback_query_id' => $cbId,
                'text'              => 'Loaded recent invites',
                'show_alert'        => false,
            ]);

            if ($adminMsgId > 0) {
                tgPost($token, 'editMessageText', [
                    'chat_id'      => $adminChat,
                    'message_id'   => $adminMsgId,
                    'text'         => $invitesText,
                    'reply_markup' => [
                        'inline_keyboard' => [
                            [
                                ['text' => '🎁 Bonus Alert Menu', 'callback_data' => 'open_bonus_menu:picker'],
                            ],
                        ],
                    ],
                ]);
            }

            http_response_code(200);
            echo "OK";
            exit;
        }

        if ($parsed['kind'] === 'open_dep_promo_menu') {
            $curPromo = getActiveDepositPromo($pdo);
            $statusStr = $curPromo['active']
                ? "ACTIVE (" . formatBonusTargetLabel($curPromo['target_group']) . ")"
                : "OFF";
            $remSec = getPromoPostCooldownRemaining($curPromo, (string)$curPromo['target_group']);
            $cooldownLine = $remSec > 0
                ? "\n• Next Post Available In: " . formatCooldownDuration($remSec)
                : "\n• Post Cooldown: Ready (once every 2h)";

            tgPost($token, 'answerCallbackQuery', [
                'callback_query_id' => $cbId,
                'text'              => 'Select target group for Deposit Promo',
                'show_alert'        => false,
            ]);

            if ($adminMsgId > 0) {
                tgPost($token, 'editMessageText', [
                    'chat_id'      => $adminChat,
                    'message_id'   => $adminMsgId,
                    'text'         => "SMART BINGO — Deposit Bonus Promo\n\n"
                                    . "• Deposit 50+ ETB → +20.00 ETB Bonus\n"
                                    . "• Deposit 100+ ETB → +30.00 ETB Bonus\n"
                                    . "• Current Status: {$statusStr}"
                                    . $cooldownLine . "\n\n"
                                    . "Choose which group gets this deposit bonus:",
                    'reply_markup' => buildDepositPromoTargetKeyboard(),
                ]);
            }

            http_response_code(200);
            echo "OK";
            exit;
        }

        if ($parsed['kind'] === 'dep_promo_target') {
            $target = (string)$parsed['target'];
            $countPreview = count(resolveBonusTargetPlayerIds($pdo, $target));

            tgPost($token, 'answerCallbackQuery', [
                'callback_query_id' => $cbId,
                'text'              => "{$countPreview} player(s) in group",
                'show_alert'        => false,
            ]);

            if ($adminMsgId > 0) {
                tgPost($token, 'editMessageText', [
                    'chat_id'      => $adminChat,
                    'message_id'   => $adminMsgId,
                    'text'         => "SMART BINGO — Confirm Deposit Promo\n\n"
                                    . "• Target Group: " . formatBonusTargetLabel($target) . "\n"
                                    . "• Players in Group: {$countPreview}\n"
                                    . "• Rule: 50+ ETB deposit = +20 ETB | 100+ ETB deposit = +30 ETB\n"
                                    . "• Broadcast Limit: Once every 2 hours",
                    'reply_markup' => buildDepositPromoConfirmKeyboard($target),
                ]);
            }

            http_response_code(200);
            echo "OK";
            exit;
        }

        if ($parsed['kind'] === 'dep_promo_enable') {
            $target = (string)$parsed['target'];
            $saved = setDepositPromoConfig($pdo, true, $target);
            $label = formatBonusTargetLabel($saved['target_group']);

            tgPost($token, 'answerCallbackQuery', [
                'callback_query_id' => $cbId,
                'text'              => "Deposit promo enabled for {$label}",
                'show_alert'        => false,
            ]);

            if ($adminMsgId > 0) {
                tgPost($token, 'editMessageText', [
                    'chat_id'      => $adminChat,
                    'message_id'   => $adminMsgId,
                    'text'         => "✅ DEPOSIT PROMO ENABLED\n\n"
                                    . "• Target Group: {$label}\n"
                                    . "• Deposit 50+ ETB → +20.00 ETB bonus\n"
                                    . "• Deposit 100+ ETB → +30.00 ETB bonus",
                    'reply_markup' => [
                        'inline_keyboard' => [[
                            ['text' => '📢 Post Promo Once (2h)', 'callback_data' => 'post_dep_promo_now'],
                        ]],
                    ],
                ]);
            }

            http_response_code(200);
            echo "OK";
            exit;
        }

        if ($parsed['kind'] === 'dep_promo_enable_post') {
            $target = (string)$parsed['target'];
            $saved = setDepositPromoConfig($pdo, true, $target);
            $postRes = broadcastPromoToPlayers($pdo, $token, (string)$saved['target_group'], $webAppUrl);

            if (!empty($postRes['throttled'])) {
                $waitStr = (string)($postRes['wait_text'] ?? '2h');
                tgPost($token, 'answerCallbackQuery', [
                    'callback_query_id' => $cbId,
                    'text'              => "Promo enabled. Next post in {$waitStr}.",
                    'show_alert'        => true,
                ]);
                if ($adminMsgId > 0) {
                    tgPost($token, 'editMessageText', [
                        'chat_id'      => $adminChat,
                        'message_id'   => $adminMsgId,
                        'text'         => "✅ DEPOSIT PROMO ENABLED\n\n"
                                        . "• Target Group: {$postRes['group_label']}\n"
                                        . "• Deposit 50+ ETB → +20.00 ETB bonus\n"
                                        . "• Deposit 100+ ETB → +30.00 ETB bonus\n"
                                        . "• Post Cooldown: Already posted within 2 hours. Try again in {$waitStr}.",
                        'reply_markup' => ['inline_keyboard' => []],
                    ]);
                }
            } else {
                tgPost($token, 'answerCallbackQuery', [
                    'callback_query_id' => $cbId,
                    'text'              => "Enabled & sent to {$postRes['sent_count']} players",
                    'show_alert'        => false,
                ]);
                if ($adminMsgId > 0) {
                    tgPost($token, 'editMessageText', [
                        'chat_id'      => $adminChat,
                        'message_id'   => $adminMsgId,
                        'text'         => "✅ DEPOSIT PROMO ENABLED & POSTED ONCE (2h COOLDOWN)\n\n"
                                        . "• Target Group: {$postRes['group_label']}\n"
                                        . "• Deposit 50+ ETB → +20.00 ETB bonus\n"
                                        . "• Deposit 100+ ETB → +30.00 ETB bonus\n"
                                        . "• Delivered to: {$postRes['sent_count']} / {$postRes['total_matched']} players\n"
                                        . "• Next post allowed in: 2 hours",
                        'reply_markup' => ['inline_keyboard' => []],
                    ]);
                }
            }

            http_response_code(200);
            echo "OK";
            exit;
        }

        if ($parsed['kind'] === 'post_dep_promo_now') {
            $curPromo = getActiveDepositPromo($pdo);
            $targetGroup = !empty($curPromo['active']) ? (string)$curPromo['target_group'] : 'all';
            $postRes = broadcastPromoToPlayers($pdo, $token, $targetGroup, $webAppUrl);

            if (!empty($postRes['throttled'])) {
                $waitStr = (string)($postRes['wait_text'] ?? '2h');
                tgPost($token, 'answerCallbackQuery', [
                    'callback_query_id' => $cbId,
                    'text'              => "Already posted! Wait {$waitStr} (2h limit).",
                    'show_alert'        => true,
                ]);
                if ($adminMsgId > 0) {
                    tgPost($token, 'editMessageText', [
                        'chat_id'      => $adminChat,
                        'message_id'   => $adminMsgId,
                        'text'         => "⏳ PROMO POST COOLDOWN ACTIVE (1 POST PER 2 HOURS)\n\n"
                                        . "• Target Group: {$postRes['group_label']}\n"
                                        . "• Time Remaining: {$waitStr}",
                        'reply_markup' => ['inline_keyboard' => []],
                    ]);
                }
            } else {
                tgPost($token, 'answerCallbackQuery', [
                    'callback_query_id' => $cbId,
                    'text'              => "Posted once to {$postRes['sent_count']} players",
                    'show_alert'        => false,
                ]);
                if ($adminMsgId > 0) {
                    tgPost($token, 'editMessageText', [
                        'chat_id'      => $adminChat,
                        'message_id'   => $adminMsgId,
                        'text'         => "📢 DEPOSIT PROMO POSTED ONCE (2h COOLDOWN)\n\n"
                                        . "• Target Group: {$postRes['group_label']}\n"
                                        . "• Deposit 50+ ETB → +20.00 ETB bonus\n"
                                        . "• Deposit 100+ ETB → +30.00 ETB bonus\n"
                                        . "• Delivered to: {$postRes['sent_count']} / {$postRes['total_matched']} players\n"
                                        . "• Next post allowed in: 2 hours",
                        'reply_markup' => ['inline_keyboard' => []],
                    ]);
                }
            }

            http_response_code(200);
            echo "OK";
            exit;
        }

        if ($parsed['kind'] === 'dep_promo_off') {
            setDepositPromoConfig($pdo, false, 'all');

            tgPost($token, 'answerCallbackQuery', [
                'callback_query_id' => $cbId,
                'text'              => 'Deposit promo turned off',
                'show_alert'        => false,
            ]);

            if ($adminMsgId > 0) {
                tgPost($token, 'editMessageText', [
                    'chat_id'      => $adminChat,
                    'message_id'   => $adminMsgId,
                    'text'         => "⛔ DEPOSIT PROMO TURNED OFF",
                    'reply_markup' => ['inline_keyboard' => []],
                ]);
            }

            http_response_code(200);
            echo "OK";
            exit;
        }

        if ($parsed['kind'] === 'open_bonus_menu') {
            $target = (string)$parsed['target'];
            tgPost($token, 'answerCallbackQuery', [
                'callback_query_id' => $cbId,
                'text'              => 'Select target players for Bonus Alert',
                'show_alert'        => false,
            ]);

            if ($adminMsgId > 0) {
                if ($target === 'picker' || $target === 'all') {
                    tgPost($token, 'editMessageText', [
                        'chat_id'      => $adminChat,
                        'message_id'   => $adminMsgId,
                        'text'         => "SMART BINGO — Bonus Alert Criteria\n\n"
                                        . "Choose which registered players should receive the bonus alert & deposit:\n"
                                        . "• All Players: every registered player\n"
                                        . "• Newcomers: 0.00 balance & no prior rounds\n"
                                        . "• 0.00 Bal: players with 0.00 ETB balance",
                        'reply_markup' => buildBonusTargetPickerKeyboard(),
                    ]);
                } else {
                    tgPost($token, 'editMessageText', [
                        'chat_id'      => $adminChat,
                        'message_id'   => $adminMsgId,
                        'text'         => "SMART BINGO — Select Bonus Alert Amount\n\n"
                                        . "Criteria: " . formatBonusTargetLabel($target) . "\n"
                                        . "Tapping an amount notifies on Telegram first and deposits the bonus into balance:",
                        'reply_markup' => buildBonusAlertKeyboard($target),
                    ]);
                }
            }

            http_response_code(200);
            echo "OK";
            exit;
        }

        if ($parsed['kind'] === 'bonus_pick_amt') {
            $target = (string)$parsed['target'];
            $countPreview = count(resolveBonusTargetPlayerIds($pdo, $target));
            tgPost($token, 'answerCallbackQuery', [
                'callback_query_id' => $cbId,
                'text'              => "{$countPreview} player(s) matched",
                'show_alert'        => false,
            ]);

            if ($adminMsgId > 0) {
                tgPost($token, 'editMessageText', [
                    'chat_id'      => $adminChat,
                    'message_id'   => $adminMsgId,
                    'text'         => "SMART BINGO — Select Bonus Alert Amount\n\n"
                                    . "Criteria: " . formatBonusTargetLabel($target) . "\n"
                                    . "Matched Players: {$countPreview}\n"
                                    . "Tapping an amount notifies on Telegram first and deposits the bonus into balance:",
                    'reply_markup' => buildBonusAlertKeyboard($target),
                ]);
            }

            http_response_code(200);
            echo "OK";
            exit;
        }

        if ($parsed['kind'] === 'bonus_alert') {
            $bonusAmt = (float)$parsed['amount'];
            $target   = (string)$parsed['target'];
            $res = sendBonusAlertToTarget($pdo, $token, $target, $bonusAmt, 'Hello dear today we have a bonus');

            if (empty($res['ok'])) {
                throw new RuntimeException((string)($res['error'] ?? 'Bonus alert failed'));
            }

            $amtStr = number_format($bonusAmt, 2);
            if (($res['mode'] ?? 'single') === 'single') {
                $pid = (string)($res['player_id'] ?? $target);
                $newBalStr = number_format((float)($res['new_balance'] ?? 0), 2);
                $notifiedFlag = !empty($res['notified']) ? 'Yes' : 'Check user /start';
                $toastText = "Alerted & deposited +{$amtStr} ETB";
                $statusLine = "\n\n✅ BONUS ALERT SENT & DEPOSITED\n• Player ID: {$pid}\n• Deposited: +{$amtStr} ETB\n• New Balance: {$newBalStr} ETB\n• Notified: {$notifiedFlag}";
            } else {
                $criteriaLabel = (string)($res['criteria'] ?? formatBonusTargetLabel($target));
                $cCount = (int)($res['credited_count'] ?? 0);
                $nCount = (int)($res['notified_count'] ?? 0);
                $lastErr = trim((string)($res['last_error'] ?? ''));
                $errNote = ($cCount === 0 && $lastErr !== '') ? "\n• Detail: " . substr($lastErr, 0, 120) : '';
                $toastText = "Sent +{$amtStr} ETB to {$cCount} players";
                $statusLine = "\n\n✅ BONUS ALERT SENT & DEPOSITED (+{$amtStr} ETB)\n• Criteria: {$criteriaLabel}\n• Credited: {$cCount} players\n• Telegram Notified: {$nCount} players{$errNote}";
            }

            tgPost($token, 'answerCallbackQuery', [
                'callback_query_id' => $cbId,
                'text'              => $toastText,
                'show_alert'        => false,
            ]);

            if ($adminMsgId > 0) {
                tgPost($token, 'editMessageText', [
                    'chat_id'      => $adminChat,
                    'message_id'   => $adminMsgId,
                    'text'         => ($origText !== '' ? $origText : 'Bonus Alert') . $statusLine,
                    'reply_markup' => ['inline_keyboard' => []],
                ]);
            }

            http_response_code(200);
            echo "OK";
            exit;
        }

        $kind           = $parsed['kind'];
        $isApprove      = $parsed['is_approve'];
        $recId          = (int)$parsed['record_id'];
        $targetPlayerId = (string)$parsed['player_id'];
        $amount         = (float)$parsed['amount'];

        if ($kind === 'deposit') {
            $depTable = null;
            $depRow = null;
            if ($recId > 0) {
                foreach (['transactions', 'deposits'] as $tbl) {
                    if (tableExists($pdo, $tbl)) {
                        $dStmt = $pdo->prepare("SELECT id, telegram_id, amount, status FROM {$tbl} WHERE id = ? LIMIT 1");
                        $dStmt->execute([$recId]);
                        $found = $dStmt->fetch(PDO::FETCH_ASSOC);
                        if ($found) {
                            $depTable = $tbl;
                            $depRow = $found;
                            break;
                        }
                    }
                }
            }

            if ($depRow) {
                if ($targetPlayerId === '' && isset($depRow['telegram_id'])) {
                    $targetPlayerId = trim((string)$depRow['telegram_id']);
                }
                if ($amount <= 0 && isset($depRow['amount'])) {
                    $amount = round((float)$depRow['amount'], 2);
                }
                $curStatus = strtolower(trim((string)($depRow['status'] ?? 'pending')));
                if ($curStatus !== '' && $curStatus !== 'pending') {
                    tgPost($token, 'answerCallbackQuery', [
                        'callback_query_id' => $cbId,
                        'text'              => "Already {$curStatus}.",
                        'show_alert'        => true,
                    ]);
                    http_response_code(200);
                    echo "OK";
                    exit;
                }
            }

            if ($targetPlayerId === '' || $amount <= 0) {
                throw new RuntimeException("Missing player_id ({$targetPlayerId}) or amount ({$amount}) in {$data}");
            }

            clearAbortedPdo($pdo);
            $ts = nowTimestampText();

            if ($isApprove) {
                $pStmt = $pdo->prepare("SELECT telegram_id, balance, chat_id FROM players WHERE CAST(telegram_id AS TEXT) = ? LIMIT 1");
                $pStmt->execute([$targetPlayerId]);
                $pRow = $pStmt->fetch(PDO::FETCH_ASSOC);

                if (!$pRow) {
                    $pdo->prepare("INSERT INTO players (telegram_id, chat_id, first_name, balance, created_at, updated_at) VALUES (?, ?, 'Player', 0.00, ?, ?)")
                        ->execute([$targetPlayerId, $targetPlayerId, $ts, $ts]);
                    $before = 0.00;
                    $canonicalPlayerId = $targetPlayerId;
                } else {
                    $canonicalPlayerId = (string)$pRow['telegram_id'];
                    $before = round((float)($pRow['balance'] ?? 0.00), 2);
                    if (trim((string)($pRow['chat_id'] ?? '')) === '') {
                        $pdo->prepare("UPDATE players SET chat_id = ?, updated_at = ? WHERE CAST(telegram_id AS TEXT) = ?")
                            ->execute([$targetPlayerId, $ts, $canonicalPlayerId]);
                    }
                }

                $promoCfg = getActiveDepositPromo($pdo);
                $promoBonus = 0.00;
                if (!empty($promoCfg['active']) && isPlayerEligibleForPromo($pdo, $canonicalPlayerId, (string)$promoCfg['target_group'], $before)) {
                    $promoBonus = calculateTieredDepositBonus($amount);
                }

                $after = round($before + $amount, 2);
                $finalAfter = round($after + $promoBonus, 2);

                $pdo->prepare("UPDATE players SET balance = ?, updated_at = ? WHERE CAST(telegram_id AS TEXT) = ?")
                    ->execute([$finalAfter, $ts, $canonicalPlayerId]);

                if ($depTable !== null && $recId > 0) {
                    $pdo->prepare("UPDATE {$depTable} SET status = 'approved' WHERE id = ?")->execute([$recId]);
                }

                addLedger($pdo, $canonicalPlayerId, null, 'deposit', $amount, $before, $after, "Deposit approved by admin");
                if ($promoBonus > 0) {
                    addLedger(
                        $pdo,
                        $canonicalPlayerId,
                        null,
                        'deposit_promo_bonus',
                        $promoBonus,
                        $after,
                        $finalAfter,
                        "Deposit promo bonus (+" . number_format($promoBonus, 2) . " ETB on " . number_format($amount, 2) . " ETB deposit)"
                    );
                }

                $amtFormatted = rtrim(rtrim(number_format($amount, 2, '.', ''), '0'), '.');
                $promoSuffix  = $promoBonus > 0 ? " + Bonus +" . number_format($promoBonus, 2) . " ETB" : "";
                $toastText    = "Approved +{$amtFormatted} ETB{$promoSuffix} (Total: " . number_format($finalAfter, 2) . " ETB)";
                $statusLabel  = "\n\n✅ APPROVED (+{$amtFormatted} ETB{$promoSuffix}) | New Balance: " . number_format($finalAfter, 2) . " ETB";
                $playerNotify = "SMART BINGO — Deposit Approved\n\n"
                              . "Previous Balance: " . number_format($before, 2) . " ETB\n"
                              . "Credited Amount: +" . number_format($amount, 2) . " ETB\n"
                              . ($promoBonus > 0 ? "Deposit Promo Bonus: +" . number_format($promoBonus, 2) . " ETB\n" : "")
                              . "New Balance: " . number_format($finalAfter, 2) . " ETB";
            } else {
                if ($depTable !== null && $recId > 0) {
                    $pdo->prepare("UPDATE {$depTable} SET status = 'rejected' WHERE id = ?")->execute([$recId]);
                }

                $toastText    = "Rejected deposit";
                $statusLabel  = "\n\n❌ REJECTED by Admin";
                $playerNotify = "SMART BINGO — Deposit Rejected\n\n"
                              . "Your deposit request of " . number_format($amount, 2) . " ETB was rejected. Please verify your reference or contact support.";
            }
        } else {
            ensureWithdrawalsSchema($pdo);
            $hasWithdrawals = tableExists($pdo, 'withdrawals');
            $wdRow = null;
            if ($recId > 0 && $hasWithdrawals) {
                $wStmt = $pdo->prepare("SELECT id, telegram_id, amount, status FROM withdrawals WHERE id = ? LIMIT 1");
                $wStmt->execute([$recId]);
                $wdRow = $wStmt->fetch(PDO::FETCH_ASSOC);
                if ($wdRow) {
                    if ($targetPlayerId === '' && isset($wdRow['telegram_id'])) {
                        $targetPlayerId = trim((string)$wdRow['telegram_id']);
                    }
                    if ($amount <= 0 && isset($wdRow['amount'])) {
                        $amount = round((float)$wdRow['amount'], 2);
                    }
                    $curStatus = strtolower(trim((string)($wdRow['status'] ?? 'pending')));
                    if ($curStatus !== '' && $curStatus !== 'pending') {
                        tgPost($token, 'answerCallbackQuery', [
                            'callback_query_id' => $cbId,
                            'text'              => "Already {$curStatus}.",
                            'show_alert'        => true,
                        ]);
                        http_response_code(200);
                        echo "OK";
                        exit;
                    }
                }
            }

            if ($targetPlayerId === '' || $amount <= 0) {
                throw new RuntimeException("Missing withdrawal player_id ({$targetPlayerId}) or amount ({$amount}) in {$data}");
            }

            clearAbortedPdo($pdo);
            $ts = nowTimestampText();

            if ($isApprove) {
                if ($recId > 0 && $hasWithdrawals) {
                    $pdo->prepare("UPDATE withdrawals SET status = 'approved' WHERE id = ?")->execute([$recId]);
                }
                $bStmt = $pdo->prepare("SELECT balance FROM players WHERE CAST(telegram_id AS TEXT) = ? LIMIT 1");
                $bStmt->execute([$targetPlayerId]);
                $curBal = round((float)($bStmt->fetchColumn() ?: 0.0), 2);

                $toastText    = "Approved withdrawal";
                $statusLabel  = "\n\n✅ WITHDRAWAL APPROVED";
                $playerNotify = "SMART BINGO — Withdrawal Approved\n\n"
                              . "Approved Amount: " . number_format($amount, 2) . " ETB\n"
                              . "Current Balance: " . number_format($curBal, 2) . " ETB";
            } else {
                $bStmt = $pdo->prepare("SELECT balance FROM players WHERE CAST(telegram_id AS TEXT) = ? LIMIT 1");
                $bStmt->execute([$targetPlayerId]);
                $before = round((float)($bStmt->fetchColumn() ?: 0.0), 2);
                $after  = round($before + $amount, 2);

                $pdo->prepare("UPDATE players SET balance = ?, updated_at = ? WHERE CAST(telegram_id AS TEXT) = ?")
                    ->execute([$after, $ts, $targetPlayerId]);

                if ($recId > 0 && $hasWithdrawals) {
                    $pdo->prepare("UPDATE withdrawals SET status = 'rejected' WHERE id = ?")->execute([$recId]);
                }

                addLedger($pdo, $targetPlayerId, null, 'withdrawal_refund', $amount, $before, $after, "Withdrawal rejected & refunded");

                $amtFormatted = rtrim(rtrim(number_format($amount, 2, '.', ''), '0'), '.');
                $toastText    = "Rejected & refunded {$amtFormatted} ETB";
                $statusLabel  = "\n\n❌ WITHDRAWAL REJECTED & REFUNDED";
                $playerNotify = "SMART BINGO — Withdrawal Rejected\n\n"
                              . "Previous Balance: " . number_format($before, 2) . " ETB\n"
                              . "Refunded Amount: +" . number_format($amount, 2) . " ETB\n"
                              . "New Balance: " . number_format($after, 2) . " ETB";
            }
        }

        $notified = sendPlayerNotification($pdo, $token, $targetPlayerId, $playerNotify);

        tgPost($token, 'answerCallbackQuery', [
            'callback_query_id' => $cbId,
            'text'              => $notified ? "{$toastText} (Player notified)" : "{$toastText} (Check player chat)",
            'show_alert'        => false,
        ]);

        if ($adminMsgId > 0) {
            $baseAdminText = $origText !== '' ? $origText : 'Request';
            $deliveryNote  = $notified ? "\n📩 Player notified" : "\n⚠️ Could not DM player (ensure user sent /start to bot)";
            tgPost($token, 'editMessageText', [
                'chat_id'      => $adminChat,
                'message_id'   => $adminMsgId,
                'text'         => $baseAdminText . $statusLabel . $deliveryNote,
                'reply_markup' => ['inline_keyboard' => []],
            ]);
        }
    } catch (Throwable $e) {
        clearAbortedPdo($pdo);
        $msg = "L" . $e->getLine() . " " . $e->getMessage();
        error_log("Callback error ({$data}): " . $msg);
        tgPost($token, 'answerCallbackQuery', [
            'callback_query_id' => $cbId,
            'text'              => substr($msg, 0, 180),
            'show_alert'        => true,
        ]);
    }

    http_response_code(200);
    echo "OK";
    exit;
}

/* =========================================================================
- 2. HANDLE NORMAL MESSAGES, COMMANDS, REFERRALS & BOTTOM MENU BUTTONS
- ========================================================================= */
$message = $update['message'] ?? $update['edited_message'] ?? null;
if (!is_array($message)) {
    http_response_code(200);
    echo "NO_MESSAGE";
    exit;
}

$chatId    = isset($message['chat']['id']) ? (int)$message['chat']['id'] : 0;
$userId    = isset($message['from']['id']) ? (int)$message['from']['id'] : $chatId;
$text      = trim((string)($message['text'] ?? ''));
$firstName = trim((string)($message['from']['first_name'] ?? 'Player'));
$username  = trim((string)($message['from']['username'] ?? ''));

if ($chatId === 0 || $userId === 0) {
    http_response_code(200);
    echo "NO_CHAT_ID";
    exit;
}

$isAdmin = ($adminId !== '' && (string)$userId === $adminId);
$reply = null;
$parseMode = '';
$inlineMarkup = null;
$replyMarkup = buildMainMenuKeyboard($webAppUrl, $isAdmin);

try {
    $pdo = getBingoPdo();
    clearAbortedPdo($pdo);
    ensureReferralSchema($pdo);
    ensureDepositStateSchema($pdo);
    ensureDepositPromoSchema($pdo);
    ensureWithdrawalsSchema($pdo);
    $player = registerOrGetUser($userId, $chatId, $firstName, $username);
    clearAbortedPdo($pdo);
    $balanceNum = (float)($player['balance'] ?? 0.0);
    $balanceFormatted = number_format($balanceNum, 2);
    $invitesCount = (int)($player['invites_count'] ?? 0);

    $currentState = getUserBotState($pdo, (string)$userId);
    $isMenuCommand = in_array($text, [
        'Deposit', '/deposit', 'Withdraw', '/withdraw', 'My Balance', '/balance',
        'Share & Invite', '/invite', '/share', 'How to Play', '/help',
        'My ID', '/myid', 'Admin Stats', '/stats', '/menu', '/bonus'
    ], true) || preg_match('/^\/start\b/i', $text);

    if (str_starts_with($currentState, 'awaiting_deposit_msg:') && $text !== '' && !$isMenuCommand) {
        $methodKey = substr($currentState, strlen('awaiting_deposit_msg:'));
        $methodLabel = ($methodKey === 'bank') ? 'Bank Account (CBE)' : 'Telebirr';

        setUserBotState($pdo, (string)$userId, '');

        $amount = 0.00;
        if (preg_match('/(\d+(?:\.\d{1,2})?)\s*(?:ብር|ETB)\b/i', $text, $m)) {
            $amount = round((float)$m[1], 2);
        }

        $promoCfg = getActiveDepositPromo($pdo);
        $previewBonus = 0.00;
        if (!empty($promoCfg['active']) && isPlayerEligibleForPromo($pdo, (string)$userId, (string)$promoCfg['target_group'], $balanceNum)) {
            $previewBonus = calculateTieredDepositBonus($amount);
        }

        $txRef = 'TG-' . strtoupper(bin2hex(random_bytes(4)));
        $ts = nowTimestampText();

        $ins = $pdo->prepare("
            INSERT INTO transactions (telegram_id, tx_reference, amount, source, status, created_at, full_message)
            VALUES (?, ?, ?, ?, 'pending', ?, ?)
        ");
        $ins->execute([(string)$userId, $txRef, $amount, 'tg_deposit:' . $methodKey, $ts, $text]);
        $txId = (int)$pdo->lastInsertId();

        addLedger($pdo, (string)$userId, null, 'deposit_pending', $amount, $balanceNum, $balanceNum, "Pending {$methodLabel} deposit (#{$txId})");

        if ($adminId !== '') {
            $promoPreviewLine = $previewBonus > 0
                ? "• Eligible Promo Bonus: +" . number_format($previewBonus, 2) . " ETB\n"
                : "";
            $adminMsg = "SMART BINGO — Deposit for Approval (#{$txId})\n\n"
                      . "• Player: {$firstName} (ID: {$userId})\n"
                      . "• Method: {$methodLabel}\n"
                      . "• Amount: " . number_format($amount, 2) . " ETB\n"
                      . $promoPreviewLine
                      . "• Ref: {$txRef}\n"
                      . "• Message:\n{$text}";

            sendPlayerNotification($pdo, $token, $adminId, $adminMsg);
            tgPost($token, 'sendMessage', [
                'chat_id'      => $adminId,
                'text'         => "Approve or reject Deposit #{$txId} ({$methodLabel}, " . number_format($amount, 2) . " ETB) for {$firstName} ({$userId}):",
                'reply_markup' => [
                    'inline_keyboard' => [[
                        ['text' => '✅ Approve', 'callback_data' => "approve_tx:{$txId}"],
                        ['text' => '❌ Reject',  'callback_data' => "reject_tx:{$txId}"],
                    ]],
                ],
            ]);
        }

        $reply = "Your {$methodLabel} deposit message has been submitted for approval.\n"
               . "Reference: {$txRef}\n"
               . "We will notify you once approved.";
    } elseif (str_starts_with($currentState, 'awaiting_withdraw_msg:') && $text !== '' && !$isMenuCommand) {
        $methodKey = substr($currentState, strlen('awaiting_withdraw_msg:'));
        $methodLabel = ($methodKey === 'bank') ? 'Bank Account (CBE)' : 'Telebirr';

        $wdAmount = parseWithdrawAmountFromText($text);
        if ($wdAmount < 10.00) {
            $reply = "Please enter a valid withdrawal amount (Min: 10 ETB) along with your {$methodLabel} account/phone.\n"
                   . "Example: `0917461538 50`";
            $parseMode = 'Markdown';
        } else {
            ensureWithdrawalsSchema($pdo);
            clearAbortedPdo($pdo);
            $ts = nowTimestampText();

            $pLock = $pdo->prepare("SELECT balance FROM players WHERE CAST(telegram_id AS TEXT) = ? LIMIT 1");
            $pLock->execute([(string)$userId]);
            $rawBal = $pLock->fetchColumn();

            if ($rawBal === false) {
                $reply = "Player account not found. Please send /start first.";
            } else {
                $beforeBal = round((float)$rawBal, 2);
                if ($beforeBal < $wdAmount) {
                    $reply = "Insufficient balance for withdrawal.\n"
                           . "Requested: " . number_format($wdAmount, 2) . " ETB\n"
                           . "Available Balance: " . number_format($beforeBal, 2) . " ETB";
                } else {
                    $afterBal = round($beforeBal - $wdAmount, 2);

                    $deduct = $pdo->prepare("
                        UPDATE players
                        SET balance = balance - ?, updated_at = ?
                        WHERE CAST(telegram_id AS TEXT) = ? AND COALESCE(balance, 0) >= ?
                    ");
                    $deduct->execute([$wdAmount, $ts, (string)$userId, $wdAmount]);

                    if ($deduct->rowCount() < 1) {
                        $reply = "Insufficient balance for withdrawal.\n"
                               . "Requested: " . number_format($wdAmount, 2) . " ETB\n"
                               . "Available Balance: " . number_format($beforeBal, 2) . " ETB";
                    } else {
                        try {
                            $wdId = insertWithdrawalRecord($pdo, (string)$userId, $methodLabel, $text, $wdAmount, $ts);
                        } catch (Throwable $insErr) {
                            clearAbortedPdo($pdo);
                            try {
                                $pdo->prepare("UPDATE players SET balance = balance + ?, updated_at = ? WHERE CAST(telegram_id AS TEXT) = ?")
                                    ->execute([$wdAmount, nowTimestampText(), (string)$userId]);
                            } catch (Throwable $refundErr) {
                            }
                            throw $insErr;
                        }

                        addLedger($pdo, (string)$userId, null, 'withdrawal_pending', -$wdAmount, $beforeBal, $afterBal, "Pending {$methodLabel} withdrawal: {$text}");
                        setUserBotState($pdo, (string)$userId, '');

                        if ($adminId !== '') {
                            $adminMsg = "SMART BINGO — Withdrawal Request" . ($wdId > 0 ? " (#{$wdId})" : "") . "\n\n"
                                      . "• Player: {$firstName} (ID: {$userId})\n"
                                      . "• Method: {$methodLabel}\n"
                                      . "• Amount: " . number_format($wdAmount, 2) . " ETB\n"
                                      . "• Remaining Balance: " . number_format($afterBal, 2) . " ETB\n"
                                      . "• Details:\n{$text}";

                            sendPlayerNotification($pdo, $token, $adminId, $adminMsg);
                            tgPost($token, 'sendMessage', [
                                'chat_id'      => $adminId,
                                'text'         => "Approve or reject Withdrawal" . ($wdId > 0 ? " #{$wdId}" : "") . " ({$methodLabel}, " . number_format($wdAmount, 2) . " ETB) for {$firstName} ({$userId}):",
                                'reply_markup' => [
                                    'inline_keyboard' => [[
                                        ['text' => '✅ Approve', 'callback_data' => "approve_wd:{$wdId}:{$userId}:{$wdAmount}"],
                                        ['text' => '❌ Reject',  'callback_data' => "reject_wd:{$wdId}:{$userId}:{$wdAmount}"],
                                    ]],
                                ],
                            ]);
                        }

                        $reply = "Your {$methodLabel} withdrawal request of " . number_format($wdAmount, 2) . " ETB has been submitted for approval.\n"
                               . "Remaining Balance: " . number_format($afterBal, 2) . " ETB";
                    }
                }
            }
        }
    } elseif (preg_match('/^\/start(?:\s+(.+))?$/i', $text, $startMatch)) {
        $startPayload = trim((string)($startMatch[1] ?? ''));
        $refBonusNote = '';

        if ($startPayload !== '') {
            $cleanRef = preg_replace('/^ref[_-]?/i', '', $startPayload) ?? '';
            $cleanRef = preg_replace('/\D+/', '', $cleanRef) ?? '';
            if ($cleanRef !== '' && $cleanRef !== (string)$userId) {
                $bonusRes = applyJoinReferralBonus($pdo, $token, $adminId, (string)$userId, $firstName, $cleanRef, $referralBonusEtb);
                if (!empty($bonusRes['awarded']) && $referralBonusEtb > 0) {
                    $refBonusNote = "\n🎁 Join promotion active! Your inviter received +" . number_format($referralBonusEtb, 2) . " ETB.";
                }
            }
        }

        $inviteLink = "https://t.me/{$botUsername}?start=ref_{$userId}";
        $reply = "Welcome to SMART BINGO ({$firstName})!\n"
               . "Balance: {$balanceFormatted} ETB | Invites: {$invitesCount}\n"
               . "Your Invite Link: {$inviteLink}"
               . $refBonusNote . "\n\n"
               . "Tap Play Bingo below or use the menu buttons.";
    } elseif ($isAdmin && preg_match('/^\/invites(?:\s+(\d+))?$/i', $text, $invMatch)) {
        $lim = isset($invMatch[1]) ? (int)$invMatch[1] : 10;
        $reply = buildRecentInvitesText($pdo, $lim);
    } elseif ($isAdmin && preg_match('/^\/bonus(10|20|50)\s+([a-zA-Z0-9_-]+)(?:\s+(.+))?$/i', $text, $quickBonusMatch)) {
        $amt = (float)$quickBonusMatch[1];
        $targetSpec = (string)$quickBonusMatch[2];
        $note = trim((string)($quickBonusMatch[3] ?? 'Hello dear today we have a bonus'));

        $res = sendBonusAlertToTarget($pdo, $token, $targetSpec, $amt, $note);
        if (!empty($res['ok'])) {
            $amtStr = number_format($amt, 2);
            if (($res['mode'] ?? 'single') === 'single') {
                $pid = (string)($res['player_id'] ?? $targetSpec);
                $notifiedFlag = !empty($res['notified']) ? 'Yes' : 'Failed (check user /start)';
                $reply = "Bonus Alert Sent & Deposited!\n\n"
                       . "• Player ID: {$pid}\n"
                       . "• Bonus Deposited: +{$amtStr} ETB\n"
                       . "• New Balance: " . number_format((float)($res['new_balance'] ?? 0), 2) . " ETB\n"
                       . "• Telegram Notified: {$notifiedFlag}";
            } else {
                $criteriaLabel = (string)($res['criteria'] ?? formatBonusTargetLabel($targetSpec));
                $reply = "Bonus Alert Sent & Deposited!\n\n"
                       . "• Amount: +{$amtStr} ETB\n"
                       . "• Criteria: {$criteriaLabel}\n"
                       . "• Balance Deposited: {$res['credited_count']} players\n"
                       . "• Telegram Notified: {$res['notified_count']} players";
            }
        } else {
            $reply = "Could not send bonus alert: " . ($res['error'] ?? 'Unknown error');
        }
    } elseif ($isAdmin && preg_match('/^\/bonusmenu(?:\s+([a-zA-Z0-9_-]+))?$/i', $text, $menuBonusMatch)) {
        $rawTarget = trim((string)($menuBonusMatch[1] ?? ''));
        if ($rawTarget === '') {
            $reply = "SMART BINGO — Bonus Alert Criteria\n\n"
                   . "Choose which registered players should receive the bonus alert & deposit:";
            $inlineMarkup = buildBonusTargetPickerKeyboard();
        } else {
            $cleanTarget = normalizeBonusTarget($rawTarget);
            $matchedCount = count(resolveBonusTargetPlayerIds($pdo, $cleanTarget));
            $reply = "SMART BINGO — Select Bonus Alert Amount\n\n"
                   . "Criteria: " . formatBonusTargetLabel($cleanTarget) . "\n"
                   . "Matched Players: {$matchedCount}\n"
                   . "Tapping an amount notifies on Telegram first and deposits the bonus into balance:";
            $inlineMarkup = buildBonusAlertKeyboard($cleanTarget);
        }
    } else {
        switch ($text) {
            case '/menu':
                $reply = "SMART BINGO Menu ({$firstName})\n"
                       . "Balance: {$balanceFormatted} ETB\n"
                       . "Tap Play Bingo or choose a menu option below.";
                break;

            case '/bonus':
                if ($isAdmin) {
                    $reply = "SMART BINGO — Bonus Alert Criteria\n\n"
                           . "Choose which registered players should receive the bonus alert & deposit:";
                    $inlineMarkup = buildBonusTargetPickerKeyboard();
                } else {
                    $reply = "Bonus alerts are managed by the admin.";
                }
                break;

            case 'Share & Invite':
            case '/invite':
            case '/share':
                $inviteLink = "https://t.me/{$botUsername}?start=ref_{$userId}";
                $bonusLine = ($referralBonusEtb > 0)
                    ? "• Join Bonus: +" . number_format($referralBonusEtb, 2) . " ETB per valid friend who joins\n"
                    : "• Share your link with friends to join SMART BINGO together\n";
                $reply = "SMART BINGO — Join & Share Promotion\n\n"
                       . $bonusLine
                       . "• Your Total Invites: {$invitesCount}\n\n"
                       . "Forward or share this link with friends:\n{$inviteLink}";
                break;

            case 'My Balance':
            case '/balance':
                $reply = "SMART BINGO — Account Balance\n\n"
                       . "Player: {$firstName}\n"
                       . "Balance: {$balanceFormatted} ETB\n"
                       . "Invites: {$invitesCount}";
                break;

            case 'Deposit':
            case '/deposit':
                $promoCfg = getActiveDepositPromo($pdo);
                $promoNote = '';
                if (!empty($promoCfg['active']) && isPlayerEligibleForPromo($pdo, (string)$userId, (string)$promoCfg['target_group'], $balanceNum)) {
                    $promoNote = "\n🎁 Deposit Promo Active: Deposit 50+ ETB → +20 ETB bonus | 100+ ETB → +30 ETB bonus!\n";
                }
                $reply = "SMART BINGO — Deposit (Min: 10 ETB)\n"
                       . $promoNote . "\n"
                       . "Select your payment method below:";
                $inlineMarkup = buildDepositMethodKeyboard();
                break;

            case 'Withdraw':
            case '/withdraw':
                $reply = "SMART BINGO — Withdraw (Min: 10 ETB)\n\n"
                       . "Available Balance: {$balanceFormatted} ETB\n"
                       . "Select where your payout should be sent:";
                $inlineMarkup = buildWithdrawMethodKeyboard();
                break;

            case 'How to Play':
            case '/help':
                $reply = "SMART BINGO — Game Rules\n\n"
                       . "• Pick 1 to 3 cartelas during the lobby countdown.\n"
                       . "• All waiting players start together when the countdown ends.\n"
                       . "• Close any row, column, diagonal, or 4 corners to win; up to 2 players can share the 80% Derash pool equally.\n"
                       . "• Late joiners while a game is active wait for the next round.";
                break;

            case 'My ID':
            case '/myid':
                $inviteLink = "https://t.me/{$botUsername}?start=ref_{$userId}";
                $reply = "Telegram User ID: {$userId}\n"
                       . "Chat ID: {$chatId}\n"
                       . "Balance: {$balanceFormatted} ETB\n"
                       . "Invite Link: {$inviteLink}";
                break;

            case 'Admin Stats':
            case '/stats':
                if ($isAdmin) {
                    $pCount = (int)$pdo->query("SELECT COUNT(*) FROM players")->fetchColumn();
                    $rCount = (int)$pdo->query("SELECT COUNT(*) FROM rooms")->fetchColumn();
                    $refTotal = tableExists($pdo, 'referrals')
                        ? (int)$pdo->query("SELECT COUNT(*) FROM referrals")->fetchColumn()
                        : 0;
                    $wPending = tableExists($pdo, 'withdrawals')
                        ? (int)$pdo->query("SELECT COUNT(*) FROM withdrawals WHERE status = 'pending'")->fetchColumn()
                        : 0;
                    $curPromo = getActiveDepositPromo($pdo);
                    $promoStatus = $curPromo['active']
                        ? "ON (" . formatBonusTargetLabel($curPromo['target_group']) . ")"
                        : "OFF";

                    $reply = "SMART BINGO — Admin Overview\n\n"
                           . "• Registered Players: {$pCount}\n"
                           . "• Total Rooms: {$rCount}\n"
                           . "• Join Referrals: {$refTotal}\n"
                           . "• Pending Withdrawals: {$wPending}\n"
                           . "• Deposit Promo (50/100): {$promoStatus}\n\n"
                           . "Tap below to manage Bonus Alerts, Deposit Promo, or view Recent Invites, or use:\n"
                           . "• /invites [limit]\n"
                           . "• /bonusmenu [all|new|zero|<telegram_id>]\n"
                           . "• /bonus10 | /bonus20 | /bonus50 <all|new|zero|<telegram_id>> [note]";
                    $inlineMarkup = [
                        'inline_keyboard' => [
                            [
                                ['text' => '🎁 Bonus Alert Menu', 'callback_data' => 'open_bonus_menu:picker'],
                                ['text' => '📋 Recent Invites', 'callback_data' => 'admin_recent_invites'],
                            ],
                            [
                                ['text' => '💸 Deposit Promo (50/100)', 'callback_data' => 'open_dep_promo_menu'],
                                ['text' => '📢 Post Promo (2h)', 'callback_data' => 'post_dep_promo_now'],
                            ],
                        ],
                    ];
                } else {
                    $reply = "This menu is for admins only.";
                }
                break;
        }
    }
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO) {
        clearAbortedPdo($pdo);
    }
    $errDetail = "L" . $e->getLine() . ": " . $e->getMessage();
    error_log("Smart_Bingo_Bot DB error: " . $errDetail);
    $reply = $isAdmin
        ? "Database error: {$errDetail}"
        : "Temporary database error while processing your request. Please try again.";
}

if ($reply !== null) {
    $payload = [
        'chat_id'      => $chatId,
        'text'         => $reply,
        'reply_markup' => $inlineMarkup ?? $replyMarkup,
    ];
    if ($parseMode !== '') {
        $payload['parse_mode'] = $parseMode;
    }
    tgPost($token, 'sendMessage', $payload);
}

http_response_code(200);
echo "OK";
