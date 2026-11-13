<?php
// SQLite database (shared across bot.php and api.php)
$pdo = new PDO("sqlite:" . __DIR__ . "/bingo.db");
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Ensure players table exists with 0.00 default starting balance
$pdo->exec("CREATE TABLE IF NOT EXISTS players (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    telegram_id TEXT UNIQUE,
    first_name TEXT,
    phone TEXT,
    balance REAL DEFAULT 0.00,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
)");

$content = file_get_contents("php://input");
$update = json_decode($content, true);

if (!$update) exit;

$chatId = $update['message']['chat']['id'] ?? null;
$text = trim($update['message']['text'] ?? '');
$contact = $update['message']['contact'] ?? null;
$firstName = $update['message']['from']['first_name'] ?? 'Player';
$telegramId = (string)($update['message']['from']['id'] ?? '');

// Bot Token
$token = "9251047882:AAFbbYkqcqfPvhkXtK5cfTgm1Z5xghmqaq0";

// Admin Telegram ID (Replace with your numeric Telegram ID if you want strict locking)
$ADMIN_ID = ""; 

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

if ($chatId) {
    // Helper command to check your numeric Telegram ID
    if ($text === '/myid') {
        sendMessage($chatId, "🆔 Your Telegram ID is: <code>{$telegramId}</code>");
        exit;
    }

    // ADMIN COMMAND: /users (View registered players & IDs)
    if ($text === '/users') {
        if ($ADMIN_ID !== '' && $telegramId !== $ADMIN_ID) {
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
            $msg .= "\n<i>To credit a user, send:</i>\n<code>/add USER_ID AMOUNT</code>";
            sendMessage($chatId, $msg);
        }
        exit;
    }

    // ADMIN COMMAND: /add <telegram_id> <amount>
    if (strpos($text, '/add ') === 0) {
        if ($ADMIN_ID !== '' && $telegramId !== $ADMIN_ID) {
            sendMessage($chatId, "⛔ Unauthorized.");
            exit;
        }
        $parts = explode(' ', $text);
        if (count($parts) === 3 && is_numeric($parts[2])) {
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
                sendMessage($targetId, "🎉 <b>Deposit Confirmed!</b>\n\n<b>{$amount} ETB</b> has been added to your Lali Bingo wallet.\nCurrent Balance: <b>{$newBal} ETB</b>");
            } else {
                sendMessage($chatId, "❌ Player with Telegram ID <code>{$targetId}</code> not found.");
            }
        } else {
            sendMessage($chatId, "⚠️ Usage: <code>/add TELEGRAM_ID AMOUNT</code>\nExample: <code>/add 123456789 50</code>");
        }
        exit;
    }

    // Contact Sharing Registration
    if ($contact) {
        $phone = $contact['phone_number'];
        $stmt = $pdo->prepare("INSERT INTO players (telegram_id, first_name, phone, balance) VALUES (?, ?, ?, 0.00) ON CONFLICT(telegram_id) DO UPDATE SET phone = excluded.phone");
        $stmt->execute([$telegramId, $firstName, $phone]);

        $mainMenu = [
            'keyboard' => [
                [['text' => '🎮 Play Bingo'], ['text' => '💰 Balance']],
                [['text' => '💳 Deposit'], ['text' => '💸 Withdraw']],
                [['text' => '📤 Invite Friends'], ['text' => 'ℹ️ Instruction']],
                [['text' => '📞 Support']]
            ],
            'resize_keyboard' => true
        ];
        sendMessage($chatId, "✅ <b>Player registered successfully!</b>\n\nWelcome to Lali Bingo! Your wallet balance is <b>0.00 ETB</b>.\nTap <b>💳 Deposit</b> to fund your account or <b>🎮 Play Bingo</b> to browse tables.", $mainMenu);
    } 
    elseif ($text === '/start' || strpos($text, 'Start') !== false) {
        $stmt = $pdo->prepare("SELECT * FROM players WHERE telegram_id = ?");
        $stmt->execute([$telegramId]);
        $player = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($player && !empty($player['phone'])) {
            $mainMenu = [
                'keyboard' => [
                    [['text' => '🎮 Play Bingo'], ['text' => '💰 Balance']],
                    [['text' => '💳 Deposit'], ['text' => '💸 Withdraw']],
                    [['text' => '📤 Invite Friends'], ['text' => 'ℹ️ Instruction']],
                    [['text' => '📞 Support']]
                ],
                'resize_keyboard' => true
            ];
            sendMessage($chatId, "👋 Welcome back, <b>{$firstName}</b>!\nChoose an option from the menu below:", $mainMenu);
        } else {
            $contactKeyboard = [
                'keyboard' => [
                    [['text' => '📱 Share contact', 'request_contact' => true]]
                ],
                'resize_keyboard' => true,
                'one_time_keyboard' => true
            ];
            sendMessage($chatId, "🎲 <b>Welcome to Lali Bingo!</b>\n\nTo finish registration and secure your wallet, tap <b>\"📱 Share contact\"</b> below.", $contactKeyboard);
        }
    }
    elseif (strpos($text, 'Play Bingo') !== false) {
        $webAppKeyboard = [
            'inline_keyboard' => [
                [['text' => '🚀 Launch Lali Bingo App', 'web_app' => ['url' => 'https://lali-bingo-bot.onrender.com/index.html']]]
            ]
        ];
        sendMessage($chatId, "Tap the button below to open your Lali Bingo game app and choose a table:", $webAppKeyboard);
    }
    elseif (strpos($text, 'Balance') !== false) {
        $stmt = $pdo->prepare("SELECT balance FROM players WHERE telegram_id = ?");
        $stmt->execute([$telegramId]);
        $player = $stmt->fetch(PDO::FETCH_ASSOC);
        $bal = number_format((float)($player['balance'] ?? 0.00), 2);
        sendMessage($chatId, "💰 Your current balance is: <b>{$bal} ETB</b>");
    }
    elseif (strpos($text, 'Deposit') !== false) {
        $depositText = "💳 <b>Deposit Funds & Top-Up</b>\n\n" .
                       "To top up your Lali Bingo wallet, transfer funds to our accounts below and send your transaction receipt along with your ID (<code>{$telegramId}</code>) to support (@Lali_Support):\n\n" .
                       "🏦 <b>Commercial Bank of Ethiopia (CBE)</b>\n" .
                       "• Account Number: <code>100017461538</code>\n" .
                       "• Account Name: Lalisa Shafe Nagari\n\n" .
                       "📱 <b>Telebirr Account</b>\n" .
                       "• Phone / Wallet: <code>0917461538</code>\n" .
                       "• Account Name: Lalisa Shafe Nagari\n\n" .
                       "<i>After transferring, send the screenshot receipt to support to credit your balance!</i>";
        sendMessage($chatId, $depositText);
    }
    elseif (strpos($text, 'Withdraw') !== false) {
        $withdrawText = "💸 <b>Withdraw Funds</b>\n\n" .
                        "To cash out your winnings:\n" .
                        "1. Open the <b>🎮 Play Bingo</b> WebApp.\n" .
                        "2. Go to the <b>💰 Wallet</b> tab at the bottom.\n" .
                        "3. Tap <b>💸 Request Withdrawal</b>, enter your amount, and provide your Telebirr or CBE account details.\n\n" .
                        "<i>Admin processes withdrawal requests promptly to your specified account!</i>";
        sendMessage($chatId, $withdrawText);
    }
    elseif (strpos($text, 'Invite Friends') !== false) {
        sendMessage($chatId, "📤 Share your referral link with friends and win together!\n\nhttps://t.me/Lali_Bingo_bot");
    }
    elseif (strpos($text, 'Instruction') !== false) {
        sendMessage($chatId, "📖 <b>How to Play:</b>\n1. Fund your wallet via <b>💳 Deposit</b>.\n2. Tap <b>🎮 Play Bingo</b> and pick a table stake (10, 20, 50, or 100 ETB).\n3. Numbers are daubed automatically as balls are called.\n4. Complete a line and tap <b>CLAIM BINGO</b> to win 80% of all stakes!");
    }
    elseif (strpos($text, 'Support') !== false) {
        sendMessage($chatId, "📞 For any deposit or withdrawal issues, contact our support team: @Lali_Support");
    }
}
?>
