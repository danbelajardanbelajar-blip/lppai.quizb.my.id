<?php
/**
 * LPPAI Corner - Admin: Kelola Jadwal & Generate Pretes
 */
define('PAGE_TITLE', 'Kelola Jadwal Pretes');
require_once __DIR__ . '/../includes/auth.php';
requireAdmin();

$pdo = getDBConnection();
$message = '';
$msgType = '';

// Auto-migration aman untuk tabel master_pretes dan kolom pretes_registrations
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

// Ambil data periode aktif
$active_pretes = $pdo->query("SELECT * FROM master_pretes WHERE status = 'aktif' ORDER BY created_at DESC LIMIT 1")->fetch()
               ?: $pdo->query("SELECT * FROM master_pretes ORDER BY created_at DESC LIMIT 1")->fetch();

if (!$active_pretes) {
    $curYear = (int)date('Y');
    $ta = $curYear . ' - ' . ($curYear + 1);
    $per = 'Pretes ' . $curYear . '/' . ($curYear + 1);
    $pdo->prepare("
        INSERT INTO master_pretes 
        (periode, tahun_ajaran, status, jam, kuota_senin, kuota_selasa, kuota_rabu, kuota_kamis, kuota_jumat) 
        VALUES (?, ?, 'aktif', '13.00 WIB (Jam 1)', 30, 30, 30, 30, 30)
    ")->execute([$per, $ta]);
    $active_pretes = $pdo->query("SELECT * FROM master_pretes WHERE id = " . $pdo->lastInsertId())->fetch();
}

// Daftar Dosen Penguji & Ruangan
$tutorsList = $pdo->query("SELECT nim as id, nama_lengkap as nama FROM users WHERE role = 'dosen' ORDER BY nama_lengkap ASC")->fetchAll();
$roomsList  = $pdo->query("SELECT id, ruang FROM rooms ORDER BY ruang ASC")->fetchAll();

function parsePengujiList($pengujiStr, $tutorsList = []) {
    if (empty($pengujiStr) || trim($pengujiStr) === '') return [];
    $pengujiStr = trim($pengujiStr);

    // 1. Jika sudah ada delimiter |||, pecah berdasarkan |||
    if (strpos($pengujiStr, '|||') !== false) {
        return array_values(array_filter(array_map('trim', explode('|||', $pengujiStr)), fn($v) => $v !== ''));
    }

    // Normalisasi string pembantu
    $norm = function($str) {
        $str = mb_strtolower($str, 'UTF-8');
        $str = preg_replace('/[`\'’‘]/u', "'", $str);
        $str = preg_replace('/\s+/', ' ', $str);
        return trim($str);
    };

    $pNorm = $norm($pengujiStr);

    if (!empty($tutorsList)) {
        // Cek kecocokan langsung dengan nama lengkap dosen
        foreach ($tutorsList as $t) {
            if ($norm($t['nama']) === $pNorm) {
                return [trim($t['nama'])];
            }
        }

        // Cek apakah string mengandung satu atau lebih nama dosen lengkap (urut dari nama terpanjang)
        $sortedTutors = $tutorsList;
        usort($sortedTutors, fn($a, $b) => strlen($b['nama']) - strlen($a['nama']));

        $tempStr = $pNorm;
        $matched = [];
        foreach ($sortedTutors as $t) {
            $tNorm = $norm($t['nama']);
            if ($tNorm === '') continue;
            if (($pos = mb_strpos($tempStr, $tNorm)) !== false) {
                $matched[$pos] = $t['nama'];
                $tempStr = substr_replace($tempStr, str_repeat('#', strlen($tNorm)), $pos, strlen($tNorm));
            }
        }
        if (!empty($matched)) {
            ksort($matched);
            return array_values($matched);
        }

        // Cek kecocokan nama dasar sebelum koma (sebelum gelar)
        foreach ($sortedTutors as $t) {
            $baseName = trim(explode(',', $norm($t['nama']))[0]);
            if (strlen($baseName) >= 4 && ($pos = mb_strpos($tempStr, $baseName)) !== false) {
                $matched[$pos] = $t['nama'];
                $tempStr = substr_replace($tempStr, str_repeat('#', strlen($baseName)), $pos, strlen($baseName));
            }
        }
        if (!empty($matched)) {
            ksort($matched);
            return array_values($matched);
        }
    }

    // JANGAN pecah koma karena koma memisahkan gelar akademis (seperti S. Fil. I., M.E.I, M.Phil)
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

// ── Handle POST Actions ───────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $token = $_POST['csrf_token'] ?? '';
    if (!verifyCsrf($token)) {
        $message = 'Sesi tidak valid.';
        $msgType = 'danger';
    } else {
        $action = $_POST['action'];

        // 1. UPDATE PENGATURAN DOSEN PENGUJI & KUOTA
        if ($action === 'update_penguji_kuota') {
            $active_id = (int)($_POST['pretes_id'] ?? 0);
            if ($active_id > 0) {
                $kuota_senin  = (int)($_POST['kuota_senin'] ?? 0);
                $kuota_selasa = (int)($_POST['kuota_selasa'] ?? 0);
                $kuota_rabu   = (int)($_POST['kuota_rabu'] ?? 0);
                $kuota_kamis  = (int)($_POST['kuota_kamis'] ?? 0);
                $kuota_jumat  = (int)($_POST['kuota_jumat'] ?? 0);

                $penguji_senin  = isset($_POST['penguji_senin']) && is_array($_POST['penguji_senin']) ? implode('|||', array_filter(array_map('trim', $_POST['penguji_senin']))) : '';
                $penguji_selasa = isset($_POST['penguji_selasa']) && is_array($_POST['penguji_selasa']) ? implode('|||', array_filter(array_map('trim', $_POST['penguji_selasa']))) : '';
                $penguji_rabu   = isset($_POST['penguji_rabu']) && is_array($_POST['penguji_rabu']) ? implode('|||', array_filter(array_map('trim', $_POST['penguji_rabu']))) : '';
                $penguji_kamis  = isset($_POST['penguji_kamis']) && is_array($_POST['penguji_kamis']) ? implode('|||', array_filter(array_map('trim', $_POST['penguji_kamis']))) : '';
                $penguji_jumat  = isset($_POST['penguji_jumat']) && is_array($_POST['penguji_jumat']) ? implode('|||', array_filter(array_map('trim', $_POST['penguji_jumat']))) : '';

                $pdo->prepare("
                    UPDATE master_pretes 
                    SET kuota_senin=?, kuota_selasa=?, kuota_rabu=?, kuota_kamis=?, kuota_jumat=?,
                        penguji_senin=?, penguji_selasa=?, penguji_rabu=?, penguji_kamis=?, penguji_jumat=?
                    WHERE id=?
                ")->execute([
                    $kuota_senin, $kuota_selasa, $kuota_rabu, $kuota_kamis, $kuota_jumat,
                    $penguji_senin, $penguji_selasa, $penguji_rabu, $penguji_kamis, $penguji_jumat,
                    $active_id
                ]);

                $message = 'Pengaturan Dosen Penguji dan Kuota Pretes berhasil disimpan.';
                $msgType = 'success';

                // Refresh active_pretes
                $active_pretes = $pdo->query("SELECT * FROM master_pretes WHERE id = $active_id")->fetch();
            }

        // 2. GENERATE JADWAL PRETES OTOMATIS
        } elseif ($action === 'generate_pretes_jadwal') {
            if (!$active_pretes) {
                $message = 'Tidak ada periode pretes yang aktif.';
                $msgType = 'danger';
            } else {
                $mode          = $_POST['generate_mode'] ?? 'distribute_evenly';
                $minPerPenguji = max(1, (int)($_POST['min_per_penguji'] ?? 15));
                $autoRoom      = isset($_POST['auto_room']) && $_POST['auto_room'] == '1';
                $overwrite     = isset($_POST['overwrite']) && $_POST['overwrite'] == '1';

                $days = ['Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat'];
                $totalAssigned = 0;

                // Ambil daftar nama ruangan
                $availableRooms = array_column($roomsList, 'ruang');
                $numRooms = count($availableRooms);

                $pdo->beginTransaction();
                try {
                    $roomIndex = 0;

                    foreach ($days as $day) {
                        $dayLower = strtolower($day);
                        $pengujiStr = $active_pretes["penguji_$dayLower"] ?? '';
                        $pengujiList = parsePengujiList($pengujiStr, $tutorsList);

                        if (empty($pengujiList)) {
                            continue; // Skip jika hari ini belum ada penguji yang diset
                        }

                        // Ambil mahasiswa yang terdaftar pada hari ini
                        $sql = "SELECT id FROM pretes_registrations 
                                WHERE (LOWER(hari_pilihan) = LOWER(?) OR (hari_pilihan IS NULL AND ? = 'Senin')) 
                                  AND (periode = ? OR periode LIKE '%2026%' OR periode LIKE '%2027%' OR periode LIKE '%2028%' OR periode LIKE '%2029%' OR periode LIKE '%2030%')";
                        if (!$overwrite) {
                            $sql .= " AND (dosen_penguji IS NULL OR dosen_penguji = '')";
                        }
                        $sql .= " ORDER BY id ASC";

                        $stmtMhs = $pdo->prepare($sql);
                        $stmtMhs->execute([$day, $day, $active_pretes['periode']]);
                        $studentIds = $stmtMhs->fetchAll(PDO::FETCH_COLUMN);

                        $N = count($studentIds);
                        $C = count($pengujiList);
                        if ($N === 0) continue;

                        // Petakan penguji -> ruangan
                        $pengujiRoomMap = [];
                        foreach ($pengujiList as $pName) {
                            if ($autoRoom && $numRooms > 0) {
                                $pengujiRoomMap[$pName] = $availableRooms[$roomIndex % $numRooms];
                                $roomIndex++;
                            } else {
                                $pengujiRoomMap[$pName] = null;
                            }
                        }

                        $stmtUpdate = $pdo->prepare("
                            UPDATE pretes_registrations 
                            SET dosen_penguji = ?, ruangan = COALESCE(?, ruangan), hari_pilihan = COALESCE(hari_pilihan, ?) 
                            WHERE id = ?
                        ");

                        if ($mode === 'fill_first') {
                            $pIdx = 0;
                            while (!empty($studentIds)) {
                                $chunk = array_splice($studentIds, 0, $minPerPenguji);
                                $assignedPenguji = $pengujiList[$pIdx % $C];
                                $assignedRoom = $pengujiRoomMap[$assignedPenguji] ?? null;

                                foreach ($chunk as $sId) {
                                    $stmtUpdate->execute([$assignedPenguji, $assignedRoom, $day, $sId]);
                                    $totalAssigned++;
                                }
                                $pIdx++;
                            }
                        } else {
                            // distribute_evenly (merata)
                            $remainingStudents = $N;
                            for ($i = 0; $i < $C; $i++) {
                                if (empty($studentIds)) break;
                                $remainingPenguji = $C - $i;
                                $chunkSize = (int)ceil($remainingStudents / $remainingPenguji);
                                $chunk = array_splice($studentIds, 0, $chunkSize);
                                $remainingStudents -= count($chunk);

                                $assignedPenguji = $pengujiList[$i];
                                $assignedRoom = $pengujiRoomMap[$assignedPenguji] ?? null;

                                foreach ($chunk as $sId) {
                                    $stmtUpdate->execute([$assignedPenguji, $assignedRoom, $day, $sId]);
                                    $totalAssigned++;
                                }
                            }
                        }
                    }

                    $pdo->commit();
                    $message = "Sukses! Berhasil mengenerate jadwal pretes dan menugaskan dosen penguji untuk <strong>$totalAssigned</strong> mahasiswa.";
                    $msgType = 'success';
                } catch (Exception $e) {
                    $pdo->rollBack();
                    $message = 'Gagal mengenerate jadwal: ' . $e->getMessage();
                    $msgType = 'danger';
                }
            }

        // 3. RESET PLOTTING JADWAL PRETES
        } elseif ($action === 'reset_plotting') {
            if ($active_pretes) {
                $stmt = $pdo->prepare("
                    UPDATE pretes_registrations 
                    SET dosen_penguji = NULL, ruangan = NULL 
                    WHERE (periode = ? OR periode LIKE '%2026%' OR periode LIKE '%2027%' OR periode LIKE '%2028%' OR periode LIKE '%2029%' OR periode LIKE '%2030%')
                ");
                $stmt->execute([$active_pretes['periode']]);
                $message = 'Plotting jadwal pretes berhasil di-reset. Dosen penguji dan ruangan telah dikosongkan.';
                $msgType = 'success';
            }

        // 4. EDIT PENUGASAN INDIVIDUAL DARI MODAL
        } elseif ($action === 'edit_penugasan') {
            $regId = (int)($_POST['reg_id'] ?? 0);
            $hari  = trim($_POST['hari_pilihan'] ?? '');
            $dosen = trim($_POST['dosen_penguji'] ?? '');
            $ruang = trim($_POST['ruangan'] ?? '');

            if ($regId > 0) {
                $stmt = $pdo->prepare("
                    UPDATE pretes_registrations 
                    SET hari_pilihan = COALESCE(NULLIF(?, ''), hari_pilihan),
                        dosen_penguji = ?,
                        ruangan = ?
                    WHERE id = ?
                ");
                $stmt->execute([$hari, $dosen ?: null, $ruang ?: null, $regId]);
                $message = 'Penugasan penguji dan ruangan peserta berhasil diperbarui.';
                $msgType = 'success';
            }
        }
    }
}

// Hitung Pendaftar per Hari pada periode aktif
$registeredCounts = ['Senin' => 0, 'Selasa' => 0, 'Rabu' => 0, 'Kamis' => 0, 'Jumat' => 0];
if ($active_pretes) {
    $stmtCount = $pdo->prepare("
        SELECT hari_pilihan, COUNT(*) as cnt 
        FROM pretes_registrations 
        WHERE (periode = ? OR periode LIKE '%2026%' OR periode LIKE '%2027%' OR periode LIKE '%2028%' OR periode LIKE '%2029%' OR periode LIKE '%2030%')
        GROUP BY hari_pilihan
    ");
    $stmtCount->execute([$active_pretes['periode']]);
    foreach ($stmtCount->fetchAll() as $row) {
        $h = ucfirst(strtolower(trim($row['hari_pilihan'] ?? '')));
        if (isset($registeredCounts[$h])) {
            $registeredCounts[$h] = (int)$row['cnt'];
        }
    }
}

// Ambil daftar seluruh peserta pretes untuk hasil plotting
$participants = $pdo->query("
    SELECT pr.id as reg_id, pr.user_id, pr.periode, pr.hari_pilihan, pr.jam, pr.dosen_penguji, pr.ruangan, pr.tanggal_daftar,
           pr.status, pr.username_tes, pr.password_tes,
           u.nama_lengkap, u.nim, u.program_studi, u.fakultas
    FROM pretes_registrations pr
    JOIN users u ON pr.user_id = u.id
    WHERE (pr.periode = " . $pdo->quote($active_pretes['periode']) . " OR pr.periode LIKE '%2026%' OR pr.periode LIKE '%2027%' OR pr.periode LIKE '%2028%' OR pr.periode LIKE '%2029%' OR pr.periode LIKE '%2030%')
    ORDER BY FIELD(pr.hari_pilihan, 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat'), pr.dosen_penguji ASC, u.nama_lengkap ASC
")->fetchAll();

$totalPeserta = count($participants);
$totalPlotted = count(array_filter($participants, fn($p) => !empty($p['dosen_penguji'])));
$totalUnplotted = $totalPeserta - $totalPlotted;

$dayCounts = ['Senin' => 0, 'Selasa' => 0, 'Rabu' => 0, 'Kamis' => 0, 'Jumat' => 0];
foreach ($participants as $p) {
    $dh = ucfirst(strtolower(trim($p['hari_pilihan'] ?? '')));
    if (isset($dayCounts[$dh])) $dayCounts[$dh]++;
}

include __DIR__ . '/../includes/header.php';
?>

<?php if ($message): ?>
    <div class="alert alert-<?= $msgType ?>"><?= $message ?></div>
<?php endif; ?>

<!-- ═══════════════════════════════════════════════════════════════
     BANNER PERIODE PRETES AKTIF
     ═══════════════════════════════════════════════════════════════ -->
<div class="card" style="margin-bottom:20px; background:#eff6ff; border-left:4px solid #3b82f6;">
    <div class="card-body" style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px;">
        <div>
            <h4 style="margin:0 0 4px; color:#1e40af;">
                🌟 Periode Pretes Aktif: <strong><?= sanitize($active_pretes['periode']) ?></strong>
            </h4>
            <p style="margin:0; font-size:14px; color:#1d4ed8;">
                Tahun Ajaran: <strong><?= sanitize($active_pretes['tahun_ajaran']) ?></strong> | 
                Jam Pelaksanaan: <strong><?= sanitize($active_pretes['jam'] ?? '13.00 WIB (Jam 1)') ?></strong>
            </p>
        </div>
        <a href="<?= BASE_URL ?>/admin/pretes-dosen.php" class="btn btn-outline-primary" style="white-space:nowrap; padding:8px 16px; font-weight:600; text-decoration:none; background:#fff;">
            ⚙️ Master Periode & Penguji
        </a>
    </div>
</div>

<!-- ===================================================
     CARD 1: PENGATURAN DOSEN PENGUJI & KUOTA PRETES
     =================================================== -->
<div class="card" style="margin-bottom: 24px;">
    <div class="card-header">👨‍🏫 Pengaturan Dosen & Kuota (Periode Aktif)</div>
    <div class="card-body">
        <form method="POST" data-no-spa>
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
            <input type="hidden" name="action" value="update_penguji_kuota">
            <input type="hidden" name="pretes_id" value="<?= $active_pretes['id'] ?>">
            
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:16px; background:#f8fafc; padding:16px; border-radius:10px; border:1px solid #e2e8f0;">
                <?php
                $days = ['Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat'];
                foreach ($days as $day):
                    $dayLower = strtolower($day);
                    $pengujiStr = $active_pretes["penguji_$dayLower"] ?? '';
                    $currentPenguji = parsePengujiList($pengujiStr, $tutorsList);
                    
                    $totalKuota = (int)($active_pretes["kuota_$dayLower"] ?? 0);
                    $terisi = $registeredCounts[$day] ?? 0;
                    $sisaKuota = $totalKuota - $terisi;
                    if (empty($currentPenguji)) $currentPenguji = ['']; // Minimal 1 baris
                ?>
                <div class="form-group" style="margin-bottom:0; display:flex; flex-direction:column; gap:8px;">
                    <div style="display:flex; justify-content:space-between; align-items:center;">
                        <label style="font-weight:bold; font-size:14px; text-transform:capitalize; margin:0;"><?= $day ?></label>
                        <span style="font-size:11px; background:#e2e8f0; color:#475569; padding:2px 6px; border-radius:4px; font-weight:600;">13.00 WIB</span>
                    </div>
                    
                    <div id="qe_tutors_<?= $dayLower ?>_container" class="tutor-container" data-day="<?= $dayLower ?>">
                        <?php foreach($currentPenguji as $idx => $tName): ?>
                        <div class="tutor-row" style="display:flex; gap:4px; margin-bottom:4px;">
                            <select name="penguji_<?= $dayLower ?>[]" class="tutor-select" style="flex:1; padding:6px; border:1.5px solid #e5e7eb; border-radius:6px; font-size:13px;" onchange="calculateQEKuota('<?= $dayLower ?>')">
                                <option value="">-- Pilih Dosen Penguji --</option>
                                <?php 
                                $matchedAny = false;
                                foreach($tutorsList as $t): 
                                    $isSel = isPengujiMatch($tName, $t['nama']);
                                    if ($isSel) $matchedAny = true;
                                ?>
                                <option value="<?= sanitize($t['nama']) ?>" <?= $isSel ? 'selected' : '' ?>><?= sanitize($t['nama']) ?></option>
                                <?php endforeach; ?>
                                <?php if (!$matchedAny && !empty($tName)): ?>
                                <option value="<?= sanitize($tName) ?>" selected><?= sanitize($tName) ?></option>
                                <?php endif; ?>
                            </select>
                            <?php if ($idx === 0): ?>
                            <button type="button" class="btn btn-sm btn-success add-tutor-btn" onclick="addQETutorRow('<?= $dayLower ?>')" style="padding:0 8px; font-weight:bold;">+</button>
                            <?php else: ?>
                            <button type="button" class="btn btn-sm btn-danger remove-tutor-btn" onclick="this.parentElement.remove(); calculateQEKuota('<?= $dayLower ?>');" style="padding:0 8px; font-weight:bold;">×</button>
                            <?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    
                    <div style="font-size:12px; color:#64748b; margin-top:-4px;">Kuota Peserta:</div>
                    <input type="number" name="kuota_<?= $dayLower ?>" id="qe_kuota_<?= $dayLower ?>" value="<?= $totalKuota ?>" min="0" style="width:100%;padding:6px;border:1.5px solid #e5e7eb;border-radius:6px;font-size:14px;background:#fff;font-weight:bold;">
                    
                    <div style="font-size:12px; margin-top:2px; font-weight:600; color: <?= $sisaKuota < 0 ? '#ef4444' : '#10b981' ?>;">
                        Terisi: <?= $terisi ?> | Sisa Kuota: <?= $sisaKuota ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            
            <button type="submit" class="btn btn-primary" style="margin-top:16px;">💾 Simpan Pengaturan</button>
        </form>
    </div>
</div>

<script>
function calculateQEKuota(day) {
    const container = document.getElementById('qe_tutors_' + day + '_container');
    const selects = container.querySelectorAll('select');
    let validTutors = 0;
    selects.forEach(sel => {
        if (sel.value.trim() !== '') validTutors++;
    });
    const kuotaInput = document.getElementById('qe_kuota_' + day);
    if (kuotaInput) kuotaInput.value = validTutors * 30;
}

function addQETutorRow(day) {
    const container = document.getElementById('qe_tutors_' + day + '_container');
    const firstSelect = container.querySelector('.tutor-select').cloneNode(true);
    firstSelect.value = '';
    
    const div = document.createElement('div');
    div.className = 'tutor-row';
    div.style.display = 'flex';
    div.style.gap = '4px';
    div.style.marginBottom = '4px';
    
    const removeBtn = document.createElement('button');
    removeBtn.type = 'button';
    removeBtn.className = 'btn btn-sm btn-danger remove-tutor-btn';
    removeBtn.style.padding = '0 8px';
    removeBtn.innerHTML = '×';
    removeBtn.onclick = function() { 
        div.remove(); 
        calculateQEKuota(day);
    };
    
    div.appendChild(firstSelect);
    div.appendChild(removeBtn);
    container.appendChild(div);
}
</script>

<!-- ===================================================
     CARD 2: GENERATE JADWAL PRETES (PLOTTING OTOMATIS)
     =================================================== -->
<div class="card" style="border: 2px dashed #3b82f6; background-color: #eff6ff; margin-bottom: 24px;">
    <div class="card-header" style="background-color: transparent; color: #1e40af;">⚡ Generate Jadwal Pretes</div>
    <div class="card-body">
        <p style="color: #1e3a8a; font-size: 14px; margin-top: 0; margin-bottom: 16px;">
            Sistem akan membagi mahasiswa pretes pada setiap hari ke dosen penguji yang bertugas dan menempatkan ruangan secara otomatis.
        </p>
        <form method="POST" data-no-spa>
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
            <input type="hidden" name="action" value="generate_pretes_jadwal">
            
            <div style="background: #fff; padding: 16px; border-radius: 8px; border: 1px solid #bfdbfe; margin-bottom: 16px;">
                <label style="display: block; font-weight: 600; color: #1e3a8a; margin-bottom: 12px;">Mode Pembagian Mahasiswa:</label>
                
                <label style="display: flex; align-items: flex-start; gap: 8px; margin-bottom: 12px; cursor: pointer;">
                    <input type="radio" name="generate_mode" value="distribute_evenly" id="modeDistribute" checked style="margin-top: 4px;">
                    <div>
                        <span style="font-weight: 500; color: #334155;">Utamakan meratakan jumlah mahasiswa</span>
                        <div style="font-size: 13px; color: #64748b; margin-top: 4px;">
                            Membagi rata jumlah mahasiswa kepada seluruh dosen penguji yang aktif pada hari tersebut.
                        </div>
                    </div>
                </label>

                <label style="display: flex; align-items: flex-start; gap: 8px; margin-bottom: 12px; cursor: pointer;">
                    <input type="radio" name="generate_mode" value="fill_first" id="modeFillFirst" style="margin-top: 4px;">
                    <div>
                        <span style="font-weight: 500; color: #334155;">Utamakan memenuhi kuota per penguji</span>
                        <div style="font-size: 13px; color: #64748b; margin-top: 4px;">
                            Jumlah mahasiswa per penguji minimal <input type="number" name="min_per_penguji" id="minPerPenguji" value="15" min="1" style="width: 60px; padding: 4px; border: 1px solid #cbd5e1; border-radius: 4px; text-align: center;"> lalu tugaskan penguji berikutnya.
                        </div>
                    </div>
                </label>
                
                <hr style="margin: 14px 0; border: none; border-top: 1px dashed #cbd5e1;">

                <div style="display:flex; flex-direction:column; gap:8px;">
                    <label style="display: flex; align-items: center; gap: 8px; cursor: pointer;">
                        <input type="checkbox" name="auto_room" value="1" checked>
                        <span style="font-size: 13px; font-weight: 500; color: #334155;">
                            🏛️ Alokasikan ruangan secara otomatis untuk setiap penguji dari master data ruangan
                        </span>
                    </label>

                    <label style="display: flex; align-items: center; gap: 8px; cursor: pointer;">
                        <input type="checkbox" name="overwrite" value="1">
                        <span style="font-size: 13px; color: #64748b;">
                            🔄 Timpa mahasiswa yang sudah memiliki dosen penguji / ruangan
                        </span>
                    </label>
                </div>
            </div>
            
            <div style="display:flex; gap:12px; flex-wrap:wrap; align-items:center;">
                <button type="submit" class="btn btn-primary" style="background-color: #2563eb; width: auto; font-weight:600;">
                    🚀 Generate Jadwal secara otomatis
                </button>

                <button type="button" class="btn btn-outline-danger" onclick="confirmResetPlotting()" style="width: auto;">
                    🔄 Reset Plotting Jadwal
                </button>
            </div>
        </form>

        <form id="form-reset-plotting" method="POST" data-no-spa style="display:none;">
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
            <input type="hidden" name="action" value="reset_plotting">
        </form>

        <script>
            document.getElementById('modeFillFirst').addEventListener('change', function() {
                document.getElementById('minPerPenguji').focus();
            });
            document.getElementById('minPerPenguji').addEventListener('click', function() {
                document.getElementById('modeFillFirst').checked = true;
            });

            function confirmResetPlotting() {
                if (confirm('Apakah Anda yakin ingin me-reset seluruh plotting dosen penguji dan ruangan pada periode ini? Data penguji dan ruangan peserta akan dikosongkan.')) {
                    document.getElementById('form-reset-plotting').submit();
                }
            }
        </script>
    </div>
</div>

<div class="card">
    <div class="card-header">Export Absensi Pretes</div>
    <div class="card-body">
        <p style="margin-top:0;color:#475569;">Lembar absensi resmi dengan kop dan logo LPPAI. Peserta dengan hari, penguji, dan ruangan yang sama digabung dalam satu halaman A4. Waktu pelaksanaan pukul 13.00 WIB. Buka pratinjau untuk mencetak atau menyimpan sebagai PDF.</p>
        <form action="pretes-absensi-export.php" method="GET" target="_blank" rel="noopener" data-no-spa style="display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end;">
            <input type="hidden" name="pretes_id" value="<?= (int)$active_pretes['id'] ?>">
            <div class="form-group" style="margin-bottom:0;flex:1;min-width:180px;">
                <label>Periode</label>
                <div style="padding:10px 0;"><?= sanitize($active_pretes['periode']) ?></div>
            </div>
            <div class="form-group" style="margin-bottom:0;flex:1;min-width:150px;">
                <label for="absensi-hari">Hari</label>
                <select id="absensi-hari" name="hari">
                    <option value="">Semua hari</option>
                    <?php foreach (['Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat'] as $day): ?>
                    <option value="<?= $day ?>"><?= $day ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php foreach (['dosen_penguji' => 'Dosen Penguji', 'ruangan' => 'Ruangan'] as $field => $label):
                $options = array_values(array_unique(array_filter(array_map(fn($value) => trim($value ?? ''), array_column($participants, $field)))));
                sort($options, SORT_NATURAL | SORT_FLAG_CASE);
            ?>
            <div class="form-group" style="margin-bottom:0;flex:1;min-width:180px;">
                <label for="absensi-<?= $field ?>"><?= $label ?></label>
                <select id="absensi-<?= $field ?>" name="<?= $field ?>">
                    <option value="">Semua</option>
                    <?php foreach ($options as $option): ?>
                    <option value="<?= sanitize($option) ?>"><?= sanitize($option) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endforeach; ?>
            <button type="submit" class="btn btn-primary" style="width:auto;">Export Absensi / PDF</button>
        </form>
        <p style="font-size:12px;color:#64748b;margin-bottom:0;">Export hanya memuat peserta pada periode yang tercantum. Tanggal pelaksanaan dapat diisi pada lembar cetak.</p>
    </div>
</div>

<!-- ===================================================
     CARD 3: TABEL HASIL PLOTTING & JADWAL PRETES
     =================================================== -->
<div class="card">
    <div class="card-header" style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px;">
        <span>📋 Data Hasil Plotting & Jadwal Pretes (<?= $totalPeserta ?> peserta)</span>
        <div style="display:flex; gap:10px; font-size:13px;">
            <span style="color:#10b981; font-weight:600;">✓ Terplot: <?= $totalPlotted ?></span>
            <span style="color:#ef4444; font-weight:600;">⏳ Belum: <?= $totalUnplotted ?></span>
        </div>
    </div>
    <div class="card-body">
        <?php if (empty($participants)): ?>
            <div class="empty-state">
                <div class="icon">👥</div>
                <h3>Belum ada peserta</h3>
                <p>Belum ada mahasiswa yang mendaftar pretes pada periode ini.</p>
            </div>
        <?php else: ?>
        <!-- Filter Hari Peserta Pretes -->
        <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:16px;align-items:center;background:#f8fafc;padding:10px 14px;border-radius:10px;border:1px solid #e2e8f0;">
            <span style="font-size:13px;font-weight:600;color:#475569;">📅 Filter Hari:</span>
            <button type="button" class="btn-filter-day" data-day="" style="padding:4px 12px;border-radius:20px;border:1px solid #cbd5e1;background:#e2e8f0;font-size:12px;cursor:pointer;font-weight:600;color:#334155;">
                Semua (<?= $totalPeserta ?>)
            </button>
            <?php foreach (['Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat'] as $day): ?>
                <button type="button" class="btn-filter-day" data-day="<?= $day ?>" style="padding:4px 12px;border-radius:20px;border:1px solid #cbd5e1;background:#fff;font-size:12px;cursor:pointer;font-weight:600;color:#334155;">
                    <?= $day ?>: <span style="color:#2563eb;font-weight:bold;"><?= $dayCounts[$day] ?? 0 ?></span>
                </button>
            <?php endforeach; ?>
        </div>

        <div class="table-responsive">
            <table>
                <thead>
                    <tr>
                        <th>No</th>
                        <th>Nama</th>
                        <th>NIM</th>
                        <th>Prodi</th>
                        <th>Hari & Jam</th>
                        <th>Dosen Penguji</th>
                        <th>Ruangan</th>
                        <th>Status</th>
                        <th>Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($participants as $i => $p): 
                        $curHari = ucfirst(strtolower(trim($p['hari_pilihan'] ?? '')));
                    ?>
                    <tr>
                        <td><?= $i + 1 ?></td>
                        <td><strong><?= sanitize($p['nama_lengkap']) ?></strong></td>
                        <td><?= sanitize($p['nim']) ?></td>
                        <td><?= sanitize($p['program_studi']) ?></td>
                        <td data-order="<?= sanitize($curHari) ?>" data-search="<?= sanitize($curHari) ?>">
                            <span class="badge badge-info" style="font-size:12px;">
                                <?= sanitize($curHari ?: 'Senin') ?>
                            </span>
                            <div style="font-size:11px; color:#64748b; margin-top:2px;">
                                <?= sanitize($p['jam'] ?? '13.00 WIB') ?>
                            </div>
                        </td>
                        <td>
                            <?php if (!empty($p['dosen_penguji'])): ?>
                                <span style="background:#f0fdf4; color:#166534; border:1px solid #bbf7d0; padding:4px 10px; border-radius:6px; font-size:13px; font-weight:600; display:inline-block;">
                                    👨‍🏫 <?= sanitize($p['dosen_penguji']) ?>
                                </span>
                            <?php else: ?>
                                <span style="color:#94a3b8; font-size:12px; font-style:italic;">— Belum diplot —</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if (!empty($p['ruangan'])): ?>
                                <span style="background:#eff6ff; color:#1e40af; border:1px solid #bfdbfe; padding:4px 8px; border-radius:6px; font-size:12px; font-weight:600; display:inline-block;">
                                    🏛️ <?= sanitize($p['ruangan']) ?>
                                </span>
                            <?php else: ?>
                                <span style="color:#94a3b8; font-size:12px; font-style:italic;">— Belum ada —</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php
                            $stBadges = ['terdaftar' => 'badge-info', 'hadir' => 'badge-success', 'tidak_hadir' => 'badge-danger'];
                            ?>
                            <span class="badge <?= $stBadges[$p['status']] ?? 'badge-secondary' ?>">
                                <?= ucfirst(str_replace('_', ' ', $p['status'])) ?>
                            </span>
                        </td>
                        <td>
                            <button type="button" class="btn btn-sm btn-warning btn-edit-penugasan"
                                data-reg-id="<?= $p['reg_id'] ?>"
                                data-nama="<?= htmlspecialchars($p['nama_lengkap'], ENT_QUOTES, 'UTF-8') ?>"
                                data-nim="<?= htmlspecialchars($p['nim'], ENT_QUOTES, 'UTF-8') ?>"
                                data-hari="<?= htmlspecialchars($curHari ?: 'Senin', ENT_QUOTES, 'UTF-8') ?>"
                                data-dosen="<?= htmlspecialchars($p['dosen_penguji'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                                data-ruangan="<?= htmlspecialchars($p['ruangan'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                                title="Edit Penugasan">
                                ✏️ Edit
                            </button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- ===================================================
     MODAL EDIT PENUGASAN INDIVIDUAL
     =================================================== -->
<div id="modal-edit-penugasan" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.55);z-index:9999;align-items:center;justify-content:center;">
    <div style="background:#fff;border-radius:16px;padding:32px;width:100%;max-width:480px;box-shadow:0 8px 40px rgba(0,0,0,0.25);">
        <h3 style="margin-bottom:4px; font-size:18px; color:#1e293b;">✏️ Edit Penugasan Penguji & Ruangan</h3>
        <p id="modal-mhs-info" style="color:#64748b;font-size:13px;margin-bottom:20px;"></p>
        
        <form method="POST" data-no-spa>
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
            <input type="hidden" name="action" value="edit_penugasan">
            <input type="hidden" name="reg_id" id="modal-reg-id">
            
            <div class="form-group" style="margin-bottom:14px;">
                <label style="font-size:13px;font-weight:600;color:#334155;margin-bottom:6px;display:block;">Hari Pelaksanaan</label>
                <select name="hari_pilihan" id="modal-hari" style="width:100%;padding:9px 12px;border:1.5px solid #cbd5e1;border-radius:8px;font-size:14px;background:#fff;">
                    <option value="Senin">Senin</option>
                    <option value="Selasa">Selasa</option>
                    <option value="Rabu">Rabu</option>
                    <option value="Kamis">Kamis</option>
                    <option value="Jumat">Jumat</option>
                </select>
            </div>

            <div class="form-group" style="margin-bottom:14px;">
                <label style="font-size:13px;font-weight:600;color:#334155;margin-bottom:6px;display:block;">Dosen Penguji</label>
                <select name="dosen_penguji" id="modal-dosen" style="width:100%;padding:9px 12px;border:1.5px solid #cbd5e1;border-radius:8px;font-size:14px;background:#fff;">
                    <option value="">-- Belum Ditugaskan / Kosongkan --</option>
                    <?php foreach ($tutorsList as $t): ?>
                    <option value="<?= sanitize($t['nama']) ?>"><?= sanitize($t['nama']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group" style="margin-bottom:20px;">
                <label style="font-size:13px;font-weight:600;color:#334155;margin-bottom:6px;display:block;">Ruangan Ujian</label>
                <select name="ruangan" id="modal-ruangan" style="width:100%;padding:9px 12px;border:1.5px solid #cbd5e1;border-radius:8px;font-size:14px;background:#fff;">
                    <option value="">-- Belum Ditentukan / Kosongkan --</option>
                    <?php foreach ($roomsList as $rm): ?>
                    <option value="<?= sanitize($rm['ruang']) ?>"><?= sanitize($rm['ruang']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div style="display:flex;gap:10px;justify-content:flex-end;">
                <button type="button" class="btn btn-secondary" onclick="closePenugasanModal()" style="width:auto;">Batal</button>
                <button type="submit" class="btn btn-primary" style="width:auto;">💾 Simpan Penugasan</button>
            </div>
        </form>
    </div>
</div>

<script>
function setPengujiSelectValue(selectElem, targetName) {
    if (!selectElem) return;
    if (!targetName || typeof targetName !== 'string' || targetName.trim() === '') {
        selectElem.value = '';
        return;
    }
    targetName = targetName.trim();
    
    selectElem.value = targetName;
    if (selectElem.value === targetName && selectElem.selectedIndex > 0) return;
    
    const norm = str => (str || '').toLowerCase().replace(/[`'’‘]/g, "'").replace(/\s+/g, ' ').trim();
    const targetNorm = norm(targetName);
    for (let opt of selectElem.options) {
        if (opt.value && norm(opt.value) === targetNorm) {
            selectElem.value = opt.value;
            return;
        }
    }
    
    const targetBase = targetNorm.split(',')[0].trim();
    if (targetBase.length >= 4) {
        for (let opt of selectElem.options) {
            if (opt.value) {
                const optBase = norm(opt.value).split(',')[0].trim();
                if (optBase === targetBase || optBase.includes(targetBase) || targetBase.includes(optBase)) {
                    selectElem.value = opt.value;
                    return;
                }
            }
        }
    }
    
    const newOpt = new Option(targetName, targetName, true, true);
    selectElem.add(newOpt);
    selectElem.value = targetName;
}

function openPenugasanModal(regId, nama, nim, hari, dosen, ruangan) {
    document.getElementById('modal-reg-id').value = regId;
    document.getElementById('modal-mhs-info').textContent = nama + ' (' + nim + ')';
    document.getElementById('modal-hari').value = hari || 'Senin';
    setPengujiSelectValue(document.getElementById('modal-dosen'), dosen || '');
    document.getElementById('modal-ruangan').value = ruangan || '';
    document.getElementById('modal-edit-penugasan').style.display = 'flex';
}

function closePenugasanModal() {
    document.getElementById('modal-edit-penugasan').style.display = 'none';
}

document.addEventListener('click', function(e) {
    const btn = e.target.closest('.btn-edit-penugasan');
    if (btn) {
        const d = btn.dataset;
        openPenugasanModal(d.regId, d.nama, d.nim, d.hari, d.dosen, d.ruangan);
        return;
    }
    const modal = document.getElementById('modal-edit-penugasan');
    if (e.target === modal) {
        closePenugasanModal();
    }
});

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closePenugasanModal();
    }
});

// Filter DataTables berdasarkan Hari Pelaksanaan
document.querySelectorAll('.btn-filter-day').forEach(function(btn) {
    btn.addEventListener('click', function() {
        var day = this.dataset.day || '';
        if (window.jQuery && $.fn.DataTable && $.fn.DataTable.isDataTable('table')) {
            $('table').DataTable().column(4).search(day).draw();
        }
        document.querySelectorAll('.btn-filter-day').forEach(function(b) {
            b.style.background = '#fff';
            b.style.color = '#334155';
        });
        this.style.background = '#e2e8f0';
    });
});
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
