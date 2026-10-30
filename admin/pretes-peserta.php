<?php
/**
 * LPPAI Corner - Admin: Data Peserta Pretes & Kelola Credentials Tes Tulis
 */



define('PAGE_TITLE', 'Data Peserta & Credentials Tes Tulis');
require_once __DIR__ . '/../includes/auth.php';
requireAdmin();

$pdo     = getDBConnection();
$message = '';
$msgType = '';

/**
 * Helper pembuat password acak dengan pilihan tipe karakter
 * @param string $type 'campuran' | 'angka' | 'huruf'
 * @param int $length panjang karakter password (default 4)
 * @return string
 */
function generateCustomPassword($type = 'angka', $length = 4) {
    $length = max(4, min(16, (int)$length));
    
    if ($type === 'angka') {
        $pool = '0123456789';
        $pass = '';
        $max = strlen($pool) - 1;
        for ($i = 0; $i < $length; $i++) {
            $pass .= $pool[random_int(0, $max)];
        }
        return $pass;
    } elseif ($type === 'huruf') {
        // Menggunakan huruf kapital yang mudah dibaca (menghindari I dan O)
        $pool = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
        $pass = '';
        $max = strlen($pool) - 1;
        for ($i = 0; $i < $length; $i++) {
            $pass .= $pool[random_int(0, $max)];
        }
        return $pass;
    } else {
        // Campuran angka dan huruf (memastikan setidaknya ada huruf dan angka)
        $letters = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
        $digits  = '23456789';
        $all     = $letters . $digits;

        $chars = [
            $letters[random_int(0, strlen($letters) - 1)],
            $digits[random_int(0, strlen($digits) - 1)],
        ];

        for ($i = 2; $i < $length; $i++) {
            $chars[] = $all[random_int(0, strlen($all) - 1)];
        }

        shuffle($chars);
        return implode('', $chars);
    }
}



// ── Handle actions ────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $token = $_POST['csrf_token'] ?? '';
    if (!verifyCsrf($token)) {
        $message = 'Sesi tidak valid.';
        $msgType = 'danger';
    } else {
        $action = $_POST['action'];

        // ── Impor Akun & Password Peserta dari File Excel / CSV E-Ujian ──
        if ($action === 'import_excel') {
            if (!isset($_FILES['excel_file']) || $_FILES['excel_file']['error'] !== UPLOAD_ERR_OK) {
                $message = 'Pilih file Excel (.xlsx, .xls) atau CSV (.csv) untuk diunggah.';
                $msgType = 'danger';
            } else {
                $file = $_FILES['excel_file'];
                $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
                if (!in_array($ext, ['xlsx', 'xls', 'csv'])) {
                    $message = 'Format file tidak didukung. Mohon gunakan file .xlsx, .xls, atau .csv dari E-Ujian.';
                    $msgType = 'danger';
                } else {
                    require_once __DIR__ . '/../includes/import_eujian_excel.php';
                    $res = importEujianSpreadsheet($file['tmp_name'], $file['name'], $pdo);
                    if ($res['success']) {
                        $message = "<strong>Impor Berhasil:</strong> " . htmlspecialchars($res['message']);
                        $msgType = 'success';
                    } else {
                        $message = "<strong>Gagal Impor:</strong> " . htmlspecialchars($res['message']);
                        $msgType = 'danger';
                    }
                }
            }

        // ── Generate credentials otomatis untuk SEMUA peserta ──────────────
        } elseif ($action === 'generate_all' || $action === 'regenerate_all') {
            $isRegen    = ($action === 'regenerate_all');
            $userFormat = trim($_POST['user_format'] ?? 'eujian');
            $prefix     = trim($_POST['prefix'] ?? 'TES');
            $passType   = trim($_POST['pass_type'] ?? 'angka');
            $passLen    = (int)($_POST['pass_len'] ?? 4);

            if (!in_array($userFormat, ['eujian', 'prefix_nim', 'nim_only'])) {
                $userFormat = 'eujian';
            }
            if (!in_array($passType, ['angka', 'campuran', 'huruf'])) {
                $passType = 'angka';
            }
            if ($passLen < 4 || $passLen > 16) {
                $passLen = 4;
            }

            $stmt = $pdo->query("
                SELECT pr.id, u.nim 
                FROM pretes_registrations pr 
                JOIN users u ON pr.user_id = u.id
            ");
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if ($isRegen) {
                $upd = $pdo->prepare(
                    "UPDATE pretes_registrations SET username_tes = ?, password_tes = ? WHERE id = ?"
                );
            } else {
                $upd = $pdo->prepare(
                    "UPDATE pretes_registrations 
                     SET username_tes = ?, password_tes = ? 
                     WHERE id = ? AND (username_tes IS NULL OR username_tes = '' OR password_tes IS NULL OR password_tes = '')"
                );
            }

            $count = 0;
            foreach ($rows as $row) {
                $nim = trim((string)$row['nim']);
                if ($userFormat === 'eujian') {
                    $username = $nim . '.22205';
                } elseif ($userFormat === 'nim_only') {
                    $username = $nim;
                } else {
                    $username = strtoupper($prefix) . $nim;
                }

                $password = generateCustomPassword($passType, $passLen);

                $upd->execute([$username, $password, $row['id']]);
                if ($isRegen) {
                    $count++;
                } else {
                    $count += $upd->rowCount();
                }
            }

            $tipeLabel = ($passType === 'angka') ? 'Hanya Angka' : (($passType === 'huruf') ? 'Hanya Huruf' : 'Campuran Huruf & Angka');
            $formatLabel = ($userFormat === 'eujian') ? '{NIM}.22205 (E-Ujian)' : (($userFormat === 'nim_only') ? 'Hanya NIM' : 'Prefix ' . strtoupper($prefix) . ' + NIM');
            if ($isRegen) {
                $message = "Semua credentials (<strong>$count peserta</strong>) berhasil di-regenerate ulang dengan format password <strong>$tipeLabel</strong> ($passLen karakter) dan username <strong>$formatLabel</strong>!";
            } else {
                $message = "Credentials berhasil digenerate untuk <strong>$count peserta</strong> (yang belum punya credentials) dengan format password <strong>$tipeLabel</strong> ($passLen karakter) dan username <strong>$formatLabel</strong>.";
            }
            $msgType = 'success';

        // ── Set / Edit credentials & hari individual ─────────────────────
        } elseif ($action === 'set_credential') {
            $regId    = (int)($_POST['reg_id'] ?? 0);
            $username = trim($_POST['username_tes'] ?? '');
            $password = trim($_POST['password_tes'] ?? '');
            $hari     = trim($_POST['hari_pilihan'] ?? '');
            $validDays = ['Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat'];

            if ($regId <= 0) {
                $message = 'Peserta tidak valid.';
                $msgType = 'danger';
            } else {
                $updates = [];
                $params = [];
                if (in_array($hari, $validDays)) {
                    $updates[] = "hari_pilihan = ?";
                    $params[] = $hari;
                }
                if ($username !== '') {
                    $updates[] = "username_tes = ?";
                    $params[] = $username;
                }
                if ($password !== '') {
                    $updates[] = "password_tes = ?";
                    $params[] = $password;
                }

                if (!empty($updates)) {
                    $params[] = $regId;
                    $pdo->prepare("UPDATE pretes_registrations SET " . implode(', ', $updates) . " WHERE id = ?")->execute($params);
                    $message = 'Data peserta & credentials berhasil disimpan.';
                    $msgType = 'success';
                } else {
                    $message = 'Tidak ada perubahan data yang disimpan.';
                    $msgType = 'warning';
                }
            }

        // ── Update hari pelaksanaan (cepat langsung dari tabel) ───────────
        } elseif ($action === 'update_hari') {
            $regId = (int)($_POST['reg_id'] ?? 0);
            $hari  = trim($_POST['hari_pilihan'] ?? '');
            $validDays = ['Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat'];
            if ($regId > 0 && in_array($hari, $validDays)) {
                $stmtNama = $pdo->prepare("SELECT u.nama_lengkap FROM pretes_registrations pr JOIN users u ON pr.user_id = u.id WHERE pr.id = ?");
                $stmtNama->execute([$regId]);
                $namaMhs = $stmtNama->fetchColumn() ?: 'Peserta';

                $pdo->prepare("UPDATE pretes_registrations SET hari_pilihan = ? WHERE id = ?")->execute([$hari, $regId]);
                $message = "Hari pelaksanaan untuk <strong>" . sanitize($namaMhs) . "</strong> berhasil diubah menjadi <strong>$hari</strong>.";
                $msgType = 'success';
            } else {
                $message = 'Pilihan hari pelaksanaan tidak valid.';
                $msgType = 'danger';
            }

        // ── Hapus credentials individual ──────────────────────────────────
        } elseif ($action === 'clear_credential') {
            $regId = (int)($_POST['reg_id'] ?? 0);
            $pdo->prepare(
                "UPDATE pretes_registrations SET username_tes = NULL, password_tes = NULL WHERE id = ?"
            )->execute([$regId]);
            $message = 'Credentials berhasil dihapus.';
            $msgType = 'success';

        // ── Hapus data pendaftaran peserta pretes ─────────────────────────
        } elseif ($action === 'delete_peserta') {
            $regId = (int)($_POST['reg_id'] ?? 0);
            if ($regId > 0) {
                $pdo->prepare("DELETE FROM pretes_registrations WHERE id = ?")->execute([$regId]);
                $message = 'Peserta pretes berhasil dihapus.';
                $msgType = 'success';
            }

        // ── Update status kehadiran ───────────────────────────────────────
                // ── Sinkronkan dengan Data Resmi E-Ujian (414 Peserta) ───────────
        } elseif ($action === 'sync_eujian_official') {
            try {
                require_once __DIR__ . '/../includes/sync_eujian.php';
                $res = runSyncEujianOfficial($pdo);
                if (!empty($res['success'])) {
                    $message = "<strong>Berhasil!</strong> " . htmlspecialchars($res['message']);
                    $msgType = 'success';
                } else {
                    $message = "Gagal menyinkronkan data: " . htmlspecialchars($res['message'] ?? 'Terjadi kesalahan.');
                    $msgType = 'danger';
                }
            } catch (Throwable $e) {
                $message = "Kesalahan saat sinkronisasi: " . htmlspecialchars($e->getMessage());
                $msgType = 'danger';
            }
        } elseif ($action === 'update_status') {
            $regId  = (int)($_POST['reg_id'] ?? 0);
            $status = $_POST['status'] ?? '';
            if (in_array($status, ['terdaftar', 'hadir', 'tidak_hadir'])) {
                $pdo->prepare(
                    "UPDATE pretes_registrations SET status = ? WHERE id = ?"
                )->execute([$status, $regId]);
                $message = 'Status kehadiran diperbarui.';
                $msgType = 'success';
            }
        }
    }
}

