"""Render the real PHP page/helpers with isolated SQLite data and authentication."""
import argparse
import shutil
import subprocess
import tempfile
from pathlib import Path

repo = Path(__file__).resolve().parents[1]
parser = argparse.ArgumentParser()
parser.add_argument('--output-dir')
args = parser.parse_args()
with tempfile.TemporaryDirectory(prefix='lppai-pretes-preparation-') as temp:
    root = Path(temp)
    for name in ['pages/pretes-hasil.php', 'includes/pretes-helper.php', 'includes/user-helper.php', 'includes/pretes-face-engine.php',
                 'includes/pretes-preparation-view.php', 'data/pretes-istighasah.php']:
        target = root / name
        target.parent.mkdir(parents=True, exist_ok=True)
        shutil.copy(repo / name, target)
    (root / 'includes/auth.php').write_text('''<?php
define('BASE_URL', 'https://example.test');
require_once __DIR__ . '/pretes-helper.php';
function requireLogin() {}
function isAdmin() { return false; }
function isDosen() { return false; }
function getCurrentUser() { return ['id' => 7, 'role' => 'mahasiswa', 'jenis_program' => 'Reguler']; }
function sanitize($text) { return htmlspecialchars((string)$text, ENT_QUOTES, 'UTF-8'); }
function getDBConnection() {
    global $argv;
    $case = $argv[1];
    $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $pdo->exec("CREATE TABLE master_pretes (id INTEGER, nama_pretes TEXT, periode TEXT, tahun_ajaran TEXT, status TEXT, target_program TEXT, tanggal_mulai TEXT);
        CREATE TABLE master_gelombang (id INTEGER, status TEXT);
        INSERT INTO master_gelombang VALUES (1, 'aktif');
        CREATE TABLE pretes_registrations (id INTEGER, user_id INTEGER, periode TEXT, pretes_schedule_id INTEGER, hari_pilihan TEXT, jam TEXT, ruangan TEXT, dosen_penguji TEXT);
        CREATE TABLE pretes_schedules (id INTEGER, tanggal TEXT, waktu_mulai TEXT, waktu_selesai TEXT, ruangan TEXT, periode TEXT);
        CREATE TABLE pretes_results (id INTEGER, user_id INTEGER, pretes_schedule_id INTEGER, nilai REAL, created_at TEXT);
        INSERT INTO master_pretes VALUES (1, 'Pretes Lama', '2025/2026', '2025/2026', 'selesai', 'Semua', NULL);
        INSERT INTO master_pretes VALUES (2, 'Pretes Aktif', '2026/2027', '2026/2027', 'aktif', 'Semua', NULL);");
    ensurePretesFeatures($pdo);
    if (str_starts_with($case, 'closed')) $pdo->exec("UPDATE master_pretes SET status = 'selesai'");
    if ($case === 'multiple') $pdo->exec("INSERT INTO master_pretes VALUES (3, 'Khusus RPL', '2026 RPL', '2026/2027', 'aktif', 'RPL', NULL)");
    if (!in_array($case, ['none', 'closed_none'])) {
        $period = $case === 'old_only' ? '2025/2026' : '2026/2027';
        $pdo->prepare('INSERT INTO pretes_registrations VALUES (1, 7, ?, NULL, ?, ?, ?, ?)')->execute([$period, 'Senin', '13.00', '', '']);
        if ($case === 'priority') $pdo->exec("INSERT INTO pretes_registrations VALUES (9, 7, '2025/2026', NULL, 'Kamis', '13.00', '', '')");
        if (in_array($case, ['old_only', 'full_active', 'closed_pass'])) {
            $scores = array_fill_keys(array_keys(pretesComponents()), 90);
            $pdo->prepare('INSERT INTO pretes_assessments (registration_id, scores_json, assessed_by) VALUES (1, ?, 1)')->execute([json_encode($scores)]);
            $pdo->exec("INSERT INTO pretes_results VALUES (1, 7, NULL, 90, '2026-01-01')");
        }
    }
    return $pdo;
}
''', encoding='utf-8')
    (root / 'includes/header.php').write_text('<!doctype html><html lang="id"><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><body>', encoding='utf-8')
    (root / 'includes/footer.php').write_text('</body></html>', encoding='utf-8')
    for case in ['registered', 'none', 'old_only', 'full_active', 'multiple', 'priority', 'closed_pass', 'closed_fail', 'closed_none']:
        run = subprocess.run(['php', '-d', 'display_errors=1', 'pages/pretes-hasil.php', case], cwd=root, capture_output=True, text=True, encoding='utf-8')
        assert run.returncode == 0, run.stderr + run.stdout
        html = run.stdout
        assert 'Fatal error' not in html and 'Undefined' not in html and 'Deprecated:' not in html, html
        if not case.startswith('closed'):
            assert 'Pendaftaran pretes masih aktif' in html
            assert 'Status Akhir:' not in html and 'Rp 50.000' not in html and 'tutorial-pendaftaran.php' not in html
            assert 'Periode Pretes Telah Berakhir' not in html
            assert 'Istighasah' in html and html.count('class="pretes-istighasah-arabic"') == 12
            assert html.count('>40 kali</span>') == 3 and html.count('>11 kali</span>') == 9
            assert 'Surat Yasin' in html and 'lang="ar" dir="rtl"' in html
            if case in ['none', 'old_only']:
                assert '>Daftar Pretes</a>' in html
                assert 'Pendaftaran Anda untuk periode ini sudah tercatat' not in html
            else:
                assert 'Menunggu pembagian kelas dan penguji' in html
                assert '>Daftar Pretes</a>' not in html
            assert 'Khusus RPL' not in html and '>2026/2027<' in html
        else:
            assert 'pretes-preparation-hero' not in html
            if case == 'closed_pass': assert 'Selamat, Anda Dinyatakan LULUS Pretes!' in html
            if case == 'closed_fail': assert 'Status Akhir: TIDAK LULUS' in html
            if case == 'closed_none': assert 'Periode Pretes Telah Berakhir' in html
        if args.output_dir:
            output = Path(args.output_dir)
            output.mkdir(parents=True, exist_ok=True)
            (output / (case + '.html')).write_text(html, encoding='utf-8')
        print('PASS:', case)
print('PASS: active pretes overrides active tutorial and old results; waiting/preparation, Arabic reading counts, and closed-period results.')
