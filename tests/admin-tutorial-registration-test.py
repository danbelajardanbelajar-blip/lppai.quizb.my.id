"""Exercise real admin registration POST, scoped roster, quota and browser form."""
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
ROOT=Path(__file__).resolve().parents[1]
with tempfile.TemporaryDirectory(prefix='admin-tutorial-register-') as directory:
    root=Path(directory)
    for file in ['admin/tutorial-data-pendaftar.php','includes/admin-tutorial-registration.php']:
        target=root/file;target.parent.mkdir(parents=True,exist_ok=True);shutil.copy2(ROOT/file,target)
    with contextlib.closing(sqlite3.connect(root/'test.sqlite')) as db:
        db.executescript("""
        CREATE TABLE users(id INTEGER PRIMARY KEY,nama_lengkap TEXT,nim TEXT,role TEXT,program_studi TEXT,jenis_program TEXT,no_hp TEXT,email TEXT);
        INSERT INTO users VALUES(1,'Mahasiswa Satu','001','mahasiswa','PAI','RPL','',''),(2,'Mahasiswa Dua','002','mahasiswa','PAI','RPL','',''),(3,'Mahasiswa Tiga','003','mahasiswa','PAI','RPL','','');
        CREATE TABLE master_gelombang(id INTEGER PRIMARY KEY,tahun_ajaran TEXT,semester TEXT,gelombang TEXT,status TEXT,created_at TEXT,kuota_senin INTEGER DEFAULT 1,kuota_selasa INTEGER DEFAULT 0,kuota_rabu INTEGER DEFAULT 1,kuota_kamis INTEGER DEFAULT 1,kuota_jumat INTEGER DEFAULT 1);
        INSERT INTO master_gelombang(id,tahun_ajaran,semester,gelombang,status,created_at) VALUES(1,'2026/2027','Ganjil','gel1','aktif','2020-01-01'),(2,'2027/2028','Genap','gel2','aktif','2020-02-01'),(3,'2025/2026','Ganjil','gel1','nonaktif','2019-01-01');
        CREATE TABLE tutorial_classes(id INTEGER PRIMARY KEY,nama_kelas TEXT,dosen_pengampu TEXT,semester TEXT,gelombang TEXT,hari TEXT,jam TEXT,ruangan TEXT,kuota INTEGER);
        CREATE TABLE tutorial_registrations(id INTEGER PRIMARY KEY AUTOINCREMENT,user_id INTEGER,tutorial_class_id INTEGER,status TEXT,hari_pilihan TEXT,gelombang TEXT,tahun_ajaran TEXT,tipe_nilai TEXT,created_at TEXT DEFAULT CURRENT_TIMESTAMP,nilai_akhir REAL,nilai_thaharah REAL,nilai_shalat REAL,nilai_surat_pendek REAL,nilai_amaliyah REAL,nilai_jenazah REAL,nilai_ujian_tulis REAL);
        INSERT INTO tutorial_registrations(user_id,status,hari_pilihan,gelombang,tahun_ajaran) VALUES(1,'tidak_lulus','Senin','gel1','2025/2026');
        CREATE TABLE rooms(id INTEGER PRIMARY KEY,ruang TEXT);
        CREATE TABLE pretes_registrations(id INTEGER PRIMARY KEY,user_id INTEGER,periode TEXT,status TEXT,ruangan TEXT,hari_pilihan TEXT);
        INSERT INTO pretes_registrations VALUES(1,1,'Pretes RPL','hadir','R1','Senin'),(2,2,'Pretes RPL','hadir','R1','Senin'),(3,3,'Pretes RPL','hadir','R1','Senin');
        CREATE TABLE pretes_results(id INTEGER PRIMARY KEY,user_id INTEGER,nilai REAL,status_lulus TEXT);
        """)
        db.commit()
    (root/'includes/auth.php').write_text("""<?php
    define('BASE_URL','');
    function requireAdmin(){if(($_SERVER['HTTP_X_ROLE']??'admin')!=='admin'){http_response_code(403);exit('Forbidden');}}
    function getDBConnection(){return new PDO('sqlite:'.__DIR__.'/../test.sqlite',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);}
    function verifyCsrf($token){return $token==='fixture-token';}
    function csrfToken(){return 'fixture-token';}
    function sanitize($v){return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}
    function renderJenisProgramBadge($value,$compact){return sanitize($value);}
    function adminUserProfileLink($name,$id=null){return sanitize($name);}
    function formatNoHp($value){return $value;}
    """,encoding='utf-8')
    (root/'includes/header.php').write_text('<!doctype html><html><head><meta name="viewport" content="width=device-width,initial-scale=1"></head><body>',encoding='utf-8')
    (root/'includes/footer.php').write_text('</body></html>',encoding='utf-8')
    with socket.socket() as sock:
        sock.bind(('127.0.0.1',0));port=sock.getsockname()[1]
    server=subprocess.Popen(['php','-d','display_errors=stderr','-S',f'127.0.0.1:{port}','-t',str(root)],stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
    base=f'http://127.0.0.1:{port}/admin/tutorial-data-pendaftar.php'
    def request(data=None,period='1'):
        body=None if data is None else urllib.parse.urlencode(data).encode()
        with urllib.request.urlopen(base+'?gelombang_id='+period,data=body) as response:return response.read().decode()
    def post(user,day='Senin',period='1',csrf='fixture-token'):
        return request({'action':'add_single_registration','user_id':user,'hari_pilihan':day,'registration_period_id':period,'csrf_token':csrf},period)
    try:
        for _ in range(50):
            try:request();break
            except OSError:time.sleep(.1)
        html=request()
        assert 'name="registration_period_id" value="1"' in html and 'data-no-spa' in html
        assert "alert('Mahasiswa berhasil didaftarkan.');" not in html
        assert 'Sesi tidak valid.' in post(1,csrf='invalid')
        assert 'sudah penuh' in post(1,day='Selasa')
        assert 'tidak aktif' in post(1,period='3')
        assert 'valid' in post(1,day='Minggu')
        browser=root/'browser.cjs'
        browser.write_text(r"""const {chromium}=require(process.argv[2]);
(async()=>{const browser=await chromium.launch({headless:true,executablePath:'C:/Program Files/Google/Chrome/Application/chrome.exe'});
try{const page=await browser.newPage();const errors=[];page.on('pageerror',e=>errors.push(e.message));
await page.goto(process.argv[3]+'?gelombang_id=1');
await page.evaluate(()=>openAddSingleRegistrationModal(1,'Mahasiswa Satu'));
const form=page.locator('#addSingleRegistrationModal form');
await form.locator('select').selectOption('Senin');
await Promise.all([page.waitForNavigation(),form.locator('button[type=submit]').click()]);
if(!(await page.locator('.alert-success').innerText()).includes('berhasil didaftarkan'))throw Error('server success missing');
if(!(await page.locator('#tablePendaftar').innerText()).includes('Mahasiswa Satu'))throw Error('registered student missing from same-period roster');
if((await page.locator('#tablePretesBelumDaftar').innerText()).includes('Mahasiswa Satu'))throw Error('student remains unregistered');
if(errors.length)throw Error(errors.join(';'));console.log('PASS browser: native form POST shows real server outcome, student enters selected roster and leaves unregistered list.');
}finally{await browser.close()}})().catch(e=>{console.error(e);process.exit(1)});
""",encoding='utf-8')
        result=subprocess.run(['node',str(browser),str(ROOT/'scratch/cbt-preview/node_modules/playwright-core'),base],capture_output=True,text=True)
        assert result.returncode==0,result.stdout+result.stderr
        print(result.stdout.strip())
        assert 'sudah terdaftar' in post(1)
        assert 'sudah penuh' in post(2)
        assert 'berhasil didaftarkan' in post(2,period='2')
        with contextlib.closing(sqlite3.connect(root/'test.sqlite')) as db:
            rows=db.execute('SELECT user_id,tahun_ajaran,gelombang FROM tutorial_registrations ORDER BY id').fetchall()
            assert rows==[(1,'2025/2026','gel1'),(1,'2026/2027','gel1'),(2,'2027/2028','gel2')],rows
            db.execute('CREATE TRIGGER fail_registration BEFORE INSERT ON tutorial_registrations BEGIN SELECT RAISE(ABORT,"fixture write failure"); END;')
            db.commit()
        failure=post(3,day='Rabu')
        assert 'Pendaftaran gagal disimpan' in failure and 'alert-success' not in failure
        with contextlib.closing(sqlite3.connect(root/'test.sqlite')) as db:
            assert db.execute('SELECT COUNT(*) FROM tutorial_registrations').fetchone()[0]==3
        print('PASS HTTP/SQLite: selected period/year stored, old registrations do not block or consume quota, duplicates/full/zero/inactive/invalid/CSRF/write failure rejected without false success or partial inserts.')
    finally:
        server.terminate();server.wait(timeout=10)
