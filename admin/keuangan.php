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
    'pemasukan' => 'Pemasukan',
    'pengeluaran' => 'Pengeluaran',
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

    if ($view === 'pemasukan' || $view === 'pengeluaran') {
        $jenis = trim($_POST['jenis'] ?? ($view === 'pengeluaran' ? 'pengeluaran' : 'pemasukan'));
        $stmt = $pdo->prepare("INSERT INTO keuangan_transaksi (tanggal, jenis, nama, jumlah, kategori, keterangan) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $_POST['tanggal'] ?? date('Y-m-d'),
            $jenis,
            trim($_POST['nama'] ?? ''),
            (float) ($_POST['jumlah'] ?? 0),
            trim($_POST['kategori'] ?? ''),
            trim($_POST['keterangan'] ?? '')
        ]);
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
            <a href="<?= BASE_URL ?>/admin/keuangan.php?view=pemasukan" class="btn btn-secondary" style="width:auto;">Pemasukan</a>
            <a href="<?= BASE_URL ?>/admin/keuangan.php?view=pengeluaran" class="btn btn-secondary" style="width:auto;">Pengeluaran</a>
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
                                            
                                        >Edit</button>
                                        <a href="<?= BASE_URL ?>/admin/keuangan.php?view=rencana-anggaran&budget_id=<?= (int) $budget['id'] ?>&action=delete" class="btn btn-danger" style="width:auto;" onclick="return confirm('Hapus rencana ini?')">Delete</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        <?php elseif ($view === 'pemasukan' || $view === 'pengeluaran'): ?>
            <?php $defaultType = $view === 'pengeluaran' ? 'pengeluaran' : 'pemasukan'; ?>
            <form method="post" style="display:grid; gap:12px; margin-bottom:20px;">
                <input type="hidden" name="view" value="<?= sanitize($view) ?>">
                <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(220px, 1fr)); gap:12px;">
                    <div>
                        <label>Tanggal</label>
                        <input type="date" name="tanggal" required style="width:100%; padding:8px; border:1px solid #ddd; border-radius:6px;">
                    </div>
                    <div>
                        <label>Jenis Transaksi</label>
                        <select name="jenis" style="width:100%; padding:8px; border:1px solid #ddd; border-radius:6px;">
                            <option value="pemasukan" <?= $defaultType === 'pemasukan' ? 'selected' : '' ?>>Pemasukan</option>
                            <option value="pengeluaran" <?= $defaultType === 'pengeluaran' ? 'selected' : '' ?>>Pengeluaran</option>
                        </select>
                    </div>
                    <div>
                        <label>Nama / Detail</label>
                        <input type="text" name="nama" required placeholder="Contoh: Donatur, Belanja ATK" style="width:100%; padding:8px; border:1px solid #ddd; border-radius:6px;">
                    </div>
                    <div>
                        <label>Jumlah</label>
                        <input type="number" name="jumlah" min="0" step="1000" required style="width:100%; padding:8px; border:1px solid #ddd; border-radius:6px;">
                    </div>
                    <div>
                        <label>Kategori</label>
                        <input type="text" name="kategori" placeholder="Opsional" style="width:100%; padding:8px; border:1px solid #ddd; border-radius:6px;">
                    </div>
                </div>
                <div>
                    <label>Keterangan</label>
                    <textarea name="keterangan" rows="3" style="width:100%; padding:8px; border:1px solid #ddd; border-radius:6px;"></textarea>
                </div>
                <button type="submit" class="btn btn-success" style="width:auto;">Simpan Transaksi</button>
            </form>

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
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
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
