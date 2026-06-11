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
            $mataKuliah = trim($_POST['mata_kuliah'] ?? '');
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

            if (empty($namaKelas) || empty($mataKuliah) || empty($userIds)) {
                $message = 'Isi Nama Kelas, Mata Kuliah, dan pilih minimal satu mahasiswa.';
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
    SELECT tr.*, u.nama_lengkap, u.nim, u.program_studi, tc.nama_kelas, tc.mata_kuliah, tc.gelombang, tc.hari
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
     CARD: TAMBAH PESERTA
     =================================================== --><div class="card">
    <div class="card-header">➕ Buat Kelas & Tambah Peserta</div>
    <div class="card-body">
        <form method="POST" id="assignForm" data-no-spa>
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
            <input type="hidden" name="action" value="assign">

            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:16px;margin-bottom:20px;">
                <div class="form-group" style="margin-bottom:0;">
                    <label>Nama Kelas *</label>
                    <input type="text" name="nama_kelas" placeholder="Kelas A" required
                        style="width:100%;padding:11px 14px;border:1.5px solid #e5e7eb;border-radius:10px;font-size:14px;font-family:inherit;background:#fff;">
                </div>
                <div class="form-group" style="margin-bottom:0;">
                    <label>Mata Kuliah *</label>
                    <input type="text" name="mata_kuliah" placeholder="Bahasa Arab Dasar" required
                        style="width:100%;padding:11px 14px;border:1.5px solid #e5e7eb;border-radius:10px;font-size:14px;font-family:inherit;background:#fff;">
                </div>
                <div class="form-group" style="margin-bottom:0;">
                    <label>Dosen Pengampu</label>
                    <select name="dosen_pengampu"
                        style="width:100%;padding:11px 14px;border:1.5px solid #e5e7eb;border-radius:10px;font-size:14px;font-family:inherit;background:#fff;">
                        <option value="">-- Pilih Dosen --</option>
                        <?php foreach ($tutorsList as $t): ?>
                        <option value="<?= sanitize($t['nama']) ?>"><?= sanitize($t['nama']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group" style="margin-bottom:0;">
                    <label>Hari</label>
                    <select name="hari"
                        style="width:100%;padding:11px 14px;border:1.5px solid #e5e7eb;border-radius:10px;font-size:14px;font-family:inherit;background:#fff;">
                        <option value="">-- Pilih Hari --</option>
                        <option value="Senin">Senin</option>
                        <option value="Selasa">Selasa</option>
                        <option value="Rabu">Rabu</option>
                        <option value="Kamis">Kamis</option>
                        <option value="Jumat">Jumat</option>
                        <option value="Sabtu">Sabtu</option>
                        <option value="Ahad">Ahad</option>
                    </select>
                </div>
                <div class="form-group" style="margin-bottom:0;">
                    <label>Jam</label>
                    <select name="jam"
                        style="width:100%;padding:11px 14px;border:1.5px solid #e5e7eb;border-radius:10px;font-size:14px;font-family:inherit;background:#fff;">
                        <option value="">-- Pilih Jam --</option>
                        <option value="08:00-09:30">08:00 - 09:30</option>
                        <option value="10:00-11:30">10:00 - 11:30</option>
                        <option value="13:00-14:30">13:00 - 14:30</option>
                        <option value="15:30-17:00">15:30 - 17:00</option>
                        <option value="18:30-20:00">18:30 - 20:00</option>
                    </select>
                </div>
                <div class="form-group" style="margin-bottom:0;">
                    <label>Ruangan</label>
                    <select name="ruangan"
                        style="width:100%;padding:11px 14px;border:1.5px solid #e5e7eb;border-radius:10px;font-size:14px;font-family:inherit;background:#fff;">
                        <option value="">-- Pilih Ruangan --</option>
                        <?php foreach ($roomsList as $rm): ?>
                        <option value="<?= sanitize($rm['ruang']) ?>"><?= sanitize($rm['ruang']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group" style="margin-bottom:0;">
                    <label>Kuota</label>
                    <input type="number" name="kuota" min="0" placeholder="30"
                        style="width:100%;padding:11px 14px;border:1.5px solid #e5e7eb;border-radius:10px;font-size:14px;font-family:inherit;background:#fff;">
                </div>
            </div></div>

            <!-- Baris 2: Daftar Mahasiswa dengan Checkbox -->
            <div class="form-group" style="margin-bottom:20px;">
                <label style="margin-bottom:8px;display:block;">
                    Pilih Mahasiswa <span style="color:#ef4444;">*</span>
                    <span id="selectedCount" style="margin-left:8px;font-size:12px;font-weight:400;color:#6b7280;">
                        (0 dipilih)
                    </span>
                </label>

                <!-- Search + Pilih Semua -->
                <div style="display:flex;gap:10px;margin-bottom:8px;align-items:center;flex-wrap:wrap;">
                    <input type="text" id="searchMhs" placeholder="🔍 Cari nama atau NIM..."
                        style="flex:1;min-width:200px;padding:8px 12px;border:1.5px solid #e5e7eb;border-radius:8px;font-size:13px;font-family:inherit;">
                    <label style="display:flex;align-items:center;gap:6px;cursor:pointer;font-size:13px;font-weight:600;color:var(--primary);white-space:nowrap;user-select:none;">
                        <input type="checkbox" id="selectAll" style="width:16px;height:16px;cursor:pointer;accent-color:var(--primary);">
                        Pilih Semua
                    </label>
                </div>

                <!-- List mahasiswa -->
                <div id="studentList"
                    style="border:1.5px solid #e5e7eb;border-radius:10px;max-height:320px;overflow-y:auto;background:#fafafa;">

                    <?php if (empty($students)): ?>
                        <div style="padding:24px;text-align:center;color:#9ca3af;">Belum ada data mahasiswa.</div>
                    <?php else: ?>
                        <?php foreach ($students as $s): ?>
                        <label class="student-row"
                            data-nama="<?= strtolower(htmlspecialchars($s['nama_lengkap'], ENT_QUOTES)) ?>"
                            data-nim="<?= strtolower(htmlspecialchars($s['nim'] ?? '', ENT_QUOTES)) ?>"
                            style="display:flex;align-items:center;gap:12px;padding:10px 14px;cursor:pointer;border-bottom:1px solid #f0f0f0;transition:background .15s;">
                            <input type="checkbox" name="user_ids[]" value="<?= $s['id'] ?>"
                                class="student-cb"
                                style="width:16px;height:16px;flex-shrink:0;cursor:pointer;accent-color:var(--primary);">
                            <div style="flex:1;min-width:0;">
                                <div style="font-weight:600;font-size:14px;color:#111827;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">
                                    <?= sanitize($s['nama_lengkap']) ?>
                                </div>
                                <div style="font-size:12px;color:#6b7280;">
                                    <?= sanitize($s['nim'] ?? '-') ?>
                                    <?php if (!empty($s['program_studi'])): ?>
                                        · <?= sanitize($s['program_studi']) ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </label>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
                <button type="submit" class="btn btn-primary" style="width:auto;">
                    📋 Buat Kelas & Tambah Peserta
                </button>
                <button type="button" class="btn btn-secondary" style="width:auto;" onclick="clearSelection()">
                    ✕ Batal Pilihan
                </button>
                <span id="submitHint" style="font-size:12px;color:#6b7280;"></span>
            </div>
        </form>
    </div>
</div>

<!-- ===================================================
     CARD: DAFTAR PESERTA
     =================================================== -->
<div class="card">
    <div class="card-header" style="display:flex; justify-content:space-between; align-items:center;">
        <span>📋 Daftar Peserta Tutorial (<span class="badge-count">0</span>)</span>
        <div style="width: 250px;">
            <select id="filterKelasBottom"
                style="width:100%;padding:6px 12px;border:1.5px solid #e5e7eb;border-radius:8px;font-size:13px;font-family:inherit;background:#fff;font-weight:normal;color:#333;">
                <option value="">-- Pilih Kelas --</option>
                <?php foreach ($classes as $c): ?>
                    <option value="<?= $c['id'] ?>">
                        <?= sanitize($c['nama_kelas']) ?> - <?= sanitize($c['mata_kuliah']) ?>
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
                        <th>No</th>
                        <th>Nama</th>
                        <th>NIM</th>
                        <th>Prodi</th>
                        <th>Kelas</th>
                        <th>Hari</th>
                        <th>Gel.</th>
                        <th>Status</th>
                        <th>Nilai</th>
                        <th>Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($registrations as $i => $r): ?>
                    <tr data-class-id="<?= $r['tutorial_class_id'] ?>">
                        <td><?= $i + 1 ?></td>
                        <td><strong><?= sanitize($r['nama_lengkap']) ?></strong></td>
                        <td><?= sanitize($r['nim']) ?></td>
                        <td><?= sanitize($r['program_studi']) ?></td>
                        <td><?= sanitize($r['nama_kelas']) ?> — <?= sanitize($r['mata_kuliah']) ?></td>
                        <td><?= sanitize($r['hari'] ?? '-') ?></td>
                        <td><span class="badge badge-primary"><?= $gelLabels[$r['gelombang']] ?></span></td>
                        <td>
                            <form method="POST" style="display:inline;">
                                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                                <input type="hidden" name="action" value="update_status">
                                <input type="hidden" name="reg_id" value="<?= $r['id'] ?>">
                                <select name="status" onchange="this.form.submit()"
                                    style="padding:4px 8px;border-radius:6px;border:1px solid #ddd;font-size:12px;">
                                    <?php foreach (['terdaftar','aktif','lulus','tidak_lulus','mengundurkan_diri'] as $st): ?>
                                        <option value="<?= $st ?>" <?= $r['status'] === $st ? 'selected' : '' ?>>
                                            <?= ucfirst(str_replace('_',' ',$st)) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <input type="hidden" name="nilai" value="<?= $r['nilai_akhir'] ?? '' ?>">
                            </form>
                        </td>
                        <td>
                            <form method="POST" style="display:inline;">
                                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                                <input type="hidden" name="action" value="update_status">
                                <input type="hidden" name="reg_id" value="<?= $r['id'] ?>">
                                <input type="hidden" name="status" value="<?= $r['status'] ?>">
                                <input type="number" name="nilai" step="0.1" min="0" max="100"
                                    value="<?= $r['nilai_akhir'] ?? '' ?>"
                                    style="width:70px;padding:4px;border-radius:6px;border:1px solid #ddd;font-size:12px;"
                                    onchange="this.form.submit()" placeholder="-">
                            </form>
                        </td>
                        <td>
                            <form method="POST" style="display:inline;">
                                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="reg_id" value="<?= $r['id'] ?>">
                                <button type="submit" class="btn btn-sm btn-danger"
                                    data-confirm="Hapus data ini?"
                                    data-table="tutorial_registrations"
                                    data-id="<?= $r['id'] ?>">Hapus</button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<style>
.student-row:hover { background: #f0f9ff; }
.student-row:has(.student-cb:checked) { background: #eff6ff; }
.student-row:last-child { border-bottom: none; }
#studentList::-webkit-scrollbar { width: 6px; }
#studentList::-webkit-scrollbar-track { background: #f1f5f9; border-radius: 3px; }
#studentList::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 3px; }
</style>

<script>
(function () {
    var searchInput  = document.getElementById('searchMhs');
    var selectAll    = document.getElementById('selectAll');
    var countBadge   = document.getElementById('selectedCount');
    var submitHint   = document.getElementById('submitHint');
    var filterKelasBottom = document.getElementById('filterKelasBottom');

    /* ---- Helper: semua baris yang saat ini terlihat ---- */
    function visibleRows() {
        return Array.from(document.querySelectorAll('.student-row'))
            .filter(function(row) { return row.style.display !== 'none'; });
    }

    /* ---- Update badge hitungan ---- */
    function updateCount() {
        var checked = document.querySelectorAll('.student-cb:checked').length;
        countBadge.textContent = '(' + checked + ' dipilih)';
        submitHint.textContent = checked > 0
            ? checked + ' mahasiswa akan dimasukkan ke kelas yang akan dibuat.'
            : '';

        // Sinkronisasi checkbox "pilih semua"
        var vis = visibleRows();
        if (vis.length === 0) {
            selectAll.indeterminate = false;
            selectAll.checked = false;
        } else {
            var visChecked = vis.filter(function(r) {
                return r.querySelector('.student-cb').checked;
            }).length;
            selectAll.indeterminate = visChecked > 0 && visChecked < vis.length;
            selectAll.checked = visChecked === vis.length;
        }
    }

    /* ---- Filter teks ---- */
    searchInput.addEventListener('input', function () {
        var q = this.value.toLowerCase().trim();
        document.querySelectorAll('.student-row').forEach(function(row) {
            var match = !q
                || row.dataset.nama.includes(q)
                || row.dataset.nim.includes(q);
            row.style.display = match ? '' : 'none';
        });
        updateCount();
    });

    /* ---- Pilih Semua (hanya baris terlihat) ---- */
    selectAll.addEventListener('change', function () {
        var check = this.checked;
        visibleRows().forEach(function(row) {
            row.querySelector('.student-cb').checked = check;
        });
        updateCount();
    });

    /* ---- Checkbox individual ---- */
    document.getElementById('studentList').addEventListener('change', function(e) {
        if (e.target.classList.contains('student-cb')) updateCount();
    });

    /* ---- Filter tabel bawah berdasarkan Kelas Tutorial yang dipilih ---- */
    setTimeout(function() {
        if (window.jQuery && window.jQuery.fn.DataTable) {
            var $ = window.jQuery;
            var tableEl = $('#participantTable');
            
            $.fn.dataTable.ext.search.push(function(settings, data, dataIndex) {
                if (settings.nTable.id !== 'participantTable') return true;
                
                var selectedClassId = filterKelasBottom.value;
                if (!selectedClassId) return false;
                
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
                
                if (!selectedClassId) {
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

    /* ---- Validasi sebelum submit ---- */
    document.getElementById('assignForm').addEventListener('submit', function(e) {
        var anyCheck = document.querySelector('.student-cb:checked');
        if (!anyCheck) {
            e.preventDefault();
            alert('Pilih minimal satu mahasiswa.');
        }
    });

    updateCount();
})();

function clearSelection() {
    document.querySelectorAll('.student-cb').forEach(function(cb) { cb.checked = false; });
    document.getElementById('selectAll').checked = false;
    document.getElementById('searchMhs').value = '';
    document.querySelectorAll('.student-row').forEach(function(r) { r.style.display = ''; });
    document.getElementById('selectedCount').textContent = '(0 dipilih)';
    document.getElementById('submitHint').textContent = '';
}
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
