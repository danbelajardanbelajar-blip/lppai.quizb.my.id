"""Exercise actual admin profile endpoints against isolated accounts and browser DOM."""
import contextlib
import json
import shutil
import socket
import sqlite3
import subprocess
import tempfile
import time
import urllib.error
import urllib.request
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
with tempfile.TemporaryDirectory(prefix='admin-profile-') as directory:
    root = Path(directory)
    for file in ['admin/profil-pengguna.php', 'admin/ajax-user-profile-links.php',
                 'includes/admin-user-profile.php', 'includes/user-table-avatar.php',
                 'assets/js/admin-user-profile-links.js', 'assets/js/pretes-cbt-admin.js']:
        target = root/file
        target.parent.mkdir(parents=True, exist_ok=True)
        shutil.copy2(ROOT/file, target)
    with contextlib.closing(sqlite3.connect(root/'test.sqlite')) as db:
        db.executescript('''CREATE TABLE users(id INTEGER PRIMARY KEY,nama_lengkap TEXT,username TEXT,nim TEXT,role TEXT,program_studi TEXT,is_active INTEGER,foto TEXT,password TEXT);
        INSERT INTO users VALUES(1,'Mahasiswa Satu','satu','001','mahasiswa','PAI',1,NULL,'SECRET-HASH');
        INSERT INTO users VALUES(2,'Dosen Satu','dosen','002','dosen','PAI',1,NULL,'SECRET-HASH');
        INSERT INTO users VALUES(3,'Nama Sama','sama1','003','mahasiswa','PAI',1,NULL,'SECRET-HASH');
        INSERT INTO users VALUES(4,'Nama Sama','sama2','004','mahasiswa','PAI',1,NULL,'SECRET-HASH');
        CREATE TABLE pretes_registrations(id INTEGER,user_id INTEGER,periode TEXT,hari_pilihan TEXT,jam TEXT,ruangan TEXT,status TEXT);
        INSERT INTO pretes_registrations VALUES(1,1,'Periode Milik Satu','Senin','08:00','R1','terdaftar');
        INSERT INTO pretes_registrations VALUES(2,3,'Periode Rahasia Lain','Senin','08:00','R2','terdaftar');
        CREATE TABLE tutorial_classes(id INTEGER,nama_kelas TEXT,hari TEXT,jam TEXT,ruangan TEXT,dosen_pengampu TEXT,semester TEXT,gelombang TEXT);
        INSERT INTO tutorial_classes VALUES(1,'Kelas Dosen Satu','Senin','08:00','R1','Dosen Satu','Ganjil','gel1');
        CREATE TABLE tutorial_registrations(id INTEGER,user_id INTEGER,tutorial_class_id INTEGER,status TEXT,tahun_ajaran TEXT,gelombang TEXT,hari_pilihan TEXT,nilai_akhir REAL);
        INSERT INTO tutorial_registrations VALUES(1,1,1,'aktif','2026','gel1','Senin',85);''')
        db.commit()
        original = '\n'.join(db.iterdump())
    (root/'includes/auth.php').write_text('''<?php
require_once __DIR__.'/admin-user-profile.php';
define('BASE_URL',''); $_SESSION=['user_id'=>99,'role'=>$_SERVER['HTTP_X_ROLE']??'admin'];
function isLoggedIn(){return $_SESSION['role']!=='guest';}
function isAdmin(){return $_SESSION['role']==='admin';}
function requireAdmin(){if(!isAdmin()){http_response_code(403);exit('Forbidden');}}
function verifyCsrf($token){return $token==='fixture-token';}
function sanitize($v){return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}
function getDBConnection(){return new PDO('sqlite:'.__DIR__.'/../test.sqlite',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);}
''', encoding='utf-8')
    (root/'includes/header.php').write_text('<!doctype html><html><head><meta name="viewport" content="width=device-width,initial-scale=1"></head><body><main class="content-area">', encoding='utf-8')
    (root/'includes/footer.php').write_text('</main><span id="viewer">Admin <?= $_SESSION["user_id"] ?></span></body></html>', encoding='utf-8')
    (root/'index.html').write_text('''<!doctype html><html><head>
<script defer src="/assets/js/admin-user-profile-links.js" data-profile-lookup="/admin/ajax-user-profile-links.php" data-profile-page="/admin/profil-pengguna.php" data-profile-csrf="fixture-token"></script>
</head><body><main class="content-area"><table><tbody><tr><td id="student">Mahasiswa Satu</td><td id="teacher">Dosen Satu</td><td id="duplicate">Nama Sama</td></tr></tbody></table>
<a id="wa" href="https://wa.me/123">Dosen Satu</a><button>Mahasiswa Satu</button><select><option>Nama Sama</option></select><p id="partial">Halo Mahasiswa Satu</p></main>
<dialog open><table><tbody><tr><td id="dialog-name"><span class="badge">Dosen Satu</span></td></tr></tbody></table></dialog>
<table><tbody><tr><td id="outside-name">Mahasiswa Satu</td></tr></tbody></table></body></html>''', encoding='utf-8')
    (root/'helper.php').write_text('''<?php require __DIR__.'/includes/auth.php';
header('Content-Type: application/json');
echo json_encode([adminUserProfileLink('Nama Sama',3),adminUserProfileLink('Nama Sama',4),
adminUserProfileLink('Dosen Satu'),adminUserProfileLink('<script>bad</script>',1),adminUserProfileLink('Admin',99),adminUserProfileLink('-')]);
''', encoding='utf-8')
    (root/'cbt.html').write_text('''<!doctype html><html><head><script>window.CBT_BANK_URL='/mock?cbt_bank=1';</script>
<script defer src="/assets/js/pretes-cbt-admin.js"></script></head><body>
<button id="cbtLiveManualRefreshBtn">Segarkan</button><table id="cbtLiveResultsTable"><tbody id="cbtLiveResultsTbody"></tbody></table>
</body></html>''', encoding='utf-8')
    with socket.socket() as sock:
        sock.bind(('127.0.0.1', 0))
        port = sock.getsockname()[1]
    server = subprocess.Popen(['php','-d','display_errors=stderr','-S',f'127.0.0.1:{port}','-t',str(root)],stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
    base = f'http://127.0.0.1:{port}'
    def request(path, data=None, role='admin', method=None):
        req=urllib.request.Request(base+path, data=json.dumps(data).encode() if data is not None else None,
            headers={'X-Role':role,'Content-Type':'application/json'}, method=method)
        try:
            response=urllib.request.urlopen(req)
        except urllib.error.HTTPError as error:
            response=error
        with response:
            return response.status,response.read().decode()
    try:
        for _ in range(50):
            try:
                request('/index.html'); break
            except OSError:
                time.sleep(.1)
        endpoint='/admin/ajax-user-profile-links.php'
        links=json.loads(request('/helper.php')[1])
        assert '?id=3' in links[0] and '?id=4' in links[1]
        assert '?nama=Dosen%20Satu' in links[2] and '&lt;script&gt;' in links[3] and '<script>' not in links[3]
        assert '?id=99' in links[4] and links[5]=='-'
        for role in ['mahasiswa','dosen','guest']:
            assert all('<a ' not in link for link in json.loads(request('/helper.php',role=role)[1]))
        payload={'csrf_token':'fixture-token','names':['Mahasiswa Satu','Dosen Satu','Nama Sama','Halo Mahasiswa Satu']}
        status,body=request(endpoint,payload)
        assert status==200 and json.loads(body)['links']==[{'name':'Mahasiswa Satu','id':1},{'name':'Dosen Satu','id':2},{'name':'Nama Sama','id':None}],body
        assert request(endpoint,payload,role='mahasiswa')[0]==403
        assert request(endpoint,payload,role='dosen')[0]==403
        assert request(endpoint)[0]==405
        assert request(endpoint,{'csrf_token':'wrong','names':[]})[0]==403
        assert request(endpoint,{'csrf_token':'fixture-token','names':['x']*101})[0]==400
        assert request('/admin/profil-pengguna.php?id=1',role='mahasiswa')[0]==403
        assert request('/admin/profil-pengguna.php?id[]=1')[0]==400
        assert request('/admin/profil-pengguna.php?id=999')[0]==404
        assert request('/admin/profil-pengguna.php?id=1',{},method='POST')[0]==405
        status,body=request('/admin/profil-pengguna.php?id=1')
        assert status==200 and 'Periode Milik Satu' in body and 'Kelas Dosen Satu' in body
        assert 'Periode Rahasia Lain' not in body and 'SECRET-HASH' not in body and 'Admin 99' in body
        assert 'Kelas Dosen Satu' in request('/admin/profil-pengguna.php?id=2')[1]
        assert '003' in request('/admin/profil-pengguna.php?nama=Nama%20Sama')[1]
        assert '004' in request('/admin/profil-pengguna.php?nama=Nama%20Sama')[1]
        browser_test = root/'browser.cjs'
        browser_test.write_text('''const {chromium}=require(process.argv[2]);
(async()=>{const browser=await chromium.launch({executablePath:'C:/Program Files/Google/Chrome/Application/chrome.exe',headless:true});
try{const page=await browser.newPage();const errors=[];page.on('pageerror',e=>errors.push(e.message));
await page.goto(process.argv[3]);await page.waitForSelector('#student a');await page.waitForSelector('#teacher a');await page.waitForSelector('#duplicate a');
await page.waitForSelector('#dialog-name a');await page.waitForSelector('#outside-name a');
if(!((await page.locator('#student a').getAttribute('href')).endsWith('?id=1')))throw Error('wrong student');
if(!((await page.locator('#duplicate a').getAttribute('href')).includes('nama=Nama')))throw Error('duplicate not routed to chooser');
await page.evaluate(()=>{const p=document.createElement('p');p.id='dynamic';p.textContent='Dosen Satu';document.querySelector('main').appendChild(p)});
await page.waitForSelector('#dynamic a');
if(await page.locator('button a,option a,#partial a,a a').count())throw Error('controls or partial names changed');
if(await page.locator('#wa').getAttribute('href')!=='https://wa.me/123')throw Error('WA changed');
await page.locator('#student a').click();await page.waitForURL('**/profil-pengguna.php?id=1');
if(!(await page.textContent('body')).includes('Admin 99'))throw Error('admin identity changed');
await page.setViewportSize({width:390,height:844});
if(await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth))throw Error('mobile overflow');
await page.route('**/mock?**',route=>route.fulfill({json:{success:true,data:[
{id:900,user_id:3,nama_lengkap:'Nama Sama',nim:'003',is_submitted:true},
{id:901,user_id:4,nama_lengkap:'Nama Sama',nim:'004',is_submitted:true}
]}}));
await page.goto(process.argv[3]+'/cbt.html');
await page.locator('#cbtLiveManualRefreshBtn').click();
await page.waitForSelector('#attemptRow_900 .admin-user-profile-link');
if(!(await page.locator('#attemptRow_900 .admin-user-profile-link').getAttribute('href')).endsWith('?id=3'))throw Error('CBT used attempt ID or duplicate name');
if(!(await page.locator('#attemptRow_901 .admin-user-profile-link').getAttribute('href')).endsWith('?id=4'))throw Error('CBT duplicated profile target');
await page.locator('#cbtLiveManualRefreshBtn').click();
await page.waitForFunction(()=>!document.querySelector('#cbtLiveManualRefreshBtn').disabled);
if(await page.locator('#cbtLiveResultsTbody .admin-user-profile-link').count()!==2)throw Error('CBT refresh lost links');
if(errors.length)throw Error(errors.join(';'));console.log('PASS browser: static/dynamic names, duplicates, controls, navigation, mobile, no JS errors');
}finally{await browser.close()}})().catch(e=>{console.error(e);process.exit(1)});''',encoding='utf-8')
        result=subprocess.run(['node',str(browser_test),str(ROOT/'scratch/cbt-preview/node_modules/playwright-core'),base],capture_output=True,text=True)
        assert result.returncode==0,result.stdout+result.stderr
        print(result.stdout.strip())
        with contextlib.closing(sqlite3.connect(root/'test.sqlite')) as db:
            assert '\n'.join(db.iterdump())==original
        print('PASS HTTP: admin-only, CSRF, validation, exact/duplicate names, scoped history, no secrets, no database mutations')
    finally:
        server.terminate()
        server.wait(timeout=10)
