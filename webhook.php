<?php
// webhook.php - Receives incoming SMS notifications from Android SMS Forwarder
header('Content-Type: application/json');

// Read raw JSON payload if sent
$rawInput = file_get_contents('php://input');
$input = json_decode($rawInput, true) ?? [];

// Accept secret token from URL query (?secret=), POST form, JSON body, or custom header
$secret = $_GET['secret'] 
    ?? $_POST['secret'] 
    ?? $input['secret'] 
    ?? $_SERVER['HTTP_X_SECRET'] 
    ?? '';

if (trim($secret) !== 'LaliBingoSecret2026') {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

// Extract SMS message body from whichever key the Android app uses
$smsText = $input['message'] 
    ?? $input['text'] 
    ?? $input['content'] 
    ?? $_POST['message'] 
    ?? $_POST['text'] 
    ?? $_POST['content'] 
    ?? '';

if (empty($smsText) && !empty($rawInput) && empty($input)) {
    $smsText = $rawInput;
}

if (empty(trim($smsText))) {
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

    // Process messages related to deposits, receipts, transfers, or currency values
    if (
        stripos($smsText, 'received') !== false || 
        stripos($smsText, 'credited') !== false || 
        stripos($smsText, 'transferred') !== false || 
        stripos($smsText, 'deposited') !== false || 
        stripos($smsText, 'ETB') !== false || 
        stripos($smsText, 'Birr') !== false
    ) {
        // 1. Extract exact amount (ETB / Birr)
        $amount = 0.0;
        if (preg_match('/([0-9]{1,3}(?:,\d{3})*(?:\.\d+)?|[0-9]+(?:\.\d+)?)\s*(?:ETB|Birr)/i', $smsText, $m)) {
            $amount = (float)str_replace(',', '', $m[1]);
        } elseif (preg_match('/(?:Birr|ETB)\s*([0-9]{1,3}(?:,\d{3})*(?:\.\d+)?|[0-9]+(?:\.\d+)?)/i', $smsText, $m)) {
            $amount = (float)str_replace(',', '', $m[1]);
        } elseif (preg_match('/\b(\d{2,6}\.\d{2})\b/', $smsText, $m)) {
            $amount = (float)$m[1];
        }

        // 2. Extract Transaction Reference ID (supporting Telebirr and bank-to-wallet transfer formats)
        $txRef = '';
        if (preg_match('/(?:transaction number is|reference number is|Ref No[:\s]*|transaction ID[:\s]*|Txn ID[:\s]*|Reference[:\s]*)([A-Z0-9]+)/i', $smsText, $refMatches)) {
            $txRef = trim($refMatches[1]);
        } else {
            // Fallback: extract the longest alphanumeric token which represents the transaction reference
            preg_match_all('/\b[A-Z0-9]{8,14}\b/', $smsText, $allTokens);
            if (!empty($allTokens[0])) {
                $longestToken = '';
                foreach ($allTokens[0] as $token) {
                    if (strlen($token) > strlen($longestToken)) {
                        $longestToken = $token;
                    }
                }
                $txRef = $longestToken;
            }
        }

        // 3. Save or update transaction record in SQLite
        if ($amount > 0 && !empty($txRef)) {
            $checkStmt = $pdo->prepare("SELECT id, telegram_id FROM transactions WHERE tx_reference = ?");
            $checkStmt->execute([$txRef]);
            $existing = $checkStmt->fetch(PDO::FETCH_ASSOC);

            if ($existing) {
                $updateStmt = $pdo->prepare("UPDATE transactions SET amount = ?, source = 'Bank_Telebirr_SMS' WHERE tx_reference = ?");
                $updateStmt->execute([$amount, $txRef]);
            } else {
                $insertStmt = $pdo->prepare("INSERT INTO transactions (tx_reference, amount, source, status) VALUES (?, ?, 'Bank_Telebirr_SMS', 'unclaimed')");
                $insertStmt->execute([$txRef, $amount]);
            }

            echo json_encode([
                'success' => true,
                'message' => 'Transaction recorded successfully',
                'tx_reference' => $txRef,
                'amount' => $amount
            ]);
            exit;
        }
    }

    echo json_encode(['success' => true, 'message' => 'SMS received (no deposit matched)']);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>
