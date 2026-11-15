"""Test suite for Admin User Profile Photo Click-to-Zoom and Anti-Right-Click Save protection."""
import contextlib
import json
import shutil
import socket
import sqlite3
import subprocess
import tempfile
import time
import urllib.request
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]

with tempfile.TemporaryDirectory(prefix='profile-zoom-test-') as directory:
    root = Path(directory)
    for file in [
        'admin/profil-pengguna.php', 'admin/ajax-user-profile-links.php',
        'includes/admin-user-profile.php', 'includes/user-table-avatar.php',
        'includes/admin-profile-academic-view.php', 'includes/pretes-helper.php',
        'includes/pretes-face-engine.php'
    ]:
        target = root / file
        target.parent.mkdir(parents=True, exist_ok=True)
        shutil.copy2(ROOT / file, target)

    with contextlib.closing(sqlite3.connect(root / 'test.sqlite')) as db:
        db.executescript('''
        CREATE TABLE users (
            id INTEGER PRIMARY KEY,
            nama_lengkap TEXT,
            username TEXT,
            nim TEXT,
            role TEXT,
            program_studi TEXT,
            jenis_program TEXT,
            fakultas TEXT,
            angkatan TEXT,
            email TEXT,
            no_hp TEXT,
            tempat_lahir TEXT,
            tanggal_lahir TEXT,
            created_at TEXT,
            updated_at TEXT,
            is_active INTEGER,
            foto TEXT,
            password TEXT
        );
        INSERT INTO users (id, nama_lengkap, username, nim, role, program_studi, is_active, foto, password)
        VALUES (18310, 'Ahmad Fulan', 'ahmad18310', '24018310', 'mahasiswa', 'Pendidikan Agama Islam', 1, 'https://example.com/fulan.jpg', 'SECRET');

        CREATE TABLE pretes_registrations (
            id INTEGER PRIMARY KEY,
            user_id INTEGER,
            periode TEXT,
            hari_pilihan TEXT,
            jam TEXT,
            ruangan TEXT,
            dosen_penguji TEXT,
            status TEXT,
            foto TEXT,
            tanggal_daftar TEXT
        );
        INSERT INTO pretes_registrations (id, user_id, periode, hari_pilihan, jam, ruangan, dosen_penguji, status, foto, tanggal_daftar)
        VALUES (1, 18310, 'Periode 2026/2027 Ganjil', 'Senin', '08.00 WIB', 'Lab CBT 1', 'Dosen Penguji PAI', 'hadir', 'https://example.com/pasfoto-pretes.jpg', '2026-11-10');

        CREATE TABLE master_pretes (id INTEGER PRIMARY KEY, periode TEXT, status TEXT);
        INSERT INTO master_pretes VALUES (1, 'Periode 2026/2027 Ganjil', 'aktif');

        CREATE TABLE tutorial_classes (
            id INTEGER PRIMARY KEY,
            nama_kelas TEXT,
            hari TEXT,
            jam TEXT,
            ruangan TEXT,
            dosen_pengampu TEXT,
            semester TEXT,
            gelombang TEXT
        );

        CREATE TABLE tutorial_registrations (
            id INTEGER PRIMARY KEY,
            user_id INTEGER,
            tutorial_class_id INTEGER,
            status TEXT,
            tahun_ajaran TEXT,
            gelombang TEXT,
            hari_pilihan TEXT,
            nilai_akhir REAL,
            nomor_sertifikat TEXT
        );

        CREATE TABLE pretes_assessments (registration_id INTEGER PRIMARY KEY, scores_json TEXT);
        CREATE TABLE pretes_results (id INTEGER PRIMARY KEY, user_id INTEGER, status_lulus TEXT, nilai REAL, keterangan TEXT);
        ''')
        db.commit()

    (root / 'includes/auth.php').write_text('''<?php
require_once __DIR__ . '/admin-user-profile.php';
define('BASE_URL', '');
$_SESSION = ['user_id' => 99, 'role' => 'admin'];
function isLoggedIn() { return true; }
function isAdmin() { return true; }
function requireAdmin() {}
function sanitize($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function getDBConnection() {
    return new PDO('sqlite:' . __DIR__ . '/../test.sqlite', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
}
''', encoding='utf-8')

    (root / 'includes/header.php').write_text('<!doctype html><html><head><meta name="viewport" content="width=device-width,initial-scale=1"></head><body><main class="content-area">', encoding='utf-8')
    (root / 'includes/footer.php').write_text('</main></body></html>', encoding='utf-8')

    with socket.socket() as s:
        s.bind(('127.0.0.1', 0))
        port = s.getsockname()[1]

    server = subprocess.Popen(
        ['php', '-d', 'display_errors=stderr', '-S', f'127.0.0.1:{port}', '-t', str(root)],
        stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL
    )
    base_url = f'http://127.0.0.1:{port}'

    try:
        # Wait for server
        for _ in range(50):
            try:
                urllib.request.urlopen(f'{base_url}/admin/profil-pengguna.php?id=18310')
                break
            except Exception:
                time.sleep(0.1)

        # 1. HTTP verification: HTML markup integrity
        req = urllib.request.urlopen(f'{base_url}/admin/profil-pengguna.php?id=18310')
        html = req.read().decode('utf-8')
        assert req.status == 200, "HTTP Status must be 200"
        assert 'id="admin-photo-zoom-modal"' in html, "Photo zoom modal markup must exist"
        assert 'id="photo-zoom-img"' in html, "Photo zoom img element must exist"
        assert 'id="photo-zoom-shield"' in html, "Protective shield overlay must exist"
        assert 'id="photo-zoom-in"' in html, "Zoom in button must exist"
        assert 'id="photo-zoom-out"' in html, "Zoom out button must exist"
        assert 'id="photo-zoom-reset"' in html, "Zoom reset button must exist"
        assert 'closePhotoZoom()' in html, "Close handler must exist"
        assert 'openPhotoZoom' in html, "Open zoom handler must exist"
        assert 'contextmenu' in html, "Right-click contextmenu prevention must exist"
        assert 'draggable="false"' in html, "Draggable false must be present"
        assert 'pointer-events:none' in html or 'pointer-events: none' in html, "Pointer events protection must be present"
        assert 'https://example.com/fulan.jpg' in html, "Profile photo must be loaded"
        assert 'https://example.com/pasfoto-pretes.jpg' in html, "Pretes photo must be loaded"

        print("PASS HTTP: HTML markup, zoom modal structure, and photo URLs verified successfully.")

        # 2. Browser Verification via Playwright (Testing click, zoom in, zoom out, reset, right-click blocking, close)
        browser_script = root / 'run_zoom_browser_test.cjs'
        browser_script.write_text('''
const { chromium } = require(process.argv[2]);
(async () => {
    const browser = await chromium.launch({
        executablePath: 'C:/Program Files/Google/Chrome/Application/chrome.exe',
        headless: true
    });
    try {
        const page = await browser.newPage();
        const errors = [];
        page.on('pageerror', e => errors.push(e.message));

        await page.goto(process.argv[3] + '/admin/profil-pengguna.php?id=18310');

        // Check avatar has .has-photo class
        const icon = page.locator('.admin-profile-identity .users-table-user-icon');
        await icon.waitFor({ state: 'visible' });
        const hasPhotoClass = await icon.evaluate(el => el.classList.contains('has-photo'));
        if (!hasPhotoClass) throw new Error('Avatar icon must have .has-photo class');

        // Test Right Click Prevention on Avatar Thumbnail
        const rightClickPreventedOnAvatar = await icon.evaluate(el => {
            const event = new MouseEvent('contextmenu', { bubbles: true, cancelable: true });
            const dispatched = el.dispatchEvent(event);
            return event.defaultPrevented;
        });
        if (!rightClickPreventedOnAvatar) throw new Error('Right-click must be prevented on avatar thumbnail');

        // Test Click to Open Zoom Modal
        await icon.click();
        const modal = page.locator('#admin-photo-zoom-modal');
        await modal.waitFor({ state: 'visible' });
        const isModalActive = await modal.evaluate(el => el.classList.contains('active'));
        if (!isModalActive) throw new Error('Zoom modal must have .active class when opened');

        // Check image src in zoom modal
        const zoomImg = page.locator('#photo-zoom-img');
        const zoomSrc = await zoomImg.getAttribute('src');
        if (!zoomSrc || !zoomSrc.includes('fulan.jpg')) throw new Error('Zoom image must contain profile photo src');

        // Test Right Click Prevention in Zoom Modal & Shield
        const shield = page.locator('#photo-zoom-shield');
        const rightClickPreventedOnShield = await shield.evaluate(el => {
            const event = new MouseEvent('contextmenu', { bubbles: true, cancelable: true });
            el.dispatchEvent(event);
            return event.defaultPrevented;
        });
        if (!rightClickPreventedOnShield) throw new Error('Right-click must be prevented on zoom shield');

        // Test Dragstart Prevention in Zoom Modal & Shield
        const dragPreventedOnShield = await shield.evaluate(el => {
            const event = new DragEvent('dragstart', { bubbles: true, cancelable: true });
            el.dispatchEvent(event);
            return event.defaultPrevented;
        });
        if (!dragPreventedOnShield) throw new Error('Dragstart must be prevented on zoom shield');

        // Initial zoom level must be 100%
        const levelBadge = page.locator('#photo-zoom-level');
        let levelText = await levelBadge.innerText();
        if (levelText.trim() !== '100%') throw new Error('Initial zoom level must be 100%, got ' + levelText);

        // Click Zoom In (+)
        await page.locator('#photo-zoom-in').click();
        levelText = await levelBadge.innerText();
        if (levelText.trim() !== '125%') throw new Error('Zoom level after zoom-in must be 125%, got ' + levelText);

        // Click Zoom In (+) again
        await page.locator('#photo-zoom-in').click();
        levelText = await levelBadge.innerText();
        if (levelText.trim() !== '150%') throw new Error('Zoom level after 2nd zoom-in must be 150%, got ' + levelText);

        // Click Zoom Out (-)
        await page.locator('#photo-zoom-out').click();
        levelText = await levelBadge.innerText();
        if (levelText.trim() !== '125%') throw new Error('Zoom level after zoom-out must be 125%, got ' + levelText);

        // Click Reset
        await page.locator('#photo-zoom-reset').click();
        levelText = await levelBadge.innerText();
        if (levelText.trim() !== '100%') throw new Error('Zoom level after reset must be 100%, got ' + levelText);

        // Test Keyboard Zoom Shortcuts (+, -, 0, Escape)
        await page.keyboard.press('+');
        levelText = await levelBadge.innerText();
        if (levelText.trim() !== '125%') throw new Error('Keyboard + must zoom in');

        await page.keyboard.press('-');
        levelText = await levelBadge.innerText();
        if (levelText.trim() !== '100%') throw new Error('Keyboard - must zoom out');

        // Test Escape to Close Modal
        await page.keyboard.press('Escape');
        await page.waitForTimeout(300);
        const isClosed = await modal.evaluate(el => !el.classList.contains('active') || el.style.display === 'none');
        if (!isClosed) throw new Error('Modal must be closed after pressing Escape');

        // Test Pretes Thumbnail Photo click to zoom
        const pretesThumb = page.locator('.admin-pretes-photo-thumb');
        if (await pretesThumb.count() > 0) {
            await pretesThumb.first().click();
            await modal.waitFor({ state: 'visible' });
            const pretesZoomSrc = await zoomImg.getAttribute('src');
            if (!pretesZoomSrc || !pretesZoomSrc.includes('pasfoto-pretes.jpg')) {
                throw new Error('Pretes photo thumb must open with pasfoto src');
            }
            await page.locator('.photo-zoom-close').click();
            await page.waitForTimeout(300);
        }

        // Test mobile layout responsiveness (width 390px)
        await page.setViewportSize({ width: 390, height: 844 });
        const hasMobileOverflow = await page.evaluate(() => document.documentElement.scrollWidth > window.innerWidth);
        if (hasMobileOverflow) throw new Error('Mobile viewport width overflow detected');

        if (errors.length) throw new Error('Page errors found: ' + errors.join('; '));
        console.log('PASS browser: Photo click, zoom in/out, reset, keyboard shortcuts, right-click protection, and mobile responsiveness verified 100%!');
    } finally {
        await browser.close();
    }
})().catch(err => {
    console.error(err);
    process.exit(1);
});
''', encoding='utf-8')

        result = subprocess.run(
            ['node', str(browser_script), str(ROOT / 'scratch/cbt-preview/node_modules/playwright-core'), base_url],
            capture_output=True, text=True
        )
        assert result.returncode == 0, f"Browser test failed:\n{result.stdout}\n{result.stderr}"
        print(result.stdout.strip())

    finally:
        server.terminate()
        server.wait(timeout=10)

print("\nALL PHOTO ZOOM AND ANTI-RIGHT-CLICK TESTS PASSED 100%!")
