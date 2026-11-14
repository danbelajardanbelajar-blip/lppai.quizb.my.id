<?php
define('PAGE_TITLE', 'Bank Soal & Pengatur Sesi CBT Pretes');
require_once __DIR__ . '/../includes/auth.php';
requireAdmin();
require_once __DIR__ . '/../includes/pretes-cbt.php';
require_once __DIR__ . '/../includes/pretes-tryout.php';
$tryoutLabelCache = [];
require_once __DIR__ . '/../includes/pretes-cbt-xlsx.php';
$bankUrl = BASE_URL . '/pretes-ujian.php?cbt_bank=1';
if (isset($_GET['download']) && $_GET['download'] === 'template') cbtSendXlsx(__DIR__ . '/../downloads/template-bank-soal-cbt.xlsx', 'template-bank-soal-cbt.xlsx');
$pdo = getDBConnection();
ensurePretesCbtTables($pdo);

// Endpoint AJAX untuk kebutuhan data dinamis modal tanpa reload halaman
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json; charset=utf-8');
    if ($_GET['ajax'] === 'generate_token') {
        echo json_encode(['token' => pretesCbtGenerateToken(6)]);
        exit;
    }
    if ($_GET['ajax'] === 'package_categories') {
        $raw = $_GET['package_ids'] ?? [];
        $ids = is_array($raw) ? $raw : explode(',', (string)$raw);
        $cleanIds = array_values(array_filter(array_map('intval', $ids), fn($v) => $v > 0));
        if (empty($cleanIds)) {
            echo json_encode(['categories' => []]);
            exit;
        }
        $placeholders = implode(',', array_fill(0, count($cleanIds), '?'));
        $stmt = $pdo->prepare("SELECT category, COUNT(*) as total, SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) as active_count FROM pretes_cbt_questions WHERE package_id IN ($placeholders) GROUP BY category ORDER BY category ASC");
        $stmt->execute($cleanIds);
        echo json_encode(['categories' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
        exit;
    }
    if ($_GET['ajax'] === 'package_questions') {
        $pkgId = (int)($_GET['package_id'] ?? 0);
        $pkg = $pkgId > 0 ? pretesCbtGetPackage($pdo, $pkgId) : null;
        $qList = pretesCbtGetPackageQuestions($pdo, $pkgId);
        echo json_encode([
            'success' => true,
            'package' => $pkg,
            'questions' => $qList
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($_GET['ajax'] === 'get_question') {
        $qId = (int)($_GET['question_id'] ?? 0);
        $q = pretesCbtGetQuestion($pdo, $qId);
        if (!$q) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Soal tidak ditemukan.']);
            exit;
        }
        echo json_encode(['success' => true, 'question' => $q], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($_GET['ajax'] === 'live_results') {
        $filter = isset($_GET['result_filter']) ? trim((string)$_GET['result_filter']) : 'all';
        if (!in_array($filter, ['all', 'resmi', 'tryout'], true)) $filter = 'all';

        $where = '';
        if ($filter === 'resmi') {
            $where = 'WHERE a.registration_id > 0';
        } elseif ($filter === 'tryout') {
            $where = 'WHERE a.registration_id < 0';
        }

        $attempts = $pdo->query("SELECT a.*, u.nama_lengkap, u.nim, u.program_studi, u.jenis_program FROM pretes_cbt_attempts a JOIN users u ON u.id = a.user_id $where ORDER BY a.started_at DESC LIMIT 100")->fetchAll(PDO::FETCH_ASSOC);

        $attemptIds = array_map(fn($a) => (int)$a['id'], $attempts);
        $itemStatsMap = [];
        if (!empty($attemptIds)) {
            $placeholders = implode(',', array_fill(0, count($attemptIds), '?'));
            $stmtStats = $pdo->prepare("
                SELECT 
                    attempt_id,
                    COUNT(*) AS total_items,
                    SUM(CASE WHEN answer IS NOT NULL AND answer != '' THEN 1 ELSE 0 END) AS answered_count,
                    SUM(CASE WHEN flagged = 1 THEN 1 ELSE 0 END) AS flagged_count,
                    SUM(CASE WHEN answer IS NOT NULL AND answer != '' AND answer = correct_answer THEN 1 ELSE 0 END) AS live_correct_count,
                    SUM(CASE WHEN answer IS NOT NULL AND answer != '' AND answer != correct_answer THEN 1 ELSE 0 END) AS live_wrong_count
                FROM pretes_cbt_items
                WHERE attempt_id IN ($placeholders)
                GROUP BY attempt_id
            ");
            $stmtStats->execute($attemptIds);
            foreach ($stmtStats->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $itemStatsMap[(int)$row['attempt_id']] = $row;
            }
        }

        $tryoutCache = [];
        $data = [];
        $now = time();
        foreach ($attempts as $a) {
            $attId = (int)$a['id'];
            $stat = $itemStatsMap[$attId] ?? null;
            $totalItems = $stat ? (int)$stat['total_items'] : (int)($a['total_questions'] ?? 0);
            $answered = $stat ? (int)$stat['answered_count'] : 0;
            $flagged = $stat ? (int)$stat['flagged_count'] : 0;
            $unanswered = max(0, $totalItems - $answered);
            $isSubmitted = ($a['submitted_at'] !== null);
            $deadline = (int)$a['deadline_at'];
            $isExpired = (!$isSubmitted && $now >= $deadline);
            $isOngoing = (!$isSubmitted && !$isExpired);
            $remainingSeconds = max(0, $deadline - $now);

            $correct = $isSubmitted ? (int)($a['correct_count'] ?? 0) : ($stat ? (int)$stat['live_correct_count'] : 0);
            $wrong = $isSubmitted ? max(0, $totalItems - $correct) : ($stat ? (int)$stat['live_wrong_count'] : 0);
            $score = $isSubmitted ? ($a['score'] !== null ? (float)$a['score'] : null) : ($totalItems > 0 ? round(($correct / $totalItems) * 100, 1) : 0.0);

            $durationSeconds = $isSubmitted ? max(0, (int)$a['submitted_at'] - (int)$a['started_at']) : 0;

            $jenis = pretesTryoutAttemptLabel($pdo, $a, $tryoutCache);
            $startedWib = (new DateTimeImmutable('@' . $a['started_at']))->setTimezone(new DateTimeZone('Asia/Jakarta'))->format('d/m/Y H:i');

            $data[] = [
                'id' => $attId,
                'nama_lengkap' => $a['nama_lengkap'],
                'nim' => $a['nim'] ?? '-',
                'program_studi' => $a['program_studi'] ?? '',
                'jenis_program' => normalizeJenisProgram($a['jenis_program'] ?? 'Reguler'),
                'jenis_program_badge' => renderJenisProgramBadge($a['jenis_program'] ?? 'Reguler', true),
                'jenis_kind' => $jenis['kind'],
                'jenis_label' => $jenis['label'],
                'started_at' => (int)$a['started_at'],
                'started_at_wib' => $startedWib,
                'is_submitted' => $isSubmitted,
                'is_ongoing' => $isOngoing,
                'is_expired' => $isExpired,
                'deadline_at' => $deadline,
                'remaining_seconds' => $remainingSeconds,
                'duration_seconds' => $durationSeconds,
                'total_items' => $totalItems,
                'answered_count' => $answered,
                'unanswered_count' => $unanswered,
                'flagged_count' => $flagged,
                'correct_count' => $correct,
                'wrong_count' => $wrong,
                'score' => $score !== null ? number_format($score, 1) : null,
                'progress_percent' => $totalItems > 0 ? min(100, round(($answered / $totalItems) * 100)) : 0
            ];
        }

        $resmiStats = $pdo->query('SELECT COUNT(*) AS total FROM pretes_cbt_attempts WHERE registration_id > 0')->fetch(PDO::FETCH_ASSOC) ?: [];
        $tryoutStats = $pdo->query('SELECT COUNT(*) AS total FROM pretes_cbt_attempts WHERE registration_id < 0')->fetch(PDO::FETCH_ASSOC) ?: [];
        $totalAll = (int)($pdo->query('SELECT COUNT(*) FROM pretes_cbt_attempts')->fetchColumn() ?: 0);
        $ongoingCount = (int)($pdo->query("SELECT COUNT(*) FROM pretes_cbt_attempts WHERE submitted_at IS NULL AND deadline_at > $now")->fetchColumn() ?: 0);

        echo json_encode([
            'success' => true,
            'timestamp' => $now,
            'timestamp_wib' => (new DateTimeImmutable('@' . $now))->setTimezone(new DateTimeZone('Asia/Jakarta'))->format('H:i:s') . ' WIB',
            'ongoing_count' => $ongoingCount,
            'total_all' => $totalAll,
            'total_resmi' => (int)($resmiStats['total'] ?? 0),
            'total_tryout' => (int)($tryoutStats['total'] ?? 0),
            'data' => $data
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

$expired = $pdo->prepare('SELECT user_id, registration_id FROM pretes_cbt_attempts WHERE submitted_at IS NULL AND deadline_at <= ? ORDER BY deadline_at DESC LIMIT 100');
$expired->execute([time()]);
foreach ($expired->fetchAll(PDO::FETCH_ASSOC) as $entry) pretesCbtState($pdo, (int)$entry['user_id'], (int)$entry['registration_id']);
if (isset($_GET['download'])) {
    try {
        if ($_GET['download'] === 'bank') cbtSendXlsx(cbtWriteXlsx(cbtBankExportRows($pdo), 'Bank Soal', [24,22,60,28,28,28,28,28,12,16]), 'bank-soal-cbt-' . date('Ymd') . '.xlsx', true);
        elseif ($_GET['download'] === 'results') {
            $filter = isset($_GET['filter']) ? trim((string)$_GET['filter']) : 'all';
            if (!in_array($filter, ['all', 'resmi', 'tryout'], true)) $filter = 'all';
            $prefix = 'hasil-cbt-';
            if ($filter === 'resmi') $prefix = 'hasil-cbt-resmi-';
            elseif ($filter === 'tryout') $prefix = 'hasil-cbt-tryout-';
            cbtSendXlsx(cbtWriteXlsx(cbtResultsExportRows($pdo, $filter), 'Hasil CBT', [16,28,16,26,20,20,16,16,14,12]), $prefix . date('Ymd') . '.xlsx', true);
        }
        elseif ($_GET['download'] === 'template') cbtSendXlsx(__DIR__ . '/../downloads/template-bank-soal-cbt.xlsx', 'template-bank-soal-cbt.xlsx');
        elseif ($_GET['download'] === 'pretes177') cbtSendXlsx(__DIR__ . '/../downloads/bank-soal-pretes-177-soal.xlsx', 'bank-soal-pretes-177-soal.xlsx');
        throw new InvalidArgumentException('Jenis unduhan tidak dikenal.');
    } catch (Throwable $error) {
        error_log('CBT XLSX download: ' . $error->getMessage());
        http_response_code(503);
        header('Content-Type: text/plain; charset=utf-8');
        echo $error instanceof RuntimeException ? $error->getMessage() : 'Unduhan belum tersedia. Kembali ke bank soal dan coba lagi.';
        exit;
    }
}

$message = ''; $errors = []; $form = null; $pkgForm = null; $sesForm = null;
$allowedTabs = ['packages', 'sessions', 'import', 'results'];
$rawTab = $_GET['tab'] ?? '';
if ($rawTab === 'bank' || $rawTab === 'form') {
    $activeTab = 'packages';
} else {
    $activeTab = in_array($rawTab, $allowedTabs, true) ? $rawTab : 'packages';
}
$flash = $_SESSION['cbt_bank_flash'] ?? null;
unset($_SESSION['cbt_bank_flash']);

$packages = pretesCbtGetPackages($pdo);
$activePackage = pretesCbtGetActivePackage($pdo);
$sessions = pretesCbtGetSessions($pdo);
$activeSession = pretesCbtGetActiveSession($pdo);

$isAjax = isset($_POST['ajax']) || (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'save_question';
    if ($action === 'import_xlsx') {
        $activeTab = 'import';
    } elseif (in_array($action, ['save_session', 'activate_session', 'delete_session', 'delete_sessions', 'set_exam_open'], true)) {
        $activeTab = 'sessions';
    } elseif (in_array($action, ['save_package', 'activate_package', 'delete_package', 'delete_packages'], true)) {
        $activeTab = 'packages';
    } elseif ($action === 'delete_attempt' || $action === 'delete_all_attempts') {
        $activeTab = 'results';
    } else {
        $activeTab = in_array($_POST['current_tab'] ?? '', $allowedTabs, true) ? $_POST['current_tab'] : 'packages';
    }

    try {
        if (!is_string($_POST['csrf_token'] ?? null) || !verifyCsrf($_POST['csrf_token'])) {
            http_response_code(403);
            throw new InvalidArgumentException('Sesi tidak valid. Muat ulang halaman.');
        }

        if ($action === 'save_session') {
            $sesId = pretesCbtSaveSession($pdo, $_POST);
            $flashMsg = 'Sesi ujian berhasil disimpan.';
            if ($isAjax) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['success' => true, 'message' => $flashMsg, 'session_id' => $sesId]);
                exit;
            }
            $_SESSION['cbt_bank_flash'] = $flashMsg;
            header('Location: ' . $bankUrl . '&tab=sessions'); exit;
        } elseif ($action === 'set_exam_open') {
            // Sakelar admin: buka / tutup pengerjaan ujian pretes resmi
            $sesId = (int)($_POST['session_id'] ?? 0);
            $open = (int)($_POST['open'] ?? 0) === 1;
            if ($sesId <= 0) throw new InvalidArgumentException('ID sesi ujian tidak valid.');
            if (!pretesCbtSetExamOpen($pdo, $sesId, $open)) throw new InvalidArgumentException('Sesi ujian resmi tidak ditemukan.');
            $flashMsg = $open
                ? '🟢 Pengerjaan ujian pretes DIBUKA. Peserta dapat menekan "Kerjakan Soal Pretes" (tetap mengikuti jadwal mulai/batas sesi bila diisi).'
                : '🔴 Pengerjaan ujian pretes DITUTUP. Peserta tidak dapat memulai ujian baru; peserta yang sedang mengerjakan tetap dapat menyelesaikan.';
            if ($isAjax) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['success' => true, 'message' => $flashMsg]);
                exit;
            }
            $_SESSION['cbt_bank_flash'] = $flashMsg;
            header('Location: ' . $bankUrl . '&tab=sessions'); exit;
        } elseif ($action === 'activate_session') {
            $sesId = (int)($_POST['session_id'] ?? 0);
            if ($sesId <= 0) throw new InvalidArgumentException('ID sesi ujian tidak valid.');
            if (pretesCbtActivateSession($pdo, $sesId)) {
                $flashMsg = 'Sesi ujian aktif berhasil diperbarui. Peserta akan diarahkan ke sesi ini.';
            } else {
                $flashMsg = 'Sesi ujian tidak ditemukan.';
            }
            if ($isAjax) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['success' => true, 'message' => $flashMsg]);
                exit;
            }
            $_SESSION['cbt_bank_flash'] = $flashMsg;
            header('Location: ' . $bankUrl . '&tab=sessions'); exit;
        } elseif ($action === 'delete_session') {
            $sesId = (int)($_POST['session_id'] ?? 0);
            if ($sesId <= 0) throw new InvalidArgumentException('ID sesi ujian tidak valid.');
            if (pretesCbtDeleteSession($pdo, $sesId)) {
                $flashMsg = 'Sesi ujian berhasil dihapus.';
            } else {
                $flashMsg = 'Sesi ujian gagal dihapus.';
            }
            if ($isAjax) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['success' => true, 'message' => $flashMsg]);
                exit;
            }
            $_SESSION['cbt_bank_flash'] = $flashMsg;
            header('Location: ' . $bankUrl . '&tab=sessions'); exit;
        } elseif ($action === 'delete_sessions') {
            $rawIds = $_POST['session_ids'] ?? null;
            if (!is_array($rawIds) || empty($rawIds)) throw new InvalidArgumentException('Pilih minimal satu sesi ujian yang ingin dihapus.');
            $sessionIds = [];
            foreach ($rawIds as $rawId) {
                $id = filter_var($rawId, FILTER_VALIDATE_INT);
                if ($id === false || $id <= 0) throw new InvalidArgumentException('Daftar sesi terpilih tidak valid. Muat ulang halaman lalu coba kembali.');
                $sessionIds[] = $id;
            }
            $deletedCount = pretesCbtDeleteSessions($pdo, $sessionIds);
            $flashMsg = $deletedCount > 0
                ? "$deletedCount sesi ujian berhasil dihapus. Riwayat pengerjaan peserta tetap tersimpan."
                : 'Tidak ada sesi terpilih yang ditemukan; daftar tidak berubah.';
            if ($isAjax) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['success' => $deletedCount > 0, 'deleted' => $deletedCount, 'message' => $flashMsg]);
                exit;
            }
            $_SESSION['cbt_bank_flash'] = $flashMsg;
            header('Location: ' . $bankUrl . '&tab=sessions'); exit;
        } elseif ($action === 'save_package') {
            $pkgId = pretesCbtSavePackage($pdo, $_POST);
            $flashMsg = 'Paket soal berhasil disimpan.';
            if ($isAjax) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['success' => true, 'message' => $flashMsg, 'package_id' => $pkgId]);
                exit;
            }
            $_SESSION['cbt_bank_flash'] = $flashMsg;
            header('Location: ' . $bankUrl . '&tab=packages'); exit;
        } elseif ($action === 'activate_package') {
            $pkgId = (int)($_POST['package_id'] ?? 0);
            if ($pkgId <= 0) throw new InvalidArgumentException('ID paket soal tidak valid.');
            if (pretesCbtActivatePackage($pdo, $pkgId)) {
                $flashMsg = 'Paket soal aktif berhasil diubah. Peserta yang memulai ujian berikutnya akan diarahkan ke paket ini.';
            } else {
                $flashMsg = 'Paket soal tidak ditemukan.';
            }
            if ($isAjax) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['success' => true, 'message' => $flashMsg]);
                exit;
            }
            $_SESSION['cbt_bank_flash'] = $flashMsg;
            header('Location: ' . $bankUrl . '&tab=packages'); exit;
        } elseif ($action === 'delete_package') {
            $pkgId = (int)($_POST['package_id'] ?? 0);
            if ($pkgId <= 0) throw new InvalidArgumentException('ID paket soal tidak valid.');
            if (pretesCbtDeletePackage($pdo, $pkgId)) {
                $flashMsg = 'Paket soal beserta butir soal di dalamnya berhasil dihapus.';
            } else {
                $flashMsg = 'Paket soal gagal dihapus atau sudah dihapus sebelumnya.';
            }
            if ($isAjax) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['success' => true, 'message' => $flashMsg]);
                exit;
            }
            $_SESSION['cbt_bank_flash'] = $flashMsg;
            header('Location: ' . $bankUrl . '&tab=packages'); exit;
        } elseif ($action === 'delete_packages') {
            $rawIds = $_POST['package_ids'] ?? null;
            if (!is_array($rawIds) || empty($rawIds)) throw new InvalidArgumentException('Pilih minimal satu paket soal yang ingin dihapus.');
            $packageIds = [];
            foreach ($rawIds as $rawId) {
                $id = filter_var($rawId, FILTER_VALIDATE_INT);
                if ($id === false || $id <= 0) throw new InvalidArgumentException('Daftar paket terpilih tidak valid. Muat ulang halaman lalu coba kembali.');
                $packageIds[] = $id;
            }
            $deleted = pretesCbtDeletePackages($pdo, $packageIds);
            $flashMsg = $deleted['packages'] > 0
                ? $deleted['packages'] . ' paket soal dan ' . $deleted['questions'] . ' butir soal berhasil dihapus.'
                : 'Tidak ada paket terpilih yang ditemukan; daftar tidak berubah.';
            if ($isAjax) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['success' => $deleted['packages'] > 0, 'deleted' => $deleted, 'message' => $flashMsg]);
                exit;
            }
            $_SESSION['cbt_bank_flash'] = $flashMsg;
            header('Location: ' . $bankUrl . '&tab=packages'); exit;
        } elseif ($action === 'import_xlsx') {
            $packageId = (int)($_POST['package_id'] ?? 0);
            if ($packageId <= 0) {
                $packageId = (int)($activePackage['id'] ?? ($packages[0]['id'] ?? 0));
            }
            if ($packageId <= 0) throw new InvalidArgumentException('Belum ada paket soal. Buat paket soal terlebih dahulu sebelum mengimpor soal.');
            $file = $_FILES['question_file'] ?? null;
            if (!$file || $file['error'] !== UPLOAD_ERR_OK) throw new InvalidArgumentException('Pilih file .xlsx. Batas unggahan server: ' . ini_get('upload_max_filesize') . '.');
            if (strtolower(pathinfo($file['name'], PATHINFO_EXTENSION)) !== 'xlsx' || !is_uploaded_file($file['tmp_name'])) throw new InvalidArgumentException('Gunakan file Excel .xlsx dari template bank soal.');
            $result = cbtImportQuestions($pdo, cbtReadQuestionXlsx($file['tmp_name']), isset($_POST['update_existing']), $packageId);
            if ($result['errors']) {
                http_response_code(422);
                $message = 'Impor dibatalkan. Tidak ada soal ditambahkan atau diperbarui. Perbaiki baris berikut dan unggah kembali.';
                $errors = $result['errors'];
            } else {
                $_SESSION['cbt_bank_flash'] = $result['added'] . ' soal ditambahkan, ' . $result['updated'] . ' diperbarui, ' . $result['skipped'] . ' duplikat/soal lama dilewati.';
                header('Location: ' . $bankUrl . '&tab=bank'); exit;
            }
        } elseif ($action === 'save_question') {
            $packageId = (int)($_POST['package_id'] ?? 0);
            if ($packageId <= 0) {
                $packageId = (int)($activePackage['id'] ?? ($packages[0]['id'] ?? 0));
            }
            if ($packageId <= 0) throw new InvalidArgumentException('Belum ada paket soal. Buat paket soal terlebih dahulu sebelum menambahkan soal.');
            $_POST['package_id'] = $packageId;
            $qId = pretesCbtSaveQuestion($pdo, $_POST);
            $flashMsg = 'Soal berhasil disimpan. Paket ujian yang sudah dimulai tidak berubah.';
            if ($isAjax) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['success' => true, 'message' => $flashMsg, 'question_id' => $qId]);
                exit;
            }
            $_SESSION['cbt_bank_flash'] = $flashMsg;
            $targetRedirectTab = in_array($_POST['current_tab'] ?? '', $allowedTabs, true) ? $_POST['current_tab'] : 'packages';
            header('Location: ' . $bankUrl . '&tab=' . $targetRedirectTab); exit;
        } elseif ($action === 'delete_question') {
            $questionId = (int)($_POST['question_id'] ?? 0);
            if ($questionId <= 0) throw new InvalidArgumentException('ID butir soal tidak valid.');
            if (pretesCbtDeleteQuestion($pdo, $questionId)) {
                $flashMsg = 'Butir soal berhasil dihapus.';
            } else {
                $flashMsg = 'Butir soal tidak ditemukan atau sudah dihapus.';
            }
            if ($isAjax) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['success' => true, 'message' => $flashMsg]);
                exit;
            }
            $_SESSION['cbt_bank_flash'] = $flashMsg;
            $targetRedirectTab = in_array($_POST['current_tab'] ?? '', $allowedTabs, true) ? $_POST['current_tab'] : 'packages';
            header('Location: ' . $bankUrl . '&tab=' . $targetRedirectTab); exit;
        } elseif ($action === 'delete_attempt') {
            $activeTab = 'results';
            $attemptId = (int)($_POST['attempt_id'] ?? 0);
            if ($attemptId <= 0) throw new InvalidArgumentException('ID sesi CBT tidak valid.');
            $stmtFind = $pdo->prepare('SELECT a.id, u.nama_lengkap, u.nim FROM pretes_cbt_attempts a JOIN users u ON u.id = a.user_id WHERE a.id = ?');
            $stmtFind->execute([$attemptId]);
            $info = $stmtFind->fetch(PDO::FETCH_ASSOC);

            if (pretesCbtDeleteAttempt($pdo, $attemptId)) {
                $name = $info ? ($info['nama_lengkap'] . ' (' . ($info['nim'] ?: '-') . ')') : "ID #$attemptId";
                $_SESSION['cbt_bank_flash'] = "Sesi CBT peserta $name berhasil dihapus. Peserta kini dapat mengulang ujian kembali.";
            } else {
                $_SESSION['cbt_bank_flash'] = 'Sesi CBT tidak ditemukan atau sudah dihapus sebelumnya.';
            }
            header('Location: ' . $bankUrl . '&tab=results'); exit;
        } elseif ($action === 'delete_all_attempts') {
            $activeTab = 'results';
            $scope = isset($_POST['delete_scope']) ? trim((string)$_POST['delete_scope']) : 'all';
            if (!in_array($scope, ['all', 'resmi', 'tryout'], true)) $scope = 'all';
            $count = pretesCbtDeleteAllAttempts($pdo, null, $scope);
            $scopeLabel = $scope === 'tryout' ? 'tryout ' : ($scope === 'resmi' ? 'ujian resmi ' : '');
            $_SESSION['cbt_bank_flash'] = "Berhasil menghapus seluruh data sesi CBT {$scopeLabel}($count sesi dihapus).";
            header('Location: ' . $bankUrl . '&tab=results' . ($scope !== 'all' ? '&result_filter=' . urlencode($scope) : '')); exit;
        } else throw new InvalidArgumentException('Aksi bank soal tidak valid.');
    } catch (InvalidArgumentException $error) {
        if (http_response_code() !== 403) http_response_code(422);
        $message = $error->getMessage();
        if ($isAjax) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success' => false, 'message' => $message]);
            exit;
        }
        if ($action === 'save_question') $form = $_POST;
        if ($action === 'save_package') $pkgForm = $_POST;
        if ($action === 'save_session') $sesForm = $_POST;
    } catch (Throwable $error) {
        error_log('CBT bank update: ' . $error->getMessage()); http_response_code(503);
        $message = 'Perubahan belum tersimpan. ' . ($error instanceof RuntimeException && !($error instanceof PDOException) ? $error->getMessage() : 'Coba lagi atau hubungi admin.');
        if ($isAjax) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success' => false, 'message' => $message]);
            exit;
        }
        if ($action === 'save_question') $form = $_POST;
        if ($action === 'save_package') $pkgForm = $_POST;
        if ($action === 'save_session') $sesForm = $_POST;
    }
    // Refresh lists after mutations
    $packages = pretesCbtGetPackages($pdo);
    $activePackage = pretesCbtGetActivePackage($pdo);
    $sessions = pretesCbtGetSessions($pdo);
    $activeSession = pretesCbtGetActiveSession($pdo);
}

