<?php
/**
 * LPPAI Corner - Admin: Data Peserta Tutorial
 */
define('PAGE_TITLE', 'Data Pendaftar Tutorial');
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

// Ambil daftar gelombang dari master_gelombang
$allGelombang = $pdo->query("SELECT * FROM master_gelombang ORDER BY created_at DESC, id DESC")->fetchAll(PDO::FETCH_ASSOC);

$active_gel = null;
foreach ($allGelombang as $g) {
    if ($g['status'] === 'aktif') {
        $active_gel = $g;
        break;
    }
}

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
$classes  = $pdo->query("SELECT * FROM tutorial_classes WHERE (semester LIKE '2025%' OR semester LIKE '2026%' OR semester LIKE '2027%' OR semester LIKE '2028%' OR semester LIKE '2029%' OR semester LIKE '2030%') ORDER BY gelombang, hari, nama_kelas")->fetchAll();
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


// =============================================================
// FILTER & DATA PENDAFTAR TUTORIAL
// =============================================================
$selected_gel_id = isset($_GET['gelombang_id']) ? trim($_GET['gelombang_id']) : 'active';
$current_gel = null;

if ($selected_gel_id === 'all') {
    $current_gel = null;
} elseif (is_numeric($selected_gel_id)) {
    foreach ($allGelombang as $g) {
        if ($g['id'] == $selected_gel_id) {
            $current_gel = $g;
            break;
        }
    }
    if (!$current_gel) {
        $current_gel = $active_gel;
        $selected_gel_id = $active_gel ? (string)$active_gel['id'] : 'all';
    }
} else {
    $selected_gel_id = 'active';
    $current_gel = $active_gel;
}

$allRegistrations = [];

