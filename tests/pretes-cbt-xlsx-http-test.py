"""Real upload/download tests using only a temporary PHP server and SQLite fixtures."""
import contextlib
import io
import json
import shutil
import socket
import sqlite3
import subprocess
import tempfile
import time
import urllib.error
import urllib.request
import zipfile
from pathlib import Path

REPO = Path(__file__).resolve().parents[1]
ADMIN_HEADERS = {'X-Test-Login': '1', 'X-Test-Admin': '1', 'X-Test-Verified': '1'}


@contextlib.contextmanager
def fixture():
    with tempfile.TemporaryDirectory(prefix='lppai-cbt-excel-') as temp:
        root = Path(temp)
        assert root.resolve().parent == Path(tempfile.gettempdir()).resolve() and root.name.startswith('lppai-cbt-excel-')
        files = ['admin/pretes-bank-soal.php', 'api/pretes-cbt.php', 'pages/pretes-ujian.php',
                 'includes/pretes-cbt.php', 'includes/pretes-cbt-xlsx.php', 'includes/pretes-cbt-admin-view.php',
                 'includes/pretes-cbt-view.php', 'data/pretes-cbt-bank.php', 'downloads/template-bank-soal-cbt.xlsx',
                 'assets/css/style.css', 'assets/css/pretes-cbt-admin.css', 'assets/css/pretes-cbt-exam.css',
                 'assets/js/pretes-cbt-admin.js', 'assets/js/pretes-cbt.js']
        for name in files:
            (root / name).parent.mkdir(parents=True, exist_ok=True)
            shutil.copy(REPO / name, root / name)
        (root / 'pretes-ujian.php').write_text("<?php require __DIR__ . '/pages/pretes-ujian.php';", encoding='utf-8')
        (root / 'includes/auth.php').write_text('''<?php
define('BASE_URL', 'http://' . $_SERVER['HTTP_HOST']);
$_SESSION = [];
if (($_SERVER['HTTP_X_TEST_VERIFIED'] ?? '') === '1') $_SESSION['pretes_cbt_verified_v2_42'] = time();
function isLoggedIn() { return ($_SERVER['HTTP_X_TEST_LOGIN'] ?? '') === '1'; }
function requireAdmin() { if (!isLoggedIn() || ($_SERVER['HTTP_X_TEST_ADMIN'] ?? '') !== '1') { http_response_code(403); echo 'Forbidden'; exit; } }
function verifyCsrf($token) { return $token === 'test-csrf'; }
function csrfToken() { return 'test-csrf'; }
function sanitize($text) { return htmlspecialchars((string)$text, ENT_QUOTES, 'UTF-8'); }
function getCurrentUser() { return ['id'=>7,'username'=>'student','nim'=>'001234']; }
function getDBConnection() {
    $pdo = new PDO('sqlite:' . __DIR__ . '/state.sqlite', null, null, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $pdo->exec('CREATE TABLE IF NOT EXISTS pretes_registrations (id INTEGER PRIMARY KEY, user_id INTEGER)');
    $pdo->exec('INSERT OR IGNORE INTO pretes_registrations VALUES (42,7)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS users (id INTEGER PRIMARY KEY, nim TEXT, nama_lengkap TEXT)');
    $pdo->exec("INSERT OR IGNORE INTO users VALUES (7,'001234','Peserta Uji')");
    return $pdo;
}
''', encoding='utf-8')
        (root / 'includes/header.php').write_text('''<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><link rel="stylesheet" href="/assets/css/style.css"><style>body{display:block;margin:0;background:#f3f6f1;padding:24px;box-sizing:border-box}@media(max-width:500px){body{padding:12px}}</style>''', encoding='utf-8')
        (root / 'includes/footer.php').write_text('', encoding='utf-8')
        (root / 'preview-student.php').write_text('''<?php require __DIR__.'/includes/auth.php'; include __DIR__.'/includes/header.php'; require __DIR__.'/includes/pretes-cbt-view.php'; ?>
<script src="/assets/js/pretes-cbt.js"></script><script>
window.PretesCbt.init({request:async function(payload){
 const response=await fetch('/api/pretes-cbt.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(Object.assign({csrf_token:'test-csrf',registration_id:42},payload))});
 const data=await response.json(); if(!response.ok)throw new Error(data.message);return data;
}});
</script>''', encoding='utf-8')
        with socket.socket() as sock:
            sock.bind(('127.0.0.1', 0)); port = sock.getsockname()[1]
        with (root / 'server.log').open('w') as log:
            process = subprocess.Popen(['php', '-d', 'display_errors=0', '-S', f'127.0.0.1:{port}', '-t', str(root)], stdout=log, stderr=log)
            try:
                for _ in range(80):
                    try:
                        with socket.create_connection(('127.0.0.1', port), timeout=0.1): break
                    except OSError: time.sleep(0.05)
                yield f'http://127.0.0.1:{port}', root
            finally:
                process.terminate(); process.wait(timeout=5)


