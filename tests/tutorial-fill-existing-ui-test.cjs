const fs = require('fs');
const path = require('path');
const {chromium} = require('../scratch/cbt-preview/node_modules/playwright-core');
(async () => {
    const source = fs.readFileSync(path.join(__dirname, '../admin/tutorial-pengaturan-generate.php'), 'utf8');
    const start = source.indexOf('<div class="card" style="border: 2px dashed');
    const end = source.indexOf('</div> <!-- End of tab-pengaturan -->', start);
    const html = source.slice(start, end).replace(/<\?= csrfToken\(\) \?>/g, 'fixture-token');
    const browser = await chromium.launch({headless:true,executablePath:'C:/Program Files/Google/Chrome/Application/chrome.exe'});
    try {
        const page = await browser.newPage();
        const errors=[];page.on('pageerror', e=>errors.push(e.message));
        await page.setContent('<meta name="viewport" content="width=device-width,initial-scale=1">'+html);
        const reuse=page.locator('#fillExistingClasses');
        const quota=page.locator('#existingTutorQuota');
        const overwrite=page.locator('[name="overwrite_plotting"]');
        if(await reuse.count()!==1 || await reuse.isChecked() || !await quota.isDisabled())throw Error('invalid defaults');
        await overwrite.check();await reuse.check();
        if(!await quota.isEnabled() || !await overwrite.isDisabled() || await overwrite.isChecked())throw Error('reuse/overwrite conflict not prevented');
        await quota.fill('25');
        await page.locator('#modeDistribute').check();
        if(!await reuse.isChecked())throw Error('reuse lost on mode switch');
        const data=await reuse.evaluate(el=>Object.fromEntries(new FormData(el.form)));
        if(data.fill_existing_classes!=='1' || data.existing_tutor_quota!=='25' || data.generate_mode!=='distribute_evenly' || data.overwrite_plotting)throw Error('incorrect form payload');
        await reuse.uncheck();
        if(!await quota.isDisabled() || !await overwrite.isEnabled())throw Error('normal generation not restored');
        await page.setViewportSize({width:390,height:844});
        if(await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth))throw Error('mobile overflow');
        if(errors.length)throw Error(errors.join(';'));
        console.log('PASS: single checkbox applies to both modes, editable quota, mutually exclusive overwrite, correct POST payload and mobile layout.');
    } finally {await browser.close();}
})().catch(error=>{console.error(error);process.exit(1)});
