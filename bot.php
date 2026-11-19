<?php
ini_set('display_errors', 0);
error_reporting(E_ALL);

require_once __DIR__ . '/db.php';

$content = file_get_contents('php://input');
$update = json_decode($content, true);

if (!$update || !is_array($update)) {
    http_response_code(200);
    echo 'OK';
    exit;
}

$callbackQuery = $update['callback_query'] ?? null;
$message = $update['message'] ?? null;

$chatId = $message['chat']['id'] ?? $callbackQuery['message']['chat']['id'] ?? null;
$messageId = $callbackQuery['message']['message_id'] ?? null;
$text = trim((string)($message['text'] ?? ''));
$contact = $message['contact'] ?? null;
$firstName = trim((string)($message['from']['first_name'] ?? $callbackQuery['from']['first_name'] ?? 'Player'));
if ($firstName === '') {
    $firstName = 'Player';
}
$telegramId = (string)($message['from']['id'] ?? $callbackQuery['from']['id'] ?? '');

$token = trim((string)(getenv('TELEGRAM_BOT_TOKEN') ?: ''));
$appUrl = rtrim((string)(getenv('APP_URL') ?: 'https://lali-bingo-bot.onrender.com'), '/');
$botUsername = ltrim((string)(getenv('BOT_USERNAME') ?: 'Future_Bingo_bot'), '@');
$ADMIN_ID = trim((string)(getenv('ADMIN_TELEGRAM_ID') ?: '730967613'));

