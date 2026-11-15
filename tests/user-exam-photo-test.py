"""Actual profile upload + canonical reference, using isolated files/DB and a mock face-engine HTTP server."""
import base64
import contextlib
import http.server
import json
import os
import shutil
import socket
import sqlite3
import subprocess
import tempfile
import threading
import time
import urllib.error
import urllib.parse
import urllib.request
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


@contextlib.contextmanager
def fixture():
    state = {'mode':'match','calls':0}
    class Engine(http.server.BaseHTTPRequestHandler):
        def log_message(self, *args): pass
        def do_POST(self):
            assert self.headers['Authorization'] == 'Bearer ' + 't'*40
            data=json.loads(self.rfile.read(int(self.headers['Content-Length'])))
            assert data['direction'] is None and len(data['frames']) == 1
            assert data['frames'][0] == 'data:image/jpeg;base64,' + data['reference']
            assert base64.b64decode(data['reference']).startswith(b'\xff\xd8')
            state['calls'] += 1
            if state['mode'] == 'unavailable':
                self.send_response(503); self.end_headers(); return
            ok=state['mode']=='match'
            body=json.dumps({'engine':'opencv-sface-v1','match':ok,'similarity':99.0 if ok else 0,
                'status':'MATCH' if ok else 'NO_FACE','reason':'Foto memenuhi syarat' if ok else 'Harus ada tepat satu wajah.','motion_passed':False}).encode()
            self.send_response(200); self.send_header('Content-Type','application/json'); self.end_headers(); self.wfile.write(body)
    engine=http.server.ThreadingHTTPServer(('127.0.0.1',0),Engine)
    thread=threading.Thread(target=engine.serve_forever,daemon=True); thread.start()
    try:
        with tempfile.TemporaryDirectory(prefix='profile-exam-photo-') as directory:
            root=Path(directory)
            files=['pages/profil.php','includes/user-helper.php','includes/user-exam-photo.php','includes/pretes-face-engine.php',
                'assets/css/style.css','assets/js/profile-photo.js','assets/js/pretes-face-verifier.js']
            for file in files:
                target=root/file; target.parent.mkdir(parents=True,exist_ok=True); shutil.copy2(ROOT/file,target)
            (root/'uploads/pretes_foto').mkdir(parents=True)
            fixture_image=root/'uploads/pretes_foto/legacy.jpg'
            php_image='$im=imagecreatetruecolor(480,640);imagefill($im,0,0,imagecolorallocate($im,80,130,180));imagejpeg($im,$argv[1],90);'
            subprocess.run(['php','-r',php_image,str(fixture_image)],check=True,capture_output=True)
            subprocess.run(['php','-r',php_image.replace('(480,640)','(800,800)'),str(root/'new.jpg')],check=True,capture_output=True)
            with contextlib.closing(sqlite3.connect(root/'test.sqlite')) as db:
                db.executescript('''CREATE TABLE users (id INTEGER PRIMARY KEY,username TEXT,nim TEXT,nama_lengkap TEXT,role TEXT,
                    foto TEXT,email TEXT,no_hp TEXT,tempat_lahir TEXT,tanggal_lahir TEXT,jenis_program TEXT,program_studi TEXT,angkatan TEXT,is_active INT);
                    INSERT INTO users VALUES (1,'student','001','Peserta','mahasiswa',NULL,'','','','2000-01-01','Reguler','PAI','2026',1);
                    INSERT INTO users VALUES (2,'other','002','Peserta Lain','mahasiswa',NULL,'','','','2000-01-01','Reguler','PAI','2026',1);
                    CREATE TABLE pretes_registrations (id INTEGER PRIMARY KEY,user_id INT,foto TEXT);
                    INSERT INTO pretes_registrations VALUES (1,1,'uploads/pretes_foto/legacy.jpg');''')
                db.commit()
            (root/'includes/auth.php').write_text('''<?php
session_start(); $_SESSION['user_id']=1; $_SESSION['role']='mahasiswa';
require_once __DIR__.'/user-helper.php';
define('BASE_URL','http://'.$_SERVER['HTTP_HOST']);
function requireLogin(){}
function verifyCsrf($s){return $s==='valid';}
function csrfToken(){return 'valid';}
function sanitize($s){return htmlspecialchars((string)$s,ENT_QUOTES,'UTF-8');}
function getDBConnection(){return new PDO('sqlite:'.__DIR__.'/../test.sqlite',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);}
function getCurrentUser(){return getDBConnection()->query('SELECT * FROM users WHERE id=1')->fetch();}
''',encoding='utf-8')
            (root/'includes/header.php').write_text('<!doctype html><html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><link rel="stylesheet" href="/assets/css/style.css"><style>body{display:block;padding:20px;background:#f1f5f9}</style><body>',encoding='utf-8')
            (root/'includes/footer.php').write_text('</body></html>',encoding='utf-8')
            (root/'profil.php').write_text("<?php require __DIR__.'/pages/profil.php';",encoding='utf-8')
            (root/'reference.php').write_text("<?php require __DIR__.'/includes/auth.php'; require __DIR__.'/includes/user-exam-photo.php'; echo json_encode(['photo'=>getUserExamReferencePhoto(getDBConnection(),(int)($_GET['user']??1)),'session'=>$_SESSION]);",encoding='utf-8')
            (root/'seed-session.php').write_text("<?php require __DIR__.'/includes/auth.php'; $_SESSION['pretes_cbt_verified_v2_1']=time(); $_SESSION['pretes_cbt_verified_v2_-1001']=time(); $_SESSION['keep']='yes'; echo session_id();",encoding='utf-8')
            with socket.socket() as sock:
                sock.bind(('127.0.0.1',0)); port=sock.getsockname()[1]
            env={**os.environ,'PRETES_FACE_ENGINE_URL':f'http://127.0.0.1:{engine.server_port}','PRETES_FACE_ENGINE_TOKEN':'t'*40}
            with (root/'server.log').open('w') as log:
                process=subprocess.Popen(['php','-d','display_errors=0','-S',f'127.0.0.1:{port}','-t',str(root)],env=env,stdout=log,stderr=log)
                try:
                    for _ in range(80):
                        try:
                            with socket.create_connection(('127.0.0.1',port),timeout=.1): break
                        except OSError: time.sleep(.05)
                    yield f'http://127.0.0.1:{port}',root,state
                finally:
                    process.terminate(); process.wait(timeout=5)
    finally:
        engine.shutdown(); engine.server_close(); thread.join(timeout=5)


