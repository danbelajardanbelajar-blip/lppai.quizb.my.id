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

if ($isAdmin || $isDosen) {
    // 1. Fetch daftar kelas untuk Dropdown Filter
    if ($isAdmin) {
        $stmt = $pdo->query("SELECT id, nama_kelas, gelombang, hari, dosen_pengampu FROM tutorial_classes ORDER BY gelombang ASC, nama_kelas ASC");
        $classes = $stmt->fetchAll();
    } else {
        $stmt = $pdo->prepare("SELECT id, nama_kelas, gelombang, hari, dosen_pengampu FROM tutorial_classes WHERE dosen_pengampu = ? ORDER BY gelombang ASC, nama_kelas ASC");
        $stmt->execute([$user['nama_lengkap']]);
        $classes = $stmt->fetchAll();
    }
    
    // Gabungkan duplikat nama kelas (jika ada) untuk dropdown agar rapi
    $unique_classes = [];
    foreach ($classes as $c) {
        $key = $c['gelombang'] . '_' . $c['nama_kelas'];
        if (!isset($unique_classes[$key])) {
            $unique_classes[$key] = [
                'nama_kelas' => $c['nama_kelas'],
                'gelombang' => $c['gelombang'],
                'ids' => []
            ];
        }
        $unique_classes[$key]['ids'][] = $c['id'];
    }
    
    $filter_class = $_GET['class_name'] ?? '';
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
        $sql .= " WHERE tc.dosen_pengampu = ?";
        $params[] = $user['nama_lengkap'];
    } else {
        $sql .= " WHERE 1=1";
    }
    
    if ($filter_class !== '') {
        $sql .= " AND tc.nama_kelas = ?";
        $params[] = $filter_class;
    }
    
    $sql .= " ORDER BY tc.gelombang ASC, tc.nama_kelas ASC, u.nama_lengkap ASC";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $students = $stmt->fetchAll();
    
    // 3. EXPORT LOGIC
    if ($action === 'export') {
        $vendorPath = __DIR__ . '/vendor/autoload.php';
        if (!file_exists($vendorPath)) {
            $vendorPath2 = __DIR__ . '/../vendor/autoload.php';
            if (file_exists($vendorPath2)) {
                $vendorPath = $vendorPath2;
            } else {
                die("<div style='padding:20px; color:red; font-family:sans-serif;'>Library Excel (PhpSpreadsheet) tidak ditemukan. Pastikan Anda telah menginstall PhpSpreadsheet via composer.</div>");
            }
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
        $sheet->setCellValue('L1', 'Nilai Akhir');
        
        // Style headers
        $headerStyle = [
            'font' => ['bold' => true],
            'fill' => ['fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID, 'startColor' => ['rgb' => 'E2E8F0']],
            'borders' => ['allBorders' => ['borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN]]
        ];
        $sheet->getStyle('A1:L1')->applyFromArray($headerStyle);
        
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
            $sheet->setCellValue('L'.$row, $s['nilai_akhir'] ?? '-');
            
            $sheet->getStyle('A'.$row.':L'.$row)->getBorders()->getAllBorders()->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN);
            $row++;
        }
        
        foreach(range('A','L') as $col) {
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
        WHERE tr.user_id = ?
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
                <div style="flex:1; min-width:250px;">
                    <label style="display:block; margin-bottom:5px; font-weight:bold;">Filter Kelas</label>
                    <select name="class_name" class="form-control" style="width:100%; padding:8px; border:1px solid #cbd5e1; border-radius:4px;">
                        <option value="">-- Tampilkan Semua Kelas --</option>
                        <?php foreach($unique_classes as $key => $c): ?>
                            <option value="<?= sanitize($c['nama_kelas']) ?>" <?= $filter_class == $c['nama_kelas'] ? 'selected' : '' ?>>
                                <?= sanitize($c['nama_kelas']) ?> (Gel. <?= sanitize($c['gelombang']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button type="submit" class="btn btn-primary">🔍 Filter Data</button>
            </form>
        </div>
    </div>
    
    <div class="card">
        <div class="card-header" style="display:flex; justify-content:space-between; align-items:center;">
            <span>Daftar Nilai Mahasiswa: <strong><?= $filter_class !== '' ? sanitize($filter_class) : 'Semua Kelas' ?></strong></span>
            <a href="?class_name=<?= urlencode($filter_class) ?>&action=export" class="btn btn-sm btn-success" data-no-spa="true">📄 Export Excel</a>
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
                                <th>Rata-Rata Akhir</th>
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
                                <td align="center">
                                    <span class="badge badge-primary" style="font-size:14px;">
                                        <?= $s['nilai_akhir'] !== null ? number_format($s['nilai_akhir'], 2) : '-' ?>
                                    </span>
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
                            </ul>
                        </div>
                        <div style="text-align:center; padding:15px 30px; background:#f8fafc; border-radius:8px; border:1px solid #e2e8f0;">
                            <div style="font-size:12px; color:#64748b; font-weight:bold; margin-bottom:5px;">NILAI AKHIR</div>
                            <div style="font-size:32px; font-weight:900; color:var(--primary);">
                                <?= $mc['nilai_akhir'] !== null ? number_format($mc['nilai_akhir'], 2) : '-' ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
        </div>
    <?php endif; ?>
<?php endif; ?>

<?php include __DIR__ . '/includes/footer.php'; ?>