// ── Auto-sync credentials resmi E-Ujian jika belum pernah diselaraskan ─────────
require_once __DIR__ . '/../includes/sync_eujian.php';
$autoSyncRes = checkAutoSyncEujian($pdo);
if ($autoSyncRes && (!empty($autoSyncRes['updated']) || !empty($autoSyncRes['matched'])) && empty($message)) {
    $countShow = $autoSyncRes['matched'] ?? $autoSyncRes['updated'];
    $message = "<strong>Sinkronisasi Otomatis E-Ujian:</strong> {$countShow} peserta telah otomatis diselaraskan dengan akun resmi E-Ujian ({NIM}.22205).";
    $msgType = 'success';
}
// ── Fetch data ─────────────────────────────────────────────────────────────────
$participants = $pdo->query("
    SELECT pr.id as reg_id, pr.user_id, pr.periode, pr.hari_pilihan, pr.jam, pr.dosen_penguji, pr.ruangan, pr.tanggal_daftar,
           pr.status, pr.username_tes, pr.password_tes,
           u.nama_lengkap, u.nim, u.program_studi, u.fakultas, u.email, u.no_hp
    FROM pretes_registrations pr
    JOIN users u ON pr.user_id = u.id
    ORDER BY u.nama_lengkap ASC
")->fetchAll();

$totalPeserta    = count($participants);
$totalBerCredentials = count(array_filter($participants, fn($p) => !empty($p['username_tes'])));

// Hitung sebaran hari, prodi, dan status peserta
$dayCounts = ['Senin' => 0, 'Selasa' => 0, 'Rabu' => 0, 'Kamis' => 0, 'Jumat' => 0];
$prodiCounts = [];
$statusCounts = ['terdaftar' => 0, 'hadir' => 0, 'tidak_hadir' => 0];

foreach ($participants as $p) {
    $dh = ucfirst(strtolower(trim($p['hari_pilihan'] ?? '')));
    if (isset($dayCounts[$dh])) {
        $dayCounts[$dh]++;
    }
    $prd = trim($p['program_studi'] ?? '');
    if ($prd !== '') {
        $prodiCounts[$prd] = ($prodiCounts[$prd] ?? 0) + 1;
    }
    $st = strtolower(trim($p['status'] ?? 'terdaftar'));
    if (isset($statusCounts[$st])) {
        $statusCounts[$st]++;
    }
}
ksort($prodiCounts);

include __DIR__ . '/../includes/header.php';
?>

<?php if ($message): ?>
    <div class="alert alert-<?= $msgType ?>"><?= $message ?></div>
<?php endif; ?>

<!-- ── Statistik Ringkas ─────────────────────────────────────────── -->
<div class="stat-grid" style="margin-bottom:24px;">
    <div class="stat-card">
        <div class="stat-icon blue">👥</div>
        <div class="stat-info">
            <h3><?= $totalPeserta ?></h3>
            <p>Total Peserta</p>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon green">🔑</div>
        <div class="stat-info">
            <h3><?= $totalBerCredentials ?></h3>
            <p>Sudah Dapat Credentials</p>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon orange">⏳</div>
        <div class="stat-info">
            <h3><?= $totalPeserta - $totalBerCredentials ?></h3>
            <p>Belum Ada Credentials</p>
        </div>
    </div>
</div>

<!-- ── Panel Kelola Kredensial: 2 Pilihan (Impor Excel & Generate Otomatis) ────── -->
<div class="card" id="card-manage-credentials" style="margin-bottom:24px;border:1.5px solid #0284c7;background:#ffffff;box-shadow:0 2px 10px rgba(2,132,199,0.08);border-radius:14px;overflow:hidden;">
    <div class="card-header" style="background:linear-gradient(135deg, #0284c7 0%, #0369a1 100%);color:#ffffff;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;padding:16px 22px;">
        <div style="display:flex;align-items:center;gap:10px;">
            <span style="font-size:22px;">🔐</span>
            <div>
                <strong style="font-size:15px;color:#fff;display:block;">Kelola Kredensial Tes Tulis Pretes CBT</strong>
                <span style="font-size:11.5px;color:#e0f2fe;">Tersedia 2 metode: Impor File Excel (E-Ujian) atau Generate Otomatis</span>
            </div>
        </div>
        <!-- Segmented Tab Switcher Buttons -->
        <div style="display:flex;background:rgba(0,0,0,0.22);border-radius:10px;padding:4px;gap:4px;flex-wrap:wrap;">
            <button type="button" id="tab-btn-import" onclick="switchCredTab('import')"
                style="background:#ffffff;color:#0369a1;border:none;padding:7px 16px;border-radius:8px;font-size:12.5px;font-weight:800;cursor:pointer;display:inline-flex;align-items:center;gap:6px;transition:all 0.2s;box-shadow:0 1px 3px rgba(0,0,0,0.15);">
                📤 Pilihan 1: Impor Excel / CSV
            </button>
            <button type="button" id="tab-btn-generate" onclick="switchCredTab('generate')"
                style="background:transparent;color:rgba(255,255,255,0.85);border:none;padding:7px 16px;border-radius:8px;font-size:12.5px;font-weight:600;cursor:pointer;display:inline-flex;align-items:center;gap:6px;transition:all 0.2s;">
                ⚡ Pilihan 2: Generate Otomatis
            </button>
        </div>
    </div>
    
    <!-- ── KONTEN TAB 1: IMPOR EXCEL / CSV ── -->
    <div id="tab-content-import" class="card-body" style="padding:22px 24px;">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:18px;flex-wrap:wrap;gap:10px;">
            <div style="font-size:13px;color:#334155;">
                <strong style="color:#0f172a;">Metode Impor Excel:</strong> Gunakan data resmi dari platform E-Ujian.
            </div>
            <a href="download-template-eujian.php" data-no-spa="true"
               class="btn btn-sm"
               style="background:#0284c7;color:#fff;font-weight:600;font-size:12px;padding:6px 14px;border-radius:8px;text-decoration:none;display:inline-flex;align-items:center;gap:6px;">
                📗 Unduh Template Format Excel (.xlsx)
            </a>
        </div>

        <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(320px, 1fr));gap:24px;align-items:start;">
            <!-- Kolom Kiri: Form Upload Excel E-Ujian -->
            <div style="background:#f8fafc;border:1.5px dashed #cbd5e1;border-radius:12px;padding:20px;">
                <div style="display:flex;align-items:center;gap:8px;margin-bottom:8px;">
                    <span style="font-size:18px;">📤</span>
                    <strong style="font-size:14px;color:#0f172a;">Unggah File Excel (.xlsx / .csv) E-Ujian</strong>
                </div>
                <p style="font-size:12.5px;color:#64748b;line-height:1.5;margin:0 0 14px;">
                    Unggah file hasil ekspor dari <strong>E-Ujian</strong> (misal <code>PESERTA_xxxx.xlsx</code>) atau file spreadsheet lainnya. Sistem otomatis mencocokkan NIM dan menyelaraskan username (<code>{NIM}.22205</code>), password (kode akses), dan kelompok hari.
                </p>

                <form method="POST" enctype="multipart/form-data" data-no-spa="true" style="margin:0;">
                    <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                    <input type="hidden" name="action"     value="import_excel">

                    <div style="margin-bottom:14px;">
                        <input type="file" name="excel_file" id="excelFileInput" accept=".xlsx,.xls,.csv" required
                            style="width:100%;box-sizing:border-box;padding:9px 12px;background:#ffffff;border:1.5px solid #cbd5e1;border-radius:8px;font-size:13px;color:#334155;cursor:pointer;">
                        <span style="display:block;font-size:11.5px;color:#64748b;margin-top:4px;">
                            Mendukung file: <strong>.xlsx</strong>, <strong>.xls</strong>, atau <strong>.csv</strong> dari E-Ujian
                        </span>
                    </div>

                    <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
                        <button type="submit" class="btn"
                            style="background:#0284c7;color:#fff;border:none;font-weight:700;font-size:13px;padding:10px 20px;border-radius:8px;cursor:pointer;display:inline-flex;align-items:center;gap:6px;box-shadow:0 2px 6px rgba(2,132,199,0.3);transition:all 0.2s;">
                            📤 Unggah & Impor Password
                        </button>
                    </div>
                </form>
            </div>

            <!-- Kolom Kanan: Info Format & Tombol Sinkronisasi Cepat -->
            <div style="display:flex;flex-direction:column;justify-content:space-between;gap:14px;">
                <div>
                    <div style="display:flex;align-items:center;gap:8px;margin-bottom:8px;">
                        <span style="font-size:18px;">ℹ️</span>
                        <strong style="font-size:14px;color:#0f172a;">Ketentuan Format Akun E-Ujian</strong>
                    </div>
                    <ul style="margin:0;padding-left:18px;font-size:12.5px;color:#334155;line-height:1.65;">
                        <li><strong>Username:</strong> Mengikuti format resmi E-Ujian <code>{NIM}.22205</code> (kolom <em>NOMOR PESERTA</em> atau <em>USERNAME LOGIN</em>).</li>
                        <li><strong>Password:</strong> Kode akses 4-digit resmi dari E-Ujian (kolom <em>KODE AKSES</em>).</li>
                        <li><strong>Hari Ujian:</strong> Mengikuti kelompok hari pretes (kolom <em>KELOMPOK</em>: Senin s.d. Jumat).</li>
                    </ul>
                </div>

                <div style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:10px;padding:14px 16px;">
                    <div style="display:justify-between;align-items:center;display:flex;flex-wrap:wrap;gap:10px;">
                        <div>
                            <strong style="font-size:13px;color:#1e40af;display:block;">Data Resmi Bawaan (414 Peserta)</strong>
                            <span style="font-size:12px;color:#3b82f6;">Sudah terpasang di sistem. Klik tombol untuk sinkronkan kembali jika diperlukan.</span>
                        </div>
                        <form method="POST" style="margin:0;" onsubmit="return confirm('Sinkronkan kembali data 414 akun resmi E-Ujian bawaan ke database peserta pretes?');">
                            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                            <input type="hidden" name="action"     value="sync_eujian_official">
                            <button type="submit" class="btn btn-sm"
                                style="background:#1d4ed8;color:#fff;border:none;font-weight:700;font-size:12px;padding:8px 16px;border-radius:8px;cursor:pointer;display:inline-flex;align-items:center;gap:6px;box-shadow:0 1px 3px rgba(29,78,216,0.3);">
                                🔄 Sinkronkan 414 Akun Bawaan
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- ── KONTEN TAB 2: GENERATE OTOMATIS MASSAL ── -->
    <div id="tab-content-generate" class="card-body" style="padding:22px 24px;display:none;">
        <p style="color:#475569;font-size:13.5px;margin:0 0 18px;line-height:1.55;">
            Sistem akan men-generate <strong>username</strong> dan <strong>password</strong> secara otomatis untuk seluruh peserta pretes. Anda dapat menyesuaikan format username, panjang karakter, serta tipe karakter password (Hanya Angka, Campuran, atau Hanya Huruf).<br>
            <span style="color:#64748b;font-size:12.5px;">💡 Kredensial yang sudah ada <strong>tidak akan ditimpa</strong> saat klik "Generate Baru". Gunakan "Regenerate Semua (Timpa)" jika ingin memperbarui seluruhnya.</span>
        </p>

        <!-- Panel Opsi Pengaturan Generate -->
        <div style="background:#f8fafc;border:1.5px solid #e2e8f0;border-radius:12px;padding:18px 20px;margin-bottom:18px;">
            <!-- Baris 1: Format Username & Prefix -->
            <div style="margin-bottom:16px;">
                <label style="font-size:13px;font-weight:700;color:#334155;margin-bottom:8px;display:block;">
                    👤 Pilihan Format Username:
                </label>
                <div style="display:flex;gap:12px;flex-wrap:wrap;align-items:center;">
                    <label class="user-fmt-card" id="card-user-eujian"
                        style="display:inline-flex;align-items:center;gap:8px;background:#eff6ff;border:2px solid #2563eb;padding:9px 14px;border-radius:10px;cursor:pointer;font-size:13px;font-weight:700;color:#1d4ed8;transition:all 0.2s;">
                        <input type="radio" name="user_format_select" value="eujian" checked onchange="updateUserFmtUI()"
                            style="accent-color:#2563eb;width:17px;height:17px;cursor:pointer;">
                        <span>🎓 Format E-Ujian (<code>{NIM}.22205</code>)</span>
                    </label>

                    <label class="user-fmt-card" id="card-user-prefix_nim"
                        style="display:inline-flex;align-items:center;gap:8px;background:#ffffff;border:2px solid #cbd5e1;padding:9px 14px;border-radius:10px;cursor:pointer;font-size:13px;font-weight:600;color:#334155;transition:all 0.2s;">
                        <input type="radio" name="user_format_select" value="prefix_nim" onchange="updateUserFmtUI()"
                            style="accent-color:#2563eb;width:17px;height:17px;cursor:pointer;">
                        <span>🏷️ Prefix + NIM (misal: <code>TES24042062</code>)</span>
                    </label>

                    <label class="user-fmt-card" id="card-user-nim_only"
                        style="display:inline-flex;align-items:center;gap:8px;background:#ffffff;border:2px solid #cbd5e1;padding:9px 14px;border-radius:10px;cursor:pointer;font-size:13px;font-weight:600;color:#334155;transition:all 0.2s;">
                        <input type="radio" name="user_format_select" value="nim_only" onchange="updateUserFmtUI()"
                            style="accent-color:#2563eb;width:17px;height:17px;cursor:pointer;">
                        <span>🔢 Hanya NIM (misal: <code>24042062</code>)</span>
                    </label>
                </div>

                <div id="prefix-input-wrap" style="display:none;margin-top:12px;max-width:320px;">
                    <label style="font-size:12.5px;font-weight:700;color:#334155;margin-bottom:4px;display:block;">Prefix Username Huruf Depan</label>
                    <input type="text" id="prefix-input" value="TES" placeholder="contoh: TES"
                        oninput="updateSamplePreview()"
                        style="padding:8px 12px;border:2px solid #cbd5e1;border-radius:8px;font-size:13.5px;width:100%;background:#fff;outline:none;font-weight:600;color:#1e293b;box-sizing:border-box;">
                </div>
            </div>

            <hr style="border:none;border-top:1px dashed #cbd5e1;margin:16px 0;">

            <!-- Baris 2: Pilihan Tipe Password & Panjang Karakter -->
            <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(280px, 1fr));gap:18px;">
                <!-- Opsi Tipe Password -->
                <div>
                    <label style="font-size:13px;font-weight:700;color:#334155;margin-bottom:8px;display:block;">
                        🔑 Pilihan Format Karakter Password:
                    </label>
                    <div style="display:flex;flex-direction:column;gap:8px;">
                        <!-- Opsi: Angka -->
                        <label class="pass-type-card" id="card-pass-angka"
                            style="display:inline-flex;align-items:center;gap:8px;background:#f0fdf4;border:2px solid #16a34a;padding:9px 14px;border-radius:10px;cursor:pointer;font-size:13px;font-weight:700;color:#15803d;transition:all 0.2s;">
                            <input type="radio" name="pass_type_select" value="angka" checked onchange="updatePassTypeUI()"
                                style="accent-color:#16a34a;width:17px;height:17px;cursor:pointer;">
                            <span>🔢 <strong>Hanya Angka (0-9)</strong> <span style="font-size:11.5px;font-weight:normal;opacity:0.85;">(Cocok untuk Kode Akses E-Ujian)</span></span>
                        </label>

                        <!-- Opsi: Campuran -->
                        <label class="pass-type-card" id="card-pass-campuran"
                            style="display:inline-flex;align-items:center;gap:8px;background:#ffffff;border:2px solid #cbd5e1;padding:9px 14px;border-radius:10px;cursor:pointer;font-size:13px;font-weight:600;color:#334155;transition:all 0.2s;">
                            <input type="radio" name="pass_type_select" value="campuran" onchange="updatePassTypeUI()"
                                style="accent-color:#16a34a;width:17px;height:17px;cursor:pointer;">
                            <span>🔠🔢 <strong>Campuran (Huruf Kapital & Angka)</strong></span>
                        </label>

                        <!-- Opsi: Huruf -->
                        <label class="pass-type-card" id="card-pass-huruf"
                            style="display:inline-flex;align-items:center;gap:8px;background:#ffffff;border:2px solid #cbd5e1;padding:9px 14px;border-radius:10px;cursor:pointer;font-size:13px;font-weight:600;color:#334155;transition:all 0.2s;">
                            <input type="radio" name="pass_type_select" value="huruf" onchange="updatePassTypeUI()"
                                style="accent-color:#16a34a;width:17px;height:17px;cursor:pointer;">
                            <span>🔤 <strong>Hanya Huruf (A-Z)</strong></span>
                        </label>
                    </div>
                </div>

                <!-- Opsi Panjang Karakter Password -->
                <div>
                    <label style="font-size:13px;font-weight:700;color:#334155;margin-bottom:8px;display:block;">
                        📏 Panjang Karakter Password:
                    </label>
                    <select id="pass-len-input" onchange="updateSamplePreview()"
                        style="padding:10px 14px;border:2px solid #cbd5e1;border-radius:10px;font-size:13.5px;width:100%;background:#fff;outline:none;font-weight:600;color:#1e293b;cursor:pointer;box-sizing:border-box;">
                        <option value="4" selected>4 Digit / Karakter (Standar Kode Akses E-Ujian)</option>
                        <option value="6">6 Karakter (Standar Aman)</option>
                        <option value="8">8 Karakter</option>
                        <option value="10">10 Karakter</option>
                        <option value="12">12 Karakter</option>
                    </select>
                    <span style="display:block;font-size:12px;color:#64748b;margin-top:8px;line-height:1.5;">
                        Pilih <strong>4 Digit</strong> jika peserta akan login ke sistem CBT E-Ujian yang meminta 4 digit kode akses.
                    </span>
                </div>
            </div>
        </div>

        <!-- Tombol Eksekusi Massal -->
        <div style="display:flex;gap:12px;flex-wrap:wrap;align-items:center;">
            <form method="POST" id="form-gen-all" style="display:inline;" onsubmit="syncGenFields(this)">
                <input type="hidden" name="csrf_token"  value="<?= csrfToken() ?>">
                <input type="hidden" name="action"      value="generate_all">
                <input type="hidden" name="user_format" id="user-format-gen" value="eujian">
                <input type="hidden" name="prefix"      id="prefix-gen"      value="TES">
                <input type="hidden" name="pass_type"   id="pass-type-gen"   value="angka">
                <input type="hidden" name="pass_len"    id="pass-len-gen"    value="4">
                <button type="submit" class="btn" style="background:#0f766e;color:#fff;border:none;padding:11px 22px;border-radius:8px;font-weight:700;font-size:13.5px;cursor:pointer;display:inline-flex;align-items:center;gap:8px;box-shadow:0 2px 6px rgba(15,118,110,0.3);">
                    🔑 Generate Credentials Baru
                </button>
            </form>
            <form method="POST" id="form-regen-all" style="display:inline;"
                  onsubmit="syncGenFields(this); return confirm('PERINGATAN: Yakin ingin me-regenerate semua credentials? Tindakan ini akan MENIMPA semua username dan password peserta yang sudah ada dengan format yang Anda pilih saat ini!');">
                <input type="hidden" name="csrf_token"  value="<?= csrfToken() ?>">
                <input type="hidden" name="action"      value="regenerate_all">
                <input type="hidden" name="user_format" id="user-format-regen" value="eujian">
                <input type="hidden" name="prefix"      id="prefix-regen"      value="TES">
                <input type="hidden" name="pass_type"   id="pass-type-regen"   value="angka">
                <input type="hidden" name="pass_len"    id="pass-len-regen"    value="4">
                <button type="submit" class="btn btn-sm btn-danger" style="padding:11px 22px;border-radius:8px;font-weight:700;font-size:13.5px;cursor:pointer;display:inline-flex;align-items:center;gap:8px;">
                    🔄 Regenerate Semua (Timpa)
                </button>
            </form>
        </div>

        <!-- Live Preview Contoh Hasil -->
        <div id="example-preview-box" style="margin-top:14px;padding:12px 16px;background:#f0fdf4;border-radius:10px;font-size:13px;color:#166534;border:1px solid #bbf7d0;">
            💡 <strong>Contoh Hasil:</strong> Username: <code>24042062.22205</code> | Password: <code>7402</code> (Hanya Angka, 4 karakter)
        </div>
    </div>
