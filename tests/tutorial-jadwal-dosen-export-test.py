"""Isolated HTTP tests for shareable lecturer schedules."""
import contextlib
import importlib.util
import shutil
import sqlite3
import urllib.error
import urllib.request
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location('lecturer_fixture', ROOT / 'tests/tutorial-scheduled-lecturers-test.py')
lecturers = importlib.util.module_from_spec(spec)
spec.loader.exec_module(lecturers)


@contextlib.contextmanager
def fixture():
    with lecturers.fixture() as (base, root):
        for file in ['admin/tutorial-jadwal-dosen-export.php', 'includes/tutorial-jadwal-dosen-export-view.php']:
            shutil.copy2(ROOT / file, root / file)
        yield base, root


def request(base, query='', admin=True, method='GET'):
    req = urllib.request.Request(base + '/admin/tutorial-jadwal-dosen-export.php?' + query,
                                 headers={'X-Test-Admin': '1' if admin else '0'}, method=method)
    try:
        response = urllib.request.urlopen(req)
    except urllib.error.HTTPError as error:
        response = error
    with response:
        return response.status, response.read().decode('utf-8'), response.headers


if __name__ == '__main__':
    with fixture() as (base, root):
        original = lecturers.snapshot(root)
        status, html, headers = request(base)
        assert status == 200 and 'JADWAL MENGAJAR TUTORIAL' in html
        assert '2 dosen · 3 kelas' in html and html.count('<tr><td') == 3
        assert 'Kelas Genap' not in html and 'Kelas Gelombang Lain' not in html and 'Kelas Lama' not in html
        assert 'Kelas Belum Ada Dosen' not in html and 'Legacy Lama' not in html
        assert 'R Legacy' in html and '13.00 - 14.30' in html
        assert 'N001' not in html and 'Nama Mahasiswa' not in html
        assert 'private, no-store' in headers['Cache-Control']
        assert 'onclick="window.print()"' in html and 'size:A4 landscape' in html
        assert 'gelombang_id=1' in lecturers.request(base, query='gelombang_id=2')[1], 'Export card must pin active period'
        status, html, _ = request(base, 'gelombang_id=2')
        assert status == 200 and 'Kelas Lama' in html and 'Kelas A' not in html
        status, html, _ = request(base, 'gelombang_id=1&dosen=Dosen+Satu')
        assert status == 200 and '1 dosen · 2 kelas' in html and html.count('<tr><td') == 2
        status, html, _ = request(base, 'dosen=Unknown')
        assert status == 200 and 'Belum ada jadwal' in html and 'onclick="window.print()"' not in html
        for query, code in [('gelombang_id=-1',400), ('gelombang_id[]=1',400),
                            ('gelombang_id=999',404), ('gelombang_id=all',400), ('dosen[]=fake',400)]:
            assert request(base, query)[0] == code, query
        assert request(base, admin=False)[0] == 403
        assert request(base, method='POST')[0] == 405
        assert lecturers.snapshot(root) == original, 'Export must not mutate any database record'
        # Changes through the actual schedule editor must appear in a new export.
        data = {'action':'edit_tutorial_schedule','csrf_token':'valid-csrf','schedule_period_id':'1',
                'schedule_class_id':'11','nama_kelas':'Kelas Diperbarui','hari':'Kamis','ruangan':'R2'}
        assert 'alert-success' in lecturers.request(base, data)[1]
        html = request(base)[1]
        assert 'Kelas Diperbarui' in html and '<td>Kamis</td>' in html and '<td>R2</td>' in html
        with contextlib.closing(sqlite3.connect(root / 'test.sqlite')) as db:
            db.execute("UPDATE tutorial_classes SET dosen_pengampu=? WHERE id=11", ('<script>bad</script> & Dosen',))
            db.commit()
        html = request(base)[1]
        assert '&lt;script&gt;bad&lt;/script&gt; &amp; Dosen' in html and '<script>bad</script>' not in html
        with contextlib.closing(sqlite3.connect(root / 'test.sqlite')) as db:
            db.execute("UPDATE master_gelombang SET status='nonaktif'")
            db.commit()
        assert request(base)[0] == 404
        assert request(base, 'gelombang_id=1')[0] == 200, 'Admins may explicitly export a previous period'
        assert 'Fatal error' not in (root / 'server.log').read_text()
        print('PASS: admin GET export, exact period/semester/wave/legacy scope, lecturer filter, latest edited room/day, no student data, escaping, invalid input, no-active handling and read-only export')
