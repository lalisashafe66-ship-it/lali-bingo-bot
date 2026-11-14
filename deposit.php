<?php
// deposit.php - Initiates payment with Chapa
header('Content-Type: application/json');

// =========================================================================
// 🔑 PASTE YOUR CHAPA SECRET KEY HERE (e.g. "CHASECK_TEST-xxxxxxxx...")
// =========================================================================
$CHAPA_SECRET_KEY = getenv('CHAPA_SECRET_KEY') ?: 'CHASECK_TEST-PASTE_YOUR_KEY_HERE'; 

$telegram_id = $_POST['telegram_id'] ?? '';
$amount = floatval($_POST['amount'] ?? 0);
$email = $_POST['email'] ?? 'player@lalibingo.et';
$first_name = $_POST['first_name'] ?? 'Player';

if (empty($telegram_id) || $amount <= 0) {
    echo json_encode(['success' => false, 'error' => 'Invalid parameters.']);
    exit;
}

$tx_ref = "lali-bingo-" . $telegram_id . "-" . time();

$data = [
    'amount' => $amount,
    'currency' => 'ETB',
    'email' => $email,
    'first_name' => $first_name,
    'tx_ref' => $tx_ref,
    'callback_url' => 'https://lali-bingo-bot.onrender.com/webhook.php',
    'return_url' => 'https://t.me/Lali_Bingo_bot'
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