function tgRequest(string $method, array $params) {
    global $token;
    if ($token === '') {
        error_log('Future Bingo bot: TELEGRAM_BOT_TOKEN is empty.');
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

function sendMessage($chatId, string $message, ?array $keyboard = null): void {
    if (!$chatId) {
        return;
    }
    $params = [
        'chat_id' => $chatId,
        'text' => $message,
        'parse_mode' => 'HTML',
        'disable_web_page_preview' => true,
    ];
    if ($keyboard !== null) {
        $params['reply_markup'] = json_encode($keyboard);
    }
    $resp = tgRequest('sendMessage', $params);
    error_log("sendMessage ({$chatId}): " . var_export($resp, true));
}

function editMessage($chatId, $messageId, string $message): void {
    if (!$chatId || !$messageId) {
        return;
    }
    tgRequest('editMessageText', [
        'chat_id' => $chatId,
        'message_id' => $messageId,
        'text' => $message,
        'parse_mode' => 'HTML',
    ]);
}

function answerCallback($callbackId, string $text = ''): void {
    if (!$callbackId) {
        return;
    }
    tgRequest('answerCallbackQuery', [
        'callback_query_id' => $callbackId,
        'text' => $text,
    ]);
}

function recordBotLedger(PDO $pdo, string $telegramId, string $type, float $amount, float $before, float $after, string $note): void {
    try {
        $stmt = $pdo->prepare(
            "INSERT INTO balance_ledger (telegram_id, room_id, type, amount, balance_before, balance_after, note)
             VALUES (?, NULL, ?, ?, ?, ?, ?)"
        );
        $stmt->execute([$telegramId, $type, $amount, $before, $after, $note]);
    } catch (Exception $e) {
        error_log("Ledger warning: " . $e->getMessage());
    }
}

function ensurePlayersTable(PDO $pdo): void {
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    if ($driver === 'sqlite') {
        $pdo->exec("CREATE TABLE IF NOT EXISTS players (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            telegram_id TEXT UNIQUE,
            first_name TEXT DEFAULT 'Player',
            phone TEXT DEFAULT 'WebUser',
            balance REAL DEFAULT 0.00,
            referred_by TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");
        $cols = $pdo->query("PRAGMA table_info(players)")->fetchAll(PDO::FETCH_ASSOC);
        $colNames = array_column($cols, 'name');
        if (!in_array('referred_by', $colNames, true)) {
            $pdo->exec("ALTER TABLE players ADD COLUMN referred_by TEXT");
        }
    } elseif ($driver === 'mysql') {
        $pdo->exec("CREATE TABLE IF NOT EXISTS players (
            id INT AUTO_INCREMENT PRIMARY KEY,
            telegram_id VARCHAR(64) UNIQUE,
            first_name VARCHAR(120) DEFAULT 'Player',
            phone VARCHAR(64) DEFAULT 'WebUser',
            balance DECIMAL(12,2) DEFAULT 0.00,
            referred_by VARCHAR(64) NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )");
        $stmt = $pdo->query("SHOW COLUMNS FROM players LIKE 'referred_by'");
        if (!$stmt->fetch()) {
            $pdo->exec("ALTER TABLE players ADD COLUMN referred_by VARCHAR(64) NULL");
        }
    }
}

$pdo = null;
try {
    $pdo = getBingoPdo();
    ensurePlayersTable($pdo);
    $pdo->exec("CREATE TABLE IF NOT EXISTS transactions (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        telegram_id TEXT,
        tx_reference TEXT UNIQUE,
        amount REAL,
        source TEXT,
        status TEXT DEFAULT 'pending',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
} catch (Exception $e) {
    error_log("Future Bingo DB init error: " . $e->getMessage());
}

// Handle Admin Inline Button Taps (Approve / Reject)
if ($callbackQuery) {
    $data = (string)($callbackQuery['data'] ?? '');
    $cbId = (string)($callbackQuery['id'] ?? '');

    if ($telegramId !== $ADMIN_ID) {
        answerCallback($cbId, '⛔ Unauthorized.');
        exit;
    }

    if ($pdo && preg_match('/^(approve_tx|reject_tx):(\d+)$/', $data, $m)) {
        $action = $m[1];
        $txId = (int)$m[2];

        try {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare("SELECT * FROM transactions WHERE id = ? LIMIT 1");
            $stmt->execute([$txId]);
            $tx = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$tx) {
                $pdo->rollBack();
                answerCallback($cbId, 'Transaction not found.');
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
            $txRef = (string)$tx['tx_reference'];

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

                answerCallback($cbId, '❌ Rejected.');
                editMessage($chatId, $messageId, "❌ <b>REJECTED</b>\n\nPlayer: <code>{$playerId}</code>\nAmount: <b>{$amount} ETB</b>\nRef: <code>{$txRef}</code>");
                sendMessage($playerId, "❌ <b>Deposit Rejected</b>\n\nYour transaction reference <code>{$txRef}</code> could not be verified. Please check your receipt or contact support.");
            }
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log("Callback tx error: " . $e->getMessage());
            answerCallback($cbId, 'Error processing request.');
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

    $safeFirstName = htmlspecialchars($firstName, ENT_QUOTES, 'UTF-8');

    if ($text === '/myid') {
        sendMessage($chatId, "🆔 Your Telegram ID is: <code>{$telegramId}</code>", $mainMenu);
    } elseif ($text === '/pending') {
        if ($telegramId !== $ADMIN_ID) {
            sendMessage($chatId, '⛔ Unauthorized.', $mainMenu);
            exit;
        }
        $pending = $pdo
            ? $pdo->query("SELECT id, telegram_id, tx_reference, amount, created_at FROM transactions WHERE status = 'pending' ORDER BY id ASC LIMIT 20")->fetchAll(PDO::FETCH_ASSOC)
            : [];
        if (!$pending) {
            sendMessage($chatId, '✅ No pending deposit requests.', $mainMenu);
        } else {
            foreach ($pending as $p) {
                $keyboard = [
                    'inline_keyboard' => [[
                        ['text' => "✅ Approve ({$p['amount']} ETB)", 'callback_data' => "approve_tx:{$p['id']}"],
                        ['text' => '❌ Reject', 'callback_data' => "reject_tx:{$p['id']}"]
                    ]]
                ];
                sendMessage($chatId, "⏳ <b>Pending Deposit #{$p['id']}</b>\nPlayer: <code>{$p['telegram_id']}</code>\nAmount: <b>{$p['amount']} ETB</b>\nRef: <code>{$p['tx_reference']}</code>", $keyboard);
            }
        }
    } elseif ($text === '/users') {
        if ($telegramId !== $ADMIN_ID) {
            sendMessage($chatId, '⛔ Unauthorized.', $mainMenu);
            exit;
        }
        $users = $pdo
            ? $pdo->query("SELECT telegram_id, first_name, phone, balance FROM players ORDER BY id DESC LIMIT 20")->fetchAll(PDO::FETCH_ASSOC)
            : [];
        if (!$users) {
            sendMessage($chatId, 'No registered players yet.', $mainMenu);
        } else {
            $msg = "👥 <b>Registered Players (Last 20):</b>\n\n";
            foreach ($users as $u) {
                $uName = htmlspecialchars((string)$u['first_name'], ENT_QUOTES, 'UTF-8');
                $msg .= "• <b>{$uName}</b> | ID: <code>{$u['telegram_id']}</code> | Tel: {$u['phone']} | Bal: <b>{$u['balance']} ETB</b>\n";
            }
            sendMessage($chatId, $msg, $mainMenu);
        }
    } elseif (stripos($text, '/add') === 0) {
        if ($telegramId !== $ADMIN_ID) {
            sendMessage($chatId, '⛔ Unauthorized.', $mainMenu);
            exit;
        }
        $parts = preg_split('/\s+/', trim($text));
        if ($pdo && count($parts) >= 3 && is_numeric($parts[2])) {
            $targetId = trim($parts[1]);
            $amount = round((float)$parts[2], 2);
            try {
                $pdo->beginTransaction();
                $stmt = $pdo->prepare("SELECT first_name, balance FROM players WHERE telegram_id = ?");
                $stmt->execute([$targetId]);
                $targetPlayer = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($targetPlayer) {
                    $before = (float)$targetPlayer['balance'];
                    $newBal = round($before + $amount, 2);
                    $pdo->prepare("UPDATE players SET balance = ? WHERE telegram_id = ?")->execute([$newBal, $targetId]);
                    $playerName = (string)$targetPlayer['first_name'];
                } else {
                    $before = 0.00;
                    $newBal = $amount;
                    $pdo->prepare("INSERT INTO players (telegram_id, first_name, phone, balance) VALUES (?, 'Player', 'WebUser', ?)")->execute([$targetId, $newBal]);
                    $playerName = 'Player';
                }
                recordBotLedger($pdo, $targetId, 'admin_add', $amount, $before, $newBal, 'Admin /add credit');
                $pdo->commit();

                $safeTargetName = htmlspecialchars($playerName, ENT_QUOTES, 'UTF-8');
                sendMessage($chatId, "✅ Credited <b>{$amount} ETB</b> to <b>{$safeTargetName}</b> (<code>{$targetId}</code>).\nNew Balance: <b>{$newBal} ETB</b>", $mainMenu);
                sendMessage($targetId, "🎉 <b>Deposit Confirmed!</b>\n\n<b>{$amount} ETB</b> has been added to your Future Bingo wallet.\nCurrent Balance: <b>{$newBal} ETB</b>");
            } catch (Exception $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                error_log("Admin /add error: " . $e->getMessage());
                sendMessage($chatId, '❌ Failed to credit balance.', $mainMenu);
            }
        } else {
            sendMessage($chatId, "⚠️ Usage format: <code>/add TELEGRAM_ID AMOUNT</code>\nExample: <code>/add 730967613 200</code>", $mainMenu);
        }
    } elseif (preg_match('/^\/verify(?:\s+(\S+))?(?:\s+([0-9]+(?:\.[0-9]+)?))?/i', $text, $matches)) {
        $txRef = strtoupper(trim($matches[1] ?? ''));
        $amount = round((float)($matches[2] ?? 0), 2);

        if ($txRef === '' || $amount <= 0) {
            sendMessage($chatId, "⚠️ Usage: <code>/verify TRANSACTION_ID AMOUNT</code>\nExample: <code>/verify BGH82K91LM 100</code>", $mainMenu);
        } elseif (strlen($txRef) < 6 || !preg_match('/^[A-Z0-9_-]+$/', $txRef)) {
            sendMessage($chatId, '❌ Invalid transaction reference format.', $mainMenu);
        } elseif ($pdo) {
            $stmt = $pdo->prepare("SELECT status FROM transactions WHERE UPPER(tx_reference) = ? LIMIT 1");
            $stmt->execute([$txRef]);
            $existing = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($existing) {
                $statusMsg = $existing['status'] === 'pending'
                    ? '⏳ This Transaction ID is already pending admin verification.'
                    : '❌ This Transaction ID has already been processed.';
                sendMessage($chatId, $statusMsg, $mainMenu);
            } else {
                $pdo->prepare("INSERT OR IGNORE INTO players (telegram_id, first_name, phone, balance) VALUES (?, ?, 'WebUser', 0.00)")->execute([$telegramId, $firstName]);
                $ins = $pdo->prepare("INSERT INTO transactions (telegram_id, tx_reference, amount, source, status) VALUES (?, ?, ?, 'bot_manual', 'pending')");
                $ins->execute([$telegramId, $txRef, $amount]);
                $txId = $pdo->lastInsertId();

                $adminMsg = "🔔 <b>New Deposit Verification Request</b>\n\n"
                    . "👤 <b>Player:</b> {$safeFirstName} (<code>{$telegramId}</code>)\n"
                    . "💰 <b>Amount:</b> <b>{$amount} ETB</b>\n"
                    . "🧾 <b>Ref ID:</b> <code>{$txRef}</code>\n\n"
                    . "Check your Telebirr/CBE app and confirm below:";

                $keyboard = [
                    'inline_keyboard' => [[
                        ['text' => "✅ Approve ({$amount} ETB)", 'callback_data' => "approve_tx:{$txId}"],
                        ['text' => '❌ Reject', 'callback_data' => "reject_tx:{$txId}"]
                    ]]
                ];

                sendMessage($ADMIN_ID, $adminMsg, $keyboard);
                sendMessage($chatId, "⏳ <b>Deposit Submitted!</b>\n\nReference: <code>{$txRef}</code>\nAmount: <b>{$amount} ETB</b>\n\nYour payment is being verified and your balance will update shortly.", $mainMenu);
            }
        }
    } elseif (preg_match('/^\/start(?:@\S+)?(?:\s+(\S+))?/i', $text, $matches)) {
        $referrerId = isset($matches[1]) ? trim($matches[1]) : null;
        $startingBalance = 50.00;
        $player = null;

        if ($pdo) {
            try {
                $stmt = $pdo->prepare("SELECT * FROM players WHERE telegram_id = ? LIMIT 1");
                $stmt->execute([$telegramId]);
                $player = $stmt->fetch(PDO::FETCH_ASSOC);
            } catch (Exception $e) {
                error_log("/start select error: " . $e->getMessage());
            }
        }

        if (!$player && $pdo) {
            try {
                $pdo->beginTransaction();
                $ins = $pdo->prepare("INSERT INTO players (telegram_id, first_name, phone, balance, referred_by) VALUES (?, ?, 'WebUser', ?, ?)");
                $ins->execute([$telegramId, $firstName, $startingBalance, $referrerId]);
                recordBotLedger($pdo, $telegramId, 'welcome_bonus', $startingBalance, 0.00, $startingBalance, 'Initial /start registration bonus');

                if ($referrerId && $referrerId !== $telegramId) {
                    $bonusAmount = 5.00;
                    $refStmt = $pdo->prepare("SELECT balance FROM players WHERE telegram_id = ? LIMIT 1");
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
                sendMessage($chatId, "👋 Welcome to <b>Future Bingo</b>, <b>{$safeFirstName}</b>!\nYou received a <b>50.00 ETB</b> play credit bonus! Tap <b>🎮 Play Bingo</b> below to pick a table:", $mainMenu);
                exit;
            } catch (Exception $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                error_log("/start insert error: " . $e->getMessage());
            }
        }

        if ($player) {
            $currentBal = number_format((float)($player['balance'] ?? 0), 2);
            sendMessage($chatId, "👋 Welcome back to <b>Future Bingo</b>, <b>{$safeFirstName}</b>!\nCurrent Balance: <b>{$currentBal} ETB</b>. Choose an option below:", $mainMenu);
        } else {
            sendMessage($chatId, "👋 Welcome to <b>Future Bingo</b>, <b>{$safeFirstName}</b>!\nChoose an option from the menu below to get started:", $mainMenu);
        }
    } elseif ($contact) {
        $phone = (string)($contact['phone_number'] ?? '');
        if ($pdo) {
            $stmt = $pdo->prepare("SELECT balance FROM players WHERE telegram_id = ? LIMIT 1");
            $stmt->execute([$telegramId]);
            $existing = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($existing) {
                $pdo->prepare("UPDATE players SET phone = ? WHERE telegram_id = ?")->execute([$phone, $telegramId]);
                $bal = number_format((float)$existing['balance'], 2);
                sendMessage($chatId, "✅ <b>Contact updated successfully!</b>\nCurrent Balance: <b>{$bal} ETB</b>.", $mainMenu);
            } else {
                $pdo->prepare("INSERT INTO players (telegram_id, first_name, phone, balance) VALUES (?, ?, ?, 50.00)")->execute([$telegramId, $firstName, $phone]);
                recordBotLedger($pdo, $telegramId, 'welcome_bonus', 50.00, 0.00, 50.00, 'Contact registration bonus');
                sendMessage($chatId, "✅ <b>Registered successfully!</b>\nYou received a <b>50.00 ETB</b> play credit bonus.", $mainMenu);
            }
        }
    } elseif (stripos($text, 'Play Bingo') !== false) {
        $webAppKeyboard = [
            'inline_keyboard' => [[
                ['text' => '🚀 Launch Future Bingo App', 'web_app' => ['url' => $appUrl . '/index.php']]
            ]]
        ];
        sendMessage($chatId, "Tap the button below to open <b>Future Bingo</b> and choose a table:", $webAppKeyboard);
    } elseif (stripos($text, 'Balance') !== false) {
        $bal = '0.00';
        if ($pdo) {
            $stmt = $pdo->prepare("SELECT balance FROM players WHERE telegram_id = ? LIMIT 1");
            $stmt->execute([$telegramId]);
            $player = $stmt->fetch(PDO::FETCH_ASSOC);
            $bal = number_format((float)($player['balance'] ?? 0.00), 2);
        }
        sendMessage($chatId, "💰 Your current balance is: <b>{$bal} ETB</b>", $mainMenu);
    } elseif (stripos($text, 'Deposit') !== false) {
        $depositText = "💳 <b>Deposit Funds & Top-Up</b>\n\n"
            . "1. Transfer funds to our accounts below:\n"
            . "🏦 <b>CBE:</b> <code>1000171689116</code> (Lalisa Shafe Nagari)\n"
            . "📱 <b>Telebirr:</b> <code>0917461538</code> (Lalisa Shafe Nagari)\n\n"
            . "2. Reply with your <b>Transaction ID</b> and <b>Amount</b>:\n"
            . "<code>/verify TRANSACTION_ID AMOUNT</code>\n\n"
            . "<i>Example:</i> <code>/verify BGH82K91LM 100</code>";
        sendMessage($chatId, $depositText, $mainMenu);
    } elseif (stripos($text, 'Withdraw') !== false) {
        $withdrawText = "💸 <b>Withdraw Funds</b>\n\n"
            . "1. Open the <b>🎮 Play Bingo</b> WebApp.\n"
            . "2. Go to the <b>Wallet</b> tab.\n"
            . "3. Tap <b>Withdraw</b> to submit a request.";
        sendMessage($chatId, $withdrawText, $mainMenu);
    } elseif (stripos($text, 'Invite Friends') !== false) {
        sendMessage($chatId, "📤 Share your referral link with friends:\n\nhttps://t.me/{$botUsername}?start={$telegramId}", $mainMenu);
    } elseif (stripos($text, 'Instruction') !== false) {
        sendMessage($chatId, "📖 <b>How to Play Future Bingo:</b>\n• <b>10 ETB</b> table: 1 line to win (80% prize pool).\n• <b>20 ETB</b> table: 2 lines to win (80% prize pool).\n• <b>50 ETB</b> table: 3 lines to win (80% prize pool).\nPick up to 3 cartelas and complete the target lines to win!", $mainMenu);
    } elseif (stripos($text, 'Support') !== false) {
        sendMessage($chatId, "📞 Need help? Message our support or admin directly.", $mainMenu);
    }
}
