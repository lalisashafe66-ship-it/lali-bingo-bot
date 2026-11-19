<?php
ini_set('display_errors', 0);
error_reporting(E_ALL);

require_once __DIR__ . '/db.php';

$content = file_get_contents("php://input");
$update = json_decode($content, true);

if (!$update || !is_array($update)) {
    exit;
}

$callbackQuery = $update['callback_query'] ?? null;
$chatId = $update['message']['chat']['id'] ?? $callbackQuery['message']['chat']['id'] ?? null;
$messageId = $callbackQuery['message']['message_id'] ?? null;
$text = trim((string)($update['message']['text'] ?? ''));
$contact = $update['message']['contact'] ?? null;
$firstName = $update['message']['from']['first_name'] ?? $callbackQuery['from']['first_name'] ?? 'Player';
$telegramId = (string)($update['message']['from']['id'] ?? $callbackQuery['from']['id'] ?? '');

$token = trim((string)(getenv('TELEGRAM_BOT_TOKEN') ?: ''));
$ADMIN_ID = "730967613";

function tgRequest($method, array $params) {
    global $token;
    if ($token === '') {
        error_log("Future Bingo bot: TELEGRAM_BOT_TOKEN is empty.");
        return false;
    }

    $url = "https://api.telegram.org/bot{$token}/{$method}";

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $params,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
        ]);
        $result = curl_exec($ch);
        if ($result === false) {
            error_log("Telegram cURL error ({$method}): " . curl_error($ch));
        }
        curl_close($ch);
        return $result;
    }

    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => "Content-type: application/x-www-form-urlencoded\r\n",
            'content' => http_build_query($params),
            'timeout' => 10,
        ]
    ]);
    return @file_get_contents($url, false, $context);
}

function sendMessage($chatId, $message, $keyboard = null) {
    if (!$chatId) return;
    $params = [
        'chat_id' => $chatId,
        'text' => $message,
        'parse_mode' => 'HTML'
    ];
    if ($keyboard) {
        $params['reply_markup'] = json_encode($keyboard);
    }
    $resp = tgRequest('sendMessage', $params);
    error_log("sendMessage resp for {$chatId}: " . var_export($resp, true));
}

function editMessage($chatId, $messageId, $message) {
    if (!$chatId || !$messageId) return;
    tgRequest('editMessageText', [
        'chat_id' => $chatId,
        'message_id' => $messageId,
        'text' => $message,
        'parse_mode' => 'HTML'
    ]);
}

function answerCallback($callbackId, $text = '') {
    if (!$callbackId) return;
    tgRequest('answerCallbackQuery', [
        'callback_query_id' => $callbackId,
        'text' => $text
    ]);
}

function recordBotLedger(PDO $pdo, string $telegramId, string $type, float $amount, float $before, float $after, string $note): void {
    try {
        $stmt = $pdo->prepare("INSERT INTO balance_ledger (telegram_id, room_id, type, amount, balance_before, balance_after, note) VALUES (?, NULL, ?, ?, ?, ?, ?)");
        $stmt->execute([$telegramId, $type, $amount, $before, $after, $note]);
    } catch (Exception $e) {
        error_log("Ledger insert skipped/failed: " . $e->getMessage());
    }
}

function ensureReferredByColumn(PDO $pdo): void {
    try {
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'sqlite') {
            $cols = $pdo->query("PRAGMA table_info(players)")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($cols as $col) {
                if (($col['name'] ?? '') === 'referred_by') {
                    return;
                }
            }
            $pdo->exec("ALTER TABLE players ADD COLUMN referred_by TEXT");
        } elseif ($driver === 'mysql') {
            $stmt = $pdo->query("SHOW COLUMNS FROM players LIKE 'referred_by'");
            if (!$stmt->fetch()) {
                $pdo->exec("ALTER TABLE players ADD COLUMN referred_by VARCHAR(64) NULL");
            }
        }
    } catch (Exception $e) {
        error_log("ensureReferredByColumn notice: " . $e->getMessage());
    }
}

