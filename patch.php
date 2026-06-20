<?php
$files = [
    'admin/pretes-jadwal.php',
    'admin/pretes-peserta.php',
    'admin/pretes-hasil.php',
    'admin/tutorial-kelas.php',
    'admin/tutorial-peserta.php',
    'admin/dashboard.php',
    'dosen/dashboard.php',
    'dosen/kelas.php',
    'pages/dashboard.php',
    'pages/pretes-daftar.php',
    'pages/pretes-peserta.php',
    'pages/tutorial-pendaftaran.php',
    'pages/tutorial-pembagian.php',
    'pages/tutorial-kelulusan.php',
    'rekap-nilai.php',
    'api/index.php'
];

$periodCond = "(periode LIKE '%2026%' OR periode LIKE '%2027%' OR periode LIKE '%2028%' OR periode LIKE '%2029%' OR periode LIKE '%2030%')";
$semesterCond = "(semester LIKE '%2026%' OR semester LIKE '%2027%' OR semester LIKE '%2028%' OR semester LIKE '%2029%' OR semester LIKE '%2030%')";
$taCond = "(tahun_ajaran LIKE '%2026%' OR tahun_ajaran LIKE '%2027%' OR tahun_ajaran LIKE '%2028%' OR tahun_ajaran LIKE '%2029%' OR tahun_ajaran LIKE '%2030%')";

foreach ($files as $file) {
    if (!file_exists($file)) continue;
    $content = file_get_contents($file);
    $orig = $content;

    // pretes_schedules
    $content = preg_replace('/FROM pretes_schedules WHERE status = \'aktif\'/', 'FROM pretes_schedules WHERE status = \'aktif\' AND ' . $periodCond, $content);
    $content = preg_replace('/FROM pretes_schedules ORDER BY/', 'FROM pretes_schedules WHERE ' . $periodCond . ' ORDER BY', $content);
    $content = preg_replace('/FROM pretes_schedules"/', 'FROM pretes_schedules WHERE ' . $periodCond . '"', $content);

    // tutorial_classes
    $content = preg_replace('/FROM tutorial_classes WHERE dosen_pengampu = \? ORDER BY/', 'FROM tutorial_classes WHERE dosen_pengampu = ? AND ' . $semesterCond . ' ORDER BY', $content);
    $content = preg_replace('/FROM tutorial_classes WHERE dosen_pengampu = \?"/', 'FROM tutorial_classes WHERE dosen_pengampu = ? AND ' . $semesterCond . '"', $content);
    $content = preg_replace('/FROM tutorial_classes WHERE ruangan IS NULL/', 'FROM tutorial_classes WHERE ruangan IS NULL AND ' . $semesterCond, $content);
    $content = preg_replace('/FROM tutorial_classes WHERE gelombang = \? ORDER BY/', 'FROM tutorial_classes WHERE gelombang = ? AND ' . $semesterCond . ' ORDER BY', $content);
    $content = preg_replace('/FROM tutorial_classes ORDER BY/', 'FROM tutorial_classes WHERE ' . $semesterCond . ' ORDER BY', $content);
    $content = preg_replace('/FROM tutorial_classes tc"/', 'FROM tutorial_classes tc WHERE ' . $semesterCond . '"', $content);
    
    // pretes_registrations
    $content = preg_replace('/FROM pretes_registrations pr"/', 'FROM pretes_registrations pr WHERE ' . $periodCond . '"', $content);
    $content = preg_replace('/FROM pretes_registrations"/', 'FROM pretes_registrations WHERE ' . $periodCond . '"', $content);
    $content = preg_replace('/FROM pretes_registrations WHERE user_id = \?"/', 'FROM pretes_registrations WHERE user_id = ? AND ' . $periodCond . '"', $content);

    // tutorial_registrations
    $content = preg_replace('/FROM tutorial_registrations GROUP BY/', 'FROM tutorial_registrations WHERE ' . $taCond . ' GROUP BY', $content);
    $content = preg_replace('/FROM tutorial_registrations tr"/', 'FROM tutorial_registrations tr WHERE ' . $taCond . '"', $content);
    $content = preg_replace('/FROM tutorial_registrations tr JOIN tutorial_classes tc ON tr\.tutorial_class_id = tc\.id WHERE tr\.user_id = \? ORDER BY/', 'FROM tutorial_registrations tr JOIN tutorial_classes tc ON tr.tutorial_class_id = tc.id WHERE tr.user_id = ? AND ' . $taCond . ' ORDER BY', $content);
    $content = preg_replace('/FROM tutorial_registrations WHERE user_id = \?"/', 'FROM tutorial_registrations WHERE user_id = ? AND ' . $taCond . '"', $content);
    $content = preg_replace('/FROM tutorial_registrations"/', 'FROM tutorial_registrations WHERE ' . $taCond . '"', $content);
    $content = preg_replace('/FROM tutorial_registrations WHERE gelombang = \? GROUP BY/', 'FROM tutorial_registrations WHERE gelombang = ? AND ' . $taCond . ' GROUP BY', $content);
    
    $content = str_replace('WHERE ' . $taCond . '" WHERE', 'WHERE ' . $taCond . ' AND', $content);
    $content = str_replace('WHERE ' . $periodCond . '" WHERE', 'WHERE ' . $periodCond . ' AND', $content);

    if ($content !== $orig) {
        file_put_contents($file, $content);
        echo "Updated: $file\n";
    }
}
