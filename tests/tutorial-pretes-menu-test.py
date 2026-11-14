"""Render actual sidebar/dashboard against isolated tutorial membership fixtures."""
import json
import os
import re
import shutil
import subprocess
import tempfile
from pathlib import Path

repo = Path(__file__).resolve().parents[1]
with tempfile.TemporaryDirectory(prefix='lppai-tutorial-menu-') as temp:
    root = Path(temp)
    for name in ['includes/tutorial-membership.php', 'includes/sidebar.php', 'includes/pretes-helper.php',
                 'includes/pretes-face-engine.php', 'includes/user-helper.php', 'includes/announcement-helper.php', 'includes/pretes-tryout.php', 'pages/dashboard.php']:
        target = root / name
        target.parent.mkdir(parents=True, exist_ok=True)
        shutil.copy(repo / name, target)
    # Simulate the first Friday so attendance-banner visibility is actually exercised.
    dashboard = root / 'pages/dashboard.php'
    source = dashboard.read_text(encoding='utf-8')
    assert "$isFirstFriday = (date('w') == 5 && date('j') <= 7);" in source
    dashboard.write_text(source.replace("$isFirstFriday = (date('w') == 5 && date('j') <= 7);", '$isFirstFriday = true;'), encoding='utf-8')
    # Use the actual active-period registration rules; isolate the unrelated official exam gate.
    access_source = (repo / 'includes/pretes-exam-access.php').read_text(encoding='utf-8')
    registration_functions = []
    for name in ['pretesExamActivePeriodFor', 'pretesExamActiveRegistration', 'pretesExamIsRegisteredActive']:
        match = re.search(r'^function ' + name + r'\b.*?^}', access_source, re.M | re.S)
        assert match, name
        registration_functions.append(match.group())
    (root / 'includes/pretes-exam-access.php').write_text("<?php function pretesExamAccess($pdo, $user) { return ['allowed'=>false]; }\n" + '\n'.join(registration_functions), encoding='utf-8')
    # Keep the real tryout list/window functions; isolate unrelated CBT schema migrations.
    (root / 'includes/pretes-cbt.php').write_text('''<?php
function ensurePretesCbtTables($pdo) {}
function pretesCbtGetSession($pdo, $id) { $s=$pdo->prepare('SELECT * FROM pretes_cbt_sessions WHERE id=?'); $s->execute([$id]); return $s->fetch(PDO::FETCH_ASSOC) ?: null; }
''', encoding='utf-8')
    (root / 'includes/auth.php').write_text('''<?php
define('BASE_URL', 'https://example.test'); define('APP_NAME', 'Test LPPAI');
$_SERVER['PHP_SELF'] = '/dashboard.php';
require_once __DIR__ . '/user-helper.php';
function requireLogin() {}
function csrfToken() { return 'fixture-token'; }
function getCurrentUser() { $c = json_decode(getenv('CASE_JSON'), true); return ['id'=>7,'role'=>$c['role'] ?? 'mahasiswa','nama_lengkap'=>'Peserta','nim'=>'2300007','jenis_program'=>'Reguler','program_studi'=>'PAI','fakultas'=>'Agama Islam']; }
function isAdmin() { return getCurrentUser()['role'] === 'admin'; }
function sanitize($text) { return htmlspecialchars((string)$text, ENT_QUOTES, 'UTF-8'); }
function renderDosenWithWa($pdo, $name, $user, $scope = null, $fallback = '-') { return sanitize($name ?: $fallback); }
function getDBConnection() {
    static $pdo = null; if ($pdo) return $pdo;
    $c = json_decode(getenv('CASE_JSON'), true);
    $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
    $pdo->exec("CREATE TABLE master_gelombang (id INTEGER, status TEXT, tahun_ajaran TEXT, semester TEXT, gelombang TEXT, created_at TEXT);
        INSERT INTO master_gelombang VALUES (1,'aktif','2026-2027','Ganjil','gel1','2026-11-01');
        CREATE TABLE tutorial_classes (id INTEGER, gelombang TEXT, semester TEXT, nama_kelas TEXT, hari TEXT DEFAULT '', jam TEXT DEFAULT '', ruangan TEXT DEFAULT '', dosen_pengampu TEXT DEFAULT '');
        INSERT INTO tutorial_classes (id,gelombang,semester,nama_kelas) VALUES (1,'gel1','2026/2027-Ganjil','Kelas Aktif'),(2,'gel1','2026-2027-Genap','Kelas Beda Semester');
        CREATE TABLE tutorial_registrations (id INTEGER PRIMARY KEY, user_id INTEGER, tutorial_class_id INTEGER, status TEXT, tahun_ajaran TEXT, gelombang TEXT, tipe_nilai TEXT, created_at TEXT, nomor_sertifikat TEXT DEFAULT NULL, hari_pilihan TEXT DEFAULT 'Senin', hari TEXT DEFAULT '', jam TEXT DEFAULT '', ruangan TEXT DEFAULT '', nilai_akhir REAL DEFAULT NULL);
        CREATE TABLE master_pretes (id INTEGER, periode TEXT, status TEXT, target_program TEXT, tanggal_mulai TEXT);
        INSERT INTO master_pretes VALUES (1,'2026/2027','aktif','Semua','2026-11-16');
        CREATE TABLE pretes_results (id INTEGER, user_id INTEGER, pretes_schedule_id INTEGER, created_at TEXT, status_lulus TEXT, nilai REAL);
        CREATE TABLE pretes_schedules (id INTEGER, tanggal TEXT, waktu_mulai TEXT, waktu_selesai TEXT, ruangan TEXT);
        CREATE TABLE pretes_registrations (id INTEGER, user_id INTEGER, periode TEXT, pretes_schedule_id INTEGER, tanggal_daftar TEXT, hari_pilihan TEXT, jam TEXT, ruangan TEXT, dosen_penguji TEXT, status TEXT, foto TEXT);
        INSERT INTO pretes_registrations VALUES (1,7,'2026/2027',NULL,'2026-11-02','Senin','13.00','','','terdaftar',NULL);
        CREATE TABLE pretes_assessments (registration_id INTEGER, scores_json TEXT);
        CREATE TABLE announcements (id INTEGER, judul TEXT, konten TEXT, tipe TEXT, link_tujuan TEXT, created_at TEXT, is_active INTEGER, target_program TEXT, target_peserta TEXT);
        INSERT INTO announcements VALUES (1,'Info pretes','Belajar pretes','hasil_pretes','/pretes-hasil.php','2026-11-13',1,'Semua','semua'),(2,'Tautan pretes','Panduan','info','/pages/pretes-peserta.php','2026-11-13',1,'Semua','semua'),(3,'Informasi umum','Belajar tutorial','info','/tutorial-info.php','2026-11-13',1,'Semua','semua');");
    if (!empty($c['inactive'])) $pdo->exec("UPDATE master_gelombang SET status='nonaktif'");
    if (!empty($c['multi'])) $pdo->exec("INSERT INTO master_gelombang VALUES (2,'aktif','2026-2027','Genap','gel2','2026-11-01')");
    if (!empty($c['pretes_inactive'])) $pdo->exec("UPDATE master_pretes SET status='nonaktif'");
    if (!empty($c['pretes_old'])) $pdo->exec("UPDATE pretes_registrations SET periode='2024/2025'; UPDATE master_pretes SET periode='2024/2025',status='nonaktif'");
    if (!empty($c['no_pretes']) || !empty($c['result_only'])) $pdo->exec('DELETE FROM pretes_registrations');
    if (!empty($c['result_only'])) $pdo->exec("INSERT INTO pretes_results VALUES (1,7,NULL,'2024-11-01','tidak_lulus',60)");
    if (!empty($c['pretes_passed'])) $pdo->exec("INSERT INTO pretes_results VALUES (1,7,NULL,'2026-11-01','lulus',90)");
    $pdo->exec('CREATE TABLE pretes_cbt_sessions (id INTEGER, mode TEXT, is_active INTEGER, start_time TEXT, end_time TEXT, title TEXT, max_attempts INTEGER DEFAULT 0)');
    $sessionInsert = $pdo->prepare('INSERT INTO pretes_cbt_sessions (id,mode,is_active,start_time,end_time,title) VALUES (?,?,?,?,?,?)');
    foreach (($c['tryout_sessions'] ?? []) as $session) $sessionInsert->execute([$session['id'], $session['mode'] ?? 'tryout', $session['is_active'] ?? 1, $session['start_time'] ?? null, $session['end_time'] ?? null, $session['title'] ?? 'Tryout Pretes']);
    $rows = $c['rows'] ?? [['status'=>'terdaftar','year'=>'2026/2027','wave'=>'gel1','created'=>'2026-11-04']];
    $stmt = $pdo->prepare('INSERT INTO tutorial_registrations (id,user_id,tutorial_class_id,status,tahun_ajaran,gelombang,tipe_nilai,created_at) VALUES (?,?,?,?,?,?,?,?)');
    foreach ($rows as $i=>$r) $stmt->execute([$i+1,$r['user'] ?? 7,$r['class'] ?? null,$r['status'] ?? 'terdaftar',$r['year'] ?? null,$r['wave'] ?? null,$r['type'] ?? '',$r['created'] ?? null]);
    return $pdo;
}
''', encoding='utf-8')
    (root / 'includes/header.php').write_text('<?php $currentUser=getCurrentUser(); include __DIR__ . "/sidebar.php"; ?><main>', encoding='utf-8')
    (root / 'includes/footer.php').write_text('</main>', encoding='utf-8')
    (root / 'sidebar.php').write_text('<?php require "includes/auth.php"; $currentUser=getCurrentUser(); include "includes/sidebar.php";', encoding='utf-8')
    (root / 'rule.php').write_text('<?php require "includes/auth.php"; require "includes/tutorial-membership.php"; echo "RULE=".(int)studentHasActiveTutorialRegistration(getDBConnection(),getCurrentUser());', encoding='utf-8')

    current = {'status': 'terdaftar', 'year': '2026/2027', 'wave': 'gel1', 'created': '2026-11-04'}
    cases = {
        'waiting': ({}, True),
        'assigned': ({'rows': [{**current, 'class': 1, 'status': 'aktif'}]}, True),
        'legacy_class_year': ({'rows': [{**current, 'class': 1, 'year': None}]}, True),
        'legacy_unplotted': ({'rows': [{**current, 'year': None}]}, True),
        'legacy_old': ({'rows': [{**current, 'year': None, 'created': '2025-11-01'}]}, False),
        'unknown_period': ({'rows': [{**current, 'year': None, 'created': None}]}, False),
        'old_year': ({'rows': [{**current, 'year': '2025/2026'}]}, False),
        'wrong_wave': ({'rows': [{**current, 'wave': 'gel2'}]}, False),
        'wrong_semester': ({'rows': [{**current, 'class': 2}]}, False),
        'inactive_period': ({'inactive': True}, False),
        'completed': ({'rows': [{**current, 'status': 'lulus'}]}, False),
        'failed': ({'rows': [{**current, 'status': 'tidak_lulus'}]}, False),
        'withdrawn': ({'rows': [{**current, 'status': 'mengundurkan_diri'}]}, False),
        'withdrawn_latest': ({'rows': [current, {**current, 'status': 'mengundurkan_diri'}]}, False),
        'old_latest': ({'rows': [current, {**current, 'year': '2025/2026'}]}, True),
        'other_student': ({'rows': [{**current, 'user': 8}]}, False),
        'no_registration': ({'rows': []}, False),
        'multiple_active': ({'multi': True}, True),
        'admin': ({'role': 'admin'}, False),
        'dosen': ({'role': 'dosen'}, False),
    }

    def render(script, config):
        run = subprocess.run(['php', '-d', 'display_errors=1', script], cwd=root, env={**os.environ, 'CASE_JSON': json.dumps(config)}, capture_output=True, text=True, encoding='utf-8')
        assert run.returncode == 0 and not any(e in run.stdout for e in ['Fatal error', 'Undefined', 'Deprecated:']), run.stderr + run.stdout
        return run.stdout

    for name, (config, hide) in cases.items():
        assert ('RULE=1' in render('rule.php', config)) == hide, name
        html = render('sidebar.php', config)
        if name in ['admin', 'dosen']:
            assert ('/admin/pretes-peserta.php' if name == 'admin' else '/dosen/pretes.php') in html
        else:
            for route in ['pretes-daftar.php', 'pretes-peserta.php', 'pretes-hasil.php']:
                assert (f'https://example.test/{route}' in html) != hide, (name, route)
            if hide:
                assert '/absensi-pretes.php' not in html
                assert '/tutorial-info.php' in html and '/absensi-tutorial.php' in html
        print('PASS:', name)
    for name in ['waiting', 'assigned', 'old_year', 'completed', 'withdrawn']:
        config, hide = cases[name]
        html = render('pages/dashboard.php', config)
        for route in ['pretes-peserta.php', 'pretes-hasil.php']:
            assert (f'href="https://example.test/{route}' in html) != hide, (name, route)
        if hide: assert 'href="https://example.test/pretes-ujian.php' not in html
        assert ('Pretes Terdaftar</p>' in html) != hide
        assert 'Informasi umum' in html and 'Kelas Tutorial Saya' in html
        print('PASS dashboard:', name)
    for name, config, hide in [
        ('pretes_active', {'rows': []}, True),
        ('pretes_inactive', {'rows': [], 'pretes_inactive': True}, True),
        ('pretes_old_year', {'rows': [], 'pretes_old': True}, True),
        ('pretes_result_only', {'rows': [], 'result_only': True}, True),
        ('tutorial_only', {'no_pretes': True}, False),
        ('unregistered', {'no_pretes': True, 'rows': []}, False),
    ]:
        html = render('pages/dashboard.php', config)
        assert ('<h3 style="margin:0;font-size:18px;color:#065f46;">Absensi Al Khidmah</h3>' in html) != hide, name
        if hide:
            for marker in ['id="pretes-absensi-status-box"', 'id="pretes-scanner-wrapper"',
                           'id="btn-toggle-pretes-scanner"', 'onclick="togglePretesScanner()"',
                           'function togglePretesScanner()', 'id="badge-pretes-status-hadir"']:
                assert marker not in html, (name, marker)
        assert 'welcome-card' in html and 'Informasi umum' in html
        if name == 'pretes_active':
            assert 'Jadwal &amp; Panduan Pretes' in html
        if name == 'pretes_inactive':
            assert 'Lihat Nilai & Hasil Pretes' in html
        print('PASS attendance dashboard:', name)
    open_session = {'id': 11}
    future_session = {'id': 12, 'start_time': '2099-01-01 00:00:00'}
    ended_session = {'id': 13, 'end_time': '2000-01-01 00:00:00'}
    for name, config, expected in [
        ('one_open', {'rows': [], 'tryout_sessions': [open_session]}, '/tryout.php?sesi=11'),
        ('many_open', {'rows': [], 'tryout_sessions': [open_session, {'id': 14}]}, '/tryout.php'),
        ('only_open_link', {'rows': [], 'tryout_sessions': [open_session, future_session, ended_session]}, '/tryout.php?sesi=11'),
        ('not_started', {'rows': [], 'tryout_sessions': [future_session]}, None),
        ('ended', {'rows': [], 'tryout_sessions': [ended_session]}, None),
        ('inactive_session', {'rows': [], 'tryout_sessions': [{'id': 11, 'is_active': 0}]}, None),
        ('official_session', {'rows': [], 'tryout_sessions': [{'id': 11, 'mode': 'resmi'}]}, None),
        ('no_sessions', {'rows': []}, None),
        ('inactive_participant', {'rows': [], 'pretes_inactive': True, 'tryout_sessions': [open_session]}, None),
        ('unregistered_participant', {'rows': [], 'no_pretes': True, 'tryout_sessions': [open_session]}, None),
        ('tutorial_participant', {'tryout_sessions': [open_session]}, None),
        ('already_passed', {'rows': [], 'pretes_passed': True, 'tryout_sessions': [open_session]}, None),
    ]:
        html = render('pages/dashboard.php', config)
        assert ('id="dashboard-required-tryout"' in html) == (expected is not None), name
        if expected:
            assert f'href="https://example.test{expected}"' in html, name
            assert 'Wajib Mengikuti Tryout Pretes' in html and 'Ikuti Tryout Sekarang' in html
            assert 'Tryout adalah latihan, bukan ujian pretes resmi.' in html
            assert 'id="pretes-absensi-status-box"' not in html
        print('PASS required tryout dashboard:', name)
print('PASS: actual sidebar/dashboard hide pretes only for ongoing membership in an active tutorial period.')