if ($selected_gel_id === 'all') {
    $stmtRegistrations = $pdo->query("
        SELECT tr.*, u.nama_lengkap, u.nim, u.program_studi, tc.nama_kelas, tc.dosen_pengampu as dosen_kelas
        FROM tutorial_registrations tr
        JOIN users u ON tr.user_id = u.id
        LEFT JOIN tutorial_classes tc ON tr.tutorial_class_id = tc.id
        WHERE tr.id IN (
            SELECT MAX(candidate.id)
            FROM tutorial_registrations candidate
            GROUP BY candidate.user_id
        )
          AND $notLulusSQL
        ORDER BY tr.created_at DESC
    ");
    $allRegistrations = $stmtRegistrations->fetchAll(PDO::FETCH_ASSOC);
} elseif ($current_gel) {
    $targetYear = str_replace('/', '-', trim($current_gel['tahun_ajaran']));
    $targetSemester = trim($current_gel['semester']);
    $stmtRegistrations = $pdo->prepare("
        SELECT tr.*, u.nama_lengkap, u.nim, u.program_studi, tc.nama_kelas, tc.dosen_pengampu as dosen_kelas
        FROM tutorial_registrations tr
        JOIN users u ON tr.user_id = u.id
        LEFT JOIN tutorial_classes tc ON tr.tutorial_class_id = tc.id
        WHERE tr.id IN (
            SELECT MAX(candidate.id)
            FROM tutorial_registrations candidate
            LEFT JOIN tutorial_classes period_class ON candidate.tutorial_class_id = period_class.id
            WHERE COALESCE(NULLIF(period_class.gelombang, ''), candidate.gelombang) = ?
              AND (
                  REPLACE(TRIM(candidate.tahun_ajaran), '/', '-') = ?
                  OR (
                      (candidate.tahun_ajaran IS NULL OR TRIM(candidate.tahun_ajaran) = '')
                      AND (
                          REPLACE(TRIM(period_class.semester), '/', '-') = ?
                          OR (period_class.id IS NULL AND candidate.created_at >= ?)
                      )
                  )
              )
              AND (
                  period_class.id IS NULL
                  OR TRIM(period_class.semester) = ?
                  OR REPLACE(TRIM(period_class.semester), '/', '-') = ?
              )
            GROUP BY candidate.user_id
        )
          AND $notLulusSQL
        ORDER BY tr.created_at DESC
    ");
    $stmtRegistrations->execute([
        $current_gel['gelombang'],
        $targetYear,
        $targetYear . '-' . $targetSemester,
        $current_gel['created_at'],
        $targetSemester,
        $targetYear . '-' . $targetSemester,
    ]);
    $allRegistrations = $stmtRegistrations->fetchAll(PDO::FETCH_ASSOC);
}

// Perhitungan Statistik Lengkap
$totalPendaftar = count($allRegistrations);
$totalSudahDiplot = 0;
$totalBelumDiplot = 0;

$hariList = ['Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat'];
$statsHari = [
    'Senin' => 0,
    'Selasa' => 0,
    'Rabu' => 0,
    'Kamis' => 0,
    'Jumat' => 0,
    'Belum Memilih' => 0
];
$statsJurusan = [];

foreach ($allRegistrations as $reg) {
    if (!empty($reg['tutorial_class_id'])) {
        $totalSudahDiplot++;
    } else {
        $totalBelumDiplot++;
    }

    $rawHari = trim($reg['hari_pilihan'] ?? '');
    $hariProper = ucfirst(strtolower($rawHari));
    if (in_array($hariProper, $hariList)) {
        $statsHari[$hariProper]++;
    } elseif (empty($rawHari) || $rawHari === '-') {
        $statsHari['Belum Memilih']++;
    } else {
        if (!isset($statsHari[$rawHari])) $statsHari[$rawHari] = 0;
        $statsHari[$rawHari]++;
    }

    $prodi = trim($reg['program_studi'] ?? '');
    if ($prodi === '') $prodi = 'Belum Ditentukan';
    if (!isset($statsJurusan[$prodi])) {
        $statsJurusan[$prodi] = 0;
    }
    $statsJurusan[$prodi]++;
}
arsort($statsJurusan);

$persenSudahDiplot = $totalPendaftar > 0 ? round(($totalSudahDiplot / $totalPendaftar) * 100, 1) : 0;
$persenBelumDiplot = $totalPendaftar > 0 ? round(($totalBelumDiplot / $totalPendaftar) * 100, 1) : 0;

// Data Kuota Periode Terpilih
$kuotaTotal = 0;
$kuotaPerHari = [];
$targetGelForQuota = $current_gel ?: $active_gel;
if ($targetGelForQuota) {
    foreach ($hariList as $h) {
        $key = 'kuota_' . strtolower($h);
        $k = (int)($targetGelForQuota[$key] ?? 0);
        $kuotaPerHari[$h] = $k;
        $kuotaTotal += $k;
    }
}

$listJurusan = $pdo->query("SELECT DISTINCT program_studi FROM users WHERE program_studi IS NOT NULL AND program_studi != '' ORDER BY program_studi")->fetchAll(PDO::FETCH_COLUMN);

// =============================================================
// QUERY PESERTA PRETES YANG BELUM MENDAFTARKAN DIRI PADA TUTORIAL
// =============================================================
$registeredTutorialUserIds = [];
foreach ($allRegistrations as $reg) {
    $registeredTutorialUserIds[(int)$reg['user_id']] = true;
}

$allPretesQuery = $pdo->query("
    SELECT 
        pr.id as pretes_id,
        pr.user_id,
        pr.periode as pretes_periode,
        pr.status as pretes_kehadiran,
        pr.ruangan as pretes_ruangan,
        pr.hari_pilihan as pretes_hari,
        u.nama_lengkap,
        u.nim,
        u.program_studi,
        u.no_hp,
        u.email,
        pres.nilai as pretes_nilai,
        pres.status_lulus as pretes_status_lulus
    FROM pretes_registrations pr
    JOIN users u ON pr.user_id = u.id
    LEFT JOIN pretes_results pres ON pres.user_id = u.id
    WHERE pr.id IN (
        SELECT MAX(id) FROM pretes_registrations GROUP BY user_id
    )
    AND u.role = 'mahasiswa'
    ORDER BY u.nama_lengkap ASC
");
$allPretesList = $allPretesQuery ? $allPretesQuery->fetchAll(PDO::FETCH_ASSOC) : [];

$unregisteredPretes = [];
$unregisteredPretesTidakLulus = 0;
$unregisteredPretesBelumDinilai = 0;
$unregisteredPretesLulus = 0;
$unregisteredProdiCounts = [];

foreach ($allPretesList as $p) {
    $uid = (int)$p['user_id'];
    if (!isset($registeredTutorialUserIds[$uid])) {
        $statusLulus = strtolower(trim($p['pretes_status_lulus'] ?? ''));
        if ($statusLulus === 'lulus') {
            $unregisteredPretesLulus++;
        } else {
            $unregisteredPretesTidakLulus++;
        }
        
        $prd = trim($p['program_studi'] ?? 'Lainnya');
        if ($prd === '') $prd = 'Belum Ditentukan';
        $unregisteredProdiCounts[$prd] = ($unregisteredProdiCounts[$prd] ?? 0) + 1;
        
        $unregisteredPretes[] = $p;
    }
}
arsort($unregisteredProdiCounts);

include __DIR__ . '/../includes/header.php';
?>

<?php if ($message): ?>
    <div class="alert alert-<?= $msgType ?>"><?= $isMessageHtml ? $message : sanitize($message) ?></div>
<?php endif; ?>

<style>
.stat-subtext {
    font-size: 11.5px;
    margin-top: 4px;
    font-weight: 500;
}
.stat-pill-badge {
    display: inline-block;
    padding: 3px 8px;
    border-radius: 999px;
    font-size: 11px;
    font-weight: 600;
}
.day-stat-card {
    border: 1px solid #e2e8f0;
    border-radius: 10px;
    padding: 10px 14px;
    margin-bottom: 8px;
    background: #ffffff;
    transition: all 0.2s ease;
    cursor: pointer;
}
.day-stat-card:hover {
    border-color: #3b82f6;
    background: #f8fafc;
    transform: translateX(3px);
}
.day-stat-card.active-filter {
    border-color: #2563eb;
    background: #eff6ff;
}
.day-progress-bar-bg {
    background: #f1f5f9;
    border-radius: 999px;
    height: 7px;
    overflow: hidden;
    margin-top: 6px;
}
.day-progress-bar-fill {
    height: 100%;
    border-radius: 999px;
    transition: width 0.4s ease;
}
.prodi-stat-item {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 8px 12px;
    border-radius: 8px;
    border: 1px solid #e2e8f0;
    margin-bottom: 6px;
    background: #fff;
    cursor: pointer;
    transition: all 0.2s ease;
}
.prodi-stat-item:hover {
    border-color: #3b82f6;
    background: #f8fafc;
    transform: translateX(3px);
}
.prodi-stat-item.active-filter {
    border-color: #2563eb;
    background: #eff6ff;
}
.filter-box-card {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: var(--radius);
    padding: 18px 20px;
    margin-bottom: 24px;
    box-shadow: var(--shadow);
}
.filter-form-label {
    display: block;
    font-size: 12px;
    font-weight: 600;
    color: #475569;
    margin-bottom: 6px;
}
.filter-form-select {
    width: 100%;
    font-size: 13px;
    padding: 8px 12px;
    border-radius: 8px;
    border: 1.5px solid #cbd5e1;
    background: #fff;
    color: #1e293b;
    outline: none;
    transition: border-color 0.2s ease;
}
.filter-form-select:focus {
    border-color: #3b82f6;
}
</style>

<div>

<!-- ===================================================
     HEADER TITLE & STATUS PERIODE
     =================================================== -->
<div style="display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 12px; margin-bottom: 20px;">
    <div>
        <h2 style="font-size: 22px; font-weight: 700; color: #0f172a; margin: 0 0 6px 0;">
            📊 Data & Statistik Pendaftar Tutorial
        </h2>
        <p style="color: #64748b; font-size: 13.5px; margin: 0;">
            Pantau ringkasan pendaftar, sebaran pilihan hari, kuota kelas, serta filtering data tutorial secara real-time.
        </p>
    </div>
    <div>
        <?php if ($selected_gel_id === 'all'): ?>
            <span class="badge badge-info" style="font-size: 13px; padding: 6px 14px;">
                🌐 Mode: Menampilkan Semua Periode
            </span>
        <?php elseif ($current_gel): ?>
            <span class="badge <?= $current_gel['status'] === 'aktif' ? 'badge-success' : 'badge-secondary' ?>" style="font-size: 13px; padding: 6px 14px;">
                <?= $current_gel['status'] === 'aktif' ? '🟢 Periode Aktif' : '⚪ Periode Riwayat' ?>: <?= sanitize($current_gel['tahun_ajaran']) ?> / <?= sanitize($current_gel['semester']) ?> / <?= sanitize($current_gel['gelombang']) ?>
            </span>
        <?php else: ?>
            <span class="badge badge-warning" style="font-size: 13px; padding: 6px 14px;">
                ⚠️ Belum Ada Periode Aktif
            </span>
        <?php endif; ?>
    </div>
</div>

<!-- ===================================================
     1. STAT GRID UTAMA (4 STAT CARDS)
     =================================================== -->
<div class="stat-grid" style="margin-bottom: 24px;">
    <!-- 1. Total Pendaftar -->
    <div class="stat-card">
        <div class="stat-icon blue">👥</div>
        <div class="stat-info">
            <h3><?= number_format($totalPendaftar) ?></h3>
            <p>Total Pendaftar</p>
            <div class="stat-subtext" style="color: #2563eb;">
                <?= $selected_gel_id === 'all' ? 'Seluruh Periode' : ($current_gel ? sanitize($current_gel['tahun_ajaran']) . ' (' . sanitize($current_gel['gelombang']) . ')' : '-') ?>
            </div>
        </div>
    </div>

    <!-- 2. Sudah Diplot -->
    <div class="stat-card">
        <div class="stat-icon green">✅</div>
        <div class="stat-info">
            <div style="display: flex; align-items: baseline; gap: 8px;">
                <h3><?= number_format($totalSudahDiplot) ?></h3>
                <span class="stat-pill-badge badge-success"><?= $persenSudahDiplot ?>%</span>
            </div>
            <p>Sudah Diplot ke Kelas</p>
            <div class="stat-subtext" style="color: #166534;">Mendapatkan jadwal tutorial</div>
        </div>
    </div>

    <!-- 3. Belum Diplot (Menunggu) -->
    <div class="stat-card">
        <div class="stat-icon orange">⏳</div>
        <div class="stat-info">
            <div style="display: flex; align-items: baseline; gap: 8px;">
                <h3><?= number_format($totalBelumDiplot) ?></h3>
                <span class="stat-pill-badge badge-warning"><?= $persenBelumDiplot ?>%</span>
            </div>
            <p>Belum Diplot (Menunggu)</p>
            <div class="stat-subtext" style="color: #b45309;">Menunggu antrean kelas</div>
        </div>
    </div>

    <!-- 4. Total Kuota -->
    <div class="stat-card">
        <div class="stat-icon purple">🎯</div>
        <div class="stat-info">
            <h3><?= $kuotaTotal > 0 ? number_format($kuotaTotal) : '-' ?></h3>
            <p>Total Kuota Kelas</p>
            <div class="stat-subtext" style="color: #6d28d9;">
                <?php if ($kuotaTotal > 0): ?>
                    <?php if ($kuotaTotal >= $totalPendaftar): ?>
                        Sisa <?= number_format($kuotaTotal - $totalPendaftar) ?> kursi tersedia
                    <?php else: ?>
                        Over kapasitas <?= number_format($totalPendaftar - $kuotaTotal) ?> pendaftar
                    <?php endif; ?>
                <?php else: ?>
                    Kuota gelombang belum diatur
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- 5. Peserta Pretes Belum Daftar Tutorial -->
    <div class="stat-card" style="cursor: pointer;" onclick="switchTableTab('pretes'); var el = document.getElementById('sectionPretesBelumDaftar'); if(el) el.scrollIntoView({ behavior: 'smooth', block: 'start' });" title="Klik untuk melihat peserta pretes yang belum mendaftar tutorial">
        <div class="stat-icon red">⚠️</div>
        <div class="stat-info">
            <div style="display: flex; align-items: baseline; gap: 8px;">
                <h3><?= number_format(count($unregisteredPretes)) ?></h3>
                <?php if ($unregisteredPretesTidakLulus > 0): ?>
                    <span class="stat-pill-badge badge-danger"><?= $unregisteredPretesTidakLulus ?> Wajib</span>
                <?php endif; ?>
            </div>
            <p>Pretes Belum Daftar</p>
            <div class="stat-subtext" style="color: #b91c1c;">
                <?= $unregisteredPretesTidakLulus ?> tdk lulus, <?= $unregisteredPretesBelumDinilai ?> blm dinilai
            </div>
        </div>
    </div>
</div>

<!-- ===================================================
     2. DETAIL SEBARAN PILIHAN HARI & JURUSAN (2 CARDS)
     =================================================== -->
<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 18px; margin-bottom: 24px;">
    
    <!-- CARD KIRI: Sebaran Pilihan Hari & Kapasitas Kuota -->
    <div class="card" style="box-shadow: var(--shadow); border: 1px solid rgba(0,0,0,0.06); border-radius: var(--radius); margin-bottom: 0;">
        <div class="card-header" style="background-color: #f8fafc; font-weight: 600; display: flex; justify-content: space-between; align-items: center;">
            <div style="display: flex; align-items: center; gap: 6px;">
                <span>📅</span> <strong>Sebaran Pilihan Hari & Kuota</strong>
            </div>
            <span style="font-size: 11.5px; color: #64748b;">Klik kartu untuk filter tabel</span>
        </div>
        <div class="card-body" style="padding: 16px 20px;">
            <?php foreach ($hariList as $hari): 
                $count = $statsHari[$hari] ?? 0;
                $kuota = $kuotaPerHari[$hari] ?? 0;
                $percent = $kuota > 0 ? min(100, round(($count / $kuota) * 100)) : ($totalPendaftar > 0 ? round(($count / $totalPendaftar) * 100) : 0);
                
                $barColor = '#10b981'; // Green
                $badgeText = "$count mhs";
                if ($kuota > 0) {
                    $badgeText = "$count / $kuota kursi ($percent%)";
                    if ($count > $kuota) {
                        $barColor = '#ef4444'; // Red over
                    } elseif ($count >= $kuota * 0.85) {
                        $barColor = '#f59e0b'; // Amber warning
                    }
                }
            ?>
                <div class="day-stat-card" onclick="filterByHari('<?= $hari ?>')" title="Klik untuk memfilter tabel ke Hari <?= $hari ?>">
                    <div style="display: flex; justify-content: space-between; align-items: center;">
                        <div style="display: flex; align-items: center; gap: 8px;">
                            <strong style="color: #1e293b; font-size: 13.5px;"><?= $hari ?></strong>
                            <?php if ($kuota > 0 && $count >= $kuota): ?>
                                <span class="badge badge-danger" style="font-size: 10px; padding: 2px 6px;">Penuh</span>
                            <?php endif; ?>
                        </div>
                        <span style="font-size: 12.5px; font-weight: 600; color: #475569;"><?= $badgeText ?></span>
                    </div>
                    <div class="day-progress-bar-bg">
                        <div class="day-progress-bar-fill" style="width: <?= max(4, $percent) ?>%; background: <?= $barColor ?>;"></div>
                    </div>
                </div>
            <?php endforeach; ?>

            <?php if (($statsHari['Belum Memilih'] ?? 0) > 0): ?>
                <div class="day-stat-card" onclick="filterByHari('Belum Memilih')" style="border-style: dashed;" title="Klik untuk memfilter yang belum memilih hari">
                    <div style="display: flex; justify-content: space-between; align-items: center;">
                        <strong style="color: #64748b; font-size: 13.5px;">⚠️ Belum Memilih Hari</strong>
                        <span class="badge badge-secondary"><?= $statsHari['Belum Memilih'] ?> mhs</span>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- CARD KANAN: Sebaran Program Studi / Jurusan -->
    <div class="card" style="box-shadow: var(--shadow); border: 1px solid rgba(0,0,0,0.06); border-radius: var(--radius); margin-bottom: 0;">
        <div class="card-header" style="background-color: #f8fafc; font-weight: 600; display: flex; justify-content: space-between; align-items: center;">
            <div style="display: flex; align-items: center; gap: 6px;">
                <span>🎓</span> <strong>Sebaran Program Studi (<?= count($statsJurusan) ?> Prodi)</strong>
            </div>
            <span style="font-size: 11.5px; color: #64748b;">Klik prodi untuk filter tabel</span>
        </div>
        <div class="card-body" style="padding: 16px 20px; max-height: 330px; overflow-y: auto;">
            <?php if (empty($statsJurusan)): ?>
                <div class="text-muted text-center" style="padding: 24px 0;">Belum ada data pendaftar pada periode ini.</div>
            <?php else: ?>
                <?php foreach ($statsJurusan as $prodiName => $prodiCount): 
                    $prodiPercent = $totalPendaftar > 0 ? round(($prodiCount / $totalPendaftar) * 100, 1) : 0;
                ?>
                    <div class="prodi-stat-item" onclick="filterByJurusan('<?= htmlspecialchars($prodiName, ENT_QUOTES) ?>')" title="Klik untuk memfilter tabel ke Prodi <?= htmlspecialchars($prodiName) ?>">
                        <div style="display: flex; align-items: center; gap: 6px;">
                            <span style="font-size: 13px; color: #1e293b; font-weight: 500;"><?= sanitize($prodiName) ?></span>
                        </div>
                        <div style="display: flex; align-items: center; gap: 8px;">
                            <span style="font-size: 12px; color: #64748b;"><?= $prodiPercent ?>%</span>
                            <span class="badge badge-primary" style="font-size: 11.5px; padding: 4px 8px;"><?= $prodiCount ?> mhs</span>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

</div>

<!-- ===================================================
     3. FILTER & PENCARIAN PANEL
     =================================================== -->
<div class="filter-box-card">
    <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px; margin-bottom: 16px; border-bottom: 1px solid #f1f5f9; padding-bottom: 12px;">
        <div style="display: flex; align-items: center; gap: 8px;">
            <span style="font-size: 18px;">🔍</span>
            <strong style="font-size: 15px; color: #1e293b;">Filter Data Pendaftar</strong>
        </div>
        <button type="button" class="btn btn-sm btn-outline-secondary" id="btnResetFilter" style="background:#fff; border:1px solid #cbd5e1; color:#475569; padding:5px 14px; font-size:12px; border-radius:6px; font-weight:600;">
            🔄 Reset Semua Filter
        </button>
    </div>

    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 14px; align-items: flex-end;">
        
        <!-- Filter 1: Periode / Gelombang (Server-side) -->
        <div>
            <label class="filter-form-label">
                📌 Periode / Gelombang
            </label>
            <select id="selectGelombang" class="filter-form-select" onchange="changeGelombang(this.value)">
                <option value="active" <?= $selected_gel_id === 'active' ? 'selected' : '' ?>>
                    🌟 Periode Aktif <?= $active_gel ? '(' . sanitize($active_gel['tahun_ajaran']) . ' - ' . sanitize($active_gel['semester']) . ' - ' . sanitize($active_gel['gelombang']) . ')' : '(Tidak Ada)' ?>
                </option>
                <optgroup label="Pilih Periode Tertentu">
                    <?php foreach ($allGelombang as $g): ?>
                        <option value="<?= $g['id'] ?>" <?= ($selected_gel_id == $g['id']) ? 'selected' : '' ?>>
                            <?= sanitize($g['tahun_ajaran']) ?> - <?= sanitize($g['semester']) ?> (<?= sanitize($g['gelombang']) ?>) <?= $g['status'] === 'aktif' ? '[Aktif]' : '[Nonaktif]' ?>
                        </option>
                    <?php endforeach; ?>
                </optgroup>
                <option value="all" <?= $selected_gel_id === 'all' ? 'selected' : '' ?>>
                    🌐 Tampilkan Semua Periode (Semua Gelombang)
                </option>
            </select>
        </div>

        <!-- Filter 2: Status Plotting Kelas (Client-side) -->
        <div>
            <label class="filter-form-label">
                📌 Status Plotting Kelas
            </label>
            <select id="filterStatusPlotting" class="filter-form-select">
                <option value="">Semua Status</option>
                <option value="menunggu">⏳ Menunggu (Belum Diplot)</option>
                <option value="diplot">✅ Sudah Diplot ke Kelas</option>
            </select>
        </div>

        <!-- Filter 3: Pilihan Hari (Client-side) -->
        <div>
            <label class="filter-form-label">
                📌 Pilihan Hari
            </label>
            <select id="filterPilihanHari" class="filter-form-select">
                <option value="">Semua Pilihan Hari</option>
                <?php foreach ($hariList as $h): ?>
                    <option value="<?= $h ?>"><?= $h ?> (<?= $statsHari[$h] ?? 0 ?> mhs)</option>
                <?php endforeach; ?>
                <option value="Belum Memilih">Belum Memilih (<?= $statsHari['Belum Memilih'] ?? 0 ?> mhs)</option>
            </select>
        </div>

        <!-- Filter 4: Program Studi (Client-side) -->
        <div>
            <label class="filter-form-label">
                📌 Program Studi / Jurusan
            </label>
            <select id="filterJurusan" class="filter-form-select">
                <option value="">Semua Program Studi</option>
                <?php foreach ($listJurusan as $jur): ?>
                    <option value="<?= sanitize($jur) ?>"><?= sanitize($jur) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

    </div>
</div>

<!-- ===================================================
     4. TAB SWITCHER (PENDAFTAR TUTORIAL VS PRETES BELUM DAFTAR)
     =================================================== -->
<div class="custom-tab-bar" style="display: flex; gap: 10px; margin-bottom: 20px; border-bottom: 2px solid #e2e8f0; padding-bottom: 0; overflow-x: auto;">
    <button type="button" class="tab-btn active" id="tabBtnPendaftar" onclick="switchTableTab('pendaftar')"
        style="padding: 12px 22px; font-size: 15px; font-weight: 700; border: none; background: transparent; cursor: pointer; border-bottom: 3px solid #2563eb; color: #2563eb; display: inline-flex; align-items: center; gap: 8px; white-space: nowrap; transition: all 0.2s;">
        <span>📝 Data Pendaftar Tutorial</span>
        <span class="badge badge-primary" style="font-size: 12px;"><?= number_format($totalPendaftar) ?></span>
    </button>
    <button type="button" class="tab-btn" id="tabBtnPretesUnregistered" onclick="switchTableTab('pretes')"
        style="padding: 12px 22px; font-size: 15px; font-weight: 600; border: none; background: transparent; cursor: pointer; border-bottom: 3px solid transparent; color: #64748b; display: inline-flex; align-items: center; gap: 8px; white-space: nowrap; transition: all 0.2s;">
        <span>⚠️ Peserta Pretes Belum Daftar</span>
        <span class="badge badge-danger" style="font-size: 12px;"><?= number_format(count($unregisteredPretes)) ?></span>
        <?php if ($unregisteredPretesTidakLulus > 0): ?>
            <span class="badge badge-warning" style="font-size: 11px;" title="Tidak Lulus Pretes (Wajib Tutorial)"><?= $unregisteredPretesTidakLulus ?> tdk lulus</span>
        <?php endif; ?>
    </button>
</div>

<!-- ===================================================
     SECTION TAB 1: TABEL DATA PENDAFTAR TUTORIAL
     =================================================== -->
<div id="sectionPendaftarTutorial">
<div class="card" style="margin-bottom: 24px; box-shadow: var(--shadow); border: 1px solid rgba(0,0,0,0.06); border-radius: var(--radius);">
    <div class="card-header" style="background-color: #f8fafc; color: #1e293b; font-weight: 600; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 8px;">
        <div style="display: flex; align-items: center; gap: 8px;">
            <span style="font-size:18px;">📝</span>
            <span style="font-size: 16px;">Tabel Data Pendaftar</span>
            <span class="badge badge-info" id="badgeTotalFiltered" style="font-size: 12px;"><?= number_format($totalPendaftar) ?> pendaftar</span>
        </div>
        <div style="font-size: 13px; color: #64748b;">
            <?php if ($selected_gel_id === 'all'): ?>
                <span>Menampilkan seluruh pendaftar lintas periode</span>
            <?php elseif ($current_gel): ?>
                <span>Periode: <strong><?= sanitize($current_gel['tahun_ajaran']) ?></strong> / <strong><?= sanitize($current_gel['semester']) ?></strong> / <strong class="badge <?= $current_gel['status'] === 'aktif' ? 'badge-success' : 'badge-secondary' ?>"><?= sanitize($current_gel['gelombang']) ?></strong></span>
            <?php else: ?>
                <span class="text-danger">Belum ada periode tutorial aktif</span>
            <?php endif; ?>
        </div>
    </div>
    <div class="card-body">
        <div id="bulkActionsPendaftarContainer" style="margin-bottom: 16px; display: none;">
            <button type="button" class="btn btn-sm btn-secondary" id="btnCheckAllPendaftar" data-checked="false" style="background:#f1f5f9; color:#475569; border:1px solid #cbd5e1;">☑️ Pilih Semua</button>
            <button type="button" class="btn btn-sm btn-danger" id="btnBulkDeletePendaftar" style="margin-left:8px; display: none;">🗑️ Hapus Semua</button>
        </div>
        
        <form id="formBulkDeletePendaftar" method="POST" style="display:none;">
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
            <input type="hidden" name="action" value="bulk_delete_registration">
        </form>

        <div class="table-responsive">
            <table class="table" id="tablePendaftar" style="width: 100%;">
                <thead>
                    <tr>
                        <th style="width: 40px; text-align: center;">Pilih</th>
                        <th>Nama Mahasiswa</th>
                        <th>NIM</th>
                        <th>Jurusan</th>
                        <th>Pilihan Hari</th>
                        <th>Status Kelas</th>
                        <th>Tanggal &amp; Waktu Daftar</th>
                        <th>Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($allRegistrations as $reg): 
                        $rawH = trim($reg['hari_pilihan'] ?? '');
                        $h = ucfirst(strtolower($rawH));
                        if (empty($h) || $h === '-') {
                            $h = 'Belum Memilih';
                        }
                        $badgeClass = 'badge-primary';
                        if ($h === 'Senin') $badgeClass = 'badge-info';
                        elseif ($h === 'Selasa') $badgeClass = 'badge-primary';
                        elseif ($h === 'Rabu') $badgeClass = 'badge-success';
                        elseif ($h === 'Kamis') $badgeClass = 'badge-warning';
                        elseif ($h === 'Jumat') $badgeClass = 'badge-danger';
                        elseif ($h === 'Belum Memilih') $badgeClass = 'badge-secondary';
                        $registeredAt = trim($reg['created_at'] ?? '');
                        $registeredTimestamp = ($registeredAt !== '' && strpos($registeredAt, '0000-00-00') !== 0)
                            ? strtotime($registeredAt)
                            : false;
                    ?>
                    <tr data-hari="<?= htmlspecialchars($h, ENT_QUOTES) ?>"
                        data-status="<?= !empty($reg['tutorial_class_id']) ? 'diplot' : 'menunggu' ?>"
                        data-jurusan="<?= htmlspecialchars(trim($reg['program_studi'] ?? ''), ENT_QUOTES) ?>">
                        <td style="text-align: center;">
                            <input type="checkbox" class="check-pendaftar" name="reg_ids[]" value="<?= $reg['id'] ?>" style="width: 18px; height: 18px; cursor: pointer;">
                        </td>
                        <td>
                            <strong><?= sanitize($reg['nama_lengkap']) ?></strong>
                            <?php if ($selected_gel_id === 'all' && !empty($reg['tahun_ajaran'])): ?>
                                <div style="font-size: 11px; color: #64748b;"><?= sanitize($reg['tahun_ajaran']) ?> (<?= sanitize($reg['gelombang'] ?? '-') ?>)</div>
                            <?php endif; ?>
                        </td>
                        <td><?= sanitize($reg['nim'] ?: '-') ?></td>
                        <td><?= sanitize($reg['program_studi'] ?: '-') ?></td>
                        <td><span class="badge <?= $badgeClass ?>"><?= sanitize($h) ?></span></td>
                        <td>
                            <?php if (!empty($reg['tutorial_class_id'])): ?>
                                <span class="badge badge-success">✓ Sudah diplot</span>
                                <?php if (!empty($reg['nama_kelas'])): ?>
                                    <div style="font-size: 11.5px; color: #15803d; font-weight: 600; margin-top: 3px;">
                                        🏫 <?= sanitize($reg['nama_kelas']) ?>
                                        <?php if (!empty($reg['dosen_kelas'])): ?>
                                            <div style="font-size: 10.5px; color: #64748b; font-weight: 400;">Tutor: <?= sanitize($reg['dosen_kelas']) ?></div>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>
                            <?php else: ?>
                                <span class="badge badge-warning">⏳ Menunggu</span>
                            <?php endif; ?>
                        </td>
                        <td data-order="<?= $registeredTimestamp !== false ? $registeredTimestamp : 0 ?>" style="white-space: nowrap;">
                            <?= $registeredTimestamp !== false ? date('d/m/Y H:i', $registeredTimestamp) : '-' ?>
                        </td>
                        <td>
                            <button type="button" class="btn btn-sm btn-warning" style="margin-right:4px;"
                                onclick="openEditPendaftarModal(<?= (int)$reg['id'] ?>, <?= htmlspecialchars(json_encode($reg['hari_pilihan'] ?: ''), ENT_QUOTES) ?>)">
                                Edit
                            </button>
                            <form method="POST" style="display:inline;">
                                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                                <input type="hidden" name="action" value="delete_registration">
                                <input type="hidden" name="reg_id" value="<?= $reg['id'] ?>">
                                <button type="submit" class="btn btn-sm btn-danger" data-confirm="Hapus pendaftar ini sepenuhnya?">Hapus</button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
</div> <!-- End of sectionPendaftarTutorial -->

<!-- ===================================================
     SECTION TAB 2: PESERTA PRETES BELUM DAFTAR TUTORIAL
     =================================================== -->
<div id="sectionPretesBelumDaftar" style="display: none;">
    <div class="card" style="margin-bottom: 24px; box-shadow: var(--shadow); border: 1px solid rgba(0,0,0,0.06); border-radius: var(--radius);">
        <div class="card-header" style="background-color: #f8fafc; color: #1e293b; font-weight: 600; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
            <div style="display: flex; align-items: center; gap: 8px;">
                <span style="font-size: 18px;">⚠️</span>
                <span style="font-size: 16px;">Peserta Pretes yang Belum Mendaftar Tutorial</span>
                <span class="badge badge-danger" id="badgeTotalPretesFiltered" style="font-size: 12px;"><?= number_format(count($unregisteredPretes)) ?> mahasiswa</span>
            </div>
            <div style="display: flex; gap: 8px; align-items: center; flex-wrap: wrap;">
                <span class="badge badge-danger" style="font-size: 11.5px;">❌ <?= $unregisteredPretesTidakLulus ?> Tidak Lulus (Wajib)</span>
                <span class="badge badge-warning" style="font-size: 11.5px;">⏳ <?= $unregisteredPretesBelumDinilai ?> Belum Dinilai</span>
                <span class="badge badge-success" style="font-size: 11.5px;">✅ <?= $unregisteredPretesLulus ?> Lulus (Bebas)</span>
            </div>
        </div>
        <div class="card-body">
            
            <!-- Quick Filter Bar for Pretes Table -->
            <div style="display: flex; gap: 12px; margin-bottom: 16px; flex-wrap: wrap; align-items: center; background: #f8fafc; padding: 12px 16px; border-radius: 8px; border: 1px solid #e2e8f0;">
                <div style="flex: 1; min-width: 180px;">
                    <label style="font-size: 12px; font-weight: 600; color: #475569; display: block; margin-bottom: 4px;">Filter Status Kelulusan Pretes:</label>
                    <select id="filterPretesStatus" class="form-control" style="width: 100%; font-size: 13px; padding: 6px 10px; border-radius: 6px; border: 1px solid #cbd5e1;">
                        <option value="">Semua Status Pretes</option>
                        <option value="tidak_lulus">❌ Tidak Lulus (Wajib Tutorial)</option>
                        <option value="lulus">✅ Lulus Pretes (Bebas Tutorial)</option>
                    </select>
                </div>
                <div style="flex: 1; min-width: 180px;">
                    <label style="font-size: 12px; font-weight: 600; color: #475569; display: block; margin-bottom: 4px;">Filter Program Studi:</label>
                    <select id="filterPretesProdi" class="form-control" style="width: 100%; font-size: 13px; padding: 6px 10px; border-radius: 6px; border: 1px solid #cbd5e1;">
                        <option value="">Semua Program Studi</option>
                        <?php foreach ($listJurusan as $jur): ?>
                            <option value="<?= sanitize($jur) ?>"><?= sanitize($jur) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div style="align-self: flex-end;">
                    <button type="button" class="btn btn-sm btn-secondary" id="btnResetPretesFilter" style="background: #fff; border: 1px solid #cbd5e1; color: #475569; padding: 7px 14px; font-size: 12px; border-radius: 6px; font-weight: 600;">
                        🔄 Reset Filter
                    </button>
                </div>
            </div>

            <div class="table-responsive">
                <table class="table" id="tablePretesBelumDaftar" style="width: 100%;">
                    <thead>
                        <tr>
                            <th style="width: 40px; text-align: center;">No</th>
                            <th>Nama Mahasiswa</th>
                            <th>NIM</th>
                            <th>Jurusan</th>
                            <th>Periode Pretes</th>
                            <th>Status Pretes</th>
                            <th>Kontak / WA</th>
                            <th style="text-align: center;">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $no = 1;
                        foreach ($unregisteredPretes as $p): 
                            $stLulus = strtolower(trim($p['pretes_status_lulus'] ?? ''));
                            $statusCategory = ($stLulus === 'lulus') ? 'lulus' : 'tidak_lulus';
                            
                            $cleanPhone = preg_replace('/[^0-9]/', '', $p['no_hp'] ?? '');
                            if (strpos($cleanPhone, '0') === 0) {
                                $cleanPhone = '62' . substr($cleanPhone, 1);
                            }
                        ?>
                        <tr data-status="<?= $statusCategory ?>" data-jurusan="<?= htmlspecialchars(trim($p['program_studi'] ?? ''), ENT_QUOTES) ?>">
                            <td style="text-align: center; color: #64748b;"><?= $no++ ?></td>
                            <td>
                                <strong style="color: #1e293b;"><?= sanitize($p['nama_lengkap']) ?></strong>
                            </td>
                            <td><?= sanitize($p['nim'] ?: '-') ?></td>
                            <td><?= sanitize($p['program_studi'] ?: '-') ?></td>
                            <td>
                                <span class="badge badge-info" style="font-size: 11px;">
                                    <?= sanitize($p['pretes_periode'] ?: '-') ?>
                                </span>
                            </td>
                            <td>
                                <?php if ($statusCategory === 'lulus'): ?>
                                    <span class="badge badge-success">✅ Lulus Pretes (Bebas)</span>
                                    <?php if ($p['pretes_nilai'] !== null): ?>
                                        <div style="font-size: 11px; color: #166534; margin-top: 2px;">Nilai: <?= number_format($p['pretes_nilai'], 1) ?></div>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span class="badge badge-danger" style="font-weight: 700;">❌ Tidak Lulus (Wajib)</span>
                                    <?php if ($p['pretes_nilai'] !== null && $p['pretes_nilai'] > 0): ?>
                                        <div style="font-size: 11px; color: #991b1b; margin-top: 2px;">Nilai: <?= number_format($p['pretes_nilai'], 1) ?></div>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if (!empty($cleanPhone)): ?>
                                    <a href="https://wa.me/<?= $cleanPhone ?>?text=<?= urlencode("Assalamualaikum Wr. Wb. Halo {$p['nama_lengkap']}, mengingatkan bahwa pendaftaran kelas tutorial LPPAI telah dibuka. Silakan segera login dan memilih hari tutorial di https://lppai.quizb.my.id") ?>" 
                                       target="_blank" class="btn btn-sm btn-outline-success" style="padding: 3px 8px; font-size: 11.5px; display: inline-flex; align-items: center; gap: 4px;" title="Kirim Pengingat WhatsApp">
                                        <span>💬</span> <?= sanitize($p['no_hp']) ?>
                                    </a>
                                <?php else: ?>
                                    <span style="color: #94a3b8; font-size: 12px;">-</span>
                                <?php endif; ?>
                            </td>
                            <td style="text-align: center;">
                                <button type="button" class="btn btn-sm btn-success" style="padding: 4px 10px; font-size: 12px; font-weight: 600; display: inline-flex; align-items: center; gap: 4px;"
                                    onclick="openAddSingleRegistrationModal(<?= (int)$p['user_id'] ?>, <?= htmlspecialchars(json_encode($p['nama_lengkap']), ENT_QUOTES) ?>)">
                                    <span>➕</span> Daftarkan
                                </button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

        </div>
    </div>
</div>

</div> <!-- End of content wrapper -->

<script>
window.changeGelombang = function(val) {
    window.location.href = 'tutorial-data-pendaftar.php?gelombang_id=' + encodeURIComponent(val);
};

window.openEditPendaftarModal = function(regId, hariPilihan) {
    document.getElementById('edit_pendaftar_reg_id').value = regId;
    document.getElementById('edit_pendaftar_hari').value = hariPilihan;
    document.getElementById('editPendaftarModal').style.display = 'flex';
};

window.switchTableTab = function(tabName) {
    var pendaftarSection = document.getElementById('sectionPendaftarTutorial');
    var pretesSection = document.getElementById('sectionPretesBelumDaftar');
    var btnPendaftar = document.getElementById('tabBtnPendaftar');
    var btnPretes = document.getElementById('tabBtnPretesUnregistered');
    
    if (tabName === 'pretes') {
        if (pendaftarSection) pendaftarSection.style.display = 'none';
        if (pretesSection) pretesSection.style.display = 'block';
        if (btnPendaftar) {
            btnPendaftar.style.borderBottomColor = 'transparent';
            btnPendaftar.style.color = '#64748b';
            btnPendaftar.style.fontWeight = '600';
        }
        if (btnPretes) {
            btnPretes.style.borderBottomColor = '#2563eb';
            btnPretes.style.color = '#2563eb';
            btnPretes.style.fontWeight = '700';
        }
        if (window.jQuery && $.fn.DataTable.isDataTable('#tablePretesBelumDaftar')) {
            $('#tablePretesBelumDaftar').DataTable().columns.adjust().draw();
        }
    } else {
        if (pendaftarSection) pendaftarSection.style.display = 'block';
        if (pretesSection) pretesSection.style.display = 'none';
        if (btnPendaftar) {
            btnPendaftar.style.borderBottomColor = '#2563eb';
            btnPendaftar.style.color = '#2563eb';
            btnPendaftar.style.fontWeight = '700';
        }
        if (btnPretes) {
            btnPretes.style.borderBottomColor = 'transparent';
            btnPretes.style.color = '#64748b';
            btnPretes.style.fontWeight = '600';
        }
        if (window.jQuery && $.fn.DataTable.isDataTable('#tablePendaftar')) {
            $('#tablePendaftar').DataTable().columns.adjust().draw();
        }
    }
};

window.closeEditPendaftarModal = function() {
    document.getElementById('editPendaftarModal').style.display = 'none';
};

(function () {
    setTimeout(function() {
        if (window.jQuery && window.jQuery.fn.DataTable) {
            var $ = window.jQuery;
            
            // Custom filtering untuk DataTables
            $.fn.dataTable.ext.search.push(function(settings, data, dataIndex) {
                if (settings.nTable.id !== 'tablePendaftar') return true;
                
                var tr = settings.aoData[dataIndex].nTr;
                var rowHari = tr.getAttribute('data-hari') || '';
                var rowStatus = tr.getAttribute('data-status') || '';
                var rowJurusan = tr.getAttribute('data-jurusan') || '';
                
                var filterHari = $('#filterPilihanHari').val();
                var filterStatus = $('#filterStatusPlotting').val();
                var filterJurusan = $('#filterJurusan').val();
                
                if (filterHari && rowHari !== filterHari) {
                    return false;
                }
                if (filterStatus && rowStatus !== filterStatus) {
                    return false;
                }
                if (filterJurusan && rowJurusan !== filterJurusan) {
                    return false;
                }
                return true;
            });

            // Inisialisasi DataTable untuk Data Pendaftar
            var tablePendaftar = $('#tablePendaftar').DataTable({
                "destroy": true,
                "pageLength": 10,
                "language": {
                    "search": "Cari Mahasiswa / NIM:",
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

            function updateFilterCount() {
                if (tablePendaftar) {
                    var info = tablePendaftar.page.info();
                    var countBadge = document.getElementById('badgeTotalFiltered');
                    if (countBadge) {
                        countBadge.textContent = info.recordsDisplay + ' pendaftar';
                    }
                }
            }

            // Event listener filter instan
            $('#filterPilihanHari, #filterStatusPlotting, #filterJurusan').on('change', function() {
                tablePendaftar.draw();
                updateFilterCount();
                updateBulkPendaftarButtons();
            });

            // Inisialisasi DataTable untuk Peserta Pretes Belum Daftar
            var tablePretes = $('#tablePretesBelumDaftar').DataTable({
                "destroy": true,
                "pageLength": 10,
                "language": {
                    "search": "Cari Peserta Pretes:",
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

            // Custom filter untuk tablePretesBelumDaftar
            $.fn.dataTable.ext.search.push(function(settings, data, dataIndex) {
                if (settings.nTable.id !== 'tablePretesBelumDaftar') return true;
                
                var tr = settings.aoData[dataIndex].nTr;
                var rowStatus = tr.getAttribute('data-status') || '';
                var rowJurusan = tr.getAttribute('data-jurusan') || '';
                
                var filterStatus = $('#filterPretesStatus').val();
                var filterJurusan = $('#filterPretesProdi').val();
                
                if (filterStatus && rowStatus !== filterStatus) {
                    return false;
                }
                if (filterJurusan && rowJurusan !== filterJurusan) {
                    return false;
                }
                return true;
            });

            $('#filterPretesStatus, #filterPretesProdi').on('change', function() {
                tablePretes.draw();
                var info = tablePretes.page.info();
                var badge = document.getElementById('badgeTotalPretesFiltered');
                if (badge) badge.textContent = info.recordsDisplay + ' mahasiswa';
            });

            $('#btnResetPretesFilter').on('click', function() {
                $('#filterPretesStatus').val('');
                $('#filterPretesProdi').val('');
                tablePretes.search('').draw();
                var info = tablePretes.page.info();
                var badge = document.getElementById('badgeTotalPretesFiltered');
                if (badge) badge.textContent = info.recordsDisplay + ' mahasiswa';
            });

            // Tombol Reset Filter
            $('#btnResetFilter').on('click', function() {
                $('#filterPilihanHari').val('');
                $('#filterStatusPlotting').val('');
                $('#filterJurusan').val('');
                tablePendaftar.search('').draw();
                updateFilterCount();
                updateBulkPendaftarButtons();
                $('.day-stat-card').removeClass('active-filter');
                $('.prodi-stat-item').removeClass('active-filter');
            });

            // Quick click handlers for Stat Cards
            window.filterByHari = function(hari) {
                $('#filterPilihanHari').val(hari).trigger('change');
                $('.day-stat-card').removeClass('active-filter');
                var el = document.getElementById('tablePendaftar');
                if (el) el.scrollIntoView({ behavior: 'smooth', block: 'start' });
            };

            window.filterByJurusan = function(jurusan) {
                $('#filterJurusan').val(jurusan).trigger('change');
                $('.prodi-stat-item').removeClass('active-filter');
                var el = document.getElementById('tablePendaftar');
                if (el) el.scrollIntoView({ behavior: 'smooth', block: 'start' });
            };

            // Fitur Checkbox untuk tabel Data Pendaftar Tutorial
            const checkAllPendaftarBtn = document.getElementById('btnCheckAllPendaftar');
            const bulkDeletePendaftarBtn = document.getElementById('btnBulkDeletePendaftar');
            const bulkActionsPendaftarContainer = document.getElementById('bulkActionsPendaftarContainer');

            function getPendaftarNodes() {
                if (tablePendaftar) {
                    return tablePendaftar.rows({ search: 'applied' }).nodes();
                }
                return document;
            }

            function updateBulkPendaftarButtons() {
                const checkedCount = $(getPendaftarNodes()).find('.check-pendaftar:checked').length;
                const totalCount = $(getPendaftarNodes()).find('.check-pendaftar').length;
                if (totalCount > 0) {
                    bulkActionsPendaftarContainer.style.display = 'block';
                    bulkDeletePendaftarBtn.style.display = checkedCount > 0 ? 'inline-block' : 'none';
                    if (checkedCount > 0) {
                        bulkDeletePendaftarBtn.innerHTML = '🗑️ Hapus ' + checkedCount + ' Terpilih';
                    }
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
                    this.innerHTML = newValue ? '🔳 Hapus Centang' : '☑️ Pilih Semua';
                    
                    updateBulkPendaftarButtons();
                });
            }

            $('#tablePendaftar').on('change', '.check-pendaftar', updateBulkPendaftarButtons);

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

        }
    }, 400);

})();
</script>

<!-- Modal Edit Pendaftar -->
<div id="editPendaftarModal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:9999; align-items:center; justify-content:center;">
    <div style="background:#fff; width:90%; max-width:500px; border-radius:12px; padding:24px; box-shadow:0 4px 6px rgba(0,0,0,0.1);">
        <h3 style="margin-top:0; margin-bottom:20px; font-size:18px; color:#1e293b;">Edit Data Pendaftar</h3>
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
            <input type="hidden" name="action" value="edit_pendaftaran">
            <input type="hidden" name="reg_id" id="edit_pendaftar_reg_id" value="">
            
            <div class="form-group" style="margin-bottom:24px;">
                <label style="display:block; margin-bottom:8px; font-size:14px; color:#475569;">Pilihan Hari</label>
                <select name="hari_pilihan" id="edit_pendaftar_hari" required
                    style="width:100%; padding:10px; border:1.5px solid #cbd5e1; border-radius:8px; font-size:14px;">
                    <option value="">-- Pilih Hari --</option>
                    <?php 
                    $days = ['Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat'];
                    $modalGel = $current_gel ?: $active_gel;
                    foreach ($days as $day): 
                        $dayLower = strtolower($day);
                        $kuota = $modalGel["kuota_$dayLower"] ?? 0;
                        $terisi = $statsHari[$day] ?? 0;
                        $sisa = $kuota - $terisi;
                        $isFull = ($kuota > 0 && $sisa <= 0);
                    ?>
                    <option value="<?= $day ?>">
                        <?= $day ?> <?= $isFull ? '(Penuh)' : "(Sisa $sisa kursi)" ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div style="display:flex; justify-content:flex-end; gap:12px;">
                <button type="button" class="btn btn-secondary" onclick="closeEditPendaftarModal()" style="background:#f1f5f9; color:#475569; border:none; padding:8px 16px;">Batal</button>
                <button type="submit" class="btn btn-primary" style="padding:8px 16px;">Simpan Perubahan</button>
            </div>
        </form>
    </div>
</div>

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
                    $modalGel = $current_gel ?: $active_gel;
                    foreach ($days as $day): 
                        $dayLower = strtolower($day);
                        $kuota = $modalGel["kuota_$dayLower"] ?? 0;
                        $terisi = $statsHari[$day] ?? 0;
                        $sisa = $kuota - $terisi;
                        $isFull = ($kuota > 0 && $sisa <= 0);
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
