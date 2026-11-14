<?php
// Enable error logging for debugging
ini_set('display_errors', 0);
error_reporting(E_ALL);

$content = file_get_contents("php://input");
$update = json_decode($content, true);

if (!$update) {
    exit;
}

// Handle both standard messages and inline keyboard callbacks
$chatId = $update['message']['chat']['id'] ?? $update['callback_query']['message']['chat']['id'] ?? null;
$text = trim($update['message']['text'] ?? '');
$contact = $update['message']['contact'] ?? null;
$firstName = $update['message']['from']['first_name'] ?? $update['callback_query']['from']['first_name'] ?? 'Player';
$telegramId = (string)($update['message']['from']['id'] ?? $update['callback_query']['from']['id'] ?? '');
$callbackData = $update['callback_query']['data'] ?? null;

$token = getenv('TELEGRAM_BOT_TOKEN') ?: '9251047882:AAFbbYkqcqfPvhkXtK5cfTgm1Z5xghmqaq0';
$ADMIN_ID = "730967613"; 

// 📢 OFFICIAL TELEGRAM CHANNEL USERNAME
$REQUIRED_CHANNEL = "@Future_Bingo"; 

function sendMessage($chatId, $message, $keyboard = null) {
    global $token;
    $url = "https://api.telegram.org/bot$token/sendMessage";
    $data = [
        'chat_id' => $chatId,
        'text' => $message,
        'parse_mode' => 'HTML'
    ];
    if ($keyboard) {
        $data['reply_markup'] = json_encode($keyboard);
    }
    @file_get_contents($url . '?' . http_build_query($data));
}

// Function to check if user has joined your channel
function checkChannelMembership($userId) {
    global $token, $REQUIRED_CHANNEL;
    $url = "https://api.telegram.org/bot$token/getChatMember?chat_id=" . urlencode($REQUIRED_CHANNEL) . "&user_id=" . $userId;
    $response = @file_get_contents($url);
    if (!$response) return true; // Fail safe if API is unreachable
    
    $res = json_decode($response, true);
    if (isset($res['ok']) && $res['ok'] === true) {
        $status = $res['result']['status'] ?? '';
        if (in_array($status, ['creator', 'administrator', 'member'])) {
            return true;
        }
    }
    return false;
}

