// Regression: render PHP-generated JavaScript before exercising the camera controls.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const { spawnSync } = require('node:child_process');

const root = path.resolve(__dirname, '..');
const page = fs.readFileSync(path.join(root, 'pages/pretes-ujian.php'), 'utf8');
const auth = fs.readFileSync(path.join(root, 'includes/auth.php'), 'utf8');
const csrfFunction = auth.match(/function csrfToken\(\) \{[\s\S]*?\n\}/)[0];
const template = page.match(/<script>([\s\S]*?)<\/script>/)[1];

function renderScript(verified = false) {
    const result = spawnSync('php', ['-n'], {
        input: '<?php $_SESSION = []; $regId = 42; $isVerified = ' +
            (verified ? 'true' : 'false') + '; define("BASE_URL", "https://example.test"); ' +
            csrfFunction + ' ?>' + template,
        encoding: 'utf8'
    });
    assert.equal(result.status, 0, result.stdout + result.stderr);
    assert.doesNotMatch(result.stdout, /Fatal error|Warning:/);
    return result.stdout;
}

function environment(getUserMedia) {
    const elements = new Map();
    function element(id) {
        if (!elements.has(id)) elements.set(id, {
            style: {}, listeners: {}, textContent: '', innerHTML: '', videoWidth: 640, videoHeight: 480,
            addEventListener(name, callback) { this.listeners[name] = callback; },
            contains(target) { return target === this; },
            setAttribute() {}, play() { return Promise.resolve(); }
        });
        return elements.get(id);
    }
    const alerts = [];
    const requests = [];
    const timers = new Map();
    let timerId = 0;
    const sandbox = {
        document: {
            readyState: 'complete', getElementById: element,
            createElement() {
                return { getContext: () => ({ drawImage() {} }), toDataURL: () => 'data:image/jpeg;base64,test' };
            }
        },
        navigator: { mediaDevices: { getUserMedia } },
        console: { log() {}, warn() {}, error() {} },
        alert: message => alerts.push(message),
        AbortController,
        setInterval() { return 1; }, clearInterval() {},
        setTimeout(callback, delay) { timers.set(++timerId, { callback, delay }); return timerId; },
        clearTimeout(id) { timers.delete(id); },
        fetch(url, options) {
            const payload = JSON.parse(options.body);
            requests.push(payload);
            const data = payload.action === 'challenge'
                ? { success: true, challenge_id: 'nonce-from-server', direction: 'left' }
                : { verified: false, score: 0, message: 'Tidak cocok' };
            return Promise.resolve({ ok: true, status: 200, json: () => Promise.resolve(data) });
        }
    };
    sandbox.window = sandbox;
    vm.createContext(sandbox);
    return { sandbox, element, alerts, requests, timers };
}

const settle = () => new Promise(resolve => setImmediate(resolve));

