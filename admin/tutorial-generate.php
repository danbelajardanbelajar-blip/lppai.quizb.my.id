<?php
/**
 * LPPAI Corner - Admin: Generate Jadwal Otomatis
 */
define('PAGE_TITLE', 'Generate Jadwal Otomatis');
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/tutorial-plotting.php';
requireAdmin();

$pdo = getDBConnection();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!verifyCsrf($token)) {
        die('Sesi tidak valid.');
    }

    $generateMode = $_POST['generate_mode'] ?? 'distribute_evenly';
    $overwritePlotting = ($_POST['overwrite_plotting'] ?? '') === '1';
    $minPerClass = (int)($_POST['min_per_class'] ?? 30);
    if ($minPerClass < 1) $minPerClass = 1;

    // Sama dengan periode yang ditampilkan pada halaman pengaturan.
    $gel = $pdo->query("SELECT * FROM master_gelombang WHERE status = 'aktif' ORDER BY created_at DESC, id DESC LIMIT 1")->fetch()
        ?: $pdo->query("SELECT * FROM master_gelombang ORDER BY created_at DESC, id DESC LIMIT 1")->fetch();
    if (!$gel) {
        die("Belum ada data gelombang di Master Gelombang.");
    }

    $semester = $gel['semester'];
    $tahun_ajaran = str_replace('/', '-', $gel['tahun_ajaran']);
    $gelombang_name = $gel['gelombang'];

    // Fungsi untuk mem-parsing data dosen lama yang masih menggunakan koma atau delimiter
    if (!function_exists('parseLegacyTutors')) {
        function parseLegacyTutors($tutorsStr, $tutorsList) {
            if ($tutorsStr === null || trim($tutorsStr) === '') return [];
            $tutorsStr = trim($tutorsStr);
            if (strpos($tutorsStr, '|||') !== false) {
                return array_values(array_filter(array_map('trim', explode('|||', $tutorsStr)), function($v) { return $v !== ''; }));
            }
            $list = $tutorsList;
            usort($list, function($a, $b) { return strlen($b['nama']) - strlen($a['nama']); });
            $tempStr = $tutorsStr;
            $matched = [];
            foreach ($list as $t) {
                $nama = trim($t['nama']);
                if ($nama === '') continue;
                while (($pos = mb_stripos($tempStr, $nama)) !== false) {
                    $matched[$pos] = $nama;
                    $tempStr = substr_replace($tempStr, str_repeat('#', strlen($nama)), $pos, strlen($nama));
                }
                $namaBase = trim(explode(',', $nama)[0]);
                if (strlen($namaBase) >= 4) {
                    while (($pos = mb_stripos($tempStr, $namaBase)) !== false) {
                        $matched[$pos] = $nama;
                        $tempStr = substr_replace($tempStr, str_repeat('#', strlen($namaBase)), $pos, strlen($namaBase));
                    }
                }
            }
            if (!empty($matched)) {
                ksort($matched);
                return array_values($matched);
            }
            $fallback = array_values(array_filter(array_map('trim', explode(',', $tutorsStr)), function($v) { return $v !== ''; }));
            return !empty($fallback) ? $fallback : [$tutorsStr];
        }
    }

    $hariList = ['Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat'];
    
    // Siapkan statement untuk membuat kelas
    $stmtInsertClass = $pdo->prepare("INSERT INTO tutorial_classes (nama_kelas, dosen_pengampu, hari, jam, ruangan, gelombang, semester, kuota) VALUES (?, ?, ?, '13.00 - 14.30', NULL, ?, ?, 30)");
    
    // Map gelombang to tipe_nilai
    $tipe_nilai = 'mandiri';
    if ($gelombang_name === 'gel1') $tipe_nilai = 'gel 1';
    else if ($gelombang_name === 'gel2') $tipe_nilai = 'gel 2';

    // Siapkan statement untuk update class id mahasiswa beserta tahun_ajaran dan tipe_nilai
    $stmtUpdateReg = $pdo->prepare("UPDATE tutorial_registrations SET tutorial_class_id = ?, tahun_ajaran = ?, tipe_nilai = ? WHERE id = ?");

    $pdo->beginTransaction();

    try {
        // Kunci periode agar dua permintaan generate tidak memplot peserta yang sama.
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
            $lock = $pdo->prepare('SELECT id FROM master_gelombang WHERE id = ? FOR UPDATE');
            $lock->execute([$gel['id']]);
        }
        $tutorsList = $pdo->query("SELECT nim as id, nama_lengkap as nama FROM users WHERE role = 'dosen' ORDER BY nama_lengkap ASC")->fetchAll();
        $candidates = tutorialPlottingCandidates($pdo, $gel, $overwritePlotting);
        $oldClassIds = [];
        if ($overwritePlotting) {
            $reset = $pdo->prepare('UPDATE tutorial_registrations SET tutorial_class_id = NULL WHERE id = ?');
            foreach ($candidates as $reg) {
                if (!in_array($reg['hari_pilihan'], $hariList, true)) {
                    throw new RuntimeException('Pilihan hari peserta tidak valid. Perbaiki data sebelum menindih plotting.');
                }
                if ($reg['tutorial_class_id'] !== null) {
                    $oldClassIds[(int)$reg['tutorial_class_id']] = true;
                    $reset->execute([$reg['id']]);
                }
            }
        }
        $deleteEmpty = $pdo->prepare('DELETE FROM tutorial_classes WHERE id = ? AND NOT EXISTS
            (SELECT 1 FROM tutorial_registrations WHERE tutorial_class_id = tutorial_classes.id)');
        foreach (array_keys($oldClassIds) as $oldClassId) {
            $deleteEmpty->execute([$oldClassId]);
        }
        $countClasses = $pdo->prepare("SELECT COUNT(*) FROM tutorial_classes
            WHERE hari = ? AND gelombang = ? AND REPLACE(semester, '/', '-') = ?");
        foreach ($hariList as $hari) {
            $hariLower = strtolower($hari);
            $tutorsString = $gel['tutors_'.$hariLower] ?? '';
            $tutors = parseLegacyTutors($tutorsString, $tutorsList);
            
            // Kandidat sudah dibatasi periode dan pilihan mempertahankan/menindih plotting.
            $students = array_column(array_filter($candidates, static fn($reg) => $reg['hari_pilihan'] === $hari), 'id');

            $N = count($students);
            $C = count($tutors);

            if ($overwritePlotting && $N > 0 && $C === 0) {
                throw new RuntimeException("Tutor hari $hari belum diatur. Plotting lama tetap dipertahankan.");
            }

            if ($N === 0 || $C === 0) {
                // Jika tidak ada mahasiswa atau tidak ada tutor di hari ini, skip hari ini
                continue;
            }

            // Siapkan helper untuk resolve nama asli tutor
            $realTutorNames = [];
            $stmtFindTutor = $pdo->prepare("SELECT nama_lengkap as nama FROM users WHERE role = 'dosen' AND nama_lengkap LIKE CONCAT('%', ?, '%') LIMIT 1");
            $countClasses->execute([$hari, $gelombang_name, $tahun_ajaran . '-' . $semester]);
            $firstClassIndex = (int)$countClasses->fetchColumn();

            if ($generateMode === 'fill_first') {
                $tutorIndex = 0;
                $classIndex = $firstClassIndex;
                
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
                        $stmtUpdateReg->execute([$classId, $tahun_ajaran, $tipe_nilai, $regId]);
                    }
                    
                    $tutorIndex++;
                }
            } else {
                // Mode distribute_evenly (meratakan)
                $classIndex = $firstClassIndex;
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
                        $stmtUpdateReg->execute([$classId, $tahun_ajaran, $tipe_nilai, $regId]);
                    }
                }
            }
        }
        
        // Nama tutor sudah di-resolve saat membuat kelas; jangan ubah kelas periode lain.
        
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
    header("Location: tutorial-hasil-plotting.php");
    exit;
}
