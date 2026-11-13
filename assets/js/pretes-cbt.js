(function (window) {
    'use strict';
    let config, attempt = null, position = 0, target = 0, ticker = null, savePromise = null, finishing = false, needsSubmit = false, monitoringStarted = false;
    const pending = new Map();
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
            button.onclick = () => { position = index; renderQuestion(); };
            nav.appendChild(button);
        });
        el('cbtProgress').textContent = answered() + '/' + attempt.total + ' dijawab · ' + pending.size + ' perubahan belum tersimpan';
        progress(answered(), attempt.total);
    }

    function edit(answer, flagged) {
        if (!attempt || attempt.submitted || finishing || Date.now() >= target) return;
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
            radio.disabled = Date.now() >= target || finishing;
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
        el('cbtFlag').disabled = Date.now() >= target || finishing;
        el('cbtPrevious').disabled = position === 0;
        el('cbtNext').disabled = position === attempt.questions.length - 1;
        navigation();
    }

    function showResult(result) {
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
        el('cbtIntro').style.display = 'none';
        el('cbtWorkspace').style.display = 'grid';
        message('Paket soal tersimpan. Pilih jawaban; tunggu status tersimpan sebelum menutup halaman.');
        renderQuestion();
        if (ticker) clearInterval(ticker);
        ticker = setInterval(tick, 1000);
        if (!monitoringStarted && config.onStarted) { monitoringStarted = true; config.onStarted(); }
        tick();
    }

    function flush() {
        if (savePromise) return savePromise;
        savePromise = (async () => {
            while (pending.size && !attempt.submitted) {
                const [key, value] = pending.entries().next().value;
                message('Menyimpan jawaban…');
                const data = await config.request(Object.assign({ action: 'save' }, value));
                if (data.attempt.submitted) { showResult(data.attempt); return; }
                if (pending.get(key) === value) pending.delete(key);
                target = Math.min(target, Date.now() + data.attempt.remaining_seconds * 1000);
                navigation();
            }
            el('cbtRetry').style.display = 'none';
            message('Semua perubahan jawaban tersimpan di server.');
        })().catch(error => {
            message('Belum tersimpan: ' + error.message + ' Jangan tutup halaman. Tekan Coba Simpan Lagi.', true);
            el('cbtRetry').style.display = 'block';
            throw error;
        }).finally(() => { savePromise = null; });
        return savePromise;
    }

    async function finish(expired) {
        if (finishing || !attempt || attempt.submitted) return;
        if (!expired && !needsSubmit && !window.confirm('Kumpulkan ujian? ' + (attempt.total - answered()) + ' soal belum dijawab. Jawaban tidak dapat diubah setelah dikumpulkan.')) return;
        finishing = true;
        el('cbtSubmit').disabled = true;
        renderQuestion();
        try {
            try { await flush(); } catch (error) { if (!expired) throw error; }
            if (attempt.submitted) return;
            message(expired ? 'Waktu habis. Mengumpulkan jawaban yang tersimpan…' : 'Mengumpulkan ujian…');
            apply(await config.request({ action: 'submit' }));
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
        if (!attempt || attempt.submitted) return;
        const seconds = Math.max(0, Math.ceil((target - Date.now()) / 1000));
        el('cbtTimer').textContent = String(Math.floor(seconds / 60)).padStart(2, '0') + ':' + String(seconds % 60).padStart(2, '0');
        if (seconds === 0) {
            clearInterval(ticker);
            finish(true);
        }
    }

    window.PretesCbt = {
        init(settings) {
            if (config || !el('pretesCbt')) return;
            config = settings;
            el('cbtStart').onclick = async () => {
                el('cbtStart').disabled = true;
                message('Menyiapkan paket soal…');
                try { apply(await config.request({ action: 'start' })); }
                catch (error) { message(error.message, true); el('cbtStart').disabled = false; }
            };
            el('cbtPrevious').onclick = () => { if (position > 0) { position--; renderQuestion(); } };
            el('cbtNext').onclick = () => { if (position < attempt.questions.length - 1) { position++; renderQuestion(); } };
            el('cbtFlag').onchange = () => edit(attempt.questions[position].answer, el('cbtFlag').checked);
            el('cbtClear').onclick = () => { edit(null, attempt.questions[position].flagged); renderQuestion(); };
            el('cbtSubmit').onclick = () => finish(false);
            el('cbtRetry').onclick = () => {
                if (needsSubmit || Date.now() >= target) finish(Date.now() >= target);
                else flush().catch(() => {});
            };
            window.addEventListener('online', () => { if (attempt && !attempt.submitted) el('cbtRetry').onclick(); });
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
