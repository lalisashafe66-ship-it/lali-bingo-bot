<?php
// SQLite database (stored locally on Render with zero setup)
$pdo = new PDO("sqlite:" . __DIR__ . "/bingo.db");
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Ensure players table exists
$pdo->exec("CREATE TABLE IF NOT EXISTS players (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    telegram_id TEXT UNIQUE,
    first_name TEXT,
    phone TEXT,
    balance REAL DEFAULT 100.00,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
)");

// Get incoming Telegram update
$content = file_get_contents("php://input");
$update = json_decode($content, true);

if (!$update) exit;

$chatId = $update['message']['chat']['id'] ?? null;
$text = $update['message']['text'] ?? '';
$contact = $update['message']['contact'] ?? null;
$firstName = $update['message']['from']['first_name'] ?? 'Player';
$telegramId = $update['message']['from']['id'] ?? null;

// Bot Token
$token = "9251047882:AAFbbYkqcqfPvhkXtK5cfTgm1Z5xghmqaq0";

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
    file_get_contents($url . '?' . http_build_query($data));
}

if ($chatId) {
    if ($contact) {
        $phone = $contact['phone_number'];
        $stmt = $pdo->prepare("INSERT INTO players (telegram_id, first_name, phone) VALUES (?, ?, ?) ON CONFLICT(telegram_id) DO UPDATE SET phone = excluded.phone");
        $stmt->execute([$telegramId, $firstName, $phone]);

        $mainMenu = [
            'keyboard' => [
                [['text' => '🎮 Play Bingo'], ['text' => '💰 Balance']],
                [['text' => '💳 Deposit'], ['text' => '📤 Invite Friends']],
                [['text' => 'ℹ️ Instruction'], ['text' => '📞 Support']]
            ],
            'resize_keyboard' => true
        ];
        sendMessage($chatId, "✅ <b>Player registered successfully!</b>\n\nWelcome to Lali Bingo! Tap <b>🎮 Play Bingo</b> below to start winning.", $mainMenu);
    } 
    elseif ($text === '/start') {
        $stmt = $pdo->prepare("SELECT * FROM players WHERE telegram_id = ?");
        $stmt->execute([$telegramId]);
        $player = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($player && !empty($player['phone'])) {
            $mainMenu = [
                'keyboard' => [
                    [['text' => '🎮 Play Bingo'], ['text' => '💰 Balance']],
                    [['text' => '💳 Deposit'], ['text' => '📤 Invite Friends']],
                    [['text' => 'ℹ️ Instruction'], ['text' => '📞 Support']]
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
    elseif ($text === '🎮 Play Bingo') {
        $webAppKeyboard = [
            'inline_keyboard' => [
                [['text' => '🚀 Launch Lali Bingo App', 'web_app' => ['url' => 'https://lalishop.great-site.net/bingo/']]]
            ]
        ];
        sendMessage($chatId, "Tap the button below to open your Bingo game card and join rooms:", $webAppKeyboard);
    }
    elseif ($text === '💰 Balance') {
        $stmt = $pdo->prepare("SELECT balance FROM players WHERE telegram_id = ?");
        $stmt->execute([$telegramId]);
        $player = $stmt->fetch(PDO::FETCH_ASSOC);
        $bal = $player['balance'] ?? 100.00;
        sendMessage($chatId, "💰 Your current balance is: <b>{$bal} ETB</b>");
    }
    elseif ($text === '💳 Deposit') {
        $depositText = "💳 <b>Deposit Funds & Top-Up</b>\n\n" .
                       "To top up your Lali Bingo wallet, transfer funds to our accounts below and send your transaction receipt to support (@Lali_Support):\n\n" .
                       "🏦 <b>Commercial Bank of Ethiopia (CBE)</b>\n" .
                       "• Account Number: <code>100017461538</code>\n" .
                       "• Account Name: Lalisa Shafe Nagari\n\n" .
                       "📱 <b>Telebirr Account</b>\n" .
                       "• Phone / Wallet: <code>0917461538</code>\n" .
                       "• Account Name: Lalisa Shafe Nagari\n\n" .
                       "<i>After transferring, send the screenshot receipt to support to credit your balance instantly!</i>";
        sendMessage($chatId, $depositText);
    }
    elseif ($text === '📤 Invite Friends') {
        sendMessage($chatId, "📤 Share your referral link with friends and earn bonus cash when they play!\n\nhttps://t.me/Lali_Bingo_bot");
    }
    elseif ($text === 'ℹ️ Instruction') {
        sendMessage($chatId, "📖 <b>How to Play:</b>\n1. Join an active room or start a game.\n2. Buy your card for 10 ETB.\n3. Mark called numbers on your card.\n4. Complete a row, column, or diagonal and click CLAIM BINGO to win the prize pool!");
    }
    elseif ($text === '📞 Support') {
        sendMessage($chatId, "📞 For any deposit or withdrawal issues, contact our support team: @Lali_Support");
    }
}
?>
