<?php
/**
 * LPPAI Corner - Rekapitulasi Nilai Terpadu (Ringkasan per Kelas)
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
    // Ambil ringkasan nilai per kelas
    if ($isAdmin) {
        $stmt = $pdo->query("
            SELECT tc.id, tc.nama_kelas, tc.gelombang, tc.dosen_pengampu, tc.hari,
                   COUNT(tr.id) as total_mahasiswa,
                   AVG(tr.nilai_thaharah) as avg_thaharah,
                   AVG(tr.nilai_shalat) as avg_shalat,
                   AVG(tr.nilai_surat_pendek) as avg_surat_pendek,
                   AVG(tr.nilai_amaliyah) as avg_amaliyah,
                   AVG(tr.nilai_jenazah) as avg_jenazah,
                   AVG(tr.nilai_akhir) as avg_akhir
            FROM tutorial_classes tc
            LEFT JOIN tutorial_registrations tr ON tc.id = tr.tutorial_class_id
            GROUP BY tc.id
            ORDER BY tc.gelombang ASC, tc.nama_kelas ASC
        ");
        $classes_summary = $stmt->fetchAll();
    } else {
        $stmt = $pdo->prepare("
            SELECT tc.id, tc.nama_kelas, tc.gelombang, tc.dosen_pengampu, tc.hari,
                   COUNT(tr.id) as total_mahasiswa,
                   AVG(tr.nilai_thaharah) as avg_thaharah,
                   AVG(tr.nilai_shalat) as avg_shalat,
                   AVG(tr.nilai_surat_pendek) as avg_surat_pendek,
                   AVG(tr.nilai_amaliyah) as avg_amaliyah,
                   AVG(tr.nilai_jenazah) as avg_jenazah,
                   AVG(tr.nilai_akhir) as avg_akhir
            FROM tutorial_classes tc
            LEFT JOIN tutorial_registrations tr ON tc.id = tr.tutorial_class_id
            WHERE tc.dosen_pengampu = ?
            GROUP BY tc.id
            ORDER BY tc.gelombang ASC, tc.nama_kelas ASC
        ");
        $stmt->execute([$user['nama_lengkap']]);
        $classes_summary = $stmt->fetchAll();
    }
    
    // EXPORT LOGIC
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
        $sheet->setCellValue('C1', 'Nama Kelas');
        $sheet->setCellValue('D1', 'Hari');
        $sheet->setCellValue('E1', 'Dosen Pengampu');
        $sheet->setCellValue('F1', 'Jml Mhs');
        $sheet->setCellValue('G1', 'Rata-rata Thaharah');
        $sheet->setCellValue('H1', 'Rata-rata Shalat');
        $sheet->setCellValue('I1', 'Rata-rata Srt Pendek');
        $sheet->setCellValue('J1', 'Rata-rata Amaliyah');
        $sheet->setCellValue('K1', 'Rata-rata Jenazah');
        $sheet->setCellValue('L1', 'Rata-rata Akhir');
        
        // Style headers
        $headerStyle = [
            'font' => ['bold' => true],
            'fill' => ['fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID, 'startColor' => ['rgb' => 'E2E8F0']],
            'borders' => ['allBorders' => ['borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN]]
        ];
        $sheet->getStyle('A1:L1')->applyFromArray($headerStyle);
        
        $row = 2;
        $no = 1;
        foreach($classes_summary as $c) {
            $sheet->setCellValue('A'.$row, $no++);
            $sheet->setCellValueExplicit('B'.$row, $c['gelombang'], \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
            $sheet->setCellValue('C'.$row, $c['nama_kelas']);
            $sheet->setCellValue('D'.$row, $c['hari']);
            $sheet->setCellValue('E'.$row, $c['dosen_pengampu']);
            $sheet->setCellValue('F'.$row, $c['total_mahasiswa']);
            $sheet->setCellValue('G'.$row, $c['avg_thaharah'] !== null ? round($c['avg_thaharah'], 2) : '-');
            $sheet->setCellValue('H'.$row, $c['avg_shalat'] !== null ? round($c['avg_shalat'], 2) : '-');
            $sheet->setCellValue('I'.$row, $c['avg_surat_pendek'] !== null ? round($c['avg_surat_pendek'], 2) : '-');
            $sheet->setCellValue('J'.$row, $c['avg_amaliyah'] !== null ? round($c['avg_amaliyah'], 2) : '-');
            $sheet->setCellValue('K'.$row, $c['avg_jenazah'] !== null ? round($c['avg_jenazah'], 2) : '-');
            $sheet->setCellValue('L'.$row, $c['avg_akhir'] !== null ? round($c['avg_akhir'], 2) : '-');
            
            $sheet->getStyle('A'.$row.':L'.$row)->getBorders()->getAllBorders()->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN);
            $row++;
        }
        
        foreach(range('A','L') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }
        
        $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
        $filename = 'Rekap_Nilai_Ringkasan_Kelas_' . date('Ymd') . '.xlsx';
        
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

<div class="mb-4" style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px;">
    <div>
        <h2 class="page-title"><?= PAGE_TITLE ?></h2>
        <?php if ($isAdmin): ?>
            <p class="text-muted">Pantau ringkasan rata-rata nilai dari seluruh kelas tutorial.</p>
        <?php elseif ($isDosen): ?>
            <p class="text-muted">Pantau ringkasan rata-rata nilai dari kelas yang Anda ampu.</p>
        <?php else: ?>
            <p class="text-muted">Lihat rincian nilai kelas tutorial yang Anda ikuti.</p>
        <?php endif; ?>
    </div>
    
    <?php if ($isAdmin || $isDosen): ?>
        <div>
            <a href="?action=export" class="btn btn-success" data-no-spa="true">📄 Export Excel (Semua Kelas)</a>
        </div>
    <?php endif; ?>
</div>

<?php if ($isAdmin || $isDosen): ?>
    <div class="card">
        <div class="card-header">
            <span>Ringkasan Nilai per Kelas</span>
        </div>
        <div class="card-body">
            <?php if (empty($classes_summary)): ?>
                <div class="empty-state">Belum ada kelas yang terdaftar.</div>
            <?php else: ?>
                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th width="40">No</th>
                                <th>Gel.</th>
                                <th>Nama Kelas</th>
                                <th>Hari</th>
                                <?php if($isAdmin): ?>
                                <th>Dosen Pengampu</th>
                                <?php endif; ?>
                                <th>Jml Mhs</th>
                                <th>Rata Thaharah</th>
                                <th>Rata Shalat</th>
                                <th>Rata Srt Pdk</th>
                                <th>Rata Amaliyah</th>
                                <th>Rata Jenazah</th>
                                <th>Rata Akhir</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php $no=1; foreach($classes_summary as $c): ?>
                            <tr>
                                <td align="center"><?= $no++ ?></td>
                                <td align="center"><?= sanitize($c['gelombang']) ?></td>
                                <td><strong><?= sanitize($c['nama_kelas']) ?></strong></td>
                                <td><?= sanitize($c['hari']) ?></td>
                                <?php if($isAdmin): ?>
                                <td><?= sanitize($c['dosen_pengampu']) ?></td>
                                <?php endif; ?>
                                <td align="center"><?= $c['total_mahasiswa'] ?></td>
                                <td align="center"><?= $c['avg_thaharah'] !== null ? number_format($c['avg_thaharah'], 1) : '-' ?></td>
                                <td align="center"><?= $c['avg_shalat'] !== null ? number_format($c['avg_shalat'], 1) : '-' ?></td>
                                <td align="center"><?= $c['avg_surat_pendek'] !== null ? number_format($c['avg_surat_pendek'], 1) : '-' ?></td>
                                <td align="center"><?= $c['avg_amaliyah'] !== null ? number_format($c['avg_amaliyah'], 1) : '-' ?></td>
                                <td align="center"><?= $c['avg_jenazah'] !== null ? number_format($c['avg_jenazah'], 1) : '-' ?></td>
                                <td align="center">
                                    <span class="badge badge-primary" style="font-size:14px;">
                                        <?= $c['avg_akhir'] !== null ? number_format($c['avg_akhir'], 2) : '-' ?>
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
