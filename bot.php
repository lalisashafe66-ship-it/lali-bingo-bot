<?php
// Enable error logging for debugging
ini_set('display_errors', 0);
error_reporting(E_ALL);

$content = file_get_contents("php://input");
$update = json_decode($content, true);

if (!$update || !isset($update['message'])) {
    exit;
}

$chatId = $update['message']['chat']['id'] ?? null;
$text = trim($update['message']['text'] ?? '');
$contact = $update['message']['contact'] ?? null;
$firstName = $update['message']['from']['first_name'] ?? 'Player';
$telegramId = (string)($update['message']['from']['id'] ?? '');

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
    @file_get_contents($url . '?' . http_build_query($data));
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
    elseif ($text === '/start' || stripos($text, 'start') !== false) {
        $stmt = $pdo->prepare("SELECT * FROM players WHERE telegram_id = ?");
        $stmt->execute([$telegramId]);
        $player = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($player && !empty($player['phone'])) {
            sendMessage($chatId, "👋 Welcome back, <b>{$firstName}</b>!\nChoose an option from the menu below:", $mainMenu);
        } else {
            $contactKeyboard = [
                'keyboard' => [
                    [['text' => '📱 Share contact', 'request_contact' => true]]
                ],
                'resize_keyboard' => true,
                'one_time_keyboard' => true
            ];
            sendMessage($chatId, "🎲 <b>Welcome to Lali Bingo!</b>\n\nTo finish registration, tap <b>\"📱 Share contact\"</b> below.", $contactKeyboard);
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
                [['text' => '🚀 Launch Lali Bingo App', 'web_app' => ['url' => 'https://lali-bingo-bot.onrender.com/index.html']]]
            ]
        ];
        sendMessage($chatId, "Tap the button below to open your Lali Bingo game app and choose a table:", $webAppKeyboard);
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
                       "Transfer funds to our accounts below and send your transaction receipt along with your ID (<code>{$telegramId}</code>) to support (@Lali_Support):\n\n" .
                       "🏦 <b>CBE:</b> <code>100017461538</code> (Lalisa Shafe)\n" .
                       "📱 <b>Telebirr:</b> <code>0917461538</code> (Lalisa Shafe)";
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
        sendMessage($chatId, "📤 Share your referral link with friends:\n\nhttps://t.me/Lali_Bingo_bot", $mainMenu);
    }
    elseif (stripos($text, 'Instruction') !== false) {
        sendMessage($chatId, "📖 <b>How to Play:</b>\n1. Fund your wallet via <b>💳 Deposit</b>.\n2. Tap <b>🎮 Play Bingo</b> and pick a table stake (10, 20, 50, or 100 ETB).\n3. Complete a line and tap <b>CLAIM BINGO</b> to win 80% of all stakes!", $mainMenu);
    }
    elseif (stripos($text, 'Support') !== false) {
        sendMessage($chatId, "📞 Contact our support team: @Lali_Support", $mainMenu);
    }
}
?>
