<?php
/**
 * LPPAI Corner - Admin: Pengaturan Sesi & Penguji / Tutor Pretes
 * Halaman terpadu untuk mengelola sesi ujian pretes per dosen, per ruang, dan per hari
 * (Fokus murni pada konfigurasi sesi, jadwal, ruangan, dan penguji tanpa menampilkan daftar mahasiswa)
 */
define('PAGE_TITLE', 'Pengaturan Sesi & Penguji Pretes');
require_once __DIR__ . '/../includes/auth.php';
requireAdmin();
require_once __DIR__ . '/../includes/pretes-helper.php';

$pdo = getDBConnection();
$message = '';
$msgType = '';

// Auto-migration aman untuk tabel dan kolom terkait tutor, ruangan, dan sesi
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
ensurePretesFeatures($pdo);

// Kolom pendukung pada pretes_attendance_sessions
try {
    $pdo->exec("ALTER TABLE pretes_attendance_sessions ADD COLUMN jam VARCHAR(50) DEFAULT '13.00 WIB (Jam 1)'");
} catch (Exception $e) {}
try {
    $pdo->exec("ALTER TABLE pretes_attendance_sessions ADD COLUMN kuota INT DEFAULT 30");
} catch (Exception $e) {}

// ── Helpers ───────────────────────────────────────────────────────────────────
function parsePengujiList($pengujiStr) {
    if (empty($pengujiStr) || trim($pengujiStr) === '') return [];
    $pengujiStr = trim($pengujiStr);
    if (strpos($pengujiStr, '|||') !== false) {
        return array_values(array_filter(array_map('trim', explode('|||', $pengujiStr)), fn($v) => $v !== ''));
    }
    return [$pengujiStr];
}

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

// Preset daftar sesi waktu pretes yang umum digunakan
$presetSesiJam = [
    '13.00 WIB (Jam 1)',
    '14.30 WIB (Jam 2)',
    '16.00 WIB (Jam 3)',
    '08.00 WIB (Pagi 1)',
    '09.30 WIB (Pagi 2)',
];

