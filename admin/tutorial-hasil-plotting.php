<?php
/**
 * LPPAI Corner - Admin: Data Peserta Tutorial
 */
define('PAGE_TITLE', 'Hasil Plotting & Jadwal');
require_once __DIR__ . '/../includes/auth.php';
requireAdmin();

$pdo = getDBConnection();

// Sinkronkan nama dosen_pengampu pada tutorial_classes dengan nama lengkap beserta gelar di tabel users (role = dosen)
try {
    $pdo->exec("
        UPDATE tutorial_classes tc
        JOIN users u ON u.nama_lengkap LIKE CONCAT('%', tc.dosen_pengampu, '%') AND u.nama_lengkap != tc.dosen_pengampu AND u.role = 'dosen'
        SET tc.dosen_pengampu = u.nama_lengkap
    ");
} catch(PDOException $e) {
    // Ignore if errors
}

$message = '';
$msgType = '';
$isMessageHtml = false;

// Ambil data gelombang aktif terlebih dahulu agar bisa dipakai di pengecekan kuota
$active_gel = $pdo->query("SELECT * FROM master_gelombang ORDER BY created_at DESC LIMIT 1")->fetch();

// Condition to exclude Lulus
$notLulusSQL = "u.id NOT IN (
    SELECT tr_sub.user_id FROM tutorial_registrations tr_sub 
    WHERE tr_sub.nilai_thaharah IS NOT NULL AND tr_sub.nilai_shalat IS NOT NULL AND tr_sub.nilai_surat_pendek IS NOT NULL AND tr_sub.nilai_amaliyah IS NOT NULL AND tr_sub.nilai_jenazah IS NOT NULL AND tr_sub.nilai_ujian_tulis IS NOT NULL
    AND tr_sub.nilai_thaharah >= (CASE WHEN LOWER(tr_sub.tipe_nilai) = 'pretest' THEN 80 ELSE 70 END)
    AND tr_sub.nilai_shalat >= (CASE WHEN LOWER(tr_sub.tipe_nilai) = 'pretest' THEN 80 ELSE 70 END)
    AND tr_sub.nilai_surat_pendek >= (CASE WHEN LOWER(tr_sub.tipe_nilai) = 'pretest' THEN 80 ELSE 70 END)
    AND tr_sub.nilai_amaliyah >= (CASE WHEN LOWER(tr_sub.tipe_nilai) = 'pretest' THEN 80 ELSE 70 END)
    AND tr_sub.nilai_jenazah >= (CASE WHEN LOWER(tr_sub.tipe_nilai) = 'pretest' THEN 80 ELSE 70 END)
    AND tr_sub.nilai_ujian_tulis >= (CASE WHEN LOWER(tr_sub.tipe_nilai) = 'pretest' THEN 80 ELSE 70 END)
)";

