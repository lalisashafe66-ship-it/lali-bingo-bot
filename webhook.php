<?php
// webhook.php - Receives incoming SMS notifications from Android SMS Forwarder
header('Content-Type: application/json');

// Read JSON payload if sent as raw JSON
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

// Extract SMS message body from whichever key the Android app sends
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

    // Process only deposit/transfer messages containing currency or credit keywords
    if (
        stripos($smsText, 'received') !== false || 
        stripos($smsText, 'credited') !== false || 
        stripos($smsText, 'ETB') !== false || 
        stripos($smsText, 'Birr') !== false
    ) {
        // 1. Extract exact amount
        $amount = 0.0;
        if (preg_match('/([0-9]{1,3}(?:,\d{3})*(?:\.\d+)?|[0-9]+(?:\.\d+)?)\s*(?:ETB|Birr)/i', $smsText, $m)) {
            $amount = (float)str_replace(',', '', $m[1]);
        } elseif (preg_match('/(?:Birr|ETB)\s*([0-9]{1,3}(?:,\d{3})*(?:\.\d+)?|[0-9]+(?:\.\d+)?)/i', $smsText, $m)) {
            $amount = (float)str_replace(',', '', $m[1]);
        } elseif (preg_match('/\b(\d{2,6}\.\d{2})\b/', $smsText, $m)) {
            $amount = (float)$m[1];
        }

        // 2. Extract Transaction Reference ID
        $txRef = '';
        if (preg_match('/(?:transaction number is|reference number is|Ref No[:\s]*|transaction ID[:\s]*)([A-Z0-9]+)/i', $smsText, $refMatches)) {
            $txRef = trim($refMatches[1]);
        } else {
            $words = explode(' ', $smsText);
            foreach ($words as $i => $w) {
                if ((strtolower($w) === 'is' || strtolower($w) === 'number') && isset($words[$i + 1])) {
                    $cleanCode = preg_replace('/[^A-Z0-9]/', '', $words[$i + 1]);
                    if (strlen($cleanCode) >= 6) {
                        $txRef = $cleanCode;
                        break;
                    }
                }
            }
        }

        // Fallback: look for standard 8-12 character alphanumeric Telebirr/CBE reference tokens
        if (empty($txRef)) {
            preg_match_all('/\b[A-Z0-9]{8,12}\b/', $smsText, $allTokens);
            if (!empty($allTokens[0])) {
                $txRef = end($allTokens[0]);
            }
        }

        // 3. Save or safely update the transaction in SQLite without overwriting telegram_id
        if ($amount > 0 && !empty($txRef)) {
            $checkStmt = $pdo->prepare("SELECT id, telegram_id FROM transactions WHERE tx_reference = ?");
            $checkStmt->execute([$txRef]);
            $existing = $checkStmt->fetch(PDO::FETCH_ASSOC);

            if ($existing) {
                $updateStmt = $pdo->prepare("UPDATE transactions SET amount = ?, source = 'Telebirr_SMS' WHERE tx_reference = ?");
                $updateStmt->execute([$amount, $txRef]);
            } else {
                $insertStmt = $pdo->prepare("INSERT INTO transactions (tx_reference, amount, source, status) VALUES (?, ?, 'Telebirr_SMS', 'unclaimed')");
                $insertStmt->execute([$txRef, $amount]);
            }

            echo json_encode([
                'success' => true,
                'message' => 'Transaction recorded',
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
