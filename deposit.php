<?php
// deposit.php - Records deposit requests as 'pending' approval and returns copyable method details
declare(strict_types=1);

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST');

require_once __DIR__ . '/db.php';

// Method-specific copyable details
$paymentMethods = [
    'telebirr' => [
        'key'            => 'telebirr',
        'label'          => 'Telebirr',
        'account_number' => '0917461538',
        'account_name'   => 'LALISA SHAFE',
        'instruction'    => 'Copy the Telebirr number below, send your deposit (min 10 ETB), then paste your SMS/confirmation message below for approval.'
    ],
    'bank' => [
        'key'            => 'bank',
        'label'          => 'Bank Account (CBE)',
        'account_number' => '1000171689116',
        'account_name'   => 'LALISA SHAFE',
        'instruction'    => 'Copy the CBE account number below, transfer your deposit (min 10 ETB), then paste your transfer message/reference below for approval.'
    ]
];

if ($_SERVER['REQUEST_METHOD'] === 'GET' && (($_GET['action'] ?? '') === 'methods')) {
    echo json_encode([
        'success' => true,
        'methods' => $paymentMethods
    ]);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    $input = [];
}

$telegramId  = trim((string)($_POST['telegram_id'] ?? $input['telegram_id'] ?? ''));
$firstName   = trim((string)($_POST['first_name'] ?? $input['first_name'] ?? 'Player'));
$rawMethod   = strtolower(trim((string)($_POST['method'] ?? $input['method'] ?? 'telebirr')));
$amount      = round((float)($_POST['amount'] ?? $input['amount'] ?? 0), 2);
$fullMessage = trim((string)($_POST['full_message'] ?? $input['full_message'] ?? $input['message'] ?? ''));
$txRef       = strtoupper(trim((string)($_POST['tx_ref'] ?? $input['tx_ref'] ?? '')));

$methodKey   = (str_contains($rawMethod, 'bank') || str_contains($rawMethod, 'cbe')) ? 'bank' : 'telebirr';
$methodLabel = $paymentMethods[$methodKey]['label'];

// Auto-extract amount if not typed or below 10 ETB
if ($amount < 10 && $fullMessage !== '' && preg_match('/(\d+(?:\.\d{1,2})?)\s*(?:ብር|ETB)\b/i', $fullMessage, $m)) {
    $amount = round((float)$m[1], 2);
}

// Auto-extract transaction reference if not provided
if ($txRef === '' && $fullMessage !== '') {
    if (preg_match('/\b(?:transaction\s*(?:id|no|number)|tx\s*ref|ref(?:erence)?\s*(?:id|no)?|receipt\s*(?:no)?|ft)\s*[:#-]?\s*([A-Z0-9]{6,25})\b/i', $fullMessage, $rm)) {
        $txRef = strtoupper($rm[1]);
    } elseif (preg_match('/\b([A-Z0-9]{8,20})\b/', $fullMessage, $rm)) {
        $txRef = strtoupper($rm[1]);
    }
}

if ($telegramId === '' || $telegramId === 'undefined' || $telegramId === 'null') {
    echo json_encode(['success' => false, 'error' => 'Player ID is missing.']);
    exit;
}
if ($fullMessage === '') {
    echo json_encode(['success' => false, 'error' => 'Please paste your payment message for approval.']);
    exit;
}
if ($amount < 10) {
    echo json_encode(['success' => false, 'error' => 'Minimum deposit is 10 ETB.']);
    exit;
}

