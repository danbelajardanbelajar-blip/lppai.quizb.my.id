const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');
const source = fs.readFileSync(path.join(__dirname, '../assets/js/pretes-cbt.js'), 'utf8');
const settle = () => new Promise(resolve => setImmediate(resolve));
const clone = value => JSON.parse(JSON.stringify(value));

function environment() {
    function node() {
        return { style: {}, children: [], textContent: '', className: '', disabled: false,
            replaceChildren(...children) { this.children = children; },
            append(...children) { this.children.push(...children); },
            appendChild(child) { this.children.push(child); },
            setAttribute(key, value) { this[key] = value; }
        };
    }
    const elements = new Map(), events = {}, intervals = [], requests = [], deferred = [];
    let now = 1000, stored = null, mode = 'immediate', submitted = false, started = 0;
    const element = id => { if (!elements.has(id)) elements.set(id, node()); return elements.get(id); };
    const sandbox = {
        document: { getElementById: element, createElement: node },
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
        if (payload.action === 'submit') stored = { ...stored, submitted: true, score: 88, correct: 22, questions: [] };
        return { success: true, attempt: stored ? clone(stored) : null };
    };
    sandbox.PretesCbt.init({ request, onStarted: () => { started++; }, onSubmitted: () => { submitted = true; } });
    return { element, events, intervals, requests, deferred,
        setMode(value) { mode = value; }, advance(value) { now += value; },
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
    radio(0).onchange();
    radio(1).onchange();
    assert.equal(env.deferred.length, 1);
    env.deferred.shift()();
    await settle();
    assert.equal(env.deferred.length, 1, 'Latest answer must be sent after old response');
    env.deferred.shift()();
    await settle();
    assert.equal(env.stored.questions[0].answer, 'B');
    assert.match(env.element('cbtMessage').textContent, /Semua perubahan/);
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
    expiry.intervals[0]();
    await settle();
    assert.equal(expiry.submitted, true, 'Countdown expiry collects saved answers');
    assert.equal(expiry.element('cbtTimer').textContent, 'Selesai');
    console.log('PASS: CBT navigation, text safety, serialized autosave, stale responses, offline retry, safe manual submit, server result, and time expiry.');
})().catch(error => { console.error(error); process.exitCode = 1; });
