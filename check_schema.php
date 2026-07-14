<?php
$db = new PDO('sqlite:' . __DIR__ . '/db/database.sqlite');
$stmt = $db->query("PRAGMA table_info(tutorial_registrations)");
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
