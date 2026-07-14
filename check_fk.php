<?php
$pdo = new PDO('mysql:host=localhost;dbname=lppai_new', 'root', '');
$stmt = $pdo->query("SELECT TABLE_NAME, COLUMN_NAME FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE WHERE REFERENCED_TABLE_NAME = 'tutorial_classes'");
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
