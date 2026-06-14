<?php
require_once __DIR__ . '/../includes/auth.php';
$pdo = getDBConnection();
$classes = $pdo->query('SELECT id, dosen_pengampu FROM tutorial_classes')->fetchAll(PDO::FETCH_ASSOC);
$tutors = $pdo->query("SELECT nama_lengkap as nama FROM users WHERE role = 'dosen'")->fetchAll(PDO::FETCH_COLUMN);

foreach ($classes as $c) {
    if (!$c['dosen_pengampu']) continue;
    foreach ($tutors as $t) {
        // If the generated name is a substring of the full title-inclusive name from the tutors table
        if (strpos($t, $c['dosen_pengampu']) !== false && $t !== $c['dosen_pengampu']) {
            echo "Updating class {$c['id']} from {$c['dosen_pengampu']} to {$t}\n";
            $pdo->prepare("UPDATE tutorial_classes SET dosen_pengampu = ? WHERE id = ?")->execute([$t, $c['id']]);
            break;
        }
    }
}
echo "Done\n";
