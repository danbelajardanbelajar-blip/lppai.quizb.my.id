<?php
/**
 * LPPAI Corner - Rekapitulasi Nilai Terpadu
 */
define('PAGE_TITLE', 'Rekapitulasi Nilai');
require_once __DIR__ . '/includes/auth.php';
requireLogin();

$user = getCurrentUser();
$pdo = getDBConnection();

$isAdmin = isAdmin();
$isDosen = isDosen();
$isMahasiswa = !$isAdmin && !$isDosen;

$action = $_GET['action'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'edit_nilai') {
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        die("Invalid CSRF token.");
    }
    
    $reg_id = $_POST['reg_id'] ?? '';
    $thaharah = $_POST['nilai_thaharah'] !== '' ? (float)$_POST['nilai_thaharah'] : null;
    $shalat = $_POST['nilai_shalat'] !== '' ? (float)$_POST['nilai_shalat'] : null;
    $srt_pdk = $_POST['nilai_surat_pendek'] !== '' ? (float)$_POST['nilai_surat_pendek'] : null;
    $amaliyah = $_POST['nilai_amaliyah'] !== '' ? (float)$_POST['nilai_amaliyah'] : null;
    $jenazah = $_POST['nilai_jenazah'] !== '' ? (float)$_POST['nilai_jenazah'] : null;
    $ut = $_POST['nilai_ujian_tulis'] !== '' ? (float)$_POST['nilai_ujian_tulis'] : null;
    
    if ($reg_id && ($isAdmin || $isDosen)) {
        $can_edit = true;
        if ($isDosen) {
            $check_stmt = $pdo->prepare("
                SELECT tc.dosen_pengampu 
                FROM tutorial_registrations tr
                JOIN tutorial_classes tc ON tr.tutorial_class_id = tc.id
                WHERE tr.id = ?
            ");
            $check_stmt->execute([$reg_id]);
            $res = $check_stmt->fetch();
            if (!$res || $res['dosen_pengampu'] !== $user['nama_lengkap']) {
                $can_edit = false;
            }
        }
        
        if ($can_edit) {
            $upd = $pdo->prepare("
                UPDATE tutorial_registrations 
                SET nilai_thaharah = ?, nilai_shalat = ?, nilai_surat_pendek = ?, 
                    nilai_amaliyah = ?, nilai_jenazah = ?, nilai_ujian_tulis = ?
                WHERE id = ?
            ");
            $upd->execute([$thaharah, $shalat, $srt_pdk, $amaliyah, $jenazah, $ut, $reg_id]);
        }
    }
    exit('success');
}

if ($isAdmin || $isDosen) {
    // 1. Fetch daftar kelas untuk Dropdown Filter
    if ($isAdmin) {
        $stmt = $pdo->query("
            SELECT tc.nama_kelas, tc.gelombang, GROUP_CONCAT(DISTINCT tc.dosen_pengampu SEPARATOR ', ') as dosen_pengampu, COUNT(tr.id) as jml_mhs
            FROM tutorial_classes tc
            LEFT JOIN tutorial_registrations tr ON tc.id = tr.tutorial_class_id
            WHERE (tc.semester LIKE '%2026%' OR tc.semester LIKE '%2027%' OR tc.semester LIKE '%2028%' OR tc.semester LIKE '%2029%' OR tc.semester LIKE '%2030%')
            GROUP BY tc.gelombang, tc.nama_kelas
            ORDER BY tc.gelombang ASC, tc.nama_kelas ASC
        ");
        $unique_classes = $stmt->fetchAll();
    } else {
        $stmt = $pdo->prepare("
            SELECT tc.nama_kelas, tc.gelombang, GROUP_CONCAT(DISTINCT tc.dosen_pengampu SEPARATOR ', ') as dosen_pengampu, COUNT(tr.id) as jml_mhs
            FROM tutorial_classes tc
            LEFT JOIN tutorial_registrations tr ON tc.id = tr.tutorial_class_id
            WHERE tc.dosen_pengampu = ? AND (tc.semester LIKE '%2026%' OR tc.semester LIKE '%2027%' OR tc.semester LIKE '%2028%' OR tc.semester LIKE '%2029%' OR tc.semester LIKE '%2030%')
            GROUP BY tc.gelombang, tc.nama_kelas
            ORDER BY tc.gelombang ASC, tc.nama_kelas ASC
        ");
        $stmt->execute([$user['nama_lengkap']]);
        $unique_classes = $stmt->fetchAll();
    }
    
    $filter_class = $_GET['class_name'] ?? '';
    $filter_ta = $_GET['tahun_ajaran'] ?? '';
    $students = [];
    
    // 2. Build Query untuk mengambil mahasiswa (Filtered)
    $sql = "
        SELECT tr.*, u.nama_lengkap, u.nim, u.program_studi, tc.nama_kelas, tc.gelombang
        FROM tutorial_registrations tr
        JOIN users u ON tr.user_id = u.id
        JOIN tutorial_classes tc ON tr.tutorial_class_id = tc.id
    ";
    $params = [];
    
    if ($isDosen) {
        $sql .= " WHERE tc.dosen_pengampu = ? AND (tc.semester LIKE '%2026%' OR tc.semester LIKE '%2027%' OR tc.semester LIKE '%2028%' OR tc.semester LIKE '%2029%' OR tc.semester LIKE '%2030%')";
        $params[] = $user['nama_lengkap'];
    } else {
        $sql .= " WHERE (tc.semester LIKE '%2026%' OR tc.semester LIKE '%2027%' OR tc.semester LIKE '%2028%' OR tc.semester LIKE '%2029%' OR tc.semester LIKE '%2030%')";
    }
    
    if ($filter_class !== '') {
        $sql .= " AND tc.nama_kelas = ?";
        $params[] = $filter_class;
    }
    if ($filter_ta !== '') {
        $sql .= " AND tc.semester LIKE ?";
        $params[] = $filter_ta . '%';
    }
    
    $sql .= " ORDER BY tc.gelombang ASC, tc.nama_kelas ASC, u.nama_lengkap ASC";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $students = $stmt->fetchAll();
    
    // 3. EXPORT LOGIC
    if ($action === 'export') {
        $possible_paths = [
            __DIR__ . '/vendor/autoload.php',
            __DIR__ . '/../vendor/autoload.php',
            __DIR__ . '/../../vendor/autoload.php',
            __DIR__ . '/../../../vendor/autoload.php',
            $_SERVER['DOCUMENT_ROOT'] . '/vendor/autoload.php',
            $_SERVER['DOCUMENT_ROOT'] . '/../vendor/autoload.php',
            '/public_html/vendor/autoload.php'
        ];
        
        $vendorPath = null;
        foreach ($possible_paths as $path) {
            if (file_exists($path)) {
                $vendorPath = $path;
                break;
            }
        }
        
        if (!$vendorPath) {
            die("<div style='padding:20px; color:red; font-family:sans-serif;'>Library Excel (PhpSpreadsheet) tidak ditemukan di server. Harap pastikan folder vendor ada di public_html.</div>");
        }
        
        require_once $vendorPath;
        
        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        
        $sheet->setCellValue('A1', 'No');
        $sheet->setCellValue('B1', 'Gelombang');
        $sheet->setCellValue('C1', 'Kelas');
        $sheet->setCellValue('D1', 'NIM');
        $sheet->setCellValue('E1', 'Nama Lengkap');
        $sheet->setCellValue('F1', 'Prodi');
        $sheet->setCellValue('G1', 'Thaharah');
        $sheet->setCellValue('H1', 'Shalat');
        $sheet->setCellValue('I1', 'Surat Pendek');
        $sheet->setCellValue('J1', 'Amaliyah');
        $sheet->setCellValue('K1', 'Jenazah');
        $sheet->setCellValue('L1', 'Ujian Tulis');
        $sheet->setCellValue('M1', 'Nilai Akhir');
        
        // Style headers
        $headerStyle = [
            'font' => ['bold' => true],
            'fill' => ['fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID, 'startColor' => ['rgb' => 'E2E8F0']],
            'borders' => ['allBorders' => ['borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN]]
        ];
        $sheet->getStyle('A1:M1')->applyFromArray($headerStyle);
        
        $row = 2;
        $no = 1;
        foreach($students as $s) {
            $sheet->setCellValue('A'.$row, $no++);
            $sheet->setCellValueExplicit('B'.$row, $s['gelombang'], \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
            $sheet->setCellValue('C'.$row, $s['nama_kelas']);
            $sheet->setCellValueExplicit('D'.$row, $s['nim'], \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
            $sheet->setCellValue('E'.$row, $s['nama_lengkap']);
            $sheet->setCellValue('F'.$row, $s['program_studi']);
            $sheet->setCellValue('G'.$row, $s['nilai_thaharah'] ?? '-');
            $sheet->setCellValue('H'.$row, $s['nilai_shalat'] ?? '-');
            $sheet->setCellValue('I'.$row, $s['nilai_surat_pendek'] ?? '-');
            $sheet->setCellValue('J'.$row, $s['nilai_amaliyah'] ?? '-');
            $sheet->setCellValue('K'.$row, $s['nilai_jenazah'] ?? '-');
            $sheet->setCellValue('L'.$row, $s['nilai_ujian_tulis'] ?? '-');
            
            $sum = 0; $count = 0;
            if ($s['nilai_thaharah'] !== null) { $sum += (float)$s['nilai_thaharah']; $count++; }
            if ($s['nilai_shalat'] !== null) { $sum += (float)$s['nilai_shalat']; $count++; }
            if ($s['nilai_surat_pendek'] !== null) { $sum += (float)$s['nilai_surat_pendek']; $count++; }
            if ($s['nilai_amaliyah'] !== null) { $sum += (float)$s['nilai_amaliyah']; $count++; }
            if ($s['nilai_jenazah'] !== null) { $sum += (float)$s['nilai_jenazah']; $count++; }
            if ($s['nilai_ujian_tulis'] !== null) { $sum += (float)$s['nilai_ujian_tulis']; $count++; }
            $calc_akhir = $count > 0 ? round($sum / $count, 2) : '-';
            
            $sheet->setCellValue('M'.$row, $calc_akhir);
            
            $sheet->getStyle('A'.$row.':M'.$row)->getBorders()->getAllBorders()->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN);
            $row++;
        }
        
        foreach(range('A','M') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }
        
        $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
        $filename = 'Rekap_Nilai_Mahasiswa_' . ($filter_class ?: 'Semua') . '_' . date('Ymd') . '.xlsx';
        
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="'.urlencode($filename).'"');
        header('Cache-Control: max-age=0');
        
        $writer->save('php://output');
        exit;
    }
} else {
    // Mahasiswa Logic
    $stmt = $pdo->prepare("
        SELECT tr.*, tc.nama_kelas, tc.dosen_pengampu
        FROM tutorial_registrations tr
        JOIN tutorial_classes tc ON tr.tutorial_class_id = tc.id
        WHERE tr.user_id = ? AND (tc.semester LIKE '%2026%' OR tc.semester LIKE '%2027%' OR tc.semester LIKE '%2028%' OR tc.semester LIKE '%2029%' OR tc.semester LIKE '%2030%')
        ORDER BY tr.created_at DESC
    ");
    $stmt->execute([$user['id']]);
    $my_classes = $stmt->fetchAll();
}

include __DIR__ . '/includes/header.php';
?>

<div class="mb-4">
    <h2 class="page-title"><?= PAGE_TITLE ?></h2>
    <?php if ($isAdmin): ?>
        <p class="text-muted">Kelola dan ekspor data nilai dari semua mahasiswa dan kelas tutorial.</p>
    <?php elseif ($isDosen): ?>
        <p class="text-muted">Pantau nilai mahasiswa dari kelas yang Anda ampu.</p>
    <?php else: ?>
        <p class="text-muted">Lihat rincian nilai kelas tutorial yang Anda ikuti.</p>
    <?php endif; ?>
</div>

<?php if ($isAdmin || $isDosen): ?>
    
    <!-- Filter Card -->
    <div class="card" style="margin-bottom:20px;">
        <div class="card-body">
            <form method="GET" style="display:flex; gap:10px; align-items:flex-end; flex-wrap:wrap;">
                <div style="flex:1; min-width: 200px;">
                    <label style="display:block; margin-bottom:5px; font-weight:bold;">Tahun Ajaran</label>
                    <select name="tahun_ajaran" class="form-control" style="width:100%; padding:8px; border:1px solid #cbd5e1; border-radius:4px;" onchange="this.form.submit()">
                        <option value="">-- Semua Tahun Ajaran --</option>
                        <?php
                        $startYear = 2026;
                        $endYear = 2050;
                        for ($y = $startYear; $y < $endYear; $y++) {
                            $label = $y . '-' . ($y + 1);
                            $selected = ($filter_ta === $label) ? 'selected' : '';
                            echo "<option value=\"$label\" $selected>$label</option>";
                        }
                        ?>
                    </select>
                </div>
                <div style="flex:1; min-width: 200px;">
                    <label style="display:block; margin-bottom:5px; font-weight:bold;">Filter Kelas</label>
                    <select name="class_name" class="form-control" style="width:100%; padding:8px; border:1px solid #cbd5e1; border-radius:4px;" onchange="this.form.submit()">
                        <option value="">-- Tampilkan Semua Kelas --</option>
                        <?php foreach($unique_classes as $c): ?>
                            <option value="<?= sanitize($c['nama_kelas']) ?>" <?= $filter_class == $c['nama_kelas'] ? 'selected' : '' ?>>
                                <?= sanitize($c['nama_kelas']) ?> (Gel. <?= sanitize($c['gelombang']) ?>) - <?= sanitize($c['dosen_pengampu'] ?: 'Tanpa Dosen') ?> - <?= sanitize($c['jml_mhs']) ?> Mahasiswa
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </form>
        </div>
    </div>
    
    <div class="card">
        <div class="card-header" style="display:flex; justify-content:space-between; align-items:center;">
            <span>Daftar Nilai Mahasiswa: <strong><?= $filter_class !== '' ? sanitize($filter_class) : 'Semua Kelas' ?> <?= $filter_ta !== '' ? '('.sanitize($filter_ta).')' : '' ?></strong></span>
            <a href="?class_name=<?= urlencode($filter_class) ?>&tahun_ajaran=<?= urlencode($filter_ta) ?>&action=export" class="btn btn-sm btn-success" data-no-spa="true">📄 Export Excel</a>
        </div>
        <div class="card-body">
            <?php if (empty($students)): ?>
                <div class="empty-state">Belum ada data mahasiswa yang dapat ditampilkan.</div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="datatable">
                        <thead>
                            <tr>
                                <th width="40">No</th>
                                <th>Kelas</th>
                                <th>NIM</th>
                                <th>Nama Mahasiswa</th>
                                <th>Prodi</th>
                                <th>Thaharah</th>
                                <th>Shalat</th>
                                <th>Srt Pdk</th>
                                <th>Amaliyah</th>
                                <th>Jenazah</th>
                                <th>UT</th>
                                <th>Rata-Rata Akhir</th>
                                <th>Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php $no=1; foreach($students as $s): ?>
                            <tr>
                                <td align="center"><?= $no++ ?></td>
                                <td><?= sanitize($s['nama_kelas']) ?> <br><small class="text-muted">Gel. <?= sanitize($s['gelombang']) ?></small></td>
                                <td><?= sanitize($s['nim']) ?></td>
                                <td><strong><?= sanitize($s['nama_lengkap']) ?></strong></td>
                                <td><small><?= sanitize($s['program_studi']) ?></small></td>
                                <td align="center"><?= $s['nilai_thaharah'] !== null ? number_format($s['nilai_thaharah'], 1) : '-' ?></td>
                                <td align="center"><?= $s['nilai_shalat'] !== null ? number_format($s['nilai_shalat'], 1) : '-' ?></td>
                                <td align="center"><?= $s['nilai_surat_pendek'] !== null ? number_format($s['nilai_surat_pendek'], 1) : '-' ?></td>
                                <td align="center"><?= $s['nilai_amaliyah'] !== null ? number_format($s['nilai_amaliyah'], 1) : '-' ?></td>
                                <td align="center"><?= $s['nilai_jenazah'] !== null ? number_format($s['nilai_jenazah'], 1) : '-' ?></td>
                                <td align="center"><?= $s['nilai_ujian_tulis'] !== null ? number_format($s['nilai_ujian_tulis'], 1) : '-' ?></td>
                                <td align="center">
                                    <span class="badge badge-primary" style="font-size:14px;">
                                        <?php
                                        $sum = 0; $count = 0;
                                        if ($s['nilai_thaharah'] !== null) { $sum += (float)$s['nilai_thaharah']; $count++; }
                                        if ($s['nilai_shalat'] !== null) { $sum += (float)$s['nilai_shalat']; $count++; }
                                        if ($s['nilai_surat_pendek'] !== null) { $sum += (float)$s['nilai_surat_pendek']; $count++; }
                                        if ($s['nilai_amaliyah'] !== null) { $sum += (float)$s['nilai_amaliyah']; $count++; }
                                        if ($s['nilai_jenazah'] !== null) { $sum += (float)$s['nilai_jenazah']; $count++; }
                                        if ($s['nilai_ujian_tulis'] !== null) { $sum += (float)$s['nilai_ujian_tulis']; $count++; }
                                        echo $count > 0 ? number_format(round($sum / $count, 2), 2) : '-';
                                        ?>
                                    </span>
                                </td>
                                <td align="center">
                                    <button class="btn btn-sm btn-warning text-white" onclick="openEditNilaiModal(<?= $s['id'] ?>, '<?= sanitize(addslashes($s['nama_lengkap'])) ?>', '<?= sanitize($s['nim']) ?>', '<?= sanitize($s['nama_kelas']) ?>', '<?= $s['nilai_thaharah'] ?? '' ?>', '<?= $s['nilai_shalat'] ?? '' ?>', '<?= $s['nilai_surat_pendek'] ?? '' ?>', '<?= $s['nilai_amaliyah'] ?? '' ?>', '<?= $s['nilai_jenazah'] ?? '' ?>', '<?= $s['nilai_ujian_tulis'] ?? '' ?>')">✏️ Edit</button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

<?php else: ?>
    <!-- VIEW MAHASISWA -->
    <?php if (empty($my_classes)): ?>
        <div class="empty-state card">
            <p>Anda belum terdaftar di kelas tutorial manapun.</p>
        </div>
    <?php else: ?>
        <div style="display:grid; gap:20px;">
        <?php foreach ($my_classes as $mc): ?>
            <div class="card" style="border-top:4px solid var(--primary);">
                <div class="card-header">
                    <h3 style="margin:0;"><?= sanitize($mc['nama_kelas']) ?></h3>
                    <small style="color:#64748b;">Dosen: <?= sanitize($mc['dosen_pengampu']) ?></small>
                </div>
                <div class="card-body">
                    <div style="display:flex; flex-wrap:wrap; gap:20px; justify-content:space-between; align-items:center; margin-bottom:20px;">
                        <div style="flex:1;">
                            <strong>Ringkasan Kategori:</strong>
                            <ul style="margin-top:10px; color:#475569; display:grid; grid-template-columns:repeat(auto-fit, minmax(200px, 1fr)); gap:10px;">
                                <li>Thaharah: <strong><?= $mc['nilai_thaharah'] !== null ? number_format($mc['nilai_thaharah'], 1) : '-' ?></strong></li>
                                <li>Shalat: <strong><?= $mc['nilai_shalat'] !== null ? number_format($mc['nilai_shalat'], 1) : '-' ?></strong></li>
                                <li>Surat Pendek: <strong><?= $mc['nilai_surat_pendek'] !== null ? number_format($mc['nilai_surat_pendek'], 1) : '-' ?></strong></li>
                                <li>Amaliyah: <strong><?= $mc['nilai_amaliyah'] !== null ? number_format($mc['nilai_amaliyah'], 1) : '-' ?></strong></li>
                                <li>Jenazah: <strong><?= $mc['nilai_jenazah'] !== null ? number_format($mc['nilai_jenazah'], 1) : '-' ?></strong></li>
                                <li>Ujian Tulis: <strong><?= $mc['nilai_ujian_tulis'] !== null ? number_format($mc['nilai_ujian_tulis'], 1) : '-' ?></strong></li>
                            </ul>
                        </div>
                        <div style="text-align:center; padding:15px 30px; background:#f8fafc; border-radius:8px; border:1px solid #e2e8f0;">
                            <div style="font-size:12px; color:#64748b; font-weight:bold; margin-bottom:5px;">NILAI AKHIR</div>
                            <div style="font-size:32px; font-weight:900; color:var(--primary);">
                                <?php
                                $sum = 0; $count = 0;
                                if ($mc['nilai_thaharah'] !== null) { $sum += (float)$mc['nilai_thaharah']; $count++; }
                                if ($mc['nilai_shalat'] !== null) { $sum += (float)$mc['nilai_shalat']; $count++; }
                                if ($mc['nilai_surat_pendek'] !== null) { $sum += (float)$mc['nilai_surat_pendek']; $count++; }
                                if ($mc['nilai_amaliyah'] !== null) { $sum += (float)$mc['nilai_amaliyah']; $count++; }
                                if ($mc['nilai_jenazah'] !== null) { $sum += (float)$mc['nilai_jenazah']; $count++; }
                                if ($mc['nilai_ujian_tulis'] !== null) { $sum += (float)$mc['nilai_ujian_tulis']; $count++; }
                                echo $count > 0 ? number_format(round($sum / $count, 2), 2) : '-';
                                ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
        </div>
    <?php endif; ?>
<?php endif; ?>

<?php if ($isAdmin || $isDosen): ?>
<!-- Modal Edit Nilai -->
<div id="editNilaiModal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:9999; align-items:center; justify-content:center;">
    <div style="background:#fff; width:90%; max-width:600px; border-radius:12px; padding:24px; box-shadow:0 4px 6px rgba(0,0,0,0.1); max-height: 90vh; overflow-y: auto;">
        <h3 style="margin-top:0; margin-bottom:10px; font-size:18px; color:#1e293b;">Edit Nilai Mahasiswa</h3>
        <p style="margin-bottom:20px; font-size:14px; color:#64748b;">Mahasiswa: <strong id="edit_nilai_nama" style="color:#0f172a;"></strong> (<span id="edit_nilai_nim"></span>) <br> Kelas: <span id="edit_nilai_kelas"></span></p>
        
        <form method="POST" id="formEditNilai">
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
            <input type="hidden" name="action" value="edit_nilai">
            <input type="hidden" name="reg_id" id="edit_nilai_reg_id" value="">
            
            <div style="display:grid; grid-template-columns: 1fr 1fr; gap:16px; margin-bottom:24px;">
                <?php
                $fields = [
                    ['id' => 'edit_thaharah', 'name' => 'nilai_thaharah', 'label' => 'Thaharah'],
                    ['id' => 'edit_shalat', 'name' => 'nilai_shalat', 'label' => 'Shalat'],
                    ['id' => 'edit_srt_pdk', 'name' => 'nilai_surat_pendek', 'label' => 'Surat Pendek'],
                    ['id' => 'edit_amaliyah', 'name' => 'nilai_amaliyah', 'label' => 'Amaliyah'],
                    ['id' => 'edit_jenazah', 'name' => 'nilai_jenazah', 'label' => 'Jenazah'],
                    ['id' => 'edit_ut', 'name' => 'nilai_ujian_tulis', 'label' => 'Ujian Tulis'],
                ];
                foreach ($fields as $f):
                ?>
                <div class="form-group">
                    <label style="display:block; margin-bottom:8px; font-size:14px; color:#475569;"><?= $f['label'] ?></label>
                    <input type="number" step="0.01" name="<?= $f['name'] ?>" id="<?= $f['id'] ?>" class="form-control" style="width:100%; padding:10px; border:1px solid #cbd5e1; border-radius:8px; margin-bottom: 5px;">
                    <div style="display:flex; gap:4px; flex-wrap:wrap;">
                        <?php foreach([60, 70, 80, 85, 90, 100] as $val): ?>
                        <button type="button" class="btn btn-sm btn-outline-secondary" style="padding:2px 8px; font-size:12px;" onclick="document.getElementById('<?= $f['id'] ?>').value='<?= $val ?>'"><?= $val ?></button>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>

            <div style="display:flex; justify-content:flex-end; gap:12px;">
                <button type="button" class="btn btn-secondary" onclick="closeEditNilaiModal()" style="background:#f1f5f9; color:#475569; border:none; padding:8px 16px;">Batal</button>
                <button type="submit" class="btn btn-primary" style="padding:8px 16px;">Simpan Nilai</button>
            </div>
        </form>
    </div>
</div>

<script>
window.openEditNilaiModal = function(regId, nama, nim, kelas, thaharah, shalat, srtPdk, amaliyah, jenazah, ut) {
    document.getElementById('edit_nilai_reg_id').value = regId;
    document.getElementById('edit_nilai_nama').textContent = nama;
    document.getElementById('edit_nilai_nim').textContent = nim;
    document.getElementById('edit_nilai_kelas').textContent = kelas;
    
    document.getElementById('edit_thaharah').value = thaharah;
    document.getElementById('edit_shalat').value = shalat;
    document.getElementById('edit_srt_pdk').value = srtPdk;
    document.getElementById('edit_amaliyah').value = amaliyah;
    document.getElementById('edit_jenazah').value = jenazah;
    document.getElementById('edit_ut').value = ut;
    
    document.getElementById('editNilaiModal').style.display = 'flex';
};

window.closeEditNilaiModal = function() {
    document.getElementById('editNilaiModal').style.display = 'none';
};

if (document.getElementById('formEditNilai')) {
    document.getElementById('formEditNilai').addEventListener('submit', function(e) {
        e.preventDefault();
        const formData = new FormData(this);
        fetch('', { method: 'POST', body: formData })
        .then(res => {
            if (!res.ok) throw new Error('Server error');
            return res.text();
        })
        .then(html => {
            closeEditNilaiModal();
            alert('Nilai berhasil diperbarui.');
            window.location.reload();
        })
        .catch(err => {
            alert('Terjadi kesalahan saat menyimpan nilai. Coba lagi.');
        });
    });
}
</script>
<?php endif; ?>

<?php include __DIR__ . '/includes/footer.php'; ?>