def request(base, route, data=None, cookie=None):
    req=urllib.request.Request(base+route,data=urllib.parse.urlencode(data).encode() if data is not None else None,
        headers={'Cookie':cookie} if cookie else {})
    with urllib.request.urlopen(req) as response: return response.read().decode(),response.headers


if __name__=='__main__':
    with fixture() as (base,root,state):
        body,headers=request(base,'/seed-session.php')
        cookie='PHPSESSID='+body.strip()
        assert json.loads(request(base,'/reference.php',cookie=cookie)[0])['photo']=='uploads/pretes_foto/legacy.jpg'
        assert json.loads(request(base,'/reference.php?user=2',cookie=cookie)[0])['photo']=='', 'Never reuse another account/session photo'
        photo='data:image/jpeg;base64,'+base64.b64encode((root/'new.jpg').read_bytes()).decode()
        post={'csrf_token':'valid','action':'upload_photo','foto_data':photo,'face_verified':'true'}
        state['mode']='reject'
        html,_=request(base,'/profil.php',post,cookie)
        assert 'Harus ada tepat satu wajah' in html
        rejected=json.loads(request(base,'/reference.php',cookie=cookie)[0])
        assert rejected['photo']=='uploads/pretes_foto/legacy.jpg'
        assert 'pretes_cbt_verified_v2_1' in rejected['session'], 'A rejected upload must not reset a valid check-in'
        assert not list((root/'uploads/.profile-validation').glob('*.jpg'))
        state['mode']='unavailable'
        assert 'Mesin verifikasi sedang tidak tersedia' in request(base,'/profil.php',post,cookie)[0]
        state['mode']='match'
        assert 'Foto profil lolos pemeriksaan' in request(base,'/profil.php',post,cookie)[0]
        result=json.loads(request(base,'/reference.php',cookie=cookie)[0]); saved=result['photo']
        assert saved.startswith('uploads/profil/') and saved!='uploads/pretes_foto/legacy.jpg'
        assert 'pretes_cbt_verified_v2_1' not in result['session'] and 'pretes_cbt_verified_v2_-1001' not in result['session']
        assert result['session']['keep']=='yes' and result['session']['foto']==saved
        assert (root/saved).exists() and (root/'uploads/pretes_foto/legacy.jpg').exists()
        dimensions=subprocess.check_output(['php','-d','display_errors=stderr','-r','echo json_encode(getimagesize($argv[1]));',str(root/saved)],text=True)
        assert json.loads(dimensions)['0']==480 and json.loads(dimensions)['1']==640
        with contextlib.closing(sqlite3.connect(root/'test.sqlite')) as db:
            assert db.execute('SELECT foto FROM pretes_registrations WHERE id=1').fetchone()[0]=='uploads/pretes_foto/legacy.jpg', 'Keep historical registration photo intact'
        # Direct multipart uploads still pass through server-side normalization and face checks.
        boundary='ProfilePhotoTestBoundary'
        body=b''
        for name,value in [('csrf_token','valid'),('action','upload_photo')]:
            body+=(f'--{boundary}\r\nContent-Disposition: form-data; name="{name}"\r\n\r\n{value}\r\n').encode()
        body+=(f'--{boundary}\r\nContent-Disposition: form-data; name="foto_profil"; filename="photo.jpg"\r\nContent-Type: image/jpeg\r\n\r\n').encode()+(root/'new.jpg').read_bytes()+f'\r\n--{boundary}--\r\n'.encode()
        req=urllib.request.Request(base+'/profil.php',data=body,headers={'Cookie':cookie,'Content-Type':'multipart/form-data; boundary='+boundary})
        with urllib.request.urlopen(req) as response:
            assert 'Foto profil lolos pemeriksaan' in response.read().decode()
        saved=json.loads(request(base,'/reference.php',cookie=cookie)[0])['photo']
        saved_count=len(list((root/'uploads/profil').glob('*.jpg')))
        calls=state['calls']
        for patch in [{'csrf_token':'wrong'},{'foto_data':'data:image/jpeg;base64,broken'},{'foto_data':'data:text/plain;base64,SGk='},
                      {'foto_data':'data:image/jpeg;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7'}]:
            request(base,'/profil.php',dict(post,**patch),cookie)
            assert json.loads(request(base,'/reference.php',cookie=cookie)[0])['photo']==saved
        assert state['calls']==calls, 'Reject invalid inputs before reaching engine'
        # A DB failure must not leave a new photo or remove the current one.
        with contextlib.closing(sqlite3.connect(root/'test.sqlite')) as db:
            db.executescript("CREATE TRIGGER fail_photo BEFORE UPDATE OF foto ON users BEGIN SELECT RAISE(ABORT,'DB failure'); END;")
        assert 'Foto belum tersimpan' in request(base,'/profil.php',post,cookie)[0]
        assert json.loads(request(base,'/reference.php',cookie=cookie)[0])['photo']==saved
        assert len(list((root/'uploads/profil').glob('*.jpg')))==saved_count
        assert 'Fatal error' not in (root/'server.log').read_text()
        for file in ['pages/pretes-ujian.php','pages/tryout.php','api/pretes-proctoring-check.php']:
            assert 'getUserExamReferencePhoto($pdo,' in (ROOT/file).read_text(encoding='utf-8')
        print('PASS: actual profile POST, shared reference selector, quality rejection/service outage preserve old photo, successful 3:4 JPEG save, official/tryout reference wiring, session invalidation, CSRF/input rejection, account isolation and DB rollback; face engine mocked via HTTP')