</div>

<!-- ── Tabel Peserta & Credentials ──────────────────────────────── -->
<div class="card">
    <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;">
        <span>📋 Data Peserta & Credentials Tes Tulis (<?= $totalPeserta ?> peserta)</span>
        <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
            <button type="button" class="btn btn-sm" onclick="switchCredTab('import'); document.getElementById('card-manage-credentials').scrollIntoView({behavior:'smooth'});"
               style="background:#0284c7;color:#ffffff;border:1px solid #0369a1;font-weight:700;font-size:12.5px;padding:7px 14px;border-radius:8px;cursor:pointer;display:inline-flex;align-items:center;gap:6px;box-shadow:0 1px 3px rgba(2,132,199,0.25);">
                📤 Impor Excel
            </button>
            <button type="button" class="btn btn-sm" onclick="switchCredTab('generate'); document.getElementById('card-manage-credentials').scrollIntoView({behavior:'smooth'});"
               style="background:#0f766e;color:#ffffff;border:1px solid #0d9488;font-weight:700;font-size:12.5px;padding:7px 14px;border-radius:8px;cursor:pointer;display:inline-flex;align-items:center;gap:6px;box-shadow:0 1px 3px rgba(15,118,110,0.25);">
                ⚡ Generate Otomatis
            </button>
            <a href="pretes-peserta-export.php" class="btn btn-sm"
               style="background:#16a34a;color:#ffffff;border:1px solid #15803d;font-weight:700;font-size:12.5px;padding:7px 14px;border-radius:8px;text-decoration:none;display:inline-flex;align-items:center;gap:6px;box-shadow:0 1px 3px rgba(22,163,74,0.25);">
                📗 Halaman Export ke Excel
            </a>
            <button type="button" class="btn btn-sm" onclick="openExportModal()"
               style="background:#334155;color:#ffffff;border:1px solid #1e293b;font-weight:700;font-size:12.5px;padding:7px 14px;border-radius:8px;cursor:pointer;display:inline-flex;align-items:center;gap:6px;box-shadow:0 1px 3px rgba(51,65,85,0.25);">
                📥 Export ke Excel
            </button>
        </div>
    </div>
    <div class="card-body">
        <?php if (empty($participants)): ?>
            <div class="empty-state">
                <div class="icon">👥</div>
                <h3>Belum ada peserta</h3>
                <p>Belum ada mahasiswa yang mendaftar pretes.</p>
            </div>
        <?php else: ?>
        <!-- ── Filter Multi-Kolom di Atas Tabel Peserta Pretes ───────────── -->
        <div class="filter-panel" style="background:#ffffff;border:1.5px solid #e2e8f0;border-radius:12px;padding:18px 20px;margin-bottom:18px;box-shadow:0 1px 3px rgba(0,0,0,0.04);">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;flex-wrap:wrap;gap:10px;">
                <div style="display:flex;align-items:center;gap:10px;">
                    <span style="display:inline-flex;align-items:center;justify-content:center;width:32px;height:32px;border-radius:8px;background:#eff6ff;color:#2563eb;font-size:16px;">
                        🔍
                    </span>
                    <div>
                        <strong style="font-size:14px;color:#0f172a;display:block;">Filter Data Peserta (Sesuai Kolom Tabel)</strong>
                        <span style="font-size:12px;color:#64748b;">Filter instan berdasarkan Nama, NIM, Prodi, Hari Pelaksanaan, Status Kehadiran, atau Status Credentials</span>
                    </div>
                    <span id="filter-active-badge" style="display:none;background:#dbeafe;color:#1e40af;font-size:11px;font-weight:700;padding:3px 10px;border-radius:20px;border:1px solid #bfdbfe;margin-left:6px;">
                        0 Filter Aktif
                    </span>
                </div>
                <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
                    <button type="button" id="btn-export-filtered" class="btn btn-sm" onclick="exportCurrentFiltered('xlsx')" data-no-spa="true"
                        title="Unduh file Excel (.xlsx) dengan filter yang sedang aktif"
                        style="background:#15803d;border:1px solid #166534;color:#ffffff;font-weight:700;font-size:12px;padding:6px 14px;border-radius:8px;cursor:pointer;display:inline-flex;align-items:center;gap:6px;transition:all 0.2s;">
                        📥 Export Sesuai Filter (.xlsx)
                    </button>
                    <button type="button" id="btn-reset-filters" class="btn btn-sm" style="background:#f1f5f9;border:1px solid #cbd5e1;color:#334155;font-weight:600;font-size:12px;padding:6px 14px;border-radius:8px;cursor:pointer;display:inline-flex;align-items:center;gap:6px;transition:all 0.2s;">
                        🔄 Reset Semua Filter
                    </button>
                </div>
            </div>

            <!-- Grid Input Filter Sesuai Nama Kolom -->
            <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(180px, 1fr));gap:12px;">
                <!-- Kolom 1: Nama -->
                <div>
                    <label for="filter-nama" style="display:block;font-size:12px;font-weight:600;color:#475569;margin-bottom:5px;">
                        👤 Nama Mahasiswa
                    </label>
                    <input type="text" id="filter-nama" placeholder="Cari nama..."
                        style="width:100%;padding:8px 12px;border:1.5px solid #cbd5e1;border-radius:8px;font-size:13px;background:#f8fafc;outline:none;box-sizing:border-box;transition:border-color 0.2s;"
                        onfocus="this.style.borderColor='#3b82f6';this.style.background='#fff'"
                        onblur="this.style.borderColor='#cbd5e1';if(!this.value)this.style.background='#f8fafc'">
                </div>

                <!-- Kolom 2: NIM -->
                <div>
                    <label for="filter-nim" style="display:block;font-size:12px;font-weight:600;color:#475569;margin-bottom:5px;">
                        🔢 NIM
                    </label>
                    <input type="text" id="filter-nim" placeholder="Cari NIM..."
                        style="width:100%;padding:8px 12px;border:1.5px solid #cbd5e1;border-radius:8px;font-size:13px;background:#f8fafc;outline:none;box-sizing:border-box;transition:border-color 0.2s;"
                        onfocus="this.style.borderColor='#3b82f6';this.style.background='#fff'"
                        onblur="this.style.borderColor='#cbd5e1';if(!this.value)this.style.background='#f8fafc'">
                </div>

                <!-- Kolom 3: Prodi -->
                <div>
                    <label for="filter-prodi" style="display:block;font-size:12px;font-weight:600;color:#475569;margin-bottom:5px;">
                        🎓 Program Studi
                    </label>
                    <select id="filter-prodi"
                        style="width:100%;padding:8px 12px;border:1.5px solid #cbd5e1;border-radius:8px;font-size:13px;background:#f8fafc;color:#1e293b;outline:none;box-sizing:border-box;cursor:pointer;transition:border-color 0.2s;">
                        <option value="">Semua Prodi (<?= count($prodiCounts) ?>)</option>
                        <?php foreach ($prodiCounts as $prd => $cnt): ?>
                            <option value="<?= sanitize($prd) ?>"><?= sanitize($prd) ?> (<?= $cnt ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Kolom 4: Hari / Periode -->
                <div>
                    <label for="filter-hari" style="display:block;font-size:12px;font-weight:600;color:#475569;margin-bottom:5px;">
                        📅 Hari Pelaksanaan
                    </label>
                    <select id="filter-hari"
                        style="width:100%;padding:8px 12px;border:1.5px solid #cbd5e1;border-radius:8px;font-size:13px;background:#f8fafc;color:#1e293b;outline:none;box-sizing:border-box;cursor:pointer;transition:border-color 0.2s;">
                        <option value="">Semua Hari (<?= $totalPeserta ?>)</option>
                        <?php foreach (['Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat'] as $day): ?>
                            <option value="<?= $day ?>"><?= $day ?> (<?= $dayCounts[$day] ?? 0 ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Kolom 5: Status Hadir -->
                <div>
                    <label for="filter-status" style="display:block;font-size:12px;font-weight:600;color:#475569;margin-bottom:5px;">
                        📋 Status Hadir
                    </label>
                    <select id="filter-status"
                        style="width:100%;padding:8px 12px;border:1.5px solid #cbd5e1;border-radius:8px;font-size:13px;background:#f8fafc;color:#1e293b;outline:none;box-sizing:border-box;cursor:pointer;transition:border-color 0.2s;">
                        <option value="">Semua Status Kehadiran</option>
                        <option value="terdaftar">Terdaftar (<?= $statusCounts['terdaftar'] ?? 0 ?>)</option>
                        <option value="hadir">Hadir (<?= $statusCounts['hadir'] ?? 0 ?>)</option>
                        <option value="tidak_hadir">Tidak Hadir (<?= $statusCounts['tidak_hadir'] ?? 0 ?>)</option>
                    </select>
                </div>

                <!-- Kolom 6: Username Tes / Credentials -->
                <div>
                    <label for="filter-cred" style="display:block;font-size:12px;font-weight:600;color:#475569;margin-bottom:5px;">
                        🔑 Status Credentials
                    </label>
                    <select id="filter-cred"
                        style="width:100%;padding:8px 12px;border:1.5px solid #cbd5e1;border-radius:8px;font-size:13px;background:#f8fafc;color:#1e293b;outline:none;box-sizing:border-box;cursor:pointer;transition:border-color 0.2s;">
                        <option value="">Semua Status Credentials</option>
                        <option value="has_cred">Sudah Ada (<?= $totalBerCredentials ?>)</option>
                        <option value="no_cred">Belum Ada (<?= $totalPeserta - $totalBerCredentials ?>)</option>
                    </select>
                </div>
            </div>
        </div>

        <!-- Filter & Sebaran Hari Peserta Pretes (Shortcut Badges) -->
        <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:16px;align-items:center;background:#f8fafc;padding:10px 14px;border-radius:10px;border:1px solid #e2e8f0;">
            <span style="font-size:13px;font-weight:600;color:#475569;">📅 Distribusi Hari:</span>
            <button type="button" class="btn-filter-day" data-day="" style="padding:4px 12px;border-radius:20px;border:1px solid #cbd5e1;background:#e2e8f0;font-size:12px;cursor:pointer;font-weight:600;color:#334155;">
                Semua (<?= $totalPeserta ?>)
            </button>
            <?php foreach (['Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat'] as $day): ?>
                <button type="button" class="btn-filter-day" data-day="<?= $day ?>" style="padding:4px 12px;border-radius:20px;border:1px solid #cbd5e1;background:#fff;font-size:12px;cursor:pointer;font-weight:600;color:#334155;">
                    <?= $day ?>: <span style="color:#2563eb;font-weight:bold;"><?= $dayCounts[$day] ?? 0 ?></span>
                </button>
            <?php endforeach; ?>
            <span style="margin-left:auto;font-size:12px;color:#64748b;">
                💡 <em>Admin dapat merubah hari langsung pada dropdown tabel atau klik tombol Edit.</em>
            </span>
        </div>

        <div class="table-responsive">
            <table id="table-peserta-pretes">
                <thead>
                    <tr>
                        <th>No</th>
                        <th>Nama</th>
                        <th>NIM</th>
                        <th>Prodi</th>
                        <th>Hari / Periode</th>
                        <th>Status Hadir</th>
                        <th style="min-width:140px;">Username Tes</th>
                        <th style="min-width:120px;">Password Tes</th>
                        <th style="min-width:160px;">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($participants as $i => $p): ?>
                    <tr id="row-<?= $p['reg_id'] ?>">
                        <td><?= $i + 1 ?></td>
                        <td data-search="<?= sanitize($p['nama_lengkap']) ?>"><strong><?= sanitize($p['nama_lengkap']) ?></strong></td>
                        <td data-search="<?= sanitize($p['nim']) ?>"><?= sanitize($p['nim']) ?></td>
                        <td data-search="<?= sanitize($p['program_studi']) ?>"><?= sanitize($p['program_studi']) ?></td>
                        <?php 
                        $validDays = ['Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat'];
                        $curHari = ucfirst(strtolower(trim($p['hari_pilihan'] ?? '')));
                        ?>
                        <td data-order="<?= sanitize($curHari) ?>" data-search="<?= sanitize($curHari) ?>">
                            <!-- Dropdown ubah hari pelaksanaan langsung -->
                            <form method="POST" style="display:inline-flex;align-items:center;gap:6px;margin:0;">
                                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                                <input type="hidden" name="action"     value="update_hari">
                                <input type="hidden" name="reg_id"     value="<?= $p['reg_id'] ?>">
                                <select name="hari_pilihan" onchange="this.form.submit()"
                                    title="Klik untuk langsung merubah hari pelaksanaan"
                                    style="padding:5px 8px;border-radius:6px;border:1.5px solid #cbd5e1;font-size:12px;font-weight:600;color:#1e293b;background-color:#fff;cursor:pointer;">
                                    <?php foreach ($validDays as $day): ?>
                                        <option value="<?= $day ?>" <?= ($curHari === $day) ? 'selected' : '' ?>>
                                            <?= $day ?>
                                        </option>
                                    <?php endforeach; ?>
                                    <?php if (!in_array($curHari, $validDays) && !empty($p['hari_pilihan'])): ?>
                                        <option value="<?= sanitize($p['hari_pilihan']) ?>" selected><?= sanitize($p['hari_pilihan']) ?></option>
                                    <?php endif; ?>
                                </select>
                                <span style="font-size:11px;color:#64748b;white-space:nowrap;">
                                    (<?= sanitize($p['jam'] ?? '13.00 WIB') ?>)
                                </span>
                            </form>
                        </td>
                        <td data-order="<?= sanitize($p['status']) ?>" data-search="<?= sanitize($p['status']) ?>">
                            <!-- Dropdown status hadir -->
                            <form method="POST" style="display:inline;">
                                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                                <input type="hidden" name="action"  value="update_status">
                                <input type="hidden" name="reg_id"  value="<?= $p['reg_id'] ?>">
                                <select name="status" onchange="this.form.submit()"
                                    style="padding:4px 8px;border-radius:6px;border:1px solid #ddd;font-size:12px;">
                                    <?php foreach (['terdaftar', 'hadir', 'tidak_hadir'] as $st): ?>
                                        <option value="<?= $st ?>" <?= $p['status'] === $st ? 'selected' : '' ?>>
                                            <?= ucfirst(str_replace('_', ' ', $st)) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </form>
                        </td>
                        <!-- Username -->
                        <td data-order="<?= !empty($p['username_tes']) ? 1 : 0 ?>" data-search="<?= !empty($p['username_tes']) ? ('has_cred ' . sanitize($p['username_tes'])) : 'no_cred' ?>">
                            <?php if (!empty($p['username_tes'])): ?>
                                <code style="background:#f0f7ff;padding:3px 8px;border-radius:6px;font-size:13px;color:#1a73e8;">
                                    <?= sanitize($p['username_tes']) ?>
                                </code>
                            <?php else: ?>
                                <span style="color:#ccc;font-size:12px;font-style:italic;">— belum ada —</span>
                            <?php endif; ?>
                        </td>
                        <!-- Password -->
                        <td>
                            <?php if (!empty($p['password_tes'])): ?>
                                <span class="password-cell" style="position:relative;display:inline-flex;align-items:center;gap:6px;">
                                    <!-- Hidden encoded password -->
                                    <input type="hidden"
                                        class="enc-pass-data"
                                        data-reg-id="<?= $p['reg_id'] ?>"
                                        value="<?= base64_encode($p['password_tes']) ?>">
                                    <code id="pass-display-<?= $p['reg_id'] ?>"
                                        style="background:#fff3e0;padding:3px 8px;border-radius:6px;font-size:13px;color:#e65100;letter-spacing:2px;">
                                        ••••••
                                    </code>
                                    <button type="button"
                                        id="pass-btn-<?= $p['reg_id'] ?>"
                                        style="background:none;border:none;cursor:pointer;font-size:14px;padding:0;"
                                        title="Tampilkan password"
                                        onclick="openPasswordModal(<?= $p['reg_id'] ?>)">
                                        👁️
                                    </button>
                                </span>
                            <?php else: ?>
                                <span style="color:#ccc;font-size:12px;font-style:italic;">— belum ada —</span>
                            <?php endif; ?>
                        </td>
                        <!-- Aksi -->
                        <td style="white-space:nowrap;">
                            <div style="display:flex;gap:6px;align-items:center;">
                                <!-- Tombol Edit Data & Credentials -->
                                <button type="button"
                                    class="btn btn-sm btn-warning btn-edit-cred"
                                    data-reg-id="<?= $p['reg_id'] ?>"
                                    data-nama="<?= htmlspecialchars($p['nama_lengkap'], ENT_QUOTES, 'UTF-8') ?>"
                                    data-hari="<?= htmlspecialchars($curHari, ENT_QUOTES, 'UTF-8') ?>"
                                    data-username="<?= htmlspecialchars($p['username_tes'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                                    data-password="<?= htmlspecialchars($p['password_tes'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                                    title="Edit Hari Pelaksanaan & Credentials">
                                    ✏️ Edit
                                </button>
                                <!-- Tombol Hapus Peserta -->
                                <form method="POST" style="display:inline;margin:0;" onsubmit="return confirm('Apakah Anda yakin ingin menghapus peserta <?= htmlspecialchars(addslashes($p['nama_lengkap']), ENT_QUOTES, 'UTF-8') ?> dari pendaftaran pretes? Tindakan ini tidak dapat dibatalkan.');">
                                    <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                                    <input type="hidden" name="action" value="delete_peserta">
                                    <input type="hidden" name="reg_id" value="<?= $p['reg_id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-danger" title="Hapus Peserta Pretes">
                                        🗑️ Hapus
                                    </button>
                                </form>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- ── Modal Edit Data Peserta & Credentials ───────────────────── -->
<div id="modal-edit-cred" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.55);z-index:9999;align-items:center;justify-content:center;">
    <div style="background:#fff;border-radius:16px;padding:32px;width:100%;max-width:460px;box-shadow:0 8px 40px rgba(0,0,0,0.25);">
        <h3 style="margin-bottom:4px;">✏️ Edit Data & Credentials Peserta</h3>
        <p id="modal-name" style="color:var(--text-muted);font-size:13px;margin-bottom:20px;"></p>
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
            <input type="hidden" name="action"  value="set_credential">
            <input type="hidden" name="reg_id"  id="modal-reg-id">
            
            <div class="form-group" style="margin-bottom:16px;">
                <label style="font-size:13px;font-weight:600;color:#334155;margin-bottom:6px;display:block;">Hari Pelaksanaan Pretes *</label>
                <select name="hari_pilihan" id="modal-hari" required
                    style="width:100%;padding:10px 14px;border:2px solid #e0e0e0;border-radius:10px;font-size:14px;background:#fff;color:#1e293b;outline:none;">
                    <option value="Senin">Senin</option>
                    <option value="Selasa">Selasa</option>
                    <option value="Rabu">Rabu</option>
                    <option value="Kamis">Kamis</option>
                    <option value="Jumat">Jumat</option>
                </select>
            </div>

            <div class="form-group" style="margin-bottom:16px;">
                <label style="font-size:13px;font-weight:600;color:#334155;margin-bottom:6px;display:block;">Username Tes</label>
                <input type="text" name="username_tes" id="modal-username" placeholder="contoh: 24042062.22205"
                    style="width:100%;padding:10px 14px;border:2px solid #e0e0e0;border-radius:10px;font-size:14px;">
            </div>
            <div class="form-group" style="margin-bottom:16px;">
                <label style="font-size:13px;font-weight:600;color:#334155;margin-bottom:6px;display:block;">Password Tes (Kode Akses E-Ujian / Password)</label>
                <div style="position:relative;margin-bottom:8px;">
                    <input type="text" name="password_tes" id="modal-password" placeholder="contoh: 7402"
                        style="width:100%;padding:10px 14px;padding-right:75px;border:2px solid #e0e0e0;border-radius:10px;font-size:14px;box-sizing:border-box;">
                    <button type="button" onclick="generatePasswordModal()"
                        style="position:absolute;right:8px;top:50%;transform:translateY(-50%);background:#0284c7;color:#fff;border:none;border-radius:6px;padding:6px 12px;cursor:pointer;font-size:12px;font-weight:700;">
                        🎲 Acak
                    </button>
                </div>
                <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;background:#f8fafc;padding:6px 10px;border-radius:8px;border:1px solid #e2e8f0;">
                    <span style="font-size:11.5px;color:#64748b;font-weight:600;">Format Acak:</span>
                    <label style="font-size:11.5px;color:#334155;cursor:pointer;display:inline-flex;align-items:center;gap:4px;">
                        <input type="radio" name="modal_pass_type" value="angka" checked style="accent-color:#0284c7;">
                        <span>Angka (4 digit)</span>
                    </label>
                    <label style="font-size:11.5px;color:#334155;cursor:pointer;display:inline-flex;align-items:center;gap:4px;">
                        <input type="radio" name="modal_pass_type" value="campuran" style="accent-color:#0284c7;">
                        <span>Campuran (6 kar)</span>
                    </label>
                    <label style="font-size:11.5px;color:#334155;cursor:pointer;display:inline-flex;align-items:center;gap:4px;">
                        <input type="radio" name="modal_pass_type" value="huruf" style="accent-color:#0284c7;">
                        <span>Huruf (6 kar)</span>
                    </label>
                </div>
                <span style="font-size:11.5px;color:#64748b;display:block;margin-top:4px;">Kode akses 4-digit sesuai data resmi dari E-Ujian atau format acak.</span>
            </div>
            <div style="display:flex;gap:10px;justify-content:space-between;align-items:center;margin-top:20px;">
                <button type="button" class="btn btn-sm btn-outline-danger" id="btn-modal-clear" style="display:none;" onclick="clearCredFromModal()">
                    🗑️ Kosongkan Credentials
                </button>
                <div style="display:flex;gap:10px;margin-left:auto;">
                    <button type="button" class="btn btn-sm btn-secondary" onclick="closeEditModal()">
                        Batal
                    </button>
                    <button type="submit" class="btn btn-primary" style="width:auto;">💾 Simpan</button>
                </div>
            </div>
        </form>
    </div>
</div>


<script>
function openPasswordModal(regId) {
    const code = document.getElementById('pass-display-' + regId);
    const btn  = document.getElementById('pass-btn-' + regId);

    // Jika sudah tampil → sembunyikan
    if (btn && btn.textContent.trim() === '🙈') {
        code.textContent = '••••••';
        code.style.letterSpacing = '2px';
        btn.textContent = '👁️';
        btn.title = 'Tampilkan password';
        return;
    }

    const encInput = document.querySelector('.enc-pass-data[data-reg-id="' + regId + '"]');
    if (encInput) {
        const plain = atob(encInput.value);
        code.textContent = plain;
        code.style.letterSpacing = 'normal';
        btn.textContent = '🙈';
        btn.title = 'Sembunyikan password';
    }
}

// Buka modal edit data peserta & credentials
function openEditModal(regId, nama, hari, username, password) {
    document.getElementById('modal-reg-id').value  = regId;
    document.getElementById('modal-name').textContent = 'Peserta: ' + (nama || '');
    if (document.getElementById('modal-hari')) {
        document.getElementById('modal-hari').value = hari || 'Senin';
    }
    document.getElementById('modal-username').value  = username || '';
    document.getElementById('modal-password').value  = password || '';
    
    const clearBtn = document.getElementById('btn-modal-clear');
    if (clearBtn) {
        clearBtn.style.display = (username || password) ? 'inline-block' : 'none';
    }
    document.getElementById('modal-edit-cred').style.display = 'flex';
}

function closeEditModal() {
    const m = document.getElementById('modal-edit-cred');
    if (m) m.style.display = 'none';
}

function clearCredFromModal() {
    if (confirm('Apakah Anda yakin ingin mengosongkan username dan password tes untuk peserta ini?')) {
        const form = document.querySelector('#modal-edit-cred form');
        const actionInput = form.querySelector('input[name="action"]');
        if (actionInput) actionInput.value = 'clear_credential';
        form.submit();
    }
}

// Event delegation untuk tombol Edit Credentials agar selalu dapat diklik
document.addEventListener('click', function(e) {
    const btn = e.target.closest('.btn-edit-cred');
    if (btn) {
        const d = btn.dataset;
        openEditModal(d.regId, d.nama, d.hari, d.username, d.password);
        return;
    }
    const modal = document.getElementById('modal-edit-cred');
    if (e.target === modal) {
        closeEditModal();
    }
});

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeEditModal();
    }
});

