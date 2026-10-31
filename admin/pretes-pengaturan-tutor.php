<?php
/**
 * LPPAI Corner - Admin: Pengaturan Tutor Pretes
 * Halaman untuk mengelola tutor/penguji secara bebas pada seluruh kolom terkait
 */
define('PAGE_TITLE', 'Pengaturan Tutor Pretes');
require_once __DIR__ . '/../includes/auth.php';
requireAdmin();

$pdo = getDBConnection();
$message = '';
$msgType = '';

// Auto-migration aman untuk tabel dan kolom terkait tutor/penguji
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS master_pretes (
            id INT AUTO_INCREMENT PRIMARY KEY,
            periode VARCHAR(100) NOT NULL,
            tahun_ajaran VARCHAR(50) NOT NULL,
            status ENUM('aktif','selesai','nonaktif') NOT NULL DEFAULT 'aktif',
            jam VARCHAR(50) DEFAULT '13.00 WIB (Jam 1)',
            kuota_senin INT DEFAULT 0,
            kuota_selasa INT DEFAULT 0,
            kuota_rabu INT DEFAULT 0,
            kuota_kamis INT DEFAULT 0,
            kuota_jumat INT DEFAULT 0,
            penguji_senin VARCHAR(1000) DEFAULT NULL,
            penguji_selasa VARCHAR(1000) DEFAULT NULL,
            penguji_rabu VARCHAR(1000) DEFAULT NULL,
            penguji_kamis VARCHAR(1000) DEFAULT NULL,
            penguji_jumat VARCHAR(1000) DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");
} catch (Exception $e) {}

try {
    $pdo->exec("SET FOREIGN_KEY_CHECKS=0");
    $pdo->exec("ALTER TABLE pretes_registrations MODIFY pretes_schedule_id INT NULL");
    $pdo->exec("ALTER TABLE pretes_registrations ADD COLUMN hari_pilihan VARCHAR(20) DEFAULT NULL");
    $pdo->exec("ALTER TABLE pretes_registrations ADD COLUMN jam VARCHAR(50) DEFAULT '13.00 WIB (Jam 1)'");
    $pdo->exec("ALTER TABLE pretes_registrations ADD COLUMN dosen_penguji VARCHAR(150) DEFAULT NULL");
    $pdo->exec("ALTER TABLE pretes_registrations ADD COLUMN ruangan VARCHAR(100) DEFAULT NULL");
    $pdo->exec("SET FOREIGN_KEY_CHECKS=1");
} catch (Exception $e) {}

// Pastikan fitur pretes assessments dan absensi terinisialisasi
if (function_exists('ensurePretesFeatures')) {
    ensurePretesFeatures($pdo);
} else {
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS pretes_assessments (
            registration_id INT NOT NULL PRIMARY KEY,
            scores_json TEXT NOT NULL,
            assessed_by INT NOT NULL,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Exception $e) {}
}

// ── Helpers ───────────────────────────────────────────────────────────────────
function parsePengujiList($pengujiStr) {
    if (empty($pengujiStr) || trim($pengujiStr) === '') return [];
    $pengujiStr = trim($pengujiStr);
    if (strpos($pengujiStr, '|||') !== false) {
        return array_values(array_filter(array_map('trim', explode('|||', $pengujiStr)), fn($v) => $v !== ''));
    }
    return [$pengujiStr];
}

// Helper pencocokan cerdas nama penguji (kebal perbedaan gelar, spasi, dan tanda petik)
function isPengujiMatch($targetName, $optionName) {
    if (empty($targetName) || empty($optionName)) return false;
    $targetName = trim($targetName);
    $optionName = trim($optionName);
    if ($targetName === $optionName) return true;

    $norm = function($str) {
        $str = mb_strtolower($str, 'UTF-8');
        $str = preg_replace('/[`\'’‘]/u', "'", $str);
        $str = preg_replace('/\s+/', ' ', $str);
        return trim($str);
    };

    $tNorm = $norm($targetName);
    $oNorm = $norm($optionName);
    if ($tNorm === $oNorm) return true;

    $tBase = trim(explode(',', $tNorm)[0]);
    $oBase = trim(explode(',', $oNorm)[0]);
    if (strlen($tBase) >= 4 && strlen($oBase) >= 4) {
        if ($tBase === $oBase || strpos($oBase, $tBase) !== false || strpos($tBase, $oBase) !== false) {
            return true;
        }
    }
    return false;
}