if ($pkgForm === null && !empty($_GET['edit_pkg'])) {
    $pkgForm = pretesCbtGetPackage($pdo, (int)$_GET['edit_pkg']);
    if ($pkgForm) {
        $activeTab = 'packages';
    }
}
if ($sesForm === null && !empty($_GET['edit_ses'])) {
    $sesForm = pretesCbtGetSession($pdo, (int)$_GET['edit_ses']);
    if ($sesForm) {
        $activeTab = 'sessions';
    }
}
if ($form === null && !empty($_GET['edit'])) {
    $stmt = $pdo->prepare('SELECT * FROM pretes_cbt_questions WHERE id = ?'); $stmt->execute([(int)$_GET['edit']]);
    $form = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    if ($form) { $activeTab = 'form'; foreach (json_decode($form['options_json'], true) as $letter => $text) $form['option_' . $letter] = $text; }
}
$form ??= ['is_active'=>1,'category'=>'Umum','package_id'=>$activePackage['id'] ?? ($packages[0]['id'] ?? 1)];
$questions = $pdo->query('SELECT q.*, p.name AS package_name FROM pretes_cbt_questions q LEFT JOIN pretes_cbt_packages p ON p.id = q.package_id ORDER BY q.id DESC')->fetchAll(PDO::FETCH_ASSOC);
$active = count(array_filter($questions, static fn($question) => (bool)$question['is_active']));
$categories = array_values(array_unique(array_column($questions, 'category'))); sort($categories);

