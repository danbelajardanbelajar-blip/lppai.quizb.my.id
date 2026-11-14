"""Run the real generation endpoint against an isolated SQLite fixture (no production data)."""
import json
import shutil
import sqlite3
import subprocess
import tempfile
from contextlib import closing
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def scenario(mode, overwrite, failure=None):
    with tempfile.TemporaryDirectory(prefix='tutorial-plotting-') as directory:
        root = Path(directory)
        for file in ['admin/tutorial-generate.php', 'includes/tutorial-plotting.php']:
            target = root / file
            target.parent.mkdir(exist_ok=True)
            shutil.copy2(ROOT / file, target)
        database = root / 'test.sqlite'
        with closing(sqlite3.connect(database)) as db:
            db.executescript('''
                CREATE TABLE master_gelombang (id INTEGER PRIMARY KEY, status TEXT, tahun_ajaran TEXT,
                    semester TEXT, gelombang TEXT, created_at TEXT, tutors_senin TEXT);
                INSERT INTO master_gelombang VALUES
                    (1,'aktif','2026/2027','Ganjil','gel1','2026-11-01','Tutor A|||Tutor B'),
                    (2,'nonaktif','2027-2028','Genap','gel2','2026-11-10','Other Tutor');
                CREATE TABLE users (nim TEXT, nama_lengkap TEXT, role TEXT);
                INSERT INTO users VALUES ('1','Tutor A','dosen'),('2','Tutor B','dosen');
                CREATE TABLE tutorial_classes (id INTEGER PRIMARY KEY AUTOINCREMENT, nama_kelas TEXT,
                    dosen_pengampu TEXT, hari TEXT, jam TEXT, ruangan TEXT, gelombang TEXT,
                    semester TEXT, kuota INTEGER);
                INSERT INTO tutorial_classes VALUES
                    (1,'Existing','Old Tutor','Senin','09:00','R1','gel1','2026/2027-Ganjil',30),
                    (2,'Historical','Old Tutor','Senin','09:00','R2','gel1','2025-2026-Ganjil',30),
                    (3,'Completed','Old Tutor','Senin','09:00','R3','gel1','2026-2027-Ganjil',30),
                    (4,'Replace me','Old Tutor','Senin','09:00','R4','gel1','2026-2027-Ganjil',30);
                CREATE TABLE tutorial_registrations (id INTEGER PRIMARY KEY, user_id INTEGER,
                    tutorial_class_id INTEGER, tahun_ajaran TEXT, gelombang TEXT, status TEXT,
                    created_at TEXT, hari_pilihan TEXT, tipe_nilai TEXT, nilai_akhir REAL);
                INSERT INTO tutorial_registrations VALUES
                    (1,1,1,'2026/2027','gel1','aktif','2026-11-02','Senin','gel 1',NULL),
                    (2,2,4,NULL,NULL,'terdaftar','2026-11-02','Senin','gel 1',NULL),
                    (3,3,NULL,'2026-2027','gel1','terdaftar','2026-11-02','Senin',NULL,NULL),
                    (4,4,2,'2025-2026','gel1','aktif','2025-11-02','Senin','gel 1',85),
                    (5,5,NULL,'2025-2026','gel1','terdaftar','2025-11-02','Senin',NULL,NULL),
                    (6,6,NULL,NULL,'gel1','terdaftar','2025-11-02','Senin',NULL,NULL),
                    (7,7,1,'2026-2027','gel1','lulus','2026-11-02','Senin','gel 1',90),
                    (8,8,NULL,'2026-2027','gel1','mengundurkan_diri','2026-11-02','Senin',NULL,NULL),
                    (9,9,NULL,'2026-2027','gel2','terdaftar','2026-11-02','Senin',NULL,NULL),
                    (10,10,NULL,NULL,'gel1','terdaftar','2026-11-02','Senin',NULL,NULL);
            ''')
            if failure == 'tutor':
                db.execute("UPDATE master_gelombang SET tutors_senin = '' WHERE id = 1")
            if failure == 'insert':
                db.executescript("CREATE TRIGGER fail_insert BEFORE INSERT ON tutorial_classes BEGIN SELECT RAISE(ABORT, 'simulated failure'); END;")
            db.commit()
            before_regs = db.execute('SELECT * FROM tutorial_registrations ORDER BY id').fetchall()
            before_classes = db.execute('SELECT * FROM tutorial_classes ORDER BY id').fetchall()
        (root / 'includes/auth.php').write_text('''<?php
            function requireAdmin() {}
            function verifyCsrf($token) { return $token === 'valid'; }
            function getDBConnection() {
                $pdo = new PDO('sqlite:' . __DIR__ . '/../test.sqlite');
                $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
                $pdo->sqliteCreateFunction('CONCAT', fn(...$parts) => implode('', $parts));
                return $pdo;
            }
        ''', encoding='utf-8')
        post = {'csrf_token': 'invalid' if failure == 'csrf' else 'valid',
                'generate_mode': mode, 'min_per_class': '2'}
        if overwrite:
            post['overwrite_plotting'] = '1'
        runner = root / 'run.php'
        runner.write_text("<?php $_SERVER['REQUEST_METHOD'] = 'POST'; $_POST = json_decode('"
                          + json.dumps(post) + "', true); require __DIR__ . '/admin/tutorial-generate.php';",
                          encoding='utf-8')

        def run():
            result = subprocess.run(['php', '-d', 'display_errors=stderr', str(runner)], capture_output=True, text=True)
            assert result.returncode == 0, result.stdout + result.stderr
            return result.stdout

        output = run()
        with closing(sqlite3.connect(database)) as db:
            after_regs = db.execute('SELECT * FROM tutorial_registrations ORDER BY id').fetchall()
            after_classes = db.execute('SELECT * FROM tutorial_classes ORDER BY id').fetchall()
        if failure:
            assert output, 'Expected validation/transaction failure'
            assert after_regs == before_regs, 'Failure must restore all registration data'
            assert after_classes == before_classes, 'Failure must restore original classes'
        else:
            assert not output.strip(), output
            for index in [3, 4, 5, 6, 7, 8]:
                assert after_regs[index] == before_regs[index], 'Unrelated period/status modified'
            assert after_regs[2][2] is not None and after_regs[9][2] is not None
            if overwrite:
                assert all(after_regs[i][2] not in [None, 1, 2, 3, 4] for i in [0, 1, 2, 9])
                assert not any(c[0] == 4 for c in after_classes), 'Empty replaced class remains'
                assert after_classes[:3] == before_classes[:3], 'Shared/historical/completed classes must remain'
                assert len({after_regs[i][2] for i in [0, 1, 2, 9]}) == 2
            else:
                assert after_regs[:2] == before_regs[:2], 'Unchecked must retain existing plotting'
                assert after_classes[:len(before_classes)] == before_classes
            run()
            with closing(sqlite3.connect(database)) as db:
                assert db.execute('SELECT COUNT(*) FROM tutorial_classes').fetchone()[0] == len(after_classes), 'Repeated generation accumulates classes'
        print(f'PASS: {mode}, overwrite={overwrite}, failure={failure}')


for mode in ['fill_first', 'distribute_evenly']:
    for overwrite in [False, True]:
        scenario(mode, overwrite)
scenario('fill_first', True, 'tutor')
scenario('fill_first', True, 'insert')
scenario('fill_first', True, 'csrf')
view = (ROOT / 'admin/tutorial-pengaturan-generate.php').read_text(encoding='utf-8')
assert 'name="overwrite_plotting" value="1"' in view
assert 'Tindih plotting jadwal yang telah ada' in view
print('PASS: checkbox included in generation form')