// ── Filter Multi-Kolom DataTables Peserta Pretes ──────────────────
function getPretesTable() {
    var tbl = $('#table-peserta-pretes');
    if (window.jQuery && $.fn.DataTable && $.fn.DataTable.isDataTable(tbl)) {
        return tbl.DataTable();
    }
    return null;
}

function updateDayBadges(activeDay) {
    document.querySelectorAll('.btn-filter-day').forEach(function(b) {
        var day = b.dataset.day || '';
        if (day === activeDay) {
            b.style.background = '#2563eb';
            b.style.color = '#ffffff';
            b.style.borderColor = '#2563eb';
            var sp = b.querySelector('span');
            if (sp) sp.style.color = '#ffffff';
        } else {
            b.style.background = '#fff';
            b.style.color = '#334155';
            b.style.borderColor = '#cbd5e1';
            var sp = b.querySelector('span');
            if (sp) sp.style.color = '#2563eb';
        }
    });
}

function applyPretesFilters() {
    var dt = getPretesTable();
    if (!dt) return;

    var namaVal   = ($('#filter-nama').val() || '').trim();
    var nimVal    = ($('#filter-nim').val() || '').trim();
    var prodiVal  = $('#filter-prodi').val() || '';
    var hariVal   = $('#filter-hari').val() || '';
    var statusVal = $('#filter-status').val() || '';
    var credVal   = $('#filter-cred').val() || '';

    // Kolom 1: Nama
    dt.column(1).search(namaVal);

    // Kolom 2: NIM
    dt.column(2).search(nimVal);

    // Kolom 3: Prodi (Exact match jika dipilih)
    if (prodiVal) {
        dt.column(3).search('^' + $.fn.dataTable.util.escapeRegex(prodiVal) + '$', true, false);
    } else {
        dt.column(3).search('');
    }

    // Kolom 4: Hari (Exact match jika dipilih)
    if (hariVal) {
        dt.column(4).search('^' + $.fn.dataTable.util.escapeRegex(hariVal) + '$', true, false);
    } else {
        dt.column(4).search('');
    }

    // Kolom 5: Status Hadir (Exact match jika dipilih)
    if (statusVal) {
        dt.column(5).search('^' + $.fn.dataTable.util.escapeRegex(statusVal) + '$', true, false);
    } else {
        dt.column(5).search('');
    }

    // Kolom 6: Credentials (has_cred / no_cred)
    if (credVal === 'has_cred') {
        dt.column(6).search('has_cred');
    } else if (credVal === 'no_cred') {
        dt.column(6).search('no_cred');
    } else {
        dt.column(6).search('');
    }

    dt.draw();

    // Sinkronkan badge shortcut distribusi hari
    updateDayBadges(hariVal);

    // Update indikator filter aktif
    var activeCount = 0;
    if (namaVal) activeCount++;
    if (nimVal) activeCount++;
    if (prodiVal) activeCount++;
    if (hariVal) activeCount++;
    if (statusVal) activeCount++;
    if (credVal) activeCount++;

    var badge = document.getElementById('filter-active-badge');
    if (badge) {
        if (activeCount > 0) {
            badge.style.display = 'inline-block';
            badge.textContent = activeCount + ' Filter Aktif';
        } else {
            badge.style.display = 'none';
        }
    }

    try {
        sessionStorage.setItem('pretes_peserta_filter', JSON.stringify({
            nama: namaVal,
            nim: nimVal,
            prodi: prodiVal,
            hari: hariVal,
            status: statusVal,
            cred: credVal
        }));
    } catch(e) {}
}

