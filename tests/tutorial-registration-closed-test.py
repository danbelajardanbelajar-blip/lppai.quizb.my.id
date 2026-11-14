"""Exercise the actual tutorial registration route with an isolated SQLite database."""
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
    with tempfile.TemporaryDirectory(prefix='tutorial-registration-') as directory:
        root = Path(directory)
        for file in ['pages/tutorial-pendaftaran.php', 'includes/tutorial-registration.php',
                     'includes/tutorial-registration-closed.php', 'assets/css/style.css']:
            target = root / file
            target.parent.mkdir(parents=True, exist_ok=True)
            shutil.copy2(ROOT / file, target)
        (root / 'tutorial-pendaftaran.php').write_text("<?php require __DIR__ . '/pages/tutorial-pendaftaran.php';", encoding='utf-8')
        (root / 'includes/auth.php').write_text('''<?php
            define('BASE_URL', 'http://' . $_SERVER['HTTP_HOST']);
            function requireLogin() {}
            function isAdmin() { return false; }
            function getCurrentUser() { return ['id'=>7,'role'=>'mahasiswa']; }
            function verifyCsrf($token) { return $token === 'valid'; }
            function csrfToken() { return 'valid'; }
            function sanitize($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
            function getDBConnection() { return new PDO('sqlite:' . __DIR__ . '/../test.sqlite', null, null,
                [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]); }
        ''', encoding='utf-8')
        (root / 'includes/pretes-helper.php').write_text("<?php function isUserLulusPretes($pdo,$id) { return false; }", encoding='utf-8')
        (root / 'includes/pretes-stage.php').write_text('''<?php function pretesStageGate($pdo,$user,$note) {
            if (($_SERVER['HTTP_X_TEST_STAGE'] ?? '') === '1') { http_response_code(403); exit('TAHAP PRETES'); }
            if (($_SERVER['HTTP_X_TEST_PLOT_DURING'] ?? '') === '1') {
                $pdo->exec("INSERT INTO tutorial_registrations (user_id,tutorial_class_id,gelombang,tahun_ajaran) VALUES (99,1,'gel1','2026-2027')");
            }
        }''', encoding='utf-8')
        (root / 'includes/header.php').write_text('<!doctype html><html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><link rel="stylesheet" href="/assets/css/style.css"><style>body{display:block;padding:24px;background:#f1f5f9}</style><body>', encoding='utf-8')
        (root / 'includes/footer.php').write_text('</body></html>', encoding='utf-8')
        with contextlib.closing(sqlite3.connect(root / 'test.sqlite')) as db:
            db.executescript('''
                CREATE TABLE master_gelombang (id INTEGER PRIMARY KEY, status TEXT, tahun_ajaran TEXT,
                    semester TEXT, gelombang TEXT, created_at TEXT, kuota_senin INTEGER);
                INSERT INTO master_gelombang VALUES
                    (1,'aktif','2026/2027','Ganjil','gel1','2026-11-01',30),
                    (2,'nonaktif','2027-2028','Genap','gel2','2026-11-10',30);
                CREATE TABLE tutorial_classes (id INTEGER PRIMARY KEY, nama_kelas TEXT, dosen_pengampu TEXT,
                    hari TEXT, jam TEXT, ruangan TEXT, gelombang TEXT, semester TEXT);
                INSERT INTO tutorial_classes VALUES
                    (1,'Kelas Aktif','Dosen Satu','Senin','13.00','R1','gel1','2026-2027-Ganjil'),
                    (2,'Kelas Lama','Dosen Satu','Senin','13.00','R1','gel1','2025-2026-Ganjil'),
                    (3,'Semester Lain','Dosen Satu','Senin','13.00','R1','gel1','2026-2027-Genap'),
                    (4,'Gelombang Lain','Dosen Satu','Senin','13.00','R1','gel2','2026-2027-Ganjil'),
                    (5,'Legacy','Dosen Satu','Senin','13.00','R1','gel1','Ganjil'),
                    (6,'Format Slash','Dosen Satu','Senin','13.00','R1','gel1','2026/2027-Ganjil');
                CREATE TABLE tutorial_registrations (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER,
                    tutorial_class_id INTEGER, status TEXT DEFAULT 'terdaftar', hari_pilihan TEXT DEFAULT 'Senin',
                    gelombang TEXT, tahun_ajaran TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP);
                CREATE TABLE announcements (id INTEGER PRIMARY KEY, tipe TEXT, is_active INTEGER,
                    created_at TEXT, judul TEXT, konten TEXT);
                INSERT INTO announcements VALUES (1,'pendaftaran_gel1',1,'2026-11-01','Daftar Tutorial','Pendaftaran dibuka');
            ''')
            db.commit()
        with socket.socket() as sock:
            sock.bind(('127.0.0.1', 0))
            port = sock.getsockname()[1]
        with (root / 'server.log').open('w') as log:
            process = subprocess.Popen(['php','-d','display_errors=0','-S',f'127.0.0.1:{port}','-t',str(root)], stdout=log, stderr=log)
            try:
                for _ in range(80):
                    try:
                        with socket.create_connection(('127.0.0.1',port),timeout=.1): break
                    except OSError: time.sleep(.05)
                yield f'http://127.0.0.1:{port}', root
            finally:
                process.terminate()
                process.wait(timeout=5)


