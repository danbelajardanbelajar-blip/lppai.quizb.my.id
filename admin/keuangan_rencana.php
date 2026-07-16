<?php
/**
 * Detail Rencana Anggaran (single page)
 */
require_once __DIR__ . '/../includes/auth.php';
requireAdmin();

$pdo = getDBConnection();

function formatCurrency($amount) {
    return 'Rp ' . number_format((float) $amount, 0, ',', '.');
}

$budgetId = isset($_GET['budget_id']) ? (int) $_GET['budget_id'] : 0;
if ($budgetId <= 0) {
    header('Location: ' . BASE_URL . '/admin/keuangan.php?view=rencana-anggaran');
    exit;
}

$stmt = $pdo->prepare("SELECT * FROM keuangan_anggaran WHERE id = ?");
$stmt->execute([$budgetId]);
$selectedBudget = $stmt->fetch();
if (!$selectedBudget) {
    header('Location: ' . BASE_URL . '/admin/keuangan.php?view=rencana-anggaran');
    exit;
}

$plannedTransactions = [];
$stmt = $pdo->prepare("SELECT id, nama, jumlah_item, nilai_per_item, jumlah, keterangan, created_at FROM keuangan_rencana_pemasukan WHERE anggaran_id = ? ORDER BY id DESC");
$stmt->execute([$selectedBudget['id']]);
while ($row = $stmt->fetch()) {
    $row['jenis'] = 'pemasukan';
    $plannedTransactions[] = $row;
}
$stmt = $pdo->prepare("SELECT id, nama, jumlah_item, nilai_per_item, jumlah, keterangan, created_at FROM keuangan_rencana_pengeluaran WHERE anggaran_id = ? ORDER BY id DESC");
$stmt->execute([$selectedBudget['id']]);
while ($row = $stmt->fetch()) {
    $row['jenis'] = 'pengeluaran';
    $plannedTransactions[] = $row;
}
usort($plannedTransactions, function($a, $b){ return strcmp($b['created_at'], $a['created_at']); });

$totalPlannedIncome = 0;
$totalPlannedExpense = 0;
foreach ($plannedTransactions as $p) {
    if ($p['jenis'] === 'pemasukan') $totalPlannedIncome += (float) $p['jumlah'];
    else $totalPlannedExpense += (float) $p['jumlah'];
}
$totalPlannedRemaining = $totalPlannedIncome - $totalPlannedExpense;

