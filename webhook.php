<?php
// webhook.php - Receives payment confirmation from Chapa and updates SQLite
$input = @file_get_contents("php://input");
$event = json_decode($input, true);

// Alternatively, Chapa sends GET request verification or POST webhook event
$tx_ref = $_GET['tx_ref'] ?? $event['tx_ref'] ?? null;

if (!$tx_ref) {
    exit;
}

// Extract telegram_id from tx_ref format: lali-bingo-{telegram_id}-{timestamp}
$parts = explode('-', $tx_ref);
if (count($parts) < 3) {
    exit;
}
$telegram_id = $parts[2];

$CHAPA_SECRET_KEY = "CHASECK_TEST-xxxxxxxxxxxxxxxxxxxxx"; // Replace with your Chapa Secret Key

// Verify transaction with Chapa API to prevent fake webhooks
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, "https://api.chapa.co/v1/transaction/verify/" . urlencode($tx_ref));
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    "Authorization: Bearer " . $CHAPA_SECRET_KEY
]);

$response = curl_exec($ch);
curl_close($ch);
$res = json_decode($response, true);

if (isset($res['status']) && $res['status'] === 'success') {
    $amount_paid = floatval($res['data']['amount']);

    try {
        $pdo = new PDO("sqlite:" . __DIR__ . "/bingo.db");
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        // Update player balance in SQLite
        $stmt = $pdo->prepare("UPDATE players SET balance = balance + ? WHERE telegram_id = ?");
        $stmt->execute([$amount_paid, $telegram_id]);

        // Fetch updated balance & name
        $stmt = $pdo->prepare("SELECT balance, first_name FROM players WHERE telegram_id = ?");
        $stmt->execute([$telegram_id]);
        $player = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($player) {
            $newBal = $player['balance'];
            $token = "9251047882:AAFbbYkqcqfPvhkXtK5cfTgm1Z5xghmqaq0";
            
            // Send instant Telegram notification to the player
            $msg = "🎉 <b>Automated Deposit Confirmed!</b>\n\n" .
                   "<b>{$amount_paid} ETB</b> has been successfully added to your Lali Bingo wallet.\n" .
                   "New Balance: <b>{$newBal} ETB</b>\n\n" .
                   "You can now play instantly!";
            
            @file_get_contents("https://api.telegram.org/bot{$token}/sendMessage?" . http_build_query([
                'chat_id' => $telegram_id,
                'text' => $msg,
                'parse_mode' => 'HTML'
            ]));
        }
    } catch (Exception $e) {
        // Log error if needed
    }
}
http_response_code(200);
?>
