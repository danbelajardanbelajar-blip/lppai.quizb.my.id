const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { chromium } = require('../scratch/cbt-preview/node_modules/playwright-core');
(async () => {
    const browser = await chromium.launch({ executablePath:'C:/Program Files/Google/Chrome/Application/chrome.exe', headless:true });
    try {
        const page = await browser.newPage();
        const errors = [];
        page.on('pageerror', error => errors.push(error.message));
        const response = await page.goto(process.argv[2]+'/tutorial-pendaftaran.php');
        assert.equal(response.status(),200);
        assert.equal(await page.getByRole('heading',{name:'Pendaftaran telah ditutup'}).count(),1);
        assert.equal(await page.locator('form').count(),0);
        assert.match(await page.locator('#tutorial-registration-closed').textContent(),/sudah diplot/);
        const output = path.resolve(__dirname,'../scratch/tutorial-registration-closed-preview');
        fs.mkdirSync(output,{recursive:true});
        for (const width of [1366,390]) {
            await page.setViewportSize({width,height:844});
            for (const name of ['Lihat Info Tutorial','Kembali ke Dashboard']) {
                const link=page.getByRole('link',{name});
                const box=await link.boundingBox();
                assert.ok(box && box.x>=0 && box.x+box.width<=width, 'Link must fit viewport');
            }
            await page.screenshot({path:path.join(output,`closed-${width}.png`)});
        }
        assert.deepEqual(errors,[]);
        console.log('PASS: closed page actual route, no form, clear explanation, responsive navigation links and no JS errors');
    } finally { await browser.close(); }
})().catch(error=>{console.error(error);process.exitCode=1;});
