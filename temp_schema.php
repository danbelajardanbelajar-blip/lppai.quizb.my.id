<?php
require 'includes/auth.php';
$pdo=getDBConnection();
$stmt=$pdo->query('SHOW COLUMNS FROM tutorial_registrations');
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
