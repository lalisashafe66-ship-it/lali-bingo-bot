<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

header('Content-Type: application/json; charset=utf-8');

$token   = trim((string)(getenv('TELEGRAM_BOT_TOKEN') ?: getenv('BOT_TOKEN') ?: ''));
$adminId = trim((string)(getenv('ADMIN_TELEGRAM_ID') ?: getenv('ADMIN_ID') ?: '730967613'));

function tgApi(string $token, string $method, array $payload): array {
    if ($token === '') {
        return ['ok' => false, 'error' => 'Missing bot token'];
    }
    $ch = curl_init("https://api.telegram.org/bot{$token}/{$method}");
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
    ]);
    $resp = curl_exec($ch);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($err !== '') {
        return ['ok' => false, 'error' => $err];
    }
    $decoded = json_decode((string)$resp, true);
    return is_array($decoded) ? $decoded : ['ok' => false];
}

function hasPgTable(PDO $pdo, string $table): bool {
    $stmt = $pdo->prepare("
        SELECT 1
        FROM information_schema.tables
        WHERE table_schema = 'public' AND table_name = ?
        LIMIT 1
    ");
    $stmt->execute([$table]);
    return (bool)$stmt->fetchColumn();
}

function approveDepositById(PDO $pdo, int $txId): array {
    if ($txId <= 0) {
        throw new InvalidArgumentException("Invalid transaction ID: {$txId}");
    }

    while ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("SELECT id, telegram_id, amount, status FROM transactions WHERE id = ? LIMIT 1");
        $stmt->execute([$txId]);
        $tx = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$tx) {
            throw new RuntimeException("Transaction #{$txId} not found");
        }

        $status = strtolower(trim((string)($tx['status'] ?? 'pending')));
        if ($status !== '' && $status !== 'pending') {
            $pdo->rollBack();
            return ['ok' => false, 'already_processed' => true, 'status' => $status];
        }

        $playerId = trim((string)($tx['telegram_id'] ?? ''));
        $amount   = round((float)($tx['amount'] ?? 0), 2);
        if ($playerId === '' || $amount <= 0) {
            throw new RuntimeException("Invalid player_id or amount on transaction #{$txId}");
        }

        $ts = gmdate('Y-m-d H:i:s');

        $pStmt = $pdo->prepare("SELECT telegram_id, balance, chat_id FROM players WHERE CAST(telegram_id AS TEXT) = ? LIMIT 1");
        $pStmt->execute([$playerId]);
        $player = $pStmt->fetch(PDO::FETCH_ASSOC);

        if (!$player) {
            $pdo->prepare("
                INSERT INTO players (telegram_id, chat_id, first_name, balance, created_at, updated_at)
                VALUES (?, ?, 'Player', 0.0, ?, ?)
            ")->execute([$playerId, $playerId, $ts, $ts]);
            $before = 0.0;
            $canonicalId = $playerId;
        } else {
            $canonicalId = (string)$player['telegram_id'];
            $before = round((float)($player['balance'] ?? 0.0), 2);
            if (trim((string)($player['chat_id'] ?? '')) === '') {
                $pdo->prepare("UPDATE players SET chat_id = ?, updated_at = ? WHERE CAST(telegram_id AS TEXT) = ?")
                    ->execute([$playerId, $ts, $canonicalId]);
            }
        }

        $after = round($before + $amount, 2);

        $pdo->prepare("UPDATE players SET balance = ?, updated_at = ? WHERE CAST(telegram_id AS TEXT) = ?")
            ->execute([$after, $ts, $canonicalId]);

        $pdo->prepare("UPDATE transactions SET status = 'approved' WHERE id = ?")
            ->execute([$txId]);

        if (hasPgTable($pdo, 'balance_ledger')) {
            $pdo->prepare("
                INSERT INTO balance_ledger (telegram_id, room_id, type, amount, balance_before, balance_after, note, created_at)
                VALUES (?, NULL, 'deposit', ?, ?, ?, ?, ?)
            ")->execute([$canonicalId, $amount, $before, $after, "Deposit #{$txId} approved", $ts]);
        }

        $pdo->commit();

        return [
            'ok'          => true,
            'tx_id'       => $txId,
            'player_id'   => $canonicalId,
            'amount'      => $amount,
            'before'      => $before,
            'after'       => $after,
        ];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

$raw = file_get_contents('php://input');
$update = json_decode($raw ?: '', true);

if (is_array($update) && isset($update['callback_query'])) {
    $cb         = $update['callback_query'];
    $cbId       = (string)($cb['id'] ?? '');
    $fromId     = (string)($cb['from']['id'] ?? '');
    $data       = trim((string)($cb['data'] ?? ''));
    $adminChat  = (string)($cb['message']['chat']['id'] ?? $adminId);
    $adminMsgId = isset($cb['message']['message_id']) ? (int)$cb['message']['message_id'] : 0;
    $origText   = (string)($cb['message']['text'] ?? '');

    if ($adminId !== '' && $fromId !== $adminId) {
        tgApi($token, 'answerCallbackQuery', [
            'callback_query_id' => $cbId,
            'text'              => 'Admin only.',
            'show_alert'        => true,
        ]);
        echo json_encode(['ok' => true]);
        exit;
    }

    if (!preg_match('/^approve_tx:(\d+)$/i', $data, $m)) {
        tgApi($token, 'answerCallbackQuery', [
            'callback_query_id' => $cbId,
            'text'              => 'Unsupported callback: ' . substr($data, 0, 80),
            'show_alert'        => true,
        ]);
        echo json_encode(['ok' => true]);
        exit;
    }

    try {
        $pdo = getBingoPdo();
        $res = approveDepositById($pdo, (int)$m[1]);

        if (!empty($res['already_processed'])) {
            tgApi($token, 'answerCallbackQuery', [
                'callback_query_id' => $cbId,
                'text'              => 'Already ' . $res['status'],
                'show_alert'        => true,
            ]);
            echo json_encode(['ok' => true]);
            exit;
        }

        $amtStr   = number_format((float)$res['amount'], 2);
        $afterStr = number_format((float)$res['after'], 2);

        tgApi($token, 'answerCallbackQuery', [
            'callback_query_id' => $cbId,
            'text'              => "Approved +{$amtStr} ETB (Bal: {$afterStr})",
            'show_alert'        => false,
        ]);

        if ($adminMsgId > 0) {
            tgApi($token, 'editMessageText', [
                'chat_id'      => $adminChat,
                'message_id'   => $adminMsgId,
                'text'         => ($origText !== '' ? $origText : 'Deposit') . "\n\n✅ APPROVED (+{$amtStr} ETB) | Balance: {$afterStr} ETB",
                'reply_markup' => ['inline_keyboard' => []],
            ]);
        }

        tgApi($token, 'sendMessage', [
            'chat_id' => $res['player_id'],
            'text'    => "SMART BINGO — Deposit Approved\n\nCredited: +{$amtStr} ETB\nNew Balance: {$afterStr} ETB",
        ]);

        echo json_encode(['ok' => true, 'result' => $res]);
    } catch (Throwable $e) {
        error_log("approve_payment error: " . $e->getMessage());
        tgApi($token, 'answerCallbackQuery', [
            'callback_query_id' => $cbId,
            'text'              => 'Approve error: ' . substr($e->getMessage(), 0, 140),
            'show_alert'        => true,
        ]);
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// Direct POST/GET test mode: ?tx_id=6
$txId = (int)($_POST['tx_id'] ?? $_GET['tx_id'] ?? 0);
if ($txId > 0) {
    try {
        $pdo = getBingoPdo();
        $res = approveDepositById($pdo, $txId);
        echo json_encode(['ok' => true, 'result' => $res]);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

echo json_encode(['ok' => true, 'endpoint' => 'approve_payment.php']);
