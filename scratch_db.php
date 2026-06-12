<?php
require __DIR__ . '/config/database.php';
$pdo = getDBConnection();
print_r($pdo->query('SHOW CREATE TABLE tutorial_registrations')->fetch(PDO::FETCH_ASSOC));
print_r($pdo->query('SHOW CREATE TABLE master_gelombang')->fetch(PDO::FETCH_ASSOC));
