"""Render actual sidebar/dashboard against isolated tutorial membership fixtures."""
import json
import os
import shutil
import subprocess
import tempfile
from pathlib import Path

repo = Path(__file__).resolve().parents[1]
with tempfile.TemporaryDirectory(prefix='lppai-tutorial-menu-') as temp:
    root = Path(temp)
    for name in ['includes/tutorial-membership.php', 'includes/sidebar.php', 'includes/pretes-helper.php',
                 'includes/pretes-face-engine.php', 'includes/user-helper.php', 'includes/announcement-helper.php', 'pages/dashboard.php']:
        target = root / name
        target.parent.mkdir(parents=True, exist_ok=True)
        shutil.copy(repo / name, target)
    (root / 'includes/auth.php').write_text('''<?php
define('BASE_URL', 'https://example.test'); define('APP_NAME', 'Test LPPAI');
$_SERVER['PHP_SELF'] = '/dashboard.php';
require_once __DIR__ . '/user-helper.php';
function requireLogin() {}
function csrfToken() { return 'fixture-token'; }
function getCurrentUser() { $c = json_decode(getenv('CASE_JSON'), true); return ['id'=>7,'role'=>$c['role'] ?? 'mahasiswa','nama_lengkap'=>'Peserta','nim'=>'2300007','jenis_program'=>'Reguler','program_studi'=>'PAI','fakultas'=>'Agama Islam']; }
function isAdmin() { return getCurrentUser()['role'] === 'admin'; }
function sanitize($text) { return htmlspecialchars((string)$text, ENT_QUOTES, 'UTF-8'); }
function getDBConnection() {
    static $pdo = null; if ($pdo) return $pdo;
    $c = json_decode(getenv('CASE_JSON'), true);
    $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
    $pdo->exec("CREATE TABLE master_gelombang (id INTEGER, status TEXT, tahun_ajaran TEXT, semester TEXT, gelombang TEXT, created_at TEXT);
        INSERT INTO master_gelombang VALUES (1,'aktif','2026-2027','Ganjil','gel1','2026-11-01');
        CREATE TABLE tutorial_classes (id INTEGER, gelombang TEXT, semester TEXT, nama_kelas TEXT, hari TEXT DEFAULT '', jam TEXT DEFAULT '', ruangan TEXT DEFAULT '');
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
print('PASS: actual sidebar/dashboard hide pretes only for ongoing membership in an active tutorial period.')