// AJAX API Endpoint: Ambil daftar mahasiswa berdasarkan jurusan
if (isset($_GET['ajax_jurusan'])) {
    header('Content-Type: application/json');
    $jurusan = $_GET['ajax_jurusan'];
    $stmt = $pdo->prepare("
        SELECT u.id as user_id, u.nim, u.nama_lengkap, 
               MAX(tr.id) as reg_id,
               (CASE WHEN MAX(tr.id) IS NOT NULL THEN 1 ELSE 0 END) as is_registered,
               MAX(tr.hari_pilihan) as hari_pilihan
        FROM users u 
        LEFT JOIN tutorial_registrations tr ON u.id = tr.user_id AND (tr.tahun_ajaran LIKE '2025%' OR tr.tahun_ajaran LIKE '2026%' OR tr.tahun_ajaran LIKE '2027%' OR tr.tahun_ajaran LIKE '2028%' OR tr.tahun_ajaran LIKE '2029%' OR tr.tahun_ajaran LIKE '2030%' OR tr.tahun_ajaran IS NULL)
        WHERE u.program_studi = ? AND u.role = 'mahasiswa' AND $notLulusSQL
        GROUP BY u.id
        ORDER BY u.nama_lengkap
    ");
    $stmt->execute([$jurusan]);
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    exit;
}

/* =============================================================
   POST HANDLERS
   ============================================================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $token = $_POST['csrf_token'] ?? '';
    if (!verifyCsrf($token)) {
        $message = 'Sesi tidak valid.';
        $msgType = 'danger';
    } else {
        $action = $_POST['action'];

        /* ---- BUAT KELAS & TAMBAH PESERTA ---- */
        if ($action === 'assign') {
            $namaKelas  = trim($_POST['nama_kelas'] ?? '');
            $dosen      = trim($_POST['dosen_pengampu'] ?? '');
            $hari       = trim($_POST['hari'] ?? '');
            $jam        = trim($_POST['jam'] ?? '');
            $ruangan    = trim($_POST['ruangan'] ?? '');
            $kuota      = (int)($_POST['kuota'] ?? 0);
            $userIds  = array_filter(array_map('intval', (array)($_POST['user_ids'] ?? [])));

            // Nilai Default
            $gelombang = 'gel1';
            $tahun = date('Y');
            $semester = $tahun . '-' . ($tahun+1) . '-Ganjil';
            $tahun_ajaran = $tahun . '-' . ($tahun+1);
            
            $tipe_nilai = 'mandiri';
            if ($gelombang === 'gel1') $tipe_nilai = 'gel 1';
            else if ($gelombang === 'gel2') $tipe_nilai = 'gel 2';

                if (empty($namaKelas) || empty($userIds)) {
                    $message = 'Isi Nama Kelas dan pilih minimal satu mahasiswa.';
                $msgType = 'danger';
            } else {
                $pdo->prepare("INSERT INTO tutorial_classes (nama_kelas, dosen_pengampu, hari, jam, ruangan, gelombang, semester, kuota) VALUES (?,?,?,?,?,?,?,?)")
                    ->execute([$namaKelas, $dosen, $hari, $jam, $ruangan, $gelombang, $semester, $kuota]);
                
                $classId = (int)$pdo->lastInsertId();

                $added = 0;
                $stmtInsert = $pdo->prepare("INSERT INTO tutorial_registrations (user_id, tutorial_class_id, status, tahun_ajaran, tipe_nilai) VALUES (?, ?, 'terdaftar', ?, ?)");

                foreach ($userIds as $uid) {
                    // Cek apakah mahasiswa ini sudah ada di kelas lain?
                    // Karena membuat kelas baru, tidak mungkin sudah ada di kelas *ini*.
                    // Kita insert saja
                    $stmtInsert->execute([$uid, $classId, $tahun_ajaran, $tipe_nilai]);
                    $added++;
                }

                $message  = "Kelas '$namaKelas' berhasil dibuat dan $added mahasiswa dimasukkan ke dalamnya.";
                $msgType  = 'success';
            }

        /* ---- UPDATE STATUS ---- */
        } elseif ($action === 'update_status') {
            $regId  = (int)($_POST['reg_id'] ?? 0);
            $status = $_POST['status'] ?? '';
            $nilaiRaw = $_POST['nilai'] ?? '';
            $nilai  = ($nilaiRaw !== '') ? (float)$nilaiRaw : null;
            if (in_array($status, ['terdaftar', 'aktif', 'lulus', 'tidak_lulus', 'mengundurkan_diri'])) {
                $pdo->prepare("UPDATE tutorial_registrations SET status = ?, nilai_akhir = ? WHERE id = ?")
                    ->execute([$status, $nilai, $regId]);
                $message = 'Status berhasil diperbarui.';
                $msgType = 'success';
            }

        /* ---- EDIT PESERTA & KELAS ---- */
        } elseif ($action === 'edit_peserta') {
            $regId  = (int)($_POST['reg_id'] ?? 0);
            $newClassId = (int)($_POST['tutorial_class_id'] ?? 0);
            $newRuangan = trim($_POST['ruangan'] ?? '');

            if ($regId > 0 && $newClassId > 0) {
                // Ambil tahun_ajaran dari kelas yang dipilih
                $stmtClass = $pdo->prepare("SELECT semester, gelombang FROM tutorial_classes WHERE id = ?");
                $stmtClass->execute([$newClassId]);
                $classData = $stmtClass->fetch();
                $semStr = $classData ? $classData['semester'] : '';
                $gel = $classData ? $classData['gelombang'] : '';
                
                $tahun_ajaran = '';
                if (preg_match('/^(\d{4}[-\/]\d{4})/', $semStr, $m)) {
                    $tahun_ajaran = str_replace('/', '-', $m[1]);
                }
                
                $tipe_nilai = 'mandiri';
                if ($gel === 'gel1') $tipe_nilai = 'gel 1';
                else if ($gel === 'gel2') $tipe_nilai = 'gel 2';

                // Pindahkan mahasiswa
                $pdo->prepare("UPDATE tutorial_registrations SET tutorial_class_id = ?, tahun_ajaran = ?, tipe_nilai = ? WHERE id = ?")
                    ->execute([$newClassId, $tahun_ajaran, $tipe_nilai, $regId]);

                // Update ruangan kelas jika diisi
                if ($newRuangan !== '') {
                    $pdo->prepare("UPDATE tutorial_classes SET ruangan = ? WHERE id = ?")
                        ->execute([$newRuangan, $newClassId]);
                }

                $message = 'Data penempatan berhasil diperbarui.';
                $msgType = 'success';
            }

        /* ---- HAPUS DARI KELAS ---- */
        } elseif ($action === 'delete') {
            $regId = (int)($_POST['reg_id'] ?? 0);
            if ($regId > 0) {
                // Set tutorial_class_id menjadi NULL agar mahasiswa keluar dari kelas
                $pdo->prepare("UPDATE tutorial_registrations SET tutorial_class_id = NULL WHERE id = ?")
                    ->execute([$regId]);
                $message = 'Peserta berhasil dihapus dari kelas (dikembalikan ke status Menunggu).';
                $msgType = 'success';
            }

        /* ---- HAPUS MASSAL DARI KELAS ---- */
        } elseif ($action === 'bulk_delete') {
            $regIds = $_POST['reg_ids'] ?? [];
            if (!empty($regIds) && is_array($regIds)) {
                $placeholders = implode(',', array_fill(0, count($regIds), '?'));
                $pdo->prepare("UPDATE tutorial_registrations SET tutorial_class_id = NULL WHERE id IN ($placeholders)")
                    ->execute($regIds);
                $message = count($regIds) . ' peserta berhasil dihapus dari kelas (dikembalikan ke status Menunggu).';
                $msgType = 'success';
            }
        
        /* ---- HAPUS PENDAFTARAN TOTAL ---- */
        } elseif ($action === 'delete_registration') {
            $regId = (int)($_POST['reg_id'] ?? 0);
            if ($regId > 0) {
                $pdo->prepare("DELETE FROM tutorial_registrations WHERE id = ?")->execute([$regId]);
                $message = 'Data pendaftar berhasil dihapus sepenuhnya.';
                $msgType = 'success';
            }

        /* ---- HAPUS MASSAL PENDAFTARAN TOTAL ---- */
        } elseif ($action === 'bulk_delete_registration') {
            $regIds = $_POST['reg_ids'] ?? [];
            if (!empty($regIds) && is_array($regIds)) {
                $placeholders = implode(',', array_fill(0, count($regIds), '?'));
                $pdo->prepare("DELETE FROM tutorial_registrations WHERE id IN ($placeholders)")->execute($regIds);
                $message = count($regIds) . ' pendaftar berhasil dihapus sepenuhnya.';
                $msgType = 'success';
            }

        /* ---- HAPUS KELAS ---- */
        } elseif ($action === 'delete_class') {
            $classId = (int)($_POST['class_id'] ?? 0);
            if ($classId > 0) {
                $pdo->beginTransaction();
                try {
                    // Hapus data absensi terkait kelas ini
                    $pdo->prepare("DELETE FROM tutorial_attendance WHERE tutorial_class_id = ?")
                        ->execute([$classId]);
                    // Set tutorial_class_id = NULL untuk semua mahasiswa di kelas ini
                    $pdo->prepare("UPDATE tutorial_registrations SET tutorial_class_id = NULL WHERE tutorial_class_id = ?")
                        ->execute([$classId]);
                    // Hapus kelas dari tutorial_classes
                    $pdo->prepare("DELETE FROM tutorial_classes WHERE id = ?")
                        ->execute([$classId]);
                    $pdo->commit();
                    $message = 'Kelas berhasil dihapus beserta semua pesertanya.';
                    $msgType = 'success';
                } catch (Exception $e) {
                    $pdo->rollBack();
                    $message = 'Gagal menghapus kelas: ' . $e->getMessage();
                    $msgType = 'danger';
                }
            }
            
        /* ---- EDIT PENDAFTARAN ---- */
        } elseif ($action === 'edit_pendaftaran') {
            $regId = (int)($_POST['reg_id'] ?? 0);
            $newHariPilihan = trim($_POST['hari_pilihan'] ?? '');
            if ($regId > 0 && $newHariPilihan !== '') {
                $pdo->prepare("UPDATE tutorial_registrations SET hari_pilihan = ? WHERE id = ?")->execute([$newHariPilihan, $regId]);
                $message = 'Pilihan hari pendaftar berhasil diperbarui.';
                $msgType = 'success';
            }
            
        /* ---- PENDAFTARAN SINGLE (DARI TABEL KOLEKTIF) ---- */
        } elseif ($action === 'add_single_registration') {
            $user_id = (int)($_POST['user_id'] ?? 0);
            $hari_pilihan = trim($_POST['hari_pilihan'] ?? '');
            $gelombang = $active_gel['gelombang'] ?? 'gel1';

            if ($user_id > 0 && $hari_pilihan !== '') {
                $stmtCek = $pdo->prepare("SELECT COUNT(*) FROM tutorial_registrations WHERE user_id = ? AND (tahun_ajaran LIKE '2025%' OR tahun_ajaran LIKE '2026%' OR tahun_ajaran LIKE '2027%' OR tahun_ajaran LIKE '2028%' OR tahun_ajaran LIKE '2029%' OR tahun_ajaran LIKE '2030%' OR tahun_ajaran IS NULL)");
                $stmtCek->execute([$user_id]);
                if ($stmtCek->fetchColumn() > 0) {
                    $message = 'Mahasiswa ini sudah terdaftar sebelumnya.';
                    $msgType = 'warning';
                } else {
                    $dayLower = strtolower($hari_pilihan);
                    $totalKuota = $active_gel["kuota_$dayLower"] ?? 0;
                    
                    $stmtCount = $pdo->prepare("SELECT COUNT(*) FROM tutorial_registrations WHERE LOWER(hari_pilihan) = LOWER(?) AND (tahun_ajaran LIKE '2025%' OR tahun_ajaran LIKE '2026%' OR tahun_ajaran LIKE '2027%' OR tahun_ajaran LIKE '2028%' OR tahun_ajaran LIKE '2029%' OR tahun_ajaran LIKE '2030%' OR tahun_ajaran IS NULL)");
                    $stmtCount->execute([$hari_pilihan]);
                    $terisi = $stmtCount->fetchColumn();
                    $sisaKuota = $totalKuota - $terisi;
                    
                    if ($sisaKuota <= 0) {
                        $message = "Maaf, kuota untuk hari $hari_pilihan sudah penuh.";
                        $msgType = 'danger';
                    } else {
                        $stmtInsert = $pdo->prepare("INSERT INTO tutorial_registrations (user_id, status, hari_pilihan, gelombang) VALUES (?, 'terdaftar', ?, ?)");
                        $stmtInsert->execute([$user_id, $hari_pilihan, $gelombang]);
                        $message = 'Mahasiswa berhasil didaftarkan.';
                        $msgType = 'success';
                    }
                }
            } else {
                $message = 'Data tidak valid.';
                $msgType = 'danger';
            }

        /* ---- PENDAFTARAN KOLEKTIF ---- */
        } elseif ($action === 'pendaftaran_kolektif') {
            $jurusan = trim($_POST['jurusan'] ?? '');
            $hari_pilihan = trim($_POST['hari_pilihan'] ?? '');
            $gelombang = $active_gel['gelombang'] ?? 'gel1';

            if ($jurusan !== '' && $hari_pilihan !== '') {
                $dayLower = strtolower($hari_pilihan);
                $totalKuota = $active_gel["kuota_$dayLower"] ?? 0;
                
                $stmtCount = $pdo->prepare("SELECT COUNT(*) FROM tutorial_registrations WHERE LOWER(hari_pilihan) = LOWER(?) AND (tahun_ajaran LIKE '2025%' OR tahun_ajaran LIKE '2026%' OR tahun_ajaran LIKE '2027%' OR tahun_ajaran LIKE '2028%' OR tahun_ajaran LIKE '2029%' OR tahun_ajaran LIKE '2030%')");
                $stmtCount->execute([$hari_pilihan]);
                $terisi = $stmtCount->fetchColumn();
                $sisaKuota = $totalKuota - $terisi;
                
                if ($sisaKuota <= 0) {
                    $message = "Maaf, kuota untuk hari $hari_pilihan sudah penuh. Silakan tambah kuota hari tersebut di tab Pengaturan.";
                    $msgType = 'danger';
                } else {
                    $stmt = $pdo->prepare("
                        SELECT u.id, u.nama_lengkap FROM users u
                        WHERE u.program_studi = ? 
                          AND u.id NOT IN (SELECT user_id FROM tutorial_registrations WHERE tahun_ajaran LIKE '2025%' OR tahun_ajaran LIKE '2026%' OR tahun_ajaran LIKE '2027%' OR tahun_ajaran LIKE '2028%' OR tahun_ajaran LIKE '2029%' OR tahun_ajaran LIKE '2030%' OR tahun_ajaran IS NULL)
                          AND $notLulusSQL
                    ");
                    $stmt->execute([$jurusan]);
                    $usersToRegister = $stmt->fetchAll(PDO::FETCH_ASSOC);

                    if (empty($usersToRegister)) {
                        $message = 'Tidak ada mahasiswa di jurusan ' . htmlspecialchars($jurusan) . ' yang belum mendaftar.';
                        $msgType = 'warning';
                    } else {
                        $toRegister = array_slice($usersToRegister, 0, $sisaKuota);
                        $failedToRegister = array_slice($usersToRegister, $sisaKuota);
                        $added = 0;
                        $stmtInsert = $pdo->prepare("INSERT INTO tutorial_registrations (user_id, status, hari_pilihan, gelombang) VALUES (?, 'terdaftar', ?, ?)");
                        foreach ($toRegister as $u) {
                            $stmtInsert->execute([$u['id'], $hari_pilihan, $gelombang]);
                            $added++;
                        }
                        
                        if (!empty($failedToRegister)) {
                            $failedNames = array_map(function($u) { return "<li><strong>" . htmlspecialchars($u['nama_lengkap']) . "</strong></li>"; }, $failedToRegister);
                            $failedCount = count($failedToRegister);
                            $message = "Hanya $added mahasiswa jurusan " . htmlspecialchars($jurusan) . " yang didaftarkan. <br><br><strong>$failedCount mahasiswa berikut gagal didaftarkan karena kuota hari $hari_pilihan penuh:</strong><ul style='color: #334155; margin-top: 8px; margin-bottom: 12px; padding-left: 20px; max-height: 150px; overflow-y: auto; border: 1px solid #fcd34d; background: #fffbeb; border-radius: 6px; padding: 10px 10px 10px 30px;'>" . implode('', $failedNames) . "</ul><em>👉 Tips: Pilih jurusan yang sama dan pilih <b>Hari Lain</b> pada form di bawah untuk langsung mendaftarkan sisa mahasiswa ini.</em>";
                            $msgType = 'warning';
                            $isMessageHtml = true;
                        } else {
                            $message = "$added mahasiswa dari jurusan " . htmlspecialchars($jurusan) . " berhasil didaftarkan kolektif pada hari $hari_pilihan.";
                            $msgType = 'success';
                        }
                    }
                }
            } else {
                $message = 'Silakan pilih jurusan dan hari.';
                $msgType = 'danger';
            }
        /* ---- UPDATE TUTORS & KUOTA ---- */
        } elseif ($action === 'update_tutors_kuota') {
            $active_gel_id = (int)($_POST['gel_id'] ?? 0);
            if ($active_gel_id > 0) {
                $kuota_senin = (int)($_POST['kuota_senin'] ?? 0);
                $kuota_selasa = (int)($_POST['kuota_selasa'] ?? 0);
                $kuota_rabu = (int)($_POST['kuota_rabu'] ?? 0);
                $kuota_kamis = (int)($_POST['kuota_kamis'] ?? 0);
                $kuota_jumat = (int)($_POST['kuota_jumat'] ?? 0);
                
                $tutors_senin = isset($_POST['tutors_senin']) && is_array($_POST['tutors_senin']) ? implode('|||', array_filter(array_map('trim', $_POST['tutors_senin']))) : '';
                $tutors_selasa = isset($_POST['tutors_selasa']) && is_array($_POST['tutors_selasa']) ? implode('|||', array_filter(array_map('trim', $_POST['tutors_selasa']))) : '';
                $tutors_rabu = isset($_POST['tutors_rabu']) && is_array($_POST['tutors_rabu']) ? implode('|||', array_filter(array_map('trim', $_POST['tutors_rabu']))) : '';
                $tutors_kamis = isset($_POST['tutors_kamis']) && is_array($_POST['tutors_kamis']) ? implode('|||', array_filter(array_map('trim', $_POST['tutors_kamis']))) : '';
                $tutors_jumat = isset($_POST['tutors_jumat']) && is_array($_POST['tutors_jumat']) ? implode('|||', array_filter(array_map('trim', $_POST['tutors_jumat']))) : '';
                
                $pdo->prepare("UPDATE master_gelombang SET kuota_senin=?, kuota_selasa=?, kuota_rabu=?, kuota_kamis=?, kuota_jumat=?, tutors_senin=?, tutors_selasa=?, tutors_rabu=?, tutors_kamis=?, tutors_jumat=? WHERE id=?")
                    ->execute([$kuota_senin, $kuota_selasa, $kuota_rabu, $kuota_kamis, $kuota_jumat, $tutors_senin, $tutors_selasa, $tutors_rabu, $tutors_kamis, $tutors_jumat, $active_gel_id]);
                
                $message = 'Pengaturan Dosen dan Kuota berhasil disimpan.';
                $msgType = 'success';
            }
        }
    }
}