def request(url, body=None, headers=None):
    req = urllib.request.Request(url, data=body, headers=headers or ADMIN_HEADERS)
    try:
        with urllib.request.urlopen(req, timeout=10) as response: return response.status, response.headers, response.read()
    except urllib.error.HTTPError as error:
        return error.code, error.headers, error.read()


def upload(url, data, filename='bank.xlsx', csrf='test-csrf', overwrite=False):
    boundary = 'LppaiExcelTestBoundary'
    parts = []
    fields = {'action':'import_xlsx', 'csrf_token':csrf}
    if overwrite: fields['update_existing'] = '1'
    for name, value in fields.items():
        parts.append(f'--{boundary}\r\nContent-Disposition: form-data; name="{name}"\r\n\r\n{value}\r\n'.encode())
    parts.append(f'--{boundary}\r\nContent-Disposition: form-data; name="question_file"; filename="{filename}"\r\nContent-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet\r\n\r\n'.encode()+data+b'\r\n')
    parts.append(f'--{boundary}--\r\n'.encode())
    return request(url, b''.join(parts), {**ADMIN_HEADERS,'Content-Type':f'multipart/form-data; boundary={boundary}'})


if __name__ == '__main__':
    with fixture() as (base, root):
        bank = base + '/pretes-ujian.php?cbt_bank=1'
        assert request(bank + '&download=template', headers={'X-Test-Login':'1','X-Test-Admin':'0'})[0] == 403
        status, headers, template = request(bank + '&download=template')
        assert status == 200 and template[:2] == b'PK'
        assert headers['Content-Type'] == 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
        assert len(zipfile.ZipFile(io.BytesIO(template)).namelist()) > 5
        status, _, content = request(bank)
        assert status == 200 and b'Koleksi soal' in content
        status, _, exported = request(bank + '&download=bank')
        assert status == 200 and exported[:2] == b'PK'
        assert upload(bank, exported, csrf='forged')[0] == 403
        assert upload(bank, exported, filename='file.csv')[0] == 422
        assert upload(bank, exported)[0] == 200
        with contextlib.closing(sqlite3.connect(root / 'includes/state.sqlite')) as db:
            assert db.execute('SELECT COUNT(*) FROM pretes_cbt_questions').fetchone()[0] == 40
        assert upload(bank, exported, overwrite=True)[0] == 200
        broken = io.BytesIO()
        with zipfile.ZipFile(io.BytesIO(exported)) as old, zipfile.ZipFile(broken,'w') as new:
            for name in old.namelist():
                value = old.read(name)
                if name == 'xl/worksheets/sheet1.xml': value = value.replace(b'<c r="I2" s="0" t="inlineStr"><is><t xml:space="preserve">C</t>', b'<c r="I2" s="0" t="inlineStr"><is><t xml:space="preserve">Z</t>', 1)
                new.writestr(name, value)
        status, _, error = upload(bank, broken.getvalue())
        assert status == 422 and b'Baris 2' in error, (status, error[:300])
        with contextlib.closing(sqlite3.connect(root / 'includes/state.sqlite')) as db:
            assert db.execute("SELECT correct_answer FROM pretes_cbt_questions WHERE code='initial-1'").fetchone()[0] == 'C'
        assert request(base + '/pretes-ujian.php?cbt_style=../../config/database.php')[0] == 404
        assert request(base + '/pretes-ujian.php?cbt_style=bank')[0] == 200
        assert request(base + '/pretes-ujian.php?cbt_admin_asset=1')[0] == 200
        print('PASS: admin-only XLSX downloads, real multipart upload, CSRF, extension validation, reimport/update, row errors, atomic rollback, and asset routes.')
