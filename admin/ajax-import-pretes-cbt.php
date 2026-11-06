<?php
/**
 * LPPAI Corner - AJAX Import Nilai Hasil CBT Pretes
 * Endpoint untuk memproses file upload (CSV / Excel) atau teks hasil paste
 */
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../logs/error.log');
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');

try {
    set_time_limit(180);
    require_once __DIR__ . '/../includes/auth.php';
    requireAdmin();

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        echo json_encode(['success' => false, 'message' => 'Metode HTTP tidak valid. Gunakan POST.']);
        exit;
    }

    $token = $_POST['csrf_token'] ?? '';
    if (!verifyCsrf($token)) {
        echo json_encode(['success' => false, 'message' => 'Sesi tidak valid atau telah kedaluwarsa. Silakan muat ulang halaman.']);
        exit;
    }

    require_once __DIR__ . '/../config/database.php';
    require_once __DIR__ . '/../includes/pretes-helper.php';
    require_once __DIR__ . '/../includes/pretes-cbt-importer.php';
    require_once __DIR__ . '/../includes/import_eujian_excel.php';

    $pdo = getDBConnection();
    $user = getCurrentUser();

    // Tentukan periode target
    $periode = trim($_POST['periode'] ?? '');
    if ($periode === '') {
        $stmtAct = $pdo->query("SELECT periode FROM master_pretes WHERE status = 'aktif' ORDER BY id DESC LIMIT 1");
        $act = $stmtAct->fetch(PDO::FETCH_ASSOC);
        $periode = $act['periode'] ?? '';
    }
    if ($periode === '') {
        echo json_encode(['success' => false, 'message' => 'Periode pretes tidak ditemukan atau belum dipilih.']);
        exit;
    }

    $overwrite = isset($_POST['overwrite']) ? ((string)$_POST['overwrite'] === '1' || $_POST['overwrite'] === 'true') : true;
    $autoRegister = isset($_POST['auto_register']) ? ((string)$_POST['auto_register'] === '1' || $_POST['auto_register'] === 'true') : true;

    $options = [
        'overwrite' => $overwrite,
        'auto_register' => $autoRegister,
    ];

    $rows = [];

    // 1. Cek apakah ada teks paste
    if (!empty($_POST['cbt_text'])) {
        $rows = parsePretesCbtText($_POST['cbt_text']);
    }
    // 2. Cek apakah ada file upload
    elseif (isset($_FILES['cbt_file']) && $_FILES['cbt_file']['error'] === UPLOAD_ERR_OK) {
        $file = $_FILES['cbt_file'];
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

        if (!in_array($ext, ['csv', 'xlsx', 'xls'])) {
            echo json_encode(['success' => false, 'message' => 'Format berkas tidak didukung. Harap unggah berkas .csv, .xlsx, atau .xls.']);
            exit;
        }

        if ($file['size'] > 10 * 1024 * 1024) {
            echo json_encode(['success' => false, 'message' => 'Ukuran berkas terlalu besar. Maksimal 10MB.']);
            exit;
        }

        if ($ext === 'csv') {
            $content = file_get_contents($file['tmp_name']);
            $rows = parsePretesCbtText($content);
        } else {
            $rows = parseSpreadsheetRows($file['tmp_name'], $file['name']);
        }
    } else {
        echo json_encode(['success' => false, 'message' => 'Harap unggah berkas CBT atau tempelkan data teks hasil CBT.']);
        exit;
    }

    if (empty($rows)) {
        echo json_encode(['success' => false, 'message' => 'Data berkas atau teks kosong.']);
        exit;
    }

    $result = importPretesCbtData($pdo, $rows, $periode, (int)$user['id'], $options);

    echo json_encode($result);
    exit;

} catch (Throwable $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Terjadi kesalahan sistem: ' . $e->getMessage()
    ]);
    exit;
}
