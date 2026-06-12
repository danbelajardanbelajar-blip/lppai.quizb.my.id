<?php
require __DIR__ . '/includes/auth.php';
$pdo = getDBConnection();
$stmt = $pdo->query('SELECT id, tutors_senin, tutors_selasa, tutors_rabu, tutors_kamis, tutors_jumat FROM master_gelombang ORDER BY id DESC LIMIT 1');
print_r($stmt->fetch(PDO::FETCH_ASSOC));
