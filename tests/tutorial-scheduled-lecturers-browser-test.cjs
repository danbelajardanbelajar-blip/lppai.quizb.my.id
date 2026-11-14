const assert = require('node:assert/strict');
const path = require('node:path');
const fs = require('node:fs');
const { chromium } = require('../scratch/cbt-preview/node_modules/playwright-core');

(async () => {
    const browser = await chromium.launch({ executablePath: 'C:/Program Files/Google/Chrome/Application/chrome.exe', headless: true });
    try {
        const context = await browser.newContext({ extraHTTPHeaders: { 'X-Test-Admin': '1' }, viewport: { width: 1366, height: 900 } });
        // The existing chart is outside this test; keep the UI fixture independent of a CDN.
        await context.route('https://cdn.jsdelivr.net/**', route => route.fulfill({ contentType: 'application/javascript', body: 'window.Chart = function() {};' }));
        const page = await context.newPage();
        const errors = [];
        page.on('pageerror', error => errors.push(error.message));
        await page.goto(process.argv[2] + '/admin/tutorial-hasil-plotting.php?gelombang_id=2');
        const card = page.locator('#scheduledTutorialLecturers');
        const row = card.locator('tr[data-schedule-id="11"]');
        assert.equal(await card.locator('tbody tr').count(), 3);
        await row.getByRole('button', { name: 'Edit', exact: true }).click();
        const dialog = page.getByRole('dialog', { name: 'Edit Jadwal Tutorial' });
        assert.equal(await dialog.isVisible(), true);
        assert.equal(await dialog.getByLabel('Nama Kelas').inputValue(), 'Kelas A');
        assert.equal(await dialog.getByLabel('Hari Tutorial').inputValue(), 'Senin');
        assert.equal(await dialog.getByLabel('Ruangan', { exact: true }).inputValue(), 'R1');
        assert.equal(await dialog.getByLabel('Ruangan', { exact: true }).locator('option[value="R Legacy"]').count(), 1);
        assert.equal(await dialog.locator('#tutorialScheduleLecturer').textContent(), 'Dosen Satu');
        await dialog.getByLabel('Nama Kelas').fill('Kelas Kamis A');
        await dialog.getByLabel('Hari Tutorial').selectOption('Kamis');
        await dialog.getByLabel('Ruangan', { exact: true }).selectOption('R2');
        await Promise.all([
            page.waitForNavigation(),
            dialog.getByRole('button', { name: 'Simpan Jadwal' }).click()
        ]);
        assert.equal(new URL(page.url()).searchParams.get('gelombang_id'), '2');
        assert.equal(await page.locator('.alert-success').count(), 1);
        assert.match(await row.textContent(), /Kelas Kamis A/);
        assert.match(await row.textContent(), /Kamis/);
        assert.match(await row.textContent(), /R2/);
        await page.goto(process.argv[2] + '/admin/tutorial-hasil-plotting.php');
        assert.match(await row.textContent(), /Kelas Kamis A/);
        assert.equal(await page.locator('#participantTable tbody tr').count(), 31);
        await row.getByRole('button', { name: 'Edit', exact: true }).click();
        await dialog.getByLabel('Nama Kelas').fill('Unsaved value');
        await dialog.getByLabel('Ruangan', { exact: true }).selectOption('R1');
        await dialog.getByRole('button', { name: 'Batal' }).click();
        assert.equal(await dialog.isVisible(), false);
        await page.setViewportSize({ width: 390, height: 844 });
        await row.getByRole('button', { name: 'Edit', exact: true }).click();
        assert.equal(await dialog.getByLabel('Nama Kelas').inputValue(), 'Kelas Kamis A');
        assert.equal(await dialog.getByLabel('Ruangan', { exact: true }).inputValue(), 'R2');
        const bounds = await dialog.boundingBox();
        assert.ok(bounds.x >= 0 && bounds.x + bounds.width <= 391, 'Dialog must fit mobile viewport');
        const output = path.resolve(__dirname, '../scratch/tutorial-scheduled-lecturers-preview');
        fs.mkdirSync(output, { recursive: true });
        await page.screenshot({ path: path.join(output, 'mobile-edit.png') });
        await page.keyboard.press('Escape');
        assert.equal(await dialog.isVisible(), false);
        await page.setViewportSize({ width: 1366, height: 900 });
        await card.screenshot({ path: path.join(output, 'lecturers-card.png') });
        assert.deepEqual(errors, []);
        console.log('PASS: pre-filled room and legacy choices; change room via actual POST; room persists in row and reopened dialog; roster retained; cancel/Escape; mobile fit; no JavaScript errors');
    } finally {
        await browser.close();
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
