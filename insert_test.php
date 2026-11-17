<?php
$dbPath = __DIR__ . '/bingo.db';
$pdo = new PDO("sqlite:" . $dbPath);
$stmt = $pdo->prepare("INSERT OR REPLACE INTO transactions (telegram_id, tx_reference, amount, source, status) VALUES (NULL, 'TESTREF123', 50.00, 'Manual_Test', 'unclaimed')");
$stmt->execute();
echo "Inserted test transaction TESTREF123 for 50.00 ETB.";
?>
