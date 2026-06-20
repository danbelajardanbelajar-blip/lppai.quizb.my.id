<?php
/**
 * LPPAI Corner - API for Mobile App
 * Endpoint utama untuk WebView Android
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST');
header('Access-Control-Allow-Headers: Content-Type');

require_once __DIR__ . '/../config/database.php';

$pdo = getDBConnection();
$action = $_GET['action'] ?? '';

switch ($action) {
    case 'login':
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';

        if (empty($username) || empty($password)) {
            echo json_encode(['success' => false, 'message' => 'Username dan password harus diisi.']);
            exit;
        }

        $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ? LIMIT 1");
        $stmt->execute([$username]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password'])) {
            unset($user['password']);
            echo json_encode(['success' => true, 'user' => $user]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Username atau password salah.']);
        }
        break;

    case 'announcements':
        $tipe = $_GET['tipe'] ?? '';
        if ($tipe) {
            $stmt = $pdo->prepare("SELECT * FROM announcements WHERE tipe = ? AND is_active = 1 ORDER BY created_at DESC");
            $stmt->execute([$tipe]);
        } else {
            $stmt = $pdo->query("SELECT * FROM announcements WHERE is_active = 1 ORDER BY created_at DESC");
        }
        echo json_encode(['success' => true, 'data' => $stmt->fetchAll()]);
        break;

    case 'pretes_schedules':
        $stmt = $pdo->query("SELECT * FROM pretes_schedules WHERE status = 'aktif' AND (periode LIKE '%2026%' OR periode LIKE '%2027%' OR periode LIKE '%2028%' OR periode LIKE '%2029%' OR periode LIKE '%2030%') ORDER BY tanggal, waktu_mulai");
        echo json_encode(['success' => true, 'data' => $stmt->fetchAll()]);
        break;

    case 'pretes_results':
        $userId = (int)($_GET['user_id'] ?? 0);
        if ($userId > 0) {
            $stmt = $pdo->prepare("SELECT pr.*, ps.periode, ps.tanggal FROM pretes_results pr LEFT JOIN pretes_schedules ps ON pr.pretes_schedule_id = ps.id WHERE pr.user_id = ?");
            $stmt->execute([$userId]);
        } else {
            $stmt = $pdo->query("SELECT pr.*, u.nama_lengkap, u.nim FROM pretes_results pr JOIN users u ON pr.user_id = u.id WHERE pr.status_lulus != 'belum_diumumkan' ORDER BY pr.nilai DESC");
        }
        echo json_encode(['success' => true, 'data' => $stmt->fetchAll()]);
        break;

    case 'tutorial_classes':
        $gelombang = $_GET['gelombang'] ?? '';
        if ($gelombang) {
            $stmt = $pdo->prepare("SELECT * FROM tutorial_classes WHERE gelombang = ? AND (semester LIKE '%2026%' OR semester LIKE '%2027%' OR semester LIKE '%2028%' OR semester LIKE '%2029%' OR semester LIKE '%2030%') ORDER BY nama_kelas");
            $stmt->execute([$gelombang]);
        } else {
            $stmt = $pdo->query("SELECT * FROM tutorial_classes WHERE (semester LIKE '%2026%' OR semester LIKE '%2027%' OR semester LIKE '%2028%' OR semester LIKE '%2029%' OR semester LIKE '%2030%') ORDER BY gelombang, nama_kelas");
        }
        echo json_encode(['success' => true, 'data' => $stmt->fetchAll()]);
        break;

    default:
        echo json_encode(['success' => false, 'message' => 'Unknown action.']);
}
