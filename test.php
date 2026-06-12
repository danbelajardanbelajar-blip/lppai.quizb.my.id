<?php
$pdo = new PDO('sqlite:database.sqlite');
$gel = $pdo->query('SELECT tutors_senin, tutors_selasa, tutors_rabu, tutors_kamis, tutors_jumat FROM master_gelombang ORDER BY id DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
print_r($gel);
