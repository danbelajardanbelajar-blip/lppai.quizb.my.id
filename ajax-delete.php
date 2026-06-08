<?php
/**
 * LPPAI Corner - Generic AJAX Delete Handler (root-level)
 * Called by JS to delete records without page reload.
 * Mirrors /api/ajax-delete.php — placed here so it is always
 * reachable even when the /api/ subdirectory is not deployed.
 */
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/logs/error.log');

header('Content-Type: application/json');

try {
    require_once __DIR__ . '/includes/auth.php';
    requireAdmin();

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        echo json_encode(['success' => false, 'message' => 'Method tidak valid.']);
        exit;
    }

    $token = $_POST['csrf_token'] ?? '';
    if (!verifyCsrf($token)) {
        echo json_encode(['success' => false, 'message' => 'Sesi tidak valid. Muat ulang halaman.']);
        exit;
    }

    // Whitelist: allowed tables and their PK column
    $allowedTables = [
        'users'                  => 'id',
        'announcements'          => 'id',
        'pretes_schedules'       => 'id',
        'pretes_results'         => 'id',
        'tutorial_classes'       => 'id',
        'tutorial_registrations' => 'id',
    ];

    $table = trim($_POST['table'] ?? '');
    $id    = (int)($_POST['id'] ?? 0);

    if (!array_key_exists($table, $allowedTables)) {
        echo json_encode(['success' => false, 'message' => 'Tabel tidak diizinkan.']);
        exit;
    }

    if ($id <= 0) {
        echo json_encode(['success' => false, 'message' => 'ID tidak valid.']);
        exit;
    }

    // Prevent deleting own admin account
    if ($table === 'users' && $id === (int)$_SESSION['user_id']) {
        echo json_encode(['success' => false, 'message' => 'Tidak dapat menghapus akun sendiri.']);
        exit;
    }

    $pdo = getDBConnection();
    $col  = $allowedTables[$table];

    // Verify record exists first
    $check = $pdo->prepare("SELECT {$col} FROM {$table} WHERE {$col} = ? LIMIT 1");
    $check->execute([$id]);
    if (!$check->fetch()) {
        echo json_encode(['success' => false, 'message' => 'Data tidak ditemukan.']);
        exit;
    }

    $pdo->prepare("DELETE FROM {$table} WHERE {$col} = ?")->execute([$id]);

    echo json_encode(['success' => true, 'message' => 'Data berhasil dihapus.']);

} catch (Throwable $e) {
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}
exit;