// ── 1. HANDLE AJAX & FORM POST ACTIONS ────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    $token  = $_POST['csrf_token'] ?? '';

    // AJAX: Buka / Tutup Sesi Absensi Pretes (Instant Toggle)
    if ($action === 'toggle_session_ajax') {
        header('Content-Type: application/json; charset=utf-8');
        if (!verifyCsrf($token)) {
            echo json_encode(['success' => false, 'message' => 'Sesi tidak valid.']);
            exit;
        }
        $sessionId   = (int)($_POST['session_id'] ?? 0);
        $targetState = $_POST['target_state'] ?? ''; // 'open' | 'close'
        $duration    = max(1, min(24, (int)($_POST['duration_hours'] ?? 2)));

        if ($sessionId <= 0) {
            echo json_encode(['success' => false, 'message' => 'ID sesi tidak valid.']);
            exit;
        }

        try {
            if ($targetState === 'open') {
                $pdo->prepare("UPDATE pretes_attendance_sessions SET open_until = DATE_ADD(NOW(), INTERVAL ? HOUR) WHERE id = ?")
                    ->execute([$duration, $sessionId]);
                $msg = "Sesi absensi berhasil dibuka selama $duration jam.";
            } else {
                $pdo->prepare("UPDATE pretes_attendance_sessions SET open_until = NULL WHERE id = ?")
                    ->execute([$sessionId]);
                $msg = "Sesi absensi berhasil ditutup.";
            }

            $stmt = $pdo->prepare("SELECT open_until, (open_until > NOW()) as is_open FROM pretes_attendance_sessions WHERE id = ?");
            $stmt->execute([$sessionId]);
            $sessData = $stmt->fetch();

            echo json_encode([
                'success'    => true,
                'message'    => $msg,
                'session_id' => $sessionId,
                'is_open'    => (bool)($sessData['is_open'] ?? false),
                'open_until' => $sessData['open_until'] ?? null
            ]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => 'Gagal memperbarui status sesi: ' . $e->getMessage()]);
        }
        exit;
    }

    // AJAX: Reset Token Sesi Presensi
    if ($action === 'reset_session_token_ajax') {
        header('Content-Type: application/json; charset=utf-8');
        if (!verifyCsrf($token)) {
            echo json_encode(['success' => false, 'message' => 'Sesi tidak valid.']);
            exit;
        }
        $sessionId = (int)($_POST['session_id'] ?? 0);
        if ($sessionId <= 0) {
            echo json_encode(['success' => false, 'message' => 'ID sesi tidak valid.']);
            exit;
        }

        try {
            $newToken = bin2hex(random_bytes(24));
            $pdo->prepare("UPDATE pretes_attendance_sessions SET token = ? WHERE id = ?")->execute([$newToken, $sessionId]);
            $newUrl = BASE_URL . '/absensi-pretes.php?token=' . rawurlencode($newToken);

            echo json_encode([
                'success'    => true,
                'message'    => 'Token sesi QR berhasil diperbarui',
                'session_id' => $sessionId,
                'token'      => $newToken,
                'url'        => $newUrl
            ]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => 'Gagal mereset token sesi: ' . $e->getMessage()]);
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
            $pdo->prepare("UPDATE users SET is_active = ? WHERE id = ? AND role = 'dosen'")->execute([$newStatus, $tutorId]);

            echo json_encode([
                'success'   => true,
                'message'   => 'Status tutor berhasil diubah menjadi ' . ($newStatus ? 'Aktif' : 'Nonaktif'),
                'is_active' => $newStatus
            ]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => 'Gagal mengubah status: ' . $e->getMessage()]);
        }
        exit;
    }

    // ── STANDARD POST ACTIONS ─────────────────────────────────────────────────
    if (!verifyCsrf($token)) {
        $message = 'Sesi tidak valid atau telah kedaluwarsa.';
        $msgType = 'danger';
    } else {

        // A. TAMBAH SESI PRETES BARU (PER DOSEN, RUANG, HARI, JAM)
        if ($action === 'create_session') {
            $periode = trim($_POST['periode'] ?? '');
            $hari    = trim($_POST['hari'] ?? 'Senin');
            $jam     = trim($_POST['jam'] ?? '13.00 WIB (Jam 1)');
            $penguji = trim($_POST['penguji'] ?? '');
            $ruangan = trim($_POST['ruangan'] ?? '');
            $kuota   = max(1, (int)($_POST['kuota'] ?? 30));

            if ($jam === '__CUSTOM__') {
                $jam = trim($_POST['custom_jam'] ?? '13.00 WIB (Jam 1)');
            }

            if (empty($periode) || empty($hari) || empty($penguji) || empty($ruangan)) {
                $message = 'Hari, Jam, Dosen Penguji, dan Ruangan wajib diisi.';
                $msgType = 'danger';
            } else {
                $scope = ['periode' => $periode, 'hari' => $hari, 'penguji' => $penguji, 'ruangan' => $ruangan];
                $hash  = pretesScopeHash($scope);

                // Cek apakah kombinasi sesi ini sudah ada
                $check = $pdo->prepare("SELECT id FROM pretes_attendance_sessions WHERE scope_hash = ?");
                $check->execute([$hash]);
                if ($check->fetch()) {
                    $message = 'Sesi untuk dosen <strong>' . htmlspecialchars($penguji) . '</strong> di ruangan <strong>' . htmlspecialchars($ruangan) . '</strong> pada hari ' . htmlspecialchars($hari) . ' sudah terdaftar.';
                    $msgType = 'warning';
                } else {
                    $tokenStr = bin2hex(random_bytes(24));
                    $ins = $pdo->prepare("
                        INSERT INTO pretes_attendance_sessions 
                        (scope_hash, periode, hari, penguji, ruangan, jam, kuota, token)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    $ins->execute([$hash, $periode, $hari, $penguji, $ruangan, $jam, $kuota, $tokenStr]);
                    $message = "Sesi pretes berhasil ditambahkan untuk <strong>" . htmlspecialchars($penguji) . "</strong> di <strong>" . htmlspecialchars($ruangan) . "</strong> ($hari · $jam).";
                    $msgType = 'success';
                }
            }

        // B. EDIT SESI PRETES LENGKAP (PER DOSEN, RUANG, HARI & JAM)
        } elseif ($action === 'edit_session') {
            $sessionId = (int)($_POST['session_id'] ?? 0);
            $newHari   = trim($_POST['hari'] ?? 'Senin');
            $newJam    = trim($_POST['jam'] ?? '13.00 WIB (Jam 1)');
            $newPenguji= trim($_POST['penguji'] ?? '');
            $newRuangan= trim($_POST['ruangan'] ?? '');
            $newKuota  = max(1, (int)($_POST['kuota'] ?? 30));
            $syncMhs   = isset($_POST['sync_mhs']) ? 1 : 0;

            if ($newJam === '__CUSTOM__') {
                $newJam = trim($_POST['custom_jam'] ?? '13.00 WIB (Jam 1)');
            }

            if ($sessionId <= 0 || empty($newPenguji) || empty($newRuangan)) {
                $message = 'Data sesi, dosen penguji, dan ruangan tidak boleh kosong.';
                $msgType = 'danger';
            } else {
                $stmtOld = $pdo->prepare("SELECT * FROM pretes_attendance_sessions WHERE id = ?");
                $stmtOld->execute([$sessionId]);
                $oldSess = $stmtOld->fetch();

                if (!$oldSess) {
                    $message = 'Sesi pretes tidak ditemukan.';
                    $msgType = 'danger';
                } else {
                    $newScope = [
                        'periode' => $oldSess['periode'],
                        'hari'    => $newHari,
                        'penguji' => $newPenguji,
                        'ruangan' => $newRuangan
                    ];
                    $newHash = pretesScopeHash($newScope);

                    $pdo->prepare("
                        UPDATE pretes_attendance_sessions 
                        SET hari = ?, jam = ?, penguji = ?, ruangan = ?, kuota = ?, scope_hash = ?
                        WHERE id = ?
                    ")->execute([$newHari, $newJam, $newPenguji, $newRuangan, $newKuota, $newHash, $sessionId]);

                    $mhsNotice = '';
                    if ($syncMhs) {
                        // Perbarui data mahasiswa yang sebelumnya diplot ke sesi ini
                        $updMhs = $pdo->prepare("
                            UPDATE pretes_registrations 
                            SET hari_pilihan = ?, dosen_penguji = ?, ruangan = ?, jam = ?
                            WHERE periode = ?
                              AND (hari_pilihan = ? OR (? = '' AND hari_pilihan IS NULL))
                              AND (TRIM(dosen_penguji) = ? OR (? = '' AND (dosen_penguji IS NULL OR dosen_penguji = '')))
                              AND (TRIM(ruangan) = ? OR (? = '' AND (ruangan IS NULL OR ruangan = '')))
                        ");
                        $updMhs->execute([
                            $newHari, $newPenguji, $newRuangan, $newJam,
                            $oldSess['periode'],
                            $oldSess['hari'], $oldSess['hari'],
                            trim($oldSess['penguji']), trim($oldSess['penguji']),
                            trim($oldSess['ruangan']), trim($oldSess['ruangan'])
                        ]);
                        $affected = $updMhs->rowCount();
                        if ($affected > 0) {
                            $mhsNotice = " (Penugasan $affected mahasiswa pada sesi ini otomatis diperbarui).";
                        }
                    }

                    $message = "Sesi pretes berhasil diperbarui menjadi <strong>" . htmlspecialchars($newPenguji) . "</strong> di <strong>" . htmlspecialchars($newRuangan) . "</strong> ($newHari · $newJam)." . $mhsNotice;
                    $msgType = 'success';
                }
            }

        // C. HAPUS SESI PRETES
        } elseif ($action === 'delete_session') {
            $sessionId = (int)($_POST['session_id'] ?? 0);
            if ($sessionId > 0) {
                $stmtOld = $pdo->prepare("SELECT * FROM pretes_attendance_sessions WHERE id = ?");
                $stmtOld->execute([$sessionId]);
                $sData = $stmtOld->fetch();
                if ($sData) {
                    $pdo->prepare("DELETE FROM pretes_attendance_sessions WHERE id = ?")->execute([$sessionId]);
                    $message = "Sesi pretes untuk <strong>" . htmlspecialchars($sData['penguji']) . "</strong> di <strong>" . htmlspecialchars($sData['ruangan']) . "</strong> berhasil dihapus.";
                    $msgType = 'success';
                }
            }

        // D. DUPLIKASI SESI DARI SATU HARI KE HARI LAIN (PLOT CEPAT)
        } elseif ($action === 'duplicate_day_sessions') {
            $periode   = trim($_POST['periode'] ?? '');
            $fromHari  = trim($_POST['from_hari'] ?? '');
            $toHari    = trim($_POST['to_hari'] ?? '');

            if (empty($fromHari) || empty($toHari) || $fromHari === $toHari) {
                $message = 'Pilih hari sumber dan hari tujuan yang berbeda.';
                $msgType = 'danger';
            } else {
                $stmtSrc = $pdo->prepare("SELECT * FROM pretes_attendance_sessions WHERE periode = ? AND hari = ?");
                $stmtSrc->execute([$periode, $fromHari]);
                $srcSessions = $stmtSrc->fetchAll();

                $insertedCount = 0;
                foreach ($srcSessions as $src) {
                    $newScope = [
                        'periode' => $periode,
                        'hari'    => $toHari,
                        'penguji' => $src['penguji'],
                        'ruangan' => $src['ruangan']
                    ];
                    $newHash = pretesScopeHash($newScope);

                    $check = $pdo->prepare("SELECT id FROM pretes_attendance_sessions WHERE scope_hash = ?");
                    $check->execute([$newHash]);
                    if (!$check->fetch()) {
                        $tokenStr = bin2hex(random_bytes(24));
                        $pdo->prepare("
                            INSERT INTO pretes_attendance_sessions 
                            (scope_hash, periode, hari, penguji, ruangan, jam, kuota, token)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                        ")->execute([$newHash, $periode, $toHari, $src['penguji'], $src['ruangan'], $src['jam'], $src['kuota'], $tokenStr]);
                        $insertedCount++;
                    }
                }
                $message = "Berhasil menyalin <strong>$insertedCount sesi</strong> dari hari $fromHari ke hari $toHari.";
                $msgType = 'success';
            }

        // E. BUKA / TUTUP SEMUA SESI PRETES
        } elseif ($action === 'open_all_sessions') {
            $periode    = trim($_POST['periode'] ?? '');
            $targetHari = trim($_POST['filter_hari'] ?? '');
            $duration   = max(1, min(24, (int)($_POST['duration_hours'] ?? 2)));

            $sql = "UPDATE pretes_attendance_sessions SET open_until = DATE_ADD(NOW(), INTERVAL ? HOUR) WHERE 1=1";
            $params = [$duration];
            if (!empty($periode)) {
                $sql .= " AND periode = ?";
                $params[] = $periode;
            }
            if (!empty($targetHari) && $targetHari !== 'Semua') {
                $sql .= " AND hari = ?";
                $params[] = $targetHari;
            }

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $affected = $stmt->rowCount();

            $message = "Berhasil <strong>membuka $affected sesi absensi</strong> selama $duration jam untuk scan QR presensi.";
            $msgType = 'success';

        } elseif ($action === 'close_all_sessions') {
            $periode    = trim($_POST['periode'] ?? '');
            $targetHari = trim($_POST['filter_hari'] ?? '');

            $sql = "UPDATE pretes_attendance_sessions SET open_until = NULL WHERE 1=1";
            $params = [];
            if (!empty($periode)) {
                $sql .= " AND periode = ?";
                $params[] = $periode;
            }
            if (!empty($targetHari) && $targetHari !== 'Semua') {
                $sql .= " AND hari = ?";
                $params[] = $targetHari;
            }

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $affected = $stmt->rowCount();

            $message = "Seluruh sesi absensi ($affected sesi) berhasil <strong>ditutup</strong>.";
            $msgType = 'info';

        // F. PENGATURAN JAM DEFAULT PERIODE MASTER
        } elseif ($action === 'update_master_jam') {
            $masterId  = (int)($_POST['master_id'] ?? 0);
            $customJam = trim($_POST['jam'] ?? '13.00 WIB (Jam 1)');

            if ($masterId <= 0 || empty($customJam)) {
                $message = 'Periode dan jam sesi wajib diisi.';
                $msgType = 'danger';
            } else {
                $pdo->prepare("UPDATE master_pretes SET jam = ? WHERE id = ?")->execute([$customJam, $masterId]);
                $message = "Jam sesi default periode berhasil diatur menjadi <strong>" . htmlspecialchars($customJam) . "</strong>.";
                $msgType = 'success';
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

                $message = 'Jadwal tutor harian dan kuota master pretes berhasil disimpan!';
                $msgType = 'success';
            }

        // H. MASTER DATA TUTOR (TAMBAH, EDIT, RESET PASSWORD, HAPUS)
        } elseif ($action === 'create_tutor') {
            $nama     = trim($_POST['nama_lengkap'] ?? '');
            $nim      = trim($_POST['nim'] ?? '');
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
                    $pdo->prepare("
                        INSERT INTO users (username, password, nama_lengkap, nim, email, no_hp, role, is_active)
                        VALUES (?, ?, ?, ?, ?, ?, 'dosen', ?)
                    ")->execute([$username, $hash, $nama, $nim, $email, $noHp, $isActive]);
                    $message = 'Tutor / Penguji <strong>' . htmlspecialchars($nama) . '</strong> berhasil ditambahkan!';
                    $msgType = 'success';
                }
            }

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
                $stmtOld = $pdo->prepare("SELECT nama_lengkap FROM users WHERE id = ? AND role = 'dosen'");
                $stmtOld->execute([$id]);
                $oldName = $stmtOld->fetchColumn();

                $pdo->prepare("
                    UPDATE users 
                    SET nama_lengkap = ?, nim = ?, username = ?, email = ?, no_hp = ?, is_active = ?
                    WHERE id = ? AND role = 'dosen'
                ")->execute([$nama, $nim, $username, $email, $noHp, $isActive, $id]);

                if ($syncCascade && $oldName && trim($oldName) !== $nama) {
                    $pdo->prepare("UPDATE pretes_attendance_sessions SET penguji = ? WHERE TRIM(penguji) = ?")->execute([$nama, trim($oldName)]);
                    $pdo->prepare("UPDATE pretes_registrations SET dosen_penguji = ? WHERE TRIM(dosen_penguji) = ?")->execute([$nama, trim($oldName)]);
                }

                $message = 'Data tutor <strong>' . htmlspecialchars($nama) . '</strong> berhasil diperbarui!';
                $msgType = 'success';
            }

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

        } elseif ($action === 'delete_tutor') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id > 0) {
                $stmtName = $pdo->prepare("SELECT nama_lengkap FROM users WHERE id = ? AND role = 'dosen'");
                $stmtName->execute([$id]);
                $tName = $stmtName->fetchColumn();
                if ($tName) {
                    $pdo->prepare("UPDATE users SET is_active = 0 WHERE id = ?")->execute([$id]);
                    $message = 'Tutor <strong>' . htmlspecialchars($tName) . '</strong> telah dinonaktifkan.';
                    $msgType = 'warning';
                }
            }
        }
    }
}

// ── 2. QUERY DATA PERIODE & MASTER PRETES ──────────────────────────────────────
$periodsList = $pdo->query("SELECT id, periode, tahun_ajaran, status, jam FROM master_pretes ORDER BY id DESC")->fetchAll();

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
$defaultPeriodeJam = $currentMaster['jam'] ?? '13.00 WIB (Jam 1)';

// ── 3. QUERY MASTER TUTOR (ROLE DOSEN) ─────────────────────────────────────────
$tutors = $pdo->query("SELECT id, username, nama_lengkap, nim, email, no_hp, is_active FROM users WHERE role = 'dosen' ORDER BY nama_lengkap ASC")->fetchAll();
$activeTutors = array_filter($tutors, fn($t) => (int)$t['is_active'] === 1);

// ── 4. QUERY DAFTAR RUANGAN ───────────────────────────────────────────────────
$roomsDb = $pdo->query("SELECT id, ruang FROM rooms ORDER BY ruang ASC")->fetchAll();
$allRoomNames = array_column($roomsDb, 'ruang');