if (isset($_GET['action']) && $_GET['action'] === 'export-docx') {
    require_once __DIR__ . '/../../vendor/autoload.php';
    
    $phpWord = new \PhpOffice\PhpWord\PhpWord();
    $section = $phpWord->addSection();
    
    // Add title
    $phpWord->addTitleStyle(1, ['size' => 16, 'bold' => true], ['alignment' => 'center']);
    $section->addTitle('RENCANA ANGGARAN BELANJA', 1);
    
    $phpWord->addTitleStyle(2, ['size' => 14, 'bold' => true], ['alignment' => 'center']);
    $section->addTitle('LPPAI UNISDA', 2);
    
    $section->addText($selectedBudget['nama'] . ' • Periode ' . $selectedBudget['periode'], ['size' => 12], ['alignment' => 'center']);
    $section->addTextBreak(1);
    
    // Add table
    $styleTable = ['borderSize' => 6, 'borderColor' => '000000', 'cellMargin' => 80];
    $styleFirstRow = ['bgColor' => 'F3F4F6'];
    $phpWord->addTableStyle('RabTable', $styleTable, $styleFirstRow);
    $table = $section->addTable('RabTable');
    
    // Header
    $table->addRow();
    $table->addCell(3000)->addText('Nama / Detail', ['bold' => true]);
    $table->addCell(1500)->addText('Jumlah Item', ['bold' => true], ['alignment' => 'center']);
    $table->addCell(2000)->addText('Nilai per Item', ['bold' => true], ['alignment' => 'right']);
    $table->addCell(2000)->addText('Pemasukan', ['bold' => true], ['alignment' => 'right']);
    $table->addCell(2000)->addText('Pengeluaran', ['bold' => true], ['alignment' => 'right']);
    
    // Data
    if (empty($plannedTransactions)) {
        $table->addRow();
        $table->addCell(10500, ['gridSpan' => 5])->addText('Belum ada transaksi rencana.');
    } else {
        foreach ($plannedTransactions as $item) {
            $table->addRow();
            $table->addCell(3000)->addText(sanitize($item['nama']));
            $table->addCell(1500)->addText((string)$item['jumlah_item'], null, ['alignment' => 'center']);
            $table->addCell(2000)->addText(formatCurrency($item['nilai_per_item']), null, ['alignment' => 'right']);
            $table->addCell(2000)->addText($item['jenis'] === 'pemasukan' ? formatCurrency($item['jumlah']) : '-', null, ['alignment' => 'right']);
            $table->addCell(2000)->addText($item['jenis'] === 'pengeluaran' ? formatCurrency($item['jumlah']) : '-', null, ['alignment' => 'right']);
        }
    }
    
    $section->addTextBreak(1);
    
    // Totals Table
    $phpWord->addTableStyle('TotalsTable', $styleTable, $styleFirstRow);
    $totalsTable = $section->addTable('TotalsTable');
    
    $totalsTable->addRow();
    $totalsTable->addCell(3500)->addText('Total Pemasukan', ['bold' => true]);
    $totalsTable->addCell(3500)->addText('Total Pengeluaran', ['bold' => true]);
    $totalsTable->addCell(3500)->addText('Sisa', ['bold' => true]);
    
    $totalsTable->addRow();
    $totalsTable->addCell(3500)->addText(formatCurrency($totalPlannedIncome ?? 0), null, ['alignment' => 'right']);
    $totalsTable->addCell(3500)->addText(formatCurrency($totalPlannedExpense ?? 0), null, ['alignment' => 'right']);
    $totalsTable->addCell(3500)->addText(formatCurrency($totalPlannedRemaining ?? 0), ['bold' => true], ['alignment' => 'right']);
    
    $section->addTextBreak(3);
    
    // Signatures
    $tableSignature = $section->addTable();
    $tableSignature->addRow();
    $tableSignature->addCell(6000)->addText(''); // Empty space
    $cellSig = $tableSignature->addCell(4500);
    $cellSig->addText('Mengetahui,', null, ['alignment' => 'center']);
    $cellSig->addText('Ketua LPPAI UNISDA', null, ['alignment' => 'center']);
    $cellSig->addTextBreak(3);
    $cellSig->addText('_________________________', ['bold' => true], ['alignment' => 'center']);
    
    $filename = 'Rencana_Anggaran_' . preg_replace('/[^A-Za-z0-9_-]/', '_', $selectedBudget['nama']) . '.docx';
    header("Content-Description: File Transfer");
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
    header('Content-Transfer-Encoding: binary');
    header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
    header('Expires: 0');
    ob_clean(); // Ensure no extra output is sent before the file
    $objWriter = \PhpOffice\PhpWord\IOFactory::createWriter($phpWord, 'Word2007');
    $objWriter->save("php://output");
    exit;
}

include __DIR__ . '/../includes/header.php';
?>
<style>
    .print-only { display: none; }
    @media print {
        body * {
            visibility: hidden;
        }
        #print-rab, #print-rab * {
            visibility: visible;
        }
        #print-rab {
            position: absolute;
            left: 0;
            top: 0;
            width: 100%;
        }
        .no-print, .no-print * {
            display: none !important;
            visibility: hidden !important;
        }
        #print-rab table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
        }
        #print-rab th, #print-rab td {
            border: 1px solid #000 !important;
            padding: 8px;
        }
        #print-rab th {
            background-color: #f3f4f6 !important;
            -webkit-print-color-adjust: exact;
            color-adjust: exact;
            print-color-adjust: exact;
        }
        #print-rab .print-only {
            display: block !important;
            margin-bottom: 20px;
        }
        #print-rab .print-only-flex {
            display: flex !important;
        }
    }
