<?php
header('Content-Type: application/json');

$telegramId = $_POST['telegram_id'] ?? '';
$amount = (float)($_POST['amount'] ?? 0);
$firstName = $_POST['first_name'] ?? 'Player';

if (!$telegramId || $amount < 10) {
    echo json_encode(['success' => false, 'error' => 'Invalid amount or player ID. Minimum deposit is 10 ETB.']);
    exit;
}

$chapaSecretKey = getenv('CHAPA_SECRET_KEY') ?: 'CHASECK_TEST-XXXXXXXXXXXXXXXXXXXXXXXXX'; 
$txRef = "future_bingo_" . uniqid();

$callbackUrl = "https://lali-bingo-bot.onrender.com/verify_payment.php?telegram_id=" . urlencode($telegramId) . "&amount=" . $amount;
$returnUrl = "https://lali-bingo-bot.onrender.com/index.html";

$data = [
    'amount' => $amount,
    'currency' => 'ETB',
    'email' => 'player_' . $telegramId . '@gmail.com', // Valid email format for Chapa
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
    echo json_encode(['success' => false, 'error' => 'Gateway connection error: ' . $err]);
    exit;
}

$result = json_decode($response, true);

if (isset($result['status']) && $result['status'] === 'success') {
    echo json_encode([
        'success' => true,
        'checkout_url' => $result['data']['checkout_url']
    ]);
} else {
    $errorMsg = $result['message'] ?? 'Could not initialize Chapa payment.';
    if (is_array($errorMsg)) {
        $errorMsg = json_encode($errorMsg);
    }
    echo json_encode([
        'success' => false,
        'error' => $errorMsg
    ]);
}
?>