/* =============================================================
   DATA
   ============================================================= */
$students = $pdo->query("
    SELECT id, nama_lengkap, nim, program_studi
    FROM users
    WHERE role = 'mahasiswa'
    AND id IN (SELECT user_id FROM tutorial_registrations WHERE tahun_ajaran LIKE '2025%' OR tahun_ajaran LIKE '2026%' OR tahun_ajaran LIKE '2027%' OR tahun_ajaran LIKE '2028%' OR tahun_ajaran LIKE '2029%' OR tahun_ajaran LIKE '2030%' OR tahun_ajaran IS NULL)
    ORDER BY nama_lengkap
")->fetchAll();
$classes  = $pdo->query("SELECT * FROM tutorial_classes WHERE (semester LIKE '2025/%' OR semester LIKE '2026/%' OR semester LIKE '2027/%' OR semester LIKE '2028/%' OR semester LIKE '2029/%' OR semester LIKE '2030/%') ORDER BY gelombang, hari, nama_kelas")->fetchAll();
$gelLabels = ['gel1' => 'Gel.1', 'gel2' => 'Gel.2', 'mandiri' => 'Mandiri'];
$roomsList = $pdo->query("SELECT id, ruang FROM rooms ORDER BY ruang ASC")->fetchAll();
$tutorsList = $pdo->query("SELECT nim as id, nama_lengkap as nama FROM users WHERE role = 'dosen' ORDER BY nama_lengkap ASC")->fetchAll();

$registeredCounts = ['Senin' => 0, 'Selasa' => 0, 'Rabu' => 0, 'Kamis' => 0, 'Jumat' => 0];
if ($active_gel) {
    try {
        // Coba hitung berdasarkan hari_pilihan dari SEMUA registrasi aktif
        $stmtCount = $pdo->query("SELECT hari_pilihan, COUNT(*) as cnt FROM tutorial_registrations WHERE (tahun_ajaran LIKE '2025%' OR tahun_ajaran LIKE '2026%' OR tahun_ajaran LIKE '2027%' OR tahun_ajaran LIKE '2028%' OR tahun_ajaran LIKE '2029%' OR tahun_ajaran LIKE '2030%' OR tahun_ajaran IS NULL) GROUP BY hari_pilihan");
        foreach ($stmtCount->fetchAll() as $row) {
            $hari = ucfirst(strtolower(trim($row['hari_pilihan'])));
            if (isset($registeredCounts[$hari])) {
                $registeredCounts[$hari] += $row['cnt'];
            }
        }
    } catch (Exception $e) {
        // Abaikan error
    }
}

// Kumpulkan hari unik dari kelas yang ada
$hariList = [];
foreach ($classes as $c) {
    if (!empty($c['hari']) && !in_array($c['hari'], $hariList)) {
        $hariList[] = $c['hari'];
    }
}
sort($hariList);

// Kumpulkan tutor unik dari kelas yang ada dan gabungkan dengan tabel tutors
$allTutors = [];
foreach ($tutorsList as $t) {
    $allTutors[] = $t['nama'];
}
foreach ($classes as $c) {
    if (!empty($c['dosen_pengampu']) && !in_array($c['dosen_pengampu'], $allTutors)) {
        $allTutors[] = $c['dosen_pengampu'];
    }
}
sort($allTutors);

// Kumpulkan ruangan unik dari kelas yang ada dan gabungkan dengan tabel rooms
$allRooms = [];
foreach ($roomsList as $rm) {
    $allRooms[] = $rm['ruang'];
}
foreach ($classes as $c) {
    if (!empty($c['ruangan']) && !in_array($c['ruangan'], $allRooms)) {
        $allRooms[] = $c['ruangan'];
    }
}
sort($allRooms);

// Build lookup: class_id → hari, untuk filter JS
$classHariMap = [];
foreach ($classes as $c) {
    $classHariMap[$c['id']] = $c['hari'] ?? '';
}

// Mahasiswa yang sudah terdaftar (untuk tanda di checklist)
$registered = $pdo->query("
    SELECT tr.user_id, tr.tutorial_class_id
    FROM tutorial_registrations tr
")->fetchAll(PDO::FETCH_KEY_PAIR); // user_id => tutorial_class_id (mungkin multi, gunakan fetchAll biasa)

$registeredPairs = [];
foreach ($pdo->query("SELECT user_id, tutorial_class_id FROM tutorial_registrations WHERE (tahun_ajaran LIKE '2025%' OR tahun_ajaran LIKE '2026%' OR tahun_ajaran LIKE '2027%' OR tahun_ajaran LIKE '2028%' OR tahun_ajaran LIKE '2029%' OR tahun_ajaran LIKE '2030%' OR tahun_ajaran IS NULL)")->fetchAll() as $row) {
    $registeredPairs[$row['user_id'] . '_' . $row['tutorial_class_id']] = true;
}

$registrations = $pdo->query("
    SELECT tr.*, u.nama_lengkap, u.nim, u.program_studi, tc.nama_kelas, tc.gelombang, tc.hari, tc.dosen_pengampu, tc.ruangan
    FROM tutorial_registrations tr
    JOIN users u ON tr.user_id = u.id
    JOIN tutorial_classes tc ON tr.tutorial_class_id = tc.id
    WHERE tr.id IN (SELECT MAX(id) FROM tutorial_registrations WHERE (tahun_ajaran LIKE '2025%' OR tahun_ajaran LIKE '2026%' OR tahun_ajaran LIKE '2027%' OR tahun_ajaran LIKE '2028%' OR tahun_ajaran LIKE '2029%' OR tahun_ajaran LIKE '2030%' OR tahun_ajaran IS NULL) GROUP BY user_id)
      AND $notLulusSQL
    ORDER BY tc.gelombang, tc.nama_kelas, u.nama_lengkap
")->fetchAll();

$allStudents = $pdo->query("
    SELECT u.id as user_id, u.nim, u.nama_lengkap, u.program_studi, 
           MAX(tr.id) as reg_id, MAX(tr.hari_pilihan) as hari_pilihan,
           (CASE WHEN MAX(tr.id) IS NOT NULL THEN 1 ELSE 0 END) as is_registered
    FROM users u
    LEFT JOIN tutorial_registrations tr ON u.id = tr.user_id AND (tr.tahun_ajaran LIKE '2025%' OR tr.tahun_ajaran LIKE '2026%' OR tr.tahun_ajaran LIKE '2027%' OR tr.tahun_ajaran LIKE '2028%' OR tr.tahun_ajaran LIKE '2029%' OR tr.tahun_ajaran LIKE '2030%' OR tr.tahun_ajaran IS NULL)
    WHERE u.role = 'mahasiswa' AND $notLulusSQL
    GROUP BY u.id
    ORDER BY u.program_studi, u.nama_lengkap
")->fetchAll(PDO::FETCH_ASSOC);


$allRegistrations = $pdo->query("
    SELECT tr.*, u.nama_lengkap, u.nim, u.program_studi
    FROM tutorial_registrations tr
    JOIN users u ON tr.user_id = u.id
    WHERE tr.id IN (SELECT MAX(id) FROM tutorial_registrations WHERE (tahun_ajaran LIKE '2025%' OR tahun_ajaran LIKE '2026%' OR tahun_ajaran LIKE '2027%' OR tahun_ajaran LIKE '2028%' OR tahun_ajaran LIKE '2029%' OR tahun_ajaran LIKE '2030%' OR tahun_ajaran IS NULL) GROUP BY user_id)
      AND $notLulusSQL
    ORDER BY tr.created_at DESC
")->fetchAll();

$listJurusan = $pdo->query("SELECT DISTINCT program_studi FROM users WHERE program_studi IS NOT NULL AND program_studi != '' ORDER BY program_studi")->fetchAll(PDO::FETCH_COLUMN);

include __DIR__ . '/../includes/header.php';
?>

<?php if ($message): ?>
    <div class="alert alert-<?= $msgType ?>"><?= $isMessageHtml ? $message : sanitize($message) ?></div>
<?php endif; ?>

<style>
.custom-tabs {
    display: flex;
    gap: 8px;
    margin-bottom: 20px;
    border-bottom: 1px solid #e2e8f0;
    padding-bottom: 0;
    overflow-x: auto;
}
.custom-tab {
    padding: 12px 20px;
    cursor: pointer;
    background: transparent;
    border: none;
    font-size: 15px;
    font-weight: 600;
    color: #64748b;
    border-bottom: 3px solid transparent;
    transition: all 0.2s ease;
    white-space: nowrap;
}
.custom-tab:hover {
    color: #3b82f6;
    background: #f8fafc;
}
.custom-tab.active {
    color: #3b82f6;
    border-bottom-color: #3b82f6;
}
.tab-content {
    display: none;
    animation: fadeIn 0.3s ease;
}
.tab-content.active {
    display: block;
}
@keyframes fadeIn {
    from { opacity: 0; transform: translateY(5px); }
    to { opacity: 1; transform: translateY(0); }
}
</style>









<div>

<!-- ===================================================
     CARD: DAFTAR PESERTA
     =================================================== -->
<?php
// Siapkan data untuk grafik statistik
$statsKelas = [];
$statsLabels = [];
foreach ($registrations as $r) {
    $kelas = $r['nama_kelas'];
    $tutor = $r['dosen_pengampu'] ?: '-';
    $key = $kelas . '|' . $tutor;
    if (!isset($statsKelas[$key])) {
        $statsKelas[$key] = 0;
        $statsLabels[$key] = [$kelas, "(" . $tutor . ")"];
    }
    $statsKelas[$key]++;
}
ksort($statsKelas); // Urutkan nama kelas
$chartLabels = json_encode(array_values($statsLabels));
$chartData = json_encode(array_values($statsKelas));
?>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<div class="card" style="margin-top: 30px;">
    <div class="card-header" style="padding:16px 20px; background:#f8fafc; border-bottom:1px solid #e2e8f0;">
        <span style="font-size:16px; font-weight:600; color:#1e293b; display:flex; align-items:center; gap:8px;">
            <span style="font-size:20px;">📊</span> Grafik Statistik Distribusi Mahasiswa
        </span>
    </div>
    <div class="card-body" style="padding: 20px;">
        <?php if(empty($statsKelas)): ?>
            <div style="text-align: center; color: #64748b; font-style: italic;">Data belum tersedia untuk grafik ini.</div>
        <?php else: ?>
            <div style="position: relative; height: 300px; width: 100%;">
                <canvas id="scheduleChart"></canvas>
            </div>
            <script>
                (function() {
                    var canvas = document.getElementById('scheduleChart');
                    if (!canvas) return;
                    
                    if (window.scheduleChartInstance) {
                        window.scheduleChartInstance.destroy();
                    }
                    
                    var ctx = canvas.getContext('2d');
                    window.scheduleChartInstance = new Chart(ctx, {
                        type: 'bar',
                        data: {
                            labels: <?= $chartLabels ?>,
                            datasets: [{
                                label: 'Jumlah Mahasiswa',
                                data: <?= $chartData ?>,
                                backgroundColor: '#4f46e5',
                                borderRadius: 4,
                                barPercentage: 0.6
                            }]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            plugins: {
                                legend: {
                                    display: false
                                },
                                title: {
                                    display: false
                                }
                            },
                            scales: {
                                x: {
                                    ticks: {
                                        font: {
                                            size: 11
                                        }
                                    }
                                },
                                y: {
                                    beginAtZero: true,
                                    ticks: {
                                        stepSize: 1
                                    }
                                }
                            }
                        }
                    });
                })();
            </script>
        <?php endif; ?>
    </div>
</div>



<div class="card" style="margin-top: 30px;">
    <div class="card-header" style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:16px; padding:16px 20px; background:#f8fafc; border-bottom:1px solid #e2e8f0;">
        <span style="font-size:16px; font-weight:600; color:#1e293b; display:flex; align-items:center; gap:8px;">
            <span style="font-size:20px;">📋</span> Daftar Peserta Tutorial (<span class="badge-count" style="background:#e0e7ff; color:#4f46e5; padding:2px 8px; border-radius:12px; font-size:14px;">0</span>)
        </span>
        <div style="display:flex; gap:12px; align-items:center;">
            <div style="flex:1; max-width:300px;">
                <select id="filterKelasBottom"
                    style="width:100%;padding:10px 14px;border:1.5px solid #cbd5e1;border-radius:8px;font-size:14px;font-family:inherit;background:#fff;font-weight:500;color:#334155;box-shadow:0 1px 2px rgba(0,0,0,0.05);cursor:pointer;">
                    <option value="ALL">-- Tampilkan Semua Kelas --</option>
                    <option value="">-- Pilih Kelas --</option>
                    <?php foreach ($classes as $c): ?>
                        <option value="<?= $c['id'] ?>">
                            <?= sanitize($c['nama_kelas']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button type="button" class="btn btn-sm btn-danger" id="btnDeleteClass" style="display:none;" data-my-confirm="Yakin ingin menghapus kelas ini beserta semua pesertanya?">🗑️ Hapus Kelas</button>
        </div>
    </div>
    <div class="card-body">
        <div class="empty-state" id="tableEmptyState" style="display: flex; flex-direction:column; align-items:center;">
            <div class="icon" style="font-size:3rem; margin-bottom:10px;">📋</div>
            <h3>Pilih Kelas Terlebih Dahulu</h3>
            <p>Daftar peserta akan muncul setelah Anda memilih kelas tutorial di atas.</p>
        </div>

        <div id="bulkActionsContainer" style="margin-bottom: 16px; display: none;">
            <button type="button" class="btn btn-sm btn-secondary" id="btnCheckAll" data-checked="false" style="background:#f1f5f9; color:#475569; border:1px solid #cbd5e1;">☑️ Centang Semua</button>
            <button type="button" class="btn btn-sm btn-danger" id="btnBulkDelete" style="margin-left:8px;">🗑️ Hapus</button>
        </div>
        
        <form id="formBulkDelete" method="POST" style="display:none;">
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
            <input type="hidden" name="action" value="bulk_delete">
        </form>

        <div class="table-responsive" id="tableResponsiveContainer" style="display: none;">
            <table id="participantTable">
                <thead>
                    <tr>
                        <th style="width: 40px; text-align: center;">Pilih</th>
                        <th>Nama Kelas</th>
                        <th>Nama Dosen</th>
                        <th>Nama Mahasiswa</th>
                        <th>Nama Ruang</th>
                        <th>Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($registrations as $r): ?>
                    <tr data-class-id="<?= $r['tutorial_class_id'] ?>">
                        <td style="text-align: center;">
                            <input type="checkbox" class="check-peserta" name="reg_ids[]" value="<?= $r['id'] ?>" style="width: 18px; height: 18px; cursor: pointer;">
                        </td>
                        <td><strong><?= sanitize($r['nama_kelas']) ?></strong></td>
                        <td><?= sanitize($r['dosen_pengampu'] ?: '-') ?></td>
                        <td><?= sanitize($r['nama_lengkap']) ?></td>
                        <td><span class="badge badge-success"><?= sanitize($r['ruangan'] ?: 'Belum Ada Ruang') ?></span></td>
                        <td>
                            <button type="button" class="btn btn-sm btn-warning" style="margin-right:4px;"
                                onclick="openEditModal(<?= (int)$r['id'] ?>, <?= (int)$r['tutorial_class_id'] ?>, <?= htmlspecialchars(json_encode($r['dosen_pengampu'] ?: ''), ENT_QUOTES) ?>, <?= htmlspecialchars(json_encode($r['ruangan'] ?: ''), ENT_QUOTES) ?>)">
                                Edit
                            </button>
                            <form method="POST" style="display:inline;">
                                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="reg_id" value="<?= $r['id'] ?>">
                                <button type="submit" class="btn btn-sm btn-danger"
                                    data-confirm="Hapus data ini?">Hapus</button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

</div> <!-- End of tab-peserta -->

<!-- Modal Edit Peserta -->
<div id="editPesertaModal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:9999; align-items:center; justify-content:center;">
    <div style="background:#fff; width:90%; max-width:500px; border-radius:12px; padding:24px; box-shadow:0 4px 6px rgba(0,0,0,0.1);">
        <h3 style="margin-top:0; margin-bottom:20px; font-size:18px; color:#1e293b;">Edit Penempatan Peserta</h3>
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
            <input type="hidden" name="action" value="edit_peserta">
            <input type="hidden" name="reg_id" id="edit_reg_id" value="">
            
            <div class="form-group" style="margin-bottom:16px;">
                <label style="display:block; margin-bottom:8px; font-size:14px; color:#475569;">Nama Kelas</label>
                <select name="tutorial_class_id" id="edit_class_id" required
                    style="width:100%; padding:10px; border:1.5px solid #cbd5e1; border-radius:8px; font-size:14px;">
                    <?php foreach ($classes as $c): ?>
                        <option value="<?= $c['id'] ?>"><?= sanitize($c['nama_kelas']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group" style="margin-bottom:16px;">
                <label style="display:block; margin-bottom:8px; font-size:14px; color:#475569;">Nama Dosen</label>
                <div id="display_dosen" style="padding:10px; border:1.5px solid #cbd5e1; border-radius:8px; font-size:14px; background-color:#f1f5f9; color:#475569; min-height:42px; font-weight:500;">
                    -
                </div>
            </div>

            <div class="form-group" style="margin-bottom:24px;">
                <label style="display:block; margin-bottom:8px; font-size:14px; color:#475569;">Nama Ruang</label>
                <select name="ruangan" id="edit_ruangan"
                    style="width:100%; padding:10px; border:1.5px solid #cbd5e1; border-radius:8px; font-size:14px;">
                    <option value="">-- Pilih Ruangan --</option>
                    <?php foreach ($allRooms as $rmName): ?>
                        <option value="<?= sanitize($rmName) ?>"><?= sanitize($rmName) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div style="display:flex; justify-content:flex-end; gap:12px;">
                <button type="button" class="btn btn-secondary" onclick="closeEditModal()" style="background:#f1f5f9; color:#475569; border:none; padding:8px 16px;">Batal</button>
                <button type="submit" class="btn btn-primary" style="padding:8px 16px;">Simpan Perubahan</button>
            </div>
        </form>
    </div>
</div>

<script>
var classDosenMap = {
<?php foreach ($classes as $c): ?>
    "<?= $c['id'] ?>": <?= json_encode($c['dosen_pengampu'] ?: '-') ?>,
<?php endforeach; ?>
};

window.openEditModal = function(regId, classId, dosen, ruangan) {
    document.getElementById('edit_reg_id').value = regId;
    document.getElementById('edit_class_id').value = classId;
    document.getElementById('display_dosen').textContent = classDosenMap[classId] || '-';
    document.getElementById('edit_ruangan').value = ruangan;
    document.getElementById('editPesertaModal').style.display = 'flex';
};

window.openEditPendaftarModal = function(regId, hariPilihan) {
    document.getElementById('edit_pendaftar_reg_id').value = regId;
    document.getElementById('edit_pendaftar_hari').value = hariPilihan;
    document.getElementById('editPendaftarModal').style.display = 'flex';
};

window.closeEditPendaftarModal = function() {
    document.getElementById('editPendaftarModal').style.display = 'none';
};

document.getElementById('edit_class_id').addEventListener('change', function() {
    var classId = this.value;
    document.getElementById('display_dosen').textContent = classDosenMap[classId] || '-';
});

window.closeEditModal = function() {
    document.getElementById('editPesertaModal').style.display = 'none';
};

(function () {
    var filterKelasBottom = document.getElementById('filterKelasBottom');

    setTimeout(function() {
        if (window.jQuery && window.jQuery.fn.DataTable) {
            var $ = window.jQuery;
            
            // Inisialisasi DataTable untuk Data Pendaftar
            $('#tablePendaftar').DataTable({
                "destroy": true,
                "pageLength": 10,
                "language": {
                    "search": "Cari:",
                    "lengthMenu": "Tampilkan _MENU_ data",
                    "info": "Menampilkan _START_ - _END_ dari _TOTAL_ data",
                    "paginate": {
                        "first": "Pertama",
                        "last": "Terakhir",
                        "next": "Selanjutnya",
                        "previous": "Sebelumnya"
                    }
                }
            });
            var tableEl = $('#participantTable');
            
            $.fn.dataTable.ext.search.push(function(settings, data, dataIndex) {
                if (settings.nTable.id !== 'participantTable') return true;
                
                var selectedClassId = filterKelasBottom.value;
                if (!selectedClassId || selectedClassId === 'ALL') return true;
                
                var tr = settings.aoData[dataIndex].nTr;
                var rowClassId = tr.getAttribute('data-class-id');
                return rowClassId === selectedClassId;
            });
            
            filterKelasBottom.addEventListener('change', function() {
                if ($.fn.DataTable.isDataTable(tableEl)) {
                    tableEl.DataTable().draw();
                }
                
                var selectedClassId = filterKelasBottom.value;
                var countBadge = document.querySelector('.card-header span.badge-count');
                var emptyState = document.getElementById('tableEmptyState');
                var tableDiv = document.getElementById('tableResponsiveContainer');
                
                if (selectedClassId === '') {
                    if (emptyState) {
                        emptyState.style.display = 'flex';
                        emptyState.querySelector('h3').textContent = 'Pilih Kelas Terlebih Dahulu';
                        emptyState.querySelector('p').textContent = 'Daftar peserta akan muncul setelah Anda memilih kelas tutorial.';
                    }
                    if (tableDiv) tableDiv.style.display = 'none';
                    if (countBadge) countBadge.textContent = '0';
                    var bulkContainer = document.getElementById('bulkActionsContainer');
                    if (bulkContainer) bulkContainer.style.display = 'none';
                    var btnDeleteClass = document.getElementById('btnDeleteClass');
                    if (btnDeleteClass) btnDeleteClass.style.display = 'none';
                } else {
                    if ($.fn.DataTable.isDataTable(tableEl)) {
                        var info = tableEl.DataTable().page.info();
                        var count = info.recordsDisplay;
                        if (countBadge) countBadge.textContent = count;
                        
                        if (count === 0 && emptyState) {
                            emptyState.style.display = 'flex';
                            emptyState.querySelector('h3').textContent = 'Belum ada peserta di kelas ini';
                            emptyState.querySelector('p').textContent = 'Tambahkan mahasiswa ke kelas melalui form di atas.';
                            if (tableDiv) tableDiv.style.display = 'none';
                            var bulkContainer = document.getElementById('bulkActionsContainer');
                            if (bulkContainer) bulkContainer.style.display = 'none';
                        } else {
                            if (emptyState) emptyState.style.display = 'none';
                            if (tableDiv) tableDiv.style.display = 'block';
                            var bulkContainer = document.getElementById('bulkActionsContainer');
                            if (bulkContainer) bulkContainer.style.display = 'block';
                            var btnDeleteClass = document.getElementById('btnDeleteClass');
                            if (btnDeleteClass) btnDeleteClass.style.display = 'inline-block';
                        }
                        if (emptyState) emptyState.style.display = 'none';
                        if (tableDiv) tableDiv.style.display = 'block';
                        var bulkContainer = document.getElementById('bulkActionsContainer');
                        if (bulkContainer) bulkContainer.style.display = 'block';
                        var btnDeleteClass = document.getElementById('btnDeleteClass');
                        if (btnDeleteClass) btnDeleteClass.style.display = 'inline-block';
                    }
                }
            });
            
            setTimeout(function() {
                filterKelasBottom.dispatchEvent(new Event('change'));
            }, 100);

            // Logic untuk Check All
            var btnCheckAll = document.getElementById('btnCheckAll');
            if (btnCheckAll) {
                btnCheckAll.addEventListener('click', function() {
                    var isChecked = this.getAttribute('data-checked') === 'true';
                    var newCheckedState = !isChecked;
                    this.setAttribute('data-checked', newCheckedState);
                    this.innerHTML = newCheckedState ? '☐ Batal Centang' : '☑️ Centang Semua';
                    
                    if ($.fn.DataTable.isDataTable(tableEl)) {
                        var dt = tableEl.DataTable();
                        $(dt.rows({ search: 'applied' }).nodes()).find('.check-peserta').prop('checked', newCheckedState);
                    } else {
                        var checkboxes = document.querySelectorAll('#participantTable tbody .check-peserta');
                        checkboxes.forEach(function(cb) {
                            cb.checked = newCheckedState;
                        });
                    }
                });
            }

            // Logic untuk Delete Class
            var btnDeleteClass = document.getElementById('btnDeleteClass');
            if (btnDeleteClass) {
                btnDeleteClass.addEventListener('click', function() {
                    var selectedClassId = filterKelasBottom.value;
                    if (!selectedClassId || selectedClassId === 'ALL' || selectedClassId === '') {
                        alert('Silakan pilih kelas terlebih dahulu.');
                        return;
                    }

                    if (typeof showConfirm === 'function') {
                        showConfirm(this.getAttribute('data-my-confirm'), function() {
                            var form = document.createElement('form');
                            form.method = 'POST';
                            form.style.display = 'none';

                            var csrfInput = document.createElement('input');
                            csrfInput.type = 'hidden';
                            csrfInput.name = 'csrf_token';
                            csrfInput.value = '<?= csrfToken() ?>';
                            form.appendChild(csrfInput);

                            var actionInput = document.createElement('input');
                            actionInput.type = 'hidden';
                            actionInput.name = 'action';
                            actionInput.value = 'delete_class';
                            form.appendChild(actionInput);

                            var classIdInput = document.createElement('input');
                            classIdInput.type = 'hidden';
                            classIdInput.name = 'class_id';
                            classIdInput.value = selectedClassId;
                            form.appendChild(classIdInput);

                            document.body.appendChild(form);
                            form.submit();
                        }, 'danger');
                    } else {
                        if (confirm(this.getAttribute('data-my-confirm'))) {
                            var form = document.createElement('form');
                            form.method = 'POST';
                            form.style.display = 'none';

                            var csrfInput = document.createElement('input');
                            csrfInput.type = 'hidden';
                            csrfInput.name = 'csrf_token';
                            csrfInput.value = '<?= csrfToken() ?>';
                            form.appendChild(csrfInput);

                            var actionInput = document.createElement('input');
                            actionInput.type = 'hidden';
                            actionInput.name = 'action';
                            actionInput.value = 'delete_class';
                            form.appendChild(actionInput);

                            var classIdInput = document.createElement('input');
                            classIdInput.type = 'hidden';
                            classIdInput.name = 'class_id';
                            classIdInput.value = selectedClassId;
                            form.appendChild(classIdInput);

                            document.body.appendChild(form);
                            form.submit();
                        }
                    }
                });
            }

            // Logic untuk Bulk Delete
            var btnBulkDelete = document.getElementById('btnBulkDelete');
            if (btnBulkDelete) {
                btnBulkDelete.addEventListener('click', function() {
                    var checked = [];
                    if ($.fn.DataTable.isDataTable(tableEl)) {
                        $(tableEl.DataTable().rows({ search: 'applied' }).nodes()).find('.check-peserta:checked').each(function() {
                            checked.push(this);
                        });
                    } else {
                        document.querySelectorAll('#participantTable tbody .check-peserta:checked').forEach(function(cb) {
                            checked.push(cb);
                        });
                    }
                    
                    if (checked.length === 0) {
                        alert('Silakan centang minimal satu peserta terlebih dahulu.');
                        return;
                    }
                    
                    if (confirm('Yakin ingin mengeluarkan ' + checked.length + ' peserta yang dicentang dari kelas ini?')) {
                        var form = document.getElementById('formBulkDelete');
                        if (form) {
                            form.innerHTML = `<input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                                              <input type="hidden" name="action" value="bulk_delete">`;
                            checked.forEach(chk => {
                                const input = document.createElement('input');
                                input.type = 'hidden';
                                input.name = 'reg_ids[]';
                                input.value = chk.value;
                                form.appendChild(input);
                            });
                            form.submit();
                        }
                    }
                });
            }
            
            // Fitur Checkbox untuk tabel Data Pendaftar Tutorial
            const checkAllPendaftarBtn = document.getElementById('btnCheckAllPendaftar');
            const bulkDeletePendaftarBtn = document.getElementById('btnBulkDeletePendaftar');
            const bulkActionsPendaftarContainer = document.getElementById('bulkActionsPendaftarContainer');

            function getPendaftarNodes() {
                if (window.jQuery && $.fn.DataTable.isDataTable($('#tablePendaftar'))) {
                    return $('#tablePendaftar').DataTable().rows({ search: 'applied' }).nodes();
                }
                return document;
            }

            function updateBulkPendaftarButtons() {
                const checkedCount = $(getPendaftarNodes()).find('.check-pendaftar:checked').length;
                const totalCount = $(getPendaftarNodes()).find('.check-pendaftar').length;
                if (totalCount > 0) {
                    bulkActionsPendaftarContainer.style.display = 'block';
                    bulkDeletePendaftarBtn.style.display = checkedCount > 0 ? 'inline-block' : 'none';
                } else {
                    bulkActionsPendaftarContainer.style.display = 'none';
                }
            }

            if (document.querySelectorAll('.check-pendaftar').length > 0) {
                bulkActionsPendaftarContainer.style.display = 'block';
            }

            if (checkAllPendaftarBtn) {
                checkAllPendaftarBtn.addEventListener('click', function() {
                    const isChecked = this.getAttribute('data-checked') === 'true';
                    const newValue = !isChecked;
                    
                    $(getPendaftarNodes()).find('.check-pendaftar').prop('checked', newValue);
                    
                    this.setAttribute('data-checked', newValue);
                    this.innerHTML = newValue ? '🔳 Hapus Centang' : '☑️ Centang Semua';
                    
                    updateBulkPendaftarButtons();
                });
            }

            // Delegasikan event change agar checkbox di halaman lain (pagination) tetap berfungsi
            if (window.jQuery) {
                $('#tablePendaftar').on('change', '.check-pendaftar', updateBulkPendaftarButtons);
            } else {
                document.querySelectorAll('.check-pendaftar').forEach(chk => {
                    chk.addEventListener('change', updateBulkPendaftarButtons);
                });
            }

            if (bulkDeletePendaftarBtn) {
                bulkDeletePendaftarBtn.addEventListener('click', function() {
                    const checked = $(getPendaftarNodes()).find('.check-pendaftar:checked');
                    
                    if (checked.length === 0) {
                        alert('Silakan centang minimal satu pendaftar terlebih dahulu.');
                        return;
                    }

                    if (!confirm('Hapus ' + checked.length + ' pendaftar yang dicentang secara permanen?')) return;
                    
                    const form = document.getElementById('formBulkDeletePendaftar');
                    if (form) {
                        form.innerHTML = `<input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                                          <input type="hidden" name="action" value="bulk_delete_registration">`;
                        checked.each(function() {
                            const input = document.createElement('input');
                            input.type = 'hidden';
                            input.name = 'reg_ids[]';
                            input.value = this.value;
                            form.appendChild(input);
                        });
                        form.submit();
                    }
                });
            }
            
            // Inisialisasi DataTable untuk Semua Mahasiswa
            var tableSemua = $('#tableSemuaMahasiswa').DataTable({
                "destroy": true,
                "pageLength": 10,
                "language": {
                    "search": "Cari Mahasiswa:",
                    "lengthMenu": "Tampilkan _MENU_ data",
                    "info": "Menampilkan _START_ - _END_ dari _TOTAL_ data",
                    "paginate": {
                        "first": "Pertama",
                        "last": "Terakhir",
                        "next": "Selanjutnya",
                        "previous": "Sebelumnya"
                    }
                }
            });

            // Filter berdasarkan Jurusan yang dipilih di tab Kolektif
            var selectJurusan = document.getElementById('selectJurusanKolektif');
            if (selectJurusan) {
                selectJurusan.addEventListener('change', function() {
                    var val = this.value;
                    // Kolom Jurusan ada di index 3 (NIM, Angkatan, Nama Lengkap, Jurusan, Status, Aksi)
                    if (val) {
                        tableSemua.column(3).search('^' + $.fn.dataTable.util.escapeRegex(val) + '$', true, false).draw();
                    } else {
                        tableSemua.column(3).search('').draw();
                    }
                });
            }

            // Custom Filtering for Angkatan and Status
            $.fn.dataTable.ext.search.push(
                function(settings, data, dataIndex) {
                    if (settings.nTable.id !== 'tableSemuaMahasiswa') {
                        return true;
                    }
                    var filterAngkatan = $('#filterAngkatanSemua').val();
                    var filterStatus = $('#filterStatusSemua').val();
                    
                    var angkatan = data[1] || ""; // Index 1: Angkatan
                    var status = data[4] || "";   // Index 4: Status
                    
                    if (filterAngkatan && angkatan.indexOf(filterAngkatan) === -1) {
                        return false;
                    }
                    if (filterStatus && status.indexOf(filterStatus) === -1) {
                        return false;
                    }
                    return true;
                }
            );

            $('#filterAngkatanSemua, #filterStatusSemua').on('change', function() {
                tableSemua.draw();
            });
        }
    }, 500);

})();
</script>



<!-- Modal Daftarkan Single Mahasiswa -->
<div id="addSingleRegistrationModal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:9999; align-items:center; justify-content:center;">
    <div style="background:#fff; width:90%; max-width:500px; border-radius:12px; padding:24px; box-shadow:0 4px 6px rgba(0,0,0,0.1);">
        <h3 style="margin-top:0; margin-bottom:20px; font-size:18px; color:#1e293b;">Daftarkan Mahasiswa</h3>
        <p style="margin-bottom:16px; font-size:14px; color:#64748b;">Mendaftarkan: <strong id="add_single_nama_lengkap" style="color:#0f172a;"></strong></p>
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
            <input type="hidden" name="action" value="add_single_registration">
            <input type="hidden" name="user_id" id="add_single_user_id" value="">
            
            <div class="form-group" style="margin-bottom:24px;">
                <label style="display:block; margin-bottom:8px; font-size:14px; color:#475569;">Pilihan Hari</label>
                <select name="hari_pilihan" required
                    style="width:100%; padding:10px; border:1.5px solid #cbd5e1; border-radius:8px; font-size:14px;">
                    <option value="">-- Pilih Hari --</option>
                    <?php 
                    $days = ['Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat'];
                    foreach ($days as $day): 
                        $dayLower = strtolower($day);
                        $kuota = $active_gel["kuota_$dayLower"] ?? 0;
                        $terisi = $registeredCounts[$day] ?? 0;
                        $sisa = $kuota - $terisi;
                        $isFull = $sisa <= 0;
                    ?>
                    <option value="<?= $day ?>" <?= $isFull ? 'disabled' : '' ?>>
                        <?= $day ?> <?= $isFull ? '(Penuh)' : "(Sisa $sisa kursi)" ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div style="display:flex; justify-content:flex-end; gap:12px;">
                <button type="button" class="btn btn-secondary" onclick="closeAddSingleRegistrationModal()" style="background:#f1f5f9; color:#475569; border:none; padding:8px 16px;">Batal</button>
                <button type="submit" class="btn btn-success" style="padding:8px 16px; background:#10b981; border:none;">Daftarkan</button>
            </div>
        </form>
    </div>
</div>

<script>
window.openAddSingleRegistrationModal = function(userId, namaLengkap) {
    document.getElementById('add_single_user_id').value = userId;
    document.getElementById('add_single_nama_lengkap').textContent = namaLengkap;
    document.getElementById('addSingleRegistrationModal').style.display = 'flex';
};

window.closeAddSingleRegistrationModal = function() {
    document.getElementById('addSingleRegistrationModal').style.display = 'none';
};

if (document.querySelector('#addSingleRegistrationModal form')) {
    document.querySelector('#addSingleRegistrationModal form').addEventListener('submit', function(e) {
        e.preventDefault();
        const formData = new FormData(this);
        fetch('', { method: 'POST', body: formData })
        .then(res => res.text())
        .then(html => {
            closeAddSingleRegistrationModal();
            alert('Mahasiswa berhasil didaftarkan.');
            window.location.reload();
        })
        .catch(err => {
            alert('Terjadi kesalahan saat mendaftarkan mahasiswa.');
        });
    });
}

if (document.querySelector('#editPendaftarModal form')) {
    document.querySelector('#editPendaftarModal form').addEventListener('submit', function(e) {
        e.preventDefault();
        const formData = new FormData(this);
        fetch('', { method: 'POST', body: formData })
        .then(res => res.text())
        .then(html => {
            closeEditPendaftarModal();
            alert('Pilihan hari berhasil diperbarui.');
            window.location.reload();
        })
        .catch(err => {
            alert('Terjadi kesalahan saat mengedit pendaftaran.');
        });
    });
}
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
