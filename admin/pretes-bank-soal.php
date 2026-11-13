<?php
define('PAGE_TITLE', 'Bank Soal & Hasil CBT Pretes');
require_once __DIR__ . '/../includes/auth.php';
requireAdmin();
require_once __DIR__ . '/../includes/pretes-cbt.php';
require_once __DIR__ . '/../includes/pretes-cbt-xlsx.php';
$bankUrl = BASE_URL . '/pretes-ujian.php?cbt_bank=1';
if (isset($_GET['download']) && $_GET['download'] === 'template') cbtSendXlsx(__DIR__ . '/../downloads/template-bank-soal-cbt.xlsx', 'template-bank-soal-cbt.xlsx');
$pdo = getDBConnection();
ensurePretesCbtTables($pdo);
$expired = $pdo->prepare('SELECT user_id, registration_id FROM pretes_cbt_attempts WHERE submitted_at IS NULL AND deadline_at <= ? LIMIT 100');
$expired->execute([time()]);
foreach ($expired->fetchAll(PDO::FETCH_ASSOC) as $entry) pretesCbtState($pdo, (int)$entry['user_id'], (int)$entry['registration_id']);
if (isset($_GET['download'])) {
    try {
        if ($_GET['download'] === 'bank') cbtSendXlsx(cbtWriteXlsx(cbtBankExportRows($pdo), 'Bank Soal', [24,22,60,28,28,28,28,28,12,16]), 'bank-soal-cbt-' . date('Ymd') . '.xlsx', true);
        elseif ($_GET['download'] === 'results') cbtSendXlsx(cbtWriteXlsx(cbtResultsExportRows($pdo), 'Hasil CBT', [20,34,24,24,18,18,16,14]), 'hasil-cbt-' . date('Ymd') . '.xlsx', true);
        throw new InvalidArgumentException('Jenis unduhan tidak dikenal.');
    } catch (Throwable $error) {
        error_log('CBT XLSX download: ' . $error->getMessage());
        http_response_code(503);
        header('Content-Type: text/plain; charset=utf-8');
        echo $error instanceof RuntimeException ? $error->getMessage() : 'Unduhan belum tersedia. Kembali ke bank soal dan coba lagi.';
        exit;
    }
}
$message = ''; $errors = []; $form = null;
$allowedTabs = ['bank','form','import','results'];
$activeTab = in_array($_GET['tab'] ?? '', $allowedTabs, true) ? $_GET['tab'] : 'bank';
$flash = $_SESSION['cbt_bank_flash'] ?? null;
unset($_SESSION['cbt_bank_flash']);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'save_question';
    $activeTab = $action === 'import_xlsx' ? 'import' : 'form';
    try {
        if (!is_string($_POST['csrf_token'] ?? null) || !verifyCsrf($_POST['csrf_token'])) {
            http_response_code(403);
            throw new InvalidArgumentException('Sesi tidak valid. Muat ulang halaman.');
        }
        if ($action === 'import_xlsx') {
            $file = $_FILES['question_file'] ?? null;
            if (!$file || $file['error'] !== UPLOAD_ERR_OK) throw new InvalidArgumentException('Pilih file .xlsx. Batas unggahan server: ' . ini_get('upload_max_filesize') . '.');
            if (strtolower(pathinfo($file['name'], PATHINFO_EXTENSION)) !== 'xlsx' || !is_uploaded_file($file['tmp_name'])) throw new InvalidArgumentException('Gunakan file Excel .xlsx dari template bank soal.');
            $result = cbtImportQuestions($pdo, cbtReadQuestionXlsx($file['tmp_name']), isset($_POST['update_existing']));
            if ($result['errors']) {
                http_response_code(422);
                $message = 'Impor dibatalkan. Tidak ada soal ditambahkan atau diperbarui. Perbaiki baris berikut dan unggah kembali.';
                $errors = $result['errors'];
            } else {
                $_SESSION['cbt_bank_flash'] = $result['added'] . ' soal ditambahkan, ' . $result['updated'] . ' diperbarui, ' . $result['skipped'] . ' duplikat/soal lama dilewati.';
                header('Location: ' . $bankUrl . '&tab=bank'); exit;
            }
        } elseif ($action === 'save_question') {
            pretesCbtSaveQuestion($pdo, $_POST);
            $_SESSION['cbt_bank_flash'] = 'Soal berhasil disimpan. Paket ujian yang sudah dimulai tidak berubah.';
            header('Location: ' . $bankUrl . '&tab=bank'); exit;
        } else throw new InvalidArgumentException('Aksi bank soal tidak valid.');
    } catch (InvalidArgumentException $error) {
        if (http_response_code() !== 403) http_response_code(422);
        $message = $error->getMessage();
        if ($action === 'save_question') $form = $_POST;
    } catch (Throwable $error) {
        error_log('CBT bank update: ' . $error->getMessage()); http_response_code(503);
        $message = 'Perubahan belum tersimpan. ' . ($error instanceof RuntimeException && !($error instanceof PDOException) ? $error->getMessage() : 'Coba lagi atau hubungi admin.');
        if ($action === 'save_question') $form = $_POST;
    }
}
if ($form === null && !empty($_GET['edit'])) {
    $stmt = $pdo->prepare('SELECT * FROM pretes_cbt_questions WHERE id = ?'); $stmt->execute([(int)$_GET['edit']]);
    $form = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    if ($form) { $activeTab = 'form'; foreach (json_decode($form['options_json'], true) as $letter => $text) $form['option_' . $letter] = $text; }
}
$form ??= ['is_active'=>1,'category'=>'Umum'];
$questions = $pdo->query('SELECT * FROM pretes_cbt_questions ORDER BY id DESC')->fetchAll(PDO::FETCH_ASSOC);
$active = count(array_filter($questions, static fn($question) => (bool)$question['is_active']));
$categories = array_values(array_unique(array_column($questions, 'category'))); sort($categories);
$stats = $pdo->query('SELECT COUNT(*) AS total, SUM(CASE WHEN submitted_at IS NOT NULL THEN 1 ELSE 0 END) AS finished, AVG(score) AS average_score FROM pretes_cbt_attempts')->fetch(PDO::FETCH_ASSOC);
$attempts = $pdo->query('SELECT a.*, u.nama_lengkap, u.nim FROM pretes_cbt_attempts a JOIN users u ON u.id = a.user_id ORDER BY a.started_at DESC LIMIT 100')->fetchAll(PDO::FETCH_ASSOC);
include __DIR__ . '/../includes/header.php';
require __DIR__ . '/../includes/pretes-cbt-admin-view.php';
include __DIR__ . '/../includes/footer.php';
