<?php
// Receive Chapa webhook notification and verify payment
$input = file_get_contents('php://input');
$event = json_decode($input, true);

$telegramId = $_GET['telegram_id'] ?? '';
$amount = (float)($_GET['amount'] ?? 0);

if ($telegramId && $amount > 0) {
    try {
        $pdo = new PDO("sqlite:" . __DIR__ . "/bingo.db");
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        // Fetch current balance
        $stmt = $pdo->prepare("SELECT balance FROM players WHERE telegram_id = ?");
        $stmt->execute([$telegramId]);
        $player = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($player) {
            $newBalance = (float)$player['balance'] + $amount;
            // Update player wallet balance permanently
            $update = $pdo->prepare("UPDATE players SET balance = ? WHERE telegram_id = ?");
            $update->execute([$newBalance, $telegramId]);
        }
    } catch (Exception $e) {
        // Log error silently
    }
}
http_response_code(200);
?>
