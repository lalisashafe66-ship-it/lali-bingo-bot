<?php
// webhook.php - Securely parses Telebirr & CBE SMS from Android SMS Forwarder
header('Content-Type: application/json');

// 1. Security Check: Set SMS_WEBHOOK_SECRET in Render Environment Variables
$SECRET_KEY = getenv('SMS_WEBHOOK_SECRET') ?: 'LaliBingoSecret2026';
$providedSecret = $_GET['secret'] ?? $_SERVER['HTTP_X_WEBHOOK_SECRET'] ?? '';

if ($providedSecret !== $SECRET_KEY) {
    http_response_code(403);
    echo json_encode(["status" => "error", "message" => "Unauthorized webhook request"]);
    exit;
}

$rawInput = file_get_contents('php://input');
$data = json_decode($rawInput, true);

$smsText = $data['text'] ?? $data['msg'] ?? $_POST['text'] ?? $_GET['text'] ?? '';
$sender  = strtoupper(trim($data['from'] ?? $data['sender'] ?? $_POST['from'] ?? ''));

if (empty($smsText)) {
    echo json_encode(["status" => "error", "message" => "Empty SMS payload"]);
    exit;
}

if (preg_match('/(debited|transferred|sent|paid|ወጪ|ተቀንሷል)/i', $smsText) && !preg_match('/(credited|received|ገቢ)/i', $smsText)) {
    echo json_encode(["status" => "ignored", "message" => "Outgoing transaction ignored"]);
    exit;
}

try {
    $pdo = new PDO("sqlite:" . __DIR__ . "/bingo.db");
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $pdo->exec("CREATE TABLE IF NOT EXISTS transactions (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        telegram_id TEXT,
        tx_reference TEXT UNIQUE,
        amount REAL,
        source TEXT,
        status TEXT DEFAULT 'unclaimed',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    $txRef = '';
    $amount = 0.0;
    $source = 'UNKNOWN';

    // Parse Telebirr Credit SMS
    if (stripos($smsText, 'telebirr') !== false || $sender === '127' || stripos($smsText, 'transactioninfo.ethiotelecom.et') !== false) {
        $source = 'TELEBIRR';
        if (preg_match('/(?:ETB|Birr|ብር)\s*([0-9,]+(?:\.[0-9]+)?)|([0-9,]+(?:\.[0-9]+)?)\s*(?:ETB|Birr|ብር)/i', $smsText, $amtMatch)) {
            $rawAmt = !empty($amtMatch[1]) ? $amtMatch[1] : $amtMatch[2];
            $amount = (float)str_replace(',', '', $rawAmt);
        }
        if (preg_match('/receipt\/([A-Z0-9]{8,15})/i', $smsText, $refMatch)) {
            $txRef = strtoupper($refMatch[1]);
        } elseif (preg_match('/(?:transaction number is|ቁጥርዎ|ID[:\s]*)\s*([A-Z0-9]{8,15})/i', $smsText, $refMatch)) {
            $txRef = strtoupper($refMatch[1]);
        }
    }
    // Parse CBE Credit SMS
    elseif (stripos($smsText, 'CBE') !== false || stripos($smsText, 'credited') !== false || preg_match('/FT[A-Z0-9]{8,12}/', $smsText)) {
        $source = 'CBE';
        if (preg_match('/credited\s+with\s+(?:ETB|Br)?\s*([0-9,]+(?:\.[0-9]+)?)/i', $smsText, $amtMatch)) {
            $amount = (float)str_replace(',', '', $amtMatch[1]);
        }
        if (preg_match('/(FT[A-Z0-9]{8,14})/i', $smsText, $refMatch)) {
            $txRef = strtoupper($refMatch[1]);
        }
    }

    if (!empty($txRef) && $amount >= 10) {
        $stmt = $pdo->prepare("INSERT OR IGNORE INTO transactions (tx_reference, amount, source, status) VALUES (?, ?, ?, 'unclaimed')");
        $stmt->execute([$txRef, $amount, $source]);

        echo json_encode(["status" => "success", "source" => $source, "tx_ref" => $txRef, "amount" => $amount]);
        exit;
    }

    echo json_encode(["status" => "ignored", "message" => "Could not match valid credit SMS format"]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
?>
