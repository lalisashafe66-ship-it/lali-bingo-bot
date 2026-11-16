<?php
// webhook.php - Receives incoming SMS notifications from Android SMS Forwarder
header('Content-Type: application/json');

// Verify secret token to block unauthorized posts
$secret = $_GET['secret'] ?? '';
if ($secret !== 'LaliBingoSecret2026') {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$smsText = $input['message'] ?? $input['text'] ?? $_POST['message'] ?? $_POST['text'] ?? '';
$sender = $input['sender'] ?? $_POST['sender'] ?? '';

if (empty($smsText)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Empty message content']);
    exit;
}

try {
    $pdo = new PDO("sqlite:" . __DIR__ . "/bingo.db");
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Ensure transactions table exists
    $pdo->exec("CREATE TABLE IF NOT EXISTS transactions (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        telegram_id TEXT,
        tx_reference TEXT UNIQUE,
        amount REAL,
        source TEXT,
        status TEXT DEFAULT 'unclaimed',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    // Check if this is an incoming credit SMS (Telebirr or Bank deposit)
    // Looking for phrases like "received ETB" or "credited"
    if (stripos($smsText, 'received ETB') !== false || stripos($smsText, 'credited') !== false) {
        
        // Extract Amount
        $amount = 0.0;
        if (preg_match('/(?:ETB|Birr)\s*([\d,]+\.?\d*)/i', $smsText, $matches)) {
            $amount = (float)str_replace(',', '', $matches[1]);
        }

        // Extract Transaction Number (e.g. DKG49EJKIE or similar reference)
        $txRef = '';
        if (preg_match('/(?:transaction number is|reference number is|Ref No[:\s]*)([A-Z0-9]+)/i', $smsText, $refMatches)) {
            $txRef = trim($refMatches[1]);
        } else {
            // Fallback: look for uppercase alphanumeric code after words like 'is' or 'No'
            $words = explode(' ', $smsText);
            foreach ($words as $i => $w) {
                if ((strtolower($w) === 'is' || strtolower($w) === 'number') && isset($words[$i+1])) {
                    $cleanCode = preg_replace('/[^A-Z0-9]/', '', $words[$i+1]);
                    if (strlen($cleanCode) >= 6) {
                        $txRef = $cleanCode;
                        break;
                    }
                }
            }
        }

        // If regex didn't catch the exact ref, grab the last large alphanumeric token as fallback
        if (empty($txRef)) {
            preg_match_all('/\b[A-Z0-9]{8,12}\b/', $smsText, $allTokens);
            if (!empty($allTokens[0])) {
                $txRef = end($allTokens[0]);
            }
        }

        if ($amount > 0 && !empty($txRef)) {
            $stmt = $pdo->prepare("INSERT OR IGNORE INTO transactions (tx_reference, amount, source, status) VALUES (?, ?, 'Telebirr_SMS', 'unclaimed')");
            $stmt->execute([$txRef, $amount]);
        }
    }

    echo json_encode(['success' => true, 'message' => 'SMS processed successfully']);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>
