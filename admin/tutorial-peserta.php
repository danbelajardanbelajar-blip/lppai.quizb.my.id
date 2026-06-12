<?php
/**
 * LPPAI Corner - Admin: Data Peserta Tutorial
 */
define('PAGE_TITLE', 'Data Peserta Tutorial');
require_once __DIR__ . '/../includes/auth.php';
requireAdmin();

$pdo = getDBConnection();
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

        /* ---- HAPUS ---- */
        } elseif ($action === 'delete') {
            $regId = (int)($_POST['reg_id'] ?? 0);
            $pdo->prepare("DELETE FROM tutorial_registrations WHERE id = ?")->execute([$regId]);
            $message = 'Data berhasil dihapus.';
            $msgType = 'success';
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

// Kumpulkan hari unik dari kelas yang ada
$hariList = [];
foreach ($classes as $c) {
    if (!empty($c['hari']) && !in_array($c['hari'], $hariList)) {
        $hariList[] = $c['hari'];
    }
}
sort($hariList);

// Kumpulkan tutor unik dari kelas yang ada
$tutorList = [];
foreach ($classes as $c) {
    if (!empty($c['dosen_pengampu']) && !in_array($c['dosen_pengampu'], $tutorList)) {
        $tutorList[] = $c['dosen_pengampu'];
    }
}
sort($tutorList);

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

include __DIR__ . '/../includes/header.php';
?>

<?php if ($message): ?>
    <div class="alert alert-<?= $msgType ?>"><?= sanitize($message) ?></div>
<?php endif; ?>

<!-- ===================================================
     CARD: GENERATE JADWAL
     =================================================== -->
<div class="card" style="border: 2px dashed #3b82f6; background-color: #eff6ff;">
    <div class="card-header" style="background-color: transparent; color: #1e40af;">⚡ Generate Jadwal</div>
    <div class="card-body">
        <p style="color: #1e3a8a; font-size: 14px; margin-top: 0;">Sistem akan membagi rata mahasiswa yang belum memiliki kelas ke dalam kelas yang tersedia, menugaskan dosen secara otomatis, dan mempersiapkan kelas untuk penempatan ruangan.</p>
        <form method="POST" action="tutorial-generate.php" data-no-spa>
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
            <button type="submit" class="btn btn-primary" style="background-color: #2563eb; width: auto;">🚀 Generate Jadwal secara otomatis</button>
        </form>
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

        <div class="table-responsive" id="tableResponsiveContainer" style="display: none;">
            <table id="participantTable">
                <thead>
                    <tr>
                        <th>Nama Kelas</th>
                        <th>Nama Dosen</th>
                        <th>Nama Mahasiswa</th>
                        <th>Nama Ruang</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($registrations as $r): ?>
                    <tr data-class-id="<?= $r['tutorial_class_id'] ?>">
                        <td><strong><?= sanitize($r['nama_kelas']) ?></strong></td>
                        <td><?= sanitize($r['dosen_pengampu'] ?: '-') ?></td>
                        <td><?= sanitize($r['nama_lengkap']) ?></td>
                        <td><span class="badge badge-success"><?= sanitize($r['ruangan'] ?: 'Belum Ada Ruang') ?></span></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
(function () {
    var filterKelasBottom = document.getElementById('filterKelasBottom');

    /* ---- Filter tabel bawah berdasarkan Kelas Tutorial yang dipilih ---- */
    setTimeout(function() {
        if (window.jQuery && window.jQuery.fn.DataTable) {
            var $ = window.jQuery;
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
                        } else {
                            if (emptyState) emptyState.style.display = 'none';
                            if (tableDiv) tableDiv.style.display = 'block';
                        }
                    } else {
                        if (emptyState) emptyState.style.display = 'none';
                        if (tableDiv) tableDiv.style.display = 'block';
                    }
                }
            });
            
            setTimeout(function() {
                filterKelasBottom.dispatchEvent(new Event('change'));
            }, 100);
        }
    }, 500);

})();
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
