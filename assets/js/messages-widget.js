/**
 * LPPAI Corner - Widget Pesan Melayang (kanan bawah)
 *
 * - Mahasiswa/Dosen ↔ Admin LPPAI (admin: kotak masuk tim + mulai percakapan baru).
 * - Polling adaptif (panel terbuka 4 dtk, terlihat 15 dtk, tab tersembunyi 60 dtk, backoff saat error),
 *   sinkron antar-tab via BroadcastChannel, dedup notifikasi via localStorage.
 * - Notifikasi: badge, judul tab "(n)", toast, suara singkat (Web Audio), Notification API saat tab tidak aktif.
 * - Disembunyikan otomatis saat ujian CBT berlangsung, kamera aktif, upload file berjalan, layar penuh,
 *   modal terbuka, atau halaman meminta (data-hide-messages / window.LppaiMessages.hide()).
 * Tanpa dependensi; semua teks pengguna dirender via textContent (aman XSS).
 */
(function () {
    'use strict';
    const CFG = window.LPPAI_MSG;
    if (!CFG || !CFG.api || window.LppaiMessages) return;

    const IS_ADMIN = !!CFG.isAdmin;
    const UID = String(CFG.userId || '0');
    const LS = {
        get(k) { try { return localStorage.getItem('lmw:' + UID + ':' + k); } catch (e) { return null; } },
        set(k, v) { try { localStorage.setItem('lmw:' + UID + ':' + k, String(v)); } catch (e) {} },
    };
    const channel = 'BroadcastChannel' in window ? new BroadcastChannel('lppai-messages-' + UID) : null;

    const state = {
        open: false, view: IS_ADMIN ? 'list' : 'chat', threadId: null, thread: null,
        lastId: 0, firstId: 0, hasMore: false, rev: 0, unread: 0,
        polling: null, failures: 0, hiddenReasons: new Set(), forcedHide: new Set(),
        uploads: 0, sending: false, baseTitle: document.title, userInteracted: false,
    };

    // ── Util ──────────────────────────────────────────────────────────────
    const el = (tag, cls, text) => { const n = document.createElement(tag); if (cls) n.className = cls; if (text != null) n.textContent = text; return n; };
    const uuid = () => (window.crypto && crypto.randomUUID) ? crypto.randomUUID().replace(/-/g, '') : (Date.now().toString(36) + Math.random().toString(36).slice(2));
    const fmtTime = ts => new Date(ts * 1000).toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit' });
    const fmtDay = ts => {
        const d = new Date(ts * 1000), today = new Date(), y = new Date(); y.setDate(today.getDate() - 1);
        if (d.toDateString() === today.toDateString()) return 'Hari ini';
        if (d.toDateString() === y.toDateString()) return 'Kemarin';
        return d.toLocaleDateString('id-ID', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' });
    };
    const fmtRel = ts => {
        if (!ts) return '';
        const s = Math.floor(Date.now() / 1000) - ts;
        if (s < 60) return 'baru saja';
        if (s < 3600) return Math.floor(s / 60) + ' mnt';
        if (s < 86400) return fmtTime(ts);
        return new Date(ts * 1000).toLocaleDateString('id-ID', { day: 'numeric', month: 'short' });
    };
    const initials = name => (String(name || '?').trim().split(/\s+/).slice(0, 2).map(w => w[0] || '').join('') || '?').toUpperCase();

    async function api(action, payload = {}) {
        const ctrl = new AbortController();
        const timer = setTimeout(() => ctrl.abort(), 20000);
        try {
            const res = await fetch(CFG.api, {
                method: 'POST', credentials: 'same-origin', signal: ctrl.signal,
                headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                body: JSON.stringify(Object.assign({ action, csrf_token: CFG.csrf }, payload)),
            });
            let data = null;
            try { data = await res.json(); } catch (e) { /* bukan JSON */ }
            if (!res.ok || !data || !data.success) {
                const err = new Error((data && data.message) || ('Gagal memuat pesan (HTTP ' + res.status + ').'));
                err.status = res.status;
                throw err;
            }
            return data;
        } finally { clearTimeout(timer); }
    }

    // ── DOM ───────────────────────────────────────────────────────────────
    const ICON_CHAT = '<svg viewBox="0 0 24 24" width="26" height="26" aria-hidden="true"><path fill="currentColor" d="M12 3C6.48 3 2 6.94 2 11.8c0 2.6 1.3 4.94 3.37 6.55L4.5 21.5a.6.6 0 0 0 .86.68l3.7-1.9c.94.25 1.93.38 2.94.38 5.52 0 10-3.94 10-8.86S17.52 3 12 3Zm-4.5 10.1a1.3 1.3 0 1 1 0-2.6 1.3 1.3 0 0 1 0 2.6Zm4.5 0a1.3 1.3 0 1 1 0-2.6 1.3 1.3 0 0 1 0 2.6Zm4.5 0a1.3 1.3 0 1 1 0-2.6 1.3 1.3 0 0 1 0 2.6Z"/></svg>';
    const ICON_CLOSE = '<svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true"><path fill="currentColor" d="M6.4 5 5 6.4 10.6 12 5 17.6 6.4 19l5.6-5.6 5.6 5.6 1.4-1.4L13.4 12 19 6.4 17.6 5 12 10.6z"/></svg>';
    const ICON_BACK = '<svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true"><path fill="currentColor" d="M15.4 5.4 14 4l-8 8 8 8 1.4-1.4L8.8 12z"/></svg>';
    const ICON_SEND = '<svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true"><path fill="currentColor" d="M3.4 20.4 21 12 3.4 3.6 3.4 10l12.6 2-12.6 2z"/></svg>';

    const root = el('div', 'lmw');
    root.id = 'lppai-messages';
    root.setAttribute('data-role', IS_ADMIN ? 'admin' : 'user');

    const fab = el('button', 'lmw-fab');
    fab.type = 'button';
    fab.setAttribute('aria-label', 'Buka Pesan');
    fab.setAttribute('aria-expanded', 'false');
    fab.setAttribute('aria-controls', 'lppai-messages-panel');
    fab.innerHTML = ICON_CHAT;
    const badge = el('span', 'lmw-badge');
    badge.hidden = true;
    badge.setAttribute('aria-live', 'polite');
    fab.appendChild(badge);

    const panel = el('section', 'lmw-panel');
    panel.id = 'lppai-messages-panel';
    panel.setAttribute('role', 'dialog');
    panel.setAttribute('aria-modal', 'false');
    panel.setAttribute('aria-label', 'Pesan');
    panel.hidden = true;

    const head = el('header', 'lmw-head');
    const backBtn = el('button', 'lmw-icon-btn lmw-back');
    backBtn.type = 'button'; backBtn.innerHTML = ICON_BACK; backBtn.setAttribute('aria-label', 'Kembali ke daftar percakapan');
    const avatar = el('div', 'lmw-avatar', IS_ADMIN ? '✉' : 'LP');
    const titles = el('div', 'lmw-titles');
    const titleEl = el('div', 'lmw-title', IS_ADMIN ? 'Kotak Masuk Pesan' : 'Admin LPPAI');
    const subEl = el('div', 'lmw-sub', IS_ADMIN ? 'Pesan dari mahasiswa & dosen' : 'Layanan bantuan LPPAI');
    titles.append(titleEl, subEl);
    const bellBtn = el('button', 'lmw-icon-btn lmw-bell');
    bellBtn.type = 'button';
    const closeBtn = el('button', 'lmw-icon-btn');
    closeBtn.type = 'button'; closeBtn.innerHTML = ICON_CLOSE; closeBtn.setAttribute('aria-label', 'Tutup Pesan');
    head.append(backBtn, avatar, titles, bellBtn, closeBtn);

    // Admin: daftar percakapan
    const listView = el('div', 'lmw-view lmw-list-view');
    const listTools = el('div', 'lmw-list-tools');
    const searchInput = el('input', 'lmw-search');
    searchInput.type = 'search'; searchInput.placeholder = 'Cari nama / NIM…'; searchInput.setAttribute('aria-label', 'Cari percakapan');
    const newBtn = el('button', 'lmw-new-btn', '＋ Pesan baru');
    newBtn.type = 'button';
    listTools.append(searchInput, newBtn);
    const listEl = el('ul', 'lmw-threads');
    listEl.setAttribute('role', 'list');
    const listEmpty = el('div', 'lmw-empty', 'Belum ada percakapan.');
    listView.append(listTools, listEl, listEmpty);

    // Admin: mulai percakapan baru
    const newView = el('div', 'lmw-view lmw-new-view');
    const userSearch = el('input', 'lmw-search');
    userSearch.type = 'search'; userSearch.placeholder = 'Ketik minimal 2 huruf nama / NIM penerima…'; userSearch.setAttribute('aria-label', 'Cari penerima');
    const userList = el('ul', 'lmw-threads');
    const newHint = el('div', 'lmw-empty', 'Cari mahasiswa atau dosen untuk memulai percakapan.');
    newView.append(userSearch, userList, newHint);

    // Percakapan
    const chatView = el('div', 'lmw-view lmw-chat-view');
    const log = el('div', 'lmw-log');
    log.setAttribute('role', 'log'); log.setAttribute('aria-live', 'polite'); log.tabIndex = 0;
    const moreBtn = el('button', 'lmw-more', 'Muat pesan sebelumnya');
    moreBtn.type = 'button'; moreBtn.hidden = true;
    const intro = el('div', 'lmw-intro');
    const form = el('form', 'lmw-composer');
    form.setAttribute('autocomplete', 'off');
    const input = el('textarea', 'lmw-input');
    input.rows = 1; input.maxLength = 2000; input.placeholder = 'Tulis pesan…'; input.setAttribute('aria-label', 'Tulis pesan');
    const sendBtn = el('button', 'lmw-send');
    sendBtn.type = 'submit'; sendBtn.innerHTML = ICON_SEND; sendBtn.setAttribute('aria-label', 'Kirim pesan'); sendBtn.disabled = true;
    form.append(input, sendBtn);
    const errorBar = el('div', 'lmw-error'); errorBar.hidden = true; errorBar.setAttribute('role', 'alert');
    chatView.append(moreBtn, log, errorBar, form);

    panel.append(head, listView, newView, chatView);
    root.append(panel, fab);

    // ── Tampilan ──────────────────────────────────────────────────────────
    function setView(view) {
        state.view = view;
        listView.hidden = view !== 'list';
        newView.hidden = view !== 'new';
        chatView.hidden = view !== 'chat';
        backBtn.hidden = !IS_ADMIN || view === 'list';
        if (view === 'list') { titleEl.textContent = 'Kotak Masuk Pesan'; subEl.textContent = 'Pesan dari mahasiswa & dosen'; avatar.textContent = '✉'; }
        if (view === 'new') { titleEl.textContent = 'Pesan Baru'; subEl.textContent = 'Pilih penerima'; avatar.textContent = '＋'; setTimeout(() => userSearch.focus(), 50); }
    }

    function updateBadge() {
        const n = state.unread;
        badge.hidden = n <= 0;
        badge.textContent = n > 99 ? '99+' : String(n);
        fab.classList.toggle('has-unread', n > 0);
        fab.setAttribute('aria-label', n > 0 ? ('Buka Pesan, ' + n + ' belum dibaca') : 'Buka Pesan');
        document.title = (n > 0 ? '(' + (n > 99 ? '99+' : n) + ') ' : '') + state.baseTitle;
    }

    function updateBell() {
        const supported = 'Notification' in window;
        const perm = supported ? Notification.permission : 'unsupported';
        bellBtn.textContent = perm === 'granted' ? '🔔' : '🔕';
        bellBtn.title = perm === 'granted' ? 'Notifikasi browser aktif'
            : perm === 'denied' ? 'Notifikasi diblokir browser (izinkan lewat ikon gembok di bilah alamat)'
            : supported ? 'Aktifkan notifikasi browser' : 'Browser tidak mendukung notifikasi';
        bellBtn.setAttribute('aria-label', bellBtn.title);
        bellBtn.classList.toggle('is-on', perm === 'granted');
    }

    function renderIntro() {
        intro.replaceChildren();
        if (IS_ADMIN) return;
        intro.append(el('strong', null, 'Assalamu’alaikum 👋'),
            el('p', null, 'Ada pertanyaan seputar pretes, tutorial, atau sertifikat? Kirim pesan ke Admin LPPAI di sini. Balasan akan muncul di jendela ini dan Anda mendapat notifikasi.'));
    }

    function bubble(m) {
        const row = el('div', 'lmw-msg ' + (m.mine ? 'is-mine' : 'is-theirs') + (m.pending ? ' is-pending' : '') + (m.failed ? ' is-failed' : ''));
        row.dataset.id = m.id || '';
        if (m.client_id) row.dataset.clientId = m.client_id;
        if (!m.mine) row.append(el('div', 'lmw-msg-name', m.sender_name));
        const b = el('div', 'lmw-msg-body');
        b.textContent = m.body;
        const meta = el('div', 'lmw-msg-meta', m.failed ? 'Gagal terkirim · ketuk untuk ulang' : m.pending ? 'Mengirim…' : fmtTime(m.created_at));
        row.append(b, meta);
        if (m.failed) {
            row.tabIndex = 0;
            row.addEventListener('click', () => { row.remove(); sendMessage(m.body, m.client_id); }, { once: true });
        }
        return row;
    }

    function appendMessages(list, { prepend = false } = {}) {
        if (!list.length) return;
        const atBottom = log.scrollHeight - log.scrollTop - log.clientHeight < 80;
        const frag = document.createDocumentFragment();
        let lastDay = prepend ? '' : (log.dataset.lastDay || '');
        for (const m of list) {
            if (m.client_id) {
                const ghost = log.querySelector('.lmw-msg[data-client-id="' + CSS.escape(m.client_id) + '"]');
                if (ghost) ghost.remove();
            }
            if (m.id && log.querySelector('.lmw-msg[data-id="' + m.id + '"]')) continue;
            const day = fmtDay(m.created_at);
            if (day !== lastDay) { frag.append(el('div', 'lmw-day', day)); lastDay = day; }
            frag.append(bubble(m));
            if (m.id) {
                state.lastId = Math.max(state.lastId, m.id);
                state.firstId = state.firstId ? Math.min(state.firstId, m.id) : m.id;
            }
        }
        if (prepend) {
            const before = log.scrollHeight;
            log.insertBefore(frag, log.firstChild);
            log.scrollTop += log.scrollHeight - before;
        } else {
            log.dataset.lastDay = lastDay;
            log.append(frag);
            if (atBottom || list.some(m => m.mine)) log.scrollTop = log.scrollHeight;
        }
    }

    function resetChat() {
        log.replaceChildren(intro);
        log.dataset.lastDay = '';
        state.lastId = 0; state.firstId = 0; state.hasMore = false;
        moreBtn.hidden = true;
        renderIntro();
    }

    function showError(text) {
        errorBar.textContent = text || '';
        errorBar.hidden = !text;
    }

    // ── Data ──────────────────────────────────────────────────────────────
    async function openThread(threadId, summary) {
        state.threadId = threadId || null;
        state.thread = summary || null;
        if (summary) { titleEl.textContent = summary.title; subEl.textContent = summary.subtitle || ''; avatar.textContent = initials(summary.title); }
        else if (!IS_ADMIN) { titleEl.textContent = 'Admin LPPAI'; subEl.textContent = 'Layanan bantuan LPPAI'; avatar.textContent = 'LP'; }
        resetChat();
        setView('chat');
        showError('');
        try {
            const data = await api('thread', { thread_id: state.threadId || 0 });
            if (data.thread) {
                state.threadId = data.thread.id;
                state.thread = data.thread;
                titleEl.textContent = data.thread.title;
                subEl.textContent = data.thread.subtitle || '';
                avatar.textContent = IS_ADMIN ? initials(data.thread.title) : 'LP';
            }
            appendMessages(data.messages || []);
            state.hasMore = !!data.has_more;
            moreBtn.hidden = !state.hasMore;
            log.scrollTop = log.scrollHeight;
            refreshSoon();
        } catch (e) { showError(e.message); }
        if (!matchMedia('(pointer: coarse)').matches) setTimeout(() => input.focus(), 60);
    }

    async function loadOlder() {
        if (!state.firstId) return;
        moreBtn.disabled = true;
        try {
            const data = await api('thread', { thread_id: state.threadId || 0, before_id: state.firstId });
            appendMessages(data.messages || [], { prepend: true });
            state.hasMore = !!data.has_more;
            moreBtn.hidden = !state.hasMore;
        } catch (e) { showError(e.message); }
        moreBtn.disabled = false;
    }

    async function fetchNew() {
        if (state.view !== 'chat' || (!state.threadId && IS_ADMIN)) return;
        const data = await api('thread', { thread_id: state.threadId || 0, after_id: state.lastId });
        if (data.thread && !state.threadId) state.threadId = data.thread.id;
        appendMessages(data.messages || []);
    }

    function threadItem(t, onPick, metaText) {
        const li = el('li', 'lmw-thread' + (t.unread > 0 ? ' is-unread' : ''));
        const btn = el('button', 'lmw-thread-btn');
        btn.type = 'button';
        const av = el('span', 'lmw-avatar sm', initials(t.title || t.name));
        const main = el('span', 'lmw-thread-main');
        const top = el('span', 'lmw-thread-top');
        top.append(el('span', 'lmw-thread-name', t.title || t.name), el('span', 'lmw-thread-time', fmtRel(t.last_message_at)));
        const bottom = el('span', 'lmw-thread-bottom');
        const prev = el('span', 'lmw-thread-preview', metaText != null ? metaText : ((t.last_sender_role === 'admin' ? 'Admin: ' : '') + (t.preview || '')));
        bottom.append(prev);
        if (t.unread > 0) bottom.append(el('span', 'lmw-pill', String(t.unread)));
        main.append(top, bottom);
        btn.append(av, main);
        btn.addEventListener('click', () => onPick(t));
        li.append(btn);
        return li;
    }

    let listReq = 0;
    async function loadThreads() {
        if (!IS_ADMIN) return;
        const my = ++listReq;
        try {
            const data = await api('threads', { q: searchInput.value.trim() });
            if (my !== listReq) return;
            listEl.replaceChildren(...data.threads.map(t => threadItem(t, picked => openThread(picked.id, picked))));
            listEmpty.hidden = data.threads.length > 0;
            listEmpty.textContent = searchInput.value.trim() ? 'Tidak ada percakapan yang cocok.' : 'Belum ada percakapan. Klik “＋ Pesan baru” untuk memulai.';
        } catch (e) { listEmpty.hidden = false; listEmpty.textContent = e.message; }
    }

    let userReq = 0, userTimer = null;
    function searchUsers() {
        clearTimeout(userTimer);
        userTimer = setTimeout(async () => {
            const q = userSearch.value.trim();
            const my = ++userReq;
            if (q.length < 2) { userList.replaceChildren(); newHint.hidden = false; newHint.textContent = 'Cari mahasiswa atau dosen untuk memulai percakapan.'; return; }
            try {
                const data = await api('users', { q });
                if (my !== userReq) return;
                userList.replaceChildren(...data.users.map(u => threadItem({ title: u.name, last_message_at: 0, unread: 0 }, async () => {
                    try { const s = await api('start', { user_id: u.id }); openThread(s.thread.id, s.thread); }
                    catch (e) { newHint.hidden = false; newHint.textContent = e.message; }
                }, u.meta)));
                newHint.hidden = data.users.length > 0;
                newHint.textContent = 'Penerima tidak ditemukan.';
            } catch (e) { newHint.hidden = false; newHint.textContent = e.message; }
        }, 250);
    }

    async function sendMessage(body, reuseClientId) {
        const text = (body || '').trim();
        if (!text || state.sending) return;
        if (IS_ADMIN && !state.threadId) { showError('Pilih percakapan terlebih dahulu.'); return; }
        const clientId = reuseClientId || uuid();
        const ghost = { client_id: clientId, mine: true, pending: true, body: text, created_at: Math.floor(Date.now() / 1000), sender_name: 'Anda' };
        appendMessages([ghost]);
        state.sending = true;
        showError('');
        try {
            const data = await api('send', { thread_id: state.threadId || 0, body: text, client_id: clientId });
            if (!state.threadId) state.threadId = data.message.thread_id;
            appendMessages([data.message]);
            state.rev = Math.max(state.rev, data.message.id);
            LS.set('notified', Math.max(Number(LS.get('notified') || 0), data.message.id));
            channel && channel.postMessage({ type: 'sent', id: data.message.id });
        } catch (e) {
            const g = log.querySelector('.lmw-msg[data-client-id="' + CSS.escape(clientId) + '"]');
            if (g) g.replaceWith(bubble(Object.assign({}, ghost, { pending: false, failed: true })));
            showError(e.message);
        } finally {
            state.sending = false;
            sendBtn.disabled = !input.value.trim();
        }
    }

    // ── Notifikasi ────────────────────────────────────────────────────────
    let audioCtx = null;
    function chime() {
        if (!state.userInteracted) return;
        try {
            audioCtx = audioCtx || new (window.AudioContext || window.webkitAudioContext)();
            const t = audioCtx.currentTime;
            [[880, 0], [1320, 0.12]].forEach(([f, d]) => {
                const o = audioCtx.createOscillator(), g = audioCtx.createGain();
                o.type = 'sine'; o.frequency.value = f;
                g.gain.setValueAtTime(0.0001, t + d);
                g.gain.exponentialRampToValueAtTime(0.18, t + d + 0.02);
                g.gain.exponentialRampToValueAtTime(0.0001, t + d + 0.22);
                o.connect(g).connect(audioCtx.destination);
                o.start(t + d); o.stop(t + d + 0.25);
            });
        } catch (e) {}
    }

    function notifyIncoming(list) {
        const notified = Number(LS.get('notified') || 0);
        const fresh = list.filter(m => m.id > notified && !m.mine);
        if (!fresh.length) return;
        LS.set('notified', Math.max(...fresh.map(m => m.id)));
        const last = fresh[fresh.length - 1];
        const viewingThis = state.open && state.view === 'chat' && !document.hidden && (!IS_ADMIN || state.threadId === last.thread_id);
        if (viewingThis) return;
        // Pengguna: nama admin yang membalas ("Ali · Admin LPPAI"), sesuai sender_name dari server
        const title = (last.sender_name || 'Admin LPPAI') + (fresh.length > 1 ? ' (+' + (fresh.length - 1) + ' pesan)' : '');
        const body = last.body.length > 140 ? last.body.slice(0, 137) + '…' : last.body;
        chime();
        fab.classList.remove('is-ping'); void fab.offsetWidth; fab.classList.add('is-ping');
        if (document.hidden && 'Notification' in window && Notification.permission === 'granted') {
            try {
                const n = new Notification('💬 ' + title, { body, tag: 'lppai-messages', renotify: true, icon: CFG.icon || undefined });
                n.onclick = () => { window.focus(); openPanel(IS_ADMIN ? last.thread_id : null); n.close(); };
            } catch (e) {}
        } else if (typeof window.showToast === 'function' && !state.open) {
            window.showToast('💬 Pesan baru dari ' + title + ': ' + body, 'info', 6000);
        }
    }

    // ── Polling adaptif ───────────────────────────────────────────────────
    function nextDelay() {
        if (state.failures > 0) return Math.min(120000, 5000 * 2 ** Math.min(state.failures, 5));
        if (document.hidden) return 60000;
        return state.open ? 4000 : 15000;
    }
    function schedule(ms) { clearTimeout(state.polling); state.polling = setTimeout(poll, ms ?? nextDelay()); }
    function refreshSoon() { schedule(300); }

    // Mulai dari pesan terakhir yang sudah dinotifikasi (di tab/halaman mana pun), sehingga pesan yang masuk
    // saat pengguna berpindah halaman tetap dinotifikasi tepat sekali.
    state.rev = Number(LS.get('notified') || 0);

    async function poll() {
        if (!navigator.onLine) { schedule(15000); return; }
        try {
            const firstRun = !LS.get('notified');
            const data = await api('poll', { since_id: state.rev });
            state.failures = 0;
            const changed = data.rev !== state.rev;
            state.unread = data.unread;
            updateBadge();
            if (firstRun) LS.set('notified', data.rev);
            else if (data.incoming && data.incoming.length) notifyIncoming(data.incoming);
            state.rev = data.rev;
            channel && channel.postMessage({ type: 'state', unread: data.unread, rev: data.rev });
            if (changed && state.open) {
                if (state.view === 'chat') await fetchNew();
                else if (state.view === 'list') await loadThreads();
            }
        } catch (e) {
            if (e.status === 401) { clearTimeout(state.polling); root.remove(); return; }
            state.failures++;
        }
        schedule();
    }

    channel && channel.addEventListener('message', ev => {
        const d = ev.data || {};
        if (d.type === 'state' && typeof d.unread === 'number') {
            state.unread = d.unread; updateBadge();
            if (d.rev > state.rev && state.open) refreshSoon();
        }
        if (d.type === 'sent' && state.open) refreshSoon();
    });

    // ── Buka / tutup ──────────────────────────────────────────────────────
    function openPanel(threadId) {
        if (root.classList.contains('is-suppressed')) return;
        state.open = true;
        panel.hidden = false;
        root.classList.add('is-open');
        fab.setAttribute('aria-expanded', 'true');
        document.documentElement.classList.add('lmw-open');
        updateBell();
        if (IS_ADMIN) {
            if (threadId) openThread(threadId);
            else if (state.view === 'chat' && state.threadId) openThread(state.threadId, state.thread);
            else { setView('list'); loadThreads(); }
        } else {
            openThread(null);
        }
        refreshSoon();
    }
    function closePanel() {
        state.open = false;
        panel.hidden = true;
        root.classList.remove('is-open');
        fab.setAttribute('aria-expanded', 'false');
        document.documentElement.classList.remove('lmw-open');
        schedule();
    }

    fab.addEventListener('click', () => (state.open ? closePanel() : openPanel()));
    closeBtn.addEventListener('click', () => { closePanel(); fab.focus(); });
    backBtn.addEventListener('click', () => { state.threadId = null; state.thread = null; setView('list'); loadThreads(); });
    newBtn.addEventListener('click', () => { userSearch.value = ''; userList.replaceChildren(); newHint.hidden = false; setView('new'); });
    moreBtn.addEventListener('click', loadOlder);
    let searchTimer = null;
    searchInput.addEventListener('input', () => { clearTimeout(searchTimer); searchTimer = setTimeout(loadThreads, 250); });
    userSearch.addEventListener('input', searchUsers);
    bellBtn.addEventListener('click', async () => {
        if (!('Notification' in window)) return;
        if (Notification.permission === 'default') { try { await Notification.requestPermission(); } catch (e) {} }
        updateBell();
        if (Notification.permission === 'granted' && typeof window.showToast === 'function') window.showToast('Notifikasi pesan browser aktif.', 'success', 3000);
    });
    input.addEventListener('input', () => {
        sendBtn.disabled = !input.value.trim();
        input.style.height = 'auto';
        input.style.height = Math.min(140, input.scrollHeight) + 'px';
    });
    input.addEventListener('keydown', e => {
        if (e.key === 'Enter' && !e.shiftKey && !e.isComposing) { e.preventDefault(); form.requestSubmit(); }
    });
    form.addEventListener('submit', e => {
        e.preventDefault();
        const text = input.value;
        if (!text.trim()) return;
        input.value = ''; input.style.height = 'auto'; sendBtn.disabled = true;
        sendMessage(text);
    });
    document.addEventListener('keydown', e => { if (e.key === 'Escape' && state.open) { closePanel(); fab.focus(); } });
    ['pointerdown', 'keydown'].forEach(t => window.addEventListener(t, () => { state.userInteracted = true; }, { once: true, capture: true }));
    document.addEventListener('visibilitychange', () => { if (!document.hidden) { if (state.unread === 0) updateBadge(); refreshSoon(); } });
    window.addEventListener('online', refreshSoon);

    // ── Sembunyikan otomatis pada keadaan tertentu ───────────────────────
    // Upload: hitung request fetch/XHR yang membawa File/Blob di FormData, dan form multipart yang dikirim.
    const hasFile = body => {
        try {
            if (body instanceof FormData) { for (const v of body.values()) if (v instanceof Blob && v.size > 0) return true; }
            return body instanceof Blob && body.size > 0;
        } catch (e) { return false; }
    };
    const origFetch = window.fetch;
    if (origFetch) {
        window.fetch = function (input, init) {
            const uploading = init && hasFile(init.body);
            if (uploading) { state.uploads++; evaluate(); }
            const p = origFetch.apply(this, arguments);
            if (uploading) p.finally(() => { state.uploads = Math.max(0, state.uploads - 1); evaluate(); }).catch(() => {});
            return p;
        };
    }
    const origSend = XMLHttpRequest.prototype.send;
    XMLHttpRequest.prototype.send = function (body) {
        if (hasFile(body)) {
            state.uploads++; evaluate();
            this.addEventListener('loadend', () => { state.uploads = Math.max(0, state.uploads - 1); evaluate(); }, { once: true });
        }
        return origSend.apply(this, arguments);
    };
    document.addEventListener('submit', e => {
        const f = e.target;
        if (!(f instanceof HTMLFormElement) || f === form) return;
        const files = [...f.querySelectorAll('input[type="file"]')].some(i => i.files && i.files.length);
        if (files) setTimeout(() => { if (!e.defaultPrevented) { state.hiddenReasons.add('upload-form'); evaluate(); } }, 0);
    }, true);
    window.addEventListener('pageshow', () => { state.hiddenReasons.delete('upload-form'); evaluate(); });

    const visible = n => !!n && n.getClientRects().length > 0 && getComputedStyle(n).visibility !== 'hidden';
    function suppressReason() {
        if (state.forcedHide.size) return 'page';
        if (state.hiddenReasons.size) return [...state.hiddenReasons][0];
        if (state.uploads > 0) return 'upload';
        if (document.fullscreenElement || document.webkitFullscreenElement) return 'fullscreen';
        if (document.querySelector('[data-hide-messages]:not([hidden])')) return 'page-flag';
        // Ujian CBT berlangsung (ruang kerja soal tampil / popup jeda ujian)
        if (visible(document.getElementById('cbtWorkspace')) || document.querySelector('dialog.cbt-interruption[open]')) return 'exam';
        // Kamera aktif (verifikasi wajah, scan QR absensi, ambil foto)
        for (const v of document.querySelectorAll('video')) {
            const s = v.srcObject;
            if (s && typeof s.getVideoTracks === 'function' && s.getVideoTracks().some(t => t.readyState === 'live')) return 'camera';
        }
        // Modal/dialog aplikasi terbuka
        if (document.querySelector('.modal-backdrop.show, .bank-modal-backdrop.open, .swal2-container, #confirm-backdrop[style*="flex"], #alert-backdrop[style*="flex"]')) return 'modal';
        return '';
    }
    function evaluate() {
        const reason = suppressReason();
        const suppressed = reason !== '';
        if (suppressed === root.classList.contains('is-suppressed')) return;
        root.classList.toggle('is-suppressed', suppressed);
        root.setAttribute('aria-hidden', suppressed ? 'true' : 'false');
        root.dataset.suppressed = reason;
        if (suppressed && state.open) closePanel();
    }
    setInterval(evaluate, 800);
    document.addEventListener('fullscreenchange', evaluate);

    // ── API publik untuk halaman ─────────────────────────────────────────
    window.LppaiMessages = {
        open: id => openPanel(id),
        close: closePanel,
        hide(reason = 'manual') { state.forcedHide.add(reason); evaluate(); },
        show(reason = 'manual') { state.forcedHide.delete(reason); evaluate(); },
        refresh: refreshSoon,
        get unread() { return state.unread; },
    };

    // ── Mulai ─────────────────────────────────────────────────────────────
    function mount() {
        document.body.appendChild(root);
        setView(state.view);
        renderIntro();
        updateBell();
        evaluate();
        poll();
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', mount);
    else mount();
})();
