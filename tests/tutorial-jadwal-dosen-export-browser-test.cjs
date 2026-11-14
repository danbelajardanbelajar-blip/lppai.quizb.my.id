const assert = require('node:assert/strict');
const path = require('node:path');
const fs = require('node:fs');
const { chromium } = require('../scratch/cbt-preview/node_modules/playwright-core');

(async () => {
    const browser = await chromium.launch({ executablePath: 'C:/Program Files/Google/Chrome/Application/chrome.exe', headless: true });
    try {
        const context = await browser.newContext({ extraHTTPHeaders: { 'X-Test-Admin': '1' }, viewport: { width:1366, height:900 } });
        await context.route('https://cdn.jsdelivr.net/**', route => route.fulfill({ contentType:'application/javascript', body:'window.Chart=function(){};' }));
        const page = await context.newPage();
        if (process.argv[3] === 'multi') {
            await page.goto(process.argv[2] + '/admin/tutorial-jadwal-dosen-export.php?gelombang_id=1');
            assert.equal(await page.locator('tbody tr').count(), 63);
            assert.match(await page.locator('tbody').textContent(), /Kelas Tambahan 59/);
            const pdf = await page.pdf({ preferCSSPageSize:true, printBackground:true });
            const count = (pdf.toString('latin1').match(/\/Type\s*\/Page\b/g) || []).length;
            assert.ok(count >= 3 && count <= 6, 'Long schedules must paginate normally');
            assert.equal(await page.locator('thead').evaluate(element => getComputedStyle(element).display), 'table-header-group');
            console.log(`PASS: 63 classes retained, long names wrap, repeated table header and ${count}-page landscape PDF`);
            return;
        }
        await page.goto(process.argv[2] + '/admin/tutorial-hasil-plotting.php?gelombang_id=2');
        const opened = page.waitForEvent('popup');
        await page.getByRole('link', { name:'Export Jadwal Dosen / PDF' }).click();
        const preview = await opened;
        const errors = [];
        preview.on('pageerror', error => errors.push(error.message));
        await preview.waitForLoadState('load');
        assert.equal(new URL(preview.url()).searchParams.get('gelombang_id'), '1');
        assert.equal(await preview.locator('tbody tr').count(), 3);
        assert.equal(await preview.locator('.kop img').evaluate(img => img.complete && img.naturalWidth > 0), true);
        const output = path.resolve(__dirname, '../scratch/tutorial-jadwal-dosen-preview');
        fs.mkdirSync(output, { recursive:true });
        await preview.screenshot({ path:path.join(output,'schedule.png'), fullPage:true });
        const pdf = await preview.pdf({ preferCSSPageSize:true, printBackground:true, path:path.join(output,'schedule.pdf') });
        const content = pdf.toString('latin1');
        assert.equal((content.match(/\/Type\s*\/Page\b/g) || []).length, 1);
        const media = content.match(/\/MediaBox\s*\[0 0 ([\d.]+) ([\d.]+)\]/);
        assert.ok(media && Number(media[1]) > Number(media[2]), 'PDF must be landscape');
        await preview.evaluate(() => { window.print = () => { window.printRequested = true; }; });
        await preview.getByRole('button', { name:'Cetak / Simpan PDF' }).click();
        assert.equal(await preview.evaluate(() => window.printRequested), true);
        await preview.selectOption('#scheduleLecturer','Dosen Satu');
        await Promise.all([preview.waitForNavigation(), preview.getByRole('button', { name:'Tampilkan' }).click()]);
        assert.equal(await preview.locator('tbody tr').count(), 2);
        assert.equal(await preview.locator('tbody').textContent().then(text => text.includes('Dosen Legacy')), false);
        await preview.setViewportSize({ width:390, height:844 });
        assert.equal(await preview.getByRole('button', { name:'Cetak / Simpan PDF' }).isVisible(), true);
        assert.deepEqual(errors, []);
        console.log('PASS: card export opens correct period in new tab, logo, landscape A4 PDF, print button, lecturer filter, mobile toolbar and no JS errors');
    } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
