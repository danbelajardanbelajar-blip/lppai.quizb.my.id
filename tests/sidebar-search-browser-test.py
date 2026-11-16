"""Exercise the real PHP sidebar and search script for all roles in Chrome."""
import shutil
import socket
import subprocess
import tempfile
import time
import urllib.request
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
with tempfile.TemporaryDirectory(prefix='sidebar-search-') as directory:
    root = Path(directory)
    for file in ['includes/sidebar.php', 'includes/pretes-helper.php', 'includes/pretes-face-engine.php',
                 'includes/tutorial-membership.php', 'assets/css/style.css', 'assets/js/sidebar-search.js']:
        target = root/file
        target.parent.mkdir(parents=True, exist_ok=True)
        shutil.copy2(ROOT/file, target)
    (root/'index.php').write_text('''<?php
    define('BASE_URL',''); define('APP_NAME','LPPAI Corner');
    $role = in_array($_GET['role'] ?? '', ['admin','dosen','mahasiswa'],true) ? $_GET['role'] : 'admin';
    $currentUser = ['id'=>1,'role'=>$role];
    ?><!doctype html><html><head><meta name="viewport" content="width=device-width,initial-scale=1"><link rel="stylesheet" href="/assets/css/style.css"></head><body>
    <?php include __DIR__.'/includes/sidebar.php'; ?></body></html>''', encoding='utf-8')
    with socket.socket() as sock:
        sock.bind(('127.0.0.1',0))
        port=sock.getsockname()[1]
    server=subprocess.Popen(['php','-d','display_errors=stderr','-S',f'127.0.0.1:{port}','-t',str(root)],stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
    base=f'http://127.0.0.1:{port}'
    try:
        for _ in range(50):
            try:
                urllib.request.urlopen(base); break
            except OSError:
                time.sleep(.1)
        script=root/'browser.cjs'
        script.write_text(r'''const {chromium}=require(process.argv[2]);
(async()=>{const browser=await chromium.launch({executablePath:'C:/Program Files/Google/Chrome/Application/chrome.exe',headless:true});
try{
 const page=await browser.newPage(); const errors=[]; page.on('pageerror',e=>errors.push(e.message));
 await page.goto(process.argv[3]+'/?role=admin');
 const search=page.locator('#sidebar-menu-search');
 const snapshot=()=>page.evaluate(()=>[...document.querySelectorAll('.nav-group,.submenu,.nav-group-toggle,.submenu-toggle')].map(el=>[el.className.trim().replace(/\s+/g,' '),el.style.display,el.getAttribute('aria-expanded')]));
 const before=JSON.stringify(await snapshot());
 await search.fill('cBt');
 const cbt=page.locator('.sidebar-menu a[href*="cbt_bank"]');
 await cbt.waitFor({state:'visible'});
 if(await page.locator('.sidebar-menu a[href*="admin/users.php"]').isVisible())throw Error('unrelated users menu visible');
 await search.press('Enter');
 if(!await cbt.evaluate(el=>el===document.activeElement))throw Error('Enter did not focus first result');
 await search.fill('pendaftaran kolektif');
 const nested=page.locator('a[href*="tutorial-pendaftaran-kolektif.php"]');
 await nested.waitFor({state:'visible'});
 if(!await nested.evaluate(el=>el.closest('.submenu') && getComputedStyle(el.closest('.submenu')).display==='block'))throw Error('nested result not expanded');
 await search.fill('MANAJEMEN TUTORIAL');
 if(!await page.locator('a[href*="tutorial-kelas.php"]').isVisible() || !await nested.isVisible())throw Error('category search incomplete');
 await search.fill('<script>tidak-ada</script>');
 if(await page.locator('.sidebar-menu a:visible').count())throw Error('unmatched menus still shown');
 if(!(await page.locator('#sidebar-search-status').innerText()).includes('Menu tidak ditemukan'))throw Error('empty state missing');
 await search.press('Escape');
 if(JSON.stringify(await snapshot())!==before)throw Error('clearing changed accordion/submenu state');
 await search.fill('kolektif');
 const href=await nested.getAttribute('href');
 await nested.evaluate(el=>el.addEventListener('click',e=>e.preventDefault()));
 await nested.click();
 if(await search.inputValue()!=='')throw Error('navigation did not reset search');
 if(await nested.getAttribute('href')!==href)throw Error('destination changed');
 await search.fill('pretes');
 await page.locator('#sidebar-search-clear').click();
 if(await search.inputValue()!=='' || !await search.evaluate(el=>el===document.activeElement))throw Error('clear button failed');
 await page.setViewportSize({width:390,height:844});
 await page.locator('.sidebar').evaluate(el=>el.classList.add('open'));
 await search.fill('cbt'); await cbt.waitFor({state:'visible'});
 if(await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth))throw Error('mobile overflow');
 for(const role of ['dosen','mahasiswa']){
  await page.goto(process.argv[3]+'/?role='+role);
  await page.locator('.sidebar').evaluate(el=>el.classList.add('open'));
  await search.fill('profil saya');
  await page.locator('a[href="/profil.php"]').waitFor({state:'visible'});
  if(await page.locator('.sidebar-menu a[href*="/admin/"]').count())throw Error('admin menus leaked to '+role);
 }
 if(errors.length)throw Error(errors.join(';'));
 console.log('PASS: real sidebar search for admin/dosen/mahasiswa, nested/category/case-insensitive matches, empty results, keyboard, reset/accordion state, link preservation and mobile.');
}finally{await browser.close()}})().catch(e=>{console.error(e);process.exit(1)});''',encoding='utf-8')
        result=subprocess.run(['node',str(script),str(ROOT/'scratch/cbt-preview/node_modules/playwright-core'),base],capture_output=True,text=True)
        assert result.returncode==0,result.stdout+result.stderr
        print(result.stdout.strip())
    finally:
        server.terminate()
        server.wait(timeout=10)
