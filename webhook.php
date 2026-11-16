<?php
// webhook.php - Receives forwarded bank/Telebirr SMS or transaction logs
header('Content-Type: application/json');

$input = file_get_contents("php://input");
$data = json_decode($input, true);

$sms_text = $data['text'] ?? $_GET['text'] ?? '';
$tx_ref = $data['tx_ref'] ?? $_GET['tx_ref'] ?? null;
$amount = (float)($data['amount'] ?? $_GET['amount'] ?? 0);

try {
    $pdo = new PDO("sqlite:" . __DIR__ . "/bingo.db");
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $pdo->exec("CREATE TABLE IF NOT EXISTS transactions (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        telegram_id TEXT,
        tx_reference TEXT UNIQUE,
        amount REAL,
        status TEXT DEFAULT 'unclaimed',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    // If an SMS forwarder sends raw message text, parse Transaction ID & Amount automatically
    if (!empty($sms_text)) {
        if (preg_match('/([A-Z0-9]{8,12})/', $sms_text, $matches)) {
            $tx_ref = $matches[1];
            preg_match('/([0-9]+(?:\.[0-9]+)?)\s*(?:ETB|Br)/i', $sms_text, $amount_matches);
            $amount = $amount_matches[1] ?? 0;
        }
    }

    if ($tx_ref && $amount > 0) {
        $stmt = $pdo->prepare("INSERT OR IGNORE INTO transactions (tx_reference, amount, status) VALUES (?, ?, 'unclaimed')");
        $stmt->execute([$tx_ref, $amount]);

        echo json_encode(["status" => "success", "message" => "Transaction logged successfully"]);
        exit;
    }

    echo json_encode(["status" => "error", "message" => "Invalid data or SMS format"]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
?>