// Connect to SQLite Database
try {
    $pdo = new PDO("sqlite:" . __DIR__ . "/bingo.db");
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec("CREATE TABLE IF NOT EXISTS players (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        telegram_id TEXT UNIQUE,
        first_name TEXT,
        phone TEXT,
        balance REAL DEFAULT 0.00,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
} catch (PDOException $e) {
    exit;
}

if ($chatId) {
    // Handle Callback Query (e.g. "I Have Joined" button)
    if ($callbackData === 'check_join') {
        if (checkChannelMembership($telegramId)) {
            sendMessage($chatId, "🎉 <b>Thank you for joining!</b>\nChoose an option from your menu below:", [
                'keyboard' => [
                    [['text' => '🎮 Play Bingo'], ['text' => '💰 Balance']],
                    [['text' => '💳 Deposit'], ['text' => '💸 Withdraw']],
                    [['text' => '📤 Invite Friends'], ['text' => 'ℹ️ Instruction']],
                    [['text' => '📞 Support']]
                ],
                'resize_keyboard' => true
            ]);
        } else {
            sendMessage($chatId, "⚠️ We couldn't verify your membership yet. Please make sure you join <b>{$REQUIRED_CHANNEL}</b> and try again.");
        }
        exit;
    }

    // 1. Mandatory Channel Subscription Check
    if (!checkChannelMembership($telegramId)) {
        $joinKeyboard = [
            'inline_keyboard' => [
                [['text' => '📢 Join Our Channel', 'url' => 'https://t.me/Future_Bingo']],
                [['text' => '✅ I Have Joined', 'callback_data' => 'check_join']]
            ]
        ];
        sendMessage($chatId, "🚀 <b>Welcome to Future Bingo!</b>\n\nTo use this bot and play games, you must first join our official channel: <b>{$REQUIRED_CHANNEL}</b>.\n\nAfter joining, tap <b>✅ I Have Joined</b> below!", $joinKeyboard);
        exit;
    }

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
            $amount = (float)$parts[2];

            $stmt = $pdo->prepare("SELECT first_name, balance FROM players WHERE telegram_id = ?");
            $stmt->execute([$targetId]);
            $targetPlayer = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($targetPlayer) {
                $newBal = $targetPlayer['balance'] + $amount;
                $upd = $pdo->prepare("UPDATE players SET balance = ? WHERE telegram_id = ?");
                $upd->execute([$newBal, $targetId]);

                sendMessage($chatId, "✅ Credited <b>{$amount} ETB</b> to <b>{$targetPlayer['first_name']}</b> (<code>{$targetId}</code>).\nNew Balance: <b>{$newBal} ETB</b>");
                sendMessage($targetId, "🎉 <b>Deposit Confirmed!</b>\n\n<b>{$amount} ETB</b> has been added to your Future Bingo wallet.\nCurrent Balance: <b>{$newBal} ETB</b>");
            } else {
                sendMessage($chatId, "❌ Player with Telegram ID <code>{$targetId}</code> not found in database.");
            }
        } else {
            sendMessage($chatId, "⚠️ Usage format: <code>/add TELEGRAM_ID AMOUNT</code>\nExample: <code>/add 730967613 200</code>");
        }
    }
    elseif ($text === '/start' || stripos($text, 'start') !== false) {
        $stmt = $pdo->prepare("SELECT * FROM players WHERE telegram_id = ?");
        $stmt->execute([$telegramId]);
        $player = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($player && !empty($player['phone'])) {
            sendMessage($chatId, "👋 Welcome back, <b>{$firstName}</b>!\nChoose an option from the menu below:", $mainMenu);
        } else {
            $stmt = $pdo->prepare("INSERT INTO players (telegram_id, first_name, phone, balance) VALUES (?, ?, ?, 0.00) ON CONFLICT(telegram_id) DO NOTHING");
            $stmt->execute([$telegramId, $firstName, 'WebUser']);

            sendMessage($chatId, "👋 Welcome, <b>{$firstName}</b>!\nYour profile is registered. Choose an option from the menu below:", $mainMenu);
        }
    }
    elseif ($contact) {
        $phone = $contact['phone_number'];
        $stmt = $pdo->prepare("INSERT INTO players (telegram_id, first_name, phone, balance) VALUES (?, ?, ?, 0.00) ON CONFLICT(telegram_id) DO UPDATE SET phone = excluded.phone");
        $stmt->execute([$telegramId, $firstName, $phone]);

        sendMessage($chatId, "✅ <b>Registered successfully!</b>\nYour wallet balance is <b>0.00 ETB</b>.", $mainMenu);
    }
    elseif (stripos($text, 'Play Bingo') !== false) {
        $webAppKeyboard = [
            'inline_keyboard' => [
                [['text' => '🚀 Launch Future Bingo App', 'web_app' => ['url' => 'https://lali-bingo-bot.onrender.com/index.html']]]
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
                       "Transfer funds to our accounts below and send your transaction receipt along with your ID (<code>{$telegramId}</code>) to support (@Future_Support):\n\n" .
                       "🏦 <b>CBE:</b> <code>1000171689116</code> (Lalisa Shafe Nagari)\n" .
                       "📱 <b>Telebirr:</b> <code>0917461538</code> (Lalisa Shafe Nagari)";
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
        sendMessage($chatId, "📤 Share your referral link with friends:\n\nhttps://t.me/Future_Bingo_bot", $mainMenu);
    }
    elseif (stripos($text, 'Instruction') !== false) {
        sendMessage($chatId, "📖 <b>How to Play:</b>\n1. Fund your wallet via <b>💳 Deposit</b>.\n2. Tap <b>🎮 Play Bingo</b> and pick a table stake (10, 20, 50, or 100 ETB) and your Cartela number (1-500).\n3. Complete a line and tap <b>CLAIM BINGO</b> to win!", $mainMenu);
    }
    elseif (stripos($text, 'Support') !== false) {
        sendMessage($chatId, "📞 Contact our support team: @Future_Support", $mainMenu);
    }
}
?>
