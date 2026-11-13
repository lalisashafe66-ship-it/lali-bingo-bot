<?php
// Lali Bingo Telegram Bot Backend (bot.php)
$content = file_get_contents("php://input");
$update = json_decode($content, true);

if (!$update) {
    exit;
}

$chatId = $update['message']['chat']['id'] ?? $update['callback_query']['message']['chat']['id'] ?? null;
$messageText = $update['message']['text'] ?? '';
$user = $update['message']['from'] ?? null;
$contact = $update['message']['contact'] ?? null;

if (!$chatId) {
    exit;
}

// Bot Token & WebApp URL (Update your WebApp URL to your live domain)
$botToken = "YOUR_TELEGRAM_BOT_TOKEN"; 
$webAppUrl = "https://lalishop.whf.bz/index.html"; // Or your Render/InfinityFree URL

// Connect to SQLite Database
try {
    $pdo = new PDO("sqlite:" . __DIR__ . "/bingo.db");
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $pdo->exec("CREATE TABLE IF NOT EXISTS players (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        telegram_id TEXT UNIQUE,
        first_name TEXT,
        phone TEXT,
        balance REAL DEFAULT 100.00,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
} catch (PDOException $e) {
    exit;
}

// Helper function to send Telegram messages with keyboard buttons
function sendMessage($chatId, $text, $replyMarkup = null) {
    global $botToken;
    $url = "https://api.telegram.org/bot{$botToken}/sendMessage";
    $data = [
        'chat_id' => $chatId,
        'text' => $text,
        'parse_mode' => 'Markdown'
    ];
    if ($replyMarkup) {
        $data['reply_markup'] = json_encode($replyMarkup);
    }
    file_get_contents($url . '?' . http_build_query($data));
}

// Handle Contact Sharing (Registration)
if ($contact) {
    $telegramId = (string)$contact['user_id'];
    $phone = $contact['phone_number'];
    $firstName = $contact['first_name'] ?? 'Player';

    $stmt = $pdo->prepare("INSERT OR IGNORE INTO players (telegram_id, first_name, phone, balance) VALUES (?, ?, ?, 100.00)");
    $stmt->execute([$telegramId, $firstName, $phone]);

    $keyboard = [
        'keyboard' => [
            [['text' => '🎮 Play Bingo', 'web_app' => ['url' => $webAppUrl]]],
            [['text' => '💰 Balance'], ['text' => 'ℹ️ Instruction']]
        ],
        'resize_keyboard' => true,
        'is_persistent' => true
    ];

    sendMessage($chatId, "✅ Player registered successfully! You received a 100 ETB starter bonus.\n\nWelcome to Lali Bingo! Tap 🎮 Play Bingo below to start winning.", $keyboard);
    exit;
}

// Handle Commands and Text Messages
if ($messageText === '/start' || strpos($messageText, 'Start') !== false) {
    // Check if player exists
    $stmt = $pdo->prepare("SELECT * FROM players WHERE telegram_id = ?");
    $stmt->execute([(string)$user['id']]);
    $player = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$player) {
        // Request phone number for registration
        $keyboard = [
            'keyboard' => [
                [['text' => '📱 Share contact', 'request_contact' => true]]
            ],
            'resize_keyboard' => true,
            'one_time_keyboard' => true
        ];
        sendMessage($chatId, "🎲 Welcome to Lali Bingo!\n\nTo finish registration and secure your wallet, tap 📱 *Share contact* below.", $keyboard);
    } else {
        $keyboard = [
            'keyboard' => [
                [['text' => '🎮 Play Bingo', 'web_app' => ['url' => $webAppUrl]]],
                [['text' => '💰 Balance'], ['text' => 'ℹ️ Instruction']]
            ],
            'resize_keyboard' => true,
            'is_persistent' => true
        ];
        sendMessage($chatId, "Welcome back to Lali Bingo! Tap 🎮 Play Bingo below to start winning.", $keyboard);
    }
}
elseif ($messageText === '💰 Balance') {
    $stmt = $pdo->prepare("SELECT balance FROM players WHERE telegram_id = ?");
    $stmt->execute([(string)$user['id']]);
    $player = $stmt->fetch(PDO::FETCH_ASSOC);
    $bal = $player ? (float)$player['balance'] : 0.00;
    
    sendMessage($chatId, "💰 Your current balance is: *{$bal} ETB*");
}
elseif ($messageText === 'ℹ️ Instruction') {
    $instructions = "📖 *How to Play:*\n" .
                    "1. Join an active room or start a game (Stakes 10 - 100 ETB).\n" .
                    "2. Buy your card and watch the auto-dauber.\n" .
                    "3. Mark called numbers on your card.\n" .
                    "4. Complete a row, column, or diagonal and click *CLAIM BINGO* to win the prize pool!";
    sendMessage($chatId, $instructions);
}
?>