</style>

<div class="container">
    <h3>Detail Rencana: <?= sanitize($selectedBudget['nama']) ?></h3>
    <p>Periode <?= sanitize($selectedBudget['periode']) ?> • Total <?= formatCurrency($selectedBudget['total_anggaran']) ?></p>

    <div style="margin-bottom:12px; display:flex; gap:8px;">
        <a href="<?= BASE_URL ?>/admin/keuangan.php?view=rencana-anggaran" class="btn btn-secondary">Kembali ke Daftar</a>
        <button type="button" class="btn btn-warning" onclick="printRab();">Cetak Rencana</button>
        <a href="<?= BASE_URL ?>/admin/keuangan_rencana.php?budget_id=<?= (int) $selectedBudget['id'] ?>&action=export-docx" class="btn btn-primary" style="background:#007bff; color:#fff; border:none;">Export DOCX</a>
    </div>



    <div style="padding:12px 0 20px 0; margin-bottom:8px;">
        <button type="button" class="btn btn-success" id="btn-open-add">Tambah Transaksi Rencana</button>
    </div>

    <!-- Modal: Tambah/Edit Transaksi Rencana -->
    <div id="modal-plan" style="display:none; position:fixed; left:0; top:0; width:100%; height:100%; background:rgba(0,0,0,0.4); align-items:center; justify-content:center; z-index:9999;">
        <div style="background:#fff; width:720px; max-width:95%; padding:16px; border-radius:8px; position:relative;">
            <button type="button" id="modal-close" style="position:absolute; right:12px; top:12px;">&times;</button>
            <h3 id="modal-title">Tambah Transaksi Rencana</h3>
            <form method="post" id="modal-form" style="display:grid; gap:12px; margin-top:8px;">
                <input type="hidden" name="view" value="rencana-anggaran">
                <input type="hidden" name="action" value="save-plan-transaction">
                <input type="hidden" name="budget_id" value="<?= (int) $selectedBudget['id'] ?>">
                <input type="hidden" name="plan_id" id="modal-plan-id" value="">
                <input type="hidden" name="original_plan_type" id="modal-original-type" value="">
                <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(220px, 1fr)); gap:12px;">
                    <div>
                        <label>Jenis</label>
                        <select name="jenis" id="modal-jenis" required style="width:100%; padding:8px; border:1px solid #ddd; border-radius:6px;">
                            <option value="pemasukan">Pemasukan</option>
                            <option value="pengeluaran">Pengeluaran</option>
                        </select>
                    </div>
                    <div>
                        <label>Nama / Detail</label>
                        <input type="text" name="nama" id="modal-nama" required placeholder="Contoh: Donatur, Belanja ATK" style="width:100%; padding:8px; border:1px solid #ddd; border-radius:6px;">
                    </div>
                    <div>
                        <label>Jumlah Item</label>
                        <input type="number" name="jumlah_item" id="modal-jumlah-item" min="1" step="1" value="1" required style="width:100%; padding:8px; border:1px solid #ddd; border-radius:6px;">
                    </div>
                    <div>
                        <label>Nilai per Item</label>
                        <input type="number" name="nilai_per_item" id="modal-nilai-per-item" min="0" step="1000" value="0" required style="width:100%; padding:8px; border:1px solid #ddd; border-radius:6px;">
                    </div>
                </div>
                <div>
                    <label>Keterangan</label>
                    <textarea name="keterangan" id="modal-keterangan" rows="2" style="width:100%; padding:8px; border:1px solid #ddd; border-radius:6px;"></textarea>
                </div>
                <div style="display:flex; gap:8px;">
                    <button type="submit" class="btn btn-success" id="modal-submit">Simpan Transaksi</button>
                    <button type="button" class="btn btn-secondary" id="modal-cancel">Batal</button>
                </div>
            </form>
        </div>
    </div>

    <div class="table-responsive">
        <h4>Daftar Transaksi Rencana</h4>
        <div id="print-rab">
            <div class="print-only" style="margin-bottom:20px; text-align:center;">
                <h2 style="font-size:22px; margin:0; text-transform:uppercase;">Rencana Anggaran Belanja</h2>
                <h3 style="font-size:18px; margin:4px 0 0;">LPPAI UNISDA</h3>
                <p style="margin:8px 0 0; font-size:14px;"><?= sanitize($selectedBudget['nama']) ?> • Periode <?= sanitize($selectedBudget['periode']) ?></p>
            </div>
            <table style="width:100%; border-collapse:collapse;">
                <thead>
                    <tr>
                        <th style="border:1px solid #ddd; padding:8px;">Nama / Detail</th>
                        <th style="border:1px solid #ddd; padding:8px; text-align:center;">Jumlah Item</th>
                        <th style="border:1px solid #ddd; padding:8px; text-align:right;">Nilai per Item</th>
                        <th style="border:1px solid #ddd; padding:8px; text-align:right;">Pemasukan</th>
                        <th style="border:1px solid #ddd; padding:8px; text-align:right;">Pengeluaran</th>
                        <th class="no-print" style="border:1px solid #ddd; padding:8px;">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($plannedTransactions)): ?>
                        <tr><td colspan="6">Belum ada transaksi rencana.</td></tr>
                    <?php else: foreach ($plannedTransactions as $item): ?>
                        <tr>
                            <td style="border:1px solid #ddd; padding:8px;"><?= sanitize($item['nama']) ?></td>
                            <td style="border:1px solid #ddd; padding:8px; text-align:center;"><?= (int) $item['jumlah_item'] ?></td>
                            <td style="border:1px solid #ddd; padding:8px; text-align:right;"><?= formatCurrency($item['nilai_per_item']) ?></td>
                            <td style="border:1px solid #ddd; padding:8px; text-align:right;"><?= $item['jenis'] === 'pemasukan' ? formatCurrency($item['jumlah']) : '-' ?></td>
                            <td style="border:1px solid #ddd; padding:8px; text-align:right;"><?= $item['jenis'] === 'pengeluaran' ? formatCurrency($item['jumlah']) : '-' ?></td>
                            <td class="no-print" style="border:1px solid #ddd; padding:8px;">
                                <div style="display:flex; gap:6px; flex-wrap:wrap; align-items:center;">
                                    <button type="button" class="btn btn-primary btn-edit" style="width:auto;"
                                        data-id="<?= (int) $item['id'] ?>"
                                        data-jenis="<?= sanitize($item['jenis']) ?>"
                                        data-nama="<?= htmlspecialchars($item['nama'], ENT_QUOTES) ?>"
                                        data-jumlah_item="<?= (int) $item['jumlah_item'] ?>"
                                        data-nilai_per_item="<?= (float) $item['nilai_per_item'] ?>"
                                        data-keterangan="<?= htmlspecialchars($item['keterangan'], ENT_QUOTES) ?>"
                                    >Edit</button>
                                    <a href="<?= BASE_URL ?>/admin/keuangan.php?view=rencana-anggaran&budget_id=<?= (int) $selectedBudget['id'] ?>&delete_plan_id=<?= (int) $item['id'] ?>&delete_plan_type=<?= sanitize($item['jenis']) ?>" class="btn btn-danger" style="width:auto;" onclick="return confirm('Hapus transaksi rencana ini?')">Delete</a>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>

            <div style="margin-top:24px; max-width:480px; page-break-inside: avoid;">
                <table style="width:100%; border-collapse:collapse;">
                    <thead>
                        <tr>
                            <th style="text-align:left; padding:8px; background:#f3f4f6; border:1px solid #ddd;">Total Pemasukan</th>
                            <th style="text-align:left; padding:8px; background:#f3f4f6; border:1px solid #ddd;">Total Pengeluaran</th>
                            <th style="text-align:left; padding:8px; background:#f3f4f6; border:1px solid #ddd;">Sisa</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td style="padding:8px; border:1px solid #ddd; text-align:right;"><?= formatCurrency($totalPlannedIncome ?? 0) ?></td>
                            <td style="padding:8px; border:1px solid #ddd; text-align:right;"><?= formatCurrency($totalPlannedExpense ?? 0) ?></td>
                            <td style="padding:8px; border:1px solid #ddd; text-align:right; font-weight:600;"><?= formatCurrency($totalPlannedRemaining ?? 0) ?></td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div class="print-only print-only-flex" style="margin-top:60px; justify-content:flex-end;">
                <div style="text-align:center; width:250px;">
                    <p style="margin:0 0 70px 0;">Mengetahui,<br>Ketua LPPAI UNISDA</p>
                    <p style="margin:0; font-weight:bold;">_________________________</p>
                </div>
            </div>
        </div>
    </div>

    <script>
        function printRab() { const printContainer = document.getElementById('print-rab'); if (!printContainer) { window.print(); return; } window.print(); }
        (function(){
            const modal = document.getElementById('modal-plan');
            const btnOpenAdd = document.getElementById('btn-open-add');
            const btnClose = document.getElementById('modal-close');
            const btnCancel = document.getElementById('modal-cancel');
            const modalTitle = document.getElementById('modal-title');
            const inputId = document.getElementById('modal-plan-id');
            const inputOriginal = document.getElementById('modal-original-type');
            const inputJenis = document.getElementById('modal-jenis');
            const inputNama = document.getElementById('modal-nama');
            const inputJumlahItem = document.getElementById('modal-jumlah-item');
            const inputNilaiPerItem = document.getElementById('modal-nilai-per-item');
            const inputKeterangan = document.getElementById('modal-keterangan');

            function openModal(values) {
                if (!modal) return;
                if (values) {
                    modalTitle.textContent = 'Edit Transaksi Rencana';
                    inputId.value = values.id || '';
                    inputOriginal.value = values.jenis || '';
                    inputJenis.value = values.jenis || 'pemasukan';
                    inputNama.value = values.nama || '';
                    inputJumlahItem.value = values.jumlah_item || 1;
                    inputNilaiPerItem.value = values.nilai_per_item || 0;
                    inputKeterangan.value = values.keterangan || '';
                    document.getElementById('modal-submit').textContent = 'Perbarui Transaksi';
                } else {
                    modalTitle.textContent = 'Tambah Transaksi Rencana';
                    inputId.value = '';
                    inputOriginal.value = '';
                    inputJenis.value = 'pemasukan';
                    inputNama.value = '';
                    inputJumlahItem.value = 1;
                    inputNilaiPerItem.value = 0;
                    inputKeterangan.value = '';
                    document.getElementById('modal-submit').textContent = 'Simpan Transaksi';
                }
                modal.style.display = 'flex';
            }

            function closeModal() { if (modal) modal.style.display = 'none'; }

            if (btnOpenAdd) btnOpenAdd.addEventListener('click', function(){ openModal(null); });
            if (btnClose) btnClose.addEventListener('click', closeModal);
            if (btnCancel) btnCancel.addEventListener('click', closeModal);

            document.querySelectorAll('.btn-edit').forEach(function(b){ b.addEventListener('click', function(){ const ds = b.dataset; openModal({ id: ds.id, jenis: ds.jenis, nama: ds.nama, jumlah_item: ds.jumlah_item, nilai_per_item: ds.nilai_per_item, keterangan: ds.keterangan }); }); });

            if (modal) modal.addEventListener('click', function(e){ if (e.target === modal) closeModal(); });
        })();
    </script>

</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