// Pengelompokan kategori dan hitungan soal per kategori per paket untuk modal sesi
$packageCategoriesMap = [];
foreach ($packages as $pkg) {
    $stmt = $pdo->prepare("SELECT category, COUNT(*) as total, SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) as active_count FROM pretes_cbt_questions WHERE package_id = ? GROUP BY category ORDER BY category ASC");
    $stmt->execute([(int)$pkg['id']]);
    $packageCategoriesMap[(int)$pkg['id']] = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$stats = $pdo->query('SELECT COUNT(*) AS total, SUM(CASE WHEN submitted_at IS NOT NULL THEN 1 ELSE 0 END) AS finished, AVG(score) AS average_score FROM pretes_cbt_attempts WHERE registration_id > 0')->fetch(PDO::FETCH_ASSOC) ?: [];
$stats['total'] = (int)($stats['total'] ?? 0);
$stats['finished'] = (int)($stats['finished'] ?? 0);
$stats['average_score'] = $stats['average_score'] !== null ? (float)$stats['average_score'] : null;

$tryoutStats = $pdo->query('SELECT COUNT(*) AS total, SUM(CASE WHEN submitted_at IS NOT NULL THEN 1 ELSE 0 END) AS finished, AVG(score) AS average_score FROM pretes_cbt_attempts WHERE registration_id < 0')->fetch(PDO::FETCH_ASSOC) ?: [];
$tryoutStats['total'] = (int)($tryoutStats['total'] ?? 0);
$tryoutStats['finished'] = (int)($tryoutStats['finished'] ?? 0);
$tryoutStats['average_score'] = $tryoutStats['average_score'] !== null ? (float)$tryoutStats['average_score'] : null;

$allStats = $pdo->query('SELECT COUNT(*) AS total FROM pretes_cbt_attempts')->fetch(PDO::FETCH_ASSOC) ?: [];
$totalAttemptCount = (int)($allStats['total'] ?? 0);

$resultFilter = isset($_GET['result_filter']) ? trim((string)$_GET['result_filter']) : 'all';
if (!in_array($resultFilter, ['all', 'resmi', 'tryout'], true)) {
    $resultFilter = 'all';
}

$whereClause = '';
if ($resultFilter === 'resmi') {
    $whereClause = 'WHERE a.registration_id > 0';
} elseif ($resultFilter === 'tryout') {
    $whereClause = 'WHERE a.registration_id < 0';
}

$now = time();
$ongoingCount = (int)($pdo->query("SELECT COUNT(*) FROM pretes_cbt_attempts WHERE submitted_at IS NULL AND deadline_at > $now")->fetchColumn() ?: 0);

$attempts = $pdo->query("SELECT a.*, u.nama_lengkap, u.nim, u.program_studi, u.jenis_program FROM pretes_cbt_attempts a JOIN users u ON u.id = a.user_id $whereClause ORDER BY a.started_at DESC LIMIT 100")->fetchAll(PDO::FETCH_ASSOC);

$attemptIds = array_map(fn($a) => (int)$a['id'], $attempts);
$itemStatsMap = [];
if (!empty($attemptIds)) {
    $placeholders = implode(',', array_fill(0, count($attemptIds), '?'));
    $stmtStats = $pdo->prepare("
        SELECT 
            attempt_id,
            COUNT(*) AS total_items,
            SUM(CASE WHEN answer IS NOT NULL AND answer != '' THEN 1 ELSE 0 END) AS answered_count,
            SUM(CASE WHEN flagged = 1 THEN 1 ELSE 0 END) AS flagged_count,
            SUM(CASE WHEN answer IS NOT NULL AND answer != '' AND answer = correct_answer THEN 1 ELSE 0 END) AS live_correct_count,
            SUM(CASE WHEN answer IS NOT NULL AND answer != '' AND answer != correct_answer THEN 1 ELSE 0 END) AS live_wrong_count
        FROM pretes_cbt_items
        WHERE attempt_id IN ($placeholders)
        GROUP BY attempt_id
    ");
    $stmtStats->execute($attemptIds);
    foreach ($stmtStats->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $itemStatsMap[(int)$row['attempt_id']] = $row;
    }
}
foreach ($attempts as &$att) {
    $attId = (int)$att['id'];
    $stat = $itemStatsMap[$attId] ?? null;
    $att['total_items'] = $stat ? (int)$stat['total_items'] : (int)($att['total_questions'] ?? 0);
    $att['answered_count'] = $stat ? (int)$stat['answered_count'] : 0;
    $att['flagged_count'] = $stat ? (int)$stat['flagged_count'] : 0;
    $att['live_correct_count'] = $stat ? (int)$stat['live_correct_count'] : 0;
    $att['live_wrong_count'] = $stat ? (int)$stat['live_wrong_count'] : 0;
}
unset($att);
if (!defined('EXTRA_HEAD')) {
    define('EXTRA_HEAD', '<link rel="stylesheet" href="' . BASE_URL . '/pretes-ujian.php?cbt_style=bank&amp;v=' . (file_exists(__DIR__ . '/../assets/css/pretes-cbt-admin.css') ? filemtime(__DIR__ . '/../assets/css/pretes-cbt-admin.css') : time()) . '">');
}
include __DIR__ . '/../includes/header.php';
require __DIR__ . '/../includes/pretes-cbt-admin-view.php';
include __DIR__ . '/../includes/footer.php';
