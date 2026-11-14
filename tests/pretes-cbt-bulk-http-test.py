"""Real HTTP/native-form deletion; optional Chrome integration via --browser."""
from contextlib import closing
import argparse
import importlib.util
import json
import shutil
import sqlite3
import subprocess
import time
import urllib.parse
from pathlib import Path

repo = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location('xlsx_fixture', repo / 'tests/pretes-cbt-xlsx-http-test.py')
fixture = importlib.util.module_from_spec(spec)
spec.loader.exec_module(fixture)
parser = argparse.ArgumentParser()
parser.add_argument('--browser', action='store_true')
args = parser.parse_args()


def setup(base, root):
    assert fixture.request(base + '/pretes-ujian.php?cbt_bank=1&tab=packages')[0] == 200
    with closing(sqlite3.connect(root / 'includes/state.sqlite')) as db:
        for i in [2, 3, 4]:
            db.execute('INSERT INTO pretes_cbt_packages (id,code,name,is_active,created_at) VALUES (?,?,?,0,?)', (i, f'PKG-{i}', f'Paket {i}', int(time.time())))
            db.execute('INSERT INTO pretes_cbt_sessions (id,code,title,is_active,created_at) VALUES (?,?,?,0,?)', (i, f'SES-{i}', f'Sesi {i}', int(time.time())))
            db.execute('INSERT INTO pretes_cbt_questions (code,package_id,category,question_text,options_json,correct_answer) VALUES (?,?,?,?,?,?)', (f'Q-{i}', i, 'Uji', f'Soal {i}', '{}', 'A'))
        db.execute('INSERT INTO pretes_cbt_attempts (id,user_id,registration_id,session_id,started_at,deadline_at) VALUES (1,7,42,2,?,?)', (int(time.time()), int(time.time()) + 1800))
        db.execute("INSERT INTO pretes_cbt_items VALUES (1,1,999,'Snapshot tetap','{}','A','A',0)")
        db.commit()


with fixture.fixture() as (base, root):
    setup(base, root)
    bank = base + '/pretes-ujian.php?cbt_bank=1'
    headers = {**fixture.ADMIN_HEADERS, 'X-Requested-With': 'XMLHttpRequest', 'Content-Type': 'application/x-www-form-urlencoded'}
    for kind, field, table in [('packages', 'package_ids[]', 'pretes_cbt_packages'), ('sessions', 'session_ids[]', 'pretes_cbt_sessions')]:
        body = {'action': 'delete_' + kind, 'csrf_token': 'test-csrf', field: ['2', '3']}
        assert fixture.request(bank, urllib.parse.urlencode(body, doseq=True).encode(), {**headers, 'X-Test-Admin': '0'})[0] == 403
        assert fixture.request(bank, urllib.parse.urlencode({**body, 'csrf_token': 'forged'}, doseq=True).encode(), headers)[0] == 403
        assert fixture.request(bank, urllib.parse.urlencode({**body, field: ['2', 'oops']}, doseq=True).encode(), headers)[0] == 422
        with closing(sqlite3.connect(root / 'includes/state.sqlite')) as db:
            assert db.execute(f'SELECT COUNT(*) FROM {table}').fetchone()[0] == 4
        status, _, content = fixture.request(bank, urllib.parse.urlencode(body, doseq=True).encode(), headers)
        assert status == 200 and json.loads(content)['success'], content
        with closing(sqlite3.connect(root / 'includes/state.sqlite')) as db:
            assert [r[0] for r in db.execute(f'SELECT id FROM {table} ORDER BY id')] == [1, 4]
        body[field] = ['1', '4']
        assert fixture.request(bank, urllib.parse.urlencode(body, doseq=True).encode(), headers)[0] == 200
        for _ in range(2): assert fixture.request(bank + '&tab=' + kind)[0] == 200
        with closing(sqlite3.connect(root / 'includes/state.sqlite')) as db:
            assert db.execute(f'SELECT COUNT(*) FROM {table}').fetchone()[0] == 0
            assert db.execute('SELECT COUNT(*) FROM pretes_cbt_attempts').fetchone()[0] == 1
            assert db.execute("SELECT question_text FROM pretes_cbt_items").fetchone()[0] == 'Snapshot tetap'
        print('PASS HTTP:', kind, 'authorization, CSRF, invalid selection, exact deletion, empty refresh and preserved snapshots')

if args.browser:
    with fixture.fixture() as (base, root):
        setup(base, root)
        # Real app.js + content-area makes this exercise the SPA submit integration too.
        shutil.copy(repo / 'assets/js/app.js', root / 'assets/js/app.js')
        header = root / 'includes/header.php'
        header.write_text(header.read_text(encoding='utf-8') + '<main class="content-area">', encoding='utf-8')
        (root / 'includes/footer.php').write_text('</main><script src="/assets/js/app.js"></script>', encoding='utf-8')
        auth = root / 'includes/auth.php'
        auth.write_text(auth.read_text(encoding='utf-8').replace('$_SESSION = [];', 'session_start();'), encoding='utf-8')
        subprocess.run(['node', 'tests/pretes-cbt-bulk-browser-test.cjs', base], cwd=repo, check=True)
        with closing(sqlite3.connect(root / 'includes/state.sqlite')) as db:
            for table in ['pretes_cbt_packages', 'pretes_cbt_questions', 'pretes_cbt_sessions']:
                assert db.execute(f'SELECT COUNT(*) FROM {table}').fetchone()[0] == 0
            assert db.execute('SELECT COUNT(*) FROM pretes_cbt_attempts').fetchone()[0] == 1
            assert db.execute("SELECT answer FROM pretes_cbt_items").fetchone()[0] == 'A'
        print('PASS browser database: both lists stay empty; historical attempts and answers remain intact.')