// Ambil juga nama ruangan unik yang pernah tersimpan di sesi atau pendaftaran
$extraRooms = $pdo->query("
    SELECT DISTINCT ruangan FROM pretes_attendance_sessions WHERE ruangan IS NOT NULL AND ruangan != ''
    UNION 
    SELECT DISTINCT ruangan FROM pretes_registrations WHERE ruangan IS NOT NULL AND ruangan != ''
")->fetchAll(PDO::FETCH_COLUMN);

foreach ($extraRooms as $er) {
    if (!empty($er) && !in_array($er, $allRoomNames)) {
        $allRoomNames[] = $er;
    }
}
sort($allRoomNames);

// ── 5. AUTO-SINKRONISASI SESI DARI DATA PENDAFTARAN YANG SUDAH ADA ────────────
try {
    $assignedCombos = $pdo->prepare("
        SELECT DISTINCT hari_pilihan, dosen_penguji, ruangan, jam
        FROM pretes_registrations
        WHERE periode = ? AND (dosen_penguji IS NOT NULL AND dosen_penguji != '')
    ");
    $assignedCombos->execute([$periodeName]);
    foreach ($assignedCombos->fetchAll() as $ac) {
        $scope = [
            'periode' => $periodeName,
            'hari'    => ucfirst(strtolower(trim($ac['hari_pilihan'] ?? ''))) ?: 'Senin',
            'penguji' => trim($ac['dosen_penguji'] ?? ''),
            'ruangan' => trim($ac['ruangan'] ?? ''),
        ];
        if (empty($scope['penguji']) || empty($scope['ruangan'])) continue;

        $hash = pretesScopeHash($scope);
        $chk = $pdo->prepare("SELECT id FROM pretes_attendance_sessions WHERE scope_hash = ?");
        $chk->execute([$hash]);
        if (!$chk->fetch()) {
            $sessJam = !empty($ac['jam']) ? $ac['jam'] : $defaultPeriodeJam;
            $tok = bin2hex(random_bytes(24));
            $pdo->prepare("
                INSERT INTO pretes_attendance_sessions 
                (scope_hash, periode, hari, penguji, ruangan, jam, kuota, token)
                VALUES (?, ?, ?, ?, ?, ?, 30, ?)
            ")->execute([$hash, $scope['periode'], $scope['hari'], $scope['penguji'], $scope['ruangan'], $sessJam, $tok]);
        }
    }
} catch (Exception $e) {}

// ── 6. QUERY STATISTIK MAHASISWA SECARA AGREGAT (TANPA MENAMPILKAN NAMA) ──────
$stmtAgg = $pdo->prepare("
    SELECT pr.hari_pilihan, pr.dosen_penguji, pr.ruangan, pr.jam,
           COUNT(pr.id) as total_mhs,
           COUNT(ar.registration_id) as total_hadir
    FROM pretes_registrations pr
    LEFT JOIN pretes_attendance_records ar ON ar.registration_id = pr.id
    WHERE pr.periode = ?
    GROUP BY pr.hari_pilihan, pr.dosen_penguji, pr.ruangan, pr.jam
");
$stmtAgg->execute([$periodeName]);
$aggregates = $stmtAgg->fetchAll();

// ── 7. QUERY DAFTAR SESI LENGKAP PADA PERIODE INI ─────────────────────────────
$stmtSess = $pdo->prepare("
    SELECT id, scope_hash, periode, hari, penguji, ruangan, jam, kuota, token, open_until,
           (open_until > NOW()) as is_open, created_at
    FROM pretes_attendance_sessions
    WHERE periode = ?
    ORDER BY FIELD(hari, 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat'), jam ASC, ruangan ASC, penguji ASC
");
$stmtSess->execute([$periodeName]);
$rawSessions = $stmtSess->fetchAll();

// Pasangkan data agregat mhs ke masing-masing sesi
$allSessions = [];
$uniqueRoomsUsed = [];
$totalSesiOpen = 0;

foreach ($rawSessions as $sess) {
    $matchMhs = 0;
    $matchHadir = 0;
    foreach ($aggregates as $agg) {
        if ($agg['hari_pilihan'] === $sess['hari']
            && trim($agg['dosen_penguji'] ?? '') === trim($sess['penguji'] ?? '')
            && trim($agg['ruangan'] ?? '') === trim($sess['ruangan'] ?? '')) {
            $matchMhs += (int)$agg['total_mhs'];
            $matchHadir += (int)$agg['total_hadir'];
        }
    }
    $sess['total_mhs']   = $matchMhs;
    $sess['total_hadir'] = $matchHadir;
    $sess['url']         = pretesAttendanceUrl($sess);

    if (!empty($sess['ruangan']) && !in_array($sess['ruangan'], $uniqueRoomsUsed)) {
        $uniqueRoomsUsed[] = $sess['ruangan'];
    }
    if ((bool)$sess['is_open']) {
        $totalSesiOpen++;
    }

    $allSessions[] = $sess;
}

$totalSessionsCount = count($allSessions);

// Jadwal master harian
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

// Map ruangan dan kuota per tutor per hari dari pretes_attendance_sessions
$sessionRooms = [];
$sessionKuotas = [];
foreach ($allSessions as $s) {
    $h = ucfirst(strtolower(trim($s['hari'])));
    $p = trim($s['penguji']);
    if (!empty($s['ruangan'])) {
        $sessionRooms[$h][$p] = trim($s['ruangan']);
    }
    if (!empty($s['kuota'])) {
        $sessionKuotas[$h][$p] = (int)$s['kuota'];
    }
}

// Ambil jumlah peserta terplot per dosen dari pretes_registrations
$stmtTutorMhs = $pdo->prepare("
    SELECT hari_pilihan, dosen_penguji, COUNT(*) as jml
    FROM pretes_registrations
    WHERE periode = ? AND dosen_penguji IS NOT NULL AND dosen_penguji != ''
    GROUP BY hari_pilihan, dosen_penguji
");
$stmtTutorMhs->execute([$periodeName]);
$tutorTerplot = [];
foreach ($stmtTutorMhs->fetchAll() as $row) {
    $h = ucfirst(strtolower(trim($row['hari_pilihan'])));
    $p = trim($row['dosen_penguji']);
    $tutorTerplot[$h][$p] = (int)$row['jml'];
}

$days = ['Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat'];
$datesShort = [
    'Senin'  => '2 Nov',
    'Selasa' => '3 Nov',
    'Rabu'   => '4 Nov',
    'Kamis'  => '5 Nov',
    'Jumat'  => '6 Nov'
];
$datesFull = [
    'Senin'  => '2 November 2026',
    'Selasa' => '3 November 2026',
    'Rabu'   => '4 November 2026',
    'Kamis'  => '5 November 2026',
    'Jumat'  => '6 November 2026'
];

$getTutorCardDetail = function($day, $pName) use ($sessionRooms, $sessionKuotas, $tutorTerplot) {
    $rName = $sessionRooms[$day][$pName] ?? '';
    if (empty($rName)) {
        foreach (($sessionRooms[$day] ?? []) as $k => $v) {
            if (isPengujiMatch($k, $pName) || isPengujiMatch($pName, $k)) {
                $rName = $v;
                break;
            }
        }
    }

    $kuota = $sessionKuotas[$day][$pName] ?? null;
    if ($kuota === null) {
        foreach (($sessionKuotas[$day] ?? []) as $k => $v) {
            if (isPengujiMatch($k, $pName) || isPengujiMatch($pName, $k)) {
                $kuota = (int)$v;
                break;
            }
        }
    }

    $terplot = $tutorTerplot[$day][$pName] ?? 0;
    if ($terplot === 0) {
        foreach (($tutorTerplot[$day] ?? []) as $k => $v) {
            if (isPengujiMatch($k, $pName) || isPengujiMatch($pName, $k)) {
                $terplot = (int)$v;
                break;
            }
        }
    }

    if (!$kuota) {
        $kuota = $terplot > 0 ? $terplot : 30;
    }

    return [
        'name'    => $pName,
        'room'    => $rName,
        'kuota'   => $kuota,
        'terplot' => $terplot,
    ];
};

$scheduleCardData = [];

foreach ($days as $day) {
    $dayLower = strtolower($day);
    $pengujiStr = $currentMaster["penguji_$dayLower"] ?? '';
    $pengujiArr = parsePengujiList($pengujiStr);
    
    // Sinkronkan juga penguji dari tabel sesi yang belum masuk master
    if (isset($sessionRooms[$day])) {
        foreach (array_keys($sessionRooms[$day]) as $sessPenguji) {
            if (!in_array($sessPenguji, $pengujiArr)) {
                $pengujiArr[] = $sessPenguji;
            }
        }
    }

    $tutorsDetail = [];
    foreach ($pengujiArr as $pName) {
        $tutorsDetail[] = $getTutorCardDetail($day, $pName);
    }

    $scheduleCardData[$day] = [
        'day'           => $day,
        'date'          => $datesShort[$day],
        'date_full'     => $datesFull[$day],
        'penguji'       => $pengujiArr,
        'tutors_detail' => $tutorsDetail,
    ];
}

// Teks format WhatsApp untuk di-share ke para tutor (kuota terplot pada masing-masing dosen, tanpa kuota di bagian bawah)
$waTextSchedule = "🌟 *JADWAL DOSEN PENGUJI PRETES LPPAI UNISDA*\n";
$waTextSchedule .= "*Periode:* " . $periodeName . "\n";
$waTextSchedule .= "*Tahun Ajaran:* " . ($currentMaster['tahun_ajaran'] ?? '-') . "\n";
$waTextSchedule .= "*Pelaksanaan:* 2 – 6 November 2026\n";
$waTextSchedule .= "*Waktu Ujian:* " . $defaultPeriodeJam . "\n";
$waTextSchedule .= "--------------------------------------------------\n\n";

foreach ($scheduleCardData as $d) {
    $waTextSchedule .= "📅 *" . strtoupper($d['day']) . " (" . $d['date_full'] . ")* - " . $defaultPeriodeJam . "\n";
    $waTextSchedule .= "• *Dosen Penguji & Kuota Terplot:*\n";
    if (empty($d['tutors_detail'])) {
        $waTextSchedule .= "  _(Belum ada penguji yang diplot)_\n";
    } else {
        $n = 1;
        foreach ($d['tutors_detail'] as $td) {
            $rm = !empty($td['room']) ? " (" . $td['room'] . ")" : "";
            $waTextSchedule .= "  " . $n++ . ". " . $td['name'] . $rm . " - Kuota Terplot: " . $td['kuota'] . " Peserta";
            if ($td['terplot'] > 0 && $td['terplot'] != $td['kuota']) {
                $waTextSchedule .= " (" . $td['terplot'] . " terisi)";
            }
            $waTextSchedule .= "\n";
        }
    }
    $waTextSchedule .= "\n";
}
$waTextSchedule .= "--------------------------------------------------\n";
$waTextSchedule .= "⚠️ *Catatan:* Diharapkan Bapak/Ibu Dosen Penguji hadir 15 menit sebelum pelaksanaan ujian. Terima kasih.\n\n";
$waTextSchedule .= "Kepala LPPAI UNISDA,\n";
$waTextSchedule .= "*Dr. Zainul Hakim. M.H.I.*\n\n";
$waTextSchedule .= "_Lembaga Pengembangan Pendidikan Agama Islam (LPPAI) Universitas Islam Darul 'Ulum Lamongan_";

$activeTab = $_GET['tab'] ?? 'sesi';

include __DIR__ . '/../includes/header.php';
?>

<style>
/* Styling Elegan Pengaturan Sesi & Penguji LPPAI */
.sesi-hero-card {
    background: linear-gradient(135deg, #1e3a8a 0%, #0284c7 100%);
    color: #fff;
    border-radius: 16px;
    padding: 24px 28px;
    margin-bottom: 24px;
    box-shadow: 0 10px 25px -5px rgba(2, 132, 199, 0.3);
    position: relative;
    overflow: hidden;
}
.sesi-hero-card::after {
    content: "⏱️";
    position: absolute;
    right: 20px;
    bottom: -15px;
    font-size: 110px;
    opacity: 0.12;
    pointer-events: none;
}
.sesi-stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(210px, 1fr));
    gap: 16px;
    margin-bottom: 24px;
}
.sesi-stat-item {
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
.sesi-stat-item:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 12px rgba(0,0,0,0.06);
}
.sesi-stat-icon {
    width: 48px;
    height: 48px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 24px;
    flex-shrink: 0;
}
.sesi-stat-val {
    font-size: 24px;
    font-weight: 700;
    color: #0f172a;
    line-height: 1.1;
}
.sesi-stat-lbl {
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

/* Modals */
.custom-modal-backdrop {
    display: none;
    position: fixed;
    inset: 0;
    background: rgba(15, 23, 42, 0.6);
    backdrop-filter: blur(3px);
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
    padding: 4px 10px;
    border-radius: 6px;
    font-size: 11.5px;
    font-weight: 700;
}
.day-pill-Senin  { background: #fee2e2; color: #991b1b; }
.day-pill-Selasa { background: #fef3c7; color: #92400e; }
.day-pill-Rabu   { background: #e0e7ff; color: #3730a3; }
.day-pill-Kamis  { background: #dcfce7; color: #166534; }
.day-pill-Jumat  { background: #f3e8ff; color: #6b21a8; }

@keyframes pulseGlow {
    0% { transform: scale(0.95); opacity: 0.8; }
    50% { transform: scale(1.15); opacity: 1; }
    100% { transform: scale(0.95); opacity: 0.8; }
}
</style>

<div class="content">
    <?php if ($message): ?>
    <div class="alert alert-<?= $msgType ?>" style="margin-bottom: 20px; border-radius: 10px; display: flex; align-items: center; gap: 10px;">
        <span><?= $msgType === 'success' ? '✅' : ($msgType === 'warning' ? '⚠️' : 'ℹ️') ?></span>
        <div><?= $message ?></div>
    </div>
    <?php endif; ?>

    <!-- HERO HEADER CARD -->
    <div class="sesi-hero-card">
        <div style="display:flex; justify-content:space-between; align-items:flex-start; flex-wrap:wrap; gap:16px;">
            <div>
                <h1 style="font-size:24px; font-weight:800; margin-bottom:6px; color:#fff;">⏱️ Pengaturan Sesi & Penguji Pretes</h1>
                <p style="font-size:14px; opacity:0.9; margin:0; max-width:680px;">
                    Pusat kendali pengaturan jadwal sesi pretes <strong>per dosen, per ruang, dan per hari</strong>. Atur waktu pelaksanaan, kuota, kontrol pembukaan absensi QR, serta master penguji LPPAI.
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

    <!-- STATS COUNTER UTAMA -->
    <div class="sesi-stats-grid">
        <div class="sesi-stat-item">
            <div class="sesi-stat-icon" style="background:#e0f2fe; color:#0284c7;">👨‍🏫</div>
            <div>
                <div class="sesi-stat-val"><?= count($activeTutors) ?> <span style="font-size:14px; color:#94a3b8; font-weight:400;">/ <?= count($tutors) ?></span></div>
                <div class="sesi-stat-lbl">Tutor / Penguji Aktif</div>
            </div>
        </div>
        <div class="sesi-stat-item">
            <div class="sesi-stat-icon" style="background:#f3e8ff; color:#7e22ce;">⏱️</div>
            <div>
                <div class="sesi-stat-val"><?= $totalSessionsCount ?></div>
                <div class="sesi-stat-lbl">Total Sesi Pretes Terbentuk</div>
            </div>
        </div>
        <div class="sesi-stat-item">
            <div class="sesi-stat-icon" style="background:#fef3c7; color:#b45309;">🏢</div>
            <div>
                <div class="sesi-stat-val"><?= count($uniqueRoomsUsed) ?></div>
                <div class="sesi-stat-lbl">Ruangan Digunakan</div>
            </div>
        </div>
        <div class="sesi-stat-item">
            <div class="sesi-stat-icon" style="background:#dcfce7; color:#15803d;">🟢</div>
            <div>
                <div class="sesi-stat-val"><?= $totalSesiOpen ?></div>
                <div class="sesi-stat-lbl">Sesi Absensi Aktif / Buka</div>
            </div>
        </div>
    </div>

    <!-- TAB NAVIGATION -->
    <div class="custom-tabs">
        <a href="?periode=<?= urlencode($periodeName) ?>&tab=sesi" class="custom-tab-btn <?= $activeTab === 'sesi' ? 'active' : '' ?>">
            <span>⏱️</span> Pengaturan Sesi (Per Dosen, Per Ruang & Per Hari)
        </a>
        <a href="?periode=<?= urlencode($periodeName) ?>&tab=jadwal" class="custom-tab-btn <?= $activeTab === 'jadwal' ? 'active' : '' ?>">
            <span>📅</span> Plotting Jadwal Harian & Kuota (`master_pretes`)
        </a>
        <a href="?periode=<?= urlencode($periodeName) ?>&tab=master" class="custom-tab-btn <?= $activeTab === 'master' ? 'active' : '' ?>">
            <span>🧑‍🏫</span> Kelola Master Data Dosen Penguji / Tutor
        </a>
        <a href="?periode=<?= urlencode($periodeName) ?>&tab=rekap" class="custom-tab-btn <?= $activeTab === 'rekap' ? 'active' : '' ?>">
            <span>📊</span> Rekap Beban Sesi per Dosen
        </a>
    </div>

    <!-- =======================================================================
         TAB 1: PENGATURAN SESI PRETES (PER DOSEN, PER RUANG, PER HARI)
         ======================================================================= -->
    <?php if ($activeTab === 'sesi'): ?>
    
    <!-- =======================================================================
         KARTU JADWAL DOSEN PENGUJI & KUOTA (FORMAT CETAK & BAGIKAN KE TUTOR)
         Sesuai Tampilan Jadwal LPPAI (Persis Screenshot)
         ======================================================================= -->
    <div class="card" style="margin-bottom: 24px; border-radius: 14px; border: 1.5px solid #bfdbfe; overflow: hidden; box-shadow: 0 4px 15px rgba(30, 64, 175, 0.06);">
        
        <!-- CALLOUT BANNER PERIODE (PERSIS GAMBAR USER) -->
        <div style="background: #eff6ff; border-left: 5px solid #3b82f6; padding: 16px 20px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 14px;">
            <div>
                <div style="font-size: 16px; font-weight: 800; color: #1e40af; margin-bottom: 4px; display: flex; align-items: center; gap: 6px;">
                    <span>🌟</span> Periode Pretes Aktif: <strong><?= sanitize($periodeName) ?></strong>
                </div>
                <div style="font-size: 13.5px; color: #1d4ed8; font-weight: 600;">
                    Tahun Ajaran: <strong><?= sanitize($currentMaster['tahun_ajaran'] ?? '-') ?></strong> | 
                    Pelaksanaan: <strong>2 – 6 November 2026</strong> | 
                    Jam: <strong><?= sanitize($defaultPeriodeJam) ?></strong>
                </div>
            </div>

            <!-- ACTION BUTTONS: CETAK, SALIN WA, SHARE WA -->
            <div style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
                <button type="button" onclick="copyScheduleWaText()" id="btn-copy-wa-top" class="btn btn-sm" style="background: #10b981; color: #fff; font-weight: 700; border-radius: 8px; padding: 7px 14px; display: inline-flex; align-items: center; gap: 5px; border: none; cursor: pointer;">
                    <span>📋</span> Salin Teks WhatsApp
                </button>
                <a href="https://api.whatsapp.com/send?text=<?= rawurlencode($waTextSchedule) ?>" target="_blank" class="btn btn-sm" style="background: #25d366; color: #fff; font-weight: 700; border-radius: 8px; padding: 7px 14px; display: inline-flex; align-items: center; gap: 5px; text-decoration: none;">
                    <span>📲</span> Bagikan ke WA
                </a>
                <a href="<?= BASE_URL ?>/admin/pretes-jadwal-cetak.php?periode=<?= urlencode($periodeName) ?>" target="_blank" class="btn btn-sm" style="background: #0284c7; color: #fff; font-weight: 700; border-radius: 8px; padding: 7px 16px; display: inline-flex; align-items: center; gap: 5px; text-decoration: none;">
                    <span>🖨️</span> Cetak / Simpan PDF
                </a>
                <a href="?periode=<?= urlencode($periodeName) ?>&tab=jadwal" class="btn btn-sm btn-outline-primary" style="background: #fff; font-weight: 600; border-radius: 8px; padding: 7px 12px; display: inline-flex; align-items: center; gap: 4px; text-decoration: none;" title="Edit Susunan Dosen Penguji">
                    <span>⚙️</span> Edit Plotting
                </a>
            </div>
        </div>

        <!-- CARD HEADER -->
        <div style="padding: 14px 20px 8px 20px; display: flex; justify-content: space-between; align-items: center; border-top: 1px solid #dbeafe;">
            <div style="font-size: 15px; font-weight: 800; color: #1e293b; display: flex; align-items: center; gap: 8px;">
                <span>👨‍🏫</span> Pengaturan Dosen & Kuota Terplot (Jadwal Siap Dishare ke Para Tutor)
            </div>
        </div>

        <!-- 5-COLUMN DAY GRID -->
        <div style="padding: 16px 20px 20px 20px;">
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(210px, 1fr)); gap: 14px; background: #f8fafc; padding: 16px; border-radius: 12px; border: 1.5px solid #e2e8f0;">
                <?php foreach ($scheduleCardData as $d): ?>
                <div style="background: #ffffff; border: 1.5px solid #cbd5e1; border-radius: 10px; padding: 12px; display: flex; flex-direction: column; box-shadow: 0 2px 4px rgba(0,0,0,0.02);">
                    <div>
                        <!-- Header Hari & Jam -->
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px; padding-bottom: 6px; border-bottom: 1.5px solid #f1f5f9;">
                            <div style="font-size: 14px; font-weight: 800; color: #0f172a;">
                                <?= $d['day'] ?> <span style="font-size: 12px; color: #0284c7; font-weight: 700;">(<?= $d['date'] ?>)</span>
                            </div>
                            <span style="font-size: 11px; background: #e2e8f0; color: #334155; padding: 2px 6px; border-radius: 4px; font-weight: 700;">
                                13.00 WIB
                            </span>
                        </div>

                        <!-- Daftar Tutor Penguji (Clean Card View dengan Kuota Terplot Masing-Masing) -->
                        <div style="display: flex; flex-direction: column; gap: 8px;">
                            <?php if (empty($d['tutors_detail'])): ?>
                                <div style="font-size: 12px; color: #94a3b8; font-style: italic; padding: 8px 6px;">
                                    Belum ada penguji diplot
                                </div>
                            <?php else: ?>
                                <?php foreach ($d['tutors_detail'] as $td): ?>
                                <div style="background: #f8fafc; border: 1.5px solid #cbd5e1; border-radius: 8px; padding: 8px 10px; font-size: 12.5px; line-height: 1.35;">
                                    <div style="font-weight: 700; color: #0f172a;"><?= sanitize($td['name']) ?></div>
                                    <?php if (!empty($td['room'])): ?>
                                    <div style="font-size: 11px; color: #0284c7; font-weight: 700; margin-top: 2px;">
                                        🏢 <?= sanitize($td['room']) ?>
                                    </div>
                                    <?php endif; ?>
                                    <div style="font-size: 11px; margin-top: 5px; display: flex; align-items: center; justify-content: space-between; gap: 4px; background: #eff6ff; border: 1px solid #bfdbfe; color: #1e3a8a; padding: 3px 8px; border-radius: 5px; font-weight: 600;">
                                        <span>👥 Kuota Terplot: <strong><?= $td['kuota'] ?> Peserta</strong></span>
                                        <?php if ($td['terplot'] > 0 && $td['terplot'] != $td['kuota']): ?>
                                        <span style="color: #0369a1; font-weight: 700;">(<?= $td['terplot'] ?> terisi)</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <!-- Hidden Textarea for WhatsApp Message -->
    <textarea id="raw-schedule-wa-text" style="position: absolute; left: -9999px; top: -9999px;"><?= htmlspecialchars($waTextSchedule, ENT_QUOTES, 'UTF-8') ?></textarea>
    
    <!-- ACTION TOOLBAR: TAMBAH SESI, DUPLIKASI, BUKA/TUTUP SEMUA -->
    <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px; margin-bottom:16px;">
        <div style="display:flex; align-items:center; gap:8px; flex-wrap:wrap;">
            <button type="button" class="btn btn-primary" onclick="openAddSessionModal()" style="display:inline-flex; align-items:center; gap:6px; font-weight:700; border-radius:8px; padding:8px 16px;">
                <span>➕</span> Tambah Sesi Pretes Baru
            </button>
            <button type="button" class="btn btn-secondary" onclick="openDuplicateDayModal()" style="display:inline-flex; align-items:center; gap:6px; font-weight:600; border-radius:8px; padding:8px 14px;">
                <span>📑</span> Salin Sesi ke Hari Lain
            </button>
        </div>

        <div style="display:flex; align-items:center; gap:8px; flex-wrap:wrap;">
            <form method="POST" data-no-spa style="margin:0;" onsubmit="return confirm('Buka seluruh sesi absensi selama 2 jam sekarang?');">
                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                <input type="hidden" name="action" value="open_all_sessions">
                <input type="hidden" name="periode" value="<?= htmlspecialchars($periodeName, ENT_QUOTES, 'UTF-8') ?>">
                <button type="submit" class="btn btn-success" style="font-weight:700; font-size:12px; padding:8px 14px; border-radius:8px; display:inline-flex; align-items:center; gap:5px;">
                    <span>🟢</span> Buka Semua Sesi (2 Jam)
                </button>
            </form>

            <form method="POST" data-no-spa style="margin:0;" onsubmit="return confirm('Tutup seluruh sesi absensi sekarang?');">
                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                <input type="hidden" name="action" value="close_all_sessions">
                <input type="hidden" name="periode" value="<?= htmlspecialchars($periodeName, ENT_QUOTES, 'UTF-8') ?>">
                <button type="submit" class="btn btn-danger" style="font-weight:700; font-size:12px; padding:8px 14px; border-radius:8px; display:inline-flex; align-items:center; gap:5px;">
                    <span>🔒</span> Tutup Semua Sesi
                </button>
            </form>
        </div>
    </div>

    <!-- FILTER BAR HARI & RUANGAN UNTUK TABEL SESI -->
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
                💡 <em>Klik tombol <strong>✏️ Edit Sesi</strong> pada baris untuk mengubah dosen, ruang, hari, dan jam sesi.</em>
            </div>
        </div>
    </div>

    <!-- TABEL SELURUH SESI PRETES (FOKUS: DOSEN, RUANG, HARI, JAM) -->
    <div class="card" style="border-radius:12px; padding:20px; overflow-x:auto;">
        <table id="table-sesi-pretes" class="table" style="width:100%; border-collapse:collapse; font-size:13px;">
            <thead>
                <tr style="background:#f8fafc; border-bottom:2px solid #e2e8f0;">
                    <th style="width:40px; text-align:center;">No</th>
                    <th style="width:110px;">Hari Pretes</th>
                    <th style="min-width:140px;">Sesi Waktu / Jam</th>
                    <th style="min-width:200px;">🧑‍🏫 Dosen Penguji / Tutor</th>
                    <th style="min-width:150px;">🏢 Ruangan Ujian</th>
                    <th style="width:110px; text-align:center;">Kuota</th>
                    <th style="width:130px; text-align:center;">Status Absensi</th>
                    <th style="width:180px; text-align:center;">Aksi Pengaturan Sesi</th>
                </tr>
            </thead>
            <tbody>
                <?php 
                $sNo = 1; 
                foreach ($allSessions as $sess): 
                    $dayPill = 'day-pill-' . $sess['hari'];
                    $isOpen  = (bool)$sess['is_open'];
                ?>
                <tr id="session-row-<?= $sess['id'] ?>" style="border-bottom:1px solid #f1f5f9;">
                    <td style="text-align:center; color:#94a3b8; font-weight:600;"><?= $sNo++ ?></td>
                    <td>
                        <span class="day-pill-badge <?= $dayPill ?>"><?= sanitize($sess['hari']) ?></span>
                    </td>
                    <td>
                        <strong style="color:#0f172a; font-size:13px;"><?= sanitize($sess['jam']) ?></strong>
                    </td>
                    <td>
                        <strong style="color:#1e293b; font-size:13.5px;"><?= sanitize($sess['penguji']) ?></strong>
                    </td>
                    <td>
                        <span class="badge" style="background:#f1f5f9; color:#1e293b; font-size:12px; font-weight:600; padding:4px 10px; border-radius:6px; border:1px solid #e2e8f0;">
                            🏢 <?= sanitize($sess['ruangan']) ?>
                        </span>
                    </td>
                    <td style="text-align:center;">
                        <span style="font-weight:700; color:#0f172a;"><?= (int)$sess['kuota'] ?> Kursi</span>
                        <div style="font-size:11px; color:#64748b; margin-top:2px;">
                            (<?= $sess['total_mhs'] ?> mhs terdaftar)
                        </div>
                    </td>
                    <td style="text-align:center;" id="session-status-cell-<?= $sess['id'] ?>">
                        <?php if ($isOpen): ?>
                        <span class="badge" style="background:#dcfce7; color:#15803d; padding:4px 10px; border-radius:12px; font-weight:700; font-size:11px; display:inline-flex; align-items:center; gap:5px;">
                            <span style="width:6px; height:6px; border-radius:50%; background:#16a34a; animation:pulseGlow 1.5s infinite;"></span> BUKA
                        </span>
                        <?php else: ?>
                        <span class="badge" style="background:#f1f5f9; color:#64748b; padding:4px 10px; border-radius:12px; font-weight:700; font-size:11px;">
                            🔒 TERTUTUP
                        </span>
                        <?php endif; ?>
                    </td>
                    <td style="text-align:center;">
                        <div style="display:inline-flex; gap:6px; align-items:center;">
                            <!-- Tombol Edit Sesi Lengkap (Modal) -->
                            <button type="button" 
                                    class="btn btn-sm btn-secondary"
                                    onclick="openEditSessionModal(<?= htmlspecialchars(json_encode($sess), ENT_QUOTES, 'UTF-8') ?>)"
                                    title="Edit Sesi (Ubah Dosen, Ruang, Hari, atau Jam)"
                                    style="padding:4px 9px; font-size:12px; border-radius:6px; font-weight:600;">
                                ✏️ Edit
                            </button>

                            <!-- Tombol Buka/Tutup Sesi (AJAX 1-Klik) -->
                            <button type="button" 
                                    class="btn btn-sm btn-toggle-session-ajax <?= $isOpen ? 'btn-danger' : 'btn-success' ?>"
                                    data-session-id="<?= $sess['id'] ?>"
                                    data-target-state="<?= $isOpen ? 'close' : 'open' ?>"
                                    title="<?= $isOpen ? 'Tutup Sesi Absensi' : 'Buka Sesi Absensi Selama 2 Jam' ?>"
                                    style="padding:4px 9px; font-size:11.5px; border-radius:6px; font-weight:600;">
                                <?= $isOpen ? '🔒 Tutup' : '🟢 Buka' ?>
                            </button>

                            <!-- Tombol QR Code Absensi -->
                            <button type="button" 
                                    class="btn btn-sm btn-secondary"
                                    onclick="openSessionQrModal(<?= $sess['id'] ?>, '<?= htmlspecialchars($sess['hari'], ENT_QUOTES, 'UTF-8') ?>', '<?= htmlspecialchars($sess['jam'], ENT_QUOTES, 'UTF-8') ?>', '<?= htmlspecialchars($sess['penguji'], ENT_QUOTES, 'UTF-8') ?>', '<?= htmlspecialchars($sess['ruangan'], ENT_QUOTES, 'UTF-8') ?>', <?= $sess['total_mhs'] ?>, <?= $isOpen ? 'true' : 'false' ?>, '<?= htmlspecialchars($sess['url'], ENT_QUOTES, 'UTF-8') ?>', '<?= htmlspecialchars($sess['open_until'] ?? '', ENT_QUOTES, 'UTF-8') ?>')"
                                    title="Tampilkan QR Code Absensi"
                                    style="padding:4px 9px; font-size:11.5px; border-radius:6px;">
                                📱 QR
                            </button>

                            <!-- Tombol Hapus Sesi -->
                            <form method="POST" data-no-spa style="display:inline;" onsubmit="return confirm('Hapus sesi untuk penguji <?= htmlspecialchars($sess['penguji'], ENT_QUOTES, 'UTF-8') ?> di ruang <?= htmlspecialchars($sess['ruangan'], ENT_QUOTES, 'UTF-8') ?>?');">
                                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                                <input type="hidden" name="action" value="delete_session">
                                <input type="hidden" name="session_id" value="<?= $sess['id'] ?>">
                                <button type="submit" class="btn btn-sm btn-outline-danger" style="padding:4px 7px; font-size:11px; border-radius:6px;" title="Hapus Sesi">
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
         TAB 2: PLOTTING JADWAL HARIAN TUTOR MASTER PRETES
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
         TAB 3: MANAJEMEN DATA MASTER TUTOR (ROLE DOSEN)
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
                            <form method="POST" data-no-spa style="display:inline;" onsubmit="return confirm('Nonaktifkan tutor <?= htmlspecialchars($tut['nama_lengkap'], ENT_QUOTES, 'UTF-8') ?>?');">
                                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                                <input type="hidden" name="action" value="delete_tutor">
                                <input type="hidden" name="id" value="<?= $tut['id'] ?>">
                                <button type="submit" class="btn btn-sm btn-outline-danger" style="padding:4px 8px; font-size:12px;" title="Nonaktifkan Tutor">
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
         TAB 4: REKAP BEBAN SESI PER DOSEN PENGUJI
         ======================================================================= -->
    <?php elseif ($activeTab === 'rekap'): ?>
    <div style="margin-bottom:16px;">
        <h3 style="font-size:18px; font-weight:700; color:#1e293b; margin:0 0 4px 0;">📊 Rekap Beban Sesi per Dosen Penguji</h3>
        <p style="font-size:13px; color:#64748b; margin:0;">
            Pantau jumlah sesi ujian dan ruangan yang diampu oleh masing-masing dosen penguji pada periode pretes ini.
        </p>
    </div>

    <div class="card" style="border-radius:12px; padding:20px; overflow-x:auto;">
        <table id="table-rekap-tutor" class="table" style="width:100%; border-collapse:collapse; font-size:13px;">
            <thead>
                <tr style="background:#f8fafc; border-bottom:2px solid #e2e8f0;">
                    <th style="width:40px; text-align:center;">No</th>
                    <th>Dosen Penguji</th>
                    <th style="width:150px;">Kontak WhatsApp</th>
                    <th style="width:130px; text-align:center;">Jumlah Sesi Diampu</th>
                    <th>Daftar Ruangan & Hari</th>
                    <th style="width:140px; text-align:center;">Aksi Kontak</th>
                </tr>
            </thead>
            <tbody>
                <?php 
                $rNo = 1; 
                foreach ($tutors as $tut): 
                    // Cari sesi-sesi yang diampu tutor ini
                    $tutorSessions = array_filter($allSessions, fn($s) => isPengujiMatch($s['penguji'], $tut['nama_lengkap']));
                    $sesiCount = count($tutorSessions);
                    $waClean = formatNoHp($tut['no_hp']);
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
                    <td style="text-align:center;">
                        <span class="badge" style="background:#e0f2fe; color:#0369a1; font-weight:700; font-size:12px; padding:4px 10px; border-radius:12px;">
                            <?= $sesiCount ?> Sesi
                        </span>
                    </td>
                    <td>
                        <?php if ($sesiCount > 0): ?>
                            <div style="display:flex; flex-wrap:wrap; gap:4px;">
                            <?php foreach ($tutorSessions as $ts): ?>
                                <span class="badge" style="background:#f8fafc; border:1px solid #cbd5e1; color:#334155; font-size:11px; padding:2px 6px; border-radius:4px;">
                                    <?= sanitize($ts['hari']) ?> · <?= sanitize($ts['ruangan']) ?>
                                </span>
                            <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <span style="color:#94a3b8; font-size:12px;">Belum ditugaskan di sesi manapun</span>
                        <?php endif; ?>
                    </td>
                    <td style="text-align:center;">
                        <?php if ($waClean): ?>
                        <a href="https://wa.me/<?= $waClean ?>" target="_blank" class="btn btn-sm" style="background:#25d366; color:#fff; font-weight:600; border-radius:6px; font-size:11px; padding:4px 10px; text-decoration:none; display:inline-flex; align-items:center; gap:4px;">
                            <span>📲</span> Chat WA
                        </a>
                        <?php else: ?>
                        <span style="color:#94a3b8; font-size:11px;">No WA Kosong</span>
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
     MODAL TAMBAH SESI PRETES BARU (PER DOSEN, RUANG, HARI, JAM)
     =========================================================================== -->
<div id="modal-add-session" class="custom-modal-backdrop">
    <div class="custom-modal-content">
        <h3 style="margin:0 0 16px 0; font-size:18px; color:#1e293b;">➕ Tambah Sesi Pretes Baru</h3>
        <form method="POST" data-no-spa>
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
            <input type="hidden" name="action" value="create_session">
            <input type="hidden" name="periode" value="<?= htmlspecialchars($periodeName, ENT_QUOTES, 'UTF-8') ?>">

            <div style="margin-bottom:12px;">
                <label style="font-size:12px; font-weight:600; color:#334155; display:block; margin-bottom:4px;">Hari Pelaksanaan *</label>
                <select name="hari" required style="width:100%; padding:8px 12px; border:1.5px solid #cbd5e1; border-radius:8px; font-size:13px; font-weight:600; background:#fff;">
                    <option value="Senin">Senin (2 Nov 2026)</option>
                    <option value="Selasa">Selasa (3 Nov 2026)</option>
                    <option value="Rabu">Rabu (4 Nov 2026)</option>
                    <option value="Kamis">Kamis (5 Nov 2026)</option>
                    <option value="Jumat">Jumat (6 Nov 2026)</option>
                </select>
            </div>

            <div style="margin-bottom:12px;">
                <label style="font-size:12px; font-weight:600; color:#334155; display:block; margin-bottom:4px;">Sesi Waktu / Jam *</label>
                <select name="jam" id="add-session-jam" required style="width:100%; padding:8px 12px; border:1.5px solid #cbd5e1; border-radius:8px; font-size:13px; font-weight:600; background:#fff;">
                    <?php foreach ($presetSesiJam as $psj): ?>
                    <option value="<?= sanitize($psj) ?>"><?= sanitize($psj) ?></option>
                    <?php endforeach; ?>
                    <option value="__CUSTOM__">✏️ Ketik Jam / Sesi Lainnya...</option>
                </select>
                <input type="text" name="custom_jam" id="add-session-custom-jam" placeholder="Contoh: 10.00 WIB (Pagi)" style="display:none; width:100%; margin-top:6px; padding:7px 10px; border:1.5px solid #cbd5e1; border-radius:6px; font-size:12px;">
            </div>

            <div style="margin-bottom:12px;">
                <label style="font-size:12px; font-weight:600; color:#334155; display:block; margin-bottom:4px;">Dosen Penguji / Tutor *</label>
                <select name="penguji" required style="width:100%; padding:8px 12px; border:1.5px solid #0284c7; border-radius:8px; font-size:13px; font-weight:600; background:#fff;">
                    <option value="">-- Pilih Dosen Penguji --</option>
                    <?php foreach ($activeTutors as $t): ?>
                    <option value="<?= sanitize($t['nama_lengkap']) ?>"><?= sanitize($t['nama_lengkap']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div style="margin-bottom:12px;">
                <label style="font-size:12px; font-weight:600; color:#334155; display:block; margin-bottom:4px;">Ruangan Ujian *</label>
                <select name="ruangan" id="add-session-ruangan-select" required style="width:100%; padding:8px 12px; border:1.5px solid #cbd5e1; border-radius:8px; font-size:13px; font-weight:600; background:#fff;">
                    <option value="">-- Pilih Ruangan --</option>
                    <?php foreach ($allRoomNames as $rName): ?>
                    <option value="<?= sanitize($rName) ?>"><?= sanitize($rName) ?></option>
                    <?php endforeach; ?>
                    <option value="__NEW_ROOM__">➕ Tambah / Tulis Ruangan Baru...</option>
                </select>
                <input type="text" name="new_ruangan_text" id="add-session-new-ruangan" placeholder="Nama ruangan baru (misal: Ruang Lab CBT 3)" style="display:none; width:100%; margin-top:6px; padding:7px 10px; border:1.5px solid #cbd5e1; border-radius:6px; font-size:12px;">
            </div>

            <div style="margin-bottom:20px;">
                <label style="font-size:12px; font-weight:600; color:#334155; display:block; margin-bottom:4px;">Kuota Peserta Sesi</label>
                <input type="number" name="kuota" value="30" min="1" max="500" required style="width:100%; padding:8px 12px; border:1.5px solid #cbd5e1; border-radius:8px; font-size:13px; font-weight:600;">
            </div>

            <div style="display:flex; justify-content:flex-end; gap:10px;">
                <button type="button" class="btn btn-secondary" onclick="closeModal('modal-add-session')">Batal</button>
                <button type="submit" class="btn btn-primary">💾 Simpan Sesi</button>
            </div>
        </form>
    </div>
</div>

<!-- ===========================================================================
     MODAL EDIT SESI PRETES (PER DOSEN, RUANG, HARI, JAM)
     =========================================================================== -->
<div id="modal-edit-session" class="custom-modal-backdrop">
    <div class="custom-modal-content">
        <h3 style="margin:0 0 16px 0; font-size:18px; color:#1e293b;">✏️ Edit Sesi Pretes</h3>
        <form method="POST" data-no-spa>
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
            <input type="hidden" name="action" value="edit_session">
            <input type="hidden" name="session_id" id="edit-session-id">

            <div style="margin-bottom:12px;">
                <label style="font-size:12px; font-weight:600; color:#334155; display:block; margin-bottom:4px;">Hari Pelaksanaan *</label>
                <select name="hari" id="edit-session-hari" required style="width:100%; padding:8px 12px; border:1.5px solid #cbd5e1; border-radius:8px; font-size:13px; font-weight:600; background:#fff;">
                    <option value="Senin">Senin (2 Nov 2026)</option>
                    <option value="Selasa">Selasa (3 Nov 2026)</option>
                    <option value="Rabu">Rabu (4 Nov 2026)</option>
                    <option value="Kamis">Kamis (5 Nov 2026)</option>
                    <option value="Jumat">Jumat (6 Nov 2026)</option>
                </select>
            </div>

            <div style="margin-bottom:12px;">
                <label style="font-size:12px; font-weight:600; color:#334155; display:block; margin-bottom:4px;">Sesi Waktu / Jam *</label>
                <select name="jam" id="edit-session-jam" required style="width:100%; padding:8px 12px; border:1.5px solid #cbd5e1; border-radius:8px; font-size:13px; font-weight:600; background:#fff;">
                    <?php foreach ($presetSesiJam as $psj): ?>
                    <option value="<?= sanitize($psj) ?>"><?= sanitize($psj) ?></option>
                    <?php endforeach; ?>
                    <option value="__CUSTOM__">✏️ Ketik Jam / Sesi Lainnya...</option>
                </select>
                <input type="text" name="custom_jam" id="edit-session-custom-jam" placeholder="Contoh: 10.00 WIB (Pagi)" style="display:none; width:100%; margin-top:6px; padding:7px 10px; border:1.5px solid #cbd5e1; border-radius:6px; font-size:12px;">
            </div>

            <div style="margin-bottom:12px;">
                <label style="font-size:12px; font-weight:600; color:#334155; display:block; margin-bottom:4px;">Dosen Penguji / Tutor *</label>
                <select name="penguji" id="edit-session-penguji" required style="width:100%; padding:8px 12px; border:1.5px solid #0284c7; border-radius:8px; font-size:13px; font-weight:600; background:#fff;">
                    <?php foreach ($activeTutors as $t): ?>
                    <option value="<?= sanitize($t['nama_lengkap']) ?>"><?= sanitize($t['nama_lengkap']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div style="margin-bottom:12px;">
                <label style="font-size:12px; font-weight:600; color:#334155; display:block; margin-bottom:4px;">Ruangan Ujian *</label>
                <select name="ruangan" id="edit-session-ruangan" required style="width:100%; padding:8px 12px; border:1.5px solid #cbd5e1; border-radius:8px; font-size:13px; font-weight:600; background:#fff;">
                    <?php foreach ($allRoomNames as $rName): ?>
                    <option value="<?= sanitize($rName) ?>"><?= sanitize($rName) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div style="margin-bottom:14px;">
                <label style="font-size:12px; font-weight:600; color:#334155; display:block; margin-bottom:4px;">Kuota Peserta Sesi</label>
                <input type="number" name="kuota" id="edit-session-kuota" value="30" min="1" max="500" required style="width:100%; padding:8px 12px; border:1.5px solid #cbd5e1; border-radius:8px; font-size:13px; font-weight:600;">
            </div>

            <!-- SMART SYNC REGISTRASI -->
            <div style="background:#f0fdf4; border:1px solid #bbf7d0; border-radius:8px; padding:10px 12px; margin-bottom:20px;">
                <label style="display:flex; align-items:flex-start; gap:8px; font-size:12px; color:#166534; cursor:pointer;">
                    <input type="checkbox" name="sync_mhs" value="1" checked style="margin-top:2px;">
                    <span>
                        <strong>Sinkronkan Peserta:</strong> Otomatis pindahkan penugasan mahasiswa yang sebelumnya terdaftar pada sesi lama ke dosen, ruangan, hari, dan jam baru ini.
                    </span>
                </label>
            </div>

            <div style="display:flex; justify-content:flex-end; gap:10px;">
                <button type="button" class="btn btn-secondary" onclick="closeModal('modal-edit-session')">Batal</button>
                <button type="submit" class="btn btn-primary">💾 Simpan Perubahan Sesi</button>
            </div>
        </form>
    </div>
</div>

<!-- ===========================================================================
     MODAL SALIN / DUPLIKASI SESI DARI HARI A KE HARI B
     =========================================================================== -->
<div id="modal-duplicate-day" class="custom-modal-backdrop">
    <div class="custom-modal-content">
        <h3 style="margin:0 0 16px 0; font-size:18px; color:#1e293b;">📑 Salin Sesi ke Hari Lain</h3>
        <p style="font-size:12.5px; color:#64748b; margin-bottom:16px;">
            Duplikasi seluruh pengaturan sesi (penguji & ruangan) dari satu hari pelaksanaan ke hari lainnya agar tidak perlu membuat ulang satu per satu.
        </p>

        <form method="POST" data-no-spa onsubmit="return confirm('Salin seluruh sesi dari hari sumber ke hari tujuan?');">
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
            <input type="hidden" name="action" value="duplicate_day_sessions">
            <input type="hidden" name="periode" value="<?= htmlspecialchars($periodeName, ENT_QUOTES, 'UTF-8') ?>">

            <div style="margin-bottom:14px;">
                <label style="font-size:12px; font-weight:600; color:#334155; display:block; margin-bottom:4px;">Hari Sumber (Yang Disalin) *</label>
                <select name="from_hari" required style="width:100%; padding:8px 12px; border:1.5px solid #cbd5e1; border-radius:8px; font-size:13px; font-weight:600; background:#fff;">
                    <option value="Senin">Senin (2 Nov)</option>
                    <option value="Selasa">Selasa (3 Nov)</option>
                    <option value="Rabu">Rabu (4 Nov)</option>
                    <option value="Kamis">Kamis (5 Nov)</option>
                    <option value="Jumat">Jumat (6 Nov)</option>
                </select>
            </div>

            <div style="margin-bottom:20px;">
                <label style="font-size:12px; font-weight:600; color:#334155; display:block; margin-bottom:4px;">Hari Tujuan *</label>
                <select name="to_hari" required style="width:100%; padding:8px 12px; border:1.5px solid #0284c7; border-radius:8px; font-size:13px; font-weight:600; background:#fff;">
                    <option value="Selasa">Selasa (3 Nov)</option>
                    <option value="Rabu">Rabu (4 Nov)</option>
                    <option value="Kamis">Kamis (5 Nov)</option>
                    <option value="Jumat">Jumat (6 Nov)</option>
                    <option value="Senin">Senin (2 Nov)</option>
                </select>
            </div>

            <div style="display:flex; justify-content:flex-end; gap:10px;">
                <button type="button" class="btn btn-secondary" onclick="closeModal('modal-duplicate-day')">Batal</button>
                <button type="submit" class="btn btn-primary">⚡ Salin Sesi Sekarang</button>
            </div>
        </form>
    </div>
</div>

<!-- ===========================================================================
     MODAL QR CODE SESI PRESENSI PRETES
     =========================================================================== -->
<div id="modal-qr-sesi" class="custom-modal-backdrop">
    <div class="custom-modal-content" style="max-width:480px; text-align:center;">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:14px; border-bottom:1px solid #e2e8f0; padding-bottom:10px;">
            <h3 style="margin:0; font-size:17px; color:#1e293b;">📱 QR Code Sesi Presensi Mahasiswa</h3>
            <button type="button" onclick="closeModal('modal-qr-sesi')" style="border:none; background:none; font-size:20px; color:#94a3b8; cursor:pointer;">✕</button>
        </div>

        <div style="background:#f8fafc; border-radius:10px; padding:12px; margin-bottom:16px; font-size:12.5px; text-align:left; color:#334155; line-height:1.6;">
            <div>📅 Hari: <strong id="modal-qr-hari">-</strong> · Jam: <strong id="modal-qr-jam">-</strong></div>
            <div>👨‍🏫 Penguji: <strong id="modal-qr-penguji">-</strong></div>
            <div>🏢 Ruangan: <strong id="modal-qr-ruangan">-</strong> · Peserta: <strong id="modal-qr-count">0 Mhs</strong></div>
            <div style="margin-top:4px;">Status: <span id="modal-qr-status-badge"></span></div>
        </div>

        <!-- Wadah QR Code -->
        <div style="display:inline-block; padding:14px; background:#ffffff; border:2px dashed #cbd5e1; border-radius:16px; margin-bottom:16px;">
            <div id="modal-qr-container" style="display:inline-block; min-width:220px; min-height:220px;"></div>
            <p style="font-size:11px; color:#64748b; margin:8px 0 0 0;">Scan dengan kamera HP untuk presensi langsung</p>
        </div>

        <!-- Input Link URL Absensi -->
        <div style="display:flex; gap:8px; margin-bottom:18px;">
            <input type="text" id="modal-qr-url" readonly style="flex:1; padding:7px 10px; font-size:11px; font-family:monospace; border:1px solid #cbd5e1; border-radius:6px; background:#f1f5f9; color:#475569;">
            <button type="button" id="btn-copy-modal-url" class="btn btn-secondary" style="font-size:12px; padding:7px 12px; white-space:nowrap;">📋 Salin</button>
        </div>

        <input type="hidden" id="modal-qr-session-id">

        <div style="display:flex; gap:10px; justify-content:center;">
            <button type="button" id="modal-qr-btn-toggle" class="btn btn-success" style="font-weight:700; padding:8px 16px; font-size:13px;">
                🟢 Buka Sesi (2 Jam)
            </button>
            <button type="button" class="btn btn-secondary" onclick="window.print()" style="font-weight:600; padding:8px 16px; font-size:13px;">
                🖨️ Cetak QR
            </button>
        </div>
    </div>
</div>

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
                        <strong>Sinkronkan Perubahan Nama:</strong> Otomatis perbarui nama penguji pada seluruh sesi pretes dan master jadwal jika nama diubah.
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

<!-- Load QRCode Library -->
<script src="<?= BASE_URL ?>/assets/js/lib/qrcode.min.js"></script>

<script>
// CSRF Token global
const CSRF_TOKEN = '<?= csrfToken() ?>';

function openAddSessionModal() {
    document.getElementById('modal-add-session').style.display = 'flex';
}

function openEditSessionModal(sess) {
    document.getElementById('edit-session-id').value = sess.id;
    document.getElementById('edit-session-hari').value = sess.hari || 'Senin';
    
    var jamSelect = document.getElementById('edit-session-jam');
    var customJamInput = document.getElementById('edit-session-custom-jam');
    
    // Check if jam is in preset
    var foundJam = false;
    for (var i = 0; i < jamSelect.options.length; i++) {
        if (jamSelect.options[i].value === sess.jam) {
            jamSelect.selectedIndex = i;
            foundJam = true;
            break;
        }
    }
    if (!foundJam) {
        jamSelect.value = '__CUSTOM__';
        customJamInput.value = sess.jam || '';
        customJamInput.style.display = 'block';
    } else {
        customJamInput.style.display = 'none';
    }

    document.getElementById('edit-session-penguji').value = sess.penguji || '';
    document.getElementById('edit-session-ruangan').value = sess.ruangan || '';
    document.getElementById('edit-session-kuota').value = sess.kuota || 30;

    document.getElementById('modal-edit-session').style.display = 'flex';
}

function openDuplicateDayModal() {
    document.getElementById('modal-duplicate-day').style.display = 'flex';
}

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

// Custom Jam Input Toggle on Add Session Modal
var addJamSelect = document.getElementById('add-session-jam');
if (addJamSelect) {
    addJamSelect.addEventListener('change', function() {
        var custom = document.getElementById('add-session-custom-jam');
        if (this.value === '__CUSTOM__') {
            custom.style.display = 'block';
            custom.required = true;
            custom.focus();
        } else {
            custom.style.display = 'none';
            custom.required = false;
        }
    });
}

// Custom Room Input Toggle on Add Session Modal
var addRoomSelect = document.getElementById('add-session-ruangan-select');
if (addRoomSelect) {
    addRoomSelect.addEventListener('change', function() {
        var custom = document.getElementById('add-session-new-ruangan');
        if (this.value === '__NEW_ROOM__') {
            custom.style.display = 'block';
            custom.required = true;
            custom.focus();
        } else {
            custom.style.display = 'none';
            custom.required = false;
        }
    });
}

// Custom Jam Input Toggle on Edit Session Modal
var editJamSelect = document.getElementById('edit-session-jam');
if (editJamSelect) {
    editJamSelect.addEventListener('change', function() {
        var custom = document.getElementById('edit-session-custom-jam');
        if (this.value === '__CUSTOM__') {
            custom.style.display = 'block';
            custom.required = true;
            custom.focus();
        } else {
            custom.style.display = 'none';
            custom.required = false;
        }
    });
}

// ── MODAL QR CODE SESI PRESENSI ───────────────────────────────────────────
function openSessionQrModal(sessionId, hari, jam, penguji, ruangan, count, isOpen, url, openUntil) {
    document.getElementById('modal-qr-session-id').value = sessionId;
    document.getElementById('modal-qr-hari').textContent = hari;
    document.getElementById('modal-qr-jam').textContent = jam;
    document.getElementById('modal-qr-penguji').textContent = penguji || 'Belum ditentukan';
    document.getElementById('modal-qr-ruangan').textContent = ruangan || 'Belum diplot';
    document.getElementById('modal-qr-count').textContent = count + ' Mahasiswa Terdaftar';
    document.getElementById('modal-qr-url').value = url;
    
    var statusBadge = document.getElementById('modal-qr-status-badge');
    var btnToggle = document.getElementById('modal-qr-btn-toggle');
    if (isOpen) {
        statusBadge.innerHTML = '<span style="background:#dcfce7;color:#15803d;padding:3px 10px;border-radius:12px;font-size:11.5px;font-weight:700;">🟢 BUKA' + (openUntil ? ' (s.d ' + openUntil + ')' : '') + '</span>';
        btnToggle.textContent = '🔒 Tutup Sesi Absensi';
        btnToggle.className = 'btn btn-danger';
        btnToggle.setAttribute('data-target-state', 'close');
    } else {
        statusBadge.innerHTML = '<span style="background:#f1f5f9;color:#64748b;padding:3px 10px;border-radius:12px;font-size:11.5px;font-weight:700;">🔒 TERTUTUP</span>';
        btnToggle.textContent = '🟢 Buka Sesi Absensi (2 Jam)';
        btnToggle.className = 'btn btn-success';
        btnToggle.setAttribute('data-target-state', 'open');
    }

    var qrContainer = document.getElementById('modal-qr-container');
    qrContainer.innerHTML = '';
    if (typeof QRCode !== 'undefined') {
        new QRCode(qrContainer, {
            text: url,
            width: 220,
            height: 220,
            correctLevel: QRCode.CorrectLevel.M
        });
    }

    document.getElementById('modal-qr-sesi').style.display = 'flex';
}

// Salin link di modal QR
document.getElementById('btn-copy-modal-url').addEventListener('click', function() {
    var urlInput = document.getElementById('modal-qr-url');
    urlInput.select();
    navigator.clipboard.writeText(urlInput.value).then(function() {
        if (typeof showToast === 'function') {
            showToast('Link absensi mahasiswa berhasil disalin!', 'success', 2500);
        } else {
            alert('Link absensi berhasil disalin!');
        }
    });
});

// Toggle Sesi Buka/Tutup dari dalam Modal QR
document.getElementById('modal-qr-btn-toggle').addEventListener('click', function() {
    var sessionId = document.getElementById('modal-qr-session-id').value;
    var targetState = this.getAttribute('data-target-state');

    toggleSessionAjax(sessionId, targetState, function(data) {
        if (data && data.success) {
            closeModal('modal-qr-sesi');
            location.reload();
        }
    });
});

// ── AJAX: BUKA / TUTUP SESI ABSENSI PRETES DARI TABEL ──────────────────────
function toggleSessionAjax(sessionId, targetState, callback) {
    var formData = new FormData();
    formData.append('action', 'toggle_session_ajax');
    formData.append('csrf_token', CSRF_TOKEN);
    formData.append('session_id', sessionId);
    formData.append('target_state', targetState);
    formData.append('duration_hours', 2);

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
            if (callback) callback(data);
            else {
                var statusCell = document.getElementById('session-status-cell-' + sessionId);
                var row = document.getElementById('session-row-' + sessionId);
                if (statusCell && row) {
                    var btn = row.querySelector('.btn-toggle-session-ajax');
                    if (data.is_open) {
                        statusCell.innerHTML = '<span class="badge" style="background:#dcfce7; color:#15803d; padding:4px 10px; border-radius:12px; font-weight:700; font-size:11px; display:inline-flex; align-items:center; gap:5px;"><span style="width:6px; height:6px; border-radius:50%; background:#16a34a; animation:pulseGlow 1.5s infinite;"></span> BUKA</span>';
                        if (btn) {
                            btn.textContent = '🔒 Tutup';
                            btn.className = 'btn btn-sm btn-toggle-session-ajax btn-danger';
                            btn.setAttribute('data-target-state', 'close');
                        }
                    } else {
                        statusCell.innerHTML = '<span class="badge" style="background:#f1f5f9; color:#64748b; padding:4px 10px; border-radius:12px; font-weight:700; font-size:11px;">🔒 TERTUTUP</span>';
                        if (btn) {
                            btn.textContent = '🟢 Buka';
                            btn.className = 'btn btn-sm btn-toggle-session-ajax btn-success';
                            btn.setAttribute('data-target-state', 'open');
                        }
                    }
                }
            }
        } else {
            var errMsg = (data && data.message) ? data.message : 'Gagal memperbarui status sesi.';
            if (typeof showToast === 'function') {
                showToast(errMsg, 'danger', 4000);
            } else {
                alert(errMsg);
            }
        }
    })
    .catch(err => {
        if (typeof showToast === 'function') {
            showToast('Koneksi terputus: ' + (err.message || 'Gagal menyimpan'), 'danger', 4000);
        }
    });
}

document.addEventListener('click', function(e) {
    var btn = e.target.closest('.btn-toggle-session-ajax');
    if (!btn) return;
    var sessionId = btn.getAttribute('data-session-id');
    var targetState = btn.getAttribute('data-target-state');
    toggleSessionAjax(sessionId, targetState);
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
        if ($('#table-sesi-pretes').length) {
            var dtSesi = $('#table-sesi-pretes').DataTable({
                pageLength: 25,
                language: {
                    search: "Cari Sesi (Dosen / Ruang / Hari / Jam):",
                    lengthMenu: "Tampilkan _MENU_ data",
                    emptyTable: "Belum ada sesi pretes yang ditambahkan"
                }
            });

            // Filter Hari Pelaksanaan Sesi
            $('.btn-filter-hari').on('click', function() {
                var day = $(this).data('day') || '';
                dtSesi.column(1).search(day).draw();
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

// ── SALIN TEKS JADWAL TUTOR UNTUK WHATSAPP ──────────────────────────────────
function copyScheduleWaText() {
    var textEl = document.getElementById('raw-schedule-wa-text');
    if (!textEl) return;
    var text = textEl.value;
    var btn = document.getElementById('btn-copy-wa-top');
    navigator.clipboard.writeText(text).then(function() {
        if (btn) {
            var oldHtml = btn.innerHTML;
            btn.innerHTML = '<span>✅</span> Berhasil Disalin!';
            btn.style.background = '#059669';
            setTimeout(function() {
                btn.innerHTML = oldHtml;
                btn.style.background = '#10b981';
            }, 2500);
        }
    }).catch(function(err) {
        prompt('Salin teks jadwal berikut untuk WhatsApp:', text);
    });
}
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