function initPretesFilters() {
    var checkTimer = setInterval(function() {
        var dt = getPretesTable();
        if (dt) {
            clearInterval(checkTimer);
            setupPretesFilters();
        }
    }, 100);
    setTimeout(function() { clearInterval(checkTimer); }, 5000);
}

function setupPretesFilters() {
    // Pulihkan filter dari sessionStorage bila ada
    try {
        var saved = JSON.parse(sessionStorage.getItem('pretes_peserta_filter') || '{}');
        if (saved.nama)   $('#filter-nama').val(saved.nama);
        if (saved.nim)    $('#filter-nim').val(saved.nim);
        if (saved.prodi)  $('#filter-prodi').val(saved.prodi);
        if (saved.hari)   $('#filter-hari').val(saved.hari);
        if (saved.status) $('#filter-status').val(saved.status);
        if (saved.cred)   $('#filter-cred').val(saved.cred);
    } catch(e) {}

    // Event input debounced untuk Nama dan NIM
    var debounceTimer;
    function debouncedApply() {
        clearTimeout(debounceTimer);
        debounceTimer = setTimeout(applyPretesFilters, 200);
    }

    $('#filter-nama').on('input', debouncedApply);
    $('#filter-nim').on('input', debouncedApply);

    // Event change untuk dropdown filter
    $('#filter-prodi, #filter-hari, #filter-status, #filter-cred').on('change', applyPretesFilters);

    // Tombol pill shortcut Distribusi Hari
    document.querySelectorAll('.btn-filter-day').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var day = this.dataset.day || '';
            $('#filter-hari').val(day);
            applyPretesFilters();
        });
    });

    // Tombol Reset Semua Filter
    var resetBtn = document.getElementById('btn-reset-filters');
    if (resetBtn) {
        resetBtn.addEventListener('click', function() {
            $('#filter-nama').val('');
            $('#filter-nim').val('');
            $('#filter-prodi').val('');
            $('#filter-hari').val('');
            $('#filter-status').val('');
            $('#filter-cred').val('');
            try { sessionStorage.removeItem('pretes_peserta_filter'); } catch(e) {}
            applyPretesFilters();
        });
    }

    // Jalankan filter awal (misal jika ada state tersimpan)
    applyPretesFilters();
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initPretesFilters);
} else {
    initPretesFilters();
}