try {
    $pdo = getBingoPdo();
    ensureReferredByColumn($pdo);

    $pdo->exec("CREATE TABLE IF NOT EXISTS transactions (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        telegram_id TEXT,
        tx_reference TEXT UNIQUE,
        amount REAL,
        source TEXT,
        status TEXT DEFAULT 'pending',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
} catch (PDOException $e) {
    error_log("Future Bingo DB error: " . $e->getMessage());
    exit;
}

// Handle Admin Inline Button Taps (Approve / Reject)
if ($callbackQuery) {
    $data = $callbackQuery['data'] ?? '';
    $cbId = $callbackQuery['id'] ?? '';

    if ($telegramId !== $ADMIN_ID) {
        answerCallback($cbId, "⛔ Unauthorized.");
        exit;
    }

    if (preg_match('/^(approve_tx|reject_tx):(\d+)$/', $data, $m)) {
        $action = $m[1];
        $txId = (int)$m[2];

        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare("SELECT * FROM transactions WHERE id = ? LIMIT 1");
            $stmt->execute([$txId]);
            $tx = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$tx) {
                $pdo->rollBack();
                answerCallback($cbId, "Transaction not found.");
                exit;
            }

            if ($tx['status'] !== 'pending') {
                $pdo->rollBack();
                answerCallback($cbId, "Already processed ({$tx['status']}).");
                editMessage($chatId, $messageId, "ℹ️ Transaction <code>{$tx['tx_reference']}</code> was already <b>{$tx['status']}</b>.");
                exit;
            }

            $playerId = (string)$tx['telegram_id'];
            $amount = round((float)$tx['amount'], 2);
            $txRef = $tx['tx_reference'];

            if ($action === 'approve_tx') {
                $pdo->prepare("UPDATE transactions SET status = 'claimed' WHERE id = ? AND status = 'pending'")->execute([$txId]);
                $pdo->prepare("INSERT OR IGNORE INTO players (telegram_id, first_name, phone, balance) VALUES (?, 'Player', 'WebUser', 0.00)")->execute([$playerId]);

                $balStmt = $pdo->prepare("SELECT balance FROM players WHERE telegram_id = ?");
                $balStmt->execute([$playerId]);
                $before = (float)($balStmt->fetchColumn() ?: 0);
                $after = round($before + $amount, 2);

                $pdo->prepare("UPDATE players SET balance = ? WHERE telegram_id = ?")->execute([$after, $playerId]);
                recordBotLedger($pdo, $playerId, 'deposit_approve', $amount, $before, $after, "Approved deposit ref {$txRef}");

                $newBal = number_format($after, 2);
                $pdo->commit();

                answerCallback($cbId, "✅ Approved {$amount} ETB!");
                editMessage($chatId, $messageId, "✅ <b>APPROVED</b>\n\nPlayer: <code>{$playerId}</code>\nAmount: <b>{$amount} ETB</b>\nRef: <code>{$txRef}</code>");
                sendMessage($playerId, "🎉 <b>Deposit Confirmed!</b>\n\n<b>{$amount} ETB</b> (Ref: <code>{$txRef}</code>) has been added to your Future Bingo wallet.\nCurrent Balance: <b>{$newBal} ETB</b>");
            } else {
                $pdo->prepare("UPDATE transactions SET status = 'rejected' WHERE id = ? AND status = 'pending'")->execute([$txId]);
                $pdo->commit();

                answerCallback($cbId, "❌ Rejected.");
                editMessage($chatId, $messageId, "❌ <b>REJECTED</b>\n\nPlayer: <code>{$playerId}</code>\nAmount: <b>{$amount} ETB</b>\nRef: <code>{$txRef}</code>");
                sendMessage($playerId, "❌ <b>Deposit Rejected</b>\n\nYour transaction reference <code>{$txRef}</code> could not be verified. Please check your receipt or contact @Future_Support.");
            }
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log("Callback tx error: " . $e->getMessage());
            answerCallback($cbId, "Error processing request.");
        }
    }
    exit;
}

