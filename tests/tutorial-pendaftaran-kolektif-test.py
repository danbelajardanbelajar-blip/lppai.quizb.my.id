"""Exercise tutorial-pendaftaran-kolektif single registration, scoped roster, quota and browser form."""
import contextlib
import shutil
import socket
import sqlite3
import subprocess
import tempfile
import time
import urllib.parse
import urllib.request
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]

with tempfile.TemporaryDirectory(prefix='tutorial-kolektif-test-') as directory:
    root = Path(directory)
    for file in ['admin/tutorial-pendaftaran-kolektif.php', 'includes/admin-tutorial-registration.php']:
        target = root / file
        target.parent.mkdir(parents=True, exist_ok=True)
        shutil.copy2(ROOT / file, target)

    with contextlib.closing(sqlite3.connect(root / 'test.sqlite')) as db:
        db.executescript("""
        CREATE TABLE users(id INTEGER PRIMARY KEY, nama_lengkap TEXT, nim TEXT, role TEXT, program_studi TEXT, jenis_program TEXT, no_hp TEXT, email TEXT);
        INSERT INTO users VALUES(1, 'Mahasiswa Satu', '001', 'mahasiswa', 'PAI', 'Reguler', '', ''),
                                (2, 'Mahasiswa Dua', '002', 'mahasiswa', 'PAI', 'Reguler', '', ''),
                                (3, 'Mahasiswa Tiga', '003', 'mahasiswa', 'PBA', 'Reguler', '', '');
        CREATE TABLE master_gelombang(id INTEGER PRIMARY KEY, tahun_ajaran TEXT, semester TEXT, gelombang TEXT, status TEXT, created_at TEXT,
                                     kuota_senin INTEGER DEFAULT 1, kuota_selasa INTEGER DEFAULT 0, kuota_rabu INTEGER DEFAULT 1, kuota_kamis INTEGER DEFAULT 1, kuota_jumat INTEGER DEFAULT 1,
                                     tutors_senin TEXT DEFAULT '', tutors_selasa TEXT DEFAULT '', tutors_rabu TEXT DEFAULT '', tutors_kamis TEXT DEFAULT '', tutors_jumat TEXT DEFAULT '');
        INSERT INTO master_gelombang(id, tahun_ajaran, semester, gelombang, status, created_at)
        VALUES(1, '2026/2027', 'Ganjil', 'gel1', 'aktif', '2020-01-01');
        CREATE TABLE tutorial_classes(id INTEGER PRIMARY KEY, nama_kelas TEXT, dosen_pengampu TEXT, semester TEXT, gelombang TEXT, hari TEXT, jam TEXT, ruangan TEXT, kuota INTEGER);
        CREATE TABLE tutorial_registrations(id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, tutorial_class_id INTEGER, status TEXT, hari_pilihan TEXT, gelombang TEXT, tahun_ajaran TEXT, tipe_nilai TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP, nilai_akhir REAL, nilai_thaharah REAL, nilai_shalat REAL, nilai_surat_pendek REAL, nilai_amaliyah REAL, nilai_jenazah REAL, nilai_ujian_tulis REAL);
        INSERT INTO tutorial_registrations(user_id, status, hari_pilihan, gelombang, tahun_ajaran) VALUES(1, 'tidak_lulus', 'Senin', 'gel1', '2025/2026');
        CREATE TABLE rooms(id INTEGER PRIMARY KEY, ruang TEXT);
        CREATE TABLE tutorial_attendance(id INTEGER PRIMARY KEY, tutorial_class_id INTEGER);
        """)
        db.commit()

    (root / 'includes/auth.php').write_text("""<?php
    define('BASE_URL', '');
    function requireAdmin(){if(($_SERVER['HTTP_X_ROLE']??'admin')!=='admin'){http_response_code(403);exit('Forbidden');}}
    function getDBConnection(){return new PDO('sqlite:'.__DIR__.'/../test.sqlite',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);}
    function verifyCsrf($token){return $token==='fixture-token';}
    function csrfToken(){return 'fixture-token';}
    function sanitize($v){return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}
    function renderJenisProgramBadge($value,$compact){return sanitize($value);}
    function adminUserProfileLink($name,$id=null){return sanitize($name);}
    function formatNoHp($value){return $value;}
    """, encoding='utf-8')

    (root / 'includes/header.php').write_text('<!doctype html><html><head><meta name="viewport" content="width=device-width,initial-scale=1"></head><body>', encoding='utf-8')
    (root / 'includes/footer.php').write_text('</body></html>', encoding='utf-8')

    with socket.socket() as sock:
        sock.bind(('127.0.0.1', 0))
        port = sock.getsockname()[1]

    server = subprocess.Popen(['php', '-d', 'display_errors=stderr', '-S', f'127.0.0.1:{port}', '-t', str(root)], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
    base = f'http://127.0.0.1:{port}/admin/tutorial-pendaftaran-kolektif.php'

    def request(data=None):
        body = None if data is None else urllib.parse.urlencode(data).encode()
        req = urllib.request.Request(base, data=body)
        with urllib.request.urlopen(req) as response:
            return response.read().decode()

    def post(user, day='Senin', period='1', csrf='fixture-token'):
        return request({'action': 'add_single_registration', 'user_id': user, 'hari_pilihan': day, 'registration_period_id': period, 'csrf_token': csrf})

    try:
        for _ in range(50):
            try:
                request()
                break
            except OSError:
                time.sleep(0.1)

        html = request()
        # Verify modal attributes
        assert 'name="registration_period_id" value="1"' in html and 'data-no-spa' in html, "Modal must have registration_period_id and data-no-spa"
        assert "alert('Mahasiswa berhasil didaftarkan.');" not in html, "Fake alert success must not exist"

        # Verify validation error responses
        assert 'Sesi tidak valid.' in post(1, csrf='invalid'), "Invalid CSRF should be rejected"
        assert 'sudah penuh' in post(1, day='Selasa'), "Full quota day should be rejected"
        assert 'tidak aktif' in post(1, period='999'), "Inactive period should be rejected"
        assert 'valid' in post(1, day='Minggu'), "Invalid day should be rejected"

        # Browser Automation with Playwright Chrome
        browser = root / 'browser.cjs'
        browser.write_text(r"""const {chromium}=require(process.argv[2]);
(async()=>{
    const browser = await chromium.launch({headless:true, executablePath:'C:/Program Files/Google/Chrome/Application/chrome.exe'});
    try {
        const page = await browser.newPage();
        const errors = [];
        page.on('pageerror', e => errors.push(e.message));
        await page.goto(process.argv[3]);

        // Verify initial state: Mahasiswa Satu is not registered in active period
        const tableHtml = await page.locator('#tableSemuaMahasiswa').innerHTML();
        if (!tableHtml.includes('Mahasiswa Satu')) throw Error('Mahasiswa Satu missing from student list');
        if (!tableHtml.includes('Belum Mendaftar')) throw Error('Unregistered badge missing');

        // Open registration modal for Mahasiswa Satu
        await page.evaluate(() => openAddSingleRegistrationModal(1, 'Mahasiswa Satu'));
        const form = page.locator('#addSingleRegistrationModal form');
        await form.locator('select').selectOption('Senin');

        // Submit form natively
        await Promise.all([page.waitForNavigation(), form.locator('button[type=submit]').click()]);

        // Verify server alert
        const alertSuccess = await page.locator('.alert-success').innerText();
        if (!alertSuccess.includes('berhasil didaftarkan')) throw Error('Server success alert missing');

        // Verify table state after registration: Mahasiswa Satu is now registered
        const updatedTable = await page.locator('#tableSemuaMahasiswa').innerHTML();
        if (!updatedTable.includes('Sudah Terdaftar (Senin)')) throw Error('Badge not updated to Sudah Terdaftar (Senin)');

        if (errors.length) throw Error(errors.join(';'));
        console.log('PASS browser: native form POST registers student, shows real server alert, and updates table to Sudah Terdaftar.');
    } finally {
        await browser.close();
    }
})().catch(e => { console.error(e); process.exit(1); });
""", encoding='utf-8')

        result = subprocess.run(['node', str(browser), str(ROOT / 'scratch/cbt-preview/node_modules/playwright-core'), base], capture_output=True, text=True)
        assert result.returncode == 0, result.stdout + result.stderr
        print(result.stdout.strip())

        # Duplicate registration in same period must be rejected
        assert 'sudah terdaftar' in post(1), "Duplicate registration in same period must be rejected"

        # Quota for Senin was 1, now occupied by Mahasiswa Satu. Attempting to register Mahasiswa Dua for Senin must fail!
        assert 'sudah penuh' in post(2, day='Senin'), "Full quota should be rejected"

        # Registering Mahasiswa Dua for Rabu (quota 1) must succeed
        assert 'berhasil didaftarkan' in post(2, day='Rabu'), "Registering Mahasiswa Dua for Rabu must succeed"

        # Verify database contents
        with contextlib.closing(sqlite3.connect(root / 'test.sqlite')) as db:
            rows = db.execute('SELECT user_id, tahun_ajaran, gelombang, hari_pilihan FROM tutorial_registrations ORDER BY id').fetchall()
            assert rows == [
                (1, '2025/2026', 'gel1', 'Senin'), # Initial unpassed record
                (1, '2026/2027', 'gel1', 'Senin'), # New registration for active period
                (2, '2026/2027', 'gel1', 'Rabu'),  # Mahasiswa Dua
            ], f"Unexpected DB rows: {rows}"

            # Test write failure / rollback handling
            db.execute('CREATE TRIGGER fail_registration BEFORE INSERT ON tutorial_registrations BEGIN SELECT RAISE(ABORT, "fixture write failure"); END;')
            db.commit()

        failure = post(3, day='Kamis')
        assert 'Pendaftaran gagal disimpan' in failure and 'alert-success' not in failure, "Write failure must show danger message and no success"

        with contextlib.closing(sqlite3.connect(root / 'test.sqlite')) as db:
            assert db.execute('SELECT COUNT(*) FROM tutorial_registrations').fetchone()[0] == 3, "No partial or uncommitted records should remain"

        print('PASS HTTP/SQLite: scoped registration, quota limits, duplication checks, error feedback, and transaction rollback verified.')
    finally:
        server.terminate()
        server.wait(timeout=10)
