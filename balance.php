<?php
// balance.php - Fetches player wallet balance
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

$telegramId = $_GET['telegram_id'] ?? '';

if (!$telegramId) {
    echo json_encode(['success' => false, 'balance' => 0.00]);
    exit;
}

try {
    $pdo = new PDO("sqlite:" . __DIR__ . "/bingo.db");
    $stmt = $pdo->prepare("SELECT balance FROM players WHERE telegram_id = ?");
    $stmt->execute([$telegramId]);
    $player = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $balance = $player ? (float)$player['balance'] : 0.00;
    echo json_encode(['success' => true, 'balance' => $balance]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'balance' => 0.00]);
}
?>