def request(base, data=None, stage=False, plot_during=False):
    req = urllib.request.Request(base+'/tutorial-pendaftaran.php',
        data=urllib.parse.urlencode(data).encode() if data is not None else None,
        headers={'X-Test-Stage':'1' if stage else '0', 'X-Test-Plot-During':'1' if plot_during else '0'})
    try: response = urllib.request.urlopen(req)
    except urllib.error.HTTPError as error: response = error
    with response: return response.status, response.read().decode('utf-8')


def snapshot(root):
    with contextlib.closing(sqlite3.connect(root/'test.sqlite')) as db:
        return '\n'.join(db.iterdump())


if __name__ == '__main__':
    data = {'csrf_token':'valid','gelombang':'gel1','hari_pilihan':'Senin'}
    with fixture() as (base, root):
        # Empty classes are not plotting; open registration still creates an enrollment.
        assert 'name="hari_pilihan"' in request(base)[1]
        status, html = request(base, data)
        assert status == 200 and 'Pendaftaran tutorial berhasil!' in html
        with contextlib.closing(sqlite3.connect(root/'test.sqlite')) as db:
            assert db.execute('SELECT tutorial_class_id FROM tutorial_registrations WHERE user_id=7').fetchone() == (None,)
        for name, class_id, year, created, closed in [
            ('active_period',1,'2026-2027','2026-11-02',True),
            ('slash_format',6,'2026/2027','2026-11-02',True),
            ('class_period_without_reg_year',1,None,'2025-11-02',True),
            ('legacy_current',5,'2026/2027','2026-11-02',True),
            ('legacy_recent_no_year',5,None,'2026-11-02',True),
            ('old_year',2,'2025-2026','2025-11-02',False),
            ('other_semester',3,'2026-2027','2026-11-02',False),
            ('other_wave',4,'2026-2027','2026-11-02',False),
            ('legacy_old',5,'2025-2026','2025-11-02',False),
            ('legacy_unknown_old',5,None,'2025-11-02',False),
        ]:
            with contextlib.closing(sqlite3.connect(root/'test.sqlite')) as db:
                db.execute('DELETE FROM tutorial_registrations')
                db.execute('INSERT INTO tutorial_registrations (user_id,tutorial_class_id,gelombang,tahun_ajaran,created_at) VALUES (?,?,?,?,?)', (99,class_id,'gel1',year,created))
                db.commit()
            before = snapshot(root)
            status, html = request(base)
            assert status == 200 and ('id="tutorial-registration-closed"' in html) == closed, name
            if closed:
                assert 'Pendaftaran telah ditutup' in html and 'name="hari_pilihan"' not in html
                assert 'tutorial-info.php' in html
                assert request(base, data)[0] == 403
                assert 'Pendaftaran telah ditutup' in request(base, data, stage=True)[1]
                assert snapshot(root) == before, 'Closed GET/POST must not change any data'
            else:
                assert 'name="hari_pilihan"' in html
            print('PASS:', name)
        # An old form fetched before plotting cannot be submitted after another participant is plotted.
        with contextlib.closing(sqlite3.connect(root/'test.sqlite')) as db:
            db.execute('DELETE FROM tutorial_registrations'); db.commit()
        assert 'name="hari_pilihan"' in request(base)[1]
        with contextlib.closing(sqlite3.connect(root/'test.sqlite')) as db:
            db.execute("INSERT INTO tutorial_registrations (user_id,tutorial_class_id,gelombang,tahun_ajaran) VALUES (99,1,'gel1','2026-2027')"); db.commit()
        before = snapshot(root)
        assert request(base,data)[0] == 403 and snapshot(root) == before
        # Existing security/quota controls and failed inserts remain effective before plotting.
        with contextlib.closing(sqlite3.connect(root/'test.sqlite')) as db:
            db.execute('DELETE FROM tutorial_registrations'); db.commit()
        assert request(base,data,plot_during=True)[0] == 403
        with contextlib.closing(sqlite3.connect(root/'test.sqlite')) as db:
            assert db.execute('SELECT user_id FROM tutorial_registrations').fetchall() == [(99,)], 'Second guard must stop enrollment if plotting appears after initial GET check'
            db.execute('DELETE FROM tutorial_registrations'); db.commit()
        before = snapshot(root)
        assert 'Sesi tidak valid' in request(base,dict(data,csrf_token='invalid'))[1]
        assert snapshot(root) == before
        with contextlib.closing(sqlite3.connect(root/'test.sqlite')) as db:
            db.executescript("CREATE TRIGGER fail_registration BEFORE INSERT ON tutorial_registrations BEGIN SELECT RAISE(ABORT, 'simulated failure'); END;")
        before = snapshot(root)
        assert 'Pendaftaran belum tersimpan' in request(base,data)[1]
        assert snapshot(root) == before
        with contextlib.closing(sqlite3.connect(root/'test.sqlite')) as db:
            db.executescript('DROP TRIGGER fail_registration; UPDATE master_gelombang SET kuota_senin=0 WHERE id=1;')
        before = snapshot(root)
        assert 'kuota untuk hari Senin sudah penuh' in request(base,data)[1]
        assert snapshot(root) == before
        assert request(base,data,stage=True)[0] == 403
        assert 'Fatal error' not in (root/'server.log').read_text()
        print('PASS: open registration, closed GET/POST, current-period scope, no mutations, stale form rejected, CSRF and rollback')
