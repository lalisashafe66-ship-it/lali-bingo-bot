<?php
// balance.php - Fetches player wallet balance
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

require_once __DIR__ . '/db.php';

$telegramId = trim((string)($_GET['telegram_id'] ?? ''));

if ($telegramId === '' || $telegramId === 'undefined' || $telegramId === 'null') {
    echo json_encode(['success' => false, 'balance' => 0.00]);
    exit;
}

try {
    $pdo = getBingoPdo();
    $stmt = $pdo->prepare("SELECT balance FROM players WHERE telegram_id = ?");
    $stmt->execute([$telegramId]);
    $player = $stmt->fetch(PDO::FETCH_ASSOC);

    $balance = $player ? round((float)$player['balance'], 2) : 0.00;
    echo json_encode(['success' => true, 'balance' => $balance]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'balance' => 0.00]);
}
