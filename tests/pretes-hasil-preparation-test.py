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
    for name in ['pages/pretes-hasil.php', 'pages/pretes-peserta.php', 'includes/pretes-helper.php', 'includes/user-helper.php', 'includes/pretes-face-engine.php',
                 'includes/pretes-active-page.php', 'includes/pretes-participant-view.php', 'includes/sync_eujian.php',
                 'includes/pretes-preparation-view.php', 'includes/pretes-learning-reading-view.php',
                 'data/pretes-istighasah.php', 'data/pretes-learning-readings.json', 'data/pretes-learning-videos.php']:
        target = root / name
        target.parent.mkdir(parents=True, exist_ok=True)
        shutil.copy(repo / name, target)
    (root / 'includes/auth.php').write_text('''<?php
define('BASE_URL', 'https://example.test');
require_once __DIR__ . '/pretes-helper.php';
function requireLogin() {}
function isAdmin() { return false; }
function isDosen() { return false; }
function getCurrentUser() { global $argv; return ['id' => 7, 'role' => 'mahasiswa', 'jenis_program' => 'Reguler', 'username' => $argv[1] === 'tester' ? '12345' : 'student']; }
function sanitize($text) { return htmlspecialchars((string)$text, ENT_QUOTES, 'UTF-8'); }
function getDBConnection() {
    global $argv;
    $case = $argv[1];
    $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $pdo->exec("CREATE TABLE master_pretes (id INTEGER, nama_pretes TEXT, periode TEXT, tahun_ajaran TEXT, status TEXT, target_program TEXT, tanggal_mulai TEXT);
        CREATE TABLE master_gelombang (id INTEGER, status TEXT);
        INSERT INTO master_gelombang VALUES (1, 'aktif');
        CREATE TABLE pretes_registrations (id INTEGER, user_id INTEGER, periode TEXT, pretes_schedule_id INTEGER, hari_pilihan TEXT, jam TEXT, ruangan TEXT, dosen_penguji TEXT, tanggal_daftar TEXT DEFAULT '2026-11-13', status TEXT DEFAULT 'terdaftar');
        CREATE TABLE pretes_assessments (registration_id INTEGER PRIMARY KEY, scores_json TEXT, assessed_by INTEGER, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP);
        CREATE TABLE pretes_schedules (id INTEGER, tanggal TEXT, waktu_mulai TEXT, waktu_selesai TEXT, ruangan TEXT, periode TEXT, status TEXT, terisi INTEGER DEFAULT 0, kuota INTEGER DEFAULT 40);
        INSERT INTO pretes_schedules (id, tanggal, waktu_mulai, waktu_selesai, ruangan, periode, status) VALUES (4, '2026-11-17', '13:00', '15:00', 'Ruang Periode Lain', '2026 RPL', 'aktif');
        CREATE TABLE pretes_results (id INTEGER, user_id INTEGER, pretes_schedule_id INTEGER, nilai REAL, created_at TEXT);
        INSERT INTO master_pretes VALUES (1, 'Pretes Lama', '2025/2026', '2025/2026', 'selesai', 'Semua', NULL);
        INSERT INTO master_pretes VALUES (2, 'Pretes Aktif', '2026/2027', '2026/2027', 'aktif', 'Semua', NULL);");
    ensurePretesFeatures($pdo);
    if (str_starts_with($case, 'closed')) $pdo->exec("UPDATE master_pretes SET status = 'selesai'");
    if ($case === 'multiple') $pdo->exec("INSERT INTO master_pretes VALUES (3, 'Khusus RPL', '2026 RPL', '2026/2027', 'aktif', 'RPL', NULL)");
    if (!in_array($case, ['none', 'closed_none'])) {
        $period = $case === 'old_only' ? '2025/2026' : '2026/2027';
        $pdo->prepare('INSERT INTO pretes_registrations (id, user_id, periode, pretes_schedule_id, hari_pilihan, jam, ruangan, dosen_penguji) VALUES (1, 7, ?, NULL, ?, ?, ?, ?)')->execute([$period, 'Senin', '13.00', '', '']);
        if ($case === 'priority') $pdo->exec("INSERT INTO pretes_registrations (id, user_id, periode, hari_pilihan, jam, ruangan, dosen_penguji) VALUES (9, 7, '2025/2026', 'Kamis', '13.00', '', '')");
        if ($case === 'assigned') {
            $pdo->exec("INSERT INTO pretes_schedules (id, tanggal, waktu_mulai, waktu_selesai, ruangan, periode, status) VALUES (8, '2026-11-16', '13:00', '15:00', 'Ruang Peserta', '2026/2027', 'aktif'), (9, '2026-11-17', '13:00', '15:00', 'Jadwal Nonaktif', '2026/2027', 'nonaktif');
                UPDATE pretes_registrations SET pretes_schedule_id = 8, dosen_penguji = 'Penguji Peserta' WHERE id = 1");
        }
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
    for case in ['registered', 'none', 'old_only', 'full_active', 'multiple', 'priority', 'assigned', 'tester', 'closed_pass', 'closed_fail', 'closed_none']:
        run = subprocess.run(['php', '-d', 'display_errors=1', 'pages/pretes-hasil.php', case], cwd=root, capture_output=True, text=True, encoding='utf-8')
        assert run.returncode == 0, run.stderr + run.stdout
        html = run.stdout
        assert 'Fatal error' not in html and 'Undefined' not in html and 'Deprecated:' not in html, html
        if not case.startswith('closed'):
            other = subprocess.run(['php', '-d', 'display_errors=1', 'pages/pretes-peserta.php', case], cwd=root, capture_output=True, text=True, encoding='utf-8')
            assert other.returncode == 0, other.stderr + other.stdout
            peserta_html = other.stdout
            hasil_html = html

            if case in ['registered', 'multiple', 'priority', 'assigned', 'tester', 'full_active']:
                # Peserta terdaftar periode aktif: pretes-peserta menampilkan jadwal, instruksi & materi persiapan
                assert peserta_html.count('id="pretes-active-information"') == 1
                assert peserta_html.index('id="pretes-active-participant"') < peserta_html.index('id="pretes-active-preparation"')
                assert peserta_html.count('<html ') == 1 and peserta_html.count('</html>') == 1
                assert 'Ruang Periode Lain' not in peserta_html and 'Jadwal Nonaktif' not in peserta_html
                if case == 'assigned':
                    assert 'Ruang Peserta' in peserta_html and 'Penguji Peserta' in peserta_html
                if case == 'tester': assert 'id="btn-kerjakan-pretes"' in peserta_html
                assert 'Pendaftaran pretes masih aktif' in peserta_html
                assert 'Status Akhir:' not in peserta_html and 'Rp 50.000' not in peserta_html and 'tutorial-pendaftaran.php' not in peserta_html
                assert 'Periode Pretes Telah Berakhir' not in peserta_html
                assert peserta_html.count('data-pretes-component=') == 12
                for component in ['ujian_tulis', 'praktik_wudlu', 'doa_setelah_wudlu', 'praktik_tayammum',
                                  'niat_mandi_besar', 'niat_jamak_qashar', 'bacaan_shalat_subuh',
                                  'al_bayyinah', 'al_alaq', 'al_dluha', 'istighasah', 'shalat_jenazah']:
                    assert f'data-pretes-component="{component}"' in peserta_html
                assert peserta_html.index('id="materi-persiapan-pretes"') < peserta_html.index('id="bacaan-istighasah"')
                assert peserta_html.count('class="pretes-reading"') == 5
                assert peserta_html.count('class="pretes-video-link"') == 5
                assert peserta_html.count('class="pretes-reading-verse"') == 39
                assert '<iframe' not in peserta_html
                assert 'Istighasah' in peserta_html and peserta_html.count('class="pretes-istighasah-arabic"') == 12
                assert peserta_html.count('>40 kali</span>') == 3 and peserta_html.count('>11 kali</span>') == 9
                assert 'Surat Yasin' in peserta_html and 'lang="ar" dir="rtl"' in peserta_html
                assert 'Materi Persiapan &amp; Praktik Pretes' in peserta_html
                assert '>Daftar Pretes</a>' not in peserta_html
                assert 'Khusus RPL' not in peserta_html and '>2026/2027<' in peserta_html

                # Halaman hasil (pretes-hasil.php) fokus pada hasil kelulusan resmi & kartu nilai 12 komponen
                assert 'Pengumuman Hasil Kelulusan Pretes' in hasil_html
                assert hasil_html.count('data-pretes-component=') == 12
                if case in ['registered', 'multiple', 'priority', 'assigned', 'tester']:
                    assert 'Pretes Periode Aktif Sedang Berlangsung' in hasil_html
                    assert 'pretes-peserta.php' in hasil_html
                elif case == 'full_active':
                    assert 'Selamat, Anda Dinyatakan LULUS Pretes!' in hasil_html
                    assert 'Rp 50.000' in hasil_html
            elif case == 'none':
                # Kasus belum pernah mendaftar pretes: arahkan ke pendaftaran pretes
                assert 'Pendaftaran Pretes Sedang Dibuka' in hasil_html
                assert 'pretes-daftar.php' in hasil_html
            elif case == 'old_only':
                # Memiliki riwayat pretes lama yang lulus
                assert 'Selamat, Anda Dinyatakan LULUS Pretes!' in hasil_html
        else:
            assert 'pretes-preparation-hero' not in html
            legacy = subprocess.run(['php', '-d', 'display_errors=1', 'pages/pretes-peserta.php', case], cwd=root, capture_output=True, text=True, encoding='utf-8')
            assert legacy.returncode == 0 and 'Fatal error' not in legacy.stdout and 'Undefined' not in legacy.stdout, legacy.stderr + legacy.stdout
            assert 'id="pretes-active-information"' not in legacy.stdout
            assert legacy.stdout.count('<html ') == 1 and legacy.stdout.count('</html>') == 1
            if case == 'closed_pass': assert 'Selamat, Anda Dinyatakan LULUS Pretes!' in html
            if case == 'closed_fail': assert 'Status Akhir: TIDAK LULUS' in html
            if case == 'closed_none': assert 'Periode Pretes Telah Berakhir' in html
        if args.output_dir:
            output = Path(args.output_dir)
            output.mkdir(parents=True, exist_ok=True)
            (output / (case + '.html')).write_text(html, encoding='utf-8')
        print('PASS:', case)
print('PASS: active pretes separation, non-redundant waiting/preparation, Arabic reading counts, and closed-period results.')
