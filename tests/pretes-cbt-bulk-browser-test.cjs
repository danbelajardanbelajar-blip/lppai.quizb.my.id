// Invoked by pretes-cbt-bulk-http-test.py --browser, against its temporary PHP server.
const assert = require('node:assert/strict');
const path = require('node:path');
const { chromium } = require(process.env.PLAYWRIGHT_MODULE || path.resolve(__dirname, '../scratch/cbt-preview/node_modules/playwright-core'));
const base = process.argv[2];
(async () => {
    const browser = await chromium.launch({ executablePath: process.env.CHROME_PATH || 'C:/Program Files/Google/Chrome/Application/chrome.exe', headless: true });
    try {
        const page = await browser.newPage({ extraHTTPHeaders: { 'X-Test-Login': '1', 'X-Test-Admin': '1' } });
        const errors = [], posts = [];
        page.on('pageerror', error => errors.push(error.message));
        page.on('request', request => {
            if (request.method() === 'POST') posts.push(new URLSearchParams(request.postData()));
        });
        for (const kind of ['packages', 'sessions']) {
            const field = kind === 'packages' ? 'package_ids[]' : 'session_ids[]';
            await page.goto(base + '/pretes-ujian.php?cbt_bank=1&tab=' + kind);
            // Also verify safety if SPA is enabled and the page script is evaluated again.
            await page.evaluate(() => { window.ENABLE_SPA = true; });
            await page.addScriptTag({ url: base + '/pretes-ujian.php?cbt_admin_asset=1' });
            const items = page.locator('[data-bulk-select-item="' + kind + '"]');
            const all = page.locator('[data-bulk-select-all="' + kind + '"]');
            const toolbar = page.locator('[data-bulk-toolbar="' + kind + '"]');
            const button = page.locator('[data-bulk-delete-button="' + kind + '"]');
            assert.equal(await items.count(), 4);
            assert.equal(await toolbar.isVisible(), false);
            await all.check();
            await page.locator('[data-bulk-select-item="' + kind + '"][value="4"]').uncheck();
            assert.equal(await all.evaluate(input => input.indeterminate), true);
            await page.locator('[data-bulk-select-item="' + kind + '"][value="1"]').uncheck();
            assert.match(await page.locator('[data-bulk-count="' + kind + '"]').textContent(), /^2 /);
            let confirmations = 0;
            const cancel = dialog => { confirmations++; dialog.dismiss(); };
            page.on('dialog', cancel);
            const countBefore = posts.length;
            await button.click();
            await page.waitForTimeout(100);
            page.off('dialog', cancel);
            assert.equal(confirmations, 1, 'Re-evaluated script must not duplicate confirmation');
            assert.equal(posts.length, countBefore, 'Cancel sends no mutation');
            assert.equal(await button.isEnabled(), true, 'Cancel does not lock delete button');
            const accept = dialog => dialog.accept();
            page.on('dialog', accept);
            await Promise.all([page.waitForNavigation(), button.click()]);
            assert.deepEqual(posts.at(-1).getAll(field).sort(), ['2', '3']);
            assert.equal(posts.length, countBefore + 1, 'One native POST per confirmed deletion');
            assert.deepEqual(await items.evaluateAll(inputs => inputs.map(i => i.value).sort()), ['1', '4']);
            assert.equal(await toolbar.isVisible(), false);
            await all.check();
            await Promise.all([page.waitForNavigation(), button.click()]);
            assert.equal(await items.count(), 0);
            await page.reload();
            assert.equal(await items.count(), 0, 'Deleted last item must not be reseeded');
            assert.equal(await all.isDisabled(), true);
            assert.equal(await toolbar.isVisible(), false);
            page.off('dialog', accept);
            console.log('PASS Chrome:', kind, 'select all/partial, cancel, exact POST, empty deletion, refresh, no duplicate listeners');
        }
        assert.deepEqual(errors, [], 'No browser runtime errors');
    } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
