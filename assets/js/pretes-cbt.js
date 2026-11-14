(function (window) {
    'use strict';
    let config, attempt = null, position = 0, target = 0, ticker = null, savePromise = null, finishing = false, needsSubmit = false, monitoringStarted = false;
    const pending = new Map();
    let interruption = null, interruptionRequest = null, resuming = false;
    const el = id => document.getElementById(id);
    const message = (text, error) => {
        el('cbtMessage').textContent = text;
        el('cbtMessage').className = 'cbt-message' + (error ? ' error' : '');
    };
    const answered = () => attempt.questions.filter(q => q.answer !== null).length;
    function progress(count, total) {
        el('cbtAnsweredCount').textContent = count + '/' + total;
        el('cbtCompletionFill').style.width = (count / total * 100) + '%';
        el('cbtCompletion').setAttribute('aria-valuenow', String(count));
    }

    function navigation() {
        const nav = el('cbtNumbers');
        nav.replaceChildren();
        attempt.questions.forEach((question, index) => {
            const button = document.createElement('button');
            button.type = 'button';
            button.textContent = String(index + 1);
            button.className = (question.answer ? 'answered ' : '') + (question.flagged ? 'flagged ' : '') + (index === position ? 'current' : '');
            button.setAttribute('aria-label', 'Soal ' + (index + 1) + (question.answer ? ', dijawab' : ', belum dijawab') + (question.flagged ? ', ditandai' : ''));
            if (index === position) button.setAttribute('aria-current', 'step');
            button.onclick = () => { if (!interruption) { position = index; renderQuestion(); } };
            nav.appendChild(button);
        });
        el('cbtProgress').textContent = answered() + '/' + attempt.total + ' dijawab · ' + pending.size + ' perubahan belum tersimpan';
        progress(answered(), attempt.total);
    }

    function edit(answer, flagged) {
        if (!attempt || attempt.submitted || finishing || interruption || Date.now() >= target) return;
        const question = attempt.questions[position];
        question.answer = answer;
        question.flagged = flagged;
        pending.set(question.position, { position: question.position, answer, flagged });
        navigation();
        flush().catch(() => {});
        return true;
    }

    function renderQuestion() {
        if (!attempt || attempt.submitted) return;
        const question = attempt.questions[position];
        el('cbtPosition').textContent = 'Soal ' + (position + 1) + ' dari ' + attempt.total;
        el('cbtQuestion').textContent = question.text;
        const options = el('cbtOptions');
        options.replaceChildren();
        const legend = document.createElement('legend');
        legend.textContent = 'Pilih satu jawaban';
        legend.style.fontSize = '13px';
        options.appendChild(legend);
        Object.entries(question.options).forEach(([letter, text]) => {
            const label = document.createElement('label');
            label.className = 'cbt-option';
            const radio = document.createElement('input');
            radio.type = 'radio'; radio.name = 'cbtAnswer'; radio.value = letter;
            radio.checked = question.answer === letter;
            radio.disabled = Date.now() >= target || finishing || !!interruption;
            radio.onchange = () => {
                if (attempt.questions[position] !== question || !edit(letter, question.flagged)) return;
                if (position < attempt.questions.length - 1) position++;
                renderQuestion();
            };
            const content = document.createElement('span');
            content.textContent = letter + '. ' + text;
            label.append(radio, content);
            options.appendChild(label);
        });
        el('cbtFlag').checked = question.flagged;
        el('cbtFlag').disabled = Date.now() >= target || finishing || !!interruption;
        el('cbtPrevious').disabled = position === 0;
        el('cbtNext').disabled = position === attempt.questions.length - 1;
        navigation();
    }

    function showResult(result) {
        hideInterruption();
        attempt = result;
        progress(result.answered, result.total);
        needsSubmit = false;
        pending.clear();
        if (ticker) clearInterval(ticker);
        el('cbtIntro').style.display = 'none';
        el('cbtWorkspace').style.display = 'none';
        el('cbtTimer').textContent = 'Selesai';
        const box = el('cbtResult');
        box.replaceChildren();
        const title = document.createElement('h4'); title.textContent = 'Ujian sudah dikumpulkan';
        const score = document.createElement('strong'); score.textContent = String(result.score);
        const details = document.createElement('p');
        details.textContent = result.correct + ' benar dari ' + result.total + ' soal. Jawaban kosong dinilai 0. Hasil CBT tersimpan di server.';
        box.append(title, score, details);
        box.style.display = 'block';
        message('Terima kasih. Jawaban dan nilai akhir tidak dapat diubah setelah dikumpulkan.');
        if (config.onSubmitted) config.onSubmitted();
    }

    function apply(data) {
        if (!data.success) throw new Error(data.message || 'CBT belum dapat dimuat.');
        if (!data.attempt) {
            el('cbtIntro').style.display = 'block';
            el('cbtStart').disabled = false;
            message('Verifikasi wajah berhasil. Tekan Mulai Ujian ketika Anda siap.');
            return;
        }
        if (data.attempt.submitted) { showResult(data.attempt); return; }
        attempt = data.attempt;
        target = Date.now() + attempt.remaining_seconds * 1000;
        if (attempt.paused) {
            position = Math.max(0, (attempt.resume_position || 1) - 1);
            interruption = { confirmed: true };
            showInterruption(attempt.pause_reason);
        }
        el('cbtIntro').style.display = 'none';
        el('cbtWorkspace').style.display = 'grid';
        message('Paket soal tersimpan. Pilih jawaban; tunggu status tersimpan sebelum menutup halaman.');
        renderQuestion();
        if (ticker) clearInterval(ticker);
        ticker = setInterval(tick, 1000);
        if (!monitoringStarted && config.onStarted) { monitoringStarted = true; config.onStarted(); }
        tick();
        if (document.hidden || (document.hasFocus && !document.hasFocus())) interrupt('page_hidden');
    }

    function flush() {
        if (savePromise) return savePromise;
        savePromise = (async () => {
            while (pending.size && !attempt.submitted && !interruption) {
                const [key, value] = pending.entries().next().value;
                message('Menyimpan jawaban…');
                const data = await config.request(Object.assign({ action: 'save', revision: attempt.revision || 0 }, value));
                if (data.attempt.submitted) { showResult(data.attempt); return; }
                if (interruption) return;
                if (pending.get(key) === value) pending.delete(key);
                target = Math.min(target, Date.now() + data.attempt.remaining_seconds * 1000);
                navigation();
            }
            el('cbtRetry').style.display = 'none';
            message('Semua perubahan jawaban tersimpan di server.');
        })().catch(error => {
            if (interruption) return;
            message('Belum tersimpan: ' + error.message + ' Jangan tutup halaman. Tekan Coba Simpan Lagi.', true);
            el('cbtRetry').style.display = 'block';
            throw error;
        }).finally(() => { savePromise = null; });
        return savePromise;
    }

    async function finish(expired) {
        if (finishing || interruption || !attempt || attempt.submitted) return;
        finishing = true;
        if (!expired && !needsSubmit && !window.confirm('Kumpulkan ujian? ' + (attempt.total - answered()) + ' soal belum dijawab. Jawaban tidak dapat diubah setelah dikumpulkan.')) {
            finishing = false;
            return;
        }
        el('cbtSubmit').disabled = true;
        renderQuestion();
        try {
            try { await flush(); } catch (error) { if (!expired) throw error; }
            if (attempt.submitted) return;
            message(expired ? 'Waktu habis. Mengumpulkan jawaban yang tersimpan…' : 'Mengumpulkan ujian…');
            apply(await config.request({ action: 'submit', revision: attempt.revision || 0 }));
        } catch (error) {
            needsSubmit = true;
            message('Pengumpulan belum terkonfirmasi: ' + error.message + ' Tekan Coba Simpan Lagi.', true);
            el('cbtRetry').style.display = 'block';
        } finally {
            finishing = false;
            el('cbtSubmit').disabled = false;
            if (!attempt.submitted) renderQuestion();
        }
    }

    function tick() {
        if (!attempt || attempt.submitted || interruption) return;
        const seconds = Math.max(0, Math.ceil((target - Date.now()) / 1000));
        el('cbtTimer').textContent = String(Math.floor(seconds / 60)).padStart(2, '0') + ':' + String(seconds % 60).padStart(2, '0');
        if (seconds === 0) {
            clearInterval(ticker);
            finish(true);
        }
    }

    function setInterruptionNotice(reason) {
        const notices = {
            page_hidden: ['Anda meninggalkan halaman ujian.', 'Kembali ke halaman ujian, lalu lanjutkan.'],
            face_missing: ['Wajah tidak terdeteksi.', 'Hadapkan wajah lurus ke kamera dan pastikan seluruh wajah terlihat.'],
            multiple_faces: ['Lebih dari satu wajah terdeteksi.', 'Pastikan hanya peserta ujian di depan kamera.'],
            face_mismatch: ['Wajah belum sesuai pasfoto.', 'Hadapkan wajah lurus ke kamera dan pastikan seluruh wajah terlihat.'],
            camera_unavailable: ['Kamera tidak aktif.', 'Aktifkan kamera, lalu coba lagi.'],
            camera_denied: ['Izin kamera belum diberikan.', 'Izinkan akses kamera, lalu coba lagi.'],
            network: ['Koneksi ke server terganggu.', 'Periksa internet, lalu coba lagi.'],
            session: ['Sesi halaman perlu diperbarui.', 'Muat ulang halaman untuk melanjutkan.'],
            server: ['Server belum dapat memproses permintaan.', 'Coba lagi beberapa saat.'],
            verification: ['Pemeriksaan wajah belum tersedia.', 'Coba lagi beberapa saat.'],
            retry: ['Ujian belum dapat dilanjutkan.', 'Coba lagi atau hubungi pengawas.']
        };
        const notice = notices[reason] || notices.retry;
        el('cbtInterruptionReason').textContent = notice[0];
        el('cbtInterruptionAction').textContent = notice[1];
    }

    function showInterruptionError(error) {
        const codes = { NO_FACE: 'face_missing', MISMATCH: 'face_mismatch', MULTIPLE_FACES: 'multiple_faces',
            CAMERA_UNAVAILABLE: 'camera_unavailable', TIMEOUT: 'network', PAGE_HIDDEN: 'page_hidden',
            ERROR: 'verification', VERIFICATION_ERROR: 'verification', INVALID_RESPONSE: 'server' };
        let reason = codes[error.code] || 'retry';
        if (error.name === 'NotAllowedError' || error.name === 'SecurityError') reason = 'camera_denied';
        else if (['NotFoundError', 'NotReadableError', 'OverconstrainedError'].includes(error.name)) reason = 'camera_unavailable';
        else if (error.name === 'TypeError' || error.name === 'AbortError') reason = 'network';
        if (error.status === 401 || error.status === 403) reason = 'session';
        else if (error.status >= 500) reason = 'server';
        setInterruptionNotice(reason);
        el('cbtInterruptionStatus').textContent = interruption?.confirmed ? 'Waktu dijeda.' : 'Jeda belum terkonfirmasi.';
    }

    function showInterruption(reason) {
        const dialog = el('cbtInterruption');
        setInterruptionNotice(reason);
        el('cbtInterruptionStatus').textContent = interruption.confirmed
            ? 'Waktu dijeda.' : 'Mengonfirmasi jeda…';
        el('cbtWorkspace').inert = true;
        if (dialog.showModal && !dialog.open) dialog.showModal();
        dialog.style.display = 'flex';
        el('cbtResume').focus?.();
    }

    function hideInterruption() {
        interruption = null;
        const dialog = el('cbtInterruption');
        if (dialog.close && dialog.open) dialog.close();
        dialog.style.display = 'none';
        el('cbtWorkspace').inert = false;
    }

    function confirmInterruption() {
        if (interruptionRequest) return interruptionRequest;
        if (!interruption || interruption.confirmed) return Promise.resolve();
        const current = interruption;
        interruptionRequest = config.request(current.payload).then(data => {
            if (!data.success || !data.attempt) throw new Error(data.message || 'Jeda belum tersimpan.');
            if (data.attempt.submitted) { showResult(data.attempt); return; }
            attempt = data.attempt;
            pending.clear();
            position = Math.max(0, (attempt.resume_position || 1) - 1);
            target = Date.now() + attempt.remaining_seconds * 1000;
            current.confirmed = true;
            showInterruption(attempt.pause_reason);
            renderQuestion();
        }).catch(error => {
            showInterruptionError(error);
            throw error;
        }).finally(() => { interruptionRequest = null; });
        return interruptionRequest;
    }

    function interrupt(reason) {
        if (!attempt || attempt.submitted || finishing || interruption) return;
        if (Date.now() >= target) { tick(); return; }
        const eventId = window.crypto?.randomUUID?.() || ('event_' + Date.now() + '_' + Math.random().toString(36).slice(2));
        interruption = { confirmed: false, payload: {
            action: 'interrupt', event_id: eventId, reason, position: attempt.questions[position].position,
            revision: attempt.revision || 0, pending: [...pending.values()]
        } };
        showInterruption(reason);
        renderQuestion();
        confirmInterruption().catch(() => {});
    }

    async function resumeInterruption() {
        if (!interruption || resuming || document.hidden || (document.hasFocus && !document.hasFocus())) return;
        resuming = true;
        el('cbtResume').disabled = true;
        try {
            await confirmInterruption();
            if (!interruption) return;
            if (savePromise) await savePromise.catch(() => {});
            el('cbtInterruptionStatus').textContent = config.verifyAttention ? 'Memeriksa kesiapan ujian…' : 'Melanjutkan ujian…';
            if (config.verifyAttention) await config.verifyAttention();
            if (document.hidden || (document.hasFocus && !document.hasFocus())) {
                const error = new Error('Kembali ke halaman ujian terlebih dahulu.');
                error.code = 'PAGE_HIDDEN';
                throw error;
            }
            const data = await config.request({ action: 'resume' });
            if (!data.success) throw new Error(data.message || 'Ujian belum dapat dilanjutkan.');
            hideInterruption();
            apply(data);
            if (document.hidden) interrupt('page_hidden');
        } catch (error) {
            showInterruptionError(error);
        } finally {
            resuming = false;
            el('cbtResume').disabled = false;
        }
    }

    window.PretesCbt = {
        interrupt,
        isPaused: () => !!interruption,
        init(settings) {
            if (config || !el('pretesCbt')) return;
            config = settings;
            el('cbtResume').onclick = resumeInterruption;
            el('cbtInterruption').addEventListener('cancel', event => event.preventDefault());
            document.addEventListener('visibilitychange', () => { if (document.hidden) interrupt('page_hidden'); });
            window.addEventListener('pagehide', () => interrupt('page_hidden'));
            window.addEventListener('blur', () => interrupt('page_hidden'));
            if (window.TOKEN_REQUIRED && el('cbtTokenBox')) {
                el('cbtTokenBox').style.display = 'block';
            }
            el('cbtStart').onclick = async () => {
                let token = '';
                const tokenInput = el('cbtInputToken');
                if (tokenInput && (window.TOKEN_REQUIRED || tokenInput.value.trim())) {
                    token = tokenInput.value.trim();
                    if (window.TOKEN_REQUIRED && !token) {
                        message('Silakan masukkan token ujian terlebih dahulu.', true);
                        tokenInput.focus();
                        return;
                    }
                }
                el('cbtStart').disabled = true;
                message('Menyiapkan paket soal…');
                try {
                    apply(await config.request({ action: 'start', token: token }));
                } catch (error) {
                    message(error.message, true);
                    el('cbtStart').disabled = false;
                    if (el('cbtTokenBox')) {
                        el('cbtTokenBox').style.display = 'block';
                        if (tokenInput) tokenInput.focus();
                    }
                }
            };
            el('cbtPrevious').onclick = () => { if (!interruption && position > 0) { position--; renderQuestion(); } };
            el('cbtNext').onclick = () => { if (!interruption && position < attempt.questions.length - 1) { position++; renderQuestion(); } };
            el('cbtFlag').onchange = () => edit(attempt.questions[position].answer, el('cbtFlag').checked);
            el('cbtClear').onclick = () => { edit(null, attempt.questions[position].flagged); renderQuestion(); };
            el('cbtSubmit').onclick = () => finish(false);
            el('cbtRetry').onclick = () => {
                if (needsSubmit || Date.now() >= target) finish(Date.now() >= target);
                else flush().catch(() => {});
            };
            window.addEventListener('online', () => {
                if (interruption) confirmInterruption().catch(() => {});
                else if (attempt && !attempt.submitted) el('cbtRetry').onclick();
            });
            window.addEventListener('beforeunload', event => {
                if (pending.size) { event.preventDefault(); event.returnValue = ''; }
            });
            config.request({ action: 'state' }).then(apply).catch(error => {
                message(error.message, true);
                el('cbtStart').disabled = false;
            });
        }
    };
})(window);
