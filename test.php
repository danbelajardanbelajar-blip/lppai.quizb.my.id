<?php
$pdo = new PDO('mysql:host=localhost;dbname=quic1934_lppai;charset=utf8mb4', 'quic1934_zenhkm', '03Maret1990');
$stmt = $pdo->query("SELECT id, tutors_senin, tutors_rabu FROM master_gelombang ORDER BY id DESC LIMIT 5");
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
