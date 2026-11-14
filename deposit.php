<?php
header('Content-Type: application/json');

// Get POST data
$telegramId = $_POST['telegram_id'] ?? '';
$amount = (float)($_POST['amount'] ?? 0);
$firstName = $_POST['first_name'] ?? 'Player';

if (!$telegramId || $amount < 10) {
    echo json_encode(['success' => false, 'error' => 'Invalid amount or player ID. Minimum deposit is 10 ETB.']);
    exit;
}

// Chapa API Configuration (Replace with your live Chapa Secret Key if needed)
$chapaSecretKey = getenv('CHAPA_SECRET_KEY') ?: 'CHASECK_TEST-qWVM5FTl9ev2UlTfCN0sI9OGlAzUVw9g'; 
$txRef = "future_bingo_" . uniqid();

$callbackUrl = "https://lali-bingo-bot.onrender.com/verify_payment.php?telegram_id=" . urlencode($telegramId) . "&amount=" . $amount;
$returnUrl = "https://lali-bingo-bot.onrender.com/index.html";

$data = [
    'amount' => $amount,
    'currency' => 'ETB',
    'email' => 'player_' . $telegramId . '@futurebingo.et',
    'first_name' => $firstName,
    'last_name' => 'Player',
    'tx_ref' => $txRef,
    'callback_url' => $callbackUrl,
    'return_url' => $returnUrl,
    'customization[title]' => 'Future Bingo Wallet Top-Up',
    'customization[description]' => 'Deposit funds to Future Bingo wallet'
];

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, "https://api.chapa.co/v1/transaction/initialize");
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    "Authorization: Bearer " . $chapaSecretKey,
    "Content-Type: application/json"
]);

$response = curl_exec($ch);
$err = curl_error($ch);
curl_close($ch);

if ($err) {
    echo json_encode(['success' => false, 'error' => 'Gateway connection error.']);
    exit;
}

$result = json_decode($response, true);

if (isset($result['status']) && $result['status'] === 'success') {
    echo json_encode([
        'success' => true,
        'checkout_url' => $result['data']['checkout_url']
    ]);
} else {
    echo json_encode([
        'success' => false,
        'error' => $result['message'] ?? 'Could not initialize Chapa payment.'
    ]);
}
?>
