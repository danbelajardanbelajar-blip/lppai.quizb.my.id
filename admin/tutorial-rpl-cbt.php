<?php
/**
 * LPPAI Corner - Admin: Pengaturan Sesi & Paket Soal Ujian Tulis Tutorial RPL (CBT)
 * Arsitektur modern berparitas dengan CBT Pretes: 4 Tab (Paket Soal, Sesi Ujian, Impor Excel, Hasil Ujian).
 */
define('PAGE_TITLE', 'Paket Soal & Sesi CBT Tutorial RPL');
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/tutorial-rpl-helper.php';
require_once __DIR__ . '/../includes/pretes-cbt-xlsx.php';
require_once __DIR__ . '/../includes/tutorial-rpl-cbt-review.php';
requireAdmin();

$pdo = getDBConnection();
ensureTutorialRplTables($pdo);
ensureTutorialRplCbtTables($pdo);

// ── Penanganan Unduhan Berkas Excel ──────────────────────────────────────────
if (isset($_GET['download'])) {
    $dl = $_GET['download'];
    if ($dl === 'template') {
        $tplPath = __DIR__ . '/../downloads/template-bank-soal-cbt.xlsx';
        if (file_exists($tplPath)) {
            cbtSendXlsx($tplPath, 'template-bank-soal-tutorial-rpl.xlsx');
        } else {
            // Bangun template Excel secara on-the-fly
            $rows = [
                ['Kode Soal', 'Kategori', 'Pertanyaan', 'Pilihan A', 'Pilihan B', 'Pilihan C', 'Pilihan D', 'Pilihan E', 'Kunci', 'Status'],
                ['TUTOR-RPL-01', 'Thaharah', 'Rukun wudhu yang pertama sesuai syariat Islam adalah...', 'Niat', 'Membasuh muka', 'Membasuh kedua tangan', 'Mengusap kepala', 'Tertib', 'A', 'Aktif'],
                ['TUTOR-RPL-02', 'Sholat Wajib', 'Membaca Surat Al-Fatihah dalam setiap rakaat sholat fardhu termasuk...', 'Syarat sah sholat', 'Rukun sholat', 'Sunnah ab\'adh', 'Sunnah hai\'ah', 'Syarat wajib', 'B', 'Aktif']
            ];
            $gen = (function() use ($rows) { foreach ($rows as $r) yield $r; })();
            cbtSendXlsx(cbtWriteXlsx($gen, 'Template Bank Soal', [16, 20, 48, 24, 24, 24, 24, 24, 10, 12]), 'template-bank-soal-tutorial-rpl.xlsx', true);
        }
        exit;
    } elseif ($dl === 'bank') {
        $pkgId = isset($_GET['package_id']) && (int)$_GET['package_id'] > 0 ? (int)$_GET['package_id'] : null;
        $rows = tutorialRplCbtExportQuestions($pdo, $pkgId);
        $gen = (function() use ($rows) { foreach ($rows as $r) yield $r; })();
        $suffix = $pkgId ? '-paket-' . $pkgId : '';
        cbtSendXlsx(cbtWriteXlsx($gen, 'Bank Soal RPL', [16, 26, 20, 50, 24, 24, 24, 24, 24, 10, 12]), 'bank-soal-tutorial-rpl' . $suffix . '-' . date('Ymd') . '.xlsx', true);
        exit;
    } elseif ($dl === 'results') {
        $rows = tutorialRplCbtExportResults($pdo);
        $gen = (function() use ($rows) { foreach ($rows as $r) yield $r; })();
        cbtSendXlsx(cbtWriteXlsx($gen, 'Hasil CBT RPL', [14, 26, 22, 22, 22, 26, 24, 18, 18, 16, 14, 12, 12, 22]), 'hasil-cbt-tutorial-rpl-' . date('Ymd') . '.xlsx', true);
        exit;
    } elseif ($dl === 'results_pdf') {
        $filterSrc = $_GET;
        if (!isset($filterSrc['result_filter']) && isset($filterSrc['package_id']) && in_array((string)$filterSrc['package_id'], ['1', '2'], true)) {
            $filterSrc['result_filter'] = (string)$filterSrc['package_id'];
        }
        $cbtFilters = tutorialRplCbtResultFilters($filterSrc);
        $now = time();
        $passingGrade = 70.0;
        [$whereSql, $params] = tutorialRplCbtResultWhere($cbtFilters, $now, $passingGrade);

        $stmt = $pdo->prepare("
            SELECT a.*,
                   u.nama_lengkap, u.nim, u.program_studi,
                   c.nama_kelompok, c.dosen_nama,
                   s.title AS session_title, s.code AS session_code,
                   p.name AS package_name, p.code AS package_code
            FROM tutorial_rpl_cbt_attempts a
            JOIN users u ON a.user_id = u.id
            LEFT JOIN tutorial_rpl_classes c ON a.class_id = c.id
            LEFT JOIN tutorial_rpl_cbt_sessions s ON a.session_id = s.id
            LEFT JOIN tutorial_rpl_cbt_packages p ON a.package_id = p.id
            $whereSql
            ORDER BY a.started_at DESC
        ");
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v);
        }
        $stmt->execute();
        $attempts = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $attemptIds = array_map(fn($a) => (int)$a['id'], $attempts);
        $itemStatsMap = [];
        if (!empty($attemptIds)) {
            $placeholders = implode(',', array_fill(0, count($attemptIds), '?'));
            $stmtStats = $pdo->prepare("
                SELECT attempt_id,
                       COUNT(*) AS total_items,
                       SUM(CASE WHEN answer IS NOT NULL AND answer != '' THEN 1 ELSE 0 END) AS answered_count,
                       SUM(CASE WHEN answer IS NOT NULL AND answer != '' AND answer = correct_answer THEN 1 ELSE 0 END) AS live_correct_count
                FROM tutorial_rpl_cbt_items
                WHERE attempt_id IN ($placeholders)
                GROUP BY attempt_id
            ");
            $stmtStats->execute($attemptIds);
            foreach ($stmtStats->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $itemStatsMap[(int)$row['attempt_id']] = $row;
            }
        }

        $dtNow = new DateTimeImmutable('now', new DateTimeZone('Asia/Jakarta'));
        $indonesianMonths = [
            1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April',
            5 => 'Mei', 6 => 'Juni', 7 => 'Juli', 8 => 'Agustus',
            9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember'
        ];
        $printDateFormatted = $dtNow->format('j') . ' ' . $indonesianMonths[(int)$dtNow->format('n')] . ' ' . $dtNow->format('Y') . ', ' . $dtNow->format('H:i') . ' WIB';
        $signatureDate = $dtNow->format('j') . ' ' . $indonesianMonths[(int)$dtNow->format('n')] . ' ' . $dtNow->format('Y');

        $backUrl = '?tab=results&' . http_build_query(array_filter([
            'status_filter' => $cbtFilters['status_filter'],
            'result_filter' => $cbtFilters['result_filter'],
            'pass_filter' => $cbtFilters['pass_filter'],
            'group' => $cbtFilters['group'],
            'q' => $cbtFilters['q'],
        ], fn($v) => $v !== ''));

        $statusLabelMap = ['all' => 'Semua Sesi', 'live' => 'Sedang Berlangsung (Live)', 'finished' => 'Riwayat Selesai'];
        $pkgLabelMap = ['all' => 'Semua Paket', '1' => 'Paket 1 (Muslim)', '2' => 'Paket 2 (Kristen)'];
        $passLabelMap = ['all' => 'Semua Kelulusan', 'lulus' => 'Lulus (>= 70)', 'tidak_lulus' => 'Tidak Lulus (< 70)'];

        $totalLulus = 0;
        $totalTidakLulus = 0;
        $totalOngoing = 0;
        $sumScoreSubmitted = 0.0;
        $cntSubmitted = 0;
        foreach ($attempts as $a) {
            $isSub = ($a['submitted_at'] !== null);
            $deadline = (int)($a['deadline_at'] ?? ($a['started_at'] + 90 * 60));
            if ($isSub) {
                $cntSubmitted++;
                $sc = $a['score'] !== null ? (float)$a['score'] : 0.0;
                $sumScoreSubmitted += $sc;
                if ($sc >= $passingGrade) $totalLulus++; else $totalTidakLulus++;
            } elseif ($now < $deadline) {
                $totalOngoing++;
            }
        }
        $avgScore = $cntSubmitted > 0 ? round($sumScoreSubmitted / $cntSubmitted, 2) : 0;
        ?>
        <!DOCTYPE html>
        <html lang="id">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>Hasil Ujian CBT Tutorial RPL - LPPAI UNISDA</title>
            <link rel="icon" href="<?= BASE_URL ?>/assets/logo.svg" type="image/svg+xml">
            <style>
                @page { size: A4 landscape; margin: 10mm; }
                * { box-sizing: border-box; -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; }
                body { margin: 0; padding: 0; background: #e2e8f0; color: #0f172a; font-family: Arial, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; font-size: 9pt; line-height: 1.35; }
                .toolbar-screen { background: #ffffff; border-bottom: 1.5px solid #cbd5e1; padding: 12px 24px; position: sticky; top: 0; z-index: 1000; box-shadow: 0 2px 8px rgba(0,0,0,0.06); display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 12px; }
                .toolbar-screen .actions-left, .toolbar-screen .actions-right { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
                .btn-toolbar { display: inline-flex; align-items: center; gap: 6px; padding: 8px 16px; border-radius: 8px; font-size: 13px; font-weight: 700; text-decoration: none; cursor: pointer; border: none; }
                .btn-back { background: #f1f5f9; color: #334155; border: 1px solid #cbd5e1; }
                .btn-print { background: #dc2626; color: #ffffff; }
                .badge-info-stat { background: #eff6ff; color: #1e40af; border: 1px solid #bfdbfe; padding: 5px 12px; border-radius: 20px; font-size: 12px; font-weight: 700; }
                .page-sheet { width: 297mm; min-height: 210mm; padding: 12mm; margin: 16px auto; background: #ffffff; box-shadow: 0 4px 20px rgba(0,0,0,0.1); }
                .kop-surat { display: flex; align-items: center; gap: 14px; padding-bottom: 10px; border-bottom: 3px double #0f172a; margin-bottom: 12px; }
                .kop-logo { width: 62px; height: 62px; object-fit: contain; flex-shrink: 0; }
                .kop-text { flex: 1; text-align: center; }
                .kop-text h1 { margin: 0; font-size: 13pt; font-weight: 800; text-transform: uppercase; color: #0f172a; }
                .kop-text h2 { margin: 2px 0 0; font-size: 11pt; font-weight: 700; color: #1e293b; }
                .kop-text p { margin: 2px 0 0; font-size: 8.5pt; color: #475569; }
                .doc-heading { text-align: center; margin-bottom: 10px; }
                .doc-heading h3 { margin: 0; font-size: 11.5pt; font-weight: 800; text-transform: uppercase; text-decoration: underline; }
                .filter-summary { display: flex; flex-wrap: wrap; gap: 6px 18px; background: #f8fafc; border: 1px solid #cbd5e1; border-radius: 6px; padding: 8px 12px; margin-bottom: 12px; font-size: 8.5pt; }
                table.pdf-table { width: 100%; border-collapse: collapse; font-size: 8pt; }
                table.pdf-table th, table.pdf-table td { border: 1px solid #94a3b8; padding: 4px 6px; vertical-align: middle; }
                table.pdf-table th { background: #f1f5f9; color: #0f172a; font-weight: 700; text-align: center; }
                table.pdf-table tbody tr:nth-child(even) { background: #f8fafc; }
                .text-center { text-align: center; }
                .mono { font-family: monospace; }
                .st-pass { color: #065f46; background: #dcfce7; font-weight: 700; }
                .st-fail { color: #be123c; background: #fee2e2; font-weight: 700; }
                .st-live { color: #1e40af; background: #dbeafe; font-weight: 700; }
                .st-exp { color: #92400e; background: #fef3c7; font-weight: 700; }
                .doc-footer-area { display: flex; justify-content: space-between; align-items: flex-start; margin-top: 16px; gap: 20px; page-break-inside: avoid; }
                .rekap-box { border: 1px solid #cbd5e1; background: #f8fafc; border-radius: 6px; padding: 8px 12px; font-size: 8.5pt; line-height: 1.5; min-width: 280px; }
                .signature-box { text-align: center; min-width: 220px; font-size: 9pt; }
                .signature-space { height: 52px; }
                @media print {
                    body { background: #ffffff; }
                    .toolbar-screen { display: none !important; }
                    .page-sheet { width: 100%; min-height: auto; margin: 0; padding: 0; box-shadow: none; }
                }
            </style>
        </head>
        <body>
            <div class="toolbar-screen">
                <div class="actions-left">
                    <a href="<?= htmlspecialchars($backUrl, ENT_QUOTES, 'UTF-8') ?>" class="btn-toolbar btn-back">← Kembali ke Hasil Ujian</a>
                    <span class="badge-info-stat">Total: <?= count($attempts) ?> Peserta</span>
                </div>
                <div class="actions-right">
                    <button type="button" class="btn-toolbar btn-print" onclick="window.print()">📄 Simpan / Cetak PDF</button>
                </div>
            </div>

            <main class="page-sheet">
                <header class="kop-surat">
                    <img src="<?= BASE_URL ?>/assets/logo.svg" alt="Logo LPPAI" class="kop-logo" onerror="this.style.display='none'">
                    <div class="kop-text">
                        <h1>Lembaga Pengembangan Pendidikan Agama Islam (LPPAI)</h1>
                        <h2>Universitas Islam Darul 'Ulum Lamongan</h2>
                        <p>Dicetak pada: <?= htmlspecialchars($printDateFormatted, ENT_QUOTES, 'UTF-8') ?></p>
                    </div>
                </header>

                <div class="doc-heading">
                    <h3>REKAPITULASI HASIL UJIAN CBT TUTORIAL RPL</h3>
                </div>

                <div class="filter-summary">
                    <span>Status Sesi: <strong><?= htmlspecialchars($statusLabelMap[$cbtFilters['status_filter']] ?? 'Semua', ENT_QUOTES, 'UTF-8') ?></strong></span>
                    <span>Paket Soal: <strong><?= htmlspecialchars($pkgLabelMap[$cbtFilters['result_filter']] ?? 'Semua Paket', ENT_QUOTES, 'UTF-8') ?></strong></span>
                    <span>Kelulusan: <strong><?= htmlspecialchars($passLabelMap[$cbtFilters['pass_filter']] ?? 'Semua', ENT_QUOTES, 'UTF-8') ?></strong></span>
                    <span>Kelompok: <strong><?= htmlspecialchars($cbtFilters['group'] !== '' ? $cbtFilters['group'] : 'Semua Kelompok', ENT_QUOTES, 'UTF-8') ?></strong></span>
                    <?php if ($cbtFilters['q'] !== ''): ?>
                        <span>Kata Kunci: <strong><?= htmlspecialchars($cbtFilters['q'], ENT_QUOTES, 'UTF-8') ?></strong></span>
                    <?php endif; ?>
                </div>

                <table class="pdf-table">
                    <thead>
                        <tr>
                            <th width="26">No</th>
                            <th width="82">NIM</th>
                            <th>Nama Mahasiswa &amp; Prodi</th>
                            <th>Kelompok &amp; Dosen</th>
                            <th>Paket &amp; Sesi</th>
                            <th width="92">Waktu Mulai</th>
                            <th width="54">Terjawab</th>
                            <th width="44">Benar</th>
                            <th width="52">Nilai</th>
                            <th width="94">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($attempts)): ?>
                            <tr><td colspan="10" class="text-center" style="padding:14px;">Tidak ada data hasil ujian CBT Tutorial RPL yang cocok dengan filter aktif.</td></tr>
                        <?php else: ?>
                            <?php $no = 1; foreach ($attempts as $a):
                                $attId = (int)$a['id'];
                                $stat = $itemStatsMap[$attId] ?? null;
                                $totalItems = $stat ? (int)$stat['total_items'] : (int)($a['total_questions'] ?? 25);
                                $answered = $stat ? (int)$stat['answered_count'] : 0;
                                $isSubmitted = ($a['submitted_at'] !== null);
                                $deadline = (int)($a['deadline_at'] ?? ($a['started_at'] + 90 * 60));
                                $isExpired = (!$isSubmitted && $now >= $deadline);
                                $correct = $isSubmitted ? (int)($a['correct_count'] ?? 0) : ($stat ? (int)$stat['live_correct_count'] : 0);
                                $score = $isSubmitted ? ($a['score'] !== null ? (float)$a['score'] : 0.0) : ($totalItems > 0 ? round(($correct / $totalItems) * 100, 1) : 0.0);
                                $startedWib = (new DateTimeImmutable('@' . $a['started_at']))->setTimezone(new DateTimeZone('Asia/Jakarta'))->format('d/m/Y H:i');
                                $pkgId = (int)($a['package_id'] ?? 1);
                                $pkgDesc = !empty($a['package_name']) ? $a['package_name'] : (($pkgId === 2) ? 'Paket 2 (Kristen)' : 'Paket 1 (Muslim)');

                                if ($isSubmitted) {
                                    $isPass = ($score >= $passingGrade);
                                    $statusText = $isPass ? 'Lulus' : 'Tidak Lulus';
                                    $statusCls = $isPass ? 'st-pass' : 'st-fail';
                                } elseif ($isExpired) {
                                    $statusText = 'Waktu Habis';
                                    $statusCls = 'st-exp';
                                } else {
                                    $statusText = 'Berlangsung';
                                    $statusCls = 'st-live';
                                }
                            ?>
                            <tr>
                                <td class="text-center"><?= $no++ ?></td>
                                <td class="mono"><?= htmlspecialchars($a['nim'] ?: '-', ENT_QUOTES, 'UTF-8') ?></td>
                                <td>
                                    <strong><?= htmlspecialchars($a['nama_lengkap'] ?: '-', ENT_QUOTES, 'UTF-8') ?></strong><br>
                                    <span style="color:#475569;"><?= htmlspecialchars($a['program_studi'] ?: '-', ENT_QUOTES, 'UTF-8') ?></span>
                                </td>
                                <td>
                                    <strong><?= htmlspecialchars($a['nama_kelompok'] ?: '-', ENT_QUOTES, 'UTF-8') ?></strong><br>
                                    <span style="color:#475569;"><?= htmlspecialchars($a['dosen_nama'] ?: '-', ENT_QUOTES, 'UTF-8') ?></span>
                                </td>
                                <td>
                                    <strong><?= htmlspecialchars($pkgDesc, ENT_QUOTES, 'UTF-8') ?></strong><br>
                                    <span style="color:#475569;"><?= htmlspecialchars($a['session_title'] ?: ($a['session_code'] ?: '-'), ENT_QUOTES, 'UTF-8') ?></span>
                                </td>
                                <td class="text-center"><?= htmlspecialchars($startedWib, ENT_QUOTES, 'UTF-8') ?></td>
                                <td class="text-center"><?= $answered ?>/<?= $totalItems ?></td>
                                <td class="text-center"><?= $correct ?></td>
                                <td class="text-center"><strong><?= number_format($score, 1) ?></strong></td>
                                <td class="text-center <?= $statusCls ?>"><?= $statusText ?></td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>

                <div class="doc-footer-area">
                    <div class="rekap-box">
                        <strong>Ringkasan Hasil Ujian:</strong><br>
                        • Total Sesi Ditampilkan: <strong><?= count($attempts) ?></strong> peserta<br>
                        • Selesai &amp; Lulus (&ge; <?= (int)$passingGrade ?>): <strong><?= $totalLulus ?></strong> peserta<br>
                        • Selesai &amp; Tidak Lulus (&lt; <?= (int)$passingGrade ?>): <strong><?= $totalTidakLulus ?></strong> peserta<br>
                        • Sedang Berlangsung: <strong><?= $totalOngoing ?></strong> peserta<br>
                        • Rata-rata Nilai Selesai: <strong><?= $avgScore ?></strong>
                    </div>
                    <div class="signature-box">
                        Lamongan, <?= htmlspecialchars($signatureDate, ENT_QUOTES, 'UTF-8') ?><br>
                        <strong>Koordinator Tutorial RPL LPPAI</strong>
                        <div class="signature-space"></div>
                        <strong>( _______________________ )</strong>
                    </div>
                </div>
            </main>

            <?php if (isset($_GET['print']) && $_GET['print'] === '1'): ?>
            <script>
                window.addEventListener('load', function() {
                    setTimeout(function() { window.print(); }, 300);
                });
            </script>
            <?php endif; ?>
        </body>
        </html>
        <?php
        exit;
    }
}

// ── Endpoint AJAX Responsif ──────────────────────────────────────────────────
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json; charset=utf-8');
    if ($_GET['ajax'] === 'package_questions') {
        $pkgId = (int)($_GET['package_id'] ?? 0);
        $pkg = $pkgId > 0 ? tutorialRplCbtGetPackage($pdo, $pkgId) : null;
        $qList = tutorialRplCbtGetQuestions($pdo, null, $pkgId > 0 ? $pkgId : null);
        foreach ($qList as &$qItem) {
            $qItem['options'] = json_decode((string)$qItem['options_json'], true) ?: [];
        }
        unset($qItem);
        echo json_encode([
            'success' => true,
            'package' => $pkg,
            'questions' => $qList
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($_GET['ajax'] === 'generate_token') {
        $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $newToken = '';
        for ($i = 0; $i < 6; $i++) {
            $newToken .= $chars[random_int(0, strlen($chars) - 1)];
        }
        echo json_encode(['token' => $newToken]);
        exit;
    }
    if ($_GET['ajax'] === 'get_question') {
        $qId = (int)($_GET['id'] ?? 0);
        $q = tutorialRplCbtGetQuestion($pdo, $qId);
        if ($q) {
            $q['options'] = json_decode((string)$q['options_json'], true) ?: [];
            echo json_encode(['success' => true, 'question' => $q]);
        } else {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Soal tidak ditemukan.']);
        }
        exit;
    }
    if ($_GET['ajax'] === 'live_results') {
        try {
            $cbtFilters = tutorialRplCbtResultFilters($_GET);
            $statusFilter = $cbtFilters['status_filter'];
            $filter = $cbtFilters['result_filter'];
            $searchQuery = $cbtFilters['q'];
            $groupFilter = $cbtFilters['group'];
            $page = $cbtFilters['page'];
            $perPage = $cbtFilters['per_page'];

            $now = time();
            $passingGrade = 70.0;

            [$whereSql, $params] = tutorialRplCbtResultWhere($cbtFilters, $now, $passingGrade);

            $countStmt = $pdo->prepare("SELECT COUNT(*) FROM tutorial_rpl_cbt_attempts a JOIN users u ON u.id = a.user_id LEFT JOIN tutorial_rpl_classes c ON c.id = a.class_id $whereSql");
            foreach ($params as $k => $v) {
                $countStmt->bindValue($k, $v);
            }
            $countStmt->execute();
            $totalFiltered = (int)$countStmt->fetchColumn();

            $totalPages = max(1, (int)ceil($totalFiltered / $perPage));
            if ($page > $totalPages) {
                $page = $totalPages;
            }
            $offset = ($page - 1) * $perPage;

            $stmt = $pdo->prepare("
                SELECT a.*,
                       u.nama_lengkap, u.nim, u.program_studi,
                       c.nama_kelompok, c.dosen_nama,
                       s.title AS session_title, s.code AS session_code,
                       p.name AS package_name, p.code AS package_code
                FROM tutorial_rpl_cbt_attempts a
                JOIN users u ON a.user_id = u.id
                LEFT JOIN tutorial_rpl_classes c ON a.class_id = c.id
                LEFT JOIN tutorial_rpl_cbt_sessions s ON a.session_id = s.id
                LEFT JOIN tutorial_rpl_cbt_packages p ON a.package_id = p.id
                $whereSql
                ORDER BY a.started_at DESC
                LIMIT $perPage OFFSET $offset
            ");
            foreach ($params as $k => $v) {
                $stmt->bindValue($k, $v);
            }
            $stmt->execute();
            $attempts = $stmt->fetchAll(PDO::FETCH_ASSOC);

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
                    FROM tutorial_rpl_cbt_items
                    WHERE attempt_id IN ($placeholders)
                    GROUP BY attempt_id
                ");
                $stmtStats->execute($attemptIds);
                foreach ($stmtStats->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    $itemStatsMap[(int)$row['attempt_id']] = $row;
                }
            }

            $data = [];
            foreach ($attempts as $a) {
                $attId = (int)$a['id'];
                $stat = $itemStatsMap[$attId] ?? null;
                $totalItems = $stat ? (int)$stat['total_items'] : (int)($a['total_questions'] ?? 25);
                $answered = $stat ? (int)$stat['answered_count'] : 0;
                $flagged = $stat ? (int)$stat['flagged_count'] : 0;
                $unanswered = max(0, $totalItems - $answered);
                $isSubmitted = ($a['submitted_at'] !== null);
                $deadline = (int)($a['deadline_at'] ?? ($a['started_at'] + 90 * 60));
                $isExpired = (!$isSubmitted && $now >= $deadline);
                $isOngoing = (!$isSubmitted && !$isExpired);
                $remainingSeconds = max(0, $deadline - $now);

                $correct = $isSubmitted ? (int)($a['correct_count'] ?? 0) : ($stat ? (int)$stat['live_correct_count'] : 0);
                $wrong = $isSubmitted ? max(0, $totalItems - $correct) : ($stat ? (int)$stat['live_wrong_count'] : 0);
                $score = $isSubmitted ? ($a['score'] !== null ? (float)$a['score'] : null) : ($totalItems > 0 ? round(($correct / $totalItems) * 100, 1) : 0.0);

                $durationSeconds = $isSubmitted ? max(0, (int)$a['submitted_at'] - (int)$a['started_at']) : 0;
                $startedWib = (new DateTimeImmutable('@' . $a['started_at']))->setTimezone(new DateTimeZone('Asia/Jakarta'))->format('d/m/Y H:i');

                $pkgId = (int)($a['package_id'] ?? 1);
                $pkgDesc = ($pkgId === 2) ? 'Paket 2 (Kristen)' : 'Paket 1 (Muslim)';

                $data[] = [
                    'id' => $attId,
                    'user_id' => (int)$a['user_id'],
                    'class_id' => (int)$a['class_id'],
                    'nama_lengkap' => $a['nama_lengkap'],
                    'nim' => $a['nim'] ?? '-',
                    'program_studi' => $a['program_studi'] ?? '',
                    'nama_kelompok' => $a['nama_kelompok'] ?? '-',
                    'dosen_nama' => $a['dosen_nama'] ?? '-',
                    'package_id' => $pkgId,
                    'package_desc' => $pkgDesc,
                    'session_title' => $a['session_title'] ?? ($pkgId === 2 ? 'Sesi Kristen' : 'Sesi Utama'),
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

            $totalAll = (int)($pdo->query('SELECT COUNT(*) FROM tutorial_rpl_cbt_attempts')->fetchColumn() ?: 0);
            $totalPkg1 = (int)($pdo->query('SELECT COUNT(*) FROM tutorial_rpl_cbt_attempts WHERE package_id = 1')->fetchColumn() ?: 0);
            $totalPkg2 = (int)($pdo->query('SELECT COUNT(*) FROM tutorial_rpl_cbt_attempts WHERE package_id = 2')->fetchColumn() ?: 0);
            $ongoingCount = (int)($pdo->query("SELECT COUNT(*) FROM tutorial_rpl_cbt_attempts WHERE submitted_at IS NULL AND deadline_at > $now")->fetchColumn() ?: 0);
            $finishedCount = (int)($pdo->query("SELECT COUNT(*) FROM tutorial_rpl_cbt_attempts WHERE submitted_at IS NOT NULL OR deadline_at <= $now")->fetchColumn() ?: 0);

            echo json_encode([
                'success' => true,
                'timestamp' => $now,
                'timestamp_wib' => (new DateTimeImmutable('@' . $now))->setTimezone(new DateTimeZone('Asia/Jakarta'))->format('H:i:s') . ' WIB',
                'ongoing_count' => $ongoingCount,
                'finished_count' => $finishedCount,
                'total_all' => $totalAll,
                'total_pkg1' => $totalPkg1,
                'total_pkg2' => $totalPkg2,
                'total_filtered' => $totalFiltered,
                'page' => $page,
                'per_page' => $perPage,
                'total_pages' => $totalPages,
                'status_filter' => $statusFilter,
                'result_filter' => $filter,
                'group' => $cbtFilters['group'],
                'pass_filter' => $cbtFilters['pass_filter'],
                'pass_counts' => tutorialRplCbtResultPassCounts($pdo, $cbtFilters, $now, $passingGrade),
                'data' => $data
            ], JSON_UNESCAPED_UNICODE);
        } catch (Throwable $e) {
            echo json_encode([
                'success' => false,
                'message' => $e->getMessage(),
                'data' => []
            ], JSON_UNESCAPED_UNICODE);
        }
        exit;
    }
}

$message = '';
$msgType = '';

$allowedTabs = ['packages', 'sessions', 'import', 'results'];
$activeTab = $_GET['tab'] ?? 'packages';
if ($activeTab === 'questions') {
    $activeTab = 'packages';
} elseif ($activeTab === 'session') {
    $activeTab = 'sessions';
} elseif ($activeTab === 'attempts') {
    $activeTab = 'results';
}
if (!in_array($activeTab, $allowedTabs, true)) {
    $activeTab = 'packages';
}

// ── Handle POST Actions ──────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';
    if (in_array($action,['delete_package','delete_session','delete_sessions'],true)
        && (!is_string($_POST['csrf_token'] ?? null) || !verifyCsrf($_POST['csrf_token']))) {
        http_response_code(403);
        $message='Sesi formulir tidak valid. Muat ulang halaman sebelum menghapus.';
        $msgType='danger';
        $action='';
    }

    try {
    if ($action === 'save_package') {
        $pkgId = (int)($_POST['package_id'] ?? 0);
        $code = trim($_POST['code'] ?? '');
        $name = trim($_POST['name'] ?? '');
        $desc = trim($_POST['description'] ?? '');
        $duration = (int)($_POST['duration_minutes'] ?? 90);
        $questionCount = (int)($_POST['question_count'] ?? 25);
        $isActive = isset($_POST['is_active']) ? 1 : 0;

        if ($name === '') {
            $message = 'Nama paket soal wajib diisi.';
            $msgType = 'danger';
        } else {
            try {
                tutorialRplCbtSavePackage($pdo, [
                    'id' => $pkgId,
                    'code' => $code,
                    'name' => $name,
                    'description' => $desc,
                    'duration_minutes' => $duration,
                    'question_count' => $questionCount,
                    'is_active' => $isActive
                ]);
                $message = ($pkgId > 0) ? 'Paket soal berhasil diperbarui.' : 'Paket soal baru berhasil dibuat.';
                $msgType = 'success';
            } catch (Exception $e) {
                $message = 'Error: ' . $e->getMessage();
                $msgType = 'danger';
            }
        }
        $activeTab = 'packages';
    } elseif ($action === 'activate_package') {
        $pkgId = (int)($_POST['package_id'] ?? 0);
        if ($pkgId > 0 && tutorialRplCbtActivatePackage($pdo, $pkgId)) {
            $message = "Paket soal #{$pkgId} berhasil diaktifkan.";
            $msgType = 'success';
        }
        $activeTab = 'packages';
    } elseif ($action === 'delete_package') {
        $pkgId = (int)($_POST['package_id'] ?? 0);
        if ($pkgId > 0 && tutorialRplCbtDeletePackage($pdo, $pkgId)) {
            $message = "Paket soal #{$pkgId} berhasil dihapus.";
            $msgType = 'success';
        } else {
            $message = 'Paket soal tidak ditemukan atau sudah dihapus.';
            $msgType = 'danger';
        }
        $activeTab = 'packages';
    } elseif ($action === 'save_session' || $action === 'update_session') {
        try {
            $sesId = tutorialRplCbtSaveSession($pdo, $_POST);
            $message = 'Sesi ujian berhasil disimpan.';
            $msgType = 'success';
        } catch (Exception $e) {
            $message = 'Error: ' . $e->getMessage();
            $msgType = 'danger';
        }
        $activeTab = 'sessions';
    } elseif ($action === 'set_exam_open') {
        $sesId = (int)($_POST['session_id'] ?? 0);
        $open = (int)($_POST['open'] ?? 0) === 1;
        if ($sesId > 0 && tutorialRplCbtSetExamOpen($pdo, $sesId, $open)) {
            $message = $open
                ? '🟢 Pengerjaan ujian CBT Tutorial RPL DIBUKA. Peserta dapat memulai ujian.'
                : '🔴 Pengerjaan ujian CBT Tutorial RPL DITUTUP. Peserta tidak dapat memulai ujian baru.';
            $msgType = 'success';
        } else {
            $message = 'Gagal mengubah status gerbang pengerjaan ujian.';
            $msgType = 'danger';
        }
        $activeTab = 'sessions';
    } elseif ($action === 'activate_session') {
        $sesId = (int)($_POST['session_id'] ?? 0);
        if ($sesId > 0 && tutorialRplCbtActivateSession($pdo, $sesId, false)) {
            $message = 'Sesi ujian berhasil diaktifkan.';
            $msgType = 'success';
        } else {
            $message = 'Gagal mengaktifkan sesi ujian.';
            $msgType = 'danger';
        }
        $activeTab = 'sessions';
    } elseif ($action === 'deactivate_session') {
        $sesId = (int)($_POST['session_id'] ?? 0);
        if ($sesId > 0 && tutorialRplCbtDeactivateSession($pdo, $sesId)) {
            $message = 'Sesi ujian berhasil dinonaktifkan.';
            $msgType = 'success';
        } else {
            $message = 'Gagal menonaktifkan sesi ujian. Minimal harus ada 1 sesi ujian yang aktif.';
            $msgType = 'danger';
        }
        $activeTab = 'sessions';
    } elseif ($action === 'delete_session') {
        $sesId = (int)($_POST['session_id'] ?? 0);
        if ($sesId <= 0) {
            $message = 'ID sesi ujian tidak valid.';
            $msgType = 'danger';
        } elseif (tutorialRplCbtDeleteSession($pdo, $sesId)) {
            $message = "Sesi ujian #{$sesId} berhasil dihapus.";
            $msgType = 'success';
        } else {
            $message = "Gagal menghapus sesi ujian #{$sesId}. Sesi mungkin sudah dihapus atau tidak ditemukan.";
            $msgType = 'danger';
        }
        $activeTab = 'sessions';
    } elseif ($action === 'delete_sessions') {
        $rawIds = $_POST['session_ids'] ?? [];
        if (!is_array($rawIds) || empty($rawIds)) {
            $message = 'Pilih minimal satu sesi ujian yang ingin dihapus.';
            $msgType = 'danger';
        } else {
            $deleted = tutorialRplCbtDeleteSessions($pdo, $rawIds);
            if ($deleted > 0) {
                $message = "{$deleted} sesi ujian berhasil dihapus. Riwayat pengerjaan peserta tetap tersimpan.";
                $msgType = 'success';
            } else {
                $message = 'Tidak ada sesi terpilih yang dihapus.';
                $msgType = 'danger';
            }
        }
        $activeTab = 'sessions';
    } elseif ($action === 'toggle_exam') {
        $sesId = (int)($_POST['session_id'] ?? 1);
        $sessionToToggle = tutorialRplCbtGetSession($pdo, $sesId);
        $newStatus = empty($sessionToToggle['exam_open']) ? 1 : 0;
        tutorialRplCbtSetExamOpen($pdo, $sesId, $newStatus === 1);
        $statusLabel = $newStatus ? '🟢 DIBUKA' : '🔴 DITUTUP';
        $message = "Gerbang {$sessionToToggle['title']} berhasil {$statusLabel}.";
        $msgType = 'success';
        $activeTab = 'sessions';
        $activeTab = 'sessions';
    } elseif ($action === 'save_question') {
        $qId = (int)($_POST['question_id'] ?? 0);
        $packageId = max(1, (int)($_POST['package_id'] ?? 1));
        $category = trim($_POST['category'] ?? 'Umum');
        $questionText = trim($_POST['question_text'] ?? '');
        $options = [
            trim($_POST['opt_a'] ?? ''),
            trim($_POST['opt_b'] ?? ''),
            trim($_POST['opt_c'] ?? ''),
            trim($_POST['opt_d'] ?? ''),
            trim($_POST['opt_e'] ?? '')
        ];
        $correct = strtoupper(trim($_POST['correct_answer'] ?? 'A'));

        if ($questionText === '' || $options[0] === '' || $options[1] === '') {
            $message = 'Teks pertanyaan dan pilihan minimal A & B wajib diisi.';
            $msgType = 'danger';
        } else {
            try {
                tutorialRplCbtSaveQuestion($pdo, [
                    'id' => $qId,
                    'package_id' => $packageId,
                    'category' => $category,
                    'question_text' => $questionText,
                    'options' => $options,
                    'correct_answer' => $correct
                ]);
                $message = ($qId > 0) ? 'Butir soal berhasil diperbarui.' : 'Butir soal baru berhasil ditambahkan ke paket soal.';
                $msgType = 'success';
            } catch (Exception $e) {
                $message = 'Error: ' . $e->getMessage();
                $msgType = 'danger';
            }
        }
        $activeTab = 'packages';
    } elseif ($action === 'delete_question') {
        $qId = (int)($_POST['question_id'] ?? 0);
        $ok = ($qId > 0 && tutorialRplCbtDeleteQuestion($pdo, $qId));
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success' => $ok, 'message' => $ok ? 'Butir soal berhasil dihapus.' : 'Gagal menghapus butir soal.']);
            exit;
        }
        if ($ok) {
            $message = 'Butir soal berhasil dihapus dari paket soal.';
            $msgType = 'success';
        } else {
            $message = 'Gagal menghapus butir soal.';
            $msgType = 'danger';
        }
        $activeTab = 'packages';
    } elseif ($action === 'reset_default_questions' || $action === 'reset_pretes_parity_packages') {
        $res = tutorialRplCbtSeedPackagesAndQuestions($pdo, true);
        $message = "Paket soal dan sesi CBT Tutorial RPL berhasil disinkronkan & ditindih dengan paket soal CBT Pretes ({$res['total_questions']} butir soal: {$res['questions_islam']} Muslim + {$res['questions_kristen']} Kristen Protestan).";
        $msgType = 'success';
        $activeTab = 'packages';
    } elseif ($action === 'import_xlsx') {
        $activeTab = 'import';
        $packageId = max(1, (int)($_POST['package_id'] ?? 1));
        $file = $_FILES['question_file'] ?? null;
        if (!$file || $file['error'] !== UPLOAD_ERR_OK) {
            $message = 'Gagal mengunggah file. Pastikan ukuran file tidak melebihi batas server.';
            $msgType = 'danger';
        } elseif (strtolower(pathinfo($file['name'], PATHINFO_EXTENSION)) !== 'xlsx' || !is_uploaded_file($file['tmp_name'])) {
            $message = 'Gunakan berkas format Excel (.xlsx) sesuai template bank soal.';
            $msgType = 'danger';
        } else {
            try {
                $rows = cbtReadQuestionXlsx($file['tmp_name']);
                $updateExisting = isset($_POST['update_existing']);
                $res = tutorialRplCbtImportQuestions($pdo, $rows, $updateExisting, $packageId);
                if (!empty($res['errors'])) {
                    $message = "Impor selesai dengan catatan: {$res['added']} ditambah, {$res['updated']} diperbarui. Terdapat " . count($res['errors']) . " baris bermasalah.";
                    $msgType = 'warning';
                } else {
                    $message = "Sukses mengimpor bank soal ke Paket {$packageId}: {$res['added']} butir soal baru ditambahkan, {$res['updated']} diperbarui.";
                    $msgType = 'success';
                    $activeTab = 'packages';
                }
            } catch (Exception $e) {
                $message = 'Error impor Excel: ' . $e->getMessage();
                $msgType = 'danger';
            }
        }
    } elseif ($action === 'delete_attempt') {
        $attemptId = (int)($_POST['attempt_id'] ?? 0);
        if ($attemptId > 0 && tutorialRplCbtDeleteAttempt($pdo, $attemptId)) {
            $message = 'Sesi ujian peserta berhasil dihapus. Peserta kini dapat mengulang ujian kembali.';
            $msgType = 'success';
        } else {
            $message = 'Gagal menghapus sesi ujian peserta.';
            $msgType = 'danger';
        }
        $activeTab = 'results';
    } elseif ($action === 'delete_all_attempts') {
        $scope = trim((string)($_POST['delete_scope'] ?? 'all'));
        $deletedCount = tutorialRplCbtDeleteAllAttempts($pdo, $scope);
        $message = "Berhasil menghapus {$deletedCount} data sesi ujian peserta.";
        $msgType = 'success';
        $activeTab = 'results';
    } elseif ($action === 'reset_attempt') {
        $userId = (int)($_POST['user_id'] ?? 0);
        $classId = (int)($_POST['class_id'] ?? 0);
        $attId = (int)($_POST['attempt_id'] ?? 0);
        if ($attId > 0 && tutorialRplCbtDeleteAttempt($pdo, $attId)) {
            $message = 'Attempt ujian peserta berhasil di-reset. Peserta kini dapat mengulang ujian kembali.';
            $msgType = 'success';
        } elseif ($userId > 0 && $classId > 0 && tutorialRplCbtResetAttempt($pdo, $userId, $classId)) {
            $message = 'Attempt ujian peserta berhasil di-reset. Peserta kini dapat mengulang ujian kembali.';
            $msgType = 'success';
        } else {
            $message = 'Gagal me-reset attempt ujian peserta.';
            $msgType = 'danger';
        }
        $activeTab = 'results';
    }
    } catch (Throwable $e) {
        error_log('Tutorial RPL CBT action: '.$e->getMessage());
        $message='Perubahan belum dapat disimpan. Muat ulang halaman; jika berulang hubungi pengelola server.';
        $msgType='danger';
    }
}

$packages = tutorialRplCbtGetPackages($pdo);
$sessions = tutorialRplCbtGetSessions($pdo);
$session = tutorialRplCbtGetSession($pdo, 1);
$sessionKristen = tutorialRplCbtGetSession($pdo, 2);

$selectedPackageId = (isset($_GET['package_id']) && $_GET['package_id'] !== '') ? (int)$_GET['package_id'] : null;
$questions = tutorialRplCbtGetQuestions($pdo, null, $selectedPackageId);
// Kategori unik untuk filter
$categories = [];
foreach ($questions as $q) {
    if (!empty($q['category']) && !in_array($q['category'], $categories, true)) {
        $categories[] = $q['category'];
    }
}
sort($categories);

// Pengelompokan kategori dan hitungan soal per kategori per paket untuk modal sesi
$packageCategoriesMap = [];
foreach ($packages as $pkg) {
    try {
        $stmt = $pdo->prepare("SELECT category, COUNT(*) as total, SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) as active_count FROM tutorial_rpl_cbt_questions WHERE package_id = ? GROUP BY category ORDER BY category ASC");
        $stmt->execute([(int)$pkg['id']]);
        $packageCategoriesMap[(int)$pkg['id']] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $packageCategoriesMap[(int)$pkg['id']] = [];
    }
}

// Statistik Umum CBT
$passingGrade = (float)($session['passing_grade'] ?? 70.0);
$now = time();

// Default values untuk metrik dan tab results
$totalAllAttempts = 0;
$totalAttempts = 0;
$totalPkg1Attempts = 0;
$totalPkg2Attempts = 0;
$ongoingCount = 0;
$finishedCount = 0;
$totalFiltered = 0;
$totalPages = 1;
$attempts = [];
$itemStatsMap = [];
$groupOptions = [];
$passCounts = ['lulus' => 0, 'tidak_lulus' => 0];
$passCount = 0;
$avgScore = 0.0;
$passPercent = 0.0;

try {
    $totalAllAttempts = (int)($pdo->query('SELECT COUNT(*) FROM tutorial_rpl_cbt_attempts')->fetchColumn() ?: 0);
    $totalAttempts = $totalAllAttempts;
    $allSubmitted = $pdo->query("SELECT COUNT(*) as cnt, SUM(CASE WHEN score >= $passingGrade THEN 1 ELSE 0 END) as passed, AVG(score) as avg_sc FROM tutorial_rpl_cbt_attempts WHERE submitted_at IS NOT NULL")->fetch(PDO::FETCH_ASSOC);
    $passCount = (int)($allSubmitted['passed'] ?? 0);
    $avgScore = ($allSubmitted && $allSubmitted['avg_sc'] !== null) ? round((float)$allSubmitted['avg_sc'], 1) : 0.0;
    $passPercent = (!empty($allSubmitted['cnt'])) ? round(($passCount / (int)$allSubmitted['cnt']) * 100, 1) : 0.0;
} catch (Throwable $e) {
    // Fallback safe defaults jika tabel attempts belum memiliki data
}

$cbtFilters = tutorialRplCbtResultFilters($_GET);
$statusFilter = $cbtFilters['status_filter'];
$resultFilter = $cbtFilters['result_filter'];
$searchQuery = $cbtFilters['q'];
$groupFilter = $cbtFilters['group'];
$passFilter = $cbtFilters['pass_filter'];
$page = $cbtFilters['page'];
$perPage = $cbtFilters['per_page'];

// Query data detail Hasil Ujian HANYA dijalankan saat tab results aktif untuk performa & stabilitas maksimal
if ($activeTab === 'results') {
    try {
        $totalPkg1Attempts = (int)($pdo->query('SELECT COUNT(*) FROM tutorial_rpl_cbt_attempts WHERE package_id = 1')->fetchColumn() ?: 0);
        $totalPkg2Attempts = (int)($pdo->query('SELECT COUNT(*) FROM tutorial_rpl_cbt_attempts WHERE package_id = 2')->fetchColumn() ?: 0);
        $ongoingCount = (int)($pdo->query("SELECT COUNT(*) FROM tutorial_rpl_cbt_attempts WHERE submitted_at IS NULL AND deadline_at > $now")->fetchColumn() ?: 0);
        $finishedCount = (int)($pdo->query("SELECT COUNT(*) FROM tutorial_rpl_cbt_attempts WHERE submitted_at IS NOT NULL OR deadline_at <= $now")->fetchColumn() ?: 0);

        [$whereClause, $params] = tutorialRplCbtResultWhere($cbtFilters, $now, $passingGrade);
        $passCounts = tutorialRplCbtResultPassCounts($pdo, $cbtFilters, $now, $passingGrade);

        $countStmt = $pdo->prepare("SELECT COUNT(*) FROM tutorial_rpl_cbt_attempts a JOIN users u ON u.id = a.user_id LEFT JOIN tutorial_rpl_classes c ON c.id = a.class_id $whereClause");
        foreach ($params as $k => $v) {
            $countStmt->bindValue($k, $v);
        }
        $countStmt->execute();
        $totalFiltered = (int)$countStmt->fetchColumn();

        $totalPages = max(1, (int)ceil($totalFiltered / $perPage));
        if ($page > $totalPages) {
            $page = $totalPages;
        }
        $offset = ($page - 1) * $perPage;

        $stmt = $pdo->prepare("
            SELECT a.*,
                   u.nama_lengkap, u.nim, u.program_studi,
                   c.nama_kelompok, c.dosen_nama,
                   s.title AS session_title, s.code AS session_code,
                   p.name AS package_name, p.code AS package_code
            FROM tutorial_rpl_cbt_attempts a
            JOIN users u ON a.user_id = u.id
            LEFT JOIN tutorial_rpl_classes c ON a.class_id = c.id
            LEFT JOIN tutorial_rpl_cbt_sessions s ON a.session_id = s.id
            LEFT JOIN tutorial_rpl_cbt_packages p ON a.package_id = p.id
            $whereClause
            ORDER BY a.started_at DESC
            LIMIT $perPage OFFSET $offset
        ");
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v);
        }
        $stmt->execute();
        $attempts = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $attemptIds = array_map(fn($a) => (int)$a['id'], $attempts);
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
                FROM tutorial_rpl_cbt_items
                WHERE attempt_id IN ($placeholders)
                GROUP BY attempt_id
            ");
            $stmtStats->execute($attemptIds);
            foreach ($stmtStats->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $itemStatsMap[(int)$row['attempt_id']] = $row;
            }
        }

        $groupOptions = $pdo->query("SELECT DISTINCT nama_kelompok FROM tutorial_rpl_classes ORDER BY nama_kelompok ASC")->fetchAll(PDO::FETCH_COLUMN) ?: [];
    } catch (Throwable $e) {
        $attempts = [];
        $totalFiltered = 0;
    }
}

include __DIR__ . '/../includes/header.php';
?>

<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/pretes-cbt-admin.css?v=<?= filemtime(__DIR__ . '/../assets/css/pretes-cbt-admin.css') ?>">

<div class="bank-app">
    <!-- Hero Header -->
    <div class="bank-hero" style="margin-bottom:20px;">
        <div>
            <div class="bank-eyebrow">LPPAI / CBT TUTORIAL RPL</div>
            <h2>🧩 Paket Soal &amp; Sesi Ujian Tulis Tutorial RPL</h2>
            <p>Kelola paket materi soal, sesi ujian 90 menit (passing grade <?= $passingGrade ?>), impor Excel, serta pantau hasil ujian mahasiswa secara real-time.</p>
        </div>
        <div class="bank-actions">
            <button type="button" class="bank-button primary" onclick="openAddModal();">
                ➕ Tambah Soal
            </button>
            <a href="?download=template" class="bank-button ghost">
                📥 Unduh Template (.xlsx)
            </a>
            <a href="?download=bank" class="bank-button ghost">
                📤 Ekspor Paket Soal (.xlsx)
            </a>
            <a href="?download=results" class="bank-button ghost">
                📊 Ekspor Hasil Ujian (.xlsx)
            </a>
            <a data-no-spa href="?download=results_pdf&amp;print=1" target="_blank" rel="noopener" class="bank-button ghost">
                📄 Export PDF
            </a>
        </div>
    </div>

    <?php if ($message): ?>
        <div class="bank-notice <?= $msgType === 'danger' ? 'error' : ($msgType === 'warning' ? 'warning' : '') ?>" style="margin-bottom:20px;">
            <?= ($msgType === 'success' ? '✅ ' : '⚠️ ') . sanitize($message) ?>
        </div>
    <?php endif; ?>

    <!-- 4 Quick Metric Cards -->
    <div class="bank-stats">
        <div class="bank-stat">
            <small>Status Gerbang &amp; Token</small>
            <strong style="color:<?= !empty($session['exam_open']) ? '#15803d' : '#b91c1c' ?>;">
                <?= !empty($session['exam_open']) ? '🟢 BUKA' : '🔴 TUTUP' ?>
            </strong>
            <span>Token: <span class="bank-badge token-badge"><?= sanitize($session['token'] ?? 'TUTOR26') ?></span></span>
        </div>
        <div class="bank-stat">
            <small>Total Butir Soal</small>
            <strong style="color:#0f766e;"><?= count($questions) ?> Butir</strong>
            <span><?= count($packages) ?> Paket Soal · <?= count($categories) ?> Kategori</span>
        </div>
        <div class="bank-stat">
            <small>Durasi &amp; Standar Kelulusan</small>
            <strong style="color:#0369a1;"><?= (int)$session['duration_minutes'] ?> Menit</strong>
            <span>Passing Grade: <strong><?= $passingGrade ?></strong></span>
        </div>
        <div class="bank-stat">
            <small>Peserta Selesai &amp; Rata-rata</small>
            <strong style="color:#7c3aed;"><?= $totalAttempts ?> Mahasiswa</strong>
            <span>Lulus: <?= $passCount ?> (<?= $passPercent ?>%) · Rerata: <?= $avgScore ?></span>
        </div>
    </div>

    <!-- 4 Modern Tabs (Berparitas 100% dengan CBT Pretes) -->
    <div class="bank-tabs">
        <a href="?tab=packages" class="<?= $activeTab === 'packages' ? 'active' : '' ?>">
            📦 Paket Soal <span class="bank-tab-count"><?= count($packages) ?></span>
        </a>
        <a href="?tab=sessions" class="<?= $activeTab === 'sessions' ? 'active' : '' ?>">
            ⚙️ Sesi Ujian <span class="bank-tab-count"><?= count($sessions) ?></span>
        </a>
        <a href="?tab=import" class="<?= $activeTab === 'import' ? 'active' : '' ?>">
            📥 Impor Excel
        </a>
        <a href="?tab=results" class="<?= $activeTab === 'results' ? 'active' : '' ?>">
            📊 Hasil Ujian <span class="bank-tab-count"><?= $totalAttempts ?></span>
        </a>
    </div>

    <!-- ═══════════════════════════════════════════════════════════════════════
         TAB 1: PAKET SOAL (Berparitas 100% dengan CBT Pretes)
         ═══════════════════════════════════════════════════════════════════════ -->
    <?php if ($activeTab === 'packages'): ?>
        <!-- Panel 1: Daftar Paket Soal (Muslim & Kristen Protestan) -->
        <div class="bank-panel" style="margin-bottom:24px;">
            <div class="bank-panel-head">
                <div>
                    <h3>Daftar Paket Soal CBT Tutorial RPL</h3>
                    <p>Kelola wadah materi soal ujian. Paket 1 Utama (Muslim) &amp; Paket 2 Kristen Protestan.</p>
                </div>
                <div style="display:flex; gap:8px; flex-wrap:wrap;">
                    <form method="POST" style="margin:0;" onsubmit="return confirm('Tindih seluruh data soal Tutorial RPL dengan butir soal resmi CBT Pretes (177 Muslim + 25 Kristen Protestan = 202 Soal)? Tindakan ini akan menimpa data soal lama.');">
                        <input type="hidden" name="action" value="reset_pretes_parity_packages">
                        <button type="submit" class="bank-button" style="background:#fef3c7; border-color:#fde047; color:#92400e; font-weight:700;">
                            🔄 Pulihkan &amp; Tindih Soal CBT Pretes
                        </button>
                    </form>
                    <button type="button" class="bank-button gold" onclick="openPackageModal();" data-action="create-package">
                        📦 ＋ Buat Paket Baru
                    </button>
                    <button type="button" class="bank-button primary" onclick="openAddModal();" data-action="add-question">
                        ➕ Tambah Butir Soal
                    </button>
                </div>
            </div>

            <!-- Tabel Daftar Paket Soal -->
            <div class="bank-table-scroll">
                <table class="bank-table no-datatable">
                    <thead>
                        <tr>
                            <th style="width:40px;">No.</th>
                            <th style="width:130px;">Kode</th>
                            <th>Nama Paket &amp; Deskripsi</th>
                            <th style="text-align:center; width:100px;">Durasi</th>
                            <th style="text-align:center; width:110px;">Target Soal</th>
                            <th style="text-align:center; width:140px;">Soal Terdaftar</th>
                            <th style="text-align:center; width:130px;">Status</th>
                            <th style="text-align:right; width:220px;">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($packages)): ?>
                            <tr>
                                <td colspan="8" class="bank-empty">
                                    Belum ada paket soal dibuat. Klik tombol <strong>＋ Buat Paket Baru</strong> untuk membuat paket soal.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($packages as $pIdx => $pkg):
                                $isP2 = ((int)$pkg['id'] === 2);
                            ?>
                                <tr>
                                    <td style="color:#64748b; font-weight:700;"><?= $pIdx + 1 ?></td>
                                    <td><span class="bank-code"><?= sanitize($pkg['code']) ?></span></td>
                                    <td>
                                        <strong style="color:#0f172a; font-size:13.5px;"><?= sanitize($pkg['name']) ?></strong>
                                        <?php if (!empty($pkg['description'])): ?>
                                            <div style="font-size:12px; color:#64748b; margin-top:3px;"><?= sanitize($pkg['description']) ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td style="text-align:center;"><span class="bank-badge"><?= (int)$pkg['duration_minutes'] ?> Menit</span></td>
                                    <td style="text-align:center;"><span class="bank-badge"><?= (int)$pkg['question_count'] ?> Butir</span></td>
                                    <td style="text-align:center;">
                                        <strong style="color:#047857;"><?= (int)$pkg['question_count_actual'] ?></strong> aktif
                                        <span style="color:#64748b; font-size:11px;">(dari <?= (int)$pkg['total_questions'] ?> total)</span>
                                    </td>
                                    <td style="text-align:center;">
                                        <?php if (!empty($pkg['is_active'])): ?>
                                            <span class="bank-badge active">● SEDANG AKTIF</span>
                                        <?php else: ?>
                                            <span class="bank-badge inactive">Tidak Aktif</span>
                                        <?php endif; ?>
                                    </td>
                                    <td style="text-align:right; white-space:nowrap;">
                                        <button type="button" class="bank-button" style="padding:4px 9px; font-size:11px; font-weight:700; background:#f0fdf4; border-color:#86efac; color:#166534;" onclick="openPackageQuestionsModal(<?= (int)$pkg['id'] ?>, <?= htmlspecialchars(json_encode($pkg['name'], JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8') ?>, <?= htmlspecialchars(json_encode($pkg['code'], JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8') ?>);" data-action="manage-questions" data-package-id="<?= (int)$pkg['id'] ?>" data-package-name="<?= sanitize($pkg['name']) ?>" data-package-code="<?= sanitize($pkg['code']) ?>" title="Lihat &amp; kelola butir pertanyaan paket ini">
                                            📋 Kelola Soal (<?= (int)$pkg['total_questions'] ?>)
                                        </button>
                                        <button type="button" class="bank-button" style="padding:4px 8px; font-size:11px; background:#fef3c7; border-color:#fde047; color:#92400e;" onclick="openAddModal(<?= (int)$pkg['id'] ?>);" data-action="add-question" data-package-id="<?= (int)$pkg['id'] ?>" title="Tambah butir soal ke paket ini">
                                            ＋ Soal
                                        </button>
                                        <button type="button" class="bank-button" style="padding:4px 8px; font-size:11px;" onclick="openEditPackageModalById(<?= (int)$pkg['id'] ?>);" data-action="edit-package" data-package-id="<?= (int)$pkg['id'] ?>" title="Edit Paket Soal">
                                            ✏️ Edit
                                        </button>
                                            <form method="POST" action="?tab=packages" data-no-spa style="display:inline; margin:0;" onsubmit="return confirm('Hapus paket soal ini beserta seluruh butir soal di dalamnya? Sesi yang kehilangan seluruh paket akan dinonaktifkan. Riwayat jawaban dan nilai peserta tetap tersimpan.');">
                                                <input type="hidden" name="action" value="delete_package">
                                                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                                                <input type="hidden" name="package_id" value="<?= (int)$pkg['id'] ?>">
                                                <button type="submit" class="bank-button danger-sm" style="padding:4px 8px; font-size:11px;" title="Hapus Paket Soal">
                                                    🗑️
                                                </button>
                                            </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>
        <!-- ═══════════════════════════════════════════════════════════════════════
         TAB 2: SESI UJIAN (PENGATUR SESI CBT)
         ═══════════════════════════════════════════════════════════════════════ -->
    <?php if ($activeTab === 'sessions'): ?>
    <section class="bank-panel">
        <div class="bank-panel-head">
            <div>
                <h3>Pengatur Sesi Ujian CBT Tutorial RPL</h3>
                <p>Atur paket soal yang ditampilkan, kadar kuota masing-masing kategori, kewajiban kamera, waktu, penilaian, jadwal mulai/selesai, dan token ujian.</p>
            </div>
            <div style="display:flex;gap:8px;flex-wrap:wrap">
                <button type="button" class="bank-button primary" data-action="create-session">🎯 ＋ Buat Sesi Baru</button>
                <button type="button" class="bank-button" data-action="create-tryout" style="background:#eff6ff;color:#1e3a8a;border-color:#bfdbfe">📝 ＋ Buat Tryout</button>
            </div>
        </div>
        <?php $examStatusAdmin = tutorialRplCbtExamStatus($pdo); $examSes = $examStatusAdmin['session']; $examSwitchOpen = $examSes && (int)($examSes['exam_open'] ?? 0) === 1; ?>
        <div class="bank-exam-switch <?= $examStatusAdmin['open'] ? 'is-open' : 'is-closed' ?>" id="examOpenSwitch" role="region" aria-label="Status pengerjaan ujian tutorial RPL resmi">
            <div class="bank-exam-switch-info">
                <div class="bank-exam-switch-state"><?= $examStatusAdmin['open'] ? '🟢 PENGERJAAN UJIAN CBT DIBUKA' : '🔴 PENGERJAAN UJIAN CBT DITUTUP' ?></div>
                <div class="bank-exam-switch-desc">
                    <?php if (!$examSes): ?>
                        Belum ada sesi resmi yang aktif. Aktifkan salah satu sesi resmi terlebih dahulu.
                    <?php else: ?>
                        Sesi resmi aktif: <strong><?= sanitize($examSes['title']) ?></strong>.
                        <?php if ($examStatusAdmin['open']): ?>
                            Peserta tutorial aktif dapat menekan <strong>"Mulai Ujian CBT"</strong> dan memulai ujian.
                        <?php elseif ($examSwitchOpen): ?>
                            Sakelar sudah <strong>dibuka</strong>, tetapi <?= sanitize($examStatusAdmin['notice']) ?> (atur di Edit Sesi › jadwal).
                        <?php else: ?>
                            Peserta melihat link pengerjaan terkunci 🔒 dan tidak dapat memulai ujian.
                        <?php endif; ?>
                        <span style="display:block;margin-top:4px;font-size:11.5px;opacity:.85">Menutup ujian tidak menghentikan peserta yang sedang mengerjakan; mereka tetap dapat menyelesaikan sampai waktunya habis.</span>
                    <?php endif; ?>
                </div>
            </div>
            <?php if ($examSes): ?>
                <form method="post" action="?tab=sessions" data-no-spa style="margin:0"
                      onsubmit="return confirm(<?= htmlspecialchars(json_encode($examSwitchOpen ? 'TUTUP pengerjaan ujian CBT Tutorial RPL sekarang? Peserta tidak akan bisa memulai ujian baru.' : 'BUKA pengerjaan ujian CBT Tutorial RPL sekarang? Peserta tutorial aktif akan bisa memulai ujian.'), ENT_QUOTES, 'UTF-8') ?>)">
                    <input type="hidden" name="action" value="set_exam_open">
                    <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                    <input type="hidden" name="session_id" value="<?= (int)$examSes['id'] ?>">
                    <input type="hidden" name="open" value="<?= $examSwitchOpen ? 0 : 1 ?>">
                    <button type="submit" class="bank-button <?= $examSwitchOpen ? 'danger' : 'primary' ?>" id="examOpenToggle" style="min-height:42px;padding:8px 18px;font-weight:800">
                        <?= $examSwitchOpen ? '🔴 Tutup Ujian' : '🟢 Buka Ujian' ?>
                    </button>
                </form>
            <?php endif; ?>
        </div>
        <form id="bulkDeleteSessionsForm" method="post" action="?tab=sessions" data-bulk-delete-form="sessions" data-no-spa>
            <input type="hidden" name="action" value="delete_sessions">
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
            <div class="bank-panel-head" data-bulk-toolbar="sessions" hidden style="padding-top:10px;padding-bottom:10px">
                <span data-bulk-count="sessions" aria-live="polite">0 sesi dipilih</span>
                <button type="submit" class="bank-button danger" data-bulk-delete-button="sessions">🗑️ Hapus Terpilih</button>
            </div>
        </form>
        <div class="bank-table-scroll">
            <table class="bank-table no-datatable">
                <thead>
                    <tr>
                        <th style="width:38px;text-align:center"><input type="checkbox" aria-label="Pilih semua sesi ujian" data-bulk-select-all="sessions"></th>
                        <th style="width:35px">No.</th>
                        <th>Kode Sesi</th>
                        <th>Nama Sesi &amp; Paket Terpilih</th>
                        <th>Kadar Kategori</th>
                        <th>Kamera</th>
                        <th>Waktu &amp; Nilai</th>
                        <th>Jadwal Pelaksanaan</th>
                        <th>Token</th>
                        <th>Status</th>
                        <th style="text-align:right">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($sessions)): ?>
                    <tr><td colspan="11" class="bank-empty">Belum ada sesi ujian dibuat. Klik tombol <strong>＋ Buat Sesi Baru</strong> untuk mengatur sesi ujian pertama.</td></tr>
                <?php else: ?>
                    <?php foreach ($sessions as $sIdx => $ses): ?>
                    <tr>
                        <td style="text-align:center"><input type="checkbox" name="session_ids[]" value="<?= (int)$ses['id'] ?>" form="bulkDeleteSessionsForm" aria-label="Pilih sesi <?= htmlspecialchars($ses['title'], ENT_QUOTES, 'UTF-8') ?>" data-bulk-select-item="sessions" data-item-label="<?= htmlspecialchars($ses['title'], ENT_QUOTES, 'UTF-8') ?>"></td>
                        <td style="color:#8b978e"><?= $sIdx + 1 ?></td>
                        <td><span class="bank-code"><?= sanitize($ses['code']) ?></span></td>
                        <td>
                            <strong><?= sanitize($ses['title']) ?></strong>
                            <?php if (!empty($ses['description'])): ?>
                                <div style="font-size:11px;color:#6b7972;margin-top:2px"><?= sanitize($ses['description']) ?></div>
                            <?php endif; ?>
                            <div style="margin-top:6px;display:flex;gap:4px;flex-wrap:wrap">
                                <?php foreach ($ses['package_names'] as $pName): ?>
                                    <span class="bank-badge package">📦 <?= sanitize($pName) ?></span>
                                <?php endforeach; ?>
                                <?php if (empty($ses['package_names'])): ?>
                                    <span class="bank-badge" style="color:#b91c1c;background:#fee2e2">Belum ada paket</span>
                                <?php endif; ?>
                            </div>
                        </td>
                        <td>
                            <?php if (!empty($ses['category_quotas'])): ?>
                                <details style="margin:0">
                                    <summary style="font-weight:700;color:#126147;font-size:11.5px"><?= array_sum($ses['category_quotas']) ?> Soal (<?= count($ses['category_quotas']) ?> Kategori)</summary>
                                    <div style="font-size:11px;margin-top:4px;background:#f8faf8;padding:6px 8px;border-radius:6px;line-height:1.6">
                                        <?php foreach ($ses['category_quotas'] as $cat => $quota): ?>
                                            <div>• <strong><?= sanitize($cat) ?></strong>: <?= (int)$quota ?> butir</div>
                                        <?php endforeach; ?>
                                    </div>
                                </details>
                            <?php else: ?>
                                <span class="bank-badge">Acak Penuh Paket (<?= (int)$ses['target_questions'] ?> butir)</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($ses['camera_mode'] === 'wajib'): ?>
                                <span class="bank-badge camera-wajib">📷 WAJIB</span>
                            <?php elseif ($ses['camera_mode'] === 'tidak_aktif'): ?>
                                <span class="bank-badge camera-bebas">🚫 TANPA KAMERA</span>
                            <?php else: ?>
                                <span class="bank-badge camera-opsi">📷 OPSIONAL</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <strong><?= (int)$ses['duration_minutes'] ?> menit</strong>
                            <div style="font-size:11px;color:#6d7f72;margin-top:3px">
                                <?= $ses['scoring_method'] === 'penalti' ? 'UTBK (+4, -1, 0)' : 'Skala 100 Proporsional' ?>
                                (KKM: <?= number_format((float)$ses['passing_grade'], 0) ?>)
                            </div>
                            <div style="margin-top:4px">
                                <?php if (!empty($ses['shuffle_on_violation'])): ?>
                                    <span class="bank-badge" style="background:#dcfce7;color:#15803d;font-size:10px;padding:2px 6px" title="Soal belum dijawab otomatis ditukar dari bank cadangan & opsi diacak jika terdeteksi pelanggaran">🛡️ Anti-Contek (Tukar Cadangan)</span>
                                <?php else: ?>
                                    <span class="bank-badge" style="background:#f1f5f9;color:#64748b;font-size:10px;padding:2px 6px" title="Hanya geser urutan posisi soal tanpa mengambil bank cadangan">Anti-Contek Standar</span>
                                <?php endif; ?>
                            </div>
                        </td>
                        <td>
                            <?php if (!empty($ses['start_time']) || !empty($ses['end_time'])): ?>
                                <div style="font-size:11.5px">
                                    <div>🟢 Mulai: <strong><?= !empty($ses['start_time']) ? (new DateTimeImmutable($ses['start_time']))->format('d/m/Y H:i') : 'Langsung' ?></strong></div>
                                    <div>🔴 Batas: <strong><?= !empty($ses['end_time']) ? (new DateTimeImmutable($ses['end_time']))->format('d/m/Y H:i') : 'Tidak dibatasi' ?></strong></div>
                                </div>
                            <?php else: ?>
                                <span class="bank-badge">Buka Terbuka</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if (!empty($ses['token_required'])): ?>
                                <span class="bank-badge token-badge" title="Token wajib diisi peserta"><?= sanitize($ses['token']) ?></span>
                            <?php else: ?>
                                <span class="bank-badge" style="color:#64748b" title="Token tidak diwajibkan">Tanpa Token</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($ses['is_active']): ?>
                                <span class="bank-badge session-active">● AKTIF</span>
                            <?php else: ?>
                                <span class="bank-badge session-inactive">Tidak Aktif</span>
                            <?php endif; ?>
                            <?php if (($ses['mode'] ?? 'resmi') === 'tryout'): ?>
                                <div style="margin-top:4px"><span class="bank-badge" style="background:#dbeafe;color:#1e3a8a">📝 TRYOUT · <?= (int)($ses['max_attempts'] ?? 0) === 0 ? 'ulang ∞' : ('maks ' . (int)$ses['max_attempts'] . '×') ?></span></div>
                            <?php else: ?>
                                <div style="margin-top:4px"><span class="bank-badge" style="background:#ecfdf5;color:#047857">🎓 RESMI</span></div>
                                <div style="margin-top:4px"><?php if ((int)($ses['exam_open'] ?? 0) === 1): ?><span class="bank-badge" style="background:#dcfce7;color:#166534">🟢 Ujian dibuka</span><?php else: ?><span class="bank-badge" style="background:#fee2e2;color:#991b1b">🔴 Ujian ditutup</span><?php endif; ?></div>
                            <?php endif; ?>
                        </td>
                        <td style="text-align:right;white-space:nowrap">
                            <?php if (!$ses['is_active']): ?>
                                <form method="post" action="?tab=sessions" data-no-spa style="display:inline;margin:0 3px">
                                    <input type="hidden" name="action" value="activate_session">
                                    <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                                    <input type="hidden" name="session_id" value="<?= (int)$ses['id'] ?>">
                                    <button type="submit" class="bank-button" style="min-height:28px;padding:4px 8px;font-size:11px" title="Aktifkan sesi ini untuk ujian mahasiswa">🟢 Aktifkan</button>
                                </form>
                            <?php else: ?>
                                <form method="post" action="?tab=sessions" data-no-spa style="display:inline;margin:0 3px">
                                    <input type="hidden" name="action" value="deactivate_session">
                                    <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                                    <input type="hidden" name="session_id" value="<?= (int)$ses['id'] ?>">
                                    <button type="submit" class="bank-button danger-sm" style="min-height:28px;padding:4px 8px;font-size:11px" title="Nonaktifkan sesi ini">🔴 Nonaktifkan</button>
                                </form>
                            <?php endif; ?>
                            <?php if (($ses['mode'] ?? 'resmi') !== 'tryout'): $rowOpen = (int)($ses['exam_open'] ?? 0) === 1; ?>
                                <form method="post" action="?tab=sessions" data-no-spa style="display:inline;margin:0 3px">
                                    <input type="hidden" name="action" value="set_exam_open">
                                    <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                                    <input type="hidden" name="session_id" value="<?= (int)$ses['id'] ?>">
                                    <input type="hidden" name="open" value="<?= $rowOpen ? 0 : 1 ?>">
                                    <button type="submit" class="bank-button <?= $rowOpen ? 'danger-sm' : '' ?>" style="min-height:28px;padding:4px 8px;font-size:11px" title="<?= $rowOpen ? 'Tutup pengerjaan ujian untuk sesi ini' : 'Buka pengerjaan ujian untuk sesi ini' ?>"><?= $rowOpen ? '🔴 Tutup Ujian' : '🟢 Buka Ujian' ?></button>
                                </form>
                            <?php endif; ?>
                            <button type="button" class="bank-button" style="min-height:28px;padding:4px 8px;font-size:11px" data-action="edit-session" data-session-id="<?= (int)$ses['id'] ?>" onclick="openEditSessionModalById(<?= (int)$ses['id'] ?>)">✏️ Edit</button>
                            <form method="post" action="?tab=sessions" data-no-spa style="display:inline;margin:0 0 0 3px" onsubmit="return confirm(<?= htmlspecialchars(json_encode("PERINGATAN: Yakin ingin menghapus Sesi Ujian '" . $ses['title'] . "'?"), ENT_QUOTES, 'UTF-8') ?>)">
                                <input type="hidden" name="action" value="delete_session">
                                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                                <input type="hidden" name="session_id" value="<?= (int)$ses['id'] ?>">
                                <button type="submit" class="bank-button danger-sm" title="Hapus sesi ujian">🗑️ Hapus</button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
    <?php endif; ?>

    <!-- ═══════════════════════════════════════════════════════════════════════
         TAB 3: IMPOR EXCEL (.XLSX)
         ═══════════════════════════════════════════════════════════════════════ -->
    <?php if ($activeTab === 'import'): ?>
        <div class="bank-panel">
            <div class="bank-panel-head">
                <div>
                    <h3>Impor Bank Soal dari Berkas Excel (.xlsx)</h3>
                    <p>Unggah file Excel berformat template LPPAI untuk menambahkan atau memperbarui butir soal secara massal.</p>
                </div>
                <div>
                    <a href="?download=template" class="bank-button ghost" style="color:#0f766e; border-color:#0f766e;">
                        📥 Unduh Template (.xlsx)
                    </a>
                </div>
            </div>

            <div class="bank-panel-body">
                <form method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="import_xlsx">

                    <div class="bank-import-grid">
                        <div>
                            <div class="bank-field" style="margin-bottom:18px;">
                                <label class="bank-label">Pilih Paket Soal Tujuan Impor:</label>
                                <select name="package_id" class="bank-input" style="font-weight:700;">
                                    <option value="1">📦 Paket 1: Utama (Ubudiyah / Muslim)</option>
                                    <option value="2">📦 Paket 2: Kristen Protestan</option>
                                </select>
                                <span class="bank-note">Butir soal dalam berkas Excel akan dimasukkan ke paket yang dipilih.</span>
                            </div>

                            <label class="bank-upload" id="uploadDropzone">
                                <span class="bank-upload-icon">📊</span>
                                <strong>Pilih atau Tarik Berkas Excel (.xlsx) ke Sini</strong>
                                <span>Gunakan format template standar LPPAI dengan header kolom A–I</span>
                                <input type="file" name="question_file" accept=".xlsx" required style="margin-top:16px;">
                            </label>

                            <div style="margin-top:16px;">
                                <label class="bank-toggle" style="cursor:pointer;">
                                    <input type="checkbox" name="update_existing" value="1" checked>
                                    <div>
                                        <strong>Perbarui butir soal jika Kode Soal sudah ada di sistem (Update Existing)</strong>
                                        <div class="bank-note">Jika kode soal cocok, teks pertanyaan dan pilihan jawaban akan diperbarui. Jika kode belum ada, akan ditambahkan sebagai soal baru.</div>
                                    </div>
                                </label>
                            </div>

                            <div style="margin-top:20px;">
                                <button type="submit" class="bank-button primary" style="width:100%; min-height:44px; font-size:14px;">
                                    🚀 Unggah &amp; Mulai Impor Bank Soal
                                </button>
                            </div>
                        </div>

                        <div>
                            <div class="bank-form-aside">
                                <h4>📋 Petunjuk Susunan Kolom Template:</h4>
                                <ul class="bank-steps" style="font-size:12px;">
                                    <li><b>A</b> <div><strong>Kode Soal</strong> Cth: <code>TUTOR-RPL-01</code> (opsional, jika kosong dibuat otomatis).</div></li>
                                    <li><b>B</b> <div><strong>Kategori</strong> Cth: <code>Thaharah</code>, <code>Sholat Wajib</code>, <code>Ubudiyah</code>.</div></li>
                                    <li><b>C</b> <div><strong>Teks Pertanyaan</strong> Pertanyaan lengkap butir soal.</div></li>
                                    <li><b>D</b> <div><strong>Pilihan A</strong> Teks opsi A.</div></li>
                                    <li><b>E</b> <div><strong>Pilihan B</strong> Teks opsi B.</div></li>
                                    <li><b>F</b> <div><strong>Pilihan C</strong> Teks opsi C (opsional).</div></li>
                                    <li><b>G</b> <div><strong>Pilihan D</strong> Teks opsi D (opsional).</div></li>
                                    <li><b>H</b> <div><strong>Pilihan E</strong> Teks opsi E (opsional).</div></li>
                                    <li><b>I</b> <div><strong>Kunci Jawaban</strong> Huruf kapital <code>A</code>, <code>B</code>, <code>C</code>, <code>D</code>, atau <code>E</code>.</div></li>
                                </ul>
                            </div>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    <?php endif; ?>

    <!-- ═══════════════════════════════════════════════════════════════════════
         TAB 4: HASIL UJIAN & LIVE MONITORING CBT TUTORIAL RPL
         ═══════════════════════════════════════════════════════════════════════ -->
    <?php if ($activeTab === 'results'): ?>
        <section class="bank-panel">
            <div class="bank-panel-head">
                <div>
                    <h3>Hasil Ujian Mahasiswa &amp; Live Monitoring CBT Tutorial RPL</h3>
                    <p>
                        <?php if ($statusFilter === 'live'): ?>
                            <strong>⚡ Mode Live:</strong> Memantau mahasiswa RPL yang sedang aktif mengerjakan ujian secara real-time.
                        <?php elseif ($statusFilter === 'finished'): ?>
                            <strong>✓ Mode Selesai:</strong> Riwayat hasil akhir, nilai, dan lembar jawaban mahasiswa RPL yang telah selesai.
                        <?php else: ?>
                            Semua sesi pengerjaan CBT Tutorial RPL (berlangsung maupun telah selesai).
                        <?php endif; ?>
                        <?= $resultFilter === '1' ? ' (Filter: Paket 1 - Muslim)' : ($resultFilter === '2' ? ' (Filter: Paket 2 - Kristen)' : '') ?>
                    </p>
                </div>
                <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
                    <?php if (!empty($attempts)): ?>
                        <form method="post" action="<?= buildTutorialRplResultUrl(['tab' => 'results']) ?>" style="margin:0;display:inline;" onsubmit="return confirm('PERINGATAN: Yakin ingin menghapus <?= $resultFilter === '1' ? 'SEMUA sesi Paket 1 (Muslim)' : ($resultFilter === '2' ? 'SEMUA sesi Paket 2 (Kristen)' : 'SEMUA sesi CBT Tutorial RPL') ?> mahasiswa?\n\nSemua riwayat pengerjaan, jawaban, dan nilai sesuai filter akan dihapus.')">
                            <input type="hidden" name="action" value="delete_all_attempts">
                            <input type="hidden" name="delete_scope" value="<?= sanitize($resultFilter) ?>">
                            <button type="submit" class="bank-button danger" title="Hapus Semua Hasil CBT mahasiswa sesuai filter">🗑️ Hapus Semua Hasil (<?= $resultFilter === '1' ? 'Paket 1' : ($resultFilter === '2' ? 'Paket 2' : 'Semua') ?>)</button>
                        </form>
                    <?php endif; ?>
                    <?php
                        $exportQs = http_build_query(array_filter([
                            'package_id' => $resultFilter !== 'all' ? $resultFilter : '',
                            'pass_filter' => ($passFilter !== 'all') ? $passFilter : '',
                            'group' => $groupFilter ?? '',
                            'q' => $searchQuery ?? '',
                        ], fn($v) => $v !== ''));
                        $exportSuffix = $exportQs !== '' ? '&amp;' . htmlspecialchars($exportQs, ENT_QUOTES, 'UTF-8') : '';
                        $exportPdfQs = http_build_query(array_filter([
                            'status_filter' => $statusFilter !== 'all' ? $statusFilter : '',
                            'result_filter' => $resultFilter !== 'all' ? $resultFilter : '',
                            'pass_filter' => ($passFilter !== 'all') ? $passFilter : '',
                            'group' => $groupFilter ?? '',
                            'q' => $searchQuery ?? '',
                        ], fn($v) => $v !== ''));
                        $exportPdfSuffix = $exportPdfQs !== '' ? '&amp;' . htmlspecialchars($exportPdfQs, ENT_QUOTES, 'UTF-8') : '';
                        $exportNote = $exportQs !== '' ? ' (mengikuti filter aktif: paket/kelulusan/kelompok/pencarian)' : '';
                    ?>
                    <a data-no-spa class="bank-button" style="background:#dc2626;color:#ffffff;border-color:#b91c1c;" href="?download=results_pdf&amp;print=1<?= $exportPdfSuffix ?>" target="_blank" rel="noopener" title="Ekspor dokumen PDF hasil ujian CBT Tutorial RPL<?= $exportNote ?>">📄 Export PDF</a>
                    <a data-no-spa class="bank-button primary" href="?download=results<?= $exportSuffix ?>" title="Ekspor data nilai ujian CBT Tutorial RPL<?= $exportNote ?>">📊 Ekspor Hasil (.xlsx)</a>
                    <?php if ($exportQs !== ''): ?>
                        <span class="cbt-export-note" id="cbtExportFilterNote">⚙️ Ekspor mengikuti filter aktif</span>
                    <?php endif; ?>
                </div>
            </div>

            <div style="padding:14px 24px 0;">
                <!-- Status Sub-Tabs: Live Monitoring vs Selesai vs Semua -->
                <div class="cbt-status-tabs" style="display:flex;gap:8px;margin-bottom:14px;border-bottom:2px solid #e2e8f0;padding-bottom:12px;flex-wrap:wrap">
                    <a href="<?= buildTutorialRplResultUrl(['status_filter' => 'live', 'pass_filter' => 'all', 'page' => 1]) ?>" class="cbt-status-tab-btn <?= $statusFilter === 'live' ? 'active' : '' ?>">
                        <span>⚡ Sedang Berlangsung (Live)</span>
                        <span id="cbtLiveTabBadge" class="cbt-tab-count <?= $ongoingCount > 0 ? 'live' : '' ?>"><?= $ongoingCount ?></span>
                    </a>
                    <a href="<?= buildTutorialRplResultUrl(['status_filter' => 'finished', 'page' => 1]) ?>" class="cbt-status-tab-btn <?= $statusFilter === 'finished' ? 'active' : '' ?>">
                        <span>✓ Riwayat Selesai</span>
                        <span id="cbtFinishedTabBadge" class="cbt-tab-count"><?= $finishedCount ?></span>
                    </a>
                    <a href="<?= buildTutorialRplResultUrl(['status_filter' => 'all', 'page' => 1]) ?>" class="cbt-status-tab-btn <?= $statusFilter === 'all' ? 'active' : '' ?>">
                        <span>📋 Semua Sesi</span>
                        <span id="cbtAllTabBadge" class="cbt-tab-count"><?= $totalAttempts ?></span>
                    </a>
                </div>

                <!-- Live Monitoring Toolbar -->
                <div class="cbt-live-toolbar" style="display:flex;justify-content:space-between;align-items:center;background:#f8fafc;border:1px solid #e2e8f0;border-radius:12px;padding:12px 18px;margin-bottom:14px;flex-wrap:wrap;gap:12px">
                    <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap">
                        <span style="display:inline-flex;align-items:center;gap:6px;font-size:12.5px;font-weight:700;color:#0f172a">
                            <span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:#10b981;box-shadow:0 0 0 3px rgba(16,185,129,0.2)"></span>
                            Live Monitoring CBT Tutorial RPL
                        </span>
                        <span id="cbtLiveOngoingCounter" class="bank-badge" style="background:#ecfdf5;color:#065f46;border:1px solid #a7f3d0;font-size:11px">
                            ⚡ <strong id="cbtLiveOngoingNum"><?= $ongoingCount ?></strong> peserta sedang mengerjakan
                        </span>
                        <span id="cbtLiveLastUpdated" style="font-size:11.5px;color:#64748b">
                            Pembaruan: <?= (new DateTimeImmutable('now', new DateTimeZone('Asia/Jakarta')))->format('H:i:s') ?> WIB
                        </span>
                    </div>
                    <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
                        <label style="display:inline-flex;align-items:center;gap:6px;font-size:12px;font-weight:600;color:#334155;cursor:pointer">
                            <input type="checkbox" id="cbtLiveAutoRefreshToggle" <?= $statusFilter === 'finished' ? '' : 'checked' ?> style="accent-color:#126147;width:16px;height:16px;cursor:pointer">
                            Auto-refresh
                        </label>
                        <select id="cbtLiveRefreshInterval" class="bank-input" style="width:auto;min-height:30px;padding:4px 8px;font-size:11.5px;margin:0">
                            <option value="5" selected>Tiap 5 Detik</option>
                            <option value="10">Tiap 10 Detik</option>
                            <option value="30">Tiap 30 Detik</option>
                        </select>
                        <button type="button" id="cbtLiveManualRefreshBtn" class="bank-button" style="min-height:30px;padding:4px 12px;font-size:11.5px" title="Segarkan data sekarang">
                            🔄 Segarkan
                        </button>
                    </div>
                </div>

                <!-- Filter Tipe, Paket, Kelulusan & Pencarian -->
                <?php
                    $hasExtraFilter = ($searchQuery !== '' || $groupFilter !== '' || $passFilter !== 'all');
                ?>
                <div class="cbt-result-filters" id="cbtResultFilters">
                    <div class="cbt-filter-row">
                        <span class="cbt-filter-label">Paket</span>
                        <div class="cbt-filter-chips">
                            <a href="<?= buildTutorialRplResultUrl(['result_filter' => 'all', 'page' => 1]) ?>" class="cbt-chip <?= $resultFilter === 'all' ? 'active' : '' ?>">Semua <span><?= $totalAttempts ?></span></a>
                            <a href="<?= buildTutorialRplResultUrl(['result_filter' => '1', 'page' => 1]) ?>" class="cbt-chip <?= $resultFilter === '1' ? 'active' : '' ?>">📦 Paket 1 (Muslim) <span><?= $totalPkg1Attempts ?></span></a>
                            <a href="<?= buildTutorialRplResultUrl(['result_filter' => '2', 'page' => 1]) ?>" class="cbt-chip <?= $resultFilter === '2' ? 'active' : '' ?>">📦 Paket 2 (Kristen) <span><?= $totalPkg2Attempts ?></span></a>
                        </div>
                    </div>
                    <div class="cbt-filter-row">
                        <span class="cbt-filter-label">Hasil</span>
                        <div class="cbt-filter-chips" id="cbtPassFilterChips">
                            <a href="<?= buildTutorialRplResultUrl(['pass_filter' => 'all', 'page' => 1]) ?>" class="cbt-chip <?= $passFilter === 'all' ? 'active' : '' ?>" data-pass-filter="all">Semua</a>
                            <a href="<?= buildTutorialRplResultUrl(['pass_filter' => 'lulus', 'page' => 1]) ?>" class="cbt-chip cbt-chip-pass <?= $passFilter === 'lulus' ? 'active' : '' ?>" data-pass-filter="lulus" title="Sesi selesai dengan nilai &ge; <?= (int)$passingGrade ?>">✓ Lulus <span id="cbtPassCountLulus"><?= (int)$passCounts['lulus'] ?></span></a>
                            <a href="<?= buildTutorialRplResultUrl(['pass_filter' => 'tidak_lulus', 'page' => 1]) ?>" class="cbt-chip cbt-chip-fail <?= $passFilter === 'tidak_lulus' ? 'active' : '' ?>" data-pass-filter="tidak_lulus" title="Sesi selesai dengan nilai &lt; <?= (int)$passingGrade ?>">✕ Belum Memenuhi <span id="cbtPassCountTidak"><?= (int)$passCounts['tidak_lulus'] ?></span></a>
                        </div>
                    </div>

                    <form method="get" action="" class="cbt-filter-form" id="cbtResultSearchForm" data-no-spa role="search">
                        <input type="hidden" name="tab" value="results">
                        <?php if ($statusFilter !== 'all'): ?><input type="hidden" name="status_filter" value="<?= sanitize($statusFilter) ?>"><?php endif; ?>
                        <?php if ($resultFilter !== 'all'): ?><input type="hidden" name="result_filter" value="<?= sanitize($resultFilter) ?>"><?php endif; ?>
                        <?php if ($passFilter !== 'all'): ?><input type="hidden" name="pass_filter" value="<?= sanitize($passFilter) ?>"><?php endif; ?>
                        <?php if ($perPage !== 25): ?><input type="hidden" name="per_page" value="<?= (int)$perPage ?>"><?php endif; ?>
                        <label class="cbt-filter-field cbt-filter-day">
                            <span class="cbt-filter-label">Kelompok</span>
                            <select name="group" id="cbtGroupFilter" class="bank-input" onchange="this.form.submit()">
                                <option value="">Semua Kelompok RPL</option>
                                <?php foreach ($groupOptions as $grp): ?>
                                    <option value="<?= sanitize($grp) ?>" <?= $grp === $groupFilter ? 'selected' : '' ?>><?= sanitize($grp) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <label class="cbt-filter-field cbt-filter-search">
                            <span class="cbt-filter-label">Cari</span>
                            <span class="cbt-search-box">
                                <span class="cbt-search-icon" aria-hidden="true">🔍</span>
                                <input type="search" name="q" id="cbtResultSearchInput" value="<?= sanitize($searchQuery) ?>" placeholder="Nama, NIM, atau program studi…" maxlength="100" autocomplete="off" enterkeyhint="search" aria-label="Cari mahasiswa berdasarkan nama, NIM, atau program studi">
                            </span>
                        </label>
                        <div class="cbt-filter-actions">
                            <button type="submit" class="bank-button primary">Cari</button>
                            <?php if ($hasExtraFilter): ?>
                                <a href="<?= buildTutorialRplResultUrl(['q' => '', 'group' => '', 'pass_filter' => 'all', 'page' => 1]) ?>" class="bank-button" id="cbtResultResetFilters" title="Hapus pencarian, filter kelompok, dan filter kelulusan">✕ Reset</a>
                            <?php endif; ?>
                        </div>
                    </form>
                    <?php if ($hasExtraFilter): ?>
                        <div class="cbt-active-filters">
                            Filter aktif:
                            <?php if ($groupFilter !== ''): ?><span class="cbt-active-tag">👥 <?= sanitize($groupFilter) ?> <a href="<?= buildTutorialRplResultUrl(['group' => '', 'page' => 1]) ?>" aria-label="Hapus filter kelompok">✕</a></span><?php endif; ?>
                            <?php if ($passFilter !== 'all'): ?><span class="cbt-active-tag"><?= $passFilter === 'lulus' ? '✓ Lulus' : '✕ Belum Memenuhi' ?> <a href="<?= buildTutorialRplResultUrl(['pass_filter' => 'all', 'page' => 1]) ?>" aria-label="Hapus filter kelulusan">✕</a></span><?php endif; ?>
                            <?php if ($searchQuery !== ''): ?><span class="cbt-active-tag">🔍 &ldquo;<?= sanitize($searchQuery) ?>&rdquo; <a href="<?= buildTutorialRplResultUrl(['q' => '', 'page' => 1]) ?>" aria-label="Hapus pencarian">✕</a></span><?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="bank-table-scroll">
                <table class="bank-table no-datatable" id="cbtLiveResultsTable">
                    <thead>
                        <tr>
                            <th>Peserta</th>
                            <th>NIM</th>
                            <th>Kelompok &amp; Tutor</th>
                            <th>Paket / Sesi</th>
                            <th>Waktu &amp; Sisa</th>
                            <th>Status</th>
                            <th>Progres Dikerjakan</th>
                            <th>Benar / Salah</th>
                            <th>Nilai</th>
                            <th>Aksi</th>
                        </tr>
                    </thead>
                    <tbody id="cbtLiveResultsTbody">
                    <?php
                    $nowTs = time();
                    foreach ($attempts as $attempt):
                        $isSubmitted = ($attempt['submitted_at'] !== null);
                        $deadline = (int)($attempt['deadline_at'] ?? ($attempt['started_at'] + 90 * 60));
                        $isExpired = (!$isSubmitted && $nowTs >= $deadline);
                        $isOngoing = (!$isSubmitted && !$isExpired);

                        $attId = (int)$attempt['id'];
                        $stat = $itemStatsMap[$attId] ?? null;
                        $totItems = $stat ? (int)$stat['total_items'] : (int)($attempt['total_questions'] ?? 25);
                        $ansCount = $stat ? (int)$stat['answered_count'] : 0;
                        $pct = $totItems > 0 ? min(100, round(($ansCount / $totItems) * 100)) : 0;
                        $flgCount = $stat ? (int)$stat['flagged_count'] : 0;

                        $corCount = $isSubmitted ? (int)($attempt['correct_count'] ?? 0) : ($stat ? (int)$stat['live_correct_count'] : 0);
                        $wrgCount = $isSubmitted ? max(0, $totItems - $corCount) : ($stat ? (int)$stat['live_wrong_count'] : 0);
                        $unansCount = max(0, $totItems - $ansCount);

                        $pkgId = (int)($attempt['package_id'] ?? 1);
                        $isPkg2 = ($pkgId === 2);
                    ?>
                        <tr id="attemptRow_<?= $attId ?>" data-attempt-id="<?= $attId ?>">
                            <td>
                                <div>
                                    <a class="admin-user-profile-link" data-no-spa href="<?= BASE_URL ?>/admin/profil-pengguna.php?id=<?= (int)$attempt['user_id'] ?>" style="color:inherit;text-decoration:underline;text-underline-offset:3px" title="Lihat profil pengguna">
                                        <strong><?= sanitize($attempt['nama_lengkap']) ?></strong>
                                    </a>
                                </div>
                                <?php if (!empty(trim((string)($attempt['program_studi'] ?? '')))): ?>
                                    <div class="cbt-user-prodi" style="font-size:11px;color:#64748b;margin-top:2px;line-height:1.25;"><?= sanitize($attempt['program_studi']) ?></div>
                                <?php endif; ?>
                            </td>
                            <td><?= sanitize($attempt['nim'] ?? '-') ?></td>
                            <td>
                                <div style="font-weight:600; color:#0369a1;"><?= sanitize($attempt['nama_kelompok'] ?? '-') ?></div>
                                <div style="font-size:11px; color:#64748b;">👨‍🏫 <?= sanitize($attempt['dosen_nama'] ?? '-') ?></div>
                            </td>
                            <td>
                                <span class="bank-badge" style="<?= $isPkg2 ? 'background:#dbeafe;color:#1e3a8a' : 'background:#ecfdf5;color:#047857' ?>"><?= $isPkg2 ? 'Paket 2 (Kristen)' : 'Paket 1 (Muslim)' ?></span>
                                <?php if (!empty($attempt['session_title'])): ?>
                                    <div style="margin-top:3px"><span class="bank-badge session" style="font-size:10px;background:#f1f5f9;color:#334155;border:1px solid #cbd5e1" title="Sesi: <?= sanitize($attempt['session_title']) ?>">🎯 <?= sanitize($attempt['session_title']) ?></span></div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div><?= (new DateTimeImmutable('@' . $attempt['started_at']))->setTimezone(new DateTimeZone('Asia/Jakarta'))->format('d/m/Y H:i') ?></div>
                                <?php if ($isSubmitted): ?>
                                    <?php
                                        $durSeconds = max(0, (int)$attempt['submitted_at'] - (int)$attempt['started_at']);
                                        $durMins = floor($durSeconds / 60);
                                        $durSecs = $durSeconds % 60;
                                    ?>
                                    <div style="margin-top:3px;font-size:11px;color:#64748b" title="Durasi pengerjaan">
                                        ⏱️ <?= $durMins ?>m <?= $durSecs ?>s
                                    </div>
                                <?php elseif ($isOngoing): ?>
                                    <?php
                                        $remSeconds = max(0, $deadline - $nowTs);
                                        $remMins = floor($remSeconds / 60);
                                        $remSecs = $remSeconds % 60;
                                    ?>
                                    <div class="live-countdown" data-deadline="<?= $deadline ?>" style="margin-top:3px;font-size:11px;font-weight:700;color:#d97706" title="Sisa waktu pengerjaan">
                                        ⏳ <span class="countdown-clock"><?= $remMins ?>m <?= sprintf('%02d', $remSecs) ?>s</span>
                                    </div>
                                <?php else: ?>
                                    <div style="margin-top:3px;font-size:11px;color:#94a3b8">⌛ Waktu Habis</div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($isSubmitted): ?>
                                    <span class="bank-badge active">✓ Selesai</span>
                                <?php elseif ($isOngoing): ?>
                                    <span class="bank-badge live-pulse-badge" style="background:#fef3c7;color:#92400e;border:1px solid #fde68a">
                                        <span class="pulse-dot"></span> Berlangsung
                                    </span>
                                <?php else: ?>
                                    <span class="bank-badge inactive">⌛ Waktu Habis</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div style="min-width:130px">
                                    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:3px;font-size:11.5px">
                                        <span><strong><?= $ansCount ?></strong> / <?= $totItems ?> Soal</span>
                                        <span style="font-size:10.5px;color:#64748b;font-weight:700"><?= $pct ?>%</span>
                                    </div>
                                    <div style="background:#e2e8f0;border-radius:999px;height:6px;width:100%;overflow:hidden;margin-bottom:5px">
                                        <div style="background:<?= $isSubmitted ? '#10b981' : '#2563eb' ?>;height:100%;width:<?= $pct ?>%;border-radius:999px;transition:width .3s"></div>
                                    </div>
                                    <div>
                                        <?php if ($flgCount > 0): ?>
                                            <span class="bank-badge" style="background:#fef9c3;color:#854d0e;border:1px solid #fef08a;font-size:10px;padding:2px 6px">
                                                🟨 <?= $flgCount ?> Ragu
                                            </span>
                                        <?php else: ?>
                                            <span style="font-size:10.5px;color:#94a3b8">0 Ragu</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </td>
                            <td>
                                <div style="display:flex;flex-direction:column;gap:2px;font-size:11.5px;min-width:95px">
                                    <span style="color:#059669;font-weight:700">✓ <?= $corCount ?> Benar</span>
                                    <span style="color:#dc2626;font-weight:700">✕ <?= $wrgCount ?> Salah</span>
                                    <?php if (!$isSubmitted && $unansCount > 0): ?>
                                        <span style="color:#64748b;font-size:10.5px">⋯ <?= $unansCount ?> Belum</span>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td>
                                <?php if ($isSubmitted): ?>
                                    <span class="bank-key" title="Nilai Akhir Ujian"><?= $attempt['score'] !== null ? number_format((float)$attempt['score'], 1) : '—' ?></span>
                                    <?php $isPass = $attempt['score'] !== null && (float)$attempt['score'] >= $passingGrade; ?>
                                    <span class="cbt-pass-label <?= $isPass ? 'is-pass' : 'is-fail' ?>"><?= $isPass ? 'Lulus' : 'Belum Memenuhi' ?></span>
                                <?php else: ?>
                                    <?php
                                        $liveScore = $totItems > 0 ? round(($corCount / $totItems) * 100, 1) : 0.0;
                                    ?>
                                    <span class="bank-key" style="background:#eff6ff;color:#1e40af;border:1px solid #bfdbfe;font-size:11.5px;width:auto;padding:0 7px" title="Estimasi nilai sementara saat ini">
                                        <?= number_format($liveScore, 1) ?>
                                        <span style="font-size:9px;font-weight:600;display:block;line-height:1;margin-top:1px;color:#3b82f6">(Live)</span>
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td style="text-align:center">
                                <div style="display:inline-flex;align-items:center;gap:6px;justify-content:center;flex-wrap:wrap">
                                    <a class="bank-button" style="background:#2563eb;color:#ffffff;border-color:#1d4ed8;padding:4px 9px;font-size:11.5px;border-radius:6px;display:inline-flex;align-items:center;gap:4px;line-height:1.2;font-weight:600;text-decoration:none" href="<?= sanitize(BASE_URL . '/admin/tutorial-rpl-cbt-review.php?' . http_build_query(array_merge(tutorialRplCbtReviewContext($_GET),['attempt_id'=>$attId]))) ?>" title="Review lembar jawaban mahasiswa (soal benar, salah, dan ragu)">👁️ Review</a>
                                    <form method="post" action="<?= buildTutorialRplResultUrl() ?>" style="margin:0;display:inline-block;" onsubmit="return confirm('Reset attempt ujian mahasiswa <?= htmlspecialchars(addslashes($attempt['nama_lengkap']), ENT_QUOTES, 'UTF-8') ?> (NIM: <?= htmlspecialchars($attempt['nim'] ?? '-', ENT_QUOTES, 'UTF-8') ?>)?\n\nMahasiswa akan diizinkan memulai ujian kembali dari awal.')">
                                        <input type="hidden" name="action" value="reset_attempt">
                                        <input type="hidden" name="attempt_id" value="<?= $attId ?>">
                                        <input type="hidden" name="user_id" value="<?= (int)$attempt['user_id'] ?>">
                                        <input type="hidden" name="class_id" value="<?= (int)$attempt['class_id'] ?>">
                                        <button type="submit" class="bank-button" style="padding:4px 8px;font-size:11.5px;" title="Izinkan Ujian Ulang">🔄 Reset</button>
                                    </form>
                                    <form method="post" action="<?= buildTutorialRplResultUrl() ?>" style="margin:0;display:inline-block;" onsubmit="return confirm('Yakin ingin menghapus sesi CBT <?= htmlspecialchars(addslashes($attempt['nama_lengkap']), ENT_QUOTES, 'UTF-8') ?> (NIM: <?= htmlspecialchars($attempt['nim'] ?? '-', ENT_QUOTES, 'UTF-8') ?>)?\n\nData jawaban dan nilai akan dihapus, dan mahasiswa dapat memulai ujian kembali.')">
                                        <input type="hidden" name="action" value="delete_attempt">
                                        <input type="hidden" name="attempt_id" value="<?= $attId ?>">
                                        <button type="submit" class="bank-button danger-sm" title="Hapus sesi CBT mahasiswa ini">🗑️ Hapus</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$attempts): ?>
                        <?php if ($statusFilter === 'live'): ?>
                            <tr>
                                <td colspan="10" class="bank-empty" style="padding:32px 16px">
                                    <div style="font-size:24px;margin-bottom:6px">⚡</div>
                                    <div style="font-weight:700;font-size:14px;color:#0f172a;margin-bottom:4px">Tidak ada mahasiswa yang sedang mengerjakan ujian saat ini.</div>
                                    <div style="font-size:12px;color:#64748b;margin-bottom:12px">Seluruh mahasiswa telah menyelesaikan ujian atau belum ada sesi baru yang dimulai.</div>
                                    <a href="<?= buildTutorialRplResultUrl(['status_filter' => 'finished', 'page' => 1]) ?>" class="bank-button primary" style="font-size:12px;min-height:30px;padding:4px 14px">Lihat Riwayat Selesai (<?= $finishedCount ?>)</a>
                                </td>
                            </tr>
                        <?php elseif ($hasExtraFilter): ?>
                            <tr>
                                <td colspan="10" class="bank-empty" style="padding:28px 16px">
                                    <div style="font-size:22px;margin-bottom:6px">🔍</div>
                                    <div style="font-weight:700;color:#0f172a;margin-bottom:4px"><?= $searchQuery !== '' ? 'Tidak ditemukan sesi dengan kata kunci &ldquo;' . sanitize($searchQuery) . '&rdquo; pada filter ini.' : 'Tidak ada sesi yang cocok dengan filter ini.' ?></div>
                                    <a href="<?= buildTutorialRplResultUrl(['q' => '', 'group' => '', 'pass_filter' => 'all', 'page' => 1]) ?>" class="bank-button" style="font-size:11.5px;min-height:28px;margin-top:8px">Reset Filter</a>
                                </td>
                            </tr>
                        <?php else: ?>
                            <tr><td colspan="10" class="bank-empty">Belum ada mahasiswa yang memulai CBT Tutorial RPL.</td></tr>
                        <?php endif; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- Pagination & Footer Bar -->
            <div class="cbt-pagination-bar" style="display:flex;justify-content:space-between;align-items:center;padding:12px 20px;border-top:1px solid #e2e8f0;background:#fafcfb;flex-wrap:wrap;gap:12px">
                <div class="cbt-pagination-info" style="font-size:12px;color:#475569">
                    Menampilkan <strong><?= $totalFiltered > 0 ? ($offset + 1) : 0 ?></strong> &ndash; <strong><?= min($offset + $perPage, $totalFiltered) ?></strong> dari <strong><?= $totalFiltered ?></strong> sesi
                    <?php if ($totalPages > 1): ?>
                        <span style="color:#94a3b8;margin-left:6px">(Halaman <strong><?= $page ?></strong> dari <strong><?= $totalPages ?></strong>)</span>
                    <?php endif; ?>
                </div>

                <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap">
                    <div style="display:inline-flex;align-items:center;gap:6px;font-size:12px;color:#64748b">
                        <label for="cbtPerPageSelect">Per halaman:</label>
                        <select id="cbtPerPageSelect" class="bank-input" style="min-height:28px;padding:2px 8px;font-size:12px;width:auto;margin:0" onchange="window.location.href = this.value">
                            <?php foreach ([10, 25, 50, 100] as $pp): ?>
                                <option value="<?= buildTutorialRplResultUrl(['per_page' => $pp, 'page' => 1]) ?>" <?= $perPage === $pp ? 'selected' : '' ?>><?= $pp ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <?php if ($totalPages > 1): ?>
                    <nav class="cbt-pagination-nav" aria-label="Navigasi Halaman CBT" style="display:flex;gap:4px;align-items:center">
                        <?php if ($page > 1): ?>
                            <a href="<?= buildTutorialRplResultUrl(['page' => 1]) ?>" class="cbt-page-link" title="Halaman Pertama">&laquo;</a>
                            <a href="<?= buildTutorialRplResultUrl(['page' => $page - 1]) ?>" class="cbt-page-link" title="Halaman Sebelumnya">&lsaquo;</a>
                        <?php else: ?>
                            <span class="cbt-page-link disabled">&laquo;</span>
                            <span class="cbt-page-link disabled">&lsaquo;</span>
                        <?php endif; ?>

                        <?php
                        $startP = max(1, $page - 2);
                        $endP = min($totalPages, $page + 2);
                        if ($startP > 1) {
                            echo '<a href="' . buildTutorialRplResultUrl(['page' => 1]) . '" class="cbt-page-link">1</a>';
                            if ($startP > 2) echo '<span class="cbt-page-ellipsis">&hellip;</span>';
                        }
                        for ($p = $startP; $p <= $endP; $p++) {
                            if ($p === $page) {
                                echo '<span class="cbt-page-link active" aria-current="page">' . $p . '</span>';
                            } else {
                                echo '<a href="' . buildTutorialRplResultUrl(['page' => $p]) . '" class="cbt-page-link">' . $p . '</a>';
                            }
                        }
                        if ($endP < $totalPages) {
                            if ($endP < $totalPages - 1) echo '<span class="cbt-page-ellipsis">&hellip;</span>';
                            echo '<a href="' . buildTutorialRplResultUrl(['page' => $totalPages]) . '" class="cbt-page-link">' . $totalPages . '</a>';
                        }
                        ?>

                        <?php if ($page < $totalPages): ?>
                            <a href="<?= buildTutorialRplResultUrl(['page' => $page + 1]) ?>" class="cbt-page-link" title="Halaman Berikutnya">&rsaquo;</a>
                            <a href="<?= buildTutorialRplResultUrl(['page' => $totalPages]) ?>" class="cbt-page-link" title="Halaman Terakhir">&raquo;</a>
                        <?php else: ?>
                            <span class="cbt-page-link disabled">&rsaquo;</span>
                            <span class="cbt-page-link disabled">&raquo;</span>
                        <?php endif; ?>
                    </nav>
                    <?php endif; ?>
                </div>
            </div>

            <div class="bank-footer">
                <span>Nilai CBT dan progres pengerjaan disimpan terpisah dari penilaian praktik dan nilai impor lama.</span>
                <?php if (!empty($attempts)): ?><span>Menampilkan sesi pengerjaan CBT dengan kontrol live monitor &amp; paginasi.</span><?php endif; ?>
            </div>
        </section>
    <?php endif; ?>
</div>

<!-- ═══════════════════════════════════════════════════════════════════════
     MODAL 2: PENGATUR SESI UJIAN (LARGE MODAL - TANPA PINDAH HALAMAN)
     ═══════════════════════════════════════════════════════════════════════ -->
<div class="bank-modal-backdrop" id="modalSessionForm" style="display:none;pointer-events:none;visibility:hidden" role="dialog" aria-modal="true" aria-labelledby="modalSessionTitle">
    <div class="bank-modal large">
        <div class="bank-modal-head">
            <h3 id="modalSessionTitle">Pengatur Sesi Ujian Baru</h3>
            <button type="button" class="bank-modal-close" data-close-modal aria-label="Tutup">✕</button>
        </div>
        <form method="post" action="?tab=sessions" id="formSessionModal">
            <input type="hidden" name="action" value="save_session">
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
            <input type="hidden" name="id" id="sesModalId" value="0">
            <div class="bank-modal-body">
                <!-- 1. Identitas Sesi -->
                <div style="display:grid;grid-template-columns:2fr 1fr;gap:14px">
                    <div class="bank-field">
                        <label class="bank-label" for="sesModalTitle">Nama Sesi Ujian *</label>
                        <input id="sesModalTitle" class="bank-input" name="title" maxlength="150" required placeholder="Contoh: Sesi 1 - CBT Tutorial RPL 2026/2027">
                    </div>
                    <div class="bank-field">
                        <label class="bank-label" for="sesModalCode">Kode Sesi</label>
                        <input id="sesModalCode" class="bank-input" name="code" maxlength="64" placeholder="Otomatis jika kosong">
                    </div>
                </div>

                <!-- 1b. Jenis Sesi: Resmi / Tryout -->
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;background:#eff6ff;border:1px solid #bfdbfe;border-radius:12px;padding:12px 14px">
                    <div class="bank-field" style="margin:0">
                        <label class="bank-label" for="sesModalMode" style="color:#1e3a8a">Jenis Sesi *</label>
                        <select id="sesModalMode" class="bank-input" name="mode">
                            <option value="resmi">🎓 Ujian Resmi (1× pengerjaan, terdaftar tutorial)</option>
                            <option value="tryout">📝 Tryout / Latihan (terpisah dari ujian resmi)</option>
                        </select>
                        <span style="font-size:11px;color:#1e40af;display:block;margin-top:4px">Tryout tampil di menu latihan mahasiswa, tanpa verifikasi wajah ketat, dan tidak memakan jatah ujian resmi. Beberapa tryout boleh aktif bersamaan dengan sesi resmi.</span>
                    </div>
                    <div class="bank-field" id="sesModalMaxAttemptsWrap" style="margin:0">
                        <label class="bank-label" for="sesModalMaxAttempts" style="color:#1e3a8a">Batas Pengerjaan Tryout</label>
                        <input type="number" id="sesModalMaxAttempts" class="bank-input" name="max_attempts" min="0" max="99" value="0">
                        <span style="font-size:11px;color:#1e40af;display:block;margin-top:4px">0 = boleh diulang tanpa batas; 1 = sekali saja.</span>
                    </div>
                </div>

                <!-- 2. Paket Soal yang Digunakan -->
                <div class="bank-field">
                    <label class="bank-label">Pilih Paket Soal yang Digunakan *</label>
                    <div style="background:#f8faf8;border:1px solid #dce5df;border-radius:10px;padding:12px;display:flex;gap:14px;flex-wrap:wrap">
                        <?php foreach ($packages as $pkg): ?>
                            <label class="bank-toggle" style="background:#fff;padding:6px 12px;border:1px solid #e2e8e2;border-radius:8px">
                                <input type="checkbox" name="package_ids[]" id="pkgCheckbox_<?= (int)$pkg['id'] ?>" value="<?= (int)$pkg['id'] ?>">
                                <span><strong><?= sanitize($pkg['name']) ?></strong> (<?= (int)($pkg['question_count_actual'] ?? $pkg['question_count']) ?> soal aktif)</span>
                            </label>
                        <?php endforeach; ?>
                        <?php if (empty($packages)): ?>
                            <div style="color:#b91c1c;font-size:12px">Belum ada paket soal dibuat. Buat paket terlebih dahulu.</div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- 3. Kadar / Kuota per Kategori Materi -->
                <div class="bank-field" style="background:#f9fbf9;border:1px solid #dae5dc;border-radius:12px;padding:14px">
                    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px">
                        <div>
                            <label class="bank-label" style="margin:0">Kadar / Kuota pada Masing-Masing Kategori (Proporsi Materi)</label>
                            <span style="font-size:11px;color:#6d7f72">Tentukan jumlah butir soal yang diacak dari masing-masing kategori untuk tiap peserta.</span>
                        </div>
                        <span id="sesModalTotalQuotaBadge" class="bank-badge package" style="font-size:11px;padding:5px 10px">Bebas / Acak Paket</span>
                    </div>
                    <div id="sesModalCategoryQuotasContainer">
                        <div style="padding:12px;color:#8a998f;font-size:12px;text-align:center">Pilih paket soal di atas untuk mengatur kadar kategori.</div>
                    </div>
                </div>

                <!-- 4. Pengaturan Kamera & Waktu -->
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px">
                    <div class="bank-field">
                        <label class="bank-label" for="sesModalCameraMode">Kewajiban Kamera &amp; Proctoring *</label>
                        <select id="sesModalCameraMode" class="bank-input" name="camera_mode" required>
                            <option value="wajib">📷 Wajib Kamera (Verifikasi Scan Wajah + Proctoring 30s)</option>
                            <option value="tidak_aktif">🚫 Bebas / Tanpa Kamera (Langsung Masuk Lembar Soal)</option>
                            <option value="opsional">📷 Kamera Opsional (Bisa Ujian Tanpa Kamera)</option>
                        </select>
                    </div>
                    <div class="bank-field">
                        <label class="bank-label" for="sesModalDuration">Durasi Waktu Ujian (Menit) *</label>
                        <input id="sesModalDuration" class="bank-input" type="number" name="duration_minutes" min="5" max="360" required value="90">
                    </div>
                </div>

                <!-- 5. Cara Menilai & Nilai KKM -->
                <div style="display:grid;grid-template-columns:1.5fr 1fr;gap:14px">
                    <div class="bank-field">
                        <label class="bank-label" for="sesModalScoringMethod">Cara Menilai (Metode Scoring) *</label>
                        <select id="sesModalScoringMethod" class="bank-input" name="scoring_method" required>
                            <option value="standar">Standar Skala 100: (Benar / Total) × 100</option>
                            <option value="penalti">Sistem Minus UTBK: Benar +4, Salah -1, Kosong 0</option>
                        </select>
                    </div>
                    <div class="bank-field">
                        <label class="bank-label" for="sesModalPassingGrade">Ambang Batas Kelulusan (KKM)</label>
                        <input id="sesModalPassingGrade" class="bank-input" type="number" step="0.01" name="passing_grade" min="0" max="100" value="70.00">
                    </div>
                </div>

                <!-- 6. Kapan Ujian Bisa Dimulai & Kapan Tidak Bisa Memulai -->
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px">
                    <div class="bank-field">
                        <label class="bank-label" for="sesModalStartTime">Kapan Ujian Bisa Dimulai (Jadwal Buka)</label>
                        <input id="sesModalStartTime" class="bank-input" type="datetime-local" name="start_time" placeholder="Pilih waktu mulai">
                        <span style="font-size:10.5px;color:#6b7972;margin-top:3px;display:block">Sebelum waktu ini, peserta belum bisa membuka lembar ujian.</span>
                    </div>
                    <div class="bank-field">
                        <label class="bank-label" for="sesModalEndTime">Kapan Batas Tidak Bisa Memulai (Jadwal Tutup)</label>
                        <input id="sesModalEndTime" class="bank-input" type="datetime-local" name="end_time" placeholder="Pilih batas akhir">
                        <span style="font-size:10.5px;color:#6b7972;margin-top:3px;display:block">Setelah waktu ini, pendaftaran ujian sesi ini ditutup bagi peserta baru.</span>
                    </div>
                </div>

                <!-- 7. Token Akses Ujian -->
                <div style="background:#fefce8;border:1px solid #fef08a;border-radius:12px;padding:14px;margin-bottom:14px">
                    <label class="bank-label" style="color:#854d0e">Token Akses Ujian Peserta</label>
                    <div style="display:flex;align-items:center;gap:14px;flex-wrap:wrap">
                        <div class="bank-token-group">
                            <input id="sesModalToken" class="bank-input" name="token" maxlength="32" placeholder="TOKEN">
                            <button type="button" class="bank-button" onclick="generateRandomToken()" title="Acak token 6 karakter baru">🎲 Acak Token</button>
                        </div>
                        <label class="bank-toggle" style="margin:0">
                            <input type="checkbox" name="token_required" id="sesModalTokenRequired" value="1">
                            <strong>Wajibkan Token Ujian</strong> (Peserta wajib memasukkan token ini sebelum memulai CBT)
                        </label>
                    </div>
                </div>

                <!-- 8. Proteksi Anti-Contek & Pertukaran Soal Pelanggaran -->
                <div class="bank-field" style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:12px;padding:14px;margin-bottom:14px">
                    <label class="bank-label" for="sesModalShuffleOnViolation" style="color:#166534;margin-bottom:6px">🛡️ Proteksi Anti-Contek Saat Pelanggaran Terdeteksi</label>
                    <div style="font-size:11.5px;color:#1e3a2b;margin-bottom:8px;line-height:1.5">
                        Tindakan sistem ketika peserta melakukan pelanggaran (ganti tab, jendela diminimalkan, wajah hilang/berbeda):
                    </div>
                    <select id="sesModalShuffleOnViolation" class="bank-input" name="shuffle_on_violation">
                        <option value="1">✅ Aktif: Seluruh soal belum dijawab diganti soal baru dari bank cadangan &amp; abjad opsi diacak (Anti-Joki/Bocoran)</option>
                        <option value="0">❌ Nonaktif: Posisi soal hanya dipindah dan abjad diacak tanpa mengambil soal cadangan</option>
                    </select>
                    <span style="font-size:11px;color:#4b6354;margin-top:5px;display:block">
                        💡 <strong>Sangat Dianjurkan</strong> bila memiliki bank soal cadangan (misal: bank soal 177 soal, dikerjakan 25 soal). Soal yang sudah dijawab peserta tetap aman dan skor tidak berkurang, hanya susunan abjadnya disamarkan ulang.
                    </span>
                </div>

                <!-- 9. Deskripsi & Status Aktif -->
                <div class="bank-field">
                    <label class="bank-label" for="sesModalDesc">Deskripsi / Petunjuk untuk Peserta Sesi Ini</label>
                    <textarea id="sesModalDesc" class="bank-input" name="description" rows="2" placeholder="Keterangan pengawas, ruang lab, atau catatan khusus…"></textarea>
                </div>
                <label class="bank-toggle">
                    <input type="checkbox" name="is_active" id="sesModalActive" value="1" checked>
                    <strong>Jadikan Sesi Aktif Saat Ini</strong> (Peserta tutorial RPL yang login otomatis diarahkan ke sesi ini)
                </label>
                <input type="hidden" name="exam_open" value="0">
                <label class="bank-toggle" id="sesModalExamOpenWrap" style="margin-top:8px">
                    <input type="checkbox" name="exam_open" id="sesModalExamOpen" value="1">
                    <strong>Buka Pengerjaan Ujian</strong> (peserta dapat menekan "Mulai Ujian CBT"; tetap mengikuti jadwal mulai/batas di atas). Bisa juga diatur dengan tombol 🟢 Buka / 🔴 Tutup Ujian.
                </label>
            </div>
            <div class="bank-modal-foot">
                <button type="button" class="bank-button" data-close-modal>Batal</button>
                <button type="submit" class="bank-button primary">💾 Simpan Sesi Ujian</button>
            </div>
        </form>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════════════════
     MODAL TAMBAH / EDIT PAKET SOAL
     ═══════════════════════════════════════════════════════════════════════════ -->
<div class="bank-modal-backdrop" id="packageModalBackdrop" style="display:none;pointer-events:none;visibility:hidden" role="dialog" aria-modal="true" aria-labelledby="packageModalTitle">
    <div class="bank-modal" style="max-width:620px;">
        <div class="bank-modal-head" style="background:#eff6ff;">
            <div>
                <h3 id="packageModalTitle" style="color:#1e40af;">📦 Buat Paket Soal Baru</h3>
                <div style="font-size:12px; color:#475569; margin-top:2px;">Wadah materi butir soal ujian tulis Tutorial RPL</div>
            </div>
            <button type="button" class="bank-modal-close" onclick="closePackageModal();">✕</button>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="save_package">
            <input type="hidden" name="package_id" id="pkgModalId" value="0">
            <div class="bank-modal-body" style="padding:20px;">
                <div style="display:grid; grid-template-columns:1fr 1fr; gap:14px; margin-bottom:14px;">
                    <div class="bank-field">
                        <label class="bank-label">Kode Paket *</label>
                        <input type="text" name="code" id="pkgModalCode" class="bank-input" placeholder="Cth: PKT-TUTOR-UTAMA" required>
                    </div>
                    <div class="bank-field">
                        <label class="bank-label">Nama Paket Soal *</label>
                        <input type="text" name="name" id="pkgModalName" class="bank-input" placeholder="Cth: Paket 1 - Utama (Muslim)" required>
                    </div>
                </div>
                <div class="bank-field" style="margin-bottom:14px;">
                    <label class="bank-label">Deskripsi Materi Paket</label>
                    <textarea name="description" id="pkgModalDesc" class="bank-input" rows="2" placeholder="Cth: Materi Fiqih, Ubudiyah, Thaharah, Sholat Wajib..."></textarea>
                </div>
                <div style="display:grid; grid-template-columns:1fr 1fr; gap:14px; margin-bottom:14px;">
                    <div class="bank-field">
                        <label class="bank-label">Durasi Paket (Menit) *</label>
                        <input type="number" name="duration_minutes" id="pkgModalDuration" class="bank-input" value="90" min="5" max="300" required>
                    </div>
                    <div class="bank-field">
                        <label class="bank-label">Target Butir Soal *</label>
                        <input type="number" name="question_count" id="pkgModalQuestionCount" class="bank-input" value="25" min="1" max="200" required>
                    </div>
                </div>
                <label class="bank-toggle" style="cursor:pointer; margin-top:8px;">
                    <input type="checkbox" name="is_active" id="pkgModalIsActive" value="1" checked>
                    <div>
                        <strong>Aktifkan Paket Soal Ini</strong>
                        <div class="bank-note">Paket aktif dapat dipilih dan digunakan pada sesi ujian tutorial.</div>
                    </div>
                </label>
            </div>
            <div class="bank-modal-foot">
                <button type="button" class="bank-button" onclick="closePackageModal();">Batal</button>
                <button type="submit" class="bank-button primary">💾 Simpan Paket Soal</button>
            </div>
        </form>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════════════════
     MODAL KOLEKSI & KELOLA SOAL PAKET (Identik dengan CBT Pretes)
     ═══════════════════════════════════════════════════════════════════════════ -->
<div class="bank-modal-backdrop" id="modalPackageQuestions" style="display:none;pointer-events:none;visibility:hidden" role="dialog" aria-modal="true" aria-labelledby="pkgQuestionsTitle">
    <div class="bank-modal extra-large" style="max-width:920px; width:95%; max-height:90vh; display:flex; flex-direction:column; padding:0; overflow:hidden;">
        <div class="modal-header-banner" style="background:linear-gradient(135deg, #0f766e 0%, #115e59 100%); color:#ffffff; padding:16px 20px; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px;">
            <div>
                <h3 style="margin:0; font-size:17px; color:#ffffff; display:flex; align-items:center; gap:8px;">
                    📋 Koleksi Soal — <span id="pkgQuestionsTitle">Paket Soal</span>
                </h3>
                <div style="font-size:12px; opacity:0.95; margin-top:4px; display:flex; align-items:center; gap:8px;">
                    <span id="pkgQuestionsCodeBadge" class="bank-code" style="background:rgba(255,255,255,0.2); color:#ffffff; border-color:rgba(255,255,255,0.3);"></span>
                    <span id="pkgQuestionsCountBadge" style="font-weight:600;">Memuat butir soal...</span>
                </div>
            </div>
            <div style="display:flex; align-items:center; gap:8px; flex-wrap:wrap;">
                <button type="button" class="bank-button gold" id="btnModalAddQuestion" style="min-height:34px; font-size:12px; font-weight:700;">
                    ＋ Tambah Soal Baru
                </button>
                <a class="bank-button" id="btnModalImportExcel" href="?tab=import" style="min-height:34px; font-size:12px; background:rgba(255,255,255,0.15); color:#ffffff; border-color:rgba(255,255,255,0.3);">
                    📥 Impor Excel
                </a>
                <a class="bank-button" id="btnModalExportExcel" href="?download=bank" style="min-height:34px; font-size:12px; background:rgba(255,255,255,0.15); color:#ffffff; border-color:rgba(255,255,255,0.3);">
                    📤 Ekspor .xlsx
                </a>
                <button type="button" class="bank-modal-close" onclick="closePackageQuestionsModal();" aria-label="Tutup" style="color:#ffffff; font-size:20px; margin-left:4px;">✕</button>
            </div>
        </div>

        <div class="modal-q-toolbar" style="padding:12px 20px; background:#f8fafc; border-bottom:1px solid #e2e8f0; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px;">
            <div class="modal-q-toolbar-left" style="display:flex; gap:10px; flex-wrap:wrap; flex:1;">
                <input id="modalQSearch" class="bank-input" type="search" placeholder="🔍 Cari butir pertanyaan, kode, kategori…" style="max-width:280px; padding:7px 12px;" oninput="filterModalQuestions();">
                <select id="modalQCategory" class="bank-input" style="max-width:180px; padding:7px 12px;" onchange="filterModalQuestions();">
                    <option value="">Semua Kategori</option>
                </select>
                <select id="modalQStatus" class="bank-input" style="max-width:140px; padding:7px 12px;" onchange="filterModalQuestions();">
                    <option value="">Semua Status</option>
                    <option value="aktif">Aktif</option>
                    <option value="nonaktif">Nonaktif</option>
                </select>
            </div>
            <span id="modalQCountBadge" style="font-size:12px; color:#64748b; font-weight:600;"></span>
        </div>

        <div class="modal-q-list" id="modalQListContainer" style="flex:1; overflow-y:auto; padding:20px; max-height:58vh;">
            <!-- Dynamic question cards rendered by JS -->
        </div>

        <div class="bank-modal-foot" style="justify-content:space-between; padding:12px 20px; background:#f8fafc; border-top:1px solid #e2e8f0;">
            <div style="font-size:12px; color:#64748b;">
                💡 <em>Pilihan jawaban yang berlatar hijau adalah <strong>Kunci Jawaban</strong> yang benar.</em>
            </div>
            <button type="button" class="bank-button" onclick="closePackageQuestionsModal();">Tutup Jendela</button>
        </div>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════════════════
     MODAL TAMBAH / EDIT BUTIR SOAL
     ═══════════════════════════════════════════════════════════════════════════ -->
<div class="bank-modal-backdrop" id="questionModalBackdrop" style="display:none;pointer-events:none;visibility:hidden" role="dialog" aria-modal="true" aria-labelledby="modalTitle">
    <div class="bank-modal large">
        <div class="bank-modal-head">
            <h3 id="modalTitle">➕ Tambah Butir Soal Baru</h3>
            <button type="button" class="bank-modal-close" onclick="closeQuestionModal();">✕</button>
        </div>
        <form method="POST" id="questionForm">
            <input type="hidden" name="action" value="save_question">
            <input type="hidden" name="question_id" id="formQuestionId" value="0">
            <div class="bank-modal-body">
                <div style="display:grid; grid-template-columns:1fr 1fr; gap:16px;">
                    <div class="bank-field">
                        <label class="bank-label">Paket Soal:</label>
                        <select name="package_id" id="formPackageId" class="bank-input" required style="font-weight:700;">
                            <option value="1">📦 Paket 1: Utama (Ubudiyah / Muslim)</option>
                            <option value="2">📦 Paket 2: Kristen Protestan</option>
                        </select>
                    </div>
                    <div class="bank-field">
                        <label class="bank-label">Kategori Materi:</label>
                        <input type="text" name="category" id="formCategory" class="bank-input" placeholder="Contoh: Thaharah, Sholat Wajib, Ubudiyah Praktis" required>
                    </div>
                </div>

                <div class="bank-field">
                    <label class="bank-label">Teks Pertanyaan Soal:</label>
                    <textarea name="question_text" id="formQuestionText" class="bank-input" rows="4" placeholder="Tuliskan pertanyaan butir soal di sini..." required style="resize:vertical;"></textarea>
                </div>

                <div class="bank-options">
                    <div>
                        <label class="bank-option-label"><span class="bank-letter">A</span> Pilihan A *</label>
                        <input type="text" name="opt_a" id="formOptA" class="bank-input" placeholder="Pilihan jawaban A" required>
                    </div>
                    <div>
                        <label class="bank-option-label"><span class="bank-letter">B</span> Pilihan B *</label>
                        <input type="text" name="opt_b" id="formOptB" class="bank-input" placeholder="Pilihan jawaban B" required>
                    </div>
                    <div>
                        <label class="bank-option-label"><span class="bank-letter">C</span> Pilihan C</label>
                        <input type="text" name="opt_c" id="formOptC" class="bank-input" placeholder="Pilihan jawaban C">
                    </div>
                    <div>
                        <label class="bank-option-label"><span class="bank-letter">D</span> Pilihan D</label>
                        <input type="text" name="opt_d" id="formOptD" class="bank-input" placeholder="Pilihan jawaban D">
                    </div>
                    <div style="grid-column: 1 / -1;">
                        <label class="bank-option-label"><span class="bank-letter">E</span> Pilihan E</label>
                        <input type="text" name="opt_e" id="formOptE" class="bank-input" placeholder="Pilihan jawaban E">
                    </div>
                </div>

                <div class="bank-field" style="margin-top:16px;">
                    <label class="bank-label">Kunci Jawaban Benar:</label>
                    <select name="correct_answer" id="formCorrectAnswer" class="bank-input" required style="font-weight:800; max-width:200px;">
                        <option value="A">A</option>
                        <option value="B">B</option>
                        <option value="C">C</option>
                        <option value="D">D</option>
                        <option value="E">E</option>
                    </select>
                </div>
            </div>
            <div class="bank-modal-foot">
                <button type="button" class="bank-button" onclick="closeQuestionModal();">Batal</button>
                <button type="submit" class="bank-button primary">💾 Simpan Butir Soal</button>
            </div>
        </form>
    </div>
</div>

<script>
window.CBT_PACKAGE_CATEGORIES = <?= json_encode($packageCategoriesMap, JSON_UNESCAPED_UNICODE) ?>;
window.PACKAGES_DATA = <?= json_encode($packages, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>;
window.SESSIONS_DATA = <?= json_encode($sessions, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>;

window.openEditPackageModalById = function (packageId) {
    packageId = parseInt(packageId, 10);
    const pkg = (window.PACKAGES_DATA || []).find(p => parseInt(p.id, 10) === packageId);
    if (pkg) {
        openEditPackageModal(pkg);
    }
};

window.openEditSessionModalById = function (sessionId) {
    sessionId = parseInt(sessionId, 10);
    const ses = (window.SESSIONS_DATA || []).find(s => parseInt(s.id, 10) === sessionId);
    if (ses) {
        window.openSessionModal(true, ses);
    }
};

function get(id) { return document.getElementById(id); }

function cbtOpenModal(id) {
    const modal = typeof id === 'string' ? get(id) : id;
    if (modal) {
        modal.style.display = 'flex';
        modal.style.pointerEvents = 'auto';
        modal.style.visibility = 'visible';
        modal.classList.add('open');
        document.body.style.overflow = 'hidden';
        const firstInput = modal.querySelector('input:not([type=hidden]), textarea, select');
        if (firstInput) setTimeout(() => firstInput.focus(), 80);
    }
}

function cbtCloseModal(id) {
    const modal = typeof id === 'string' ? get(id) : id;
    if (modal) {
        modal.classList.remove('open');
        modal.style.display = 'none';
        modal.style.pointerEvents = 'none';
        modal.style.visibility = 'hidden';
        if (!document.querySelector('.bank-modal-backdrop.open')) {
            document.body.style.overflow = '';
        }
    }
}
window.cbtOpenModal = cbtOpenModal;
window.cbtCloseModal = cbtCloseModal;

window.generateRandomToken = function () {
    const chars = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';
    let res = '';
    for (let i = 0; i < 6; i++) {
        res += chars.charAt(Math.floor(Math.random() * chars.length));
    }
    const tokenInput = get('sesModalToken');
    if (tokenInput) {
        tokenInput.value = res;
        tokenInput.focus();
    }
};

function recalculateTotalQuota() {
    const inputs = document.querySelectorAll('.category-quota-input');
    let total = 0;
    inputs.forEach(input => {
        const val = parseInt(input.value, 10);
        if (!isNaN(val) && val > 0) total += val;
    });
    const badge = get('sesModalTotalQuotaBadge');
    if (badge) {
        badge.textContent = total > 0 ? (total + ' soal target') : 'Bebas / Acak Paket';
    }
}

window.refreshCategoryQuotasForSelectedPackages = function (existingQuotas) {
    const checkedBoxes = Array.from(document.querySelectorAll('input[name="package_ids[]"]:checked'));
    const pkgIds = checkedBoxes.map(cb => cb.value);
    const container = get('sesModalCategoryQuotasContainer');
    if (!container) return;

    if (pkgIds.length === 0) {
        container.innerHTML = '<div style="padding:12px;color:#8a998f;font-size:12px;text-align:center;">Pilih minimal 1 paket soal di atas untuk menampilkan daftar kategori materi.</div>';
        recalculateTotalQuota();
        return;
    }

    const map = window.CBT_PACKAGE_CATEGORIES || {};
    const combinedCategories = {};
    pkgIds.forEach(id => {
        const list = map[id] || [];
        list.forEach(item => {
            const cat = item.category;
            if (!combinedCategories[cat]) {
                combinedCategories[cat] = { active_count: 0, total: 0 };
            }
            combinedCategories[cat].active_count += Number(item.active_count || 0);
            combinedCategories[cat].total += Number(item.total || 0);
        });
    });

    const catNames = Object.keys(combinedCategories).sort();
    if (catNames.length === 0) {
        container.innerHTML = '<div style="padding:12px;color:#8a998f;font-size:12px;text-align:center;">Belum ada butir soal dalam paket terpilih. Kuota kategori akan menggunakan acak umum.</div>';
        recalculateTotalQuota();
        return;
    }

    let html = '<table class="bank-quota-table"><thead><tr><th>Kategori Materi</th><th style="width:110px">Soal Aktif</th><th style="width:140px">Kadar / Kuota Soal</th></tr></thead><tbody>';
    catNames.forEach(cat => {
        const info = combinedCategories[cat];
        const currentVal = (existingQuotas && existingQuotas[cat] !== undefined) ? existingQuotas[cat] : '';
        html += '<tr>';
        html += '<td><strong>' + String(cat).replace(/</g, '&lt;') + '</strong></td>';
        html += '<td><span class="bank-badge">' + info.active_count + ' soal</span></td>';
        html += '<td><input type="number" class="bank-input category-quota-input" name="category_quotas[' + String(cat).replace(/"/g, '&quot;') + ']" min="0" max="' + info.active_count + '" placeholder="0" value="' + currentVal + '" style="padding:6px 10px;font-size:12px"></td>';
        html += '</tr>';
    });
    html += '</tbody></table>';
    container.innerHTML = html;

    container.querySelectorAll('.category-quota-input').forEach(inp => {
        inp.addEventListener('input', recalculateTotalQuota);
    });
    recalculateTotalQuota();
};

function syncSessionModeFields() {
    const mode = get('sesModalMode') ? get('sesModalMode').value : 'resmi';
    const wrap = get('sesModalMaxAttemptsWrap');
    if (wrap) wrap.style.opacity = mode === 'tryout' ? '1' : '0.45';
    if (get('sesModalMaxAttempts')) get('sesModalMaxAttempts').disabled = mode !== 'tryout';
    const examWrap = get('sesModalExamOpenWrap');
    if (examWrap) examWrap.style.display = mode === 'tryout' ? 'none' : '';
}

window.openSessionModal = function (isEdit, data) {
    const titleEl = get('modalSessionTitle');
    const form = get('formSessionModal');
    if (!form) return;
    form.reset();

    document.querySelectorAll('input[name="package_ids[]"]').forEach(cb => { cb.checked = false; });

    if (isEdit && data) {
        if (titleEl) titleEl.textContent = 'Edit Sesi Ujian: ' + (data.title || '');
        if (get('sesModalId')) get('sesModalId').value = data.id || 0;
        if (get('sesModalCode')) {
            get('sesModalCode').value = data.code || '';
            get('sesModalCode').readOnly = true;
        }
        if (get('sesModalTitle')) get('sesModalTitle').value = data.title || '';
        if (get('sesModalDesc')) get('sesModalDesc').value = data.description || '';
        if (get('sesModalDuration')) get('sesModalDuration').value = data.duration_minutes || 90;
        if (get('sesModalCameraMode')) get('sesModalCameraMode').value = data.camera_mode || 'wajib';
        if (get('sesModalScoringMethod')) get('sesModalScoringMethod').value = data.scoring_method || 'standar';
        if (get('sesModalPassingGrade')) get('sesModalPassingGrade').value = data.passing_grade || '70.00';
        if (get('sesModalStartTime')) get('sesModalStartTime').value = data.start_time ? data.start_time.replace(' ', 'T') : '';
        if (get('sesModalEndTime')) get('sesModalEndTime').value = data.end_time ? data.end_time.replace(' ', 'T') : '';
        if (get('sesModalToken')) get('sesModalToken').value = data.token || '';
        if (get('sesModalTokenRequired')) get('sesModalTokenRequired').checked = Number(data.token_required) === 1;
        if (get('sesModalShuffleOnViolation')) {
            get('sesModalShuffleOnViolation').value = data.shuffle_on_violation !== undefined ? String(data.shuffle_on_violation) : '1';
        }
        if (get('sesModalActive')) get('sesModalActive').checked = Number(data.is_active) === 1;
        if (get('sesModalMode')) get('sesModalMode').value = data.mode === 'tryout' ? 'tryout' : 'resmi';
        if (get('sesModalMaxAttempts')) get('sesModalMaxAttempts').value = data.mode === 'tryout' ? String(Number(data.max_attempts) || 0) : '0';
        if (get('sesModalExamOpen')) get('sesModalExamOpen').checked = Number(data.exam_open) === 1;
        syncSessionModeFields();

        let pkgIds = data.package_ids || [];
        if (typeof pkgIds === 'string') {
            try { pkgIds = JSON.parse(pkgIds); } catch(e) { pkgIds = []; }
        }
        pkgIds.forEach(id => {
            const cb = get('pkgCheckbox_' + id);
            if (cb) cb.checked = true;
        });
        window.refreshCategoryQuotasForSelectedPackages(data.category_quotas || {});
    } else {
        if (titleEl) titleEl.textContent = 'Buat Sesi Ujian Baru';
        if (get('sesModalId')) get('sesModalId').value = 0;
        if (get('sesModalCode')) {
            get('sesModalCode').value = '';
            get('sesModalCode').readOnly = false;
        }
        if (get('sesModalTitle')) get('sesModalTitle').value = '';
        if (get('sesModalDesc')) get('sesModalDesc').value = '';
        if (get('sesModalDuration')) get('sesModalDuration').value = 90;
        if (get('sesModalCameraMode')) get('sesModalCameraMode').value = 'wajib';
        if (get('sesModalScoringMethod')) get('sesModalScoringMethod').value = 'standar';
        if (get('sesModalPassingGrade')) get('sesModalPassingGrade').value = '70.00';
        if (get('sesModalStartTime')) get('sesModalStartTime').value = '';
        if (get('sesModalEndTime')) get('sesModalEndTime').value = '';
        window.generateRandomToken();
        if (get('sesModalTokenRequired')) get('sesModalTokenRequired').checked = false;
        if (get('sesModalShuffleOnViolation')) {
            get('sesModalShuffleOnViolation').value = '1';
        }
        if (get('sesModalActive')) get('sesModalActive').checked = true;
        if (get('sesModalMode')) get('sesModalMode').value = window._cbtNewSessionMode === 'tryout' ? 'tryout' : 'resmi';
        if (get('sesModalMaxAttempts')) get('sesModalMaxAttempts').value = '0';
        if (get('sesModalExamOpen')) get('sesModalExamOpen').checked = false;
        window._cbtNewSessionMode = null;
        syncSessionModeFields();

        const firstCb = document.querySelector('input[name="package_ids[]"]');
        if (firstCb) firstCb.checked = true;
        window.refreshCategoryQuotasForSelectedPackages({});
    }

    cbtOpenModal('modalSessionForm');
};

// Backward-compatibility aliases
function openEditSessionModal(s) { window.openSessionModal(true, s); }
function closeSessionModal() { cbtCloseModal('modalSessionForm'); }
function generateRandomSessionToken() { window.generateRandomToken(); }

// Bulk selection handler for sessions
function bulkItems(kind) {
    const form = document.querySelector('[data-bulk-delete-form="' + kind + '"]');
    return form ? Array.from(form.elements).filter(item => item.matches('[data-bulk-select-item="' + kind + '"]') && !item.disabled) : [];
}
function refreshBulkSelection(kind) {
    const items = bulkItems(kind);
    const checked = items.filter(item => item.checked);
    const toolbar = document.querySelector('[data-bulk-toolbar="' + kind + '"]');
    const count = document.querySelector('[data-bulk-count="' + kind + '"]');
    const selectAll = document.querySelector('[data-bulk-select-all="' + kind + '"]');
    if (toolbar) {
        toolbar.hidden = checked.length === 0;
        toolbar.style.display = checked.length ? 'flex' : 'none';
    }
    if (count) count.textContent = checked.length + ' sesi dipilih';
    if (selectAll) {
        selectAll.checked = items.length > 0 && checked.length === items.length;
        selectAll.indeterminate = checked.length > 0 && checked.length < items.length;
        selectAll.disabled = items.length === 0;
    }
}
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function () { refreshBulkSelection('sessions'); }, { once: true });
} else {
    refreshBulkSelection('sessions');
}

document.addEventListener('click', function (e) {
    const closeBtn = e.target.closest('[data-close-modal]');
    if (closeBtn) {
        const backdrop = closeBtn.closest('.bank-modal-backdrop');
        if (backdrop) cbtCloseModal(backdrop);
        return;
    }
    if (e.target.classList && e.target.classList.contains('bank-modal-backdrop')) {
        cbtCloseModal(e.target);
        return;
    }
    const btnCreateSes = e.target.closest('[data-action="create-session"]');
    if (btnCreateSes) {
        e.preventDefault();
        window._cbtNewSessionMode = 'resmi';
        window.openSessionModal(false);
        return;
    }
    const btnCreateTryout = e.target.closest('[data-action="create-tryout"]');
    if (btnCreateTryout) {
        e.preventDefault();
        window._cbtNewSessionMode = 'tryout';
        window.openSessionModal(false);
        return;
    }
    const btnEditPkg = e.target.closest('[data-action="edit-package"]');
    if (btnEditPkg) {
        e.preventDefault();
        const pkgId = parseInt(btnEditPkg.getAttribute('data-package-id') || '0', 10);
        if (pkgId > 0) window.openEditPackageModalById(pkgId);
        return;
    }
    const btnEditSes = e.target.closest('[data-action="edit-session"]');
    if (btnEditSes) {
        e.preventDefault();
        const sesId = parseInt(btnEditSes.getAttribute('data-session-id') || '0', 10);
        if (sesId > 0) window.openEditSessionModalById(sesId);
        return;
    }
});

document.addEventListener('change', function (event) {
    const selectAll = event.target.closest('[data-bulk-select-all]');
    if (selectAll) {
        const kind = selectAll.dataset.bulkSelectAll;
        bulkItems(kind).forEach(item => { item.checked = selectAll.checked; });
        refreshBulkSelection(kind);
        return;
    }
    const item = event.target.closest('[data-bulk-select-item]');
    if (item) {
        refreshBulkSelection(item.dataset.bulkSelectItem);
        return;
    }
    if (event.target && event.target.name === 'package_ids[]') {
        window.refreshCategoryQuotasForSelectedPackages();
    }
    if (event.target && event.target.id === 'sesModalMode') {
        syncSessionModeFields();
    }
});

document.addEventListener('submit', function (event) {
    const form = event.target.closest('[data-bulk-delete-form]');
    if (!form) return;
    const kind = form.dataset.bulkDeleteForm;
    const checked = bulkItems(kind).filter(item => item.checked);
    if (!checked.length) {
        event.preventDefault();
        return;
    }
    const labels = checked.map(item => item.dataset.itemLabel || ('#' + item.value)).slice(0, 5).join('\n• ');
    const more = checked.length > 5 ? ('\n...dan ' + (checked.length - 5) + ' sesi lainnya.') : '';
    if (!confirm('PERINGATAN: Hapus permanen ' + checked.length + ' sesi ujian terpilih?\n\n• ' + labels + more)) {
        event.preventDefault();
    }
});

// Filter Bank Soal
function filterQuestions() {
    const pkg = document.getElementById('filterPackage') ? document.getElementById('filterPackage').value : '';
    const cat = document.getElementById('filterCategory') ? document.getElementById('filterCategory').value : '';
    const q = document.getElementById('searchQuestion') ? document.getElementById('searchQuestion').value.toLowerCase().trim() : '';
    const rows = document.querySelectorAll('.question-row');

    rows.forEach(r => {
        const rowPkg = r.getAttribute('data-package') || '';
        const rowCat = r.getAttribute('data-category') || '';
        const rowText = r.getAttribute('data-text') || '';
        const matchPkg = (!pkg || rowPkg === pkg);
        const matchCat = (!cat || rowCat === cat);
        const matchQ = (!q || rowText.includes(q));
        r.style.display = (matchPkg && matchCat && matchQ) ? '' : 'none';
    });
}

// Filter Riwayat Attempt
function filterAttempts() {
    const search = document.getElementById('searchAttempt') ? document.getElementById('searchAttempt').value.toLowerCase().trim() : '';
    const pkg = document.getElementById('filterPackageAttempt') ? document.getElementById('filterPackageAttempt').value : '';
    const pass = document.getElementById('filterPassStatus') ? document.getElementById('filterPassStatus').value : '';
    const grp = document.getElementById('filterClassGroup') ? document.getElementById('filterClassGroup').value : '';
    const rows = document.querySelectorAll('.attempt-row');

    rows.forEach(r => {
        const s = r.getAttribute('data-search') || '';
        const p = r.getAttribute('data-pass') || '';
        const g = r.getAttribute('data-group') || '';
        const rPkg = r.getAttribute('data-package') || '';

        const matchS = (!search || s.includes(search));
        const matchPkg = (!pkg || rPkg === pkg);
        const matchP = (!pass || p === pass);
        const matchG = (!grp || g === grp);

        r.style.display = (matchS && matchPkg && matchP && matchG) ? '' : 'none';
    });
}

// Modal Paket Soal
function openPackageModal() {
    document.getElementById('packageModalTitle').textContent = '📦 Buat Paket Soal Baru';
    document.getElementById('pkgModalId').value = '0';
    document.getElementById('pkgModalCode').value = '';
    document.getElementById('pkgModalName').value = '';
    document.getElementById('pkgModalDesc').value = '';
    document.getElementById('pkgModalDuration').value = '90';
    document.getElementById('pkgModalQuestionCount').value = '25';
    document.getElementById('pkgModalIsActive').checked = true;
    const el = document.getElementById('packageModalBackdrop');
    if (el) {
        el.style.display = 'flex';
        el.style.pointerEvents = 'auto';
        el.style.visibility = 'visible';
        el.classList.add('open');
    }
}

function openEditPackageModal(pkg) {
    document.getElementById('packageModalTitle').textContent = '✏️ Edit Paket Soal (' + (pkg.code || 'ID: ' + pkg.id) + ')';
    document.getElementById('pkgModalId').value = pkg.id;
    document.getElementById('pkgModalCode').value = pkg.code || '';
    document.getElementById('pkgModalName').value = pkg.name || '';
    document.getElementById('pkgModalDesc').value = pkg.description || '';
    document.getElementById('pkgModalDuration').value = pkg.duration_minutes || 90;
    document.getElementById('pkgModalQuestionCount').value = pkg.question_count || 25;
    document.getElementById('pkgModalIsActive').checked = (parseInt(pkg.is_active, 10) === 1);
    const el = document.getElementById('packageModalBackdrop');
    if (el) {
        el.style.display = 'flex';
        el.style.pointerEvents = 'auto';
        el.style.visibility = 'visible';
        el.classList.add('open');
    }
}

function closePackageModal() {
    const el = document.getElementById('packageModalBackdrop');
    if (el) {
        el.classList.remove('open');
        el.style.display = 'none';
        el.style.pointerEvents = 'none';
        el.style.visibility = 'hidden';
    }
}

// ── Package Questions Manager Modal (Paritas Penuh dengan CBT Pretes) ───
window._currentPackageQuestions = [];
window._currentPackageId = 0;
window._currentPackageName = '';
window._currentPackageCode = '';

function openPackageQuestionsModal(packageId, packageName, packageCode) {
    window._currentPackageId = packageId;
    window._currentPackageName = packageName || 'Semua Bank Soal';
    window._currentPackageCode = packageCode || 'SEMUA';

    const titleEl = document.getElementById('pkgQuestionsTitle');
    const codeBadge = document.getElementById('pkgQuestionsCodeBadge');
    const countBadge = document.getElementById('pkgQuestionsCountBadge');
    const container = document.getElementById('modalQListContainer');

    if (titleEl) titleEl.textContent = window._currentPackageName;
    if (codeBadge) codeBadge.textContent = window._currentPackageCode;
    if (countBadge) countBadge.textContent = 'Memuat butir soal…';

    const btnAdd = document.getElementById('btnModalAddQuestion');
    if (btnAdd) {
        btnAdd.onclick = () => openAddModal(packageId);
    }
    const btnExport = document.getElementById('btnModalExportExcel');
    if (btnExport) {
        btnExport.href = '?download=bank&package_id=' + packageId;
    }
    const btnImport = document.getElementById('btnModalImportExcel');
    if (btnImport) {
        btnImport.href = '?tab=import&package_id=' + packageId;
    }

    if (container) {
        container.innerHTML = '<div style="padding:40px;text-align:center;color:#64748b;"><div style="font-size:24px;margin-bottom:8px">⏳</div>Memuat butir pertanyaan…</div>';
    }

    const modal = document.getElementById('modalPackageQuestions');
    if (modal) {
        modal.style.display = 'flex';
        modal.style.pointerEvents = 'auto';
        modal.style.visibility = 'visible';
        modal.classList.add('open');
    }

    // Sinkronkan filter di tabel halaman utama
    const select = document.getElementById('filterPackage');
    if (select) {
        select.value = String(packageId);
        filterQuestions();
    }
    const label = document.getElementById('panelQuestionsTitle');
    if (label) {
        label.textContent = 'Daftar Butir Soal CBT: ' + window._currentPackageName;
    }

    fetch('?ajax=package_questions&package_id=' + (packageId || 0))
        .then(r => r.json())
        .then(data => {
            if (data && data.success) {
                window._currentPackageQuestions = data.questions || [];
                if (countBadge) {
                    countBadge.textContent = window._currentPackageQuestions.length + ' butir soal';
                }
                const catSelect = document.getElementById('modalQCategory');
                if (catSelect) {
                    const cats = Array.from(new Set(window._currentPackageQuestions.map(q => q.category).filter(Boolean))).sort();
                    let catHtml = '<option value="">Semua Kategori</option>';
                    cats.forEach(c => {
                        catHtml += '<option value="' + String(c).replace(/"/g, '&quot;') + '">' + String(c).replace(/</g, '&lt;') + '</option>';
                    });
                    catSelect.innerHTML = catHtml;
                }
                renderModalQuestions('', '', '');
            } else {
                if (container) container.innerHTML = '<div style="padding:30px;color:#b91c1c;text-align:center;">Gagal memuat soal: ' + (data.message || 'Terjadi kesalahan') + '</div>';
            }
        })
        .catch(err => {
            if (container) container.innerHTML = '<div style="padding:30px;color:#b91c1c;text-align:center;">Gagal memuat soal: ' + err.message + '</div>';
        });
}

function closePackageQuestionsModal() {
    const modal = document.getElementById('modalPackageQuestions');
    if (modal) {
        modal.classList.remove('open');
        modal.style.display = 'none';
        modal.style.pointerEvents = 'none';
        modal.style.visibility = 'hidden';
    }
}

function renderModalQuestions(query, catFilter, statusFilter) {
    const container = document.getElementById('modalQListContainer');
    const countBadge = document.getElementById('modalQCountBadge');
    if (!container) return;

    query = (query || '').toLowerCase().trim();
    catFilter = catFilter || '';
    statusFilter = statusFilter || '';

    const list = window._currentPackageQuestions || [];
    const filtered = list.filter(q => {
        const matchQ = !query ||
            (q.question_text || '').toLowerCase().includes(query) ||
            (q.code || '').toLowerCase().includes(query) ||
            (q.category || '').toLowerCase().includes(query);
        const matchCat = !catFilter || q.category === catFilter;
        const matchStat = !statusFilter || (statusFilter === 'aktif' ? Number(q.is_active) === 1 : Number(q.is_active) === 0);
        return matchQ && matchCat && matchStat;
    });

    if (countBadge) {
        countBadge.textContent = filtered.length + ' dari ' + list.length + ' soal';
    }

    if (filtered.length === 0) {
        container.innerHTML = `
            <div style="padding:40px 20px;text-align:center;color:#64748b;background:#f8fafc;border-radius:12px;border:1.5px dashed #cbd5e1;">
                <div style="font-size:32px;margin-bottom:8px">📭</div>
                <strong style="font-size:15px;color:#334155;display:block;margin-bottom:4px">Tidak Ada Butir Soal Ditemukan</strong>
                <p style="font-size:13px;margin:0 0 14px 0">Belum ada butir soal atau tidak cocok dengan filter pencarian.</p>
                <button type="button" class="bank-button primary" onclick="openAddModal(window._currentPackageId)">＋ Tambah Soal ke Paket Ini</button>
            </div>
        `;
        return;
    }

    let html = '';
    filtered.forEach((q, idx) => {
        const isActive = Number(q.is_active) === 1;
        let opts = q.options || [];
        if (typeof opts === 'string') {
            try { opts = JSON.parse(opts); } catch(e) { opts = []; }
        }
        const qJson = JSON.stringify(q).replace(/"/g, '&quot;');

        html += '<div class="bank-qcard" id="qcard_' + q.id + '">';
        html += '<div class="bank-qcard-head">';
        html += '<div class="bank-qcard-meta">';
        html += '<span class="bank-qcard-num">#' + (idx + 1) + '</span>';
        html += '<span class="bank-code">' + String(q.code || '').replace(/</g, '&lt;') + '</span>';
        html += '<span class="bank-badge package">' + String(q.category || 'Umum').replace(/</g, '&lt;') + '</span>';
        if (isActive) {
            html += '<span class="bank-badge active">● Aktif</span>';
        } else {
            html += '<span class="bank-badge inactive">Nonaktif</span>';
        }
        html += '</div>';
        html += '<div style="display:flex;gap:6px">';
        html += '<button type="button" class="bank-button" style="min-height:26px;padding:3px 8px;font-size:11px" onclick=\'openEditModal(' + qJson + ')\'>✏️ Edit</button>';
        html += '<button type="button" class="bank-button danger-sm" style="min-height:26px;padding:3px 8px;font-size:11px" onclick="deleteModalQuestion(' + q.id + ')">🗑️ Hapus</button>';
        html += '</div>';
        html += '</div>';

        html += '<div class="bank-qcard-text">' + String(q.question_text || '').replace(/\n/g, '<br>') + '</div>';

        html += '<div class="bank-qcard-options">';
        ['A', 'B', 'C', 'D', 'E'].forEach((letter, lIdx) => {
            const optText = (Array.isArray(opts) ? opts[lIdx] : (opts[letter] || opts[lIdx])) || '';
            if (!optText) return;
            const isCorrect = (q.correct_answer === letter);
            html += '<div class="bank-qcard-opt' + (isCorrect ? ' is-key' : '') + '">';
            html += '<span class="bank-qcard-opt-letter">' + letter + '</span>';
            html += '<span style="flex:1">' + String(optText).replace(/</g, '&lt;') + '</span>';
            if (isCorrect) {
                html += '<span style="font-size:11px;font-weight:700;color:#059669;margin-left:auto">✓ Kunci</span>';
            }
            html += '</div>';
        });
        html += '</div>';
        html += '</div>';
    });

    container.innerHTML = html;
}

function filterModalQuestions() {
    const query = document.getElementById('modalQSearch') ? document.getElementById('modalQSearch').value : '';
    const cat = document.getElementById('modalQCategory') ? document.getElementById('modalQCategory').value : '';
    const stat = document.getElementById('modalQStatus') ? document.getElementById('modalQStatus').value : '';
    renderModalQuestions(query, cat, stat);
}

function deleteModalQuestion(questionId) {
    if (!confirm('Yakin ingin menghapus butir soal ini dari paket?')) return;
    const formData = new FormData();
    formData.append('action', 'delete_question');
    formData.append('question_id', String(questionId));

    fetch(window.location.href, {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        body: formData
    })
    .then(r => r.json())
    .then(res => {
        if (res && res.success) {
            window._currentPackageQuestions = window._currentPackageQuestions.filter(q => Number(q.id) !== Number(questionId));
            renderModalQuestions();
            const card = document.getElementById('qcard_' + questionId);
            if (card) card.remove();
        } else {
            alert('Gagal menghapus soal: ' + (res.message || 'Terjadi kesalahan.'));
        }
    })
    .catch(() => {
        window.location.reload();
    });
}

function focusPackageQuestions(pkgId, pkgName) {
    openPackageQuestionsModal(pkgId, pkgName, 'PKT-' + pkgId);
}

// Modal Tambah & Edit Soal
function openAddModal(defaultPkgId) {
    document.getElementById('modalTitle').textContent = '➕ Tambah Butir Soal Baru';
    document.getElementById('formQuestionId').value = '0';
    const filterPkg = document.getElementById('filterPackage') ? document.getElementById('filterPackage').value : '';
    document.getElementById('formPackageId').value = defaultPkgId ? String(defaultPkgId) : (filterPkg || '1');
    document.getElementById('formCategory').value = 'Sholat Wajib';
    document.getElementById('formQuestionText').value = '';
    document.getElementById('formOptA').value = '';
    document.getElementById('formOptB').value = '';
    document.getElementById('formOptC').value = '';
    document.getElementById('formOptD').value = '';
    document.getElementById('formOptE').value = '';
    document.getElementById('formCorrectAnswer').value = 'A';
    const el = document.getElementById('questionModalBackdrop');
    if (el) {
        el.style.display = 'flex';
        el.style.pointerEvents = 'auto';
        el.style.visibility = 'visible';
        el.classList.add('open');
    }
}

function openEditModal(q) {
    document.getElementById('modalTitle').textContent = '✏️ Edit Butir Soal (' + (q.code || 'ID: ' + q.id) + ')';
    document.getElementById('formQuestionId').value = q.id;
    document.getElementById('formPackageId').value = q.package_id || '1';
    document.getElementById('formCategory').value = q.category || '';
    document.getElementById('formQuestionText').value = q.question_text || '';
    let opts = [];
    try {
        opts = JSON.parse(q.options_json) || [];
    } catch(e) {}
    document.getElementById('formOptA').value = opts[0] || opts['A'] || '';
    document.getElementById('formOptB').value = opts[1] || opts['B'] || '';
    document.getElementById('formOptC').value = opts[2] || opts['C'] || '';
    document.getElementById('formOptD').value = opts[3] || opts['D'] || '';
    document.getElementById('formOptE').value = opts[4] || opts['E'] || '';
    document.getElementById('formCorrectAnswer').value = q.correct_answer || 'A';
    const el = document.getElementById('questionModalBackdrop');
    if (el) {
        el.style.display = 'flex';
        el.style.pointerEvents = 'auto';
        el.style.visibility = 'visible';
        el.classList.add('open');
    }
}

function closeQuestionModal() {
    const el = document.getElementById('questionModalBackdrop');
    if (el) {
        el.classList.remove('open');
        el.style.display = 'none';
        el.style.pointerEvents = 'none';
        el.style.visibility = 'hidden';
    }
}

// Generator Token Acak
function generateRandomToken() {
    fetch('?ajax=generate_token')
        .then(r => r.json())
        .then(data => {
            if (data.token) {
                document.getElementById('sessionTokenInput').value = data.token;
            }
        })
        .catch(() => {
            const chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
            let t = '';
            for (let i = 0; i < 6; i++) t += chars.charAt(Math.floor(Math.random() * chars.length));
            document.getElementById('sessionTokenInput').value = t;
        });
}

function copyTokenToClipboard() {
    const el = document.getElementById('sessionTokenInput');
    if (!el) return;
    el.select();
    navigator.clipboard.writeText(el.value).then(() => {
        alert('Token ' + el.value + ' berhasil disalin ke clipboard!');
    });
}

// Review uses the same full-page renderer as CBT Pretes.
function tutorialRplReviewUrl(attemptId) {
    const query = new URLSearchParams(window.location.search);
    const context = new URLSearchParams({attempt_id: String(attemptId)});
    ['result_filter','status_filter','pass_filter','q','group_filter','page','per_page'].forEach(key => {
        if (query.has(key)) context.set(key, query.get(key));
    });
    return window.CBT_BASE_URL + '/admin/tutorial-rpl-cbt-review.php?' + context.toString();
}

// ═══════════════════════════════════════════════════════════════════════════
// LIVE MONITORING CBT TUTORIAL RPL (100% PARITAS DENGAN CBT PRETES)
// ═══════════════════════════════════════════════════════════════════════════
window.CBT_RESULT_FILTER = <?= json_encode($resultFilter) ?>;
window.CBT_STATUS_FILTER = <?= json_encode($statusFilter ?? 'all') ?>;
window.CBT_SEARCH_QUERY = <?= json_encode($searchQuery ?? '') ?>;
window.CBT_GROUP_FILTER = <?= json_encode($groupFilter ?? '') ?>;
window.CBT_PASS_FILTER = <?= json_encode($passFilter ?? 'all') ?>;
window.CBT_PASS_MIN_SCORE = <?= json_encode((float)($passingGrade ?? 70.0)) ?>;
window.CBT_PAGE = <?= (int)($page ?? 1) ?>;
window.CBT_PER_PAGE = <?= (int)($perPage ?? 25) ?>;
window.CBT_BASE_URL = <?= json_encode(BASE_URL) ?>;

function escapeHtml(str) {
    if (str === null || str === undefined) return '';
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

function initCbtLiveMonitor() {
    const table = document.getElementById('cbtLiveResultsTable');
    if (!table) return;

    const tbody = document.getElementById('cbtLiveResultsTbody');
    const ongoingNumEl = document.getElementById('cbtLiveOngoingNum');
    const lastUpdatedEl = document.getElementById('cbtLiveLastUpdated');
    const autoRefreshToggle = document.getElementById('cbtLiveAutoRefreshToggle');
    const refreshIntervalSelect = document.getElementById('cbtLiveRefreshInterval');
    const manualRefreshBtn = document.getElementById('cbtLiveManualRefreshBtn');

    const bankUrl = window.location.pathname;
    const currentFilter = window.CBT_RESULT_FILTER || 'all';
    const currentStatus = window.CBT_STATUS_FILTER || 'all';
    const currentSearch = window.CBT_SEARCH_QUERY || '';
    const currentGroup = window.CBT_GROUP_FILTER || '';
    const currentPass = window.CBT_PASS_FILTER || 'all';
    const passMinScore = Number(window.CBT_PASS_MIN_SCORE) || 70.0;
    const currentPage = Number(window.CBT_PAGE) || 1;
    const currentPerPage = Number(window.CBT_PER_PAGE) || 25;
    const hasExtraFilter = !!(currentSearch || currentGroup || (currentPass && currentPass !== 'all'));

    let timerId = null;
    let isFetching = false;

    // 1. Countdown Ticker (1 Detik)
    setInterval(function() {
        const now = Math.floor(Date.now() / 1000);
        document.querySelectorAll('.live-countdown[data-deadline]').forEach(function(el) {
            const deadline = parseInt(el.dataset.deadline, 10);
            if (isNaN(deadline)) return;
            const diff = deadline - now;
            const clockEl = el.querySelector('.countdown-clock');
            if (diff <= 0) {
                el.innerHTML = '<span style="color:#94a3b8;font-weight:normal">⌛ Waktu Habis</span>';
                el.removeAttribute('data-deadline');
            } else if (clockEl) {
                const mins = Math.floor(diff / 60);
                const secs = diff % 60;
                clockEl.textContent = mins + 'm ' + String(secs).padStart(2, '0') + 's';
            }
        });
    }, 1000);

    // 2. Render Single Row HTML
    function renderAttemptRow(att) {
        const isSubmitted = !!att.is_submitted;
        const isOngoing = !!att.is_ongoing;
        const now = Math.floor(Date.now() / 1000);
        const deadline = Number(att.deadline_at) || 0;

        let timeCellHtml = '<div>' + escapeHtml(att.started_at_wib) + '</div>';
        if (isSubmitted) {
            const dur = Math.max(0, Number(att.duration_seconds) || 0);
            const durM = Math.floor(dur / 60);
            const durS = dur % 60;
            timeCellHtml += '<div style="margin-top:3px;font-size:11px;color:#64748b" title="Durasi pengerjaan">⏱️ ' + durM + 'm ' + durS + 's</div>';
        } else if (isOngoing) {
            const rem = Math.max(0, deadline - now);
            const remM = Math.floor(rem / 60);
            const remS = rem % 60;
            timeCellHtml += '<div class="live-countdown" data-deadline="' + deadline + '" style="margin-top:3px;font-size:11px;font-weight:700;color:#d97706" title="Sisa waktu pengerjaan">' +
                '⏳ <span class="countdown-clock">' + remM + 'm ' + String(remS).padStart(2, '0') + 's</span></div>';
        } else {
            timeCellHtml += '<div style="margin-top:3px;font-size:11px;color:#94a3b8">⌛ Waktu Habis</div>';
        }

        let statusCellHtml = '';
        if (isSubmitted) {
            statusCellHtml = '<span class="bank-badge active">✓ Selesai</span>';
        } else if (isOngoing) {
            statusCellHtml = '<span class="bank-badge live-pulse-badge" style="background:#fef3c7;color:#92400e;border:1px solid #fde68a"><span class="pulse-dot"></span> Berlangsung</span>';
        } else {
            statusCellHtml = '<span class="bank-badge inactive">⌛ Waktu Habis</span>';
        }

        const totItems = Number(att.total_items) || 25;
        const ansCount = Number(att.answered_count) || 0;
        const pct = totItems > 0 ? Math.min(100, Math.round((ansCount / totItems) * 100)) : 0;
        const flgCount = Number(att.flagged_count) || 0;

        const flagBadgeHtml = flgCount > 0
            ? '<span class="bank-badge" style="background:#fef9c3;color:#854d0e;border:1px solid #fef08a;font-size:10px;padding:2px 6px">🟨 ' + flgCount + ' Ragu</span>'
            : '<span style="font-size:10.5px;color:#94a3b8">0 Ragu</span>';

        const progressCellHtml =
            '<div style="min-width:130px">' +
                '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:3px;font-size:11.5px">' +
                    '<span><strong>' + ansCount + '</strong> / ' + totItems + ' Soal</span>' +
                    '<span style="font-size:10.5px;color:#64748b;font-weight:700">' + pct + '%</span>' +
                '</div>' +
                '<div style="background:#e2e8f0;border-radius:999px;height:6px;width:100%;overflow:hidden;margin-bottom:5px">' +
                    '<div style="background:' + (isSubmitted ? '#10b981' : '#2563eb') + ';height:100%;width:' + pct + '%;border-radius:999px;transition:width .3s"></div>' +
                '</div>' +
                '<div>' + flagBadgeHtml + '</div>' +
            '</div>';

        const corCount = Number(att.correct_count) || 0;
        const wrgCount = Number(att.wrong_count) || 0;
        const unansCount = Number(att.unanswered_count) || 0;

        let detailCellHtml =
            '<div style="display:flex;flex-direction:column;gap:2px;font-size:11.5px;min-width:95px">' +
                '<span style="color:#059669;font-weight:700">✓ ' + corCount + ' Benar</span>' +
                '<span style="color:#dc2626;font-weight:700">✕ ' + wrgCount + ' Salah</span>';
        if (!isSubmitted && unansCount > 0) {
            detailCellHtml += '<span style="color:#64748b;font-size:10.5px">⋯ ' + unansCount + ' Belum</span>';
        }
        detailCellHtml += '</div>';

        let scoreCellHtml = '';
        if (isSubmitted) {
            const sc = att.score !== null ? Number(att.score).toFixed(1) : '—';
            const isPass = att.score !== null && Number(att.score) >= passMinScore;
            scoreCellHtml =
                '<span class="bank-key" title="Nilai Akhir Ujian">' + sc + '</span>' +
                '<span class="cbt-pass-label ' + (isPass ? 'is-pass' : 'is-fail') + '">' + (isPass ? 'Lulus' : 'Belum Memenuhi') + '</span>';
        } else {
            const sc = att.score !== null ? Number(att.score).toFixed(1) : '0.0';
            scoreCellHtml =
                '<span class="bank-key" style="background:#eff6ff;color:#1e40af;border:1px solid #bfdbfe;font-size:11.5px;width:auto;padding:0 7px" title="Estimasi nilai sementara saat ini">' +
                    sc + '<span style="font-size:9px;font-weight:600;display:block;line-height:1;margin-top:1px;color:#3b82f6">(Live)</span>' +
                '</span>';
        }

        const isPkg2 = (Number(att.package_id) === 2);
        const pkgStyle = isPkg2 ? 'background:#dbeafe;color:#1e3a8a' : 'background:#ecfdf5;color:#047857';
        const pkgText = isPkg2 ? 'Paket 2 (Kristen)' : 'Paket 1 (Muslim)';
        const safeName = escapeHtml(att.nama_lengkap);
        const safeNim = escapeHtml(att.nim);
        const prodiText = (att.program_studi || '').trim();
        const prodiHtml = prodiText ? '<div class="cbt-user-prodi" style="font-size:11px;color:#64748b;margin-top:2px;line-height:1.25;">' + escapeHtml(prodiText) + '</div>' : '';

        const profileUserId = Number(att.user_id);
        const profileNameHtml = (Number.isInteger(profileUserId) && profileUserId > 0)
            ? '<a class="admin-user-profile-link" data-no-spa href="' + escapeHtml(window.CBT_BASE_URL + '/admin/profil-pengguna.php?id=' + profileUserId) + '" style="color:inherit;text-decoration:underline;text-underline-offset:3px" title="Lihat profil pengguna"><strong>' + safeName + '</strong></a>'
            : '<strong>' + safeName + '</strong>';

        const actionCellHtml =
            '<div style="display:inline-flex;align-items:center;gap:6px;justify-content:center;flex-wrap:wrap">' +
                '<a class="bank-button" style="background:#2563eb;color:#ffffff;border-color:#1d4ed8;padding:4px 9px;font-size:11.5px;border-radius:6px;display:inline-flex;align-items:center;gap:4px;line-height:1.2;font-weight:600;text-decoration:none" href="' + escapeHtml(tutorialRplReviewUrl(att.id)) + '" title="Review lembar jawaban mahasiswa (soal benar, salah, dan ragu)">👁️ Review</a>' +
                '<form method="post" action="' + escapeHtml(window.location.href) + '" style="margin:0;display:inline-block;" onsubmit="return confirm(\'Reset attempt ujian mahasiswa \' + safeName + \' (NIM: \' + safeNim + \')?\\n\\nMahasiswa akan diizinkan memulai ujian kembali dari awal.\')">' +
                    '<input type="hidden" name="action" value="reset_attempt">' +
                    '<input type="hidden" name="attempt_id" value="' + att.id + '">' +
                    '<input type="hidden" name="user_id" value="' + att.user_id + '">' +
                    '<input type="hidden" name="class_id" value="' + att.class_id + '">' +
                    '<button type="submit" class="bank-button" style="padding:4px 8px;font-size:11.5px;" title="Izinkan Ujian Ulang">🔄 Reset</button>' +
                '</form>' +
                '<form method="post" action="' + escapeHtml(window.location.href) + '" style="margin:0;display:inline-block;" onsubmit="return confirm(\'Yakin ingin menghapus sesi CBT \' + safeName + \' (NIM: \' + safeNim + \')?\\n\\nData jawaban dan nilai akan dihapus, dan mahasiswa dapat memulai ujian kembali.\')">' +
                    '<input type="hidden" name="action" value="delete_attempt">' +
                    '<input type="hidden" name="attempt_id" value="' + att.id + '">' +
                    '<button type="submit" class="bank-button danger-sm" title="Hapus sesi CBT mahasiswa ini">🗑️ Hapus</button>' +
                '</form>' +
            '</div>';

        return '<tr id="attemptRow_' + att.id + '" data-attempt-id="' + att.id + '">' +
            '<td><div>' + profileNameHtml + '</div>' + prodiHtml + '</td>' +
            '<td>' + safeNim + '</td>' +
            '<td><div style="font-weight:600; color:#0369a1;">' + escapeHtml(att.nama_kelompok || '-') + '</div><div style="font-size:11px; color:#64748b;">👨‍🏫 ' + escapeHtml(att.dosen_nama || '-') + '</div></td>' +
            '<td><span class="bank-badge" style="' + pkgStyle + '">' + pkgText + '</span>' +
                (att.session_title ? '<div style="margin-top:3px"><span class="bank-badge session" style="font-size:10px;background:#f1f5f9;color:#334155;border:1px solid #cbd5e1" title="Sesi: ' + escapeHtml(att.session_title) + '">🎯 ' + escapeHtml(att.session_title) + '</span></div>' : '') +
            '</td>' +
            '<td>' + timeCellHtml + '</td>' +
            '<td>' + statusCellHtml + '</td>' +
            '<td>' + progressCellHtml + '</td>' +
            '<td>' + detailCellHtml + '</td>' +
            '<td>' + scoreCellHtml + '</td>' +
            '<td style="text-align:center">' + actionCellHtml + '</td>' +
        '</tr>';
    }

    // 3. Update Table Rows Dom Diff
    function updateTableRows(data) {
        if (!tbody) return;
        if (!data.length) {
            if (currentStatus === 'live') {
                tbody.innerHTML = '<tr><td colspan="10" class="bank-empty" style="padding:32px 16px"><div style="font-size:24px;margin-bottom:6px">⚡</div><div style="font-weight:700;font-size:14px;color:#0f172a;margin-bottom:4px">Tidak ada mahasiswa yang sedang mengerjakan ujian saat ini.</div><div style="font-size:12px;color:#64748b">Seluruh mahasiswa telah menyelesaikan ujian atau belum ada sesi baru yang dimulai.</div></td></tr>';
            } else if (hasExtraFilter) {
                const msg = currentSearch
                    ? 'Tidak ditemukan sesi dengan kata kunci &ldquo;' + escapeHtml(currentSearch) + '&rdquo; pada filter ini.'
                    : 'Tidak ada sesi yang cocok dengan filter ini.';
                tbody.innerHTML = '<tr><td colspan="10" class="bank-empty" style="padding:28px 16px"><div style="font-size:22px;margin-bottom:6px">🔍</div><div style="font-weight:700;color:#0f172a;margin-bottom:4px">' + msg + '</div></td></tr>';
            } else {
                tbody.innerHTML = '<tr><td colspan="10" class="bank-empty">Belum ada mahasiswa yang memulai CBT Tutorial RPL.</td></tr>';
            }
            return;
        }

        const newIds = new Set(data.map(d => String(d.id)));

        // Remove rows that no longer exist
        Array.from(tbody.querySelectorAll('tr[data-attempt-id]')).forEach(tr => {
            if (!newIds.has(tr.dataset.attemptId)) {
                tr.parentNode.removeChild(tr);
            }
        });

        // Update or insert rows
        data.forEach((att, index) => {
            const existingRow = document.getElementById('attemptRow_' + att.id);
            const rendered = renderAttemptRow(att);
            if (existingRow) {
                const tempDiv = document.createElement('tbody');
                tempDiv.innerHTML = rendered;
                const newRow = tempDiv.firstElementChild;
                if (newRow) {
                    for (let c = 2; c <= 8; c++) {
                        if (existingRow.cells[c] && newRow.cells[c] && existingRow.cells[c].innerHTML !== newRow.cells[c].innerHTML) {
                            existingRow.cells[c].innerHTML = newRow.cells[c].innerHTML;
                        }
                    }
                }
            } else {
                const tempDiv = document.createElement('tbody');
                tempDiv.innerHTML = rendered;
                const newRow = tempDiv.firstElementChild;
                if (newRow) {
                    newRow.classList.add('cbt-row-flash');
                    const refRow = tbody.children[index] || null;
                    tbody.insertBefore(newRow, refRow);
                }
            }
        });
    }

    // 4. Fetch Live Data via AJAX
    function fetchLiveResults(manual) {
        if (isFetching) return;
        if (!manual && document.hidden) return;
        isFetching = true;
        if (manualRefreshBtn && manual) {
            manualRefreshBtn.disabled = true;
            manualRefreshBtn.textContent = '⏳ Memuat…';
        }

        let url = bankUrl + '?tab=results&ajax=live_results' +
            '&result_filter=' + encodeURIComponent(currentFilter) +
            '&status_filter=' + encodeURIComponent(currentStatus) +
            '&page=' + encodeURIComponent(currentPage) +
            '&per_page=' + encodeURIComponent(currentPerPage);
        if (currentSearch) url += '&q=' + encodeURIComponent(currentSearch);
        if (currentGroup) url += '&group=' + encodeURIComponent(currentGroup);
        if (currentPass && currentPass !== 'all') url += '&pass_filter=' + encodeURIComponent(currentPass);

        fetch(url, {
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then(res => res.json())
        .then(res => {
            if (!res || !res.success) return;
            if (ongoingNumEl && res.ongoing_count !== undefined) {
                ongoingNumEl.textContent = res.ongoing_count;
            }
            const liveBadge = document.getElementById('cbtLiveTabBadge');
            if (liveBadge && res.ongoing_count !== undefined) {
                liveBadge.textContent = res.ongoing_count;
                if (res.ongoing_count > 0) {
                    liveBadge.classList.add('live');
                } else {
                    liveBadge.classList.remove('live');
                }
            }
            const finishedBadge = document.getElementById('cbtFinishedTabBadge');
            if (finishedBadge && res.finished_count !== undefined) {
                finishedBadge.textContent = res.finished_count;
            }
            const allBadge = document.getElementById('cbtAllTabBadge');
            if (allBadge && res.total_all !== undefined) {
                allBadge.textContent = res.total_all;
            }
            if (res.pass_counts) {
                const lulusEl = document.getElementById('cbtPassCountLulus');
                const tidakEl = document.getElementById('cbtPassCountTidak');
                if (lulusEl) lulusEl.textContent = res.pass_counts.lulus;
                if (tidakEl) tidakEl.textContent = res.pass_counts.tidak_lulus;
            }
            if (lastUpdatedEl && res.timestamp_wib) {
                lastUpdatedEl.textContent = 'Pembaruan: ' + res.timestamp_wib;
            }
            if (tbody && Array.isArray(res.data)) {
                updateTableRows(res.data);
            }
        })
        .catch(err => {
            console.warn('CBT Live fetch error:', err);
        })
        .finally(() => {
            isFetching = false;
            if (manualRefreshBtn) {
                manualRefreshBtn.disabled = false;
                manualRefreshBtn.textContent = '🔄 Segarkan';
            }
        });
    }

    function resetAutoRefresh() {
        if (timerId) clearInterval(timerId);
        if (!autoRefreshToggle || !autoRefreshToggle.checked) return;
        const sec = parseInt(refreshIntervalSelect ? refreshIntervalSelect.value : '5', 10) || 5;
        timerId = setInterval(() => fetchLiveResults(false), sec * 1000);
    }

    if (autoRefreshToggle) autoRefreshToggle.addEventListener('change', resetAutoRefresh);
    if (refreshIntervalSelect) refreshIntervalSelect.addEventListener('change', resetAutoRefresh);
    if (manualRefreshBtn) manualRefreshBtn.addEventListener('click', () => fetchLiveResults(true));

    resetAutoRefresh();
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initCbtLiveMonitor);
} else {
    initCbtLiveMonitor();
}

// Sanitasi backdrop: pindahkan ke document.body agar tidak terpengaruh CSS transform parent (.content-area)
function sanitizeBackdrops() {
    document.querySelectorAll('.bank-modal-backdrop').forEach(function(el) {
        if (el.parentElement !== document.body) {
            document.body.appendChild(el);
        }
        if (!el.classList.contains('open')) {
            el.style.display = 'none';
            el.style.pointerEvents = 'none';
            el.style.visibility = 'hidden';
        }
    });
}
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', sanitizeBackdrops);
} else {
    sanitizeBackdrops();
}

// Global event delegation untuk seluruh aksi tombol
document.addEventListener('click', function(e) {
    // Tombol Kelola Soal Paket
    const btnManage = e.target.closest('[data-action="manage-questions"]');
    if (btnManage) {
        e.preventDefault();
        const pId = parseInt(btnManage.getAttribute('data-package-id') || '0', 10);
        const pName = btnManage.getAttribute('data-package-name') || '';
        const pCode = btnManage.getAttribute('data-package-code') || '';
        openPackageQuestionsModal(pId, pName, pCode);
        return;
    }

    // Tombol Tambah Soal Paket
    const btnAddQ = e.target.closest('[data-action="add-question"]');
    if (btnAddQ) {
        e.preventDefault();
        const pId = parseInt(btnAddQ.getAttribute('data-package-id') || '0', 10);
        openAddModal(pId);
        return;
    }

    // Tombol Buat Sesi Baru
    const btnCreateSession = e.target.closest('[data-action="create-session"]');
    if (btnCreateSession) {
        e.preventDefault();
        openSessionModal();
        return;
    }

    // Tombol Buat Paket Baru
    const btnCreatePkg = e.target.closest('[data-action="create-package"]');
    if (btnCreatePkg) {
        e.preventDefault();
        openPackageModal();
        return;
    }

    // Tombol Tutup Modal (via data-close-modal atau tombol silang ✕)
    const btnClose = e.target.closest('[data-close-modal], .bank-modal-close');
    if (btnClose) {
        e.preventDefault();
        const backdrop = btnClose.closest('.bank-modal-backdrop');
        if (backdrop) {
            backdrop.classList.remove('open');
            backdrop.style.display = 'none';
            backdrop.style.pointerEvents = 'none';
            backdrop.style.visibility = 'hidden';
            document.body.style.overflow = '';
        }
        return;
    }

    // Klik backdrop luar untuk menutup modal
    if (e.target && e.target.classList.contains('bank-modal-backdrop') && e.target.classList.contains('open')) {
        e.target.classList.remove('open');
        e.target.style.display = 'none';
        e.target.style.pointerEvents = 'none';
        e.target.style.visibility = 'hidden';
        document.body.style.overflow = '';
    }
});

// Tutup modal aktif dengan tombol Escape
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        const openBackdrop = document.querySelector('.bank-modal-backdrop.open');
        if (openBackdrop) {
            openBackdrop.classList.remove('open');
            openBackdrop.style.display = 'none';
            openBackdrop.style.pointerEvents = 'none';
            openBackdrop.style.visibility = 'hidden';
            document.body.style.overflow = '';
        }
    }
});
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