// ── Switcher Tab Kredensial (Impor vs Generate) ───────────────────
function switchCredTab(tab) {
    var tabImport = document.getElementById('tab-content-import');
    var tabGenerate = document.getElementById('tab-content-generate');
    var btnImport = document.getElementById('tab-btn-import');
    var btnGenerate = document.getElementById('tab-btn-generate');

    if (tab === 'generate') {
        if (tabImport) tabImport.style.display = 'none';
        if (tabGenerate) tabGenerate.style.display = 'block';
        if (btnImport) {
            btnImport.style.background = 'transparent';
            btnImport.style.color = 'rgba(255,255,255,0.85)';
            btnImport.style.fontWeight = '600';
            btnImport.style.boxShadow = 'none';
        }
        if (btnGenerate) {
            btnGenerate.style.background = '#ffffff';
            btnGenerate.style.color = '#0f766e';
            btnGenerate.style.fontWeight = '800';
            btnGenerate.style.boxShadow = '0 1px 3px rgba(0,0,0,0.15)';
        }
        updatePassTypeUI();
        updateUserFmtUI();
        updateSamplePreview();
        try { sessionStorage.setItem('pretes_cred_active_tab', 'generate'); } catch(e) {}
    } else {
        if (tabImport) tabImport.style.display = 'block';
        if (tabGenerate) tabGenerate.style.display = 'none';
        if (btnImport) {
            btnImport.style.background = '#ffffff';
            btnImport.style.color = '#0369a1';
            btnImport.style.fontWeight = '800';
            btnImport.style.boxShadow = '0 1px 3px rgba(0,0,0,0.15)';
        }
        if (btnGenerate) {
            btnGenerate.style.background = 'transparent';
            btnGenerate.style.color = 'rgba(255,255,255,0.85)';
            btnGenerate.style.fontWeight = '600';
            btnGenerate.style.boxShadow = 'none';
        }
        try { sessionStorage.setItem('pretes_cred_active_tab', 'import'); } catch(e) {}
    }
}

