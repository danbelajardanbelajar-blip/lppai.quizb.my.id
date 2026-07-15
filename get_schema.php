<?php
require_once __DIR__ . '/config/database.php';
try {
    $pdo = getDBConnection();
    $stmt = $pdo->query("SHOW TABLES");
    $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
    foreach ($tables as $table) {
        echo "Table: " . $table . "\n";
        $stmt2 = $pdo->query("SHOW CREATE TABLE " . $table);
        $create = $stmt2->fetch(PDO::FETCH_ASSOC);
        print_r($create['Create Table']);
        echo "\n\n";
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