try {
    $pdo = getBingoPdo();
    $isPgsql = strtolower((string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)) === 'pgsql';
    $ts = gmdate('Y-m-d H:i:s');

    if ($isPgsql) {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS transactions (
                id SERIAL PRIMARY KEY,
                telegram_id TEXT,
                tx_reference TEXT UNIQUE,
                amount DOUBLE PRECISION,
                source TEXT DEFAULT 'web_deposit',
                status TEXT DEFAULT 'pending',
                created_at TIMESTAMP WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP,
                full_message TEXT
            )
        ");
        $pdo->exec("ALTER TABLE transactions ADD COLUMN IF NOT EXISTS source TEXT DEFAULT 'web_deposit'");
        $pdo->exec("ALTER TABLE transactions ADD COLUMN IF NOT EXISTS full_message TEXT");
    } else {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS transactions (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                telegram_id TEXT,
                tx_reference TEXT UNIQUE,
                amount REAL,
                source TEXT DEFAULT 'web_deposit',
                status TEXT DEFAULT 'pending',
                created_at TEXT DEFAULT CURRENT_TIMESTAMP,
                full_message TEXT
            )
        ");
        $txCols = $pdo->query("PRAGMA table_info(transactions)")->fetchAll(PDO::FETCH_COLUMN, 1);
        if (!in_array('source', $txCols, true)) {
            $pdo->exec("ALTER TABLE transactions ADD COLUMN source TEXT DEFAULT 'web_deposit'");
        }
        if (!in_array('full_message', $txCols, true)) {
            $pdo->exec("ALTER TABLE transactions ADD COLUMN full_message TEXT");
        }
    }

    if ($txRef !== '') {
        $dupStmt = $pdo->prepare("SELECT id, status FROM transactions WHERE UPPER(tx_reference) = ? LIMIT 1");
        $dupStmt->execute([$txRef]);
        if ($existing = $dupStmt->fetch(PDO::FETCH_ASSOC)) {
            echo json_encode([
                'success' => false,
                'error'   => "This payment reference ({$txRef}) is already {$existing['status']}."
            ]);
            exit;
        }
    }

    $pdo->beginTransaction();

    if ($isPgsql) {
        $pdo->prepare("
            INSERT INTO players (telegram_id, first_name, phone, balance, created_at, updated_at)
            VALUES (?, ?, 'WebUser', 0.00, ?, ?)
            ON CONFLICT (telegram_id) DO NOTHING
        ")->execute([$telegramId, $firstName !== '' ? $firstName : 'Player', $ts, $ts]);
    } else {
        $pdo->prepare("
            INSERT OR IGNORE INTO players (telegram_id, first_name, phone, balance, created_at, updated_at)
            VALUES (?, ?, 'WebUser', 0.00, ?, ?)
        ")->execute([$telegramId, $firstName !== '' ? $firstName : 'Player', $ts, $ts]);
    }

    $balStmt = $pdo->prepare("SELECT balance FROM players WHERE telegram_id = ?");
    $balStmt->execute([$telegramId]);
    $currentBal = round((float)($balStmt->fetchColumn() ?: 0.00), 2);

    $uniqueRef = $txRef !== '' ? $txRef : ('DEP-' . strtoupper(bin2hex(random_bytes(4))));
    $sourceTag = 'web_deposit:' . $methodKey;

    $stmt = $pdo->prepare("
        INSERT INTO transactions (telegram_id, tx_reference, amount, source, status, created_at, full_message)
        VALUES (?, ?, ?, ?, 'pending', ?, ?)
    ");
    $stmt->execute([$telegramId, $uniqueRef, $amount, $sourceTag, $ts, $fullMessage]);
    $txId = (int)$pdo->lastInsertId();

    $pdo->prepare("
        INSERT INTO balance_ledger (telegram_id, room_id, type, amount, balance_before, balance_after, note, created_at)
        VALUES (?, NULL, 'deposit_pending', ?, ?, ?, ?, ?)
    ")->execute([
        $telegramId,
        $amount,
        $currentBal,
        $currentBal,
        "Pending {$methodLabel} deposit (#{$txId}, ref {$uniqueRef})",
        $ts
    ]);

    $pdo->commit();

    echo json_encode([
        'success'        => true,
        'transaction_id' => $txId,
        'tx_reference'   => $uniqueRef,
        'method'         => $methodLabel,
        'amount'         => $amount,
        'status'         => 'pending',
        'new_balance'    => $currentBal,
        'message'        => "Deposit request #{$txId} ({$methodLabel}, " . number_format($amount, 2) . " ETB) sent for approval."
    ]);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
