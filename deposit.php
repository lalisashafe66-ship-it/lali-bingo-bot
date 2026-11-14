<?php
// deposit.php - Initiates payment with Chapa
header('Content-Type: application/json');

// =========================================================================
// 🔑 CHAPA SECRET KEY (Loaded from Render Environment or fallback below)
// =========================================================================
$CHAPA_SECRET_KEY = getenv('CHAPA_SECRET_KEY') ?: 'CHASECK_TEST-qWVM5FTl9ev2UlTfCN0sI9OGlAzUVw9g'; 

$telegram_id = $_POST['telegram_id'] ?? '';
$amount = floatval($_POST['amount'] ?? 0);
$email = $_POST['email'] ?? 'player@futurebingo.et';
$first_name = $_POST['first_name'] ?? 'Player';

if (empty($telegram_id) || $amount <= 0) {
    echo json_encode(['success' => false, 'error' => 'Invalid parameters.']);
    exit;
}

$tx_ref = "future-bingo-" . $telegram_id . "-" . time();

$data = [
    'amount' => $amount,
    'currency' => 'ETB',
    'email' => $email,
    'first_name' => $first_name,
    'tx_ref' => $tx_ref,
    'callback_url' => 'https://lali-bingo-bot.onrender.com/webhook.php',
    'return_url' => 'https://t.me/Future_Bingo_bot'
];

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, "https://api.chapa.co/v1/transaction/initialize");
curl_setopt($ch, CURLOPT_POST, 1);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    "Authorization: Bearer " . $CHAPA_SECRET_KEY,
    "Content-Type: application/json"
]);

$response = curl_exec($ch);
curl_close($ch);

$res = json_decode($response, true);

if (isset($res['status']) && $res['status'] === 'success') {
    echo json_encode(['success' => true, 'checkout_url' => $res['data']['checkout_url']]);
} else {
    echo json_encode(['success' => false, 'error' => 'Could not initialize payment.']);
}
?>