if ($chatId) {
    $mainMenu = [
        'keyboard' => [
            [['text' => '🎮 Play Bingo'], ['text' => '💰 Balance']],
            [['text' => '💳 Deposit'], ['text' => '💸 Withdraw']],
            [['text' => '📤 Invite Friends'], ['text' => 'ℹ️ Instruction']],
            [['text' => '📞 Support']]
        ],
        'resize_keyboard' => true
    ];

    if ($text === '/myid') {
        sendMessage($chatId, "🆔 Your Telegram ID is: <code>{$telegramId}</code>");
    }
    elseif ($text === '/pending') {
        if ($telegramId !== $ADMIN_ID) {
            sendMessage($chatId, "⛔ Unauthorized.");
            exit;
        }
        $stmt = $pdo->query("SELECT id, telegram_id, tx_reference, amount, created_at FROM transactions WHERE status = 'pending' ORDER BY id ASC LIMIT 20");
        $pending = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!$pending) {
            sendMessage($chatId, "✅ No pending deposit requests.");
        } else {
            foreach ($pending as $p) {
                $keyboard = [
                    'inline_keyboard' => [
                        [
                            ['text' => "✅ Approve ({$p['amount']} ETB)", 'callback_data' => "approve_tx:{$p['id']}"],
                            ['text' => "❌ Reject", 'callback_data' => "reject_tx:{$p['id']}"]
                        ]
                    ]
                ];
                sendMessage($chatId, "⏳ <b>Pending Deposit #{$p['id']}</b>\nPlayer: <code>{$p['telegram_id']}</code>\nAmount: <b>{$p['amount']} ETB</b>\nRef: <code>{$p['tx_reference']}</code>", $keyboard);
            }
        }
    }
    elseif ($text === '/users') {
        if ($telegramId !== $ADMIN_ID) {
            sendMessage($chatId, "⛔ Unauthorized.");
            exit;
        }
        $stmt = $pdo->query("SELECT telegram_id, first_name, phone, balance FROM players ORDER BY id DESC LIMIT 20");
        $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!$users) {
            sendMessage($chatId, "No registered players yet.");
        } else {
            $msg = "👥 <b>Registered Players (Last 20):</b>\n\n";
            foreach ($users as $u) {
                $msg .= "• <b>{$u['first_name']}</b> | ID: <code>{$u['telegram_id']}</code> | Tel: {$u['phone']} | Bal: <b>{$u['balance']} ETB</b>\n";
            }
            sendMessage($chatId, $msg);
        }
    }
    elseif (stripos($text, '/add') === 0) {
        if ($telegramId !== $ADMIN_ID) {
            sendMessage($chatId, "⛔ Unauthorized.");
            exit;
        }
        $parts = preg_split('/\s+/', trim($text));

        if (count($parts) >= 3 && is_numeric($parts[2])) {
            $targetId = trim($parts[1]);
            $amount = round((float)$parts[2], 2);

            $pdo->beginTransaction();
            try {
                $stmt = $pdo->prepare("SELECT first_name, balance FROM players WHERE telegram_id = ?");
                $stmt->execute([$targetId]);
                $targetPlayer = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($targetPlayer) {
                    $before = (float)$targetPlayer['balance'];
                    $newBal = round($before + $amount, 2);
                    $pdo->prepare("UPDATE players SET balance = ? WHERE telegram_id = ?")->execute([$newBal, $targetId]);
                    $playerName = $targetPlayer['first_name'];
                } else {
                    $before = 0.00;
                    $newBal = $amount;
                    $pdo->prepare("INSERT INTO players (telegram_id, first_name, phone, balance) VALUES (?, 'Player', 'WebUser', ?)")->execute([$targetId, $newBal]);
                    $playerName = 'Player';
                }
                recordBotLedger($pdo, $targetId, 'admin_add', $amount, $before, $newBal, "Admin /add credit");
                $pdo->commit();

                sendMessage($chatId, "✅ Credited <b>{$amount} ETB</b> to <b>{$playerName}</b> (<code>{$targetId}</code>).\nNew Balance: <b>{$newBal} ETB</b>");
                sendMessage($targetId, "🎉 <b>Deposit Confirmed!</b>\n\n<b>{$amount} ETB</b> has been added to your Future Bingo wallet.\nCurrent Balance: <b>{$newBal} ETB</b>");
            } catch (Exception $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                error_log("Admin /add error: " . $e->getMessage());
                sendMessage($chatId, "❌ Failed to credit balance.");
            }
        } else {
            sendMessage($chatId, "⚠️ Usage format: <code>/add TELEGRAM_ID AMOUNT</code>\nExample: <code>/add 730967613 200</code>");
        }
    }
    elseif (preg_match('/^\/verify(?:\s+(\S+))?(?:\s+([0-9]+(?:\.[0-9]+)?))?/i', $text, $matches)) {
        $txRef = strtoupper(trim($matches[1] ?? ''));
        $amount = round((float)($matches[2] ?? 0), 2);

        if ($txRef === '' || $amount <= 0) {
            sendMessage($chatId, "⚠️ Usage: <code>/verify TRANSACTION_ID AMOUNT</code>\nExample: <code>/verify BGH82K91LM 100</code>");
        } elseif (strlen($txRef) < 6 || !preg_match('/^[A-Z0-9_-]+$/', $txRef)) {
            sendMessage($chatId, "❌ Invalid transaction reference format.");
        } else {
            $stmt = $pdo->prepare("SELECT status FROM transactions WHERE UPPER(tx_reference) = ? LIMIT 1");
            $stmt->execute([$txRef]);
            $existing = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($existing) {
                $statusMsg = $existing['status'] === 'pending'
                    ? "⏳ This Transaction ID is already pending admin verification."
                    : "❌ This Transaction ID has already been processed.";
                sendMessage($chatId, $statusMsg, $mainMenu);
            } else {
                $pdo->prepare("INSERT OR IGNORE INTO players (telegram_id, first_name, phone, balance) VALUES (?, ?, 'WebUser', 0.00)")->execute([$telegramId, $firstName]);
                $ins = $pdo->prepare("INSERT INTO transactions (telegram_id, tx_reference, amount, source, status) VALUES (?, ?, ?, 'bot_manual', 'pending')");
                $ins->execute([$telegramId, $txRef, $amount]);
                $txId = $pdo->lastInsertId();

                $adminMsg = "🔔 <b>New Deposit Verification Request</b>\n\n" .
                            "👤 <b>Player:</b> {$firstName} (<code>{$telegramId}</code>)\n" .
                            "💰 <b>Amount:</b> <b>{$amount} ETB</b>\n" .
                            "🧾 <b>Ref ID:</b> <code>{$txRef}</code>\n\n" .
                            "Check your Telebirr/CBE app and confirm below:";

                $keyboard = [
                    'inline_keyboard' => [
                        [
                            ['text' => "✅ Approve ({$amount} ETB)", 'callback_data' => "approve_tx:{$txId}"],
                            ['text' => "❌ Reject", 'callback_data' => "reject_tx:{$txId}"]
                        ]
                    ]
                ];

                sendMessage($ADMIN_ID, $adminMsg, $keyboard);
                sendMessage($chatId, "⏳ <b>Deposit Submitted!</b>\n\nReference: <code>{$txRef}</code>\nAmount: <b>{$amount} ETB</b>\n\nYour payment is being verified and your balance will update shortly.", $mainMenu);
            }
        }
    }
    elseif (preg_match('/^\/start(?:\s+(\S+))?/i', $text, $matches)) {
        $referrerId = isset($matches[1]) ? trim($matches[1]) : null;
        $startingBalance = 100.00;
        $currentBal = $startingBalance;

        try {
            $stmt = $pdo->prepare("SELECT * FROM players WHERE telegram_id = ?");
            $stmt->execute([$telegramId]);
            $player = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$player) {
                $pdo->beginTransaction();
                try {
                    $ins = $pdo->prepare("INSERT INTO players (telegram_id, first_name, phone, balance, referred_by) VALUES (?, ?, 'WebUser', ?, ?)");
                    $ins->execute([$telegramId, $firstName, $startingBalance, $referrerId]);
                    recordBotLedger($pdo, $telegramId, 'welcome_bonus', $startingBalance, 0.00, $startingBalance, 'Initial /start registration bonus');

                    if ($referrerId && $referrerId !== $telegramId) {
                        $bonusAmount = 5.00;
                        $refStmt = $pdo->prepare("SELECT balance FROM players WHERE telegram_id = ?");
                        $refStmt->execute([$referrerId]);
                        $refRow = $refStmt->fetch(PDO::FETCH_ASSOC);
                        if ($refRow) {
                            $refBefore = (float)$refRow['balance'];
                            $refAfter = round($refBefore + $bonusAmount, 2);
                            $pdo->prepare("UPDATE players SET balance = ? WHERE telegram_id = ?")->execute([$refAfter, $referrerId]);
                            recordBotLedger($pdo, $referrerId, 'referral_bonus', $bonusAmount, $refBefore, $refAfter, "Referral from {$telegramId}");
                            sendMessage($referrerId, "🎉 <b>Referral Bonus!</b>\nA new friend joined using your link. <b>{$bonusAmount} ETB</b> has been added to your balance!");
                        }
                    }
                    $pdo->commit();
                } catch (Exception $e) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    error_log("/start insert error: " . $e->getMessage());
                }

                sendMessage($chatId, "👋 Welcome to <b>Future Bingo</b>, <b>{$firstName}</b>!\nYou received a <b>100.00 ETB</b> free starting balance to test the game! Choose an option from the menu below:", $mainMenu);
            } else {
                $currentBal = number_format((float)$player['balance'], 2);
                sendMessage($chatId, "👋 Welcome back, <b>{$firstName}</b>!\nYou are already registered. Your current wallet balance is <b>{$currentBal} ETB</b>. Choose an option from the menu below:", $mainMenu);
            }
        } catch (Exception $e) {
            error_log("/start outer error: " . $e->getMessage());
            sendMessage($chatId, "👋 Welcome to <b>Future Bingo</b>, <b>{$firstName}</b>!\nChoose an option from the menu below:", $mainMenu);
        }
    }
    elseif ($contact) {
        $phone = $contact['phone_number'];
        $stmt = $pdo->prepare("SELECT balance FROM players WHERE telegram_id = ?");
        $stmt->execute([$telegramId]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($existing) {
            $pdo->prepare("UPDATE players SET phone = ? WHERE telegram_id = ?")->execute([$phone, $telegramId]);
            $bal = number_format((float)$existing['balance'], 2);
            sendMessage($chatId, "✅ <b>Contact updated successfully!</b>\nYour current wallet balance is <b>{$bal} ETB</b>.", $mainMenu);
        } else {
            $pdo->prepare("INSERT INTO players (telegram_id, first_name, phone, balance) VALUES (?, ?, ?, 100.00)")->execute([$telegramId, $firstName, $phone]);
            recordBotLedger($pdo, $telegramId, 'welcome_bonus', 100.00, 0.00, 100.00, 'Contact registration bonus');
            sendMessage($chatId, "✅ <b>Registered successfully!</b>\nYou received a <b>100.00 ETB</b> starting balance.", $mainMenu);
        }
    }
    elseif (stripos($text, 'Play Bingo') !== false) {
        $webAppKeyboard = [
            'inline_keyboard' => [
                [['text' => '🚀 Launch Future Bingo App', 'web_app' => ['url' => 'https://lali-bingo-bot.onrender.com/index.php']]]
            ]
        ];
        sendMessage($chatId, "Tap the button below to open your Future Bingo game app and choose a table:", $webAppKeyboard);
    }
    elseif (stripos($text, 'Balance') !== false) {
        $stmt = $pdo->prepare("SELECT balance FROM players WHERE telegram_id = ?");
        $stmt->execute([$telegramId]);
        $player = $stmt->fetch(PDO::FETCH_ASSOC);
        $bal = number_format((float)($player['balance'] ?? 0.00), 2);
        sendMessage($chatId, "💰 Your current balance is: <b>{$bal} ETB</b>", $mainMenu);
    }
    elseif (stripos($text, 'Deposit') !== false) {
        $depositText = "💳 <b>Deposit Funds & Top-Up</b>\n\n" .
                       "1. Transfer funds to our accounts below:\n" .
                       "🏦 <b>CBE:</b> <code>1000171689116</code> (Lalisa Shafe Nagari)\n" .
                       "📱 <b>Telebirr:</b> <code>0917461538</code> (Lalisa Shafe Nagari)\n\n" .
                       "2. Once you receive your confirmation SMS, reply right here with your <b>Transaction ID</b> and <b>Amount</b>:\n" .
                       "<code>/verify TRANSACTION_ID AMOUNT</code>\n\n" .
                       "<i>Example:</i> <code>/verify BGH82K91LM 100</code>";
        sendMessage($chatId, $depositText, $mainMenu);
    }
    elseif (stripos($text, 'Withdraw') !== false) {
        $withdrawText = "💸 <b>Withdraw Funds</b>\n\n" .
                        "1. Open the <b>🎮 Play Bingo</b> WebApp.\n" .
                        "2. Go to the <b>💰 Wallet</b> tab.\n" .
                        "3. Tap <b>💸 Request Withdrawal</b>.";
        sendMessage($chatId, $withdrawText, $mainMenu);
    }
    elseif (stripos($text, 'Invite Friends') !== false) {
        $botUsername = "Future_Bingo_bot";
        sendMessage($chatId, "📤 Share your referral link with friends:\n\nhttps://t.me/{$botUsername}?start={$telegramId}", $mainMenu);
    }
    elseif (stripos($text, 'Instruction') !== false) {
        sendMessage($chatId, "📖 <b>How to Play Future Bingo:</b>\n1. Fund your wallet via <b>💳 Deposit</b>.\n2. Tap <b>🎮 Play Bingo</b> and pick a table stake (10, 20, or 50 ETB) and your Cartela number (1-400).\n3. Complete a line and tap <b>CLAIM BINGO</b> to win!", $mainMenu);
    }
    elseif (stripos($text, 'Support') !== false) {
        sendMessage($chatId, "📞 Contact our support team: @Future_Support", $mainMenu);
    }
}
