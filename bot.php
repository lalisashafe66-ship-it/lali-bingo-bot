<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

header('Content-Type: text/plain; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo "OK - SmartBingoHub_bot webhook endpoint";
    exit;
}

$raw = file_get_contents('php://input');
$update = json_decode($raw ?: '', true);

if (!is_array($update)) {
    http_response_code(200);
    echo "INVALID_JSON";
    exit;
}

$token = trim((string)(getenv('TELEGRAM_BOT_TOKEN') ?: '9105830123:AAGh_IUoXNiUbLZX2f5BBUo7_woBuzn8zBU'));
$adminId = trim((string)(getenv('ADMIN_TELEGRAM_ID') ?: '730967613'));
$botUsername = 'SmartBingoHub_bot';
$webAppUrl = 'https://lali-bingo-bot.onrender.com/index.php';
$referralBonusEtb = round((float)(getenv('REFERRAL_BONUS_ETB') ?: '5.00'), 2);

if ($token === '') {
    error_log("SmartBingoHub_bot: TELEGRAM_BOT_TOKEN is empty");
    http_response_code(200);
    echo "MISSING_TOKEN";
    exit;
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

function tableExists(PDO $pdo, string $table): bool {
    $stmt = $pdo->prepare("SELECT name FROM sqlite_master WHERE type = 'table' AND name = ?");
    $stmt->execute([$table]);
    return (bool)$stmt->fetchColumn();
}

function ensureReferralSchema(PDO $pdo): void {
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
}

function addLedger(PDO $pdo, string $telegramId, ?int $roomId, string $type, float $amount, float $before, float $after, string $note): void {
    if (!tableExists($pdo, 'balance_ledger')) return;
    $stmt = $pdo->prepare("INSERT INTO balance_ledger (telegram_id, room_id, type, amount, balance_before, balance_after, note) VALUES (?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([$telegramId, $roomId, $type, $amount, $before, $after, $note]);
}

function getPlayerChatTarget(PDO $pdo, string $telegramId): string {
    $stmt = $pdo->prepare("SELECT chat_id FROM players WHERE telegram_id = ? LIMIT 1");
    $stmt->execute([$telegramId]);
    $chatId = trim((string)($stmt->fetchColumn() ?: ''));
    return $chatId !== '' ? $chatId : $telegramId;
}

function sendPlayerNotification(PDO $pdo, string $token, string $targetPlayerId, string $messageText): bool {
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
        $res = tgPost($token, 'sendMessage', [
            'chat_id' => $chatTarget,
            'text'    => $messageText,
        ]);
        if (!empty($res['ok'])) {
            return true;
        }
        error_log("SmartBingoHub_bot notify failed for chat {$chatTarget} (player {$targetPlayerId}): " . json_encode($res));
    }

    return false;
}

function buildBonusAlertKeyboard(string $targetSpec = 'all'): array {
    $cleanTarget = strtolower(trim($targetSpec));
    if ($cleanTarget !== 'all') {
        $cleanTarget = preg_replace('/\D+/', '', $cleanTarget) ?? 'all';
        if ($cleanTarget === '') {
            $cleanTarget = 'all';
        }
    }

    return [
        'inline_keyboard' => [
            [
                ['text' => '+10 ETB Bonus', 'callback_data' => "bonus_alert:10:{$cleanTarget}"],
                ['text' => '+20 ETB Bonus', 'callback_data' => "bonus_alert:20:{$cleanTarget}"],
                ['text' => '+50 ETB Bonus', 'callback_data' => "bonus_alert:50:{$cleanTarget}"],
            ],
        ],
    ];
}

/**
 * Notify the player on Telegram first, then deposit/credit the bonus amount
 * into their account balance and record it in balance_ledger.
 */
function notifyAndDepositBonus(PDO $pdo, string $token, string $targetPlayerId, float $amount, string $note = 'Admin Bonus Alert'): array {
    $targetPlayerId = preg_replace('/\D+/', '', $targetPlayerId) ?? '';
    $amount = round($amount, 2);

    if ($targetPlayerId === '' || $amount <= 0) {
        return ['ok' => false, 'error' => 'Invalid player ID or bonus amount'];
    }

    $stmt = $pdo->prepare("SELECT balance, first_name FROM players WHERE telegram_id = ? LIMIT 1");
    $stmt->execute([$targetPlayerId]);
    $existingRow = $stmt->fetch(PDO::FETCH_ASSOC);

    $playerName = $existingRow
        ? (trim((string)($existingRow['first_name'] ?? 'Player')) ?: 'Player')
        : 'Player';
    $projectedBefore = $existingRow ? round((float)($existingRow['balance'] ?? 0.00), 2) : 0.00;
    $projectedAfter  = round($projectedBefore + $amount, 2);
    $amtStr = number_format($amount, 2);
    $afterStr = number_format($projectedAfter, 2);

    // 1. Alert the player on Telegram first
    $alertMsg = "SMART BINGO — Bonus Alert!\n\n"
              . "Player: {$playerName}\n"
              . "Bonus Alert Amount: +{$amtStr} ETB\n"
              . "Reason: {$note}\n\n"
              . "Your +{$amtStr} ETB bonus is being deposited into your balance (New Balance: {$afterStr} ETB).";

    $notified = sendPlayerNotification($pdo, $token, $targetPlayerId, $alertMsg);

    // 2. Deposit/credit the bonus into player's balance inside a transaction
    $pdo->beginTransaction();
    try {
        $stmt->execute([$targetPlayerId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            $pdo->prepare("INSERT INTO players (telegram_id, chat_id, first_name, balance, invites_count) VALUES (?, ?, 'Player', 0.00, 0)")
                ->execute([$targetPlayerId, $targetPlayerId]);
            $before = 0.00;
        } else {
            $before = round((float)($row['balance'] ?? 0.00), 2);
        }

        $after = round($before + $amount, 2);
        $pdo->prepare("UPDATE players SET balance = ?, updated_at = CURRENT_TIMESTAMP WHERE telegram_id = ?")
            ->execute([$after, $targetPlayerId]);

        addLedger($pdo, $targetPlayerId, null, 'bonus_deposit', $amount, $before, $after, "{$note} (+{$amtStr} ETB bonus alert)");
        $pdo->commit();

        return [
            'ok'          => true,
            'player_id'   => $targetPlayerId,
            'bonus'       => $amount,
            'new_balance' => $after,
            'notified'    => $notified,
        ];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log("notifyAndDepositBonus error ({$targetPlayerId}): " . $e->getMessage());
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

function sendBonusAlertToTarget(PDO $pdo, string $token, string $targetSpec, float $amount, string $note = 'Admin Bonus Alert'): array {
    $allowedAmounts = [10.00, 20.00, 50.00];
    $amount = round($amount, 2);
    if (!in_array($amount, $allowedAmounts, true)) {
        return ['ok' => false, 'error' => 'Bonus alert amount must be 10, 20, or 50 ETB.'];
    }

    $targetSpec = strtolower(trim($targetSpec));
    if ($targetSpec === 'all') {
        $ids = $pdo->query("SELECT telegram_id FROM players")->fetchAll(PDO::FETCH_COLUMN, 0);
        if (!$ids) {
            return ['ok' => false, 'error' => 'No registered players found.'];
        }

        $successCount = 0;
        $notifiedCount = 0;
        foreach ($ids as $pid) {
            $res = notifyAndDepositBonus($pdo, $token, (string)$pid, $amount, $note);
            if (!empty($res['ok'])) {
                $successCount++;
                if (!empty($res['notified'])) {
                    $notifiedCount++;
                }
            }
        }

        return [
            'ok'             => true,
            'mode'           => 'all',
            'amount'         => $amount,
            'credited_count' => $successCount,
            'notified_count' => $notifiedCount,
        ];
    }

    $cleanId = preg_replace('/\D+/', '', $targetSpec) ?? '';
    if ($cleanId === '') {
        return ['ok' => false, 'error' => 'Provide a valid Telegram ID or "all".'];
    }

    $res = notifyAndDepositBonus($pdo, $token, $cleanId, $amount, $note);
    $res['mode'] = 'single';
    return $res;
}

function buildBonusHistoryText(PDO $pdo, string $telegramId, string $firstName, float $currentBalance): string {
    $balStr = number_format($currentBalance, 2);

    if (!tableExists($pdo, 'balance_ledger')) {
        return "SMART BINGO — Bonus Notifications\n\n"
             . "Player: {$firstName}\n"
             . "Current Balance: {$balStr} ETB\n"
             . "No bonus history available yet.";
    }

    $stmt = $pdo->prepare("
        SELECT amount, type, note, created_at
        FROM balance_ledger
        WHERE telegram_id = ? AND (type LIKE '%bonus%' OR amount > 0)
        ORDER BY id DESC
        LIMIT 5
    ");
    $stmt->execute([$telegramId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (!$rows) {
        return "SMART BINGO — Bonus Notifications\n\n"
             . "Player: {$firstName}\n"
             . "Current Balance: {$balStr} ETB\n\n"
             . "No bonuses received yet. When a bonus alert is sent to you, you will receive a notification here and it will be deposited into your balance.";
    }

    $lines = [
        "SMART BINGO — Bonus Notifications",
        "",
        "Player: {$firstName}",
        "Current Balance: {$balStr} ETB",
        "",
        "Recent Bonuses (Deposited):",
    ];

    foreach ($rows as $r) {
        $amt = number_format((float)($r['amount'] ?? 0), 2);
        $note = trim((string)($r['note'] ?? 'Bonus'));
        $date = trim((string)($r['created_at'] ?? ''));
        $lines[] = "• +{$amt} ETB — {$note}" . ($date !== '' ? " ({$date})" : '');
    }

    return implode("\n", $lines);
}

function applyJoinReferralBonus(PDO $pdo, string $token, string $newUserId, string $newUserName, string $referrerId, float $bonusAmount): array {
    $referrerId = preg_replace('/\D+/', '', $referrerId) ?? '';
    if ($referrerId === '' || $referrerId === $newUserId) {
        return ['awarded' => false, 'reason' => 'invalid_or_self'];
    }

    ensureReferralSchema($pdo);

    $pdo->beginTransaction();
    try {
        $chk = $pdo->prepare("SELECT id FROM referrals WHERE referred_id = ? LIMIT 1");
        $chk->execute([$newUserId]);
        if ($chk->fetchColumn()) {
            $pdo->rollBack();
            return ['awarded' => false, 'reason' => 'already_referred'];
        }

        $pNewStmt = $pdo->prepare("SELECT referred_by FROM players WHERE telegram_id = ? LIMIT 1");
        $pNewStmt->execute([$newUserId]);
        $existingRef = trim((string)($pNewStmt->fetchColumn() ?: ''));
        if ($existingRef !== '') {
            $pdo->rollBack();
            return ['awarded' => false, 'reason' => 'existing_referrer'];
        }

        $pRefStmt = $pdo->prepare("SELECT balance, invites_count FROM players WHERE telegram_id = ? LIMIT 1");
        $pRefStmt->execute([$referrerId]);
        $refRow = $pRefStmt->fetch(PDO::FETCH_ASSOC);
        if (!$refRow) {
            $pdo->prepare("INSERT INTO players (telegram_id, chat_id, first_name, balance, invites_count) VALUES (?, ?, 'Player', 0.00, 0)")
                ->execute([$referrerId, $referrerId]);
            $refBefore = 0.00;
            $refInvites = 0;
        } else {
            $refBefore = round((float)($refRow['balance'] ?? 0.00), 2);
            $refInvites = (int)($refRow['invites_count'] ?? 0);
        }

        $pdo->prepare("UPDATE players SET referred_by = ? WHERE telegram_id = ?")
            ->execute([$referrerId, $newUserId]);

        $actualBonus = ($bonusAmount > 0) ? $bonusAmount : 0.00;
        $refAfter = round($refBefore + $actualBonus, 2);
        $newInvites = $refInvites + 1;

        $pdo->prepare("UPDATE players SET balance = ?, invites_count = ? WHERE telegram_id = ?")
            ->execute([$refAfter, $newInvites, $referrerId]);

        $pdo->prepare("INSERT INTO referrals (referrer_id, referred_id, bonus_amount) VALUES (?, ?, ?)")
            ->execute([$referrerId, $newUserId, $actualBonus]);

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

        $pdo->commit();

        if ($actualBonus > 0) {
            $msg = "SMART BINGO — Join Referral Bonus!\n\n"
                 . "New Friend Joined: {$newUserName} (ID: {$newUserId})\n"
                 . "Bonus Credited: +" . number_format($actualBonus, 2) . " ETB\n"
                 . "Total Invites: {$newInvites}\n"
                 . "New Balance: " . number_format($refAfter, 2) . " ETB";
            sendPlayerNotification($pdo, $token, $referrerId, $msg);
        }

        return ['awarded' => true, 'bonus' => $actualBonus, 'new_balance' => $refAfter];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
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
            ['text' => 'Bonus Alerts'],
        ],
        [
            ['text' => 'How to Play'],
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
    if (preg_match('/^open_bonus_menu:([a-zA-Z0-9_-]+)$/i', trim($data), $openMatch)) {
        return [
            'kind'   => 'open_bonus_menu',
            'target' => strtolower((string)$openMatch[1]),
        ];
    }

    // Format for 10/20/50 bonus alert buttons: "bonus_alert:10:123456789" or "bonus_alert:20:all"
    if (preg_match('/^bonus_alert:(10|20|50):([a-zA-Z0-9_-]+)$/i', trim($data), $bMatch)) {
        return [
            'kind'       => 'bonus_alert',
            'amount'     => round((float)$bMatch[1], 2),
            'target'     => strtolower((string)$bMatch[2]),
        ];
    }

    $lower = strtolower(trim($data));
    if ($lower === '') return null;

    $isApprove = str_contains($lower, 'approve') || str_starts_with($lower, 'app');
    $isReject  = str_contains($lower, 'reject')  || str_starts_with($lower, 'rej');
    if (!$isApprove && !$isReject) return null;

    $isWithdraw = str_contains($lower, 'wd') || str_contains($lower, 'withdraw');
    $kind = $isWithdraw ? 'withdrawal' : 'deposit';

    $tokens = preg_split('/[:|_ -]+/', trim($data)) ?: [];
    $recordId = 0;
    $playerId = '';
    $amount = 0.0;

    if (str_contains($data, ':') && count($tokens) >= 4) {
        $recordId = (int)$tokens[1];
        $playerId = preg_replace('/\D+/', '', (string)$tokens[2]) ?? '';
        $amount   = round((float)$tokens[3], 2);
    } else {
        if (str_contains($data, ':') && count($tokens) >= 2 && ctype_digit((string)$tokens[1])) {
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
- 1. HANDLE INLINE BUTTON CLICKS (BONUS ALERTS, DEPOSIT & WITHDRAWAL)
- ========================================================================= */
if (isset($update['callback_query']) && is_array($update['callback_query'])) {
    $cb         = $update['callback_query'];
    $cbId       = (string)($cb['id'] ?? '');
    $fromId     = (string)($cb['from']['id'] ?? '');
    $data       = (string)($cb['data'] ?? '');
    $adminChat  = (string)($cb['message']['chat']['id'] ?? $adminId);
    $adminMsgId = isset($cb['message']['message_id']) ? (int)$cb['message']['message_id'] : 0;
    $origText   = (string)($cb['message']['text'] ?? '');

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
        if ($parsed === null) {
            throw new RuntimeException('Unrecognized callback action: ' . $data);
        }

        if ($parsed['kind'] === 'open_bonus_menu') {
            $target = (string)$parsed['target'];
            $targetLabel = ($target === 'all') ? 'ALL Players' : "Player ID {$target}";
            tgPost($token, 'answerCallbackQuery', [
                'callback_query_id' => $cbId,
                'text'              => 'Select 10, 20, or 50 ETB bonus alert',
                'show_alert'        => false,
            ]);

            if ($adminMsgId > 0) {
                tgPost($token, 'editMessageText', [
                    'chat_id'      => $adminChat,
                    'message_id'   => $adminMsgId,
                    'text'         => "SMART BINGO — Select Bonus Alert Amount\n\n"
                                    . "Target: {$targetLabel}\n"
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
            $res = sendBonusAlertToTarget($pdo, $token, $target, $bonusAmt, 'Admin Bonus Alert');

            if (empty($res['ok'])) {
                throw new RuntimeException((string)($res['error'] ?? 'Bonus alert failed'));
            }

            $amtStr = number_format($bonusAmt, 2);
            if (($res['mode'] ?? 'single') === 'all') {
                $cCount = (int)($res['credited_count'] ?? 0);
                $nCount = (int)($res['notified_count'] ?? 0);
                $toastText = "Sent +{$amtStr} ETB to {$cCount} players";
                $statusLine = "\n\n✅ BONUS ALERT SENT & DEPOSITED (+{$amtStr} ETB to ALL)\n• Credited: {$cCount} players\n• Telegram Notified: {$nCount} players";
            } else {
                $pid = (string)($res['player_id'] ?? $target);
                $newBalStr = number_format((float)($res['new_balance'] ?? 0), 2);
                $notifiedFlag = !empty($res['notified']) ? 'Yes' : 'Check user /start';
                $toastText = "Alerted & deposited +{$amtStr} ETB";
                $statusLine = "\n\n✅ BONUS ALERT SENT & DEPOSITED\n• Player ID: {$pid}\n• Deposited: +{$amtStr} ETB\n• New Balance: {$newBalStr} ETB\n• Notified: {$notifiedFlag}";
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

        $pdo->beginTransaction();

        if ($kind === 'deposit') {
            $depTable = null;
            $depRow = null;
            if ($recId > 0) {
                foreach (['transactions', 'deposits'] as $tbl) {
                    if (tableExists($pdo, $tbl)) {
                        $dStmt = $pdo->prepare("SELECT * FROM {$tbl} WHERE id = ? LIMIT 1");
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
                    $targetPlayerId = (string)$depRow['telegram_id'];
                }
                if ($amount <= 0 && isset($depRow['amount'])) {
                    $amount = round((float)$depRow['amount'], 2);
                }
                if (isset($depRow['status']) && $depRow['status'] !== 'pending') {
                    $pdo->rollBack();
                    tgPost($token, 'answerCallbackQuery', [
                        'callback_query_id' => $cbId,
                        'text'              => "Already {$depRow['status']}.",
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

            if ($isApprove) {
                $pStmt = $pdo->prepare("SELECT balance, chat_id FROM players WHERE telegram_id = ? LIMIT 1");
                $pStmt->execute([$targetPlayerId]);
                $pRow = $pStmt->fetch(PDO::FETCH_ASSOC);

                if (!$pRow) {
                    $pdo->prepare("INSERT INTO players (telegram_id, chat_id, first_name, balance) VALUES (?, ?, 'Player', 0.0)")
                        ->execute([$targetPlayerId, $targetPlayerId]);
                    $before = 0.0;
                } else {
                    $before = round((float)$pRow['balance'], 2);
                    if (trim((string)($pRow['chat_id'] ?? '')) === '') {
                        $pdo->prepare("UPDATE players SET chat_id = ? WHERE telegram_id = ?")
                            ->execute([$targetPlayerId, $targetPlayerId]);
                    }
                }

                $after = round($before + $amount, 2);
                $pdo->prepare("UPDATE players SET balance = ? WHERE telegram_id = ?")
                    ->execute([$after, $targetPlayerId]);

                if ($depTable !== null && $recId > 0) {
                    $pdo->prepare("UPDATE {$depTable} SET status = 'approved' WHERE id = ?")->execute([$recId]);
                }

                addLedger($pdo, $targetPlayerId, null, 'deposit', $amount, $before, $after, "Deposit approved by admin");
                $amtFormatted = rtrim(rtrim(number_format($amount, 2, '.', ''), '0'), '.');
                $toastText    = "Approved +{$amtFormatted} ETB (Total: " . number_format($after, 2) . " ETB)";
                $statusLabel  = "\n\n✅ APPROVED (+{$amtFormatted} ETB) | New Balance: " . number_format($after, 2) . " ETB";
                $playerNotify = "SMART BINGO — Deposit Approved\n\n"
                              . "Previous Balance: " . number_format($before, 2) . " ETB\n"
                              . "Credited Amount: +" . number_format($amount, 2) . " ETB\n"
                              . "New Balance: " . number_format($after, 2) . " ETB";
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
            if ($recId > 0 && tableExists($pdo, 'withdrawals')) {
                $wStmt = $pdo->prepare("SELECT * FROM withdrawals WHERE id = ? LIMIT 1");
                $wStmt->execute([$recId]);
                $wdRow = $wStmt->fetch(PDO::FETCH_ASSOC);
                if ($wdRow) {
                    if ($targetPlayerId === '' && isset($wdRow['telegram_id'])) {
                        $targetPlayerId = (string)$wdRow['telegram_id'];
                    }
                    if ($amount <= 0 && isset($wdRow['amount'])) {
                        $amount = round((float)$wdRow['amount'], 2);
                    }
                    if (isset($wdRow['status']) && $wdRow['status'] !== 'pending') {
                        $pdo->rollBack();
                        tgPost($token, 'answerCallbackQuery', [
                            'callback_query_id' => $cbId,
                            'text'              => "Already {$wdRow['status']}.",
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

            if ($isApprove) {
                if ($recId > 0 && tableExists($pdo, 'withdrawals')) {
                    $pdo->prepare("UPDATE withdrawals SET status = 'approved' WHERE id = ?")->execute([$recId]);
                }
                $bStmt = $pdo->prepare("SELECT balance FROM players WHERE telegram_id = ? LIMIT 1");
                $bStmt->execute([$targetPlayerId]);
                $curBal = round((float)($bStmt->fetchColumn() ?: 0.0), 2);

                $toastText    = "Approved withdrawal";
                $statusLabel  = "\n\n✅ WITHDRAWAL APPROVED";
                $playerNotify = "SMART BINGO — Withdrawal Approved\n\n"
                              . "Approved Amount: " . number_format($amount, 2) . " ETB\n"
                              . "Current Balance: " . number_format($curBal, 2) . " ETB";
            } else {
                $bStmt = $pdo->prepare("SELECT balance FROM players WHERE telegram_id = ? LIMIT 1");
                $bStmt->execute([$targetPlayerId]);
                $before = round((float)($bStmt->fetchColumn() ?: 0.0), 2);
                $after  = round($before + $amount, 2);

                $pdo->prepare("UPDATE players SET balance = ? WHERE telegram_id = ?")
                    ->execute([$after, $targetPlayerId]);

                if ($recId > 0 && tableExists($pdo, 'withdrawals')) {
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

        $pdo->commit();

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
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log("Callback error ({$data}): " . $e->getMessage());
        tgPost($token, 'answerCallbackQuery', [
            'callback_query_id' => $cbId,
            'text'              => 'Callback error: ' . substr($e->getMessage(), 0, 140),
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
    ensureReferralSchema($pdo);
    $player = registerOrGetUser($userId, $chatId, $firstName, $username);
    $balanceNum = (float)($player['balance'] ?? 0.0);
    $balanceFormatted = number_format($balanceNum, 2);
    $invitesCount = (int)($player['invites_count'] ?? 0);

    if (preg_match('/^\/start(?:\s+(.+))?$/i', $text, $startMatch)) {
        $startPayload = trim((string)($startMatch[1] ?? ''));
        $refBonusNote = '';

        if ($startPayload !== '') {
            $cleanRef = preg_replace('/^ref[_-]?/i', '', $startPayload) ?? '';
            $cleanRef = preg_replace('/\D+/', '', $cleanRef) ?? '';
            if ($cleanRef !== '' && $cleanRef !== (string)$userId) {
                $bonusRes = applyJoinReferralBonus($pdo, $token, (string)$userId, $firstName, $cleanRef, $referralBonusEtb);
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
    } elseif ($isAdmin && preg_match('/^\/bonus(10|20|50)\s+([a-zA-Z0-9_-]+)(?:\s+(.+))?$/i', $text, $quickBonusMatch)) {
        $amt = (float)$quickBonusMatch[1];
        $targetSpec = (string)$quickBonusMatch[2];
        $note = trim((string)($quickBonusMatch[3] ?? 'Admin Bonus Alert'));

        $res = sendBonusAlertToTarget($pdo, $token, $targetSpec, $amt, $note);
        if (!empty($res['ok'])) {
            $amtStr = number_format($amt, 2);
            if (($res['mode'] ?? 'single') === 'all') {
                $reply = "Bonus Alert Sent & Deposited!\n\n"
                       . "• Amount: +{$amtStr} ETB\n"
                       . "• Target: ALL Players\n"
                       . "• Balance Deposited: {$res['credited_count']} players\n"
                       . "• Telegram Notified: {$res['notified_count']} players";
            } else {
                $pid = (string)($res['player_id'] ?? $targetSpec);
                $notifiedFlag = !empty($res['notified']) ? 'Yes' : 'Failed (check user /start)';
                $reply = "Bonus Alert Sent & Deposited!\n\n"
                       . "• Player ID: {$pid}\n"
                       . "• Bonus Deposited: +{$amtStr} ETB\n"
                       . "• New Balance: " . number_format((float)($res['new_balance'] ?? 0), 2) . " ETB\n"
                       . "• Telegram Notified: {$notifiedFlag}";
            }
        } else {
            $reply = "Could not send bonus alert: " . ($res['error'] ?? 'Unknown error');
        }
    } elseif ($isAdmin && preg_match('/^\/bonusmenu(?:\s+([a-zA-Z0-9_-]+))?$/i', $text, $menuBonusMatch)) {
        $targetSpec = strtolower(trim((string)($menuBonusMatch[1] ?? 'all')));
        $targetLabel = ($targetSpec === 'all') ? 'ALL Players' : "Player ID {$targetSpec}";
        $reply = "SMART BINGO — Select Bonus Alert Amount\n\n"
               . "Target: {$targetLabel}\n"
               . "Tapping an amount notifies on Telegram first and deposits the bonus into balance:";
        $inlineMarkup = buildBonusAlertKeyboard($targetSpec);
    } else {
        switch ($text) {
            case '/menu':
                $reply = "SMART BINGO Menu ({$firstName})\n"
                       . "Balance: {$balanceFormatted} ETB\n"
                       . "Tap Play Bingo or choose a menu option below.";
                break;

            case 'Bonus Alerts':
            case '/bonus':
                $reply = buildBonusHistoryText($pdo, (string)$userId, $firstName, $balanceNum);
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
                $parseMode = 'Markdown';
                $reply = "*SMART BINGO — Deposit Accounts / Herrega Kaffaltii / የክፍያ ሂሳቦች*\n\n"
                       . "*🏦 CBE Bank: 1000171689116 (LALISA SHAFE)*\n"
                       . "*📱 Telebirr: 0917461538*\n"
                       . "*💵 Minimum / Xiqqaa / ዝቅተኛ: 10 ETB*\n\n"
                       . "*🇪🇹 [Afaan Oromoo]*\n"
                       . "*Maallaqa (yoo xiqqaate 10 ETB/minumum) herrega armaan olii keessaa tokkotti ergaa. Erga ergitanii booda Play Bingo -> Wallet -> Deposit tuqaa, hamma birrii galchaa, akkasumas ergaa SMS/ragaa kaffaltii guutuu maxxansaa.*\n\n"
                       . "*🇪🇹 [አማርኛ]*\n"
                       . "*ቢያንስ 10 ብር ከላይ ባሉት ሂሳቦች በአንዱ ያስተላልፉ። ከላኩ በኋላ Play Bingo -> Wallet -> Deposit ይንኩ፣ የብር መጠን ያስገቡ እና ሙሉ የክፍያ SMS/ደረሰኝ መልዕክት ይለጥፉ。*\n\n"
                       . "*🇬🇧 [English]*\n"
                       . "*Transfer your deposit (minimum 10 ETB) to one of the above account (CBE ACCOUNT/TELEBIRR). Then tap Play Bingo -> Wallet -> Deposit, enter your ETB amount, and paste your full payment SMS or receipt message for admin approval.*";
                break;

            case 'Withdraw':
            case '/withdraw':
                $reply = "SMART BINGO — Withdraw\n\n"
                       . "Available Balance: {$balanceFormatted} ETB\n"
                       . "Open Play Bingo -> Wallet -> Withdraw to submit your Telebirr or CBE payout request.";
                break;

            case 'How to Play':
            case '/help':
                $reply = "SMART BINGO — Game Rules\n\n"
                       . "• Pick 1 to 3 cartelas during the lobby countdown.\n"
                       . "• All waiting players start together when the countdown ends.\n"
                       . "• Up to 2 players can win together and split the 80% Derash pool equally.\n"
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
                    $reply = "SMART BINGO — Admin Overview\n\n"
                           . "• Registered Players: {$pCount}\n"
                           . "• Total Rooms: {$rCount}\n"
                           . "• Join Referrals: {$refTotal}\n"
                           . "• Pending Withdrawals: {$wPending}\n\n"
                           . "Tap below to open 10 / 20 / 50 ETB Bonus Alerts for all players, or use:\n"
                           . "• /bonusmenu <telegram_id or all>\n"
                           . "• /bonus10 | /bonus20 | /bonus50 <telegram_id or all> [note]";
                    $inlineMarkup = [
                        'inline_keyboard' => [
                            [
                                ['text' => '🎁 Bonus Alert Menu (ALL)', 'callback_data' => 'open_bonus_menu:all'],
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
    error_log("SmartBingoHub_bot DB error: " . $e->getMessage());
    $reply = "Temporary database error while saving your profile. Please try again.";
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
