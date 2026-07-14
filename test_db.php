<?php
require 'includes/db.php';
$pdo = getDBConnection();
print_r($pdo->query('SELECT id, nama_kelas, semester FROM tutorial_classes LIMIT 5')->fetchAll(PDO::FETCH_ASSOC));
?>
