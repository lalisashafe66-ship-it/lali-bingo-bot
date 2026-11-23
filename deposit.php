<?php
// deposit.php - Records deposit transactions and credits player balance
declare(strict_types=1);

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');

require_once __DIR__ . '/db.php';

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    $input = [];
}

$telegramId = trim((string)($_POST['telegram_id'] ?? $input['telegram_id'] ?? ''));
$amount = round((float)($_POST['amount'] ?? $input['amount'] ?? 0), 2);
$txRef = strtoupper(trim((string)($_POST['tx_ref'] ?? $input['tx_ref'] ?? '')));
$fullMessage = trim((string)($_POST['full_message'] ?? $input['full_message'] ?? ''));

if ($telegramId === '' || $telegramId === 'undefined' || $telegramId === 'null' || $amount < 10) {
    echo json_encode(['success' => false, 'error' => 'Invalid amount or player ID. Minimum deposit is 10 ETB.']);
    exit;
}

try {
    $pdo = getBingoPdo();
    $isPgsql = strtolower((string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)) === 'pgsql';

    if ($isPgsql) {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS transactions (
                id SERIAL PRIMARY KEY,
                telegram_id TEXT,
                tx_reference TEXT UNIQUE,
                amount DOUBLE PRECISION,
                source TEXT DEFAULT 'web_deposit',
                status TEXT DEFAULT 'verified',
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
                status TEXT DEFAULT 'verified',
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
        $stmt = $pdo->prepare("SELECT id FROM transactions WHERE UPPER(tx_reference) = ? LIMIT 1");
        $stmt->execute([$txRef]);
        if ($stmt->fetch()) {
            echo json_encode(['success' => false, 'error' => 'This Transaction ID has already been used or submitted.']);
            exit;
        }

        $ts = gmdate('Y-m-d H:i:s');
        $pdo->beginTransaction();

        if ($isPgsql) {
            $pdo->prepare("
                INSERT INTO players (telegram_id, first_name, phone, balance, created_at, updated_at)
                VALUES (?, 'Player', 'WebUser', 0.00, ?, ?)
                ON CONFLICT (telegram_id) DO NOTHING
            ")->execute([$telegramId, $ts, $ts]);
        } else {
            $pdo->prepare("
                INSERT OR IGNORE INTO players (telegram_id, first_name, phone, balance, created_at, updated_at)
                VALUES (?, 'Player', 'WebUser', 0.00, ?, ?)
            ")->execute([$telegramId, $ts, $ts]);
        }

        $balStmt = $pdo->prepare("SELECT balance FROM players WHERE telegram_id = ?");
        $balStmt->execute([$telegramId]);
        $before = (float)($balStmt->fetchColumn() ?: 0.00);
        $after = round($before + $amount, 2);

        $stmt = $pdo->prepare("
            INSERT INTO transactions (telegram_id, tx_reference, amount, source, status, full_message)
            VALUES (?, ?, ?, 'web_deposit', 'verified', ?)
        ");
        $stmt->execute([$telegramId, $txRef, $amount, $fullMessage !== '' ? $fullMessage : null]);

        $pdo->prepare("UPDATE players SET balance = ?, updated_at = ? WHERE telegram_id = ?")
            ->execute([$after, $ts, $telegramId]);

        $pdo->prepare("
            INSERT INTO balance_ledger (telegram_id, room_id, type, amount, balance_before, balance_after, note, created_at)
            VALUES (?, NULL, 'deposit_verified', ?, ?, ?, ?, ?)
        ")->execute([$telegramId, $amount, $before, $after, "Deposit ref {$txRef}", $ts]);

        $pdo->commit();

        echo json_encode([
            'success' => true,
            'message' => 'Deposit successful! Your balance has been updated instantly.',
            'amount' => $amount,
            'new_balance' => $after
        ]);
    } else {
        echo json_encode([
            'success' => true,
            'instructions' => 'Transfer money to CBE: 1000171689116 or Telebirr: 0917461538, then submit your Transaction ID.'
        ]);
    }
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
