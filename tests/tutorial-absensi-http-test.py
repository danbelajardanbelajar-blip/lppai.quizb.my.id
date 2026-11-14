"""Exercise the real admin route with isolated data and admin authentication."""
import contextlib
import shutil
import socket
import sqlite3
import subprocess
import tempfile
import time
import urllib.error
import urllib.parse
import urllib.request
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


@contextlib.contextmanager
def fixture():
    with tempfile.TemporaryDirectory(prefix='tutorial-absensi-') as directory:
        root = Path(directory)
        for file in ['admin/tutorial-hasil-plotting.php', 'includes/tutorial-absensi-view.php',
                     'includes/tutorial-absensi-form.php', 'includes/tutorial-scheduled-lecturers.php', 'assets/logo.svg']:
            target = root / file
            target.parent.mkdir(parents=True, exist_ok=True)
            shutil.copy2(ROOT / file, target)
        with contextlib.closing(sqlite3.connect(root / 'test.sqlite')) as db:
            db.executescript('''
                CREATE TABLE master_gelombang (id INTEGER PRIMARY KEY, status TEXT, tahun_ajaran TEXT,
                    semester TEXT, gelombang TEXT, created_at TEXT);
                INSERT INTO master_gelombang VALUES
                    (1,'aktif','2026/2027','Ganjil','gel1','2026-11-01'),
                    (2,'nonaktif','2025-2026','Ganjil','gel1','2025-11-01');
                CREATE TABLE users (id INTEGER PRIMARY KEY, nama_lengkap TEXT, nim TEXT, program_studi TEXT);
                CREATE TABLE tutorial_classes (id INTEGER PRIMARY KEY, nama_kelas TEXT, hari TEXT, jam TEXT,
                    dosen_pengampu TEXT, ruangan TEXT, gelombang TEXT, semester TEXT);
                INSERT INTO tutorial_classes VALUES
                    (11,'Kelas A','Senin','13.00 - 14.30','Dosen Satu','R1','gel1','2026-2027-Ganjil'),
                    (12,'Kelas B','Senin','13.00 - 14.30','Dosen Satu','R1','gel1','2026/2027-Ganjil'),
                    (13,'Kelas Lama','Selasa','09.00','Dosen Lama','R2','gel1','2025-2026-Ganjil');
                CREATE TABLE tutorial_registrations (id INTEGER PRIMARY KEY, user_id INTEGER,
                    tutorial_class_id INTEGER, tahun_ajaran TEXT, gelombang TEXT, created_at TEXT,
                    tipe_nilai TEXT, nilai_thaharah REAL, nilai_shalat REAL, nilai_surat_pendek REAL,
                    nilai_amaliyah REAL, nilai_jenazah REAL, nilai_ujian_tulis REAL);
            ''')
            for uid in range(1, 34):
                name = f'Peserta {uid:02}' if uid != 1 else '<script>unsafe</script> & Peserta 01'
                db.execute('INSERT INTO users VALUES (?,?,?,?)', (uid, name, f'N{uid:03}', 'Pendidikan Agama Islam'))
                class_id, year = (11, '2026/2027') if uid <= 30 else ((12, '2026-2027') if uid == 31 else (13, '2025-2026'))
                if uid == 33:
                    class_id, year = 11, '2026-2027'
                grades = [90]*6 if uid == 33 else [None]*6
                db.execute('INSERT INTO tutorial_registrations VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)',
                           (uid, uid, class_id, year, 'gel1', '2026-11-02', 'gel 1', *grades))
            # Legacy no-year registration is resolved through its full class period.
            db.execute('UPDATE tutorial_registrations SET tahun_ajaran = NULL WHERE id = 1')
            # The old registration must not duplicate the same participant in the current class.
            db.execute("INSERT INTO tutorial_registrations VALUES (100,2,13,'2025-2026','gel1','2025-11-02','gel 1',NULL,NULL,NULL,NULL,NULL,NULL)")
            db.commit()
        (root / 'includes/auth.php').write_text('''<?php
            define('BASE_URL', 'http://' . $_SERVER['HTTP_HOST']);
            function requireAdmin() {
                if (($_SERVER['HTTP_X_TEST_ADMIN'] ?? '') !== '1') {
                    http_response_code(403); exit('Forbidden');
                }
            }
            function sanitize($value) { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
            function getDBConnection() {
                return new PDO('sqlite:' . __DIR__ . '/../test.sqlite', null, null,
                    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
            }
        ''', encoding='utf-8')
        (root / 'admin/form-preview.php').write_text('''<?php
            require __DIR__ . '/../includes/auth.php'; requireAdmin();
            $pdo = getDBConnection(); $selected_gel_id = 'active';
            $current_gel = $pdo->query('SELECT * FROM master_gelombang WHERE id = 1')->fetch();
            $classes = $pdo->query('SELECT * FROM tutorial_classes WHERE id IN (11,12)')->fetchAll();
            ?><!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
            <style>body{font:16px Arial;padding:24px}select{display:block;max-width:100%;padding:8px}button{padding:10px}</style>
            <?php include __DIR__ . '/../includes/tutorial-absensi-form.php';
        ''', encoding='utf-8')
        with socket.socket() as sock:
            sock.bind(('127.0.0.1', 0))
            port = sock.getsockname()[1]
        with (root / 'server.log').open('w') as log:
            process = subprocess.Popen(['php', '-d', 'display_errors=0', '-S', f'127.0.0.1:{port}', '-t', str(root)], stdout=log, stderr=log)
            try:
                for _ in range(80):
                    try:
                        with socket.create_connection(('127.0.0.1', port), timeout=0.1):
                            break
                    except OSError:
                        time.sleep(0.05)
                yield f'http://127.0.0.1:{port}', root
            finally:
                process.terminate()
                process.wait(timeout=5)