// ── 1. HANDLE AJAX REQUESTS ───────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    $token  = $_POST['csrf_token'] ?? '';

    // AJAX: Update Dosen Penguji Peserta secara Instan (1-Klik via Dropdown)
    if ($action === 'update_tutor_ajax') {
        header('Content-Type: application/json; charset=utf-8');
        if (!verifyCsrf($token)) {
            echo json_encode(['success' => false, 'message' => 'Sesi tidak valid atau telah kedaluwarsa. Silakan refresh halaman.']);
            exit;
        }
        $regId = (int)($_POST['reg_id'] ?? 0);
        $tutor = trim((string)($_POST['dosen_penguji'] ?? ''));

        if ($regId <= 0) {
            echo json_encode(['success' => false, 'message' => 'ID peserta tidak valid.']);
            exit;
        }

        try {
            $stmt = $pdo->prepare("UPDATE pretes_registrations SET dosen_penguji = ? WHERE id = ?");
            $stmt->execute([$tutor !== '' ? $tutor : null, $regId]);
            echo json_encode([
                'success' => true,
                'message' => 'Dosen penguji telah dirubah',
                'reg_id'  => $regId,
                'dosen_penguji' => $tutor
            ]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => 'Gagal mengubah dosen penguji: ' . $e->getMessage()]);
        }
        exit;
    }

    // AJAX: Toggle Status Aktif/Nonaktif Tutor Cepat
    if ($action === 'toggle_tutor_status_ajax') {
        header('Content-Type: application/json; charset=utf-8');
        if (!verifyCsrf($token)) {
            echo json_encode(['success' => false, 'message' => 'Sesi tidak valid.']);
            exit;
        }
        $tutorId = (int)($_POST['tutor_id'] ?? 0);
        if ($tutorId <= 0) {
            echo json_encode(['success' => false, 'message' => 'ID tutor tidak valid.']);
            exit;
        }

        try {
            $stmt = $pdo->prepare("SELECT is_active FROM users WHERE id = ? AND role = 'dosen'");
            $stmt->execute([$tutorId]);
            $current = $stmt->fetchColumn();
            if ($current === false) {
                echo json_encode(['success' => false, 'message' => 'Tutor tidak ditemukan.']);
                exit;
            }
            $newStatus = $current ? 0 : 1;
            $upd = $pdo->prepare("UPDATE users SET is_active = ? WHERE id = ? AND role = 'dosen'");
            $upd->execute([$newStatus, $tutorId]);

            echo json_encode([
                'success' => true,
                'message' => 'Status tutor berhasil diubah menjadi ' . ($newStatus ? 'Aktif' : 'Nonaktif'),
                'is_active' => $newStatus
            ]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => 'Gagal mengubah status: ' . $e->getMessage()]);
        }
        exit;
    }

    // ── 2. HANDLE STANDARD POST ACTIONS ───────────────────────────────────────
    if (!verifyCsrf($token)) {
        $message = 'Sesi tidak valid atau telah kedaluwarsa.';
        $msgType = 'danger';
    } else {
        // A. TAMBAH TUTOR / PENGUJI BARU
        if ($action === 'create_tutor') {
            $nama     = trim($_POST['nama_lengkap'] ?? '');
            $nim      = trim($_POST['nim'] ?? ''); // NIDN / NIP
            $username = trim($_POST['username'] ?? '');
            $email    = trim($_POST['email'] ?? '');
            $noHp     = formatNoHp(trim($_POST['no_hp'] ?? ''));
            $password = trim($_POST['password'] ?? '123456');
            $isActive = isset($_POST['is_active']) ? 1 : 0;

            if (empty($username)) {
                $username = !empty($nim) ? $nim : strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $nama));
            }

            if (empty($nama) || empty($username)) {
                $message = 'Nama lengkap dan Username/NIDN wajib diisi.';
                $msgType = 'danger';
            } else {
                $chk = $pdo->prepare("SELECT id FROM users WHERE username = ?");
                $chk->execute([$username]);
                if ($chk->fetch()) {
                    $message = 'Username/NIDN <strong>' . htmlspecialchars($username) . '</strong> sudah digunakan.';
                    $msgType = 'danger';
                } else {
                    $hash = password_hash($password, PASSWORD_DEFAULT);
                    $ins = $pdo->prepare("
                        INSERT INTO users (username, password, nama_lengkap, nim, email, no_hp, role, is_active)
                        VALUES (?, ?, ?, ?, ?, ?, 'dosen', ?)
                    ");
                    $ins->execute([$username, $hash, $nama, $nim, $email, $noHp, $isActive]);
                    $message = 'Tutor / Dosen Penguji <strong>' . htmlspecialchars($nama) . '</strong> berhasil ditambahkan!';
                    $msgType = 'success';
                }
            }

        // B. EDIT DATA TUTOR / PENGUJI (DENGAN OPSI SINKRONISASI OTOMATIS)
        } elseif ($action === 'update_tutor') {
            $id          = (int)($_POST['id'] ?? 0);
            $nama        = trim($_POST['nama_lengkap'] ?? '');
            $nim         = trim($_POST['nim'] ?? '');
            $username    = trim($_POST['username'] ?? '');
            $email       = trim($_POST['email'] ?? '');
            $noHp        = formatNoHp(trim($_POST['no_hp'] ?? ''));
            $isActive    = isset($_POST['is_active']) ? 1 : 0;
            $syncCascade = isset($_POST['sync_cascade']) ? 1 : 0;

            if ($id <= 0 || empty($nama) || empty($username)) {
                $message = 'ID, Nama lengkap, dan Username wajib diisi.';
                $msgType = 'danger';
            } else {
                $chkUser = $pdo->prepare("SELECT id FROM users WHERE username = ? AND id != ?");
                $chkUser->execute([$username, $id]);
                if ($chkUser->fetch()) {
                    $message = 'Username sudah digunakan oleh akun lain.';
                    $msgType = 'danger';
                } else {
                    // Ambil nama lama untuk cascade jika ada perubahan
                    $stmtOld = $pdo->prepare("SELECT nama_lengkap FROM users WHERE id = ? AND role = 'dosen'");
                    $stmtOld->execute([$id]);
                    $oldName = $stmtOld->fetchColumn();

                    $upd = $pdo->prepare("
                        UPDATE users 
                        SET nama_lengkap = ?, nim = ?, username = ?, email = ?, no_hp = ?, is_active = ?
                        WHERE id = ? AND role = 'dosen'
                    ");
                    $upd->execute([$nama, $nim, $username, $email, $noHp, $isActive, $id]);

                    $syncInfo = '';
                    if ($syncCascade && $oldName && trim($oldName) !== $nama) {
                        // 1. Update kolom dosen_penguji di pretes_registrations
                        $stmtReg = $pdo->prepare("UPDATE pretes_registrations SET dosen_penguji = ? WHERE TRIM(dosen_penguji) = ?");
                        $stmtReg->execute([$nama, trim($oldName)]);
                        $affectedReg = $stmtReg->rowCount();

                        // 2. Update di master_pretes penguji harian (penguji_senin s/d penguji_jumat)
                        $masters = $pdo->query("SELECT id, penguji_senin, penguji_selasa, penguji_rabu, penguji_kamis, penguji_jumat FROM master_pretes")->fetchAll();
                        foreach ($masters as $m) {
                            $mUpdates = [];
                            $mParams = [];
                            foreach (['penguji_senin', 'penguji_selasa', 'penguji_rabu', 'penguji_kamis', 'penguji_jumat'] as $col) {
                                $list = parsePengujiList($m[$col] ?? '');
                                $changed = false;
                                foreach ($list as &$item) {
                                    if (trim($item) === trim($oldName)) {
                                        $item = $nama;
                                        $changed = true;
                                    }
                                }
                                unset($item);
                                if ($changed) {
                                    $mUpdates[] = "$col = ?";
                                    $mParams[] = implode('|||', array_unique($list));
                                }
                            }
                            if (!empty($mUpdates)) {
                                $mParams[] = $m['id'];
                                $pdo->prepare("UPDATE master_pretes SET " . implode(', ', $mUpdates) . " WHERE id = ?")->execute($mParams);
                            }
                        }

                        // 3. Update tabel sesi absensi jika ada
                        try {
                            $stmtSes = $pdo->prepare("UPDATE pretes_attendance_sessions SET penguji = ? WHERE TRIM(penguji) = ?");
                            $stmtSes->execute([$nama, trim($oldName)]);
                        } catch (Exception $e) {}

                        $syncInfo = " (Nama penguji pada $affectedReg peserta dan jadwal master juga telah disinkronkan).";
                    }

                    $message = 'Data tutor <strong>' . htmlspecialchars($nama) . '</strong> berhasil diperbarui!' . $syncInfo;
                    $msgType = 'success';
                }
            }

        // C. RESET PASSWORD TUTOR
        } elseif ($action === 'reset_password_tutor') {
            $id          = (int)($_POST['id'] ?? 0);
            $newPassword = trim($_POST['new_password'] ?? '');

            if ($id <= 0 || empty($newPassword)) {
                $message = 'Password baru tidak boleh kosong.';
                $msgType = 'danger';
            } else {
                $hash = password_hash($newPassword, PASSWORD_DEFAULT);
                $pdo->prepare("UPDATE users SET password = ? WHERE id = ? AND role = 'dosen'")->execute([$hash, $id]);
                $message = 'Password tutor berhasil direset!';
                $msgType = 'success';
            }

        // D. HAPUS ATAU NONAKTIFKAN TUTOR
        } elseif ($action === 'delete_tutor') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id > 0) {
                $stmtName = $pdo->prepare("SELECT nama_lengkap FROM users WHERE id = ? AND role = 'dosen'");
                $stmtName->execute([$id]);
                $tName = $stmtName->fetchColumn();

                if ($tName) {
                    $assignedCount = $pdo->prepare("SELECT COUNT(*) FROM pretes_registrations WHERE TRIM(dosen_penguji) = ?");
                    $assignedCount->execute([trim($tName)]);
                    $cnt = (int)$assignedCount->fetchColumn();

                    if ($cnt > 0) {
                        // Jangan hapus langsung jika sudah memiliki peserta, jadikan nonaktif agar data aman
                        $pdo->prepare("UPDATE users SET is_active = 0 WHERE id = ?")->execute([$id]);
                        $message = 'Tutor <strong>' . htmlspecialchars($tName) . '</strong> telah dinonaktifkan (karena memiliki ' . $cnt . ' riwayat bimbingan peserta).';
                        $msgType = 'warning';
                    } else {
                        $pdo->prepare("DELETE FROM users WHERE id = ? AND role = 'dosen'")->execute([$id]);
                        $message = 'Tutor <strong>' . htmlspecialchars($tName) . '</strong> berhasil dihapus.';
                        $msgType = 'success';
                    }
                }
            }

        // E. BULK REASSIGN / TRANSFER TUTOR MASSAL
        } elseif ($action === 'bulk_reassign_tutor') {
            $fromTutor   = trim($_POST['from_tutor'] ?? '');
            $toTutor     = trim($_POST['to_tutor'] ?? '');
            $filterHari  = trim($_POST['filter_hari'] ?? '');
            $periode     = trim($_POST['periode'] ?? '');

            if (empty($toTutor)) {
                $message = 'Silakan pilih Tutor Pengganti / Tujuan.';
                $msgType = 'danger';
            } else {
                $sql = "UPDATE pretes_registrations SET dosen_penguji = ? WHERE 1=1";
                $params = [$toTutor === '__EMPTY__' ? null : $toTutor];

                if (!empty($periode)) {
                    $sql .= " AND periode = ?";
                    $params[] = $periode;
                }

                if ($fromTutor === '__EMPTY__') {
                    $sql .= " AND (dosen_penguji IS NULL OR dosen_penguji = '')";
                } elseif (!empty($fromTutor)) {
                    $sql .= " AND TRIM(dosen_penguji) = ?";
                    $params[] = trim($fromTutor);
                }

                if (!empty($filterHari) && $filterHari !== 'Semua') {
                    $sql .= " AND hari_pilihan = ?";
                    $params[] = $filterHari;
                }

                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
                $affected = $stmt->rowCount();

                $toLabel = $toTutor === '__EMPTY__' ? 'Dikosongkan (Belum Ditentukan)' : htmlspecialchars($toTutor);
                $message = "Berhasil memindahkan <strong>$affected mahasiswa</strong> ke tutor penguji: <strong>$toLabel</strong>.";
                $msgType = 'success';
            }

        // F. DISTRIBUSI / BAGI RATA TUTOR OTOMATIS (AUTO DISTRIBUTE)
        } elseif ($action === 'auto_distribute_tutors') {
            $targetHari    = trim($_POST['distribute_hari'] ?? '');
            $periode       = trim($_POST['periode'] ?? '');
            $selectedTutor = $_POST['distribute_tutors'] ?? [];

            if (empty($selectedTutor) || !is_array($selectedTutor)) {
                $message = 'Pilih setidaknya satu tutor untuk menerima pembagian peserta.';
                $msgType = 'danger';
            } else {
                $selectedTutor = array_values(array_filter(array_map('trim', $selectedTutor)));
                $numTutors = count($selectedTutor);

                $sql = "SELECT id FROM pretes_registrations WHERE (dosen_penguji IS NULL OR dosen_penguji = '')";
                $params = [];
                if (!empty($periode)) {
                    $sql .= " AND periode = ?";
                    $params[] = $periode;
                }
                if (!empty($targetHari) && $targetHari !== 'Semua') {
                    $sql .= " AND hari_pilihan = ?";
                    $params[] = $targetHari;
                }
                $sql .= " ORDER BY hari_pilihan ASC, id ASC";

                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
                $unassigned = $stmt->fetchAll(PDO::FETCH_COLUMN);

                if (empty($unassigned)) {
                    $message = 'Tidak ada mahasiswa tanpa tutor pada kriteria yang dipilih.';
                    $msgType = 'warning';
                } else {
                    $updStmt = $pdo->prepare("UPDATE pretes_registrations SET dosen_penguji = ? WHERE id = ?");
                    $idx = 0;
                    foreach ($unassigned as $regId) {
                        $assignedTutor = $selectedTutor[$idx % $numTutors];
                        $updStmt->execute([$assignedTutor, $regId]);
                        $idx++;
                    }
                    $message = "Berhasil mendistribusikan <strong>" . count($unassigned) . " mahasiswa</strong> secara merata kepada $numTutors tutor penguji.";
                    $msgType = 'success';
                }
            }

        // G. SIMPAN PLOTTING JADWAL HARIAN TUTOR MASTER PRETES
        } elseif ($action === 'update_master_jadwal_tutor') {
            $masterId = (int)($_POST['master_id'] ?? 0);
            if ($masterId <= 0) {
                $message = 'Data periode master tidak valid.';
                $msgType = 'danger';
            } else {
                $pengujiSenin  = isset($_POST['penguji_senin']) && is_array($_POST['penguji_senin']) ? implode('|||', array_filter(array_map('trim', $_POST['penguji_senin']))) : '';
                $pengujiSelasa = isset($_POST['penguji_selasa']) && is_array($_POST['penguji_selasa']) ? implode('|||', array_filter(array_map('trim', $_POST['penguji_selasa']))) : '';
                $pengujiRabu   = isset($_POST['penguji_rabu']) && is_array($_POST['penguji_rabu']) ? implode('|||', array_filter(array_map('trim', $_POST['penguji_rabu']))) : '';
                $pengujiKamis  = isset($_POST['penguji_kamis']) && is_array($_POST['penguji_kamis']) ? implode('|||', array_filter(array_map('trim', $_POST['penguji_kamis']))) : '';
                $pengujiJumat  = isset($_POST['penguji_jumat']) && is_array($_POST['penguji_jumat']) ? implode('|||', array_filter(array_map('trim', $_POST['penguji_jumat']))) : '';

                $kuotaSenin  = (int)($_POST['kuota_senin'] ?? 30);
                $kuotaSelasa = (int)($_POST['kuota_selasa'] ?? 30);
                $kuotaRabu   = (int)($_POST['kuota_rabu'] ?? 30);
                $kuotaKamis  = (int)($_POST['kuota_kamis'] ?? 30);
                $kuotaJumat  = (int)($_POST['kuota_jumat'] ?? 30);

                $pdo->prepare("
                    UPDATE master_pretes 
                    SET penguji_senin = ?, penguji_selasa = ?, penguji_rabu = ?, penguji_kamis = ?, penguji_jumat = ?,
                        kuota_senin = ?, kuota_selasa = ?, kuota_rabu = ?, kuota_kamis = ?, kuota_jumat = ?
                    WHERE id = ?
                ")->execute([
                    $pengujiSenin, $pengujiSelasa, $pengujiRabu, $pengujiKamis, $pengujiJumat,
                    $kuotaSenin, $kuotaSelasa, $kuotaRabu, $kuotaKamis, $kuotaJumat,
                    $masterId
                ]);

                $message = 'Jadwal dan plotting tutor harian pada master pretes berhasil disimpan!';
                $msgType = 'success';
            }

        // H. KOSONGKAN PENUGASAN TUTOR MASSAL
        } elseif ($action === 'clear_tutor_massal') {
            $periode    = trim($_POST['periode'] ?? '');
            $targetHari = trim($_POST['clear_hari'] ?? '');

            $sql = "UPDATE pretes_registrations SET dosen_penguji = NULL WHERE 1=1";
            $params = [];
            if (!empty($periode)) {
                $sql .= " AND periode = ?";
                $params[] = $periode;
            }
            if (!empty($targetHari) && $targetHari !== 'Semua') {
                $sql .= " AND hari_pilihan = ?";
                $params[] = $targetHari;
            }

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $affected = $stmt->rowCount();

            $message = "Penugasan tutor untuk <strong>$affected mahasiswa</strong> telah dikosongkan.";
            $msgType = 'info';
        }
    }
}

