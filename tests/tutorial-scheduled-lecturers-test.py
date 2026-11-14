"""Full admin GET/POST integration on isolated fixtures; no production records changed."""
import contextlib
import importlib.util
import shutil
import sqlite3
import urllib.error
import urllib.parse
import urllib.request
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location('attendance_fixture', ROOT / 'tests/tutorial-absensi-http-test.py')
attendance = importlib.util.module_from_spec(spec)
spec.loader.exec_module(attendance)


@contextlib.contextmanager
def fixture():
    with attendance.fixture() as (base, root):
        for file in ['includes/tutorial-scheduled-lecturers-view.php', 'assets/css/style.css']:
            target = root / file
            target.parent.mkdir(parents=True, exist_ok=True)
            shutil.copy2(ROOT / file, target)
        with contextlib.closing(sqlite3.connect(root / 'test.sqlite')) as db:
            db.executescript('''
                ALTER TABLE users ADD COLUMN role TEXT DEFAULT 'mahasiswa';
                ALTER TABLE tutorial_registrations ADD COLUMN hari_pilihan TEXT DEFAULT 'Senin';
                CREATE TABLE rooms (id INTEGER PRIMARY KEY, ruang TEXT);
                INSERT INTO rooms VALUES (1,'R1'),(2,'R2');
                INSERT INTO tutorial_classes VALUES
                    (14,'Kelas Genap','Rabu','13.00','Dosen Semester Lain','R1','gel1','2026-2027-Genap'),
                    (15,'Kelas Gelombang Lain','Rabu','13.00','Dosen Gelombang Lain','R1','gel2','2026-2027-Ganjil'),
                    (16,'Kelas Belum Ada Dosen','Rabu','13.00','  ','R1','gel1','2026-2027-Ganjil'),
                    (17,'Kelas Legacy','Rabu','13.00','Dosen Legacy','R Legacy','gel1','Ganjil'),
                    (18,'Legacy Lama','Rabu','13.00','Dosen Lama','R1','gel1','Ganjil');
                INSERT INTO tutorial_registrations (id,user_id,tutorial_class_id,tahun_ajaran,created_at)
                    VALUES (101,1001,17,'2026/2027','2026-11-02'),(102,1002,18,'2025-2026','2025-11-02');
            ''')
            db.commit()
        with (root / 'includes/auth.php').open('a', encoding='utf-8') as auth:
            auth.write("\nfunction csrfToken() { return 'valid-csrf'; }\nfunction verifyCsrf($token) { return $token === 'valid-csrf'; }\n")
        (root / 'includes/header.php').write_text('''<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><link rel="stylesheet" href="/assets/css/style.css"><style>body{display:block;padding:24px;background:#f1f5f9}</style></head><body>''', encoding='utf-8')
        (root / 'includes/footer.php').write_text('</body></html>', encoding='utf-8')
        yield base, root


def request(base, data=None, query='', admin=True):
    payload = urllib.parse.urlencode(data).encode() if data is not None else None
    req = urllib.request.Request(base + '/admin/tutorial-hasil-plotting.php?' + query, data=payload,
                                 headers={'X-Test-Admin': '1' if admin else '0'})
    try:
        response = urllib.request.urlopen(req)
    except urllib.error.HTTPError as error:
        response = error
    with response:
        return response.status, response.read().decode('utf-8')


def snapshot(root):
    with contextlib.closing(sqlite3.connect(root / 'test.sqlite')) as db:
        return '\n'.join(db.iterdump())


