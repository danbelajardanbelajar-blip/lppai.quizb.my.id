<?php
/**
 * LPPAI Corner - Admin: Data Peserta Tutorial
 */
define('PAGE_TITLE', 'Data Peserta Tutorial');
require_once __DIR__ . '/../includes/auth.php';
requireAdmin();

$pdo = getDBConnection();

// Sinkronkan nama dosen_pengampu pada tutorial_classes dengan nama lengkap beserta gelar di tabel tutors
$pdo->exec("
    UPDATE tutorial_classes tc
    JOIN tutors t ON t.nama LIKE CONCAT('%', tc.dosen_pengampu, '%') AND t.nama != tc.dosen_pengampu
    SET tc.dosen_pengampu = t.nama
");

$message = '';
$msgType = '';

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
            $mataKuliah = '-';
            $dosen      = trim($_POST['dosen_pengampu'] ?? '');
            $hari       = trim($_POST['hari'] ?? '');
            $jam        = trim($_POST['jam'] ?? '');
            $ruangan    = trim($_POST['ruangan'] ?? '');
            $kuota      = (int)($_POST['kuota'] ?? 0);
            $userIds  = array_filter(array_map('intval', (array)($_POST['user_ids'] ?? [])));

            // Nilai Default
            $gelombang = 'gel1';
            $tahun = date('Y');
            $semester = $tahun . '/' . ($tahun+1) . '-Ganjil';

                if (empty($namaKelas) || empty($userIds)) {
                    $message = 'Isi Nama Kelas dan pilih minimal satu mahasiswa.';
                $msgType = 'danger';
            } else {
                $pdo->prepare("INSERT INTO tutorial_classes (nama_kelas, mata_kuliah, dosen_pengampu, hari, jam, ruangan, gelombang, semester, kuota) VALUES (?,?,?,?,?,?,?,?,?)")
                    ->execute([$namaKelas, $mataKuliah, $dosen, $hari, $jam, $ruangan, $gelombang, $semester, $kuota]);
                
                $classId = (int)$pdo->lastInsertId();

                $added = 0;
                $stmtInsert = $pdo->prepare("INSERT INTO tutorial_registrations (user_id, tutorial_class_id, status) VALUES (?, ?, 'terdaftar')");

                foreach ($userIds as $uid) {
                    // Cek apakah mahasiswa ini sudah ada di kelas lain? 
                    // Karena membuat kelas baru, tidak mungkin sudah ada di kelas *ini*.
                    // Kita insert saja
                    $stmtInsert->execute([$uid, $classId]);
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
                // Pindahkan mahasiswa
                $pdo->prepare("UPDATE tutorial_registrations SET tutorial_class_id = ? WHERE id = ?")
                    ->execute([$newClassId, $regId]);
                
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
    AND id IN (SELECT user_id FROM tutorial_registrations)
    ORDER BY nama_lengkap
")->fetchAll();
$classes  = $pdo->query("SELECT * FROM tutorial_classes ORDER BY gelombang, hari, nama_kelas")->fetchAll();
$gelLabels = ['gel1' => 'Gel.1', 'gel2' => 'Gel.2', 'mandiri' => 'Mandiri'];
$roomsList = $pdo->query("SELECT id, ruang FROM rooms ORDER BY ruang ASC")->fetchAll();
$tutorsList = $pdo->query("SELECT id, nama FROM tutors ORDER BY nama ASC")->fetchAll();

$active_gel = $pdo->query("SELECT * FROM master_gelombang ORDER BY created_at DESC LIMIT 1")->fetch();
$registeredCounts = ['Senin' => 0, 'Selasa' => 0, 'Rabu' => 0, 'Kamis' => 0, 'Jumat' => 0];
if ($active_gel) {
    try {
        $stmtCount = $pdo->prepare("SELECT hari_pilihan, COUNT(*) as cnt FROM tutorial_registrations WHERE gelombang = ? GROUP BY hari_pilihan");
        $stmtCount->execute([$active_gel['gelombang']]);
        foreach ($stmtCount->fetchAll() as $row) {
            $hari = ucfirst(strtolower($row['hari_pilihan']));
            if (isset($registeredCounts[$hari])) {
                $registeredCounts[$hari] = $row['cnt'];
            }
        }
    } catch (Exception $e) {
        // Fallback jika tidak ada kolom gelombang di tutorial_registrations
        $stmtCount = $pdo->query("SELECT hari_pilihan, COUNT(*) as cnt FROM tutorial_registrations GROUP BY hari_pilihan");
        foreach ($stmtCount->fetchAll() as $row) {
            $hari = ucfirst(strtolower($row['hari_pilihan']));
            if (isset($registeredCounts[$hari])) {
                $registeredCounts[$hari] = $row['cnt'];
            }
        }
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
foreach ($pdo->query("SELECT user_id, tutorial_class_id FROM tutorial_registrations")->fetchAll() as $row) {
    $registeredPairs[$row['user_id'] . '_' . $row['tutorial_class_id']] = true;
}

$registrations = $pdo->query("
    SELECT tr.*, u.nama_lengkap, u.nim, u.program_studi, tc.nama_kelas, tc.mata_kuliah, tc.gelombang, tc.hari, tc.dosen_pengampu, tc.ruangan
    FROM tutorial_registrations tr
    JOIN users u ON tr.user_id = u.id
    JOIN tutorial_classes tc ON tr.tutorial_class_id = tc.id
    ORDER BY tc.gelombang, tc.nama_kelas, u.nama_lengkap
")->fetchAll();

$allRegistrations = $pdo->query("
    SELECT tr.*, u.nama_lengkap, u.nim, u.program_studi
    FROM tutorial_registrations tr
    JOIN users u ON tr.user_id = u.id
    ORDER BY tr.created_at DESC
")->fetchAll();

include __DIR__ . '/../includes/header.php';
?>

<?php if ($message): ?>
    <div class="alert alert-<?= $msgType ?>"><?= sanitize($message) ?></div>
<?php endif; ?>

<!-- ===================================================
     CARD: DATA PENDAFTAR TUTORIAL
     =================================================== -->
<div class="card" style="margin-bottom: 24px;">
    <div class="card-header" style="background-color: #f8fafc; color: #1e293b; font-weight: 600;">
        <span style="font-size:18px;">📝</span> Data Pendaftar Tutorial
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table" id="tablePendaftar">
                <thead>
                    <tr>
                        <th>Nama Mahasiswa</th>
                        <th>NIM</th>
                        <th>Jurusan</th>
                        <th>Pilihan Hari</th>
                        <th>Status Kelas</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($allRegistrations as $reg): ?>
                    <tr>
                        <td><strong><?= sanitize($reg['nama_lengkap']) ?></strong></td>
                        <td><?= sanitize($reg['nim'] ?: '-') ?></td>
                        <td><?= sanitize($reg['program_studi'] ?: '-') ?></td>
                        <td><span class="badge badge-primary"><?= sanitize($reg['hari_pilihan'] ?: 'Belum Memilih') ?></span></td>
                        <td>
                            <?php if ($reg['tutorial_class_id']): ?>
                                <span class="badge badge-success">Sudah diplot</span>
                            <?php else: ?>
                                <span class="badge badge-warning">Menunggu</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- ===================================================
     CARD: PENGATURAN DOSEN DAN KUOTA
     =================================================== -->
<?php if ($active_gel): ?>
<div class="card" style="margin-bottom: 24px;">
    <div class="card-header">👨‍🏫 Pengaturan Dosen & Kuota (Gelombang Aktif)</div>
    <div class="card-body">
        <form method="POST" data-no-spa>
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
            <input type="hidden" name="action" value="update_tutors_kuota">
            <input type="hidden" name="gel_id" value="<?= $active_gel['id'] ?>">
            
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:16px; background:#f8fafc; padding:16px; border-radius:10px; border:1px solid #e2e8f0;">
                <?php
                // Fungsi untuk mem-parsing data dosen lama yang masih menggunakan koma
                function parseLegacyTutors($tutorsStr, $tutorsList) {
                    if ($tutorsStr === '') return [];
                    if (strpos($tutorsStr, '|||') !== false) {
                        return array_filter(array_map('trim', explode('|||', $tutorsStr)));
                    }
                    
                    // Sort by length desc
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

                $days = ['Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat'];
                foreach ($days as $day):
                    $dayLower = strtolower($day);
                    $tutorsStr = $active_gel["tutors_$dayLower"] ?? '';
                    $currentTutors = parseLegacyTutors($tutorsStr, $tutorsList);
                    
                    $totalKuota = $active_gel["kuota_$dayLower"] ?? 0;
                    $terisi = $registeredCounts[$day] ?? 0;
                    $sisaKuota = $totalKuota - $terisi;
                    if (empty($currentTutors)) $currentTutors = ['']; // Minimal 1 row kosong
                ?>
                <div class="form-group" style="margin-bottom:0; display:flex; flex-direction:column; gap:8px;">
                    <label style="font-weight:bold; font-size:14px; text-transform:capitalize;"><?= $day ?></label>
                    <div id="qe_tutors_<?= $dayLower ?>_container" class="tutor-container" data-day="<?= $dayLower ?>">
                        <?php foreach($currentTutors as $idx => $tName): ?>
                        <div class="tutor-row" style="display:flex; gap:4px; margin-bottom:4px;">
                            <select name="tutors_<?= $dayLower ?>[]" class="tutor-select" style="flex:1; padding:6px; border:1.5px solid #e5e7eb; border-radius:6px; font-size:13px;" onchange="calculateQEKuota('<?= $dayLower ?>')">
                                <option value="">- Tutor -</option>
                                <?php foreach($tutorsList as $t): ?>
                                <option value="<?= sanitize($t['nama']) ?>" <?= ($t['nama'] === $tName) ? 'selected' : '' ?>><?= sanitize($t['nama']) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <?php if ($idx === 0): ?>
                            <button type="button" class="btn btn-sm btn-success add-tutor-btn" onclick="addQETutorRow('<?= $dayLower ?>')" style="padding:0 8px;">+</button>
                            <?php else: ?>
                            <button type="button" class="btn btn-sm btn-danger remove-tutor-btn" onclick="this.parentElement.remove(); calculateQEKuota('<?= $dayLower ?>');" style="padding:0 8px;">×</button>
                            <?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    
                    <div style="font-size:12px; color:#64748b; margin-top:-4px;">Kuota:</div>
                    <input type="number" name="kuota_<?= $dayLower ?>" id="qe_kuota_<?= $dayLower ?>" value="<?= $totalKuota ?>" min="0" style="width:100%;padding:6px;border:1.5px solid #e5e7eb;border-radius:6px;font-size:14px;background:#fff;">
                    
                    <div style="font-size:12px; margin-top:2px; font-weight:600; color: <?= $sisaKuota < 0 ? '#ef4444' : '#10b981' ?>;">
                        Sisa Kuota: <?= $sisaKuota ?>
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
<?php endif; ?>

<!-- ===================================================
     CARD: GENERATE JADWAL
     =================================================== -->
<div class="card" style="border: 2px dashed #3b82f6; background-color: #eff6ff;">
    <div class="card-header" style="background-color: transparent; color: #1e40af;">⚡ Generate Jadwal</div>
    <div class="card-body">
        <p style="color: #1e3a8a; font-size: 14px; margin-top: 0; margin-bottom: 16px;">Sistem akan membagi mahasiswa yang belum memiliki kelas ke dalam kelas yang tersedia, menugaskan dosen secara otomatis, dan mempersiapkan kelas untuk penempatan ruangan.</p>
        <form method="POST" action="tutorial-generate.php" data-no-spa>
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
            
            <div style="background: #fff; padding: 16px; border-radius: 8px; border: 1px solid #bfdbfe; margin-bottom: 16px;">
                <label style="display: block; font-weight: 600; color: #1e3a8a; margin-bottom: 12px;">Mode Pembagian Mahasiswa:</label>
                
                <label style="display: flex; align-items: flex-start; gap: 8px; margin-bottom: 12px; cursor: pointer;">
                    <input type="radio" name="generate_mode" value="fill_first" id="modeFillFirst" style="margin-top: 4px;">
                    <div>
                        <span style="font-weight: 500; color: #334155;">Utamakan memenuhi kuota per kelas</span>
                        <div style="font-size: 13px; color: #64748b; margin-top: 4px;">
                            Jumlah mahasiswa per kelas minimal <input type="number" name="min_per_class" id="minPerClass" value="30" min="1" style="width: 60px; padding: 4px; border: 1px solid #cbd5e1; border-radius: 4px; text-align: center;"> lalu buat kelas baru.
                        </div>
                    </div>
                </label>
                
                <label style="display: flex; align-items: flex-start; gap: 8px; cursor: pointer;">
                    <input type="radio" name="generate_mode" value="distribute_evenly" id="modeDistribute" checked style="margin-top: 4px;">
                    <div>
                        <span style="font-weight: 500; color: #334155;">Utamakan meratakan jumlah mahasiswa</span>
                        <div style="font-size: 13px; color: #64748b; margin-top: 4px;">
                            Membagi rata jumlah mahasiswa kepada seluruh dosen yang aktif pada hari tersebut.
                        </div>
                    </div>
                </label>
            </div>
            
            <button type="submit" class="btn btn-primary" style="background-color: #2563eb; width: auto;">🚀 Generate Jadwal secara otomatis</button>
        </form>
        <script>
            document.getElementById('modeFillFirst').addEventListener('change', function() {
                document.getElementById('minPerClass').focus();
            });
            document.getElementById('minPerClass').addEventListener('click', function() {
                document.getElementById('modeFillFirst').checked = true;
            });
        </script>
    </div>
</div>



<!-- ===================================================
     CARD: DAFTAR PESERTA
     =================================================== -->
<div class="card" style="margin-top: 30px;">
    <div class="card-header" style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:16px; padding:16px 20px; background:#f8fafc; border-bottom:1px solid #e2e8f0;">
        <span style="font-size:16px; font-weight:600; color:#1e293b; display:flex; align-items:center; gap:8px;">
            <span style="font-size:20px;">📋</span> Daftar Peserta Tutorial (<span class="badge-count" style="background:#e0e7ff; color:#4f46e5; padding:2px 8px; border-radius:12px; font-size:14px;">0</span>)
        </span>
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

function openEditModal(regId, classId, dosen, ruangan) {
    document.getElementById('edit_reg_id').value = regId;
    document.getElementById('edit_class_id').value = classId;
    document.getElementById('display_dosen').textContent = classDosenMap[classId] || '-';
    document.getElementById('edit_ruangan').value = ruangan;
    document.getElementById('editPesertaModal').style.display = 'flex';
}

document.getElementById('edit_class_id').addEventListener('change', function() {
    var classId = this.value;
    document.getElementById('display_dosen').textContent = classDosenMap[classId] || '-';
});

function closeEditModal() {
    document.getElementById('editPesertaModal').style.display = 'none';
}

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
                        }
                        if (emptyState) emptyState.style.display = 'none';
                        if (tableDiv) tableDiv.style.display = 'block';
                        var bulkContainer = document.getElementById('bulkActionsContainer');
                        if (bulkContainer) bulkContainer.style.display = 'block';
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

            // Logic untuk Bulk Delete
            var btnBulkDelete = document.getElementById('btnBulkDelete');
            if (btnBulkDelete) {
                btnBulkDelete.addEventListener('click', function() {
                    var checkedBoxes = [];
                    if ($.fn.DataTable.isDataTable(tableEl)) {
                        var dt = tableEl.DataTable();
                        $(dt.rows({ search: 'applied' }).nodes()).find('.check-peserta:checked').each(function() {
                            checkedBoxes.push(this.value);
                        });
                    } else {
                        document.querySelectorAll('#participantTable tbody .check-peserta:checked').forEach(function(cb) {
                            checkedBoxes.push(cb.value);
                        });
                    }
                    
                    if (checkedBoxes.length === 0) {
                        alert('Silakan centang minimal satu peserta terlebih dahulu.');
                        return;
                    }
                    
                    if (confirm('Yakin ingin mengeluarkan ' + checkedBoxes.length + ' peserta yang dicentang dari kelas ini?')) {
                        var form = document.getElementById('formBulkDelete');
                        checkedBoxes.forEach(function(id) {
                            var input = document.createElement('input');
                            input.type = 'hidden';
                            input.name = 'reg_ids[]';
                            input.value = id;
                            form.appendChild(input);
                        });
                        form.submit();
                    }
                });
            }
        }
    }, 500);

})();
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
