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

/**
 * Parses callback_data across formats like:
 * - dep_approve:<id>:<telegram_id>:<amount>
 * - approve_deposit_<telegram_id>_<amount>
 * - dep_approve_<id>_<telegram_id>_<amount>
 * - wd_approve:<id>:<telegram_id>:<amount> / approve_wd_<telegram_id>_<amount>
 */
function parseAdminCallback(string $data, string $fallbackText = ''): ?array {
    $lower = strtolower(trim($data));
    if ($lower === '') return null;

    $isApprove = str_contains($lower, 'approve') || str_starts_with($lower, 'app');
    $isReject  = str_contains($lower, 'reject')  || str_starts_with($lower, 'rej');
    if (!$isApprove && !$isReject) return null;

    $isWithdraw = str_contains($lower, 'wd') || str_contains($lower, 'withdraw');
    $kind = $isWithdraw ? 'withdrawal' : 'deposit';

    $tokens = preg_split('/[:|_ -]+/', trim($data)) ?: [];
    $nums = [];
    $recordId = 0;
    $playerId = '';
    $amount = 0.0;

    // Colon format: kind_action:id:telegram_id:amount
    if (str_contains($data, ':') && count($tokens) >= 4) {
        $recordId = (int)$tokens[1];
        $playerId = preg_replace('/\D+/', '', (string)$tokens[2]) ?? '';
        $amount   = round((float)$tokens[3], 2);
    } else {
        foreach ($tokens as $tok) {
            if (is_numeric($tok)) {
                $nums[] = $tok;
            }
        }
        // Look for a Telegram user ID (typically 6+ digits) and an amount
        foreach ($nums as $n) {
            if (strlen((string)$n) >= 6 && $playerId === '') {
                $playerId = (string)$n;
            } elseif ($amount <= 0 && (float)$n > 0) {
                $amount = round((float)$n, 2);
            }
        }
        if ($amount <= 0 && $fallbackText !== '') {
            if (preg_match('/(\d+(?:\.\d{1,2})?)\s*ETB/i', $fallbackText, $m)) {
                $amount = round((float)$m[1], 2);
            }
        }
        if ($playerId === '' && $fallbackText !== '') {
            if (preg_match('/\((\d{5,})\)/', $fallbackText, $m) || preg_match('/ID[:\s]*(\d{5,})/i', $fallbackText, $m)) {
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
 * 1. HANDLE INLINE BUTTON CLICKS (DEPOSIT & WITHDRAWAL APPROVE / REJECT)
 * ========================================================================= */
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
            'text'              => 'Only the admin can approve or reject requests.',
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

        $kind           = $parsed['kind'];
        $isApprove      = $parsed['is_approve'];
        $recId          = (int)$parsed['record_id'];
        $targetPlayerId = (string)$parsed['player_id'];
        $amount         = (float)$parsed['amount'];

        $pdo->beginTransaction();

        if ($kind === 'deposit') {
            if ($recId > 0 && tableExists($pdo, 'deposits')) {
                $dStmt = $pdo->prepare("SELECT * FROM deposits WHERE id = ? LIMIT 1");
                $dStmt->execute([$recId]);
                $depRow = $dStmt->fetch(PDO::FETCH_ASSOC);
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
            }

            if ($targetPlayerId === '' || $amount <= 0) {
                throw new RuntimeException("Missing player_id ({$targetPlayerId}) or amount ({$amount}) in {$data}");
            }

            if ($isApprove) {
                $pStmt = $pdo->prepare("SELECT balance FROM players WHERE telegram_id = ? LIMIT 1");
                $pStmt->execute([$targetPlayerId]);
                $pRow = $pStmt->fetch(PDO::FETCH_ASSOC);

                if (!$pRow) {
                    $pdo->prepare("INSERT INTO players (telegram_id, chat_id, first_name, balance) VALUES (?, ?, 'Player', 0.0)")
                        ->execute([$targetPlayerId, $targetPlayerId]);
                    $before = 0.0;
                } else {
                    $before = (float)$pRow['balance'];
                }

                $after = round($before + $amount, 2);
                $pdo->prepare("UPDATE players SET balance = ?, updated_at = CURRENT_TIMESTAMP WHERE telegram_id = ?")
                    ->execute([$after, $targetPlayerId]);

                if ($recId > 0 && tableExists($pdo, 'deposits')) {
                    $pdo->prepare("UPDATE deposits SET status = 'approved' WHERE id = ?")->execute([$recId]);
                }

                addLedger($pdo, $targetPlayerId, null, 'deposit', $amount, $before, $after, "Deposit approved by admin");
                $toastText    = "Approved +{$amount} ETB";
                $statusLabel  = "\n\n✅ <b>APPROVED</b> (+{$amount} ETB)";
                $playerNotify = "✅ <b>Deposit Approved!</b>\n\n"
                              . "Amount Credited: <b>+" . number_format($amount, 2) . " ETB</b>\n"
                              . "New Balance: <b>" . number_format($after, 2) . " ETB</b>";
            } else {
                if ($recId > 0 && tableExists($pdo, 'deposits')) {
                    $pdo->prepare("UPDATE deposits SET status = 'rejected' WHERE id = ?")->execute([$recId]);
                }
                $toastText    = "Rejected deposit";
                $statusLabel  = "\n\n❌ <b>REJECTED</b> by Admin";
                $playerNotify = "❌ <b>Deposit Rejected</b>\n\n"
                              . "Your deposit request of <b>" . number_format($amount, 2) . " ETB</b> was rejected.";
            }
        } else {
            // Withdrawal
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
                $curBal = (float)($bStmt->fetchColumn() ?: 0.0);

                $toastText    = "Approved withdrawal";
                $statusLabel  = "\n\n✅ <b>WITHDRAWAL APPROVED</b>";
                $playerNotify = "✅ <b>Withdrawal Approved!</b>\n\n"
                              . "Amount: <b>" . number_format($amount, 2) . " ETB</b> has been approved.\n"
                              . "Current Balance: <b>" . number_format($curBal, 2) . " ETB</b>";
            } else {
                $bStmt = $pdo->prepare("SELECT balance FROM players WHERE telegram_id = ? LIMIT 1");
                $bStmt->execute([$targetPlayerId]);
                $before = (float)($bStmt->fetchColumn() ?: 0.0);
                $after  = round($before + $amount, 2);

                $pdo->prepare("UPDATE players SET balance = ?, updated_at = CURRENT_TIMESTAMP WHERE telegram_id = ?")
                    ->execute([$after, $targetPlayerId]);

                if ($recId > 0 && tableExists($pdo, 'withdrawals')) {
                    $pdo->prepare("UPDATE withdrawals SET status = 'rejected' WHERE id = ?")->execute([$recId]);
                }

                addLedger($pdo, $targetPlayerId, null, 'withdrawal_refund', $amount, $before, $after, "Withdrawal rejected & refunded");
                $toastText    = "Rejected & refunded {$amount} ETB";
                $statusLabel  = "\n\n❌ <b>WITHDRAWAL REJECTED & REFUNDED</b>";
                $playerNotify = "❌ <b>Withdrawal Rejected</b>\n\n"
                              . "Your withdrawal of <b>" . number_format($amount, 2) . " ETB</b> was rejected and refunded.\n"
                              . "Current Balance: <b>" . number_format($after, 2) . " ETB</b>";
            }
        }

        $pdo->commit();

        tgPost($token, 'answerCallbackQuery', [
            'callback_query_id' => $cbId,
            'text'              => $toastText,
            'show_alert'        => false,
        ]);

        if ($adminMsgId > 0) {
            tgPost($token, 'editMessageText', [
                'chat_id'      => $adminChat,
                'message_id'   => $adminMsgId,
                'text'         => ($origText !== '' ? $origText : 'Request') . $statusLabel,
                'parse_mode'   => 'HTML',
                'reply_markup' => ['inline_keyboard' => []],
            ]);
        }

        if ($playerNotify !== '' && $targetPlayerId !== '') {
            $playerChat = getPlayerChatTarget($pdo, $targetPlayerId);
            tgPost($token, 'sendMessage', [
                'chat_id'    => $playerChat,
                'text'       => $playerNotify,
                'parse_mode' => 'HTML',
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
 * 2. HANDLE NORMAL MESSAGES (/start, /myid)
 * ========================================================================= */
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

$reply = null;
$replyMarkup = null;
$webAppUrl = 'https://lali-bingo-bot.onrender.com/index.php';

try {
    $player = registerOrGetUser($userId, $chatId, $firstName, $username);
    $balanceFormatted = number_format((float)($player['balance'] ?? 0.0), 2);

    if ($text === '/start') {
        $reply = "Welcome to SMART BINGO ({$firstName})!\n"
               . "Balance: {$balanceFormatted} ETB\n"
               . "Tap below to open the Bingo WebApp or send /myid.";

        $replyMarkup = [
            'inline_keyboard' => [
                [
                    [
                        'text'    => 'Play SMART BINGO',
                        'web_app' => ['url' => $webAppUrl],
                    ],
                ],
            ],
        ];
    } elseif ($text === '/myid') {
        $reply = "Telegram User ID: {$userId}\n"
               . "Chat ID: {$chatId}\n"
               . "Balance: {$balanceFormatted} ETB";
    }
} catch (Throwable $e) {
    error_log("SmartBingoHub_bot DB error: " . $e->getMessage());
    $reply = "Temporary database error while saving your profile. Please try again.";
}

if ($reply !== null) {
    $payloadData = [
        'chat_id' => $chatId,
        'text'    => $reply,
    ];
    if ($replyMarkup !== null) {
        $payloadData['reply_markup'] = $replyMarkup;
    }
    tgPost($token, 'sendMessage', $payloadData);
}

http_response_code(200);
echo "OK";