if __name__ == '__main__':
    with fixture() as (base, root):
        status, html = request(base)
        assert status == 200 and 'Dosen Terjadwal Tutorial Aktif' in html
        card = html.split('id="scheduledTutorialLecturers"')[1].split('<dialog')[0]
        assert all(f'data-schedule-id="{value}"' in card for value in [11, 12, 17])
        assert all(f'data-schedule-id="{value}"' not in card for value in [13, 14, 15, 16, 18])
        assert '3 jadwal kelas' in card
        assert 'data-schedule-id="11"' in request(base, query='gelombang_id=2')[1], 'Card must follow active period even when main table views history'
        assert request(base, admin=False)[0] == 403
        original = snapshot(root)
        data = {'action': 'edit_tutorial_schedule', 'csrf_token': 'valid-csrf',
                'schedule_period_id': '1', 'schedule_class_id': '11', 'nama_kelas': 'Kelas Baru', 'hari': 'Kamis', 'ruangan': 'R2'}
        for patch in [{'csrf_token': 'wrong'}, {'schedule_class_id': '13'}, {'schedule_class_id': '14'},
                      {'schedule_class_id': '15'}, {'schedule_class_id': '18'}, {'schedule_period_id': '2'},
                      {'hari': 'Minggu'}, {'nama_kelas': ''}, {'nama_kelas': 'X'*101},
                      {'nama_kelas[]': 'array'}, {'schedule_class_id': '-1'}, {'ruangan': 'Unknown room'}, {'ruangan[]': 'R2'}]:
            payload = dict(data, **patch)
            if 'nama_kelas[]' in payload:
                payload.pop('nama_kelas')
            if 'ruangan[]' in payload:
                payload.pop('ruangan')
            status, html = request(base, payload)
            assert status == 200 and ('alert-danger' in html), (patch, html[:300])
            assert snapshot(root) == original, f'Invalid request mutated data: {patch}'
        assert request(base, data, admin=False)[0] == 403
        assert snapshot(root) == original
        with contextlib.closing(sqlite3.connect(root / 'test.sqlite')) as db:
            registrations = db.execute('SELECT * FROM tutorial_registrations ORDER BY id').fetchall()
            other_classes = db.execute('SELECT * FROM tutorial_classes WHERE id != 11 ORDER BY id').fetchall()
        status, html = request(base, data, query='gelombang_id=2')
        assert status == 200 and 'alert-success' in html
        with contextlib.closing(sqlite3.connect(root / 'test.sqlite')) as db:
            assert db.execute('SELECT nama_kelas,hari,dosen_pengampu,jam,ruangan FROM tutorial_classes WHERE id=11').fetchone() == ('Kelas Baru','Kamis','Dosen Satu','13.00 - 14.30','R2')
            assert db.execute('SELECT * FROM tutorial_classes WHERE id != 11 ORDER BY id').fetchall() == other_classes
            assert db.execute('SELECT * FROM tutorial_registrations ORDER BY id').fetchall() == registrations
        assert 'Kelas Baru' in request(base)[1]
        assert 'Kelas Baru' in attendance.request(base, 'kelas_id=11')[1], 'Printing must reflect changed schedule'
        assert '<strong>Ruangan:</strong> R2' in attendance.request(base, 'kelas_id=11')[1]
        # Same values can safely be saved again.
        after = snapshot(root)
        assert 'alert-success' in request(base, data)[1] and snapshot(root) == after
        # A room may be cleared, or retain a legacy option still used by active classes.
        assert 'alert-success' in request(base, dict(data, ruangan=''))[1]
        with contextlib.closing(sqlite3.connect(root / 'test.sqlite')) as db:
            assert db.execute('SELECT ruangan FROM tutorial_classes WHERE id=11').fetchone()[0] is None
        assert 'alert-success' in request(base, dict(data, ruangan='R Legacy'))[1]
        with contextlib.closing(sqlite3.connect(root / 'test.sqlite')) as db:
            assert db.execute('SELECT ruangan FROM tutorial_classes WHERE id=11').fetchone()[0] == 'R Legacy'
        assert 'alert-success' in request(base, data)[1]
        # A failed database update must leave no partially changed schedule.
        with contextlib.closing(sqlite3.connect(root / 'test.sqlite')) as db:
            db.executescript("CREATE TRIGGER fail_schedule BEFORE UPDATE ON tutorial_classes BEGIN SELECT RAISE(ABORT, 'simulated update failure'); END;")
        before_failure = snapshot(root)
        assert 'Jadwal belum tersimpan' in request(base, dict(data, hari='Jumat'))[1]
        assert snapshot(root) == before_failure
        with contextlib.closing(sqlite3.connect(root / 'test.sqlite')) as db:
            db.executescript('DROP TRIGGER fail_schedule;')
            db.execute("UPDATE master_gelombang SET status='nonaktif'")
            db.commit()
        no_active = snapshot(root)
        status, html = request(base)
        assert status == 200 and 'Belum ada periode tutorial aktif.' in html
        assert 'data-edit-tutorial-schedule' not in html
        assert 'alert-danger' in request(base, data)[1] and snapshot(root) == no_active
        assert 'Fatal error' not in (root / 'server.log').read_text()
        print('PASS: full admin page, active-only scope, class/day/room persist, master/legacy/clear room choices, print reflects room, unchanged roster/teacher/time, CSRF/admin/invalid-room/stale-period validation, rollback and empty state')