// ── 3. QUERY DATA PERIODE & MASTER PRETES ──────────────────────────────────────
$periodsList = $pdo->query("SELECT id, periode, tahun_ajaran, status FROM master_pretes ORDER BY id DESC")->fetchAll();

$selectedPeriodName = trim($_GET['periode'] ?? '');
$currentMaster = null;

if (!empty($selectedPeriodName)) {
    $stmtM = $pdo->prepare("SELECT * FROM master_pretes WHERE periode = ? LIMIT 1");
    $stmtM->execute([$selectedPeriodName]);
    $currentMaster = $stmtM->fetch();
}

if (!$currentMaster) {
    $currentMaster = $pdo->query("SELECT * FROM master_pretes WHERE status = 'aktif' ORDER BY id DESC LIMIT 1")->fetch()
                  ?: $pdo->query("SELECT * FROM master_pretes ORDER BY id DESC LIMIT 1")->fetch();
}

if (!$currentMaster) {
    $curYear = (int)date('Y');
    $ta  = $curYear . ' - ' . ($curYear + 1);
    $per = 'Pretes ' . $curYear . '/' . ($curYear + 1);
    $pdo->prepare("
        INSERT INTO master_pretes 
        (periode, tahun_ajaran, status, jam, kuota_senin, kuota_selasa, kuota_rabu, kuota_kamis, kuota_jumat) 
        VALUES (?, ?, 'aktif', '13.00 WIB (Jam 1)', 30, 30, 30, 30, 30)
    ")->execute([$per, $ta]);
    $currentMaster = $pdo->query("SELECT * FROM master_pretes WHERE id = " . $pdo->lastInsertId())->fetch();
}

$periodeName = $currentMaster['periode'] ?? 'Pretes';

// ── 4. QUERY MASTER TUTOR (ROLE DOSEN) DENGAN STATISTIK BEBAN ──────────────────
$tutorsQuery = "
    SELECT u.id, u.username, u.nama_lengkap, u.nim, u.email, u.no_hp, u.is_active, u.created_at,
           COUNT(pr.id) as total_peserta,
           SUM(CASE WHEN pa.scores_json IS NOT NULL THEN 1 ELSE 0 END) as sudah_dinilai,
           SUM(CASE WHEN pr.id IS NOT NULL AND pa.scores_json IS NULL THEN 1 ELSE 0 END) as belum_dinilai
    FROM users u
    LEFT JOIN pretes_registrations pr ON TRIM(pr.dosen_penguji) = TRIM(u.nama_lengkap) AND pr.periode = ?
    LEFT JOIN pretes_assessments pa ON pa.registration_id = pr.id
    WHERE u.role = 'dosen'
    GROUP BY u.id, u.username, u.nama_lengkap, u.nim, u.email, u.no_hp, u.is_active, u.created_at
    ORDER BY u.nama_lengkap ASC
";
$stmtTut = $pdo->prepare($tutorsQuery);
$stmtTut->execute([$periodeName]);
$tutors = $stmtTut->fetchAll();

// List tutor aktif saja untuk dropdown
$activeTutors = array_filter($tutors, fn($t) => (int)$t['is_active'] === 1);

// ── 5. QUERY PESERTA PRETES PERIODE AKTIF ──────────────────────────────────────
$stmtPart = $pdo->prepare("
    SELECT pr.id as reg_id, pr.user_id, pr.periode, pr.hari_pilihan, pr.jam, pr.dosen_penguji, pr.ruangan, pr.status, pr.tanggal_daftar,
           u.nim, u.nama_lengkap, u.program_studi, u.no_hp,
           CASE WHEN pa.scores_json IS NOT NULL THEN 1 ELSE 0 END as is_assessed
    FROM pretes_registrations pr
    JOIN users u ON pr.user_id = u.id
    LEFT JOIN pretes_assessments pa ON pa.registration_id = pr.id
    WHERE pr.periode = ?
    ORDER BY FIELD(pr.hari_pilihan, 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat'), pr.dosen_penguji ASC, u.nama_lengkap ASC
");
$stmtPart->execute([$periodeName]);
$participants = $stmtPart->fetchAll();

// Statistik Peserta
$totalParticipants = count($participants);
$totalWithTutor    = count(array_filter($participants, fn($p) => !empty(trim($p['dosen_penguji'] ?? ''))));
$totalUnassigned   = $totalParticipants - $totalWithTutor;
$totalAssessed     = count(array_filter($participants, fn($p) => (int)$p['is_assessed'] === 1));

// Parsing jadwal penguji master
$masterSenin  = parsePengujiList($currentMaster['penguji_senin'] ?? '');
$masterSelasa = parsePengujiList($currentMaster['penguji_selasa'] ?? '');
$masterRabu   = parsePengujiList($currentMaster['penguji_rabu'] ?? '');
$masterKamis  = parsePengujiList($currentMaster['penguji_kamis'] ?? '');
$masterJumat  = parsePengujiList($currentMaster['penguji_jumat'] ?? '');

$daysInfo = [
    'Senin'  => ['date' => '2 Nov 2026', 'penguji' => $masterSenin,  'kuota' => (int)($currentMaster['kuota_senin'] ?? 30)],
    'Selasa' => ['date' => '3 Nov 2026', 'penguji' => $masterSelasa, 'kuota' => (int)($currentMaster['kuota_selasa'] ?? 30)],
    'Rabu'   => ['date' => '4 Nov 2026', 'penguji' => $masterRabu,   'kuota' => (int)($currentMaster['kuota_rabu'] ?? 30)],
    'Kamis'  => ['date' => '5 Nov 2026', 'penguji' => $masterKamis,  'kuota' => (int)($currentMaster['kuota_kamis'] ?? 30)],
    'Jumat'  => ['date' => '6 Nov 2026', 'penguji' => $masterJumat,  'kuota' => (int)($currentMaster['kuota_jumat'] ?? 30)],
];

$activeTab = $_GET['tab'] ?? 'penugasan';

include __DIR__ . '/../includes/header.php';
?>

<style>
/* Styling Elegan & Modern LPPAI Pengaturan Tutor */
.tutor-hero-card {
    background: linear-gradient(135deg, #1e3a8a 0%, #0284c7 100%);
    color: #fff;
    border-radius: 16px;
    padding: 24px 28px;
    margin-bottom: 24px;
    box-shadow: 0 10px 25px -5px rgba(2, 132, 199, 0.3);
    position: relative;
    overflow: hidden;
}
.tutor-hero-card::after {
    content: "🧑‍🏫";
    position: absolute;
    right: 20px;
    bottom: -15px;
    font-size: 110px;
    opacity: 0.12;
    pointer-events: none;
}
.tutor-stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(210px, 1fr));
    gap: 16px;
    margin-bottom: 24px;
}
.tutor-stat-item {
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    padding: 18px 20px;
    display: flex;
    align-items: center;
    gap: 16px;
    box-shadow: 0 2px 4px rgba(0,0,0,0.03);
    transition: transform 0.2s, box-shadow 0.2s;
}
.tutor-stat-item:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 12px rgba(0,0,0,0.06);
}
.tutor-stat-icon {
    width: 48px;
    height: 48px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 24px;
    flex-shrink: 0;
}
.tutor-stat-val {
    font-size: 24px;
    font-weight: 700;
    color: #0f172a;
    line-height: 1.1;
}
.tutor-stat-lbl {
    font-size: 12px;
    color: #64748b;
    font-weight: 500;
    margin-top: 4px;
}

/* Tab Navigation */
.custom-tabs {
    display: flex;
    gap: 8px;
    border-bottom: 2px solid #e2e8f0;
    margin-bottom: 20px;
    overflow-x: auto;
    padding-bottom: 2px;
}
.custom-tab-btn {
    padding: 10px 18px;
    border: none;
    background: none;
    font-size: 14px;
    font-weight: 600;
    color: #64748b;
    cursor: pointer;
    border-bottom: 3px solid transparent;
    transition: all 0.2s;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    white-space: nowrap;
    text-decoration: none;
}
.custom-tab-btn:hover {
    color: #0284c7;
    background: rgba(2, 132, 199, 0.04);
    border-radius: 8px 8px 0 0;
}
.custom-tab-btn.active {
    color: #0284c7;
    border-bottom-color: #0284c7;
    background: #fff;
    border-radius: 8px 8px 0 0;
}

/* Inline Tutor Dropdown Interactive */
.select-tutor-inline {
    width: 100%;
    min-width: 190px;
    padding: 7px 10px;
    border: 1.5px solid #cbd5e1;
    border-radius: 8px;
    font-size: 13px;
    font-weight: 500;
    color: #1e293b;
    background-color: #fff;
    cursor: pointer;
    transition: border-color 0.2s, box-shadow 0.2s, background-color 0.3s;
}
.select-tutor-inline:focus {
    border-color: #0284c7;
    outline: none;
    box-shadow: 0 0 0 3px rgba(2, 132, 199, 0.15);
}
.select-tutor-inline.updating {
    opacity: 0.6;
    pointer-events: none;
    background-color: #f1f5f9;
}
.select-tutor-inline.updated-success {
    border-color: #10b981 !important;
    background-color: #ecfdf5 !important;
}

/* Modals */
.custom-modal-backdrop {
    display: none;
    position: fixed;
    inset: 0;
    background: rgba(15, 23, 42, 0.6);
    backdrop-filter: blur(2px);
    z-index: 99999;
    align-items: center;
    justify-content: center;
    padding: 16px;
}
.custom-modal-content {
    background: #fff;
    border-radius: 16px;
    padding: 28px;
    width: 100%;
    max-width: 520px;
    box-shadow: 0 20px 40px rgba(0,0,0,0.25);
    max-height: 90vh;
    overflow-y: auto;
}

/* Toggle Switch */
.switch {
    position: relative;
    display: inline-block;
    width: 38px;
    height: 22px;
}
.switch input { opacity: 0; width: 0; height: 0; }
.slider {
    position: absolute;
    cursor: pointer;
    inset: 0;
    background-color: #cbd5e1;
    transition: .3s;
    border-radius: 22px;
}
.slider:before {
    position: absolute;
    content: "";
    height: 16px;
    width: 16px;
    left: 3px;
    bottom: 3px;
    background-color: white;
    transition: .3s;
    border-radius: 50%;
}
input:checked + .slider { background-color: #10b981; }
input:checked + .slider:before { transform: translateX(16px); }

/* Day Pills */
.day-pill-badge {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 3px 8px;
    border-radius: 6px;
    font-size: 11px;
    font-weight: 600;
}
.day-pill-Senin  { background: #fee2e2; color: #991b1b; }
.day-pill-Selasa { background: #fef3c7; color: #92400e; }
.day-pill-Rabu   { background: #e0e7ff; color: #3730a3; }
.day-pill-Kamis  { background: #dcfce7; color: #166534; }
.day-pill-Jumat  { background: #f3e8ff; color: #6b21a8; }
</style>

<div class="content">
    <?php if ($message): ?>
    <div class="alert alert-<?= $msgType ?>" style="margin-bottom: 20px; border-radius: 10px; display: flex; align-items: center; gap: 10px;">
        <span><?= $msgType === 'success' ? '✅' : ($msgType === 'warning' ? '⚠️' : 'ℹ️') ?></span>
        <div><?= $message ?></div>
    </div>
    <?php endif; ?>

    <!-- HERO HEADER CARD -->
    <div class="tutor-hero-card">
        <div style="display:flex; justify-content:space-between; align-items:flex-start; flex-wrap:wrap; gap:16px;">
            <div>
                <h1 style="font-size:24px; font-weight:800; margin-bottom:6px; color:#fff;">🧑‍🏫 Pengaturan Tutor & Dosen Penguji Pretes</h1>
                <p style="font-size:14px; opacity:0.9; margin:0; max-width:650px;">
                    Pusat kendali pengaturan tutor/penguji LPPAI. Kelola akun tutor, plotting peserta, tukar tutor massal, pembagian otomatis, serta jadwal penguji harian.
                </p>
            </div>
            <!-- Period Selector Switcher -->
            <form method="GET" style="display:flex; align-items:center; gap:8px; background:rgba(255,255,255,0.15); padding:6px 12px; border-radius:10px; backdrop-filter:blur(4px);">
                <label style="font-size:12px; font-weight:600; color:#fff; white-space:nowrap;">Periode:</label>
                <select name="periode" onchange="this.form.submit()" style="padding:6px 10px; border-radius:6px; border:none; font-size:13px; font-weight:600; color:#0f172a; background:#fff; cursor:pointer;">
                    <?php foreach ($periodsList as $p): ?>
                    <option value="<?= sanitize($p['periode']) ?>" <?= $p['periode'] === $periodeName ? 'selected' : '' ?>>
                        <?= sanitize($p['periode']) ?> <?= $p['status'] === 'aktif' ? '(Aktif)' : '' ?>
                    </option>
                    <?php endforeach; ?>
                </select>
                <input type="hidden" name="tab" value="<?= htmlspecialchars($activeTab, ENT_QUOTES, 'UTF-8') ?>">
            </form>
        </div>
    </div>

    <!-- STATS COUNTER -->
    <div class="tutor-stats-grid">
        <div class="tutor-stat-item">
            <div class="tutor-stat-icon" style="background:#e0f2fe; color:#0284c7;">👨‍🏫</div>
            <div>
                <div class="tutor-stat-val"><?= count($activeTutors) ?> <span style="font-size:14px; color:#94a3b8; font-weight:400;">/ <?= count($tutors) ?></span></div>
                <div class="tutor-stat-lbl">Tutor Aktif / Terdaftar</div>
            </div>
        </div>
        <div class="tutor-stat-item">
            <div class="tutor-stat-icon" style="background:#f1f5f9; color:#475569;">👥</div>
            <div>
                <div class="tutor-stat-val"><?= $totalParticipants ?></div>
                <div class="tutor-stat-lbl">Total Mahasiswa Terdaftar</div>
            </div>
        </div>
        <div class="tutor-stat-item">
            <div class="tutor-stat-icon" style="background:#dcfce7; color:#16a34a;">✅</div>
            <div>
                <div class="tutor-stat-val"><?= $totalWithTutor ?> <span style="font-size:13px; color:#16a34a; font-weight:500;">(<?= $totalParticipants > 0 ? round(($totalWithTutor/$totalParticipants)*100) : 0 ?>%)</span></div>
                <div class="tutor-stat-lbl">Sudah Ditugaskan Tutor</div>
            </div>
        </div>
        <div class="tutor-stat-item">
            <div class="tutor-stat-icon" style="background:#fee2e2; color:#dc2626;">⏳</div>
            <div>
                <div class="tutor-stat-val"><?= $totalUnassigned ?></div>
                <div class="tutor-stat-lbl">Belum Memiliki Tutor</div>
            </div>
        </div>
    </div>

    <!-- TAB NAVIGATION -->
    <div class="custom-tabs">
        <a href="?periode=<?= urlencode($periodeName) ?>&tab=penugasan" class="custom-tab-btn <?= $activeTab === 'penugasan' ? 'active' : '' ?>">
            <span>📋</span> Penugasan Peserta per Tutor
        </a>
        <a href="?periode=<?= urlencode($periodeName) ?>&tab=master" class="custom-tab-btn <?= $activeTab === 'master' ? 'active' : '' ?>">
            <span>🧑‍🏫</span> Manajemen Data Tutor (Dosen)
        </a>
        <a href="?periode=<?= urlencode($periodeName) ?>&tab=jadwal" class="custom-tab-btn <?= $activeTab === 'jadwal' ? 'active' : '' ?>">
            <span>📅</span> Plotting Jadwal Harian (Master Pretes)
        </a>
        <a href="?periode=<?= urlencode($periodeName) ?>&tab=rekap" class="custom-tab-btn <?= $activeTab === 'rekap' ? 'active' : '' ?>">
            <span>📊</span> Rekap Beban & Progress Penilaian
        </a>
    </div>

    <!-- =======================================================================
         TAB 1: PENUGASAN PESERTA PER TUTOR (LIVE DROPDOWN AJAX + BULK TOOLS)
         ======================================================================= -->
    <?php if ($activeTab === 'penugasan'): ?>
    
    <!-- TOOLS PANEL: BULK REASSIGN & AUTO DISTRIBUTE -->
    <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(320px, 1fr)); gap:18px; margin-bottom:20px;">
        
        <!-- CARD 1: TUKAR / PINDAHKAN TUTOR MASSAL -->
        <div class="card" style="margin:0; border-radius:12px; border:1px solid #e2e8f0; box-shadow:0 1px 3px rgba(0,0,0,0.05);">
            <div style="font-weight:700; font-size:15px; color:#1e293b; margin-bottom:12px; display:flex; align-items:center; gap:8px;">
                <span>🔄</span> Tukar / Pindahkan Tutor Massal
            </div>
            <form method="POST" data-no-spa onsubmit="return confirm('Pindahkan mahasiswa sesuai filter ini ke tutor tujuan?');">
                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                <input type="hidden" name="action" value="bulk_reassign_tutor">
                <input type="hidden" name="periode" value="<?= htmlspecialchars($periodeName, ENT_QUOTES, 'UTF-8') ?>">
                
                <div style="display:grid; grid-template-columns:1fr 1fr; gap:10px; margin-bottom:10px;">
                    <div>
                        <label style="font-size:11px; font-weight:600; color:#64748b; display:block; margin-bottom:4px;">TUTOR SUMBER</label>
                        <select name="from_tutor" style="width:100%; padding:7px 9px; border-radius:6px; border:1px solid #cbd5e1; font-size:12px; background:#fff;">
                            <option value="">-- Semua Tutor --</option>
                            <option value="__EMPTY__">⚠️ [Khusus Mahasiswa Belum Ada Tutor]</option>
                            <?php foreach ($tutors as $t): ?>
                            <option value="<?= sanitize($t['nama_lengkap']) ?>"><?= sanitize($t['nama_lengkap']) ?> (<?= $t['total_peserta'] ?> mhs)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label style="font-size:11px; font-weight:600; color:#64748b; display:block; margin-bottom:4px;">FILTER HARI</label>
                        <select name="filter_hari" style="width:100%; padding:7px 9px; border-radius:6px; border:1px solid #cbd5e1; font-size:12px; background:#fff;">
                            <option value="Semua">Semua Hari</option>
                            <option value="Senin">Senin (2 Nov)</option>
                            <option value="Selasa">Selasa (3 Nov)</option>
                            <option value="Rabu">Rabu (4 Nov)</option>
                            <option value="Kamis">Kamis (5 Nov)</option>
                            <option value="Jumat">Jumat (6 Nov)</option>
                        </select>
                    </div>
                </div>

                <div style="margin-bottom:12px;">
                    <label style="font-size:11px; font-weight:600; color:#64748b; display:block; margin-bottom:4px;">PINDAHKAN KE TUTOR TUJUAN</label>
                    <select name="to_tutor" required style="width:100%; padding:7px 9px; border-radius:6px; border:1.5px solid #0284c7; font-size:12px; background:#fff; font-weight:600;">
                        <option value="">-- Pilih Tutor Pengganti --</option>
                        <option value="__EMPTY__">❌ Kosongkan Penugasan (Jadikan Belum Ada Penguji)</option>
                        <?php foreach ($activeTutors as $t): ?>
                        <option value="<?= sanitize($t['nama_lengkap']) ?>"><?= sanitize($t['nama_lengkap']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <button type="submit" class="btn btn-primary" style="width:100%; padding:8px 12px; font-size:13px; font-weight:600;">
                    🚀 Terapkan Pemindahan Massal
                </button>
            </form>
        </div>

        <!-- CARD 2: DISTRIBUSI / BAGI RATA OTOMATIS -->
        <div class="card" style="margin:0; border-radius:12px; border:1px solid #e2e8f0; box-shadow:0 1px 3px rgba(0,0,0,0.05);">
            <div style="font-weight:700; font-size:15px; color:#1e293b; margin-bottom:12px; display:flex; align-items:center; gap:8px;">
                <span>⚖️</span> Bagi Rata Mahasiswa Kosong (Auto Distribute)
            </div>
            <form method="POST" data-no-spa onsubmit="return confirm('Bagi rata mahasiswa yang belum memiliki tutor kepada tutor-tutor terpilih?');">
                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                <input type="hidden" name="action" value="auto_distribute_tutors">
                <input type="hidden" name="periode" value="<?= htmlspecialchars($periodeName, ENT_QUOTES, 'UTF-8') ?>">

                <div style="margin-bottom:10px;">
                    <label style="font-size:11px; font-weight:600; color:#64748b; display:block; margin-bottom:4px;">HARI TARGET</label>
                    <select name="distribute_hari" style="width:100%; padding:7px 9px; border-radius:6px; border:1px solid #cbd5e1; font-size:12px; background:#fff;">
                        <option value="Semua">Semua Hari (Total <?= $totalUnassigned ?> mhs kosong)</option>
                        <option value="Senin">Senin (2 Nov)</option>
                        <option value="Selasa">Selasa (3 Nov)</option>
                        <option value="Rabu">Rabu (4 Nov)</option>
                        <option value="Kamis">Kamis (5 Nov)</option>
                        <option value="Jumat">Jumat (6 Nov)</option>
                    </select>
                </div>

                <div style="margin-bottom:12px;">
                    <label style="font-size:11px; font-weight:600; color:#64748b; display:block; margin-bottom:4px;">
                        PILIH TUTOR YANG MENERIMA PEMBAGIAN (Centang):
                    </label>
                    <div style="max-height:95px; overflow-y:auto; border:1px solid #cbd5e1; border-radius:6px; padding:6px 10px; background:#f8fafc;">
                        <?php foreach ($activeTutors as $t): ?>
                        <label style="display:flex; align-items:center; gap:8px; font-size:12px; color:#334155; margin-bottom:4px; cursor:pointer;">
                            <input type="checkbox" name="distribute_tutors[]" value="<?= sanitize($t['nama_lengkap']) ?>" checked>
                            <span><?= sanitize($t['nama_lengkap']) ?></span>
                        </label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <button type="submit" class="btn btn-primary" style="width:100%; padding:8px 12px; font-size:13px; font-weight:600; background:#059669; border-color:#059669;">
                    ⚡ Bagi Rata Otomatis Sekarang
                </button>
            </form>
        </div>

    </div>

    <!-- FILTER BUTTONS FOR TABLE -->
    <div class="card" style="margin-bottom:14px; border-radius:12px; padding:14px 18px;">
        <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px;">
            <div style="display:flex; align-items:center; gap:8px; flex-wrap:wrap;">
                <span style="font-size:12px; font-weight:700; color:#475569;">Filter Hari:</span>
                <button type="button" class="btn-filter-hari btn btn-sm" data-day="" style="background:#0284c7; color:#fff; border-radius:20px; font-size:12px; padding:4px 12px;">Semua</button>
                <button type="button" class="btn-filter-hari btn btn-sm btn-secondary" data-day="Senin" style="border-radius:20px; font-size:12px; padding:4px 12px;">Senin (2 Nov)</button>
                <button type="button" class="btn-filter-hari btn btn-sm btn-secondary" data-day="Selasa" style="border-radius:20px; font-size:12px; padding:4px 12px;">Selasa (3 Nov)</button>
                <button type="button" class="btn-filter-hari btn btn-sm btn-secondary" data-day="Rabu" style="border-radius:20px; font-size:12px; padding:4px 12px;">Rabu (4 Nov)</button>
                <button type="button" class="btn-filter-hari btn btn-sm btn-secondary" data-day="Kamis" style="border-radius:20px; font-size:12px; padding:4px 12px;">Kamis (5 Nov)</button>
                <button type="button" class="btn-filter-hari btn btn-sm btn-secondary" data-day="Jumat" style="border-radius:20px; font-size:12px; padding:4px 12px;">Jumat (6 Nov)</button>
            </div>
            <div style="font-size:12px; color:#64748b;">
                💡 <em>Ubah tutor langsung pada kolom dropdown di tabel untuk update instan tanpa reload!</em>
            </div>
        </div>
    </div>

    <!-- TABEL DATA PESERTA PRETES DENGAN INLINE DROPDOWN TUTOR -->
    <div class="card" style="border-radius:12px; padding:20px; overflow-x:auto;">
        <table id="table-penugasan" class="table" style="width:100%; border-collapse:collapse; font-size:13px;">
            <thead>
                <tr style="background:#f8fafc; border-bottom:2px solid #e2e8f0;">
                    <th style="width:40px; text-align:center;">No</th>
                    <th style="width:100px;">NIM</th>
                    <th>Nama Mahasiswa</th>
                    <th>Program Studi</th>
                    <th style="width:120px;">Hari & Jam</th>
                    <th style="width:110px;">Ruangan</th>
                    <th style="min-width:220px; background:#eff6ff;">🧑‍🏫 Dosen Penguji (Bisa Diedit Bebas)</th>
                    <th style="width:100px; text-align:center;">Penilaian</th>
                    <th style="width:60px; text-align:center;">Aksi</th>
                </tr>
            </thead>
            <tbody>
                <?php $no = 1; foreach ($participants as $p): 
                    $currentTutor = trim($p['dosen_penguji'] ?? '');
                    $dayPill = $p['hari_pilihan'] ? 'day-pill-' . $p['hari_pilihan'] : '';
                ?>
                <tr id="row-reg-<?= $p['reg_id'] ?>" style="border-bottom:1px solid #f1f5f9;">
                    <td style="text-align:center; color:#94a3b8; font-weight:600;"><?= $no++ ?></td>
                    <td style="font-weight:600; color:#0f172a;"><?= sanitize($p['nim']) ?></td>
                    <td>
                        <strong style="color:#1e293b;"><?= sanitize($p['nama_lengkap']) ?></strong>
                        <?php if (!empty($p['no_hp'])): ?>
                        <br><a href="https://wa.me/<?= formatNoHp($p['no_hp']) ?>" target="_blank" style="font-size:11px; color:#10b981; text-decoration:none;">📲 WhatsApp</a>
                        <?php endif; ?>
                    </td>
                    <td style="color:#475569; font-size:12px;"><?= sanitize($p['program_studi'] ?: '-') ?></td>
                    <td>
                        <?php if ($p['hari_pilihan']): ?>
                        <span class="day-pill-badge <?= $dayPill ?>"><?= sanitize($p['hari_pilihan']) ?></span>
                        <div style="font-size:11px; color:#64748b; margin-top:2px;"><?= sanitize($p['jam'] ?: '13.00 WIB') ?></div>
                        <?php else: ?>
                        <span style="color:#94a3b8; font-size:12px;">-</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <span style="font-size:12px; font-weight:500; color:#334155;"><?= sanitize($p['ruangan'] ?: 'Belum diplot') ?></span>
                    </td>
                    
                    <!-- KOLOM DOSEN PENGUJI LIVE DROPDOWN AJAX -->
                    <td style="background:#fafafa;" data-sort="<?= htmlspecialchars($currentTutor, ENT_QUOTES, 'UTF-8') ?>" data-filter="<?= htmlspecialchars($currentTutor, ENT_QUOTES, 'UTF-8') ?>">
                        <select class="select-tutor-inline" 
                                data-reg-id="<?= $p['reg_id'] ?>" 
                                data-orig-val="<?= htmlspecialchars($currentTutor, ENT_QUOTES, 'UTF-8') ?>"
                                title="Klik untuk mengganti Dosen Penguji">
                            <option value="" style="color:#dc2626;">-- ⚠️ Belum Ditugaskan / Kosong --</option>
                            <?php 
                            $matchedInOptions = false;
                            foreach ($activeTutors as $t): 
                                $isMatch = isPengujiMatch($currentTutor, $t['nama_lengkap']);
                                if ($isMatch) $matchedInOptions = true;
                            ?>
                            <option value="<?= sanitize($t['nama_lengkap']) ?>" <?= $isMatch ? 'selected' : '' ?>>
                                <?= sanitize($t['nama_lengkap']) ?>
                            </option>
                            <?php endforeach; ?>
                            
                            <?php if (!empty($currentTutor) && !$matchedInOptions): ?>
                            <!-- Pertahankan penguji lama jika tidak cocok di daftar tutor aktif -->
                            <option value="<?= sanitize($currentTutor) ?>" selected>
                                📌 <?= sanitize($currentTutor) ?> (Tersimpan)
                            </option>
                            <?php endif; ?>
                        </select>
                    </td>

                    <td style="text-align:center;">
                        <?php if ((int)$p['is_assessed'] === 1): ?>
                        <span class="badge" style="background:#dcfce7; color:#15803d; font-size:11px; padding:3px 8px; border-radius:6px; font-weight:600;">Sudah Dinilai</span>
                        <?php else: ?>
                        <span class="badge" style="background:#f1f5f9; color:#64748b; font-size:11px; padding:3px 8px; border-radius:6px;">Belum Dinilai</span>
                        <?php endif; ?>
                    </td>

                    <td style="text-align:center;">
                        <button type="button" class="btn btn-sm btn-outline-danger btn-reset-tutor" 
                                data-reg-id="<?= $p['reg_id'] ?>" 
                                title="Kosongkan Tutor" 
                                style="padding:3px 7px; font-size:12px; border-radius:6px; border:1px solid #fca5a5; color:#dc2626; background:#fff; cursor:pointer;">
                            ✕
                        </button>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- =======================================================================
         TAB 2: MANAJEMEN DATA MASTER TUTOR (ROLE DOSEN)
         ======================================================================= -->
    <?php elseif ($activeTab === 'master'): ?>
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px; flex-wrap:wrap; gap:12px;">
        <div>
            <h3 style="font-size:18px; font-weight:700; color:#1e293b; margin:0;">Daftar Akun Tutor & Dosen Penguji</h3>
            <p style="font-size:13px; color:#64748b; margin:2px 0 0 0;">Pengguna dengan role <strong>'dosen'</strong> yang berhak melakukan pengujian dan penilaian pretes.</p>
        </div>
        <button type="button" class="btn btn-primary" onclick="openAddTutorModal()" style="display:flex; align-items:center; gap:6px; border-radius:8px;">
            <span>➕</span> Tambah Tutor Baru
        </button>
    </div>

    <div class="card" style="border-radius:12px; padding:20px; overflow-x:auto;">
        <table id="table-master-tutors" class="table" style="width:100%; border-collapse:collapse; font-size:13px;">
            <thead>
                <tr style="background:#f8fafc; border-bottom:2px solid #e2e8f0;">
                    <th style="width:40px; text-align:center;">No</th>
                    <th style="width:120px;">NIDN / NIP</th>
                    <th>Nama Lengkap & Gelar</th>
                    <th>Username</th>
                    <th>WhatsApp / No HP</th>
                    <th>Email</th>
                    <th style="width:80px; text-align:center;">Status</th>
                    <th style="width:120px; text-align:center;">Beban Mahasiswa</th>
                    <th style="width:130px; text-align:center;">Aksi</th>
                </tr>
            </thead>
            <tbody>
                <?php $tNo = 1; foreach ($tutors as $tut): ?>
                <tr style="border-bottom:1px solid #f1f5f9;">
                    <td style="text-align:center; color:#94a3b8;"><?= $tNo++ ?></td>
                    <td style="font-weight:600; color:#475569;"><?= sanitize($tut['nim'] ?: '-') ?></td>
                    <td>
                        <strong style="color:#0f172a; font-size:14px;"><?= sanitize($tut['nama_lengkap']) ?></strong>
                    </td>
                    <td style="color:#64748b; font-family:monospace;"><?= sanitize($tut['username']) ?></td>
                    <td>
                        <?php if (!empty($tut['no_hp'])): ?>
                        <a href="https://wa.me/<?= formatNoHp($tut['no_hp']) ?>" target="_blank" style="color:#059669; font-weight:600; text-decoration:none; display:inline-flex; align-items:center; gap:4px;">
                            <span>💬</span> <?= sanitize($tut['no_hp']) ?>
                        </a>
                        <?php else: ?>
                        <span style="color:#94a3b8;">-</span>
                        <?php endif; ?>
                    </td>
                    <td style="color:#64748b;"><?= sanitize($tut['email'] ?: '-') ?></td>
                    <td style="text-align:center;">
                        <label class="switch" title="Klik untuk mengaktifkan / menonaktifkan tutor">
                            <input type="checkbox" class="tutor-status-toggle" data-tutor-id="<?= $tut['id'] ?>" <?= $tut['is_active'] ? 'checked' : '' ?>>
                            <span class="slider"></span>
                        </label>
                    </td>
                    <td style="text-align:center;">
                        <span class="badge" style="background:#e0f2fe; color:#0369a1; font-weight:700; padding:4px 10px; border-radius:12px; font-size:12px;">
                            <?= $tut['total_peserta'] ?> mhs
                        </span>
                    </td>
                    <td style="text-align:center;">
                        <div style="display:inline-flex; gap:6px;">
                            <button type="button" class="btn btn-sm btn-secondary" 
                                    onclick="openEditTutorModal(<?= htmlspecialchars(json_encode($tut), ENT_QUOTES, 'UTF-8') ?>)"
                                    title="Edit Profil & Data Tutor" style="padding:4px 8px; font-size:12px;">
                                ✏️ Edit
                            </button>
                            <button type="button" class="btn btn-sm btn-secondary" 
                                    onclick="openResetPassModal(<?= $tut['id'] ?>, '<?= htmlspecialchars($tut['nama_lengkap'], ENT_QUOTES, 'UTF-8') ?>')"
                                    title="Reset Password Tutor" style="padding:4px 8px; font-size:12px;">
                                🔑
                            </button>
                            <form method="POST" data-no-spa style="display:inline;" onsubmit="return confirm('Hapus/nonaktifkan tutor <?= htmlspecialchars($tut['nama_lengkap'], ENT_QUOTES, 'UTF-8') ?>?');">
                                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                                <input type="hidden" name="action" value="delete_tutor">
                                <input type="hidden" name="id" value="<?= $tut['id'] ?>">
                                <button type="submit" class="btn btn-sm btn-outline-danger" style="padding:4px 8px; font-size:12px;" title="Hapus Tutor">
                                    🗑️
                                </button>
                            </form>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- =======================================================================
         TAB 3: PLOTTING JADWAL HARIAN TUTOR PRETES (MASTER_PRETES)
         ======================================================================= -->
    <?php elseif ($activeTab === 'jadwal'): ?>
    <div class="card" style="border-radius:12px; padding:24px; margin-bottom:24px;">
        <div style="margin-bottom:20px;">
            <h3 style="font-size:18px; font-weight:700; color:#1e293b; margin:0 0 4px 0;">📅 Plotting Tutor Harian & Kuota Pretes</h3>
            <p style="font-size:13px; color:#64748b; margin:0;">
                Tentukan tutor-tutor penguji yang bertugas untuk setiap hari pelaksanaan pretes (Senin 2 Nov s/d Jumat 6 Nov 2026).
            </p>
        </div>

        <form method="POST" data-no-spa>
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
            <input type="hidden" name="action" value="update_master_jadwal_tutor">
            <input type="hidden" name="master_id" value="<?= $currentMaster['id'] ?>">

            <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(280px, 1fr)); gap:18px; margin-bottom:24px;">
                <?php foreach ($daysInfo as $dayName => $info): 
                    $colField = 'penguji_' . strtolower($dayName);
                    $assignedList = $info['penguji'];
                ?>
                <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:12px; padding:16px;">
                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px; padding-bottom:8px; border-bottom:1px solid #e2e8f0;">
                        <div>
                            <span class="day-pill-badge day-pill-<?= $dayName ?>" style="font-size:13px; padding:4px 10px;">
                                <?= $dayName ?>
                            </span>
                            <span style="font-size:12px; color:#64748b; margin-left:4px;"><?= $info['date'] ?></span>
                        </div>
                        <div style="font-size:12px; color:#475569;">
                            Kuota: 
                            <input type="number" name="kuota_<?= strtolower($dayName) ?>" value="<?= $info['kuota'] ?>" min="0" max="500" style="width:60px; padding:3px 6px; border:1px solid #cbd5e1; border-radius:4px; font-weight:600; text-align:center;">
                        </div>
                    </div>

                    <div style="font-size:11px; font-weight:700; color:#64748b; margin-bottom:8px; text-transform:uppercase;">
                        Pilih Tutor Penguji Yang Bertugas:
                    </div>

                    <div style="max-height:180px; overflow-y:auto; background:#fff; border:1px solid #cbd5e1; border-radius:8px; padding:8px 12px;">
                        <?php foreach ($tutors as $t): 
                            $isChecked = false;
                            foreach ($assignedList as $assignedName) {
                                if (isPengujiMatch($assignedName, $t['nama_lengkap'])) {
                                    $isChecked = true;
                                    break;
                                }
                            }
                        ?>
                        <label style="display:flex; align-items:center; gap:8px; font-size:12px; color:#334155; margin-bottom:6px; cursor:pointer;">
                            <input type="checkbox" name="<?= $colField ?>[]" value="<?= sanitize($t['nama_lengkap']) ?>" <?= $isChecked ? 'checked' : '' ?>>
                            <span><?= sanitize($t['nama_lengkap']) ?></span>
                            <?php if (!$t['is_active']): ?>
                            <span style="font-size:10px; color:#ef4444;">(nonaktif)</span>
                            <?php endif; ?>
                        </label>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>

            <div style="display:flex; justify-content:flex-end; gap:12px;">
                <button type="submit" class="btn btn-primary" style="padding:10px 24px; font-size:14px; font-weight:700; border-radius:8px;">
                    💾 Simpan Jadwal & Plotting Tutor Harian
                </button>
            </div>
        </form>
    </div>

    <!-- =======================================================================
         TAB 4: REKAP BEBAN & PROGRESS PENILAIAN TUTOR
         ======================================================================= -->
    <?php elseif ($activeTab === 'rekap'): ?>
    <div style="margin-bottom:16px;">
        <h3 style="font-size:18px; font-weight:700; color:#1e293b; margin:0 0 4px 0;">📊 Rekap Beban & Progress Penilaian Tutor</h3>
        <p style="font-size:13px; color:#64748b; margin:0;">
            Pantau jumlah mahasiswa yang ditugaskan kepada setiap tutor, progress penilaian yang telah dilakukan, serta kirim pengingat WhatsApp.
        </p>
    </div>

    <div class="card" style="border-radius:12px; padding:20px; overflow-x:auto;">
        <table id="table-rekap-tutor" class="table" style="width:100%; border-collapse:collapse; font-size:13px;">
            <thead>
                <tr style="background:#f8fafc; border-bottom:2px solid #e2e8f0;">
                    <th style="width:40px; text-align:center;">No</th>
                    <th>Dosen Penguji</th>
                    <th style="width:140px;">Kontak WhatsApp</th>
                    <th style="width:110px; text-align:center;">Total Diuji</th>
                    <th style="width:110px; text-align:center;">Sudah Dinilai</th>
                    <th style="width:110px; text-align:center;">Belum Dinilai</th>
                    <th style="min-width:180px;">Progress Penilaian</th>
                    <th style="width:160px; text-align:center;">Aksi Pengingat</th>
                </tr>
            </thead>
            <tbody>
                <?php 
                $rNo = 1; 
                foreach ($tutors as $tut): 
                    $total = (int)$tut['total_peserta'];
                    $done  = (int)$tut['sudah_dinilai'];
                    $undone = (int)$tut['belum_dinilai'];
                    $pct   = $total > 0 ? round(($done / $total) * 100) : 0;
                    $waClean = formatNoHp($tut['no_hp']);
                    $waText = urlencode("Assalamualaikum Wr. Wb. Bapak/Ibu " . $tut['nama_lengkap'] . ", mengingatkan untuk penginputan nilai pretes LPPAI UNISDA. Saat ini terdapat $undone mahasiswa yang belum dinilai dari total $total mahasiswa. Terima kasih.");
                ?>
                <tr style="border-bottom:1px solid #f1f5f9;">
                    <td style="text-align:center; color:#94a3b8;"><?= $rNo++ ?></td>
                    <td>
                        <strong style="color:#0f172a; font-size:14px;"><?= sanitize($tut['nama_lengkap']) ?></strong>
                        <div style="font-size:11px; color:#64748b;">NIDN: <?= sanitize($tut['nim'] ?: '-') ?></div>
                    </td>
                    <td>
                        <?php if ($waClean): ?>
                        <a href="https://wa.me/<?= $waClean ?>" target="_blank" style="color:#059669; font-weight:600; text-decoration:none; display:inline-flex; align-items:center; gap:4px;">
                            <span>💬</span> <?= sanitize($tut['no_hp']) ?>
                        </a>
                        <?php else: ?>
                        <span style="color:#94a3b8;">-</span>
                        <?php endif; ?>
                    </td>
                    <td style="text-align:center; font-weight:700; font-size:14px; color:#0f172a;"><?= $total ?></td>
                    <td style="text-align:center; font-weight:700; color:#16a34a;"><?= $done ?></td>
                    <td style="text-align:center; font-weight:700; color:#dc2626;"><?= $undone ?></td>
                    <td>
                        <div style="display:flex; align-items:center; gap:8px;">
                            <div style="flex:1; height:8px; background:#e2e8f0; border-radius:4px; overflow:hidden;">
                                <div style="width:<?= $pct ?>%; height:100%; background:<?= $pct === 100 ? '#10b981' : ($pct > 50 ? '#0284c7' : '#f59e0b') ?>; border-radius:4px;"></div>
                            </div>
                            <span style="font-size:12px; font-weight:700; color:#475569; width:38px; text-align:right;"><?= $pct ?>%</span>
                        </div>
                    </td>
                    <td style="text-align:center;">
                        <?php if ($undone > 0 && $waClean): ?>
                        <a href="https://wa.me/<?= $waClean ?>?text=<?= $waText ?>" target="_blank" class="btn btn-sm" style="background:#25d366; color:#fff; font-weight:600; border-radius:6px; font-size:11px; padding:4px 10px; text-decoration:none; display:inline-flex; align-items:center; gap:4px;">
                            <span>📲</span> Ingatkan WA
                        </a>
                        <?php elseif ($total === 0): ?>
                        <span style="font-size:11px; color:#94a3b8;">Tidak ada mhs</span>
                        <?php else: ?>
                        <span style="font-size:11px; color:#10b981; font-weight:600;">✅ Tuntas</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>

</div><!-- end content -->

<!-- ===========================================================================
     MODAL TAMBAH TUTOR BARU
     =========================================================================== -->
<div id="modal-add-tutor" class="custom-modal-backdrop">
    <div class="custom-modal-content">
        <h3 style="margin:0 0 16px 0; font-size:18px; color:#1e293b;">➕ Tambah Tutor / Dosen Penguji Baru</h3>
        <form method="POST" data-no-spa>
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
            <input type="hidden" name="action" value="create_tutor">

            <div style="margin-bottom:12px;">
                <label style="font-size:12px; font-weight:600; color:#334155; display:block; margin-bottom:4px;">Nama Lengkap & Gelar *</label>
                <input type="text" name="nama_lengkap" required placeholder="Contoh: Dr. H. Ahmad Fauzi, M.Pd.I" style="width:100%; padding:8px 12px; border:1.5px solid #cbd5e1; border-radius:8px; font-size:13px;">
            </div>

            <div style="display:grid; grid-template-columns:1fr 1fr; gap:10px; margin-bottom:12px;">
                <div>
                    <label style="font-size:12px; font-weight:600; color:#334155; display:block; margin-bottom:4px;">NIDN / NIP</label>
                    <input type="text" name="nim" placeholder="NIDN atau NIP" style="width:100%; padding:8px 12px; border:1.5px solid #cbd5e1; border-radius:8px; font-size:13px;">
                </div>
                <div>
                    <label style="font-size:12px; font-weight:600; color:#334155; display:block; margin-bottom:4px;">Username Login *</label>
                    <input type="text" name="username" required placeholder="Username" style="width:100%; padding:8px 12px; border:1.5px solid #cbd5e1; border-radius:8px; font-size:13px;">
                </div>
            </div>

            <div style="display:grid; grid-template-columns:1fr 1fr; gap:10px; margin-bottom:12px;">
                <div>
                    <label style="font-size:12px; font-weight:600; color:#334155; display:block; margin-bottom:4px;">WhatsApp / No HP</label>
                    <input type="text" name="no_hp" placeholder="08123456789" style="width:100%; padding:8px 12px; border:1.5px solid #cbd5e1; border-radius:8px; font-size:13px;">
                </div>
                <div>
                    <label style="font-size:12px; font-weight:600; color:#334155; display:block; margin-bottom:4px;">Email</label>
                    <input type="email" name="email" placeholder="email@unisda.ac.id" style="width:100%; padding:8px 12px; border:1.5px solid #cbd5e1; border-radius:8px; font-size:13px;">
                </div>
            </div>

            <div style="margin-bottom:16px;">
                <label style="font-size:12px; font-weight:600; color:#334155; display:block; margin-bottom:4px;">Password Awal</label>
                <input type="text" name="password" value="123456" style="width:100%; padding:8px 12px; border:1.5px solid #cbd5e1; border-radius:8px; font-size:13px;">
                <small style="color:#64748b; font-size:11px;">Default: 123456 (dapat diubah nanti oleh tutor).</small>
            </div>

            <div style="margin-bottom:20px;">
                <label style="display:flex; align-items:center; gap:8px; font-size:13px; color:#334155; cursor:pointer;">
                    <input type="checkbox" name="is_active" value="1" checked>
                    <span>Status Tutor Langsung Aktif</span>
                </label>
            </div>

            <div style="display:flex; justify-content:flex-end; gap:10px;">
                <button type="button" class="btn btn-secondary" onclick="closeModal('modal-add-tutor')">Batal</button>
                <button type="submit" class="btn btn-primary">💾 Simpan Tutor</button>
            </div>
        </form>
    </div>
</div>

<!-- ===========================================================================
     MODAL EDIT DATA TUTOR
     =========================================================================== -->
<div id="modal-edit-tutor" class="custom-modal-backdrop">
    <div class="custom-modal-content">
        <h3 style="margin:0 0 16px 0; font-size:18px; color:#1e293b;">✏️ Edit Data Tutor / Dosen Penguji</h3>
        <form method="POST" data-no-spa>
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
            <input type="hidden" name="action" value="update_tutor">
            <input type="hidden" name="id" id="edit-tutor-id">

            <div style="margin-bottom:12px;">
                <label style="font-size:12px; font-weight:600; color:#334155; display:block; margin-bottom:4px;">Nama Lengkap & Gelar *</label>
                <input type="text" name="nama_lengkap" id="edit-tutor-nama" required style="width:100%; padding:8px 12px; border:1.5px solid #cbd5e1; border-radius:8px; font-size:13px;">
            </div>

            <div style="display:grid; grid-template-columns:1fr 1fr; gap:10px; margin-bottom:12px;">
                <div>
                    <label style="font-size:12px; font-weight:600; color:#334155; display:block; margin-bottom:4px;">NIDN / NIP</label>
                    <input type="text" name="nim" id="edit-tutor-nim" style="width:100%; padding:8px 12px; border:1.5px solid #cbd5e1; border-radius:8px; font-size:13px;">
                </div>
                <div>
                    <label style="font-size:12px; font-weight:600; color:#334155; display:block; margin-bottom:4px;">Username Login *</label>
                    <input type="text" name="username" id="edit-tutor-username" required style="width:100%; padding:8px 12px; border:1.5px solid #cbd5e1; border-radius:8px; font-size:13px;">
                </div>
            </div>

            <div style="display:grid; grid-template-columns:1fr 1fr; gap:10px; margin-bottom:12px;">
                <div>
                    <label style="font-size:12px; font-weight:600; color:#334155; display:block; margin-bottom:4px;">WhatsApp / No HP</label>
                    <input type="text" name="no_hp" id="edit-tutor-nohp" style="width:100%; padding:8px 12px; border:1.5px solid #cbd5e1; border-radius:8px; font-size:13px;">
                </div>
                <div>
                    <label style="font-size:12px; font-weight:600; color:#334155; display:block; margin-bottom:4px;">Email</label>
                    <input type="email" name="email" id="edit-tutor-email" style="width:100%; padding:8px 12px; border:1.5px solid #cbd5e1; border-radius:8px; font-size:13px;">
                </div>
            </div>

            <div style="margin-bottom:14px;">
                <label style="display:flex; align-items:center; gap:8px; font-size:13px; color:#334155; cursor:pointer;">
                    <input type="checkbox" name="is_active" id="edit-tutor-active" value="1">
                    <span>Status Tutor Aktif</span>
                </label>
            </div>

            <!-- SMART AUTO-SYNC CASCADE OPTION -->
            <div style="background:#f0fdf4; border:1px solid #bbf7d0; border-radius:8px; padding:10px 12px; margin-bottom:20px;">
                <label style="display:flex; align-items:flex-start; gap:8px; font-size:12px; color:#166534; cursor:pointer;">
                    <input type="checkbox" name="sync_cascade" value="1" checked style="margin-top:2px;">
                    <span>
                        <strong>Sinkronkan Perubahan Nama:</strong> Otomatis perbarui nama penguji pada seluruh data mahasiswa pretes dan jadwal master yang sudah ada jika nama lengkap diubah.
                    </span>
                </label>
            </div>

            <div style="display:flex; justify-content:flex-end; gap:10px;">
                <button type="button" class="btn btn-secondary" onclick="closeModal('modal-edit-tutor')">Batal</button>
                <button type="submit" class="btn btn-primary">💾 Simpan Perubahan</button>
            </div>
        </form>
    </div>
</div>

<!-- ===========================================================================
     MODAL RESET PASSWORD TUTOR
     =========================================================================== -->
<div id="modal-reset-pass" class="custom-modal-backdrop">
    <div class="custom-modal-content" style="max-width:400px;">
        <h3 style="margin:0 0 10px 0; font-size:18px; color:#1e293b;">🔑 Reset Password Tutor</h3>
        <p id="reset-pass-tutor-name" style="font-size:13px; color:#64748b; margin-bottom:16px;"></p>
        
        <form method="POST" data-no-spa>
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
            <input type="hidden" name="action" value="reset_password_tutor">
            <input type="hidden" name="id" id="reset-pass-tutor-id">

            <div style="margin-bottom:20px;">
                <label style="font-size:12px; font-weight:600; color:#334155; display:block; margin-bottom:4px;">Password Baru</label>
                <input type="text" name="new_password" required placeholder="Masukkan password baru" style="width:100%; padding:8px 12px; border:1.5px solid #cbd5e1; border-radius:8px; font-size:13px;">
            </div>

            <div style="display:flex; justify-content:flex-end; gap:10px;">
                <button type="button" class="btn btn-secondary" onclick="closeModal('modal-reset-pass')">Batal</button>
                <button type="submit" class="btn btn-primary">Simpan Password</button>
            </div>
        </form>
    </div>
</div>

<script>
// CSRF Token global
const CSRF_TOKEN = '<?= csrfToken() ?>';

function openAddTutorModal() {
    document.getElementById('modal-add-tutor').style.display = 'flex';
}

function openEditTutorModal(tutor) {
    document.getElementById('edit-tutor-id').value = tutor.id;
    document.getElementById('edit-tutor-nama').value = tutor.nama_lengkap || '';
    document.getElementById('edit-tutor-nim').value = tutor.nim || '';
    document.getElementById('edit-tutor-username').value = tutor.username || '';
    document.getElementById('edit-tutor-nohp').value = tutor.no_hp || '';
    document.getElementById('edit-tutor-email').value = tutor.email || '';
    document.getElementById('edit-tutor-active').checked = parseInt(tutor.is_active) === 1;
    document.getElementById('modal-edit-tutor').style.display = 'flex';
}

function openResetPassModal(id, name) {
    document.getElementById('reset-pass-tutor-id').value = id;
    document.getElementById('reset-pass-tutor-name').textContent = 'Tutor: ' + name;
    document.getElementById('modal-reset-pass').style.display = 'flex';
}

function closeModal(modalId) {
    document.getElementById(modalId).style.display = 'none';
}

// Tutup modal ketika backdrop diklik
document.querySelectorAll('.custom-modal-backdrop').forEach(function(modal) {
    modal.addEventListener('click', function(e) {
        if (e.target === this) {
            this.style.display = 'none';
        }
    });
});

// ── INTERACTIVE AJAX: INLINE DROPDOWN PENGGANTIAN DOSEN PENGUJI ─────────────
document.addEventListener('change', function(e) {
    if (!e.target.classList.contains('select-tutor-inline')) return;

    const selectEl = e.target;
    const regId    = selectEl.getAttribute('data-reg-id');
    const newVal   = selectEl.value;
    const oldVal   = selectEl.getAttribute('data-orig-val') || '';

    selectEl.classList.add('updating');
    selectEl.disabled = true;

    const formData = new FormData();
    formData.append('action', 'update_tutor_ajax');
    formData.append('csrf_token', CSRF_TOKEN);
    formData.append('reg_id', regId);
    formData.append('dosen_penguji', newVal);

    fetch(window.location.href, {
        method: 'POST',
        body: formData,
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
    .then(r => r.json())
    .then(data => {
        selectEl.disabled = false;
        selectEl.classList.remove('updating');

        if (data && data.success) {
            selectEl.setAttribute('data-orig-val', newVal);
            selectEl.classList.add('updated-success');
            setTimeout(() => selectEl.classList.remove('updated-success'), 2000);

            // Update data-sort dan data-filter di cell TD agar DataTables tetap konsisten
            const td = selectEl.closest('td');
            if (td) {
                td.setAttribute('data-sort', newVal);
                td.setAttribute('data-filter', newVal);
            }

            if (typeof showToast === 'function') {
                showToast(data.message || 'Dosen penguji telah dirubah', 'success', 3500);
            } else {
                alert(data.message || 'Dosen penguji telah dirubah');
            }
        } else {
            selectEl.value = oldVal;
            const errMsg = (data && data.message) ? data.message : 'Gagal mengubah dosen penguji.';
            if (typeof showToast === 'function') {
                showToast(errMsg, 'danger', 4000);
            } else {
                alert(errMsg);
            }
        }
    })
    .catch(err => {
        selectEl.disabled = false;
        selectEl.classList.remove('updating');
        selectEl.value = oldVal;
        const errMsg = 'Koneksi terputus: ' + (err.message || 'Gagal menyimpan');
        if (typeof showToast === 'function') {
            showToast(errMsg, 'danger', 4000);
        } else {
            alert(errMsg);
        }
    });
});

// ── TOMBOL RESET TUTOR (1-KLIK KOSONGKAN) ──────────────────────────────────
document.addEventListener('click', function(e) {
    const btn = e.target.closest('.btn-reset-tutor');
    if (!btn) return;

    const regId = btn.getAttribute('data-reg-id');
    const row = document.getElementById('row-reg-' + regId);
    if (!row) return;

    const selectEl = row.querySelector('.select-tutor-inline');
    if (!selectEl) return;

    if (!confirm('Kosongkan dosen penguji untuk mahasiswa ini?')) return;

    selectEl.value = '';
    selectEl.dispatchEvent(new Event('change'));
});

// ── TOGGLE STATUS AKTIF/NONAKTIF TUTOR VIA AJAX ─────────────────────────────
document.querySelectorAll('.tutor-status-toggle').forEach(function(toggle) {
    toggle.addEventListener('change', function() {
        const tutorId = this.getAttribute('data-tutor-id');
        const isChecked = this.checked;

        const formData = new FormData();
        formData.append('action', 'toggle_tutor_status_ajax');
        formData.append('csrf_token', CSRF_TOKEN);
        formData.append('tutor_id', tutorId);

        fetch(window.location.href, {
            method: 'POST',
            body: formData,
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then(r => r.json())
        .then(data => {
            if (data && data.success) {
                if (typeof showToast === 'function') {
                    showToast(data.message, 'success', 3000);
                }
            } else {
                toggle.checked = !isChecked;
                if (typeof showToast === 'function') {
                    showToast(data.message || 'Gagal mengubah status', 'danger', 4000);
                } else {
                    alert(data.message);
                }
            }
        })
        .catch(err => {
            toggle.checked = !isChecked;
            if (typeof showToast === 'function') {
                showToast('Gagal terhubung ke server', 'danger', 4000);
            }
        });
    });
});

// ── INISIALISASI DATATABLES & FILTER HARI ───────────────────────────────────
$(document).ready(function() {
    if ($.fn.DataTable) {
        if ($('#table-penugasan').length) {
            var dtPenugasan = $('#table-penugasan').DataTable({
                pageLength: 25,
                language: {
                    search: "Cari Mahasiswa/NIM:",
                    lengthMenu: "Tampilkan _MENU_ data",
                    info: "Menampilkan _START_ s/d _END_ dari _TOTAL_ mahasiswa",
                    paginate: { first: "«", last: "»", next: "›", previous: "‹" },
                    emptyTable: "Belum ada peserta pretes terdaftar"
                }
            });

            // Filter Hari Pelaksanaan
            $('.btn-filter-hari').on('click', function() {
                var day = $(this).data('day') || '';
                dtPenugasan.column(4).search(day).draw();
                $('.btn-filter-hari').removeClass('btn-primary').addClass('btn-secondary').css({'background': '', 'color': ''});
                $(this).removeClass('btn-secondary').addClass('btn-primary').css({'background': '#0284c7', 'color': '#fff'});
            });
        }

        if ($('#table-master-tutors').length) {
            $('#table-master-tutors').DataTable({
                pageLength: 15,
                language: { search: "Cari Tutor:" }
            });
        }

        if ($('#table-rekap-tutor').length) {
            $('#table-rekap-tutor').DataTable({
                pageLength: 20,
                order: [[3, 'desc']],
                language: { search: "Cari Tutor:" }
            });
        }
    }
});
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
