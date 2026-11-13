const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');
const source = fs.readFileSync(path.join(__dirname, '../assets/js/pretes-cbt.js'), 'utf8');
const settle = () => new Promise(resolve => setImmediate(resolve));
const clone = value => JSON.parse(JSON.stringify(value));

function environment(settings = {}) {
    function node() {
        return { style: {}, children: [], textContent: '', className: '', disabled: false, value: '',
            addEventListener() {},
            replaceChildren(...children) { this.children = children; },
            append(...children) { this.children.push(...children); },
            appendChild(child) { this.children.push(child); },
            setAttribute(key, value) { this[key] = value; }
        };
    }
    const elements = new Map(), events = {}, intervals = [], requests = [], deferred = [];
    let now = 1000, stored = settings.attempt ? clone(settings.attempt) : null, mode = 'immediate', submitted = false, started = 0;
    const element = id => { if (!elements.has(id)) elements.set(id, node()); return elements.get(id); };
    const sandbox = {
        document: { getElementById: element, createElement: node, addEventListener: (name, fn) => { events[name] = fn; } },
        Date: { now: () => now },
        setInterval(fn) { intervals.push(fn); return intervals.length; }, clearInterval() {},
        confirm: () => true, addEventListener: (name, fn) => { events[name] = fn; }
    };
    sandbox.window = sandbox;
    vm.createContext(sandbox);
    vm.runInContext(source, sandbox);
    const request = async payload => {
        requests.push(clone(payload));
        if (payload.action === 'start' && !stored) stored = {
            id: 1, submitted: false, remaining_seconds: 1800, total: 25, answered: 0, score: null, correct: null,
            questions: Array.from({ length: 25 }, (_, index) => ({ position: index + 1, text: '<img onerror=alert(1)> Soal ' + (index + 1),
                options: { A: 'Satu', B: 'Dua', C: 'Tiga', D: 'Empat', E: 'Lima' }, answer: null, flagged: false }))
        };
        if (payload.action === 'save') {
            if (mode === 'fail') throw new Error('Offline');
            if (mode === 'defer') await new Promise(resolve => deferred.push(resolve));
            const q = stored.questions[payload.position - 1];
            q.answer = payload.answer; q.flagged = payload.flagged;
        }
        if (payload.action === 'interrupt') {
            if (mode === 'fail') throw new Error('Offline');
            stored.paused = true;
            stored.revision = (stored.revision || 0) + 1;
            stored.resume_position = payload.position;
            stored.pause_reason = payload.reason;
        }
        if (payload.action === 'resume') stored.paused = false;
        if (payload.action === 'submit') stored = { ...stored, submitted: true, score: 88, correct: 22, questions: [] };
        return { success: true, attempt: stored ? clone(stored) : null };
    };
    sandbox.PretesCbt.init({ request, verifyAttention: settings.verifyAttention, onStarted: () => { started++; }, onSubmitted: () => { submitted = true; } });
    return { element, events, intervals, requests, deferred,
        setMode(value) { mode = value; }, advance(value) { now += value; },
        interrupt: reason => sandbox.PretesCbt.interrupt(reason),
        setHidden(value) { sandbox.document.hidden = value; events.visibilitychange(); },
        get stored() { return stored; }, get submitted() { return submitted; }, get started() { return started; } };
}

