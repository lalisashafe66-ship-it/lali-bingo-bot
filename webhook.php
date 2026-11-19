<?php
// webhook.php - Receives incoming SMS notifications from Android SMS Forwarder
header('Content-Type: application/json');

require_once __DIR__ . '/db.php';

$rawInput = file_get_contents('php://input');
$input = json_decode($rawInput, true) ?? [];

$expectedSecret = trim((string)(getenv('SMS_WEBHOOK_SECRET') ?: 'LaliBingoSecret2026'));
$secret = $_GET['secret']
    ?? $_POST['secret']
    ?? $input['secret']
    ?? $_SERVER['HTTP_X_SECRET']
    ?? '';

if (trim((string)$secret) !== $expectedSecret) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

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

if (empty(trim((string)$smsText))) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Empty message content']);
    exit;
}

try {
    $pdo = getBingoPdo();

    $pdo->exec("CREATE TABLE IF NOT EXISTS transactions (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        telegram_id TEXT,
        tx_reference TEXT UNIQUE,
        amount REAL,
        source TEXT,
        status TEXT DEFAULT 'unclaimed',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    if (
        stripos($smsText, 'received') !== false ||
        stripos($smsText, 'credited') !== false ||
        stripos($smsText, 'transferred') !== false ||
        stripos($smsText, 'deposited') !== false ||
        stripos($smsText, 'ETB') !== false ||
        stripos($smsText, 'Birr') !== false
    ) {
        $amount = 0.0;
        if (preg_match('/([0-9]{1,3}(?:,\d{3})*(?:\.\d+)?|[0-9]+(?:\.\d+)?)\s*(?:ETB|Birr)/i', $smsText, $m)) {
            $amount = (float)str_replace(',', '', $m[1]);
        } elseif (preg_match('/(?:Birr|ETB)\s*([0-9]{1,3}(?:,\d{3})*(?:\.\d+)?|[0-9]+(?:\.\d+)?)/i', $smsText, $m)) {
            $amount = (float)str_replace(',', '', $m[1]);
        } elseif (preg_match('/\b(\d{2,6}\.\d{2})\b/', $smsText, $m)) {
            $amount = (float)$m[1];
        }

        $txRef = '';
        if (preg_match('/(?:transaction number is|reference number is|Ref No[:\s]*|transaction ID[:\s]*|Txn ID[:\s]*|Reference[:\s]*)([A-Z0-9]+)/i', $smsText, $refMatches)) {
            $txRef = strtoupper(trim($refMatches[1]));
        } else {
            preg_match_all('/\b[A-Z0-9]{8,14}\b/i', $smsText, $allTokens);
            if (!empty($allTokens[0])) {
                $longestToken = '';
                foreach ($allTokens[0] as $token) {
                    if (strlen($token) > strlen($longestToken)) {
                        $longestToken = $token;
                    }
                }
                $txRef = strtoupper(trim($longestToken));
            }
        }

        if ($amount > 0 && $txRef !== '') {
            $checkStmt = $pdo->prepare("SELECT id, telegram_id FROM transactions WHERE UPPER(tx_reference) = ?");
            $checkStmt->execute([$txRef]);
            $existing = $checkStmt->fetch(PDO::FETCH_ASSOC);

            if ($existing) {
                $updateStmt = $pdo->prepare("UPDATE transactions SET amount = ?, source = 'Bank_Telebirr_SMS' WHERE id = ?");
                $updateStmt->execute([$amount, $existing['id']]);
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