// ── Helper & UI State Generator Credentials Massal ────────────────
function getSelectedPassType() {
    var checked = document.querySelector('input[name="pass_type_select"]:checked');
    return checked ? checked.value : 'angka';
}

function getSelectedUserFmt() {
    var checked = document.querySelector('input[name="user_format_select"]:checked');
    return checked ? checked.value : 'eujian';
}

function getSelectedPassLen() {
    var el = document.getElementById('pass-len-input');
    return el ? el.value : '4';
}

function updatePassTypeUI() {
    var selected = getSelectedPassType();
    var options = ['angka', 'campuran', 'huruf'];
    options.forEach(function(opt) {
        var card = document.getElementById('card-pass-' + opt);
        if (!card) return;
        if (opt === selected) {
            card.style.background = '#f0fdf4';
            card.style.borderColor = '#16a34a';
            card.style.color = '#15803d';
            card.style.fontWeight = '700';
        } else {
            card.style.background = '#ffffff';
            card.style.borderColor = '#cbd5e1';
            card.style.color = '#334155';
            card.style.fontWeight = '600';
        }
    });
    updateSamplePreview();
}

function updateUserFmtUI() {
    var selected = getSelectedUserFmt();
    var options = ['eujian', 'prefix_nim', 'nim_only'];
    options.forEach(function(opt) {
        var card = document.getElementById('card-user-' + opt);
        if (!card) return;
        if (opt === selected) {
            card.style.background = '#eff6ff';
            card.style.borderColor = '#2563eb';
            card.style.color = '#1d4ed8';
            card.style.fontWeight = '700';
        } else {
            card.style.background = '#ffffff';
            card.style.borderColor = '#cbd5e1';
            card.style.color = '#334155';
            card.style.fontWeight = '600';
        }
    });
    updateSamplePreview();
}

function updateSamplePreview() {
    var userFmt = getSelectedUserFmt();
    var prefix = (document.getElementById('prefix-input').value || 'TES').trim().toUpperCase();
    var passType = getSelectedPassType();
    var passLen = parseInt(getSelectedPassLen(), 10) || 4;

    var sampleNim = '24042062';
    var sampleUser = '';
    if (userFmt === 'eujian') {
        sampleUser = sampleNim + '.22205';
    } else if (userFmt === 'nim_only') {
        sampleUser = sampleNim;
    } else {
        sampleUser = prefix + sampleNim;
    }

    var samplePass = '';
    var labelDesc = '';
    if (passType === 'angka') {
        samplePass = '7402831965'.substring(0, passLen);
        labelDesc = 'Hanya Angka';
    } else if (passType === 'huruf') {
        samplePass = 'ABKXYZMQTR'.substring(0, passLen);
        labelDesc = 'Hanya Huruf';
    } else {
        samplePass = 'A8C2K9M4P7'.substring(0, passLen);
        labelDesc = 'Campuran Huruf & Angka';
    }

    var el = document.getElementById('example-preview-box');
    if (el) {
        el.innerHTML = '💡 <strong>Contoh Hasil:</strong> Username: <code>' + sampleUser + '</code> | Password: <code>' + samplePass + '</code> (' + labelDesc + ', ' + passLen + ' karakter)';
    }

    var prefixWrap = document.getElementById('prefix-input-wrap');
    if (prefixWrap) {
        prefixWrap.style.display = (userFmt === 'prefix_nim') ? 'block' : 'none';
    }
}

