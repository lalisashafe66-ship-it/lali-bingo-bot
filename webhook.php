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
    $dbPath = __DIR__ . '/bingo.db';
    $pdo = new PDO("sqlite:" . $dbPath);
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
    if (stripos($smsText, 'received') !== false || stripos($smsText, 'credited') !== false || stripos($smsText, 'ETB') !== false || stripos($smsText, 'Birr') !== false) {
        
        // Extract Amount (Matches both "100.00 ETB", "100 ETB", or "Birr 100.00")
        $amount = 0.0;
        if (preg_match('/(?:[\d,]+\.?\d*)\s*(?:ETB|Birr)|(?:Birr|ETB)\s*([\d,]+\.?\d*)/i', $smsText, $matches)) {
            // Find which group matched the number
            $rawAmount = !empty($matches[1]) ? $matches[1] : $matches[0];
            $amount = (float)str_replace([',', 'ETB', 'Birr', ' '], '', $rawAmount);
        }

        // Fallback amount search: look for any number with decimals if above regex misses
        if ($amount <= 0 && preg_match('/\b(\d{2,6}\.\d{2})\b/', $smsText, $numMatches)) {
            $amount = (float)$numMatches[1];
        }

        // Extract Transaction Number (e.g. DKG49EJKIE or similar reference)
        $txRef = '';
        if (preg_match('/(?:transaction number is|reference number is|Ref No[:\s]*)([A-Z0-9]+)/i', $smsText, $refMatches)) {
            $txRef = trim($refMatches[1]);
        } else {
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
            // Insert or replace so if an amount updates, it records correctly
            $stmt = $pdo->prepare("INSERT OR REPLACE INTO transactions (tx_reference, amount, source, status) VALUES (?, ?, 'Telebirr_SMS', 'unclaimed')");
            $stmt->execute([$txRef, $amount]);
        }
    }

    echo json_encode(['success' => true, 'message' => 'SMS processed successfully']);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>
