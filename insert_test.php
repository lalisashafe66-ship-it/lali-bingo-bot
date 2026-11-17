<?php
// insert_test.php - Creates table if missing and inserts test transaction
$dbPath = __DIR__ . '/bingo.db';
$pdo = new PDO("sqlite:" . $dbPath);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Create transactions table if it doesn't exist yet
$pdo->exec("CREATE TABLE IF NOT EXISTS transactions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    telegram_id TEXT,
    tx_reference TEXT UNIQUE,
    amount REAL,
    source TEXT,
    status TEXT DEFAULT 'unclaimed',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
)");

$stmt = $pdo->prepare("INSERT OR REPLACE INTO transactions (telegram_id, tx_reference, amount, source, status) VALUES (NULL, 'TESTREF123', 50.00, 'Manual_Test', 'unclaimed')");
$stmt->execute();

echo "Test transaction TESTREF123 for 50.00 ETB inserted successfully!";
?>
