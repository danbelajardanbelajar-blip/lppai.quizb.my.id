const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { chromium } = require('../scratch/cbt-preview/node_modules/playwright-core');

(async () => {
    const browser = await chromium.launch({ executablePath: 'C:/Program Files/Google/Chrome/Application/chrome.exe', headless: true });
    try {
        const context = await browser.newContext({ extraHTTPHeaders: { 'X-Test-Admin': '1' }, viewport: { width: 1366, height: 900 } });
        const page = await context.newPage();
        const errors = [];
        context.on('page', opened => opened.on('pageerror', error => errors.push(error.message)));
        await page.goto(process.argv[2] + '/admin/form-preview.php');
        await page.selectOption('#absensi-kelas', '11');
        await page.selectOption('#absensi-hari', 'Senin');
        const popupPromise = page.waitForEvent('popup');
        await page.getByRole('button', { name: 'Cetak Absensi / PDF' }).click();
        const popup = await popupPromise;
        await popup.waitForLoadState('load');
        assert.equal(new URL(popup.url()).searchParams.get('gelombang_id'), '1');
        assert.equal(new URL(popup.url()).searchParams.get('kelas_id'), '11');
        assert.equal(await popup.locator('section.sheet').count(), 2);
        assert.equal(await popup.locator('tbody tr').count(), 30);
        assert.equal(await popup.locator('.kop img').first().evaluate(img => img.complete && img.naturalWidth > 0), true);
        const output = path.resolve(__dirname, '../scratch/tutorial-absensi-preview');
        fs.mkdirSync(output, { recursive: true });
        await popup.screenshot({ path: path.join(output, 'attendance.png'), fullPage: true });
        await popup.emulateMedia({ media: 'print' });
        await popup.evaluate(() => fitAttendanceSheets());
        const fits = await popup.locator('section.sheet').evaluateAll(sheets => sheets.every(sheet =>
            sheet.querySelector('.sheet-fit').getBoundingClientRect().bottom <= sheet.getBoundingClientRect().bottom + 1));
        assert.equal(fits, true, 'Print content must fit each A4 sheet');
        const pdf = await popup.pdf({ preferCSSPageSize: true, printBackground: true, path: path.join(output, 'attendance.pdf') });
        assert.equal((pdf.toString('latin1').match(/\/Type\s*\/Page\b/g) || []).length, 2, '30 participants must produce exactly 2 PDF pages');
        await popup.goto(process.argv[2] + '/admin/tutorial-hasil-plotting.php?cetak_absensi=1');
        await popup.waitForLoadState('load');
        await popup.emulateMedia({ media: 'print' });
        const allPdf = await popup.pdf({ preferCSSPageSize: true, printBackground: true });
        assert.equal((allPdf.toString('latin1').match(/\/Type\s*\/Page\b/g) || []).length, 3, 'Separate classes must produce separate pages');
        await page.setViewportSize({ width: 390, height: 844 });
        assert.equal(await page.getByRole('button', { name: 'Cetak Absensi / PDF' }).isVisible(), true);
        assert.deepEqual(errors, []);
        console.log('PASS: actual form submits to a new tab with period/class filters; logo, 30 rows, A4 fit, 2-page/class and 3-page/all PDF, mobile button, no JS errors');
    } finally {
        await browser.close();
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
