<?php
header('Content-Type: application/json');
$telegramId = $_GET['telegram_id'] ?? '';

if (!$telegramId) {
    echo json_encode(['balance' => 0.00]);
    exit;
}

try {
    $pdo = new PDO("sqlite:" . __DIR__ . "/bingo.db");
    $stmt = $pdo->prepare("SELECT balance FROM players WHERE telegram_id = ?");
    $stmt->execute([$telegramId]);
    $player = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $balance = $player ? (float)$player['balance'] : 0.00;
    echo json_encode(['balance' => $balance]);
} catch (Exception $e) {
    echo json_encode(['balance' => 0.00]);
}
?>
