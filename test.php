<?php
$pdo = new PDO('mysql:host=localhost;dbname=quic1934_lppai;charset=utf8mb4', 'quic1934_zenhkm', '03Maret1990');
$stmt = $pdo->query("DESCRIBE tutorial_registrations");
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