(async () => {
    const env = environment();
    await settle();
    assert.equal(env.element('cbtStart').disabled, false);
    assert.equal(env.started, 0);
    await env.element('cbtStart').onclick();
    assert.equal(env.started, 1);
    assert.equal(env.element('cbtWorkspace').style.display, 'grid');
    assert.equal(env.element('cbtNumbers').children.length, 25);
    assert.match(env.element('cbtQuestion').textContent, /^<img onerror/); // Rendered as text, never HTML.
    assert.equal(env.element('cbtTimer').textContent, '30:00');
    const radio = index => env.element('cbtOptions').children[index + 1].children[0];
    env.setMode('defer');
    const oldRadio = radio(0);
    oldRadio.onchange();
    assert.equal(env.element('cbtPosition').textContent, 'Soal 2 dari 25', 'Answer advances immediately while saving');
    oldRadio.onchange();
    assert.equal(env.element('cbtPosition').textContent, 'Soal 2 dari 25', 'Detached option cannot skip another question');
    env.element('cbtPrevious').onclick();
    radio(1).onchange();
    assert.equal(env.deferred.length, 1);
    env.deferred.shift()();
    await settle();
    assert.equal(env.deferred.length, 1, 'Latest answer must be sent after old response');
    env.deferred.shift()();
    await settle();
    assert.equal(env.stored.questions[0].answer, 'B');
    assert.match(env.element('cbtMessage').textContent, /Semua perubahan/);
    env.setMode('immediate');
    env.element('cbtFlag').checked = true;
    env.element('cbtFlag').onchange();
    await settle();
    assert.equal(env.element('cbtPosition').textContent, 'Soal 2 dari 25', 'Flagging does not advance');
    env.element('cbtClear').onclick();
    await settle();
    assert.equal(env.element('cbtPosition').textContent, 'Soal 2 dari 25', 'Clearing does not advance');
    env.element('cbtNumbers').children[24].onclick();
    radio(4).onchange();
    await settle();
    assert.equal(env.element('cbtPosition').textContent, 'Soal 25 dari 25');
    assert.equal(env.stored.questions[24].answer, 'E');
    assert.equal(env.submitted, false, 'Last answer must not submit automatically');
    env.setMode('fail');
    radio(3).onchange();
    await settle();
    let prevented = false;
    env.events.beforeunload({ preventDefault() { prevented = true; } });
    assert.equal(prevented, true, 'Unsaved answers must warn before closing');
    assert.equal(env.element('cbtRetry').style.display, 'block');
    await env.element('cbtSubmit').onclick();
    await settle();
    assert.equal(env.requests.filter(request => request.action === 'submit').length, 0, 'Manual submit must not discard unsaved answers');
    env.setMode('immediate');
    env.element('cbtRetry').onclick();
    await settle();
    await settle();
    assert.equal(env.submitted, true);
    assert.equal(env.element('cbtWorkspace').style.display, 'none');
    assert.equal(env.element('cbtResult').children[1].textContent, '88', 'Display only server score');
    assert.equal(env.requests.filter(request => request.action === 'submit').length, 1);
    assert.equal(env.requests.filter(request => request.action === 'save').at(-1).answer, 'D');

    const expiry = environment();
    await settle();
    await expiry.element('cbtStart').onclick();
    expiry.advance(1800000);
    expiry.element('cbtOptions').children[1].children[0].onchange();
    assert.equal(expiry.element('cbtPosition').textContent, 'Soal 1 dari 25', 'Expired answer must not advance');
    expiry.intervals[0]();
    await settle();
    assert.equal(expiry.submitted, true, 'Countdown expiry collects saved answers');
    assert.equal(expiry.element('cbtTimer').textContent, 'Selesai');

    let cameraReady = false;
    const paused = environment({ verifyAttention: async () => { if (!cameraReady) throw new Error('Wajah belum sesuai'); } });
    await settle();
    paused.setHidden(true);
    assert.equal(paused.requests.filter(r => r.action === 'interrupt').length, 0, 'No penalty before starting exam');
    paused.setHidden(false);
    await paused.element('cbtStart').onclick();
    paused.setHidden(true);
    assert.equal(paused.element('cbtInterruption').style.display, 'flex');
    assert.equal(paused.element('cbtWorkspace').inert, true);
    paused.interrupt('face_missing');
    await settle();
    assert.equal(paused.requests.filter(r => r.action === 'interrupt').length, 1, 'Overlapping signals cause one interruption');
    paused.advance(120000);
    paused.intervals[0]();
    assert.equal(paused.element('cbtTimer').textContent, '30:00', 'Popup freezes countdown');
    paused.element('cbtOptions').children[1].children[0].onchange();
    await paused.element('cbtSubmit').onclick();
    assert.equal(paused.requests.filter(r => ['save', 'submit'].includes(r.action)).length, 0, 'Popup blocks answering and submitting');
    await paused.element('cbtResume').onclick();
    assert.equal(paused.requests.filter(r => r.action === 'resume').length, 0, 'Hidden page cannot resume');
    paused.setHidden(false);
    await paused.element('cbtResume').onclick();
    assert.match(paused.element('cbtInterruptionStatus').textContent, /Wajah belum sesuai/);
    assert.equal(paused.element('cbtWorkspace').inert, true, 'Failed camera check keeps popup');
    cameraReady = true;
    await paused.element('cbtResume').onclick();
    assert.equal(paused.element('cbtInterruption').style.display, 'none');
    assert.equal(paused.element('cbtWorkspace').inert, false);
    paused.advance(10000);
    paused.intervals.at(-1)();
    assert.equal(paused.element('cbtTimer').textContent, '29:50', 'Timer resumes only after popup closes');
    paused.element('cbtOptions').children[1].children[0].onchange();
    await settle();
    assert.equal(paused.requests.filter(r => r.action === 'save').at(-1).revision, 1, 'New saves use remapped revision');
    paused.setMode('fail');
    paused.interrupt('face_missing');
    await settle();
    assert.match(paused.element('cbtInterruptionStatus').textContent, /belum terkonfirmasi/);
    const retryEvent = paused.requests.filter(r => r.action === 'interrupt').at(-1).event_id;
    paused.setMode('immediate');
    await paused.element('cbtResume').onclick();
    assert.equal(paused.requests.filter(r => r.action === 'interrupt').at(-1).event_id, retryEvent, 'Offline retry reuses event ID');
    const restored = environment({ attempt: { ...clone(paused.stored), paused: true, resume_position: 4, pause_reason: 'page_hidden' } });
    await settle();
    assert.equal(restored.element('cbtInterruption').style.display, 'flex', 'Refresh restores popup');
    assert.equal(restored.element('cbtPosition').textContent, 'Soal 4 dari 25');
    const slow = environment();
    await settle();
    await slow.element('cbtStart').onclick();
    slow.setMode('defer');
    slow.element('cbtOptions').children[1].children[0].onchange();
    slow.element('cbtOptions').children[2].children[0].onchange();
    slow.interrupt('page_hidden');
    await settle();
    const pendingAtPause = slow.requests.find(r => r.action === 'interrupt').pending;
    assert.deepEqual(pendingAtPause.map(r => r.position), [1, 2], 'Pause sends other unsaved answers atomically');
    slow.deferred.shift()();
    await settle();
    assert.equal(slow.requests.filter(r => r.action === 'save').length, 1, 'Old autosave queue stops when positions change');
    await slow.element('cbtResume').onclick();
    assert.equal(slow.element('cbtInterruption').style.display, 'none', 'Delayed old autosave does not prevent resume');
    console.log('PASS: CBT navigation, text safety, serialized autosave, stale responses, offline retry, safe manual submit, server result, and time expiry.');
})().catch(error => { console.error(error); process.exitCode = 1; });
