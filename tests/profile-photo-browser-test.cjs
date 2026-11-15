const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { chromium } = require('../scratch/cbt-preview/node_modules/playwright-core');
(async () => {
    const browser = await chromium.launch({ executablePath:'C:/Program Files/Google/Chrome/Application/chrome.exe', headless:true,
        args:['--use-fake-device-for-media-stream','--use-fake-ui-for-media-stream'] });
    try {
        const context = await browser.newContext({ permissions:['camera'],viewport:{width:1366,height:1000} });
        // Isolate classifier results; the profile client, camera, PHP handler and HTTP engine bridge are real.
        await context.route('**/assets/js/pretes-face-verifier.js*', route => route.fulfill({contentType:'application/javascript',body:`
window.testFaceValid=false;
window.PretesFaceVerifier={stream:null,
 verifyFace:async function(canvas,mode){window.lastPhotoMode=mode;return {valid:window.testFaceValid,reason:'Wajah belum memenuhi syarat.'};},
 startCamera:function(video,ok,fail){navigator.mediaDevices.getUserMedia({video:true,audio:false}).then(async stream=>{this.stream=stream;video.srcObject=stream;await video.play();ok(stream);}).catch(fail);},
 stopCamera:function(){if(this.stream)this.stream.getTracks().forEach(track=>track.stop());this.stream=null;}
};` }));
        const page = await context.newPage();
        const errors=[]; page.on('pageerror', error=>errors.push(error.message));
        await page.goto(process.argv[2]+'/profil.php');
        assert.equal(await page.locator('#profilePhotoCapture').isVisible(),false);
        assert.equal(await page.locator('#profilePhotoStop').isVisible(),false);
        assert.equal(await page.locator('#profilePhotoSubmit').isDisabled(),true);
        await page.locator('#foto_profil').setInputFiles(path.join(process.argv[3],'new.jpg'));
        await page.waitForFunction(()=>document.getElementById('profilePhotoStatus').textContent.includes('Wajah belum memenuhi'));
        assert.equal(await page.locator('#profilePhotoData').inputValue(),'');
        assert.equal(await page.locator('#profilePhotoSubmit').isDisabled(),true);
        await page.evaluate(()=>{window.testFaceValid=true;});
        await page.locator('#foto_profil').setInputFiles([]);
        await page.locator('#foto_profil').setInputFiles(path.join(process.argv[3],'new.jpg'));
        await page.waitForFunction(()=>!document.getElementById('profilePhotoSubmit').disabled);
        assert.match(await page.locator('#profilePhotoData').inputValue(),/^data:image\/jpeg;base64,/);
        assert.equal(await page.evaluate(()=>window.lastPhotoMode),'upload');
        await Promise.all([page.waitForNavigation(),page.locator('#profilePhotoSubmit').click()]);
        assert.match(await page.locator('.alert-success').textContent(),/Foto profil lolos pemeriksaan/);
        let response=await context.request.get(process.argv[2]+'/reference.php');
        assert.match((await response.json()).photo,/^uploads\/profil\/avatar_1_/);
        await page.evaluate(()=>{window.testFaceValid=true;});
        await page.locator('#profilePhotoCamera').click();
        await page.waitForFunction(()=>{const v=document.getElementById('profilePhotoVideo');return !v.hidden&&v.videoWidth>0&&v.readyState>=2;});
        await page.locator('#profilePhotoCapture').click();
        await page.waitForFunction(()=>!document.getElementById('profilePhotoSubmit').disabled);
        assert.equal(await page.evaluate(()=>window.lastPhotoMode),'camera');
        assert.equal(await page.locator('#profilePhotoVideo').isVisible(),false);
        assert.equal(await page.locator('#profilePhotoCapture').isVisible(),false);
        await Promise.all([page.waitForNavigation(),page.locator('#profilePhotoSubmit').click()]);
        assert.match(await page.locator('.alert-success').textContent(),/Foto profil lolos pemeriksaan/);
        await page.setViewportSize({width:390,height:844});
        assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth<=window.innerWidth),true,'Profile must fit mobile width');
        const output=path.resolve(__dirname,'../scratch/profile-exam-photo-preview');fs.mkdirSync(output,{recursive:true});
        await page.screenshot({path:path.join(output,'profile-mobile.png'),fullPage:true});
        assert.deepEqual(errors,[]);
        console.log('PASS: real profile page/client rejects bad classifier result, enables verified file upload, saves through HTTP bridge, captures camera frame, updates canonical exam reference and fits mobile; classifier/engine mocked');
    } finally { await browser.close(); }
})().catch(error=>{console.error(error);process.exitCode=1;});
