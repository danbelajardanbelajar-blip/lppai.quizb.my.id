<?php
/**
 * LPPAI Corner - Keuangan
 */
define('PAGE_TITLE', 'Keuangan');
require_once __DIR__ . '/../includes/auth.php';
requireAdmin();

$pdo = getDBConnection();

function ensureKeuanganTables(PDO $pdo): void {
    $sqls = [
        "CREATE TABLE IF NOT EXISTS keuangan_anggaran (
            id INT AUTO_INCREMENT PRIMARY KEY,
            nama VARCHAR(150) NOT NULL,
            periode VARCHAR(50) NOT NULL,
            total_anggaran DECIMAL(15,2) NOT NULL DEFAULT 0,
            deskripsi TEXT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'aktif',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS keuangan_pemasukan (
            id INT AUTO_INCREMENT PRIMARY KEY,
            tanggal DATE NOT NULL,
            sumber VARCHAR(150) NOT NULL,
            jumlah DECIMAL(15,2) NOT NULL DEFAULT 0,
            kategori VARCHAR(50) NULL,
            keterangan TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS keuangan_pengeluaran (
            id INT AUTO_INCREMENT PRIMARY KEY,
            tanggal DATE NOT NULL,
            tujuan VARCHAR(150) NOT NULL,
            jumlah DECIMAL(15,2) NOT NULL DEFAULT 0,
            kategori VARCHAR(50) NULL,
            keterangan TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS keuangan_transaksi (
            id INT AUTO_INCREMENT PRIMARY KEY,
            tanggal DATE NOT NULL,
            jenis VARCHAR(20) NOT NULL,
            nama VARCHAR(150) NOT NULL,
            jumlah DECIMAL(15,2) NOT NULL DEFAULT 0,
            kategori VARCHAR(50) NULL,
            keterangan TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS keuangan_rencana_pemasukan (
            id INT AUTO_INCREMENT PRIMARY KEY,
            anggaran_id INT NOT NULL,
            nama VARCHAR(150) NOT NULL,
            jumlah_item INT NOT NULL DEFAULT 1,
            nilai_per_item DECIMAL(15,2) NOT NULL DEFAULT 0,
            jumlah DECIMAL(15,2) NOT NULL DEFAULT 0,
            keterangan TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (anggaran_id) REFERENCES keuangan_anggaran(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS keuangan_rencana_pengeluaran (
            id INT AUTO_INCREMENT PRIMARY KEY,
            anggaran_id INT NOT NULL,
            nama VARCHAR(150) NOT NULL,
            jumlah_item INT NOT NULL DEFAULT 1,
            nilai_per_item DECIMAL(15,2) NOT NULL DEFAULT 0,
            jumlah DECIMAL(15,2) NOT NULL DEFAULT 0,
            keterangan TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (anggaran_id) REFERENCES keuangan_anggaran(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    ];

    foreach ($sqls as $sql) {
        $pdo->exec($sql);
    }

    $alterStatements = [
        "ALTER TABLE keuangan_rencana_pemasukan ADD COLUMN IF NOT EXISTS jumlah_item INT NOT NULL DEFAULT 1",
        "ALTER TABLE keuangan_rencana_pemasukan ADD COLUMN IF NOT EXISTS nilai_per_item DECIMAL(15,2) NOT NULL DEFAULT 0",
        "ALTER TABLE keuangan_rencana_pemasukan ADD COLUMN IF NOT EXISTS jumlah DECIMAL(15,2) NOT NULL DEFAULT 0",
        "ALTER TABLE keuangan_rencana_pengeluaran ADD COLUMN IF NOT EXISTS jumlah_item INT NOT NULL DEFAULT 1",
        "ALTER TABLE keuangan_rencana_pengeluaran ADD COLUMN IF NOT EXISTS nilai_per_item DECIMAL(15,2) NOT NULL DEFAULT 0",
        "ALTER TABLE keuangan_rencana_pengeluaran ADD COLUMN IF NOT EXISTS jumlah DECIMAL(15,2) NOT NULL DEFAULT 0",
        "ALTER TABLE keuangan_transaksi ADD COLUMN IF NOT EXISTS bukti VARCHAR(255) NULL",
    ];

    foreach ($alterStatements as $sql) {
        try {
            $pdo->exec($sql);
        } catch (PDOException $e) {
            // ignore if database version doesn't support ADD COLUMN IF NOT EXISTS
        }
    }
}

function formatCurrency($amount) {
    return 'Rp ' . number_format((float) $amount, 0, ',', '.');
}

ensureKeuanganTables($pdo);

$view = isset($_GET['view']) ? $_GET['view'] : 'rencana-anggaran';
$viewLabels = [
    'rencana-anggaran' => 'Rencana Anggaran',
    'transaksi' => 'Transaksi (In/Out)',
    'laporan' => 'Laporan',
];
$viewTitle = $viewLabels[$view] ?? 'Keuangan';

if (isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['budget_id'])) {
    $budgetId = (int) $_GET['budget_id'];
    $pdo->prepare("DELETE FROM keuangan_anggaran WHERE id = ?")->execute([$budgetId]);
    header('Location: ' . BASE_URL . '/admin/keuangan.php?view=rencana-anggaran');
    exit;
}

if (isset($_GET['delete_plan_id']) && isset($_GET['delete_plan_type']) && isset($_GET['budget_id'])) {
    $planId = (int) $_GET['delete_plan_id'];
    $planType = $_GET['delete_plan_type'] === 'pengeluaran' ? 'pengeluaran' : 'pemasukan';
    $budgetId = (int) $_GET['budget_id'];

    if ($planType === 'pemasukan') {
        $pdo->prepare("DELETE FROM keuangan_rencana_pemasukan WHERE id = ? AND anggaran_id = ?")->execute([$planId, $budgetId]);
    } else {
        $pdo->prepare("DELETE FROM keuangan_rencana_pengeluaran WHERE id = ? AND anggaran_id = ?")->execute([$planId, $budgetId]);
    }

    header('Location: ' . BASE_URL . '/admin/keuangan.php?view=rencana-anggaran&budget_id=' . $budgetId);
    exit;
}

if (isset($_GET['action']) && $_GET['action'] === 'delete-transaksi' && isset($_GET['id'])) {
    $id = (int) $_GET['id'];
    
    $stmt = $pdo->prepare("SELECT bukti FROM keuangan_transaksi WHERE id = ?");
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if ($row && !empty($row['bukti'])) {
        $filePath = __DIR__ . '/../' . $row['bukti'];
        if (file_exists($filePath) && is_file($filePath)) {
            unlink($filePath);
        }
    }

    $pdo->prepare("DELETE FROM keuangan_transaksi WHERE id = ?")->execute([$id]);
    header('Location: ' . BASE_URL . '/admin/keuangan.php?view=transaksi');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $view = isset($_POST['view']) ? $_POST['view'] : $view;

    if (isset($_POST['action']) && $_POST['action'] === 'save-budget') {
        $budgetId = !empty($_POST['budget_id']) ? (int) $_POST['budget_id'] : null;
        if ($budgetId) {
            $stmt = $pdo->prepare("UPDATE keuangan_anggaran SET nama = ?, periode = ?, total_anggaran = ?, deskripsi = ?, status = ? WHERE id = ?");
            $stmt->execute([
                trim($_POST['nama'] ?? ''),
                trim($_POST['periode'] ?? ''),
                (float) ($_POST['total_anggaran'] ?? 0),
                trim($_POST['deskripsi'] ?? ''),
                trim($_POST['status'] ?? 'aktif'),
                $budgetId
            ]);
        } else {
            $stmt = $pdo->prepare("INSERT INTO keuangan_anggaran (nama, periode, total_anggaran, deskripsi, status) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([
                trim($_POST['nama'] ?? ''),
                trim($_POST['periode'] ?? ''),
                (float) ($_POST['total_anggaran'] ?? 0),
                trim($_POST['deskripsi'] ?? ''),
                trim($_POST['status'] ?? 'aktif')
            ]);
            $budgetId = (int) $pdo->lastInsertId();
        }

        header('Location: ' . BASE_URL . '/admin/keuangan.php?view=rencana-anggaran&budget_id=' . $budgetId);
        exit;
    }

    if (isset($_POST['action']) && $_POST['action'] === 'save-plan-transaction') {
        $budgetId = (int) ($_POST['budget_id'] ?? 0);
        if ($budgetId > 0) {
            $jenis = $_POST['jenis'] === 'pengeluaran' ? 'pengeluaran' : 'pemasukan';
            $jumlahItem = max(1, (int) ($_POST['jumlah_item'] ?? 1));
            $nilaiPerItem = max(0, (float) ($_POST['nilai_per_item'] ?? 0));
            $total = $jumlahItem * $nilaiPerItem;
            $planId = !empty($_POST['plan_id']) ? (int) $_POST['plan_id'] : null;

            if ($planId) {
                $originalJenis = isset($_POST['original_plan_type']) && $_POST['original_plan_type'] === 'pengeluaran' ? 'pengeluaran' : 'pemasukan';
                if (!isset($_POST['original_plan_type']) || empty($_POST['original_plan_type'])) {
                    $stmt = $pdo->prepare("SELECT id FROM keuangan_rencana_pemasukan WHERE id = ? AND anggaran_id = ?");
                    $stmt->execute([$planId, $budgetId]);
                    if ($stmt->fetch()) {
                        $originalJenis = 'pemasukan';
                    } else {
                        $originalJenis = 'pengeluaran';
                    }
                }
                if ($originalJenis === $jenis) {
                    if ($jenis === 'pemasukan') {
                        $stmt = $pdo->prepare("UPDATE keuangan_rencana_pemasukan SET nama = ?, jumlah_item = ?, nilai_per_item = ?, jumlah = ?, keterangan = ? WHERE id = ? AND anggaran_id = ?");
                    } else {
                        $stmt = $pdo->prepare("UPDATE keuangan_rencana_pengeluaran SET nama = ?, jumlah_item = ?, nilai_per_item = ?, jumlah = ?, keterangan = ? WHERE id = ? AND anggaran_id = ?");
                    }
                    $stmt->execute([
                        trim($_POST['nama'] ?? ''),
                        $jumlahItem,
                        $nilaiPerItem,
                        $total,
                        trim($_POST['keterangan'] ?? ''),
                        $planId,
                        $budgetId
                    ]);
                } else {
                    if ($originalJenis === 'pemasukan') {
                        $pdo->prepare("DELETE FROM keuangan_rencana_pemasukan WHERE id = ? AND anggaran_id = ?")->execute([$planId, $budgetId]);
                    } else {
                        $pdo->prepare("DELETE FROM keuangan_rencana_pengeluaran WHERE id = ? AND anggaran_id = ?")->execute([$planId, $budgetId]);
                    }

                    if ($jenis === 'pemasukan') {
                        $stmt = $pdo->prepare("INSERT INTO keuangan_rencana_pemasukan (anggaran_id, nama, jumlah_item, nilai_per_item, jumlah, keterangan) VALUES (?, ?, ?, ?, ?, ?)");
                    } else {
                        $stmt = $pdo->prepare("INSERT INTO keuangan_rencana_pengeluaran (anggaran_id, nama, jumlah_item, nilai_per_item, jumlah, keterangan) VALUES (?, ?, ?, ?, ?, ?)");
                    }
                    $stmt->execute([
                        $budgetId,
                        trim($_POST['nama'] ?? ''),
                        $jumlahItem,
                        $nilaiPerItem,
                        $total,
                        trim($_POST['keterangan'] ?? '')
                    ]);
                }
            } else {
                if ($jenis === 'pemasukan') {
                    $stmt = $pdo->prepare("INSERT INTO keuangan_rencana_pemasukan (anggaran_id, nama, jumlah_item, nilai_per_item, jumlah, keterangan) VALUES (?, ?, ?, ?, ?, ?)");
                    $stmt->execute([
                        $budgetId,
                        trim($_POST['nama'] ?? ''),
                        $jumlahItem,
                        $nilaiPerItem,
                        $total,
                        trim($_POST['keterangan'] ?? '')
                    ]);
                } else {
                    $stmt = $pdo->prepare("INSERT INTO keuangan_rencana_pengeluaran (anggaran_id, nama, jumlah_item, nilai_per_item, jumlah, keterangan) VALUES (?, ?, ?, ?, ?, ?)");
                    $stmt->execute([
                        $budgetId,
                        trim($_POST['nama'] ?? ''),
                        $jumlahItem,
                        $nilaiPerItem,
                        $total,
                        trim($_POST['keterangan'] ?? '')
                    ]);
                }
            }
        }
        header('Location: ' . BASE_URL . '/admin/keuangan.php?view=rencana-anggaran&budget_id=' . $budgetId);
        exit;
    }

    if ($view === 'transaksi') {
        $jenis = trim($_POST['jenis'] ?? 'pemasukan');
        
        $buktiPath = '';
        if (isset($_FILES['bukti']) && $_FILES['bukti']['error'] === UPLOAD_ERR_OK) {
            $uploadDir = __DIR__ . '/../uploads/keuangan/';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }
            $fileName = time() . '_' . preg_replace('/[^a-zA-Z0-9.\-_]/', '', basename($_FILES['bukti']['name']));
            $targetPath = $uploadDir . $fileName;
            if (move_uploaded_file($_FILES['bukti']['tmp_name'], $targetPath)) {
                $buktiPath = 'uploads/keuangan/' . $fileName;
            }
        }

        $transaksiId = !empty($_POST['transaksi_id']) ? (int) $_POST['transaksi_id'] : null;

        if ($transaksiId) {
            if ($buktiPath !== '') {
                $stmt = $pdo->prepare("SELECT bukti FROM keuangan_transaksi WHERE id = ?");
                $stmt->execute([$transaksiId]);
                $row = $stmt->fetch();
                if ($row && !empty($row['bukti'])) {
                    $oldPath = __DIR__ . '/../' . $row['bukti'];
                    if (file_exists($oldPath) && is_file($oldPath)) {
                        unlink($oldPath);
                    }
                }
                $stmt = $pdo->prepare("UPDATE keuangan_transaksi SET tanggal = ?, jenis = ?, nama = ?, jumlah = ?, kategori = ?, keterangan = ?, bukti = ? WHERE id = ?");
                $stmt->execute([
                    $_POST['tanggal'] ?? date('Y-m-d'),
                    $jenis,
                    trim($_POST['nama'] ?? ''),
                    (float) ($_POST['jumlah'] ?? 0),
                    trim($_POST['kategori'] ?? ''),
                    trim($_POST['keterangan'] ?? ''),
                    $buktiPath,
                    $transaksiId
                ]);
            } else {
                $stmt = $pdo->prepare("UPDATE keuangan_transaksi SET tanggal = ?, jenis = ?, nama = ?, jumlah = ?, kategori = ?, keterangan = ? WHERE id = ?");
                $stmt->execute([
                    $_POST['tanggal'] ?? date('Y-m-d'),
                    $jenis,
                    trim($_POST['nama'] ?? ''),
                    (float) ($_POST['jumlah'] ?? 0),
                    trim($_POST['kategori'] ?? ''),
                    trim($_POST['keterangan'] ?? ''),
                    $transaksiId
                ]);
            }
        } else {
            $stmt = $pdo->prepare("INSERT INTO keuangan_transaksi (tanggal, jenis, nama, jumlah, kategori, keterangan, bukti) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([
                $_POST['tanggal'] ?? date('Y-m-d'),
                $jenis,
                trim($_POST['nama'] ?? ''),
                (float) ($_POST['jumlah'] ?? 0),
                trim($_POST['kategori'] ?? ''),
                trim($_POST['keterangan'] ?? ''),
                $buktiPath
            ]);
        }
    }

    header('Location: ' . BASE_URL . '/admin/keuangan.php?view=' . urlencode($view));
    exit;
}

$budgets = $pdo->query("SELECT * FROM keuangan_anggaran ORDER BY id DESC")->fetchAll();
$transactions = $pdo->query("SELECT * FROM keuangan_transaksi ORDER BY tanggal DESC, id DESC")->fetchAll();

$selectedBudget = null;
$selectedBudgetId = isset($_GET['budget_id']) ? (int) $_GET['budget_id'] : 0;
if ($selectedBudgetId > 0) {
    $stmt = $pdo->prepare("SELECT * FROM keuangan_anggaran WHERE id = ?");
    $stmt->execute([$selectedBudgetId]);
    $selectedBudget = $stmt->fetch();
}

$editPlan = null;
if ($selectedBudget && isset($_GET['edit_plan_id']) && isset($_GET['edit_plan_type'])) {
    $editPlanId = (int) $_GET['edit_plan_id'];
    $editPlanType = $_GET['edit_plan_type'] === 'pengeluaran' ? 'pengeluaran' : 'pemasukan';
    if ($editPlanType === 'pemasukan') {
        $stmt = $pdo->prepare("SELECT * FROM keuangan_rencana_pemasukan WHERE id = ? AND anggaran_id = ?");
    } else {
        $stmt = $pdo->prepare("SELECT * FROM keuangan_rencana_pengeluaran WHERE id = ? AND anggaran_id = ?");
    }
    $stmt->execute([$editPlanId, $selectedBudget['id']]);
    $editPlan = $stmt->fetch();
    if (!$editPlan) {
        $stmt = $pdo->prepare("SELECT * FROM keuangan_rencana_pemasukan WHERE id = ? AND anggaran_id = ?");
        $stmt->execute([$editPlanId, $selectedBudget['id']]);
        $editPlan = $stmt->fetch();
        if ($editPlan) {
            $editPlanType = 'pemasukan';
        }
    }
    if (!$editPlan) {
        $stmt = $pdo->prepare("SELECT * FROM keuangan_rencana_pengeluaran WHERE id = ? AND anggaran_id = ?");
        $stmt->execute([$editPlanId, $selectedBudget['id']]);
        $editPlan = $stmt->fetch();
        if ($editPlan) {
            $editPlanType = 'pengeluaran';
        }
    }
    if ($editPlan) {
        $editPlan['jenis'] = $editPlanType;
        $editPlan['original_jenis'] = $editPlanType;
    }
}

$plannedTransactions = [];
if ($selectedBudget) {
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

    usort($plannedTransactions, function ($a, $b) {
        return strcmp($b['created_at'], $a['created_at']);
    });

    $totalPlannedIncome = 0;
    $totalPlannedExpense = 0;
    foreach ($plannedTransactions as $planned) {
        if ($planned['jenis'] === 'pemasukan') {
            $totalPlannedIncome += (float) $planned['jumlah'];
        } else {
            $totalPlannedExpense += (float) $planned['jumlah'];
        }
    }
    $totalPlannedRemaining = $totalPlannedIncome - $totalPlannedExpense;
}

$totalBudget = 0;
foreach ($budgets as $budget) {
    $totalBudget += (float) $budget['total_anggaran'];
}
$totalIncome = 0;
$totalExpense = 0;
foreach ($transactions as $transaction) {
    if ($transaction['jenis'] === 'pemasukan') {
        $totalIncome += (float) $transaction['jumlah'];
    } else {
        $totalExpense += (float) $transaction['jumlah'];
    }
}
$saldo = $totalIncome - $totalExpense;

include __DIR__ . '/../includes/header.php';
?>
<style>
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
        .no-print {
            display: none !important;
        }
    }
    .print-only {
        display: none;
    }
    @media print {
        .print-only {
            display: block;
        }
    }
</style>

<div class="card">
    <div class="card-header">💰 Kelola Keuangan</div>
    <div class="card-body">
        <div style="display:flex; gap:8px; flex-wrap:wrap; margin-bottom:16px;">
            <a href="<?= BASE_URL ?>/admin/keuangan.php?view=rencana-anggaran" class="btn btn-secondary" style="width:auto;">Rencana Anggaran</a>
            <a href="<?= BASE_URL ?>/admin/keuangan.php?view=transaksi" class="btn btn-secondary" style="width:auto;">Transaksi (In/Out)</a>
            <a href="<?= BASE_URL ?>/admin/keuangan.php?view=laporan" class="btn btn-secondary" style="width:auto;">Laporan</a>
        </div>

        <h3><?= sanitize($viewTitle) ?></h3>

        <?php if ($view === 'rencana-anggaran'): ?>
            <?php if ($selectedBudget): ?>
                <div style="display:flex; justify-content:space-between; align-items:center; gap:10px; flex-wrap:wrap; margin-bottom:16px;">
                    <div>
                        <h4 style="margin:0;">Detail Rencana: <?= sanitize($selectedBudget['nama']) ?></h4>
                        <p style="margin:4px 0 0;">Periode <?= sanitize($selectedBudget['periode']) ?> • Total <?= formatCurrency($selectedBudget['total_anggaran']) ?></p>
                    </div>
                    <div style="display:flex; gap:8px; flex-wrap:wrap;">
                        <a href="<?= BASE_URL ?>/admin/keuangan.php?view=rencana-anggaran" class="btn btn-secondary" style="width:auto;">Kembali ke Daftar</a>
                        <button type="button" class="btn btn-warning no-print" style="width:auto;" onclick="printRab();">Cetak Rencana</button>
                    </div>
                </div>



                <form method="post" style="display:grid; gap:12px; margin-bottom:20px;">
                    <input type="hidden" name="view" value="rencana-anggaran">
                    <input type="hidden" name="action" value="save-budget">
                    <input type="hidden" name="budget_id" value="<?= (int) $selectedBudget['id'] ?>">
                    <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(220px, 1fr)); gap:12px;">
                        <div>
                            <label>Nama Rencana</label>
                            <input type="text" name="nama" value="<?= sanitize($selectedBudget['nama']) ?>" required style="width:100%; padding:8px; border:1px solid #ddd; border-radius:6px;">
                        </div>
                        <div>
                            <label>Periode</label>
                            <input type="text" name="periode" value="<?= sanitize($selectedBudget['periode']) ?>" required placeholder="2026/2027" style="width:100%; padding:8px; border:1px solid #ddd; border-radius:6px;">
                        </div>
                        <div>
                            <label>Total Anggaran</label>
                            <input type="number" name="total_anggaran" min="0" step="1000" value="<?= (float) $selectedBudget['total_anggaran'] ?>" required style="width:100%; padding:8px; border:1px solid #ddd; border-radius:6px;">
                        </div>
                        <div>
                            <label>Status</label>
                            <select name="status" style="width:100%; padding:8px; border:1px solid #ddd; border-radius:6px;">
                                <option value="aktif" <?= ($selectedBudget['status'] === 'aktif') ? 'selected' : '' ?>>Aktif</option>
                                <option value="draft" <?= ($selectedBudget['status'] === 'draft') ? 'selected' : '' ?>>Draft</option>
                                <option value="selesai" <?= ($selectedBudget['status'] === 'selesai') ? 'selected' : '' ?>>Selesai</option>
                            </select>
                        </div>
                    </div>
                    <div>
                        <label>Deskripsi</label>
                        <textarea name="deskripsi" rows="3" style="width:100%; padding:8px; border:1px solid #ddd; border-radius:6px;"><?= sanitize($selectedBudget['deskripsi']) ?></textarea>
                    </div>
                    <button type="submit" class="btn btn-primary" style="width:auto;">Simpan Perubahan</button>
                </form>

                    <div style="padding:12px 0 20px 0; margin-bottom:8px;">
                    <button type="button" class="btn btn-success no-print" id="btn-open-add" style="width:auto;">Tambah Transaksi Rencana</button>
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
                        <div class="print-only" style="margin-bottom:16px; text-align:center;">
                            <h1 style="font-size:18px; margin:0;">Rencana Anggaran Belanja LPPAI UNISDA Tahun 2026/2027</h1>
                            <p style="margin:4px 0 0; font-size:14px;"><?= sanitize($selectedBudget['nama']) ?> • Periode <?= sanitize($selectedBudget['periode']) ?></p>
                        </div>
                        <table>
                        <thead>
                            <tr>
                                <th>Nama / Detail</th>
                                <th>Jumlah Item</th>
                                <th>Nilai per Item</th>
                                <th>Pemasukan</th>
                                <th>Pengeluaran</th>
                                <th>Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($plannedTransactions as $item): ?>
                                <tr>
                                    <td><?= sanitize($item['nama']) ?></td>
                                    <td><?= (int) $item['jumlah_item'] ?></td>
                                    <td><?= formatCurrency($item['nilai_per_item']) ?></td>
                                    <td><?= $item['jenis'] === 'pemasukan' ? formatCurrency($item['jumlah']) : '-' ?></td>
                                    <td><?= $item['jenis'] === 'pengeluaran' ? formatCurrency($item['jumlah']) : '-' ?></td>
                                    <td style="display:flex; gap:6px; flex-wrap:wrap; align-items:center;">
                                        <button type="button" class="btn btn-primary btn-edit" style="width:auto;"
                                            data-id="<?= (int) $item['id'] ?>"
                                            data-jenis="<?= sanitize($item['jenis']) ?>"
                                            data-nama="<?= htmlspecialchars($item['nama'], ENT_QUOTES) ?>"
                                            data-jumlah_item="<?= (int) $item['jumlah_item'] ?>"
                                            data-nilai_per_item="<?= (float) $item['nilai_per_item'] ?>"
                                            data-keterangan="<?= htmlspecialchars($item['keterangan'], ENT_QUOTES) ?>"
                                            onclick="window.openPlanModalFromElement(this)"
                                        >Edit</button>
                                        <a href="<?= BASE_URL ?>/admin/keuangan.php?view=rencana-anggaran&budget_id=<?= (int) $selectedBudget['id'] ?>&delete_plan_id=<?= (int) $item['id'] ?>&delete_plan_type=<?= sanitize($item['jenis']) ?>" class="btn btn-danger" style="width:auto;" onclick="return confirm('Hapus transaksi rencana ini?')">Delete</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    </div>

                    <div style="margin-top:16px; max-width:480px;">
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
                                    <td style="padding:8px; border:1px solid #ddd;"><?= formatCurrency($totalPlannedIncome ?? 0) ?></td>
                                    <td style="padding:8px; border:1px solid #ddd;"><?= formatCurrency($totalPlannedExpense ?? 0) ?></td>
                                    <td style="padding:8px; border:1px solid #ddd; font-weight:600;"><?= formatCurrency($totalPlannedRemaining ?? 0) ?></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>


            <?php else: ?>
                <div style="padding:12px 0 20px 0; margin-bottom:8px;">
                    <button type="button" class="btn btn-success no-print" id="btn-open-budget">Tambah Rencana</button>
                </div>

                <!-- Modal: Tambah/Edit Rencana Anggaran -->
                <div id="modal-budget" style="display:none; position:fixed; left:0; top:0; width:100%; height:100%; background:rgba(0,0,0,0.4); align-items:center; justify-content:center; z-index:9999;">
                    <div style="background:#fff; width:720px; max-width:95%; padding:16px; border-radius:8px; position:relative;">
                        <button type="button" id="modal-budget-close" style="position:absolute; right:12px; top:12px;">&times;</button>
                        <h3 id="modal-budget-title">Tambah Rencana Anggaran</h3>
                        <form method="post" id="modal-budget-form" style="display:grid; gap:12px; margin-top:8px;">
                            <input type="hidden" name="view" value="rencana-anggaran">
                            <input type="hidden" name="action" value="save-budget">
                            <input type="hidden" name="budget_id" id="modal-budget-id" value="">
                            <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(220px, 1fr)); gap:12px;">
                                <div>
                                    <label>Nama Rencana</label>
                                    <input type="text" name="nama" id="modal-budget-nama" required style="width:100%; padding:8px; border:1px solid #ddd; border-radius:6px;">
                                </div>
                                <div>
                                    <label>Periode</label>
                                    <input type="text" name="periode" id="modal-budget-periode" required placeholder="2026/2027" style="width:100%; padding:8px; border:1px solid #ddd; border-radius:6px;">
                                </div>
                                <div>
                                    <label>Total Anggaran</label>
                                    <input type="number" name="total_anggaran" id="modal-budget-total" min="0" step="1000" required style="width:100%; padding:8px; border:1px solid #ddd; border-radius:6px;">
                                </div>
                                <div>
                                    <label>Status</label>
                                    <select name="status" id="modal-budget-status" style="width:100%; padding:8px; border:1px solid #ddd; border-radius:6px;">
                                        <option value="aktif">Aktif</option>
                                        <option value="draft">Draft</option>
                                        <option value="selesai">Selesai</option>
                                    </select>
                                </div>
                            </div>
                            <div>
                                <label>Deskripsi</label>
                                <textarea name="deskripsi" id="modal-budget-deskripsi" rows="3" style="width:100%; padding:8px; border:1px solid #ddd; border-radius:6px;"></textarea>
                            </div>
                            <div style="display:flex; gap:8px;">
                                <button type="submit" class="btn btn-primary" id="modal-budget-submit">Simpan Rencana</button>
                                <button type="button" class="btn btn-secondary" id="modal-budget-cancel">Batal</button>
                            </div>
                        </form>
                    </div>
                </div>

                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th>Nama</th>
                                <th>Periode</th>
                                <th>Total</th>
                                <th>Status</th>
                                <th>Deskripsi</th>
                                <th>Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($budgets as $budget): ?>
                                <tr>
                                    <td><a href="<?= BASE_URL ?>/admin/keuangan_rencana.php?budget_id=<?= (int) $budget['id'] ?>" style="color:#2563eb; font-weight:600;"><?= sanitize($budget['nama']) ?></a></td>
                                    <td><?= sanitize($budget['periode']) ?></td>
                                    <td><?= formatCurrency($budget['total_anggaran']) ?></td>
                                    <td><?= sanitize(ucfirst($budget['status'])) ?></td>
                                    <td><?= sanitize($budget['deskripsi']) ?></td>
                                    <td style="display:flex; gap:6px; flex-wrap:wrap;">
                                        <a href="<?= BASE_URL ?>/admin/keuangan_rencana.php?budget_id=<?= (int) $budget['id'] ?>" class="btn btn-secondary" style="width:auto;">Detail</a>
                                        <button type="button" class="btn btn-primary btn-edit-budget" style="width:auto;"
                                            data-id="<?= (int) $budget['id'] ?>"
                                            data-nama="<?= htmlspecialchars($budget['nama'], ENT_QUOTES) ?>"
                                            data-periode="<?= htmlspecialchars($budget['periode'], ENT_QUOTES) ?>"
                                            data-total="<?= (float) $budget['total_anggaran'] ?>"
                                            data-status="<?= sanitize($budget['status']) ?>"
                                            data-deskripsi="<?= htmlspecialchars($budget['deskripsi'], ENT_QUOTES) ?>"
                                            onclick="window.openBudgetModalFromElement(this)"
                                        >Edit</button>
                                        <a href="<?= BASE_URL ?>/admin/keuangan.php?view=rencana-anggaran&budget_id=<?= (int) $budget['id'] ?>&action=delete" class="btn btn-danger" style="width:auto;" onclick="return confirm('Hapus rencana ini?')">Delete</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
                <script>
                    function printRab() {
                        const printContainer = document.getElementById('print-rab');
                        if (!printContainer) {
                            window.print();
                            return;
                        }
                        window.print();
                    }
                    // Modal handling for add/edit (transactions and budgets)
                    (function(){
                        // Transaction modal elements
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
                                const subBtn = document.getElementById('modal-submit');
                                if (subBtn) subBtn.textContent = 'Perbarui Transaksi';
                            } else {
                                modalTitle.textContent = 'Tambah Transaksi Rencana';
                                inputId.value = '';
                                inputOriginal.value = '';
                                inputJenis.value = 'pemasukan';
                                inputNama.value = '';
                                inputJumlahItem.value = 1;
                                inputNilaiPerItem.value = 0;
                                inputKeterangan.value = '';
                                const subBtn = document.getElementById('modal-submit');
                                if (subBtn) subBtn.textContent = 'Simpan Transaksi';
                            }
                            modal.style.display = 'flex';
                        }

                        function closeModal() { if (modal) modal.style.display = 'none'; }

                        if (btnOpenAdd) btnOpenAdd.addEventListener('click', function(){ openModal(null); });
                        if (btnClose) btnClose.addEventListener('click', closeModal);
                        if (btnCancel) btnCancel.addEventListener('click', closeModal);

                        if (modal) modal.addEventListener('click', function(e){ if (e.target === modal) closeModal(); });

                        // Budget modal elements
                        const btnOpenBudget = document.getElementById('btn-open-budget');
                        const modalBudget = document.getElementById('modal-budget');
                        const btnBudgetClose = document.getElementById('modal-budget-close');
                        const btnBudgetCancel = document.getElementById('modal-budget-cancel');
                        const budgetTitle = document.getElementById('modal-budget-title');
                        const budgetForm = document.getElementById('modal-budget-form');
                        const budgetIdInput = document.getElementById('modal-budget-id');
                        const budgetNama = document.getElementById('modal-budget-nama');
                        const budgetPeriode = document.getElementById('modal-budget-periode');
                        const budgetTotal = document.getElementById('modal-budget-total');
                        const budgetStatus = document.getElementById('modal-budget-status');
                        const budgetDeskripsi = document.getElementById('modal-budget-deskripsi');

                        function openBudgetModal(values){
                            if (!modalBudget) return;
                            if (values) {
                                if (budgetTitle) budgetTitle.textContent = 'Edit Rencana Anggaran';
                                if (budgetIdInput) budgetIdInput.value = values.id || '';
                                if (budgetNama) budgetNama.value = values.nama || '';
                                if (budgetPeriode) budgetPeriode.value = values.periode || '';
                                if (budgetTotal) budgetTotal.value = values.total || 0;
                                if (budgetStatus) budgetStatus.value = values.status || 'aktif';
                                if (budgetDeskripsi) budgetDeskripsi.value = values.deskripsi || '';
                                const subBBtn = document.getElementById('modal-budget-submit');
                                if (subBBtn) subBBtn.textContent = 'Perbarui Rencana';
                            } else {
                                if (budgetTitle) budgetTitle.textContent = 'Tambah Rencana Anggaran';
                                if (budgetIdInput) budgetIdInput.value = '';
                                if (budgetNama) budgetNama.value = '';
                                if (budgetPeriode) budgetPeriode.value = '';
                                if (budgetTotal) budgetTotal.value = '';
                                if (budgetStatus) budgetStatus.value = 'aktif';
                                if (budgetDeskripsi) budgetDeskripsi.value = '';
                                const subBBtn = document.getElementById('modal-budget-submit');
                                if (subBBtn) subBBtn.textContent = 'Simpan Rencana';
                            }
                            modalBudget.style.display = 'flex';
                        }

                        function closeBudgetModal(){ if (modalBudget) modalBudget.style.display = 'none'; }

                        if (btnOpenBudget) btnOpenBudget.addEventListener('click', function(){ openBudgetModal(null); });
                        if (btnBudgetClose) btnBudgetClose.addEventListener('click', closeBudgetModal);
                        if (btnBudgetCancel) btnBudgetCancel.addEventListener('click', closeBudgetModal);

                        if (modalBudget) modalBudget.addEventListener('click', function(e){ if (e.target === modalBudget) closeBudgetModal(); });

                        // expose global fallbacks so inline onclicks work if listeners failed
                        window.openPlanModalFromElement = function(el){
                            if (!el) return openModal(null);
                            const ds = el.dataset || {};
                            openModal({
                                id: ds.id,
                                jenis: ds.jenis,
                                nama: ds.nama,
                                jumlah_item: ds.jumlah_item,
                                nilai_per_item: ds.nilai_per_item,
                                keterangan: ds.keterangan
                            });
                        };

                        window.openBudgetModalFromElement = function(el){
                            if (!el) return openBudgetModal(null);
                            const ds = el.dataset || {};
                            openBudgetModal({
                                id: ds.id,
                                nama: ds.nama,
                                periode: ds.periode,
                                total: ds.total,
                                status: ds.status,
                                deskripsi: ds.deskripsi
                            });
                        };
                    })();
                </script>
        <?php elseif ($view === 'transaksi'): ?>
            <div style="padding:12px 0 20px 0; margin-bottom:8px;">
                <button type="button" class="btn btn-success no-print" onclick="window.openTransaksiModal(null)">Tambah Transaksi</button>
            </div>

            <!-- Modal: Tambah/Edit Transaksi -->
            <div id="modal-transaksi" style="display:none; position:fixed; left:0; top:0; width:100%; height:100%; background:rgba(0,0,0,0.4); align-items:center; justify-content:center; z-index:9999;">
                <div style="background:#fff; width:720px; max-width:95%; padding:16px; border-radius:8px; position:relative;">
                    <button type="button" onclick="closeTransaksiModal()" style="position:absolute; right:12px; top:12px;">&times;</button>
                    <h3 id="modal-transaksi-title">Tambah Transaksi</h3>
                    <form method="post" enctype="multipart/form-data" id="form-transaksi" style="display:grid; gap:12px; margin-top:8px;">
                        <input type="hidden" name="view" value="transaksi">
                        <input type="hidden" name="transaksi_id" id="input-transaksi-id" value="">
                        <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(220px, 1fr)); gap:12px;">
                            <div>
                                <label>Tanggal</label>
                                <input type="date" name="tanggal" id="input-transaksi-tanggal" value="<?= date('Y-m-d') ?>" required style="width:100%; padding:8px; border:1px solid #ddd; border-radius:6px;">
                            </div>
                            <div>
                                <label>Jenis Transaksi</label>
                                <select name="jenis" id="input-transaksi-jenis" style="width:100%; padding:8px; border:1px solid #ddd; border-radius:6px;">
                                    <option value="pemasukan">Pemasukan</option>
                                    <option value="pengeluaran">Pengeluaran</option>
                                </select>
                            </div>
                            <div>
                                <label>Nama / Detail</label>
                                <input type="text" name="nama" id="input-transaksi-nama" required placeholder="Contoh: Donatur, Belanja ATK" style="width:100%; padding:8px; border:1px solid #ddd; border-radius:6px;">
                            </div>
                            <div>
                                <label>Jumlah</label>
                                <input type="number" name="jumlah" id="input-transaksi-jumlah" min="0" step="1000" required style="width:100%; padding:8px; border:1px solid #ddd; border-radius:6px;">
                            </div>
                            <div>
                                <label>Kategori</label>
                                <input type="text" name="kategori" id="input-transaksi-kategori" placeholder="Opsional" style="width:100%; padding:8px; border:1px solid #ddd; border-radius:6px;">
                            </div>
                            <div>
                                <label>Bukti / Nota <span id="bukti-wajib">(Wajib)</span></label>
                                <input type="file" name="bukti" id="input-transaksi-bukti" required accept="image/*,.pdf" style="width:100%; padding:8px; border:1px solid #ddd; border-radius:6px;">
                                <small id="bukti-help" style="display:none; color:#666;">Biarkan kosong jika tidak ingin mengubah bukti.</small>
                            </div>
                        </div>
                        <div>
                            <label>Keterangan</label>
                            <textarea name="keterangan" id="input-transaksi-keterangan" rows="3" style="width:100%; padding:8px; border:1px solid #ddd; border-radius:6px;"></textarea>
                        </div>
                        <div style="display:flex; gap:8px;">
                            <button type="submit" class="btn btn-success" id="btn-submit-transaksi" style="width:auto;">Simpan Transaksi</button>
                            <button type="button" class="btn btn-secondary" onclick="closeTransaksiModal()">Batal</button>
                        </div>
                    </form>
                </div>
            </div>

            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>Tanggal</th>
                            <th>Jenis</th>
                            <th>Nama / Detail</th>
                            <th>Kategori</th>
                            <th>Jumlah</th>
                            <th>Keterangan</th>
                            <th>Bukti</th>
                            <th>Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($transactions as $transaction): ?>
                            <tr>
                                <td><?= sanitize($transaction['tanggal']) ?></td>
                                <td><?= sanitize(ucfirst($transaction['jenis'])) ?></td>
                                <td><?= sanitize($transaction['nama']) ?></td>
                                <td><?= sanitize($transaction['kategori']) ?></td>
                                <td><?= formatCurrency($transaction['jumlah']) ?></td>
                                <td><?= sanitize($transaction['keterangan']) ?></td>
                                <td>
                                    <?php if (!empty($transaction['bukti'])): ?>
                                        <a href="<?= BASE_URL . '/' . sanitize($transaction['bukti']) ?>" target="_blank" class="btn btn-secondary" style="padding:4px 8px; font-size:12px;">Lihat Bukti</a>
                                    <?php else: ?>
                                        -
                                    <?php endif; ?>
                                </td>
                                <td style="display:flex; gap:6px; flex-wrap:wrap;">
                                    <button type="button" class="btn btn-primary btn-edit-transaksi" style="width:auto; padding:4px 8px; font-size:12px;"
                                        data-id="<?= (int) $transaction['id'] ?>"
                                        data-tanggal="<?= sanitize($transaction['tanggal']) ?>"
                                        data-jenis="<?= sanitize($transaction['jenis']) ?>"
                                        data-nama="<?= htmlspecialchars($transaction['nama'], ENT_QUOTES) ?>"
                                        data-kategori="<?= htmlspecialchars($transaction['kategori'], ENT_QUOTES) ?>"
                                        data-jumlah="<?= (float) $transaction['jumlah'] ?>"
                                        data-keterangan="<?= htmlspecialchars($transaction['keterangan'], ENT_QUOTES) ?>"
                                        onclick="window.editTransaksi(this)"
                                    >Edit</button>
                                    <a href="<?= BASE_URL ?>/admin/keuangan.php?view=transaksi&action=delete-transaksi&id=<?= (int) $transaction['id'] ?>" class="btn btn-danger" style="width:auto; padding:4px 8px; font-size:12px;" onclick="return confirm('Hapus transaksi ini?')">Delete</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <script>
                function closeTransaksiModal() {
                    document.getElementById('modal-transaksi').style.display = 'none';
                }

                window.openTransaksiModal = function(values) {
                    const modal = document.getElementById('modal-transaksi');
                    if (values) {
                        document.getElementById('modal-transaksi-title').textContent = 'Edit Transaksi';
                        document.getElementById('input-transaksi-id').value = values.id;
                        document.getElementById('input-transaksi-tanggal').value = values.tanggal;
                        document.getElementById('input-transaksi-jenis').value = values.jenis;
                        document.getElementById('input-transaksi-nama').value = values.nama;
                        document.getElementById('input-transaksi-jumlah').value = values.jumlah;
                        document.getElementById('input-transaksi-kategori').value = values.kategori;
                        document.getElementById('input-transaksi-bukti').required = false;
                        document.getElementById('bukti-wajib').style.display = 'none';
                        document.getElementById('bukti-help').style.display = 'block';
                        document.getElementById('input-transaksi-keterangan').value = values.keterangan;
                        document.getElementById('btn-submit-transaksi').textContent = 'Perbarui Transaksi';
                    } else {
                        document.getElementById('modal-transaksi-title').textContent = 'Tambah Transaksi';
                        document.getElementById('input-transaksi-id').value = '';
                        document.getElementById('input-transaksi-tanggal').value = '<?= date('Y-m-d') ?>';
                        document.getElementById('input-transaksi-jenis').value = 'pemasukan';
                        document.getElementById('input-transaksi-nama').value = '';
                        document.getElementById('input-transaksi-jumlah').value = '';
                        document.getElementById('input-transaksi-kategori').value = '';
                        document.getElementById('input-transaksi-bukti').required = true;
                        document.getElementById('bukti-wajib').style.display = 'inline';
                        document.getElementById('bukti-help').style.display = 'none';
                        document.getElementById('input-transaksi-keterangan').value = '';
                        document.getElementById('btn-submit-transaksi').textContent = 'Simpan Transaksi';
                    }
                    modal.style.display = 'flex';
                };

                window.editTransaksi = function(el) {
                    const ds = el.dataset;
                    window.openTransaksiModal({
                        id: ds.id,
                        tanggal: ds.tanggal,
                        jenis: ds.jenis,
                        nama: ds.nama,
                        jumlah: ds.jumlah,
                        kategori: ds.kategori,
                        keterangan: ds.keterangan
                    });
                };

                document.getElementById('modal-transaksi').addEventListener('click', function(e) {
                    if (e.target === this) {
                        closeTransaksiModal();
                    }
                });
            </script>
        <?php else: ?>
            <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(220px, 1fr)); gap:12px; margin-bottom:20px;">
                <div class="stat-card">
                    <div class="stat-icon blue">💵</div>
                    <div class="stat-info">
                        <h3><?= formatCurrency($totalBudget) ?></h3>
                        <p>Total Rencana Anggaran</p>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon green">📥</div>
                    <div class="stat-info">
                        <h3><?= formatCurrency($totalIncome) ?></h3>
                        <p>Total Pemasukan</p>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon orange">📤</div>
                    <div class="stat-info">
                        <h3><?= formatCurrency($totalExpense) ?></h3>
                        <p>Total Pengeluaran</p>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon purple">⚖️</div>
                    <div class="stat-info">
                        <h3><?= formatCurrency($saldo) ?></h3>
                        <p>Saldo</p>
                    </div>
                </div>
            </div>

            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>Jenis</th>
                            <th>Detail</th>
                            <th>Jumlah</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr><td>Rencana Anggaran</td><td>Total anggaran aktif</td><td><?= formatCurrency($totalBudget) ?></td></tr>
                        <tr><td>Pemasukan</td><td>Total pemasukan tercatat</td><td><?= formatCurrency($totalIncome) ?></td></tr>
                        <tr><td>Pengeluaran</td><td>Total pengeluaran tercatat</td><td><?= formatCurrency($totalExpense) ?></td></tr>
                        <tr><td>Saldo</td><td>Sisa kas setelah pengeluaran</td><td><?= formatCurrency($saldo) ?></td></tr>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
