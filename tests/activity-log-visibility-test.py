"""Run actual log queries and admin rendering against isolated records."""
import contextlib
import datetime
import json
import shutil
import sqlite3
import subprocess
import tempfile
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
with tempfile.TemporaryDirectory(prefix='activity-visibility-') as directory:
    root = Path(directory)
    for file in ['admin/log-aktivitas.php', 'includes/activity-logger.php']:
        target = root / file
        target.parent.mkdir(parents=True, exist_ok=True)
        shutil.copy2(ROOT/file, target)
    (root/'config').mkdir()
    (root/'config/database.php').write_text('''<?php
date_default_timezone_set('Asia/Jakarta');
class FixturePDO extends PDO {
    public function exec(string $statement): int|false {
        // Tables already exist; isolate only MySQL-specific bootstrap DDL.
        if (str_contains($statement, 'ENGINE=InnoDB')) return 0;
        return parent::exec($statement);
    }
}
function getDBConnection() { return new FixturePDO('sqlite:' . __DIR__ . '/../test.sqlite', null, null,
    [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]); }
''', encoding='utf-8')
    (root/'includes/auth.php').write_text('''<?php
require_once __DIR__ . '/../config/database.php';
define('BASE_URL','https://example.test');
function requireAdmin() { if (getenv('TEST_NON_ADMIN')) { echo 'Forbidden'; exit; } }
function sanitize($s) { return htmlspecialchars((string)$s,ENT_QUOTES,'UTF-8'); }
''', encoding='utf-8')
    (root/'includes/header.php').write_text('<!doctype html><html><body>', encoding='utf-8')
    (root/'includes/footer.php').write_text('</body></html>', encoding='utf-8')
    (root/'query.php').write_text('''<?php
require __DIR__ . '/includes/activity-logger.php';
echo json_encode([
    'login'=>getRecentLoginLogs(100,'zainulhakim'), 'click'=>getRecentClickLogs(200,'zainulhakim'),
    'limited'=>getRecentLoginLogs(2,' ZAINULHAKIM '), 'stats'=>getActivityLogStats('zainulhakim'),
    'all'=>getActivityLogStats(), 'injection'=>getActivityLogStats("x' OR 1=1 --")
]);
''', encoding='utf-8')
    today = datetime.datetime.now(datetime.timezone(datetime.timedelta(hours=7))).date()
    with contextlib.closing(sqlite3.connect(root/'test.sqlite')) as db:
        db.executescript('''
            CREATE TABLE users (id INTEGER PRIMARY KEY, username TEXT, no_hp TEXT);
            INSERT INTO users VALUES (7,'zainulhakim','0700'),(8,'student','0800'),(9,'zainulhakim2','0900');
            CREATE TABLE user_login_logs (id INTEGER PRIMARY KEY,user_id INTEGER,username TEXT,nama_lengkap TEXT,
                role TEXT DEFAULT 'mahasiswa',no_hp TEXT,ip_address TEXT,user_agent TEXT,device TEXT DEFAULT 'Desktop',
                browser TEXT DEFAULT 'Chrome',status TEXT DEFAULT 'success',keterangan TEXT,created_at TEXT);
            CREATE TABLE user_click_logs (id INTEGER PRIMARY KEY,user_id INTEGER,username TEXT,nama_lengkap TEXT,
                role TEXT DEFAULT 'mahasiswa',element_text TEXT,element_type TEXT,element_id TEXT,element_class TEXT,
                target_url TEXT,page_url TEXT,page_title TEXT,ip_address TEXT,user_agent TEXT,created_at TEXT);
        ''')
        for index in range(120):
            uid, username = [(7,'zainulhakim'), (None,' ZAINULHAKIM '), (7,'old_snapshot_name'), (7,None)][index % 4]
            for table in ['user_login_logs','user_click_logs']:
                db.execute(f'INSERT INTO {table} (id,user_id,username,nama_lengkap,created_at) VALUES (?,?,?,?,?)',
                           (index+1,uid,username,'Hidden Account',f'{today} 23:59:00'))
        for row_id, uid, username, date in [(201,8,'student',today),(202,9,'zainulhakim2',today),(203,None,None,today-datetime.timedelta(days=1))]:
            for table in ['user_login_logs','user_click_logs']:
                db.execute(f'INSERT INTO {table} (id,user_id,username,nama_lengkap,created_at) VALUES (?,?,?,?,?)',
                           (row_id,uid,username,'Visible Participant',f'{date} 01:00:00'))
        db.commit()
        original = '\n'.join(db.iterdump())

    def run(file):
        result = subprocess.run(['php','-d','display_errors=stderr',str(root/file)], capture_output=True,text=True,encoding='utf-8')
        assert result.returncode == 0 and 'Fatal error' not in result.stderr, result.stderr
        return result.stdout

    data = json.loads(run('query.php'))
    assert {row['id'] for row in data['login']} == {201,202,203}
    assert {row['id'] for row in data['click']} == {201,202,203}
    assert len(data['limited']) == 2
    assert data['stats'] == {'login_today':2,'click_today':2,'total_login':3,'total_click':3}
    assert data['all']['total_login'] == 123 and data['all']['total_click'] == 123
    assert data['injection'] == data['all'], 'Username must be parameterized'
    html = run('admin/log-aktivitas.php')
    assert 'Hidden Account' not in html and 'old_snapshot_name' not in html
    assert '<code>zainulhakim</code>' not in html and '<code>zainulhakim2</code>' in html
    assert 'Visible Participant' in html and '3 Total Riwayat' in html and '3 Total Aktivitas' in html
    with contextlib.closing(sqlite3.connect(root/'test.sqlite')) as db:
        assert original == '\n'.join(db.iterdump()), 'Viewing must retain all original records'
        db.execute('DELETE FROM user_login_logs WHERE id>=201')
        db.execute('DELETE FROM user_click_logs WHERE id>=201')
        db.commit()
    html = run('admin/log-aktivitas.php')
    assert 'Belum ada riwayat login' in html and 'Belum ada aktivitas klik' in html
    assert '0 Total Riwayat' in html and '0 Total Aktivitas' in html
    print('PASS: login/click rows, daily/total counters, normalized username and account ID, filter before LIMIT, anonymous/similar usernames retained, default behavior, parameter binding, actual admin page and empty states; original logs unchanged')