function syncGenFields(form) {
    var userFmtVal = getSelectedUserFmt();
    var prefixVal  = (document.getElementById('prefix-input').value || 'TES').trim();
    var typeVal    = getSelectedPassType();
    var lenVal     = getSelectedPassLen();

    var uField = form.querySelector('input[name="user_format"]');
    if (uField) uField.value = userFmtVal;

    var pField = form.querySelector('input[name="prefix"]');
    if (pField) pField.value = prefixVal;

    var tField = form.querySelector('input[name="pass_type"]');
    if (tField) tField.value = typeVal;

    var lField = form.querySelector('input[name="pass_len"]');
    if (lField) lField.value = lenVal;
}

// Generate password acak di Modal Edit Individual
function generatePasswordModal() {
    var typeEl = document.querySelector('input[name="modal_pass_type"]:checked');
    var type = typeEl ? typeEl.value : 'angka';
    var pass = '';

    if (type === 'angka') {
        var chars = '0123456789';
        for (var i = 0; i < 4; i++) pass += chars[Math.floor(Math.random() * chars.length)];
    } else if (type === 'huruf') {
        var chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
        for (var i = 0; i < 6; i++) pass += chars[Math.floor(Math.random() * chars.length)];
    } else {
        var letters = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
        var digits = '23456789';
        var all = letters + digits;
        var pArr = [
            letters[Math.floor(Math.random() * letters.length)],
            digits[Math.floor(Math.random() * digits.length)]
        ];
        for (var i = 2; i < 6; i++) {
            pArr.push(all[Math.floor(Math.random() * all.length)]);
        }
        for (var i = pArr.length - 1; i > 0; i--) {
            var j = Math.floor(Math.random() * (i + 1));
            var temp = pArr[i];
            pArr[i] = pArr[j];
            pArr[j] = temp;
        }
        pass = pArr.join('');
    }
    document.getElementById('modal-password').value = pass;
}

// Inisialisasi tab aktif saat halaman dimuat
try {
    var savedTab = sessionStorage.getItem('pretes_cred_active_tab');
    if (savedTab === 'generate' || window.location.hash === '#generate') {
        switchCredTab('generate');
    } else {
        switchCredTab('import');
    }
} catch(e) {}


</script>


<!-- ── Modal Pilihan Export ke Excel ────────────────────────────── -->
<div id="modal-export-excel" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.55);z-index:9999;align-items:center;justify-content:center;padding:16px;">
    <div style="background:#fff;border-radius:16px;padding:28px;width:100%;max-width:520px;box-shadow:0 10px 40px rgba(0,0,0,0.25);position:relative;">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;">
            <div style="display:flex;align-items:center;gap:10px;">
                <span style="display:inline-flex;align-items:center;justify-content:center;width:38px;height:38px;border-radius:10px;background:#dcfce7;color:#16a34a;font-size:20px;">
                    📗
                </span>
                <div>
                    <h3 style="margin:0;font-size:17px;font-weight:800;color:#0f172a;">Export Data ke Excel (.xlsx)</h3>
                    <p style="margin:2px 0 0;font-size:12px;color:#64748b;">Pilih jenis ekspor data peserta & credentials pretes</p>
                </div>
            </div>
            <button type="button" onclick="closeExportModal()" style="background:none;border:none;font-size:22px;color:#94a3b8;cursor:pointer;line-height:1;padding:4px;">
                &times;
            </button>
        </div>

        <div style="display:flex;flex-direction:column;gap:10px;margin-bottom:20px;">
            <!-- Pilihan 1: Export Sesuai Filter Aktif -->
            <a href="javascript:void(0)" onclick="exportCurrentFiltered('xlsx')" data-no-spa="true"
               style="display:flex;align-items:center;justify-content:space-between;padding:14px 16px;border:1.5px solid #16a34a;background:#f0fdf4;border-radius:12px;text-decoration:none;transition:all 0.2s;">
                <div style="display:flex;align-items:center;gap:12px;">
                    <span style="font-size:20px;">🎯</span>
                    <div>
                        <strong style="font-size:13.5px;color:#166534;display:block;">Export Sesuai Filter Saat Ini</strong>
                        <span id="modal-export-filter-desc" style="font-size:11.5px;color:#15803d;">Mengekspor data berdasarkan pencarian, hari, prodi, dan status aktif</span>
                    </div>
                </div>
                <span style="background:#16a34a;color:#fff;padding:4px 10px;border-radius:6px;font-size:11.5px;font-weight:700;">.xlsx</span>
            </a>

            <!-- Pilihan 2: Export Semua Peserta -->
            <a href="pretes-peserta-export.php?download=1&format=xlsx" data-no-spa="true"
               style="display:flex;align-items:center;justify-content:space-between;padding:14px 16px;border:1.5px solid #e2e8f0;background:#ffffff;border-radius:12px;text-decoration:none;transition:all 0.2s;">
                <div style="display:flex;align-items:center;gap:12px;">
                    <span style="font-size:20px;">👥</span>
                    <div>
                        <strong style="font-size:13.5px;color:#0f172a;display:block;">Export SEMUA Peserta Pretes</strong>
                        <span style="font-size:11.5px;color:#64748b;">Mengekspor seluruh <?= $totalPeserta ?> peserta (lengkap dengan username & password)</span>
                    </div>
                </div>
                <span style="background:#0284c7;color:#fff;padding:4px 10px;border-radius:6px;font-size:11.5px;font-weight:700;">.xlsx</span>
            </a>

            <!-- Pilihan 3: Buka Halaman Export Lengkap -->
            <a href="pretes-peserta-export.php"
               style="display:flex;align-items:center;justify-content:space-between;padding:14px 16px;border:1.5px solid #e2e8f0;background:#f8fafc;border-radius:12px;text-decoration:none;transition:all 0.2s;">
                <div style="display:flex;align-items:center;gap:12px;">
                    <span style="font-size:20px;">⚙️</span>
                    <div>
                        <strong style="font-size:13.5px;color:#0f172a;display:block;">Buka Halaman Export Lengkap</strong>
                        <span style="font-size:11.5px;color:#64748b;">Kustomisasi pilihan kolom, format CSV / Excel, dan lihat live preview</span>
                    </div>
                </div>
                <span style="color:#64748b;font-size:18px;">➔</span>
            </a>
        </div>

        <div style="display:flex;justify-content:flex-end;">
            <button type="button" class="btn btn-sm btn-secondary" onclick="closeExportModal()" style="padding:8px 18px;border-radius:8px;">
                Tutup
            </button>
        </div>
    </div>
</div>

<script>
// Modal Export Excel Handlers
function openExportModal() {
    var modal = document.getElementById('modal-export-excel');
    if (modal) {
        var activeDesc = [];
        var hariVal   = ($('#filter-hari').val() || '').trim();
        var prodiVal  = ($('#filter-prodi').val() || '').trim();
        var statusVal = ($('#filter-status').val() || '').trim();
        var credVal   = ($('#filter-cred').val() || '').trim();
        var namaVal   = ($('#filter-nama').val() || '').trim();
        var nimVal    = ($('#filter-nim').val() || '').trim();

        if (hariVal) activeDesc.push('Hari: ' + hariVal);
        if (prodiVal) activeDesc.push('Prodi: ' + prodiVal);
        if (statusVal) activeDesc.push('Status: ' + statusVal);
        if (credVal) activeDesc.push('Cred: ' + (credVal === 'has_cred' ? 'Sudah Ada' : 'Belum Ada'));
        if (namaVal) activeDesc.push('Nama: ' + namaVal);
        if (nimVal) activeDesc.push('NIM: ' + nimVal);

        var descEl = document.getElementById('modal-export-filter-desc');
        if (descEl) {
            if (activeDesc.length > 0) {
                descEl.textContent = 'Filter aktif: ' + activeDesc.join(', ');
            } else {
                descEl.textContent = 'Tidak ada filter aktif (semua data diexport)';
            }
        }
        modal.style.display = 'flex';
    }
}

function closeExportModal() {
    var modal = document.getElementById('modal-export-excel');
    if (modal) modal.style.display = 'none';
}

function exportCurrentFiltered(format) {
    format = format || 'xlsx';
    var params = new URLSearchParams();
    params.set('download', '1');
    params.set('format', format);

    var namaVal   = ($('#filter-nama').val() || '').trim();
    var nimVal    = ($('#filter-nim').val() || '').trim();
    var prodiVal  = ($('#filter-prodi').val() || '').trim();
    var hariVal   = ($('#filter-hari').val() || '').trim();
    var statusVal = ($('#filter-status').val() || '').trim();
    var credVal   = ($('#filter-cred').val() || '').trim();

    if (namaVal) params.set('nama', namaVal);
    if (nimVal) params.set('nim', nimVal);
    if (prodiVal) params.set('prodi', prodiVal);
    if (hariVal) params.set('hari', hariVal);
    if (statusVal) params.set('status', statusVal);
    if (credVal) params.set('cred', credVal);

    closeExportModal();
    window.location.href = 'pretes-peserta-export.php?' + params.toString();
}

// Tutup modal export bila klik di luar modal
document.addEventListener('click', function(e) {
    var modal = document.getElementById('modal-export-excel');
    if (e.target === modal) {
        closeExportModal();
    }
});
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