(async () => {
    let calls = 0;
    const stream = { getTracks: () => [{ stop() {} }] };
    const env = environment(() => { calls++; return Promise.resolve(stream); });
    const script = renderScript();
    const visual = environment(() => Promise.resolve(stream));
    vm.runInContext(script, visual.sandbox);
    const waits = [];
    visual.sandbox.waitForFacePose = milliseconds => {
        assert.equal(milliseconds, 1000);
        return new Promise(resolve => waits.push(resolve));
    };
    const pose = visual.sandbox.guideFacePose('←', '2/3 · KIRI layar', 'Ikuti panah di kamera', 4);
    assert.equal(visual.element('facePoseGuide').style.display, 'grid');
    assert.equal(visual.element('facePoseSymbol').textContent, '←');
    assert.match(visual.element('facePoseTitle').textContent, /KIRI layar/);
    assert.equal(visual.element('facePoseCountdown').textContent, 'Tahan 4 detik');
    for (const remaining of [3, 2, 1]) {
        waits.shift()();
        await settle();
        assert.equal(visual.element('facePoseCountdown').textContent, 'Tahan ' + remaining + ' detik');
    }
    waits.shift()();
    await pose;
    assert.equal(visual.element('facePoseCountdown').textContent, 'Foto diambil');
    await visual.sandbox.guideFacePose('→', '2/3 · KANAN layar', 'Menoleh ke kanan layar', 0);
    assert.equal(visual.element('facePoseSymbol').textContent, '→');
    assert.match(visual.element('facePoseTitle').textContent, /KANAN layar/);
    vm.runInContext(script, env.sandbox);
    assert.equal(vm.runInContext('API_URL', env.sandbox), 'https://example.test/pretes-ujian.php?face_api=1');
    assert.equal(vm.runInContext('CBT_API_URL', env.sandbox), 'https://example.test/pretes-ujian.php?cbt_api=1');
    env.sandbox.waitForFacePose = () => Promise.resolve();
    for (const name of ['startCheckinCamera', 'toggleCameraFace', 'runInitialCheckin']) {
        assert.equal(typeof env.sandbox[name], 'function', name);
    }
    // Execute the actual inline button handler and any explicit click binding.
    const handler = page.match(/id="btnActivateCamera" onclick="([^"]+)"/)[1];
    vm.runInContext(handler, env.sandbox);
    env.element('btnActivateCamera').listeners.click?.({ preventDefault() {} });
    await settle();
    assert.equal(calls, 1, 'Satu klik hanya boleh meminta satu stream');
    assert.equal(env.element('checkinVideo').srcObject, stream);
    assert.equal(env.element('checkinCameraPlaceholder').style.display, 'none');

    env.sandbox.toggleCameraFace();
    await settle();
    assert.equal(calls, 2);
    env.sandbox.runInitialCheckin();
    await settle();
    assert.equal(env.requests[0].action, 'challenge');
    assert.match(env.requests[0].csrf_token, /^[a-f0-9]{64}$/);
    assert.equal(env.requests[0].registration_id, 42);
    const checkin = env.requests.find(request => request.action === 'initial_checkin');
    assert.equal(checkin.challenge_id, 'nonce-from-server');
    assert.equal(checkin.frames.length, 3);
    assert.equal(env.element('btnRunCheckin').disabled, false);
    assert.equal(env.element('facePoseGuide').style.display, 'none');
    assert.equal(env.timers.size, 0, 'Timer dibersihkan setelah respons');

    // Server tidak merespons: pulihkan tombol dan tampilkan batas waktu.
    env.sandbox.fetch = () => new Promise(() => {});
    env.sandbox.runInitialCheckin();
    await settle();
    assert.equal(env.element('btnRunCheckin').disabled, true);
    const timer = [...env.timers.values()].find(timer => timer.delay === 20000);
    assert.ok(timer, 'Batas waktu 20 detik harus terpasang');
    timer.callback();
    await settle();
    assert.equal(env.element('btnRunCheckin').disabled, false);
    assert.match(env.element('checkinAlert').textContent, /20 detik/);

    // HTML error hosting dan sesi kedaluwarsa harus menghasilkan pesan yang jelas.
    env.sandbox.fetch = () => Promise.resolve({ ok: false, status: 502, json: () => Promise.reject(new SyntaxError('HTML')) });
    env.sandbox.runInitialCheckin();
    await settle();
    assert.match(env.element('checkinAlert').textContent, /HTTP 502/);
    assert.equal(env.element('btnRunCheckin').disabled, false);
    env.sandbox.fetch = () => Promise.resolve({ ok: false, status: 401, json: () => Promise.resolve({ message: 'Sesi login telah berakhir' }) });
    env.sandbox.runInitialCheckin();
    await settle();
    assert.match(env.element('checkinAlert').textContent, /Sesi login telah berakhir/);

    env.sandbox.fetch = () => Promise.resolve({ ok: true, status: 200, json: () => Promise.resolve({ challenge_id: 'test', direction: 'right' }) });
    env.sandbox.document.createElement = () => { throw new Error('Snapshot gagal'); };
    env.sandbox.runInitialCheckin();
    await settle();
    assert.match(env.element('checkinAlert').textContent, /Snapshot gagal/);
    assert.equal(env.element('btnRunCheckin').disabled, false);

    const denied = environment(() => Promise.reject({ name: 'NotAllowedError', message: 'Denied' }));
    vm.runInContext(script, denied.sandbox);
    denied.sandbox.startCheckinCamera(true);
    await settle();
    assert.match(denied.element('checkinAlert').innerHTML, /Izin Kamera Terblokir/);
    assert.equal(denied.element('checkinCameraPlaceholder').style.display, 'flex');
    assert.equal(denied.alerts.length, 1);

    const unsupported = environment();
    vm.runInContext(script, unsupported.sandbox);
    unsupported.sandbox.startCheckinCamera(true);
    assert.match(unsupported.element('checkinAlert').innerHTML, /Tidak Mendukung Kamera/);

    const verified = environment(() => Promise.resolve(stream));
    let cbtSettings;
    verified.sandbox.PretesCbt = { init(settings) { cbtSettings = settings; } };
    vm.runInContext(renderScript(true), verified.sandbox);
    await settle();
    assert.equal(verified.element('proctorVideo').srcObject, stream);
    assert.equal(typeof cbtSettings.request, 'function');
    cbtSettings.onSubmitted();
    assert.equal(verified.element('proctorTimerDisplay').textContent, 'Selesai');
    assert.equal(verified.element('proctorStatusTitle').textContent, 'Ujian Selesai');
    console.log('PASS: PHP/JS runtime, aktivasi satu kali, balik kamera, request verifikasi, izin ditolak, browser tidak mendukung, dan sesi terverifikasi.');
})().catch(error => { console.error(error); process.exitCode = 1; });
