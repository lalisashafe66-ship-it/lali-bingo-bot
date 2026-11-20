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
    $recordId = 0;
    $playerId = '';
    $amount = 0.0;

    if (str_contains($data, ':') && count($tokens) >= 4) {
        $recordId = (int)$tokens[1];
        $playerId = preg_replace('/\D+/', '', (string)$tokens[2]) ?? '';
        $amount   = round((float)$tokens[3], 2);
    } else {
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
            if ($lastNum > 0) {
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

                // Add new deposit amount on top of existing player balance
                $after = round($before + $amount, 2);
                $pdo->prepare("UPDATE players SET balance = ?, updated_at = CURRENT_TIMESTAMP WHERE telegram_id = ?")
                    ->execute([$after, $targetPlayerId]);

                if ($recId > 0 && tableExists($pdo, 'deposits')) {
                    $pdo->prepare("UPDATE deposits SET status = 'approved' WHERE id = ?")->execute([$recId]);
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
                if ($recId > 0 && tableExists($pdo, 'deposits')) {
                    $pdo->prepare("UPDATE deposits SET status = 'rejected' WHERE id = ?")->execute([$recId]);
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

                $pdo->prepare("UPDATE players SET balance = ?, updated_at = CURRENT_TIMESTAMP WHERE telegram_id = ?")
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
