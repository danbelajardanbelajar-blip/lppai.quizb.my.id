<?php
/**
 * LPPAI Corner - Admin: Generate Jadwal Otomatis
 */
define('PAGE_TITLE', 'Generate Jadwal Otomatis');
require_once __DIR__ . '/../includes/auth.php';
requireAdmin();

$pdo = getDBConnection();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!verifyCsrf($token)) {
        die('Sesi tidak valid.');
    }

    $generateMode = $_POST['generate_mode'] ?? 'distribute_evenly';
    $minPerClass = (int)($_POST['min_per_class'] ?? 30);
    if ($minPerClass < 1) $minPerClass = 1;

    // Dapatkan data gelombang terakhir yang dibuat
    $gel = $pdo->query("SELECT * FROM master_gelombang ORDER BY created_at DESC LIMIT 1")->fetch();
    if (!$gel) {
        die("Belum ada data gelombang di Master Gelombang.");
    }

    $semester = $gel['semester'];
    $tahun_ajaran = $gel['tahun_ajaran'];
    $gelombang_name = $gel['gelombang'];

    // Fungsi untuk mem-parsing data dosen lama yang masih menggunakan koma
    if (!function_exists('parseLegacyTutors')) {
        function parseLegacyTutors($tutorsStr, $tutorsList) {
            if ($tutorsStr === '') return [];
            if (strpos($tutorsStr, '|||') !== false) {
                return array_filter(array_map('trim', explode('|||', $tutorsStr)));
            }
            $list = $tutorsList;
            usort($list, function($a, $b) { return strlen($b['nama']) - strlen($a['nama']); });
            $tempStr = $tutorsStr;
            $matched = [];
            foreach ($list as $t) {
                $nama = $t['nama'];
                while (($pos = strpos($tempStr, $nama)) !== false) {
                    $matched[$pos] = $nama;
                    $tempStr = substr_replace($tempStr, str_repeat('#', strlen($nama)), $pos, strlen($nama));
                }
            }
            ksort($matched);
            return array_values($matched);
        }
    }

    $hariList = ['Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat'];
    
    // Siapkan statement untuk membuat kelas
    $stmtInsertClass = $pdo->prepare("INSERT INTO tutorial_classes (nama_kelas, dosen_pengampu, hari, jam, ruangan, gelombang, semester, kuota) VALUES (?, ?, ?, '13.00 - 14.30', NULL, ?, ?, 30)");
    
    // Siapkan statement untuk update class id mahasiswa
    $stmtUpdateReg = $pdo->prepare("UPDATE tutorial_registrations SET tutorial_class_id = ? WHERE id = ?");

    $pdo->beginTransaction();

    // Ambil daftar nama dosen dari tabel tutors
    $tutorsList = $pdo->query("SELECT nim as id, nama_lengkap as nama FROM users WHERE role = 'dosen' ORDER BY nama_lengkap ASC")->fetchAll();

    try {
        foreach ($hariList as $hari) {
            $hariLower = strtolower($hari);
            $tutorsString = $gel['tutors_'.$hariLower] ?? '';
            $tutors = parseLegacyTutors($tutorsString, $tutorsList);
            
            // Cari mahasiswa yang sudah mendaftar di hari ini tetapi belum masuk kelas
            // dan pastikan mereka terdaftar pada gelombang yang aktif ini
            $stmtMhs = $pdo->prepare("SELECT id FROM tutorial_registrations WHERE hari_pilihan = ? AND tutorial_class_id IS NULL AND gelombang = ?");
            $stmtMhs->execute([$hari, $gelombang_name]);
            $students = $stmtMhs->fetchAll(PDO::FETCH_COLUMN);

            $N = count($students);
            $C = count($tutors);

            if ($N === 0 || $C === 0) {
                // Jika tidak ada mahasiswa atau tidak ada tutor di hari ini, skip hari ini
                continue;
            }

            // Siapkan helper untuk resolve nama asli tutor
            $realTutorNames = [];
            $stmtFindTutor = $pdo->prepare("SELECT nama_lengkap as nama FROM users WHERE role = 'dosen' AND nama_lengkap LIKE CONCAT('%', ?, '%') LIMIT 1");

            if ($generateMode === 'fill_first') {
                $tutorIndex = 0;
                $classIndex = 0;
                
                while (!empty($students)) {
                    $chunk = array_splice($students, 0, $minPerClass);
                    $tutor = $tutors[$tutorIndex % $C];
                    
                    if (!isset($realTutorNames[$tutor])) {
                        $stmtFindTutor->execute([$tutor]);
                        $realName = $stmtFindTutor->fetchColumn();
                        $realTutorNames[$tutor] = $realName ? $realName : $tutor;
                    }
                    $tutorReal = $realTutorNames[$tutor];
                    
                    $namaKelas = "Kelas $hari " . chr(65 + $classIndex); // Kelas Senin A, Kelas Senin B, ...
                    $stmtInsertClass->execute([$namaKelas, $tutorReal, $hari, $gelombang_name, $tahun_ajaran . '-' . $semester]);
                    $classId = $pdo->lastInsertId();
                    $classIndex++;
                    
                    foreach ($chunk as $regId) {
                        $stmtUpdateReg->execute([$classId, $regId]);
                    }
                    
                    $tutorIndex++;
                }
            } else {
                // Mode distribute_evenly (meratakan)
                $classIndex = 0;
                $remainingStudents = $N;

                for ($i = 0; $i < $C; $i++) {
                    if (empty($students)) break;

                    $remainingTutors = $C - $i;
                    $studentsPerClass = ceil($remainingStudents / $remainingTutors);
                    $chunk = array_splice($students, 0, $studentsPerClass);
                    $remainingStudents -= count($chunk);

                    $tutor = $tutors[$i];

                    if (!isset($realTutorNames[$tutor])) {
                        $stmtFindTutor->execute([$tutor]);
                        $realName = $stmtFindTutor->fetchColumn();
                        $realTutorNames[$tutor] = $realName ? $realName : $tutor;
                    }
                    $tutorReal = $realTutorNames[$tutor];

                    $namaKelas = "Kelas $hari " . chr(65 + $classIndex);
                    $stmtInsertClass->execute([$namaKelas, $tutorReal, $hari, $gelombang_name, $tahun_ajaran . '-' . $semester]);
                    $classId = $pdo->lastInsertId();
                    $classIndex++;

                    // Masukkan mahasiswa ke kelas ini
                    foreach ($chunk as $regId) {
                        $stmtUpdateReg->execute([$classId, $regId]);
                    }
                }
            }
        }
        
        // Sinkronkan nama dosen_pengampu pada tutorial_classes dengan nama lengkap beserta gelar di tabel tutors
        $pdo->exec("
            UPDATE tutorial_classes tc
            JOIN tutors t ON t.nama LIKE CONCAT('%', tc.dosen_pengampu, '%') AND t.nama != tc.dosen_pengampu
            SET tc.dosen_pengampu = t.nama
        ");
        
        $pdo->commit();
        
        // Redirect ke halaman pilih ruangan
        header("Location: tutorial-pilih-ruangan.php");
        exit;
    } catch (Exception $e) {
        $pdo->rollBack();
        die("Error saat menggenerate: " . $e->getMessage());
    }
} else {
    // Jika bukan POST, kembalikan ke peserta
    header("Location: tutorial-peserta.php");
    exit;
}
