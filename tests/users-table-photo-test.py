"""Actual users AJAX photo cells, with isolated uploaded files and account records."""
import base64
import contextlib
import json
import shutil
import sqlite3
import subprocess
import tempfile
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
with tempfile.TemporaryDirectory(prefix='users-table-photo-') as directory:
    root = Path(directory)
    for file in ['admin/ajax-users.php','includes/user-table-avatar.php']:
        target=root/file
        target.parent.mkdir(parents=True,exist_ok=True)
        shutil.copy2(ROOT/file,target)
    photo_dir=root/'uploads/profil'
    photo_dir.mkdir(parents=True)
    png=base64.b64decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jWZkAAAAASUVORK5CYII=')
    (photo_dir/'martha.png').write_bytes(png)
    (root/'outside.png').write_bytes(png)
    with contextlib.closing(sqlite3.connect(root/'test.sqlite')) as db:
        db.executescript('CREATE TABLE users (id INTEGER PRIMARY KEY,username TEXT,nim TEXT,nama_lengkap TEXT,no_hp TEXT,tanggal_lahir TEXT,program_studi TEXT,jenis_program TEXT,angkatan TEXT,role TEXT,is_active INTEGER,email TEXT,tempat_lahir TEXT,foto TEXT);')
        users=[
            (1,'MARTHA PRAMUDYA ANGGARA','uploads/profil/martha.png'),
            (2,'Tanpa Foto',None),
            (3,'Foto Hilang','uploads/profil/missing.png'),
            (4,'Remote Gagal','https://example.test/broken.png'),
            (5,'<img src=x onerror=alert(1)>','javascript:alert(1)'),
            (6,'Traversal Ditolak','uploads/../outside.png'),
        ]
        for uid,name,photo in users:
            db.execute('INSERT INTO users VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
                (uid,f'user{uid}',f'{uid:03}',name,'','2000-01-01','PAI','Reguler','2026','mahasiswa',1,'','',photo))
        db.commit()
        original='\n'.join(db.iterdump())
    (root/'includes/auth.php').write_text('''<?php
$_SESSION=['user_id'=>99,'role'=>'admin','foto'=>'uploads/profil/admin-private.png'];
define('BASE_URL','https://example.test');
function getDBConnection(){return new PDO('sqlite:'.__DIR__.'/../test.sqlite',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);}
function ensureJenisProgramColumn($pdo){}
function normalizeJenisProgram($s){return $s?:'Reguler';}
function renderJenisProgramBadge($s,$compact){return htmlspecialchars($s,ENT_QUOTES,'UTF-8');}
function formatNoHp($s){return preg_replace('/[^0-9]/','',$s);}
function csrfToken(){return 'fixture-token';}
''',encoding='utf-8')
    (root/'run.php').write_text("<?php $_POST=json_decode($argv[1],true); require __DIR__.'/admin/ajax-users.php'; file_put_contents(__DIR__.'/session.json',json_encode($_SESSION));",encoding='utf-8')
    def request(data):
        result=subprocess.run(['php','-d','display_errors=stderr',str(root/'run.php'),json.dumps(data)],capture_output=True,text=True,encoding='utf-8')
        assert result.returncode==0,result.stderr
        return json.loads(result.stdout)
    data=request({})
    assert data['recordsTotal']==6 and len(data['data'])==6
    cells=[row[3] for row in data['data']]
    assert 'src="https://example.test/uploads/profil/martha.png"' in cells[0]
    assert all(len(row)==11 for row in data['data'])
    assert all('<img ' not in cells[index] for index in [1,2,4,5])
    assert 'onerror="this.remove()"' in cells[0] and '<svg' in cells[0]
    assert '&lt;img' in cells[4] and '<img src=x' not in cells[4]
    assert all('admin-private' not in cell for cell in cells)
    assert json.loads((root/'session.json').read_text())['foto']=='uploads/profil/admin-private.png'
    assert request({'search':{'value':'MARTHA'}})['recordsFiltered']==1
    assert len(request({'order':[{'column':3,'dir':'desc'}]})['data'])==6
    with contextlib.closing(sqlite3.connect(root/'test.sqlite')) as db:
        assert '\n'.join(db.iterdump())==original
    output=ROOT/'scratch/users-table-photo-preview'
    output.mkdir(parents=True,exist_ok=True)
    (output/'cells.json').write_text(json.dumps(cells),encoding='utf-8')
    (output/'photo.png').write_bytes(png)
    print('PASS: actual AJAX renders uploaded profile photo, missing/invalid files use icon, names escaped, admin session photo never reused/changed, 11 columns/search/sort retained, database unchanged')