def request(base, query='', admin=True, method='GET'):
    url = base + '/admin/tutorial-hasil-plotting.php?cetak_absensi=1&' + query
    req = urllib.request.Request(url, headers={'X-Test-Admin': '1' if admin else '0'}, method=method)
    try:
        response = urllib.request.urlopen(req)
    except urllib.error.HTTPError as error:
        response = error
    with response:
        return response.status, response.read().decode('utf-8'), response.headers


if __name__ == '__main__':
    with fixture() as (base, root):
        with contextlib.closing(sqlite3.connect(root / 'test.sqlite')) as db:
            before = '\n'.join(db.iterdump())
        status, html, headers = request(base)
        assert status == 200, html
        assert '31 peserta · 2 kelas · 3 lembar A4' in html
        assert html.count('<section class="sheet">') == 3
        assert html.count('<td>N002</td>') == 1, 'No duplicate registration'
        assert 'N032' not in html and 'N033' not in html, 'Exclude historical/passed participants exactly like the plotting table'
        assert '&lt;script&gt;unsafe&lt;/script&gt;' in html and '<script>unsafe</script>' not in html
        assert '13.00 - 14.30' in html and 'Dosen Satu' in html and 'Tanda Tangan' in html
        assert 'private, no-store' in headers['Cache-Control']
        assert 'onclick="window.print()"' in html
        status, html, _ = request(base, 'gelombang_id=1&kelas_id=11')
        assert status == 200 and '30 peserta · 1 kelas · 2 lembar A4' in html
        assert '<td class="center">26</td>' in html and 'N031' not in html
        status, html, _ = request(base, 'gelombang_id=2')
        assert status == 200 and 'Kelas Lama' in html and 'N032' in html and 'N031' not in html
        status, html, _ = request(base, 'gelombang_id=all&kelas_id=12')
        assert status == 200 and '1 peserta · 1 kelas · 1 lembar A4' in html
        query = urllib.parse.urlencode({'hari': 'Senin', 'dosen_pengampu': 'Dosen Satu', 'ruangan': 'R1'})
        assert '31 peserta' in request(base, query)[1]
        status, html, _ = request(base, 'hari=Selasa')
        assert status == 200 and 'Belum ada peserta' in html and 'onclick="window.print()"' not in html
        for query, code in [('kelas_id=13', 404), ('kelas_id=-1', 400), ('kelas_id[]=11', 400),
                            ('hari[]=Senin', 400), ('gelombang_id[]=1', 400), ('gelombang_id=999', 404), ('gelombang_id=invalid', 400)]:
            assert request(base, query)[0] == code, query
        assert request(base, admin=False)[0] == 403
        assert request(base, method='POST')[0] == 405
        with contextlib.closing(sqlite3.connect(root / 'test.sqlite')) as db:
            assert before == '\n'.join(db.iterdump()), 'Printing must not modify data'
        assert 'Fatal error' not in (root / 'server.log').read_text()
        print('PASS: actual HTTP route, admin access, period/class/combined filters, legacy data, escaping, pagination, empty result, invalid input and read-only printing')
