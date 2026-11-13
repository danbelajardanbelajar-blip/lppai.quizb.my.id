(function () {
    'use strict';
    const get = id => document.getElementById(id);

    // ── Global Modal Controller ──────────────────────────────────────────────
    window.openModal = function (id) {
        const modal = get(id);
        if (modal) {
            modal.classList.add('open');
            document.body.style.overflow = 'hidden';
            const firstInput = modal.querySelector('input:not([type=hidden]), textarea, select');
            if (firstInput) setTimeout(() => firstInput.focus(), 80);
        }
    };

    window.closeModal = function (id) {
        const modal = typeof id === 'string' ? get(id) : id;
        if (modal) {
            modal.classList.remove('open');
            if (!document.querySelector('.bank-modal-backdrop.open')) {
                document.body.style.overflow = '';
            }
        }
    };

    document.addEventListener('click', function (e) {
        const closeBtn = e.target.closest('[data-close-modal]');
        if (closeBtn) {
            const backdrop = closeBtn.closest('.bank-modal-backdrop');
            if (backdrop) window.closeModal(backdrop);
            return;
        }
        if (e.target.classList && e.target.classList.contains('bank-modal-backdrop')) {
            window.closeModal(e.target);
        }
    });

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') {
            const openBackdrop = document.querySelector('.bank-modal-backdrop.open');
            if (openBackdrop) window.closeModal(openBackdrop);
        }
    });

    // ── Package Modal Controller ─────────────────────────────────────────────
    window.openPackageModal = function (isEdit, data) {
        const titleEl = get('modalPackageTitle');
        const form = get('formPackageModal');
        if (!form) return;
        form.reset();

        if (isEdit && data) {
            if (titleEl) titleEl.textContent = 'Edit Paket Soal: ' + (data.name || '');
            get('pkgModalId').value = data.id || 0;
            get('pkgModalName').value = data.name || '';
            get('pkgModalCode').value = data.code || '';
            get('pkgModalCode').readOnly = true;
            get('pkgModalDuration').value = data.duration_minutes || 30;
            get('pkgModalCount').value = data.question_count || 25;
            get('pkgModalDesc').value = data.description || '';
            get('pkgModalActive').checked = Number(data.is_active) === 1;
        } else {
            if (titleEl) titleEl.textContent = 'Buat Paket Soal Baru';
            get('pkgModalId').value = 0;
            get('pkgModalName').value = '';
            get('pkgModalCode').value = '';
            get('pkgModalCode').readOnly = false;
            get('pkgModalDuration').value = 30;
            get('pkgModalCount').value = 25;
            get('pkgModalDesc').value = '';
            get('pkgModalActive').checked = true;
        }
        window.openModal('modalPackageForm');
    };

    // ── Quick Question Modal Controller ──────────────────────────────────────
    window.openQuickQuestionModal = function (packageId, packageName) {
        const form = get('formQuickQuestionModal');
        if (!form) return;
        form.reset();
        const select = get('quickQuestionPackageId');
        if (select && packageId) {
            select.value = String(packageId);
        }
        const subtitle = get('quickQuestionSubtitle');
        if (subtitle && packageName) {
            subtitle.textContent = 'Paket Soal: ' + packageName;
        }
        window.openModal('modalQuickQuestion');
    };

    // ── Session Modal Controller ─────────────────────────────────────────────
    window.generateRandomToken = function () {
        const chars = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';
        let res = '';
        for (let i = 0; i < 6; i++) {
            res += chars.charAt(Math.floor(Math.random() * chars.length));
        }
        const tokenInput = get('sesModalToken');
        if (tokenInput) {
            tokenInput.value = res;
            tokenInput.focus();
        }
    };

    function recalculateTotalQuota() {
        const inputs = document.querySelectorAll('.category-quota-input');
        let total = 0;
        inputs.forEach(input => {
            const val = parseInt(input.value, 10);
            if (!isNaN(val) && val > 0) total += val;
        });
        const badge = get('sesModalTotalQuotaBadge');
        if (badge) {
            badge.textContent = total > 0 ? (total + ' soal target') : 'Bebas / Acak Paket';
        }
    }

    window.refreshCategoryQuotasForSelectedPackages = function (existingQuotas) {
        const checkedBoxes = Array.from(document.querySelectorAll('input[name="package_ids[]"]:checked'));
        const pkgIds = checkedBoxes.map(cb => cb.value);
        const container = get('sesModalCategoryQuotasContainer');
        if (!container) return;

        if (pkgIds.length === 0) {
            container.innerHTML = '<div style="padding:12px;color:#8a998f;font-size:12px;text-align:center;">Pilih minimal 1 paket soal di atas untuk menampilkan daftar kategori materi.</div>';
            recalculateTotalQuota();
            return;
        }

        const map = window.CBT_PACKAGE_CATEGORIES || {};
        const combinedCategories = {};
        pkgIds.forEach(id => {
            const list = map[id] || [];
            list.forEach(item => {
                const cat = item.category;
                if (!combinedCategories[cat]) {
                    combinedCategories[cat] = { active_count: 0, total: 0 };
                }
                combinedCategories[cat].active_count += Number(item.active_count || 0);
                combinedCategories[cat].total += Number(item.total || 0);
            });
        });

        const catNames = Object.keys(combinedCategories).sort();
        if (catNames.length === 0) {
            container.innerHTML = '<div style="padding:12px;color:#8a998f;font-size:12px;text-align:center;">Belum ada butir soal dalam paket terpilih. Kuota kategori akan menggunakan acak umum.</div>';
            recalculateTotalQuota();
            return;
        }

        let html = '<table class="bank-quota-table"><thead><tr><th>Kategori Materi</th><th style="width:110px">Soal Aktif</th><th style="width:140px">Kadar / Kuota Soal</th></tr></thead><tbody>';
        catNames.forEach(cat => {
            const info = combinedCategories[cat];
            const currentVal = (existingQuotas && existingQuotas[cat] !== undefined) ? existingQuotas[cat] : '';
            html += '<tr>';
            html += '<td><strong>' + String(cat).replace(/</g, '&lt;') + '</strong></td>';
            html += '<td><span class="bank-badge">' + info.active_count + ' soal</span></td>';
            html += '<td><input type="number" class="bank-input category-quota-input" name="category_quotas[' + String(cat).replace(/"/g, '&quot;') + ']" min="0" max="' + info.active_count + '" placeholder="0" value="' + currentVal + '" style="padding:6px 10px;font-size:12px"></td>';
            html += '</tr>';
        });
        html += '</tbody></table>';
        container.innerHTML = html;

        container.querySelectorAll('.category-quota-input').forEach(inp => {
            inp.addEventListener('input', recalculateTotalQuota);
        });
        recalculateTotalQuota();
    };

    window.openSessionModal = function (isEdit, data) {
        const titleEl = get('modalSessionTitle');
        const form = get('formSessionModal');
        if (!form) return;
        form.reset();

        // Uncheck all package checkboxes first
        document.querySelectorAll('input[name="package_ids[]"]').forEach(cb => { cb.checked = false; });

        if (isEdit && data) {
            if (titleEl) titleEl.textContent = 'Edit Sesi Ujian: ' + (data.title || '');
            get('sesModalId').value = data.id || 0;
            get('sesModalCode').value = data.code || '';
            get('sesModalCode').readOnly = true;
            get('sesModalTitle').value = data.title || '';
            get('sesModalDesc').value = data.description || '';
            get('sesModalDuration').value = data.duration_minutes || 30;
            get('sesModalCameraMode').value = data.camera_mode || 'wajib';
            get('sesModalScoringMethod').value = data.scoring_method || 'standar';
            get('sesModalPassingGrade').value = data.passing_grade || '65.00';
            get('sesModalStartTime').value = data.start_time ? data.start_time.replace(' ', 'T') : '';
            get('sesModalEndTime').value = data.end_time ? data.end_time.replace(' ', 'T') : '';
            get('sesModalToken').value = data.token || '';
            get('sesModalTokenRequired').checked = Number(data.token_required) === 1;
            get('sesModalActive').checked = Number(data.is_active) === 1;

            const pkgIds = data.package_ids || [];
            pkgIds.forEach(id => {
                const cb = get('pkgCheckbox_' + id);
                if (cb) cb.checked = true;
            });
            window.refreshCategoryQuotasForSelectedPackages(data.category_quotas || {});
        } else {
            if (titleEl) titleEl.textContent = 'Buat Sesi Ujian Baru';
            get('sesModalId').value = 0;
            get('sesModalCode').value = '';
            get('sesModalCode').readOnly = false;
            get('sesModalTitle').value = '';
            get('sesModalDesc').value = '';
            get('sesModalDuration').value = 30;
            get('sesModalCameraMode').value = 'wajib';
            get('sesModalScoringMethod').value = 'standar';
            get('sesModalPassingGrade').value = '65.00';
            get('sesModalStartTime').value = '';
            get('sesModalEndTime').value = '';
            window.generateRandomToken();
            get('sesModalTokenRequired').checked = false;
            get('sesModalActive').checked = true;

            // Default check first package
            const firstCb = document.querySelector('input[name="package_ids[]"]');
            if (firstCb) firstCb.checked = true;
            window.refreshCategoryQuotasForSelectedPackages({});
        }

        window.openModal('modalSessionForm');
    };

    // Listen to package checkbox changes inside session modal
    document.addEventListener('change', function (e) {
        if (e.target && e.target.name === 'package_ids[]') {
            window.refreshCategoryQuotasForSelectedPackages();
        }
    });

    // ── Bank Soal Filter & Pagination ─────────────────────────────────────────
    if (get('bankSearch')) {
        const rows = Array.from(document.querySelectorAll('[data-bank-row]'));
        let page = 0;
        function filter(reset) {
            if (reset) page = 0;
            const query = get('bankSearch').value.trim().toLocaleLowerCase();
            const category = get('bankCategory') ? get('bankCategory').value : '';
            const status = get('bankStatus') ? get('bankStatus').value : '';
            const pkg = get('bankPackageFilter') ? get('bankPackageFilter').value : '';
            const matches = rows.filter(row => (!query || row.textContent.toLocaleLowerCase().includes(query)) && (!category || row.dataset.category === category) && (!status || row.dataset.status === status) && (!pkg || row.dataset.packageId === pkg));
            const size = Number(get('bankPageSize') ? get('bankPageSize').value : 10);
            const pages = Math.max(1, Math.ceil(matches.length / size));
            page = Math.min(page, pages - 1);
            rows.forEach(row => { row.hidden = true; });
            matches.slice(page * size, (page + 1) * size).forEach(row => { row.hidden = false; });
            if (get('bankEmpty')) get('bankEmpty').hidden = matches.length > 0;
            if (get('bankCount')) get('bankCount').textContent = matches.length ? 'Menampilkan ' + (page * size + 1) + '–' + Math.min((page + 1) * size, matches.length) + ' dari ' + matches.length + ' soal' : 'Tidak ada soal yang cocok';
            if (get('bankPage')) get('bankPage').textContent = (page + 1) + ' / ' + pages;
            if (get('bankPrevious')) get('bankPrevious').disabled = page === 0;
            if (get('bankNext')) get('bankNext').disabled = page + 1 >= pages;
        }
        get('bankSearch').addEventListener('input', () => filter(true));
        ['bankCategory', 'bankStatus', 'bankPackageFilter', 'bankPageSize'].forEach(id => {
            const el = get(id);
            if (el) el.addEventListener('change', () => filter(true));
        });
        if (get('bankPrevious')) get('bankPrevious').onclick = () => { page--; filter(false); };
        if (get('bankNext')) get('bankNext').onclick = () => { page++; filter(false); };
        filter(true);
    }

    // ── Excel Upload Handler ──────────────────────────────────────────────────
    if (get('bankFile')) {
        const input = get('bankFile'), zone = get('bankDropzone');
        function selected() {
            input.setCustomValidity('');
            const file = input.files[0];
            if (!file) return;
            if (!/\.xlsx$/i.test(file.name)) input.setCustomValidity('Gunakan file Excel .xlsx.');
            else if (file.size > 5 * 1024 * 1024) input.setCustomValidity('File maksimal 5 MB.');
            if (get('bankFileLabel')) get('bankFileLabel').textContent = file.name + ' · ' + (file.size / 1024).toFixed(1) + ' KB';
        }
        input.addEventListener('change', selected);
        if (zone) {
            zone.addEventListener('dragover', event => { event.preventDefault(); zone.classList.add('dragover'); });
            zone.addEventListener('dragleave', () => zone.classList.remove('dragover'));
            zone.addEventListener('drop', event => {
                event.preventDefault(); zone.classList.remove('dragover');
                if (event.dataTransfer.files.length !== 1) { input.setCustomValidity('Pilih satu file Excel.'); input.reportValidity(); return; }
                input.files = event.dataTransfer.files; selected();
            });
        }
        if (get('bankImportForm')) {
            get('bankImportForm').addEventListener('submit', () => {
                const btn = get('bankImportSubmit');
                if (btn) {
                    btn.disabled = true;
                    btn.textContent = 'Memeriksa file…';
                }
            });
        }
    }

    // ── Package Questions Manager Modal ──────────────────────────────────────
    window._currentPackageQuestions = [];
    window._currentPackageId = 0;
    window._currentPackageName = '';
    window._currentPackageCode = '';

    window.renderModalQuestions = function (query, catFilter, statusFilter) {
        const container = get('modalQListContainer');
        const countBadge = get('modalQCountBadge');
        if (!container) return;

        query = (query || '').toLowerCase().trim();
        catFilter = catFilter || '';
        statusFilter = statusFilter || '';

        const list = window._currentPackageQuestions || [];
        const filtered = list.filter(q => {
            const matchQ = !query ||
                (q.question_text || '').toLowerCase().includes(query) ||
                (q.code || '').toLowerCase().includes(query) ||
                (q.category || '').toLowerCase().includes(query);
            const matchCat = !catFilter || q.category === catFilter;
            const matchStat = !statusFilter || (statusFilter === 'aktif' ? Number(q.is_active) === 1 : Number(q.is_active) === 0);
            return matchQ && matchCat && matchStat;
        });

        if (countBadge) {
            countBadge.textContent = filtered.length + ' dari ' + list.length + ' soal';
        }

        if (filtered.length === 0) {
            container.innerHTML = '<div style="padding:40px 20px;text-align:center;color:#64748b;background:#f8fafc;border-radius:12px;border:1.5px dashed #cbd5e1;">' +
                '<div style="font-size:32px;margin-bottom:8px">📭</div>' +
                '<strong style="font-size:15px;color:#334155;display:block;margin-bottom:4px">Tidak Ada Butir Soal Ditemukan</strong>' +
                '<p style="font-size:13px;margin:0 0 14px 0">Belum ada butir soal atau tidak cocok dengan filter pencarian.</p>' +
                '<button type="button" class="bank-button primary" onclick="openQuestionFormModal(false, null, window._currentPackageId)">＋ Tambah Soal Pertama Sekarang</button>' +
                '</div>';
            return;
        }

        let html = '';
        filtered.forEach((q, idx) => {
            const isActive = Number(q.is_active) === 1;
            const options = q.options || {};
            const qJson = JSON.stringify(q).replace(/"/g, '&quot;');

            html += '<div class="bank-qcard" id="qcard_' + q.id + '">';
            html += '<div class="bank-qcard-head">';
            html += '<div class="bank-qcard-meta">';
            html += '<span class="bank-qcard-num">#' + (idx + 1) + '</span>';
            html += '<span class="bank-code">' + String(q.code || '').replace(/</g, '&lt;') + '</span>';
            html += '<span class="bank-badge package">' + String(q.category || 'Umum').replace(/</g, '&lt;') + '</span>';
            if (isActive) {
                html += '<span class="bank-badge active">● Aktif</span>';
            } else {
                html += '<span class="bank-badge inactive">Nonaktif</span>';
            }
            html += '</div>';
            html += '<div style="display:flex;gap:6px">';
            html += '<button type="button" class="bank-button" style="min-height:26px;padding:3px 8px;font-size:11px" onclick="openQuestionFormModal(true, ' + qJson + ', window._currentPackageId)">✏️ Edit</button>';
            html += '<button type="button" class="bank-button danger-sm" style="min-height:26px;padding:3px 8px;font-size:11px" onclick="deleteQuestion(' + q.id + ', window._currentPackageId)">🗑️ Hapus</button>';
            html += '</div>';
            html += '</div>';

            html += '<div class="bank-qcard-text">' + String(q.question_text || '').replace(/</g, '&lt;').replace(/>/g, '&gt;') + '</div>';

            html += '<div class="bank-qcard-options">';
            ['A', 'B', 'C', 'D', 'E'].forEach(letter => {
                const optText = options[letter] || q['option_' + letter] || '—';
                const isCorrect = q.correct_answer === letter;
                html += '<div class="bank-qcard-opt' + (isCorrect ? ' is-key' : '') + '">';
                html += '<span class="bank-qcard-opt-letter">' + letter + '</span>';
                html += '<span style="flex:1">' + String(optText).replace(/</g, '&lt;') + '</span>';
                if (isCorrect) {
                    html += '<span style="font-size:11px;font-weight:700;color:#059669;margin-left:auto">✓ Kunci</span>';
                }
                html += '</div>';
            });
            html += '</div>';
            html += '</div>';
        });

        container.innerHTML = html;
    };

    window.openPackageQuestionsModal = function (packageId, packageName, packageCode) {
        window._currentPackageId = packageId;
        window._currentPackageName = packageName || 'Semua Bank Soal';
        window._currentPackageCode = packageCode || 'SEMUA';

        const titleEl = get('pkgQuestionsTitle');
        const codeBadge = get('pkgQuestionsCodeBadge');
        const countBadge = get('pkgQuestionsCountBadge');
        const container = get('modalQListContainer');

        if (titleEl) titleEl.textContent = window._currentPackageName;
        if (codeBadge) codeBadge.textContent = window._currentPackageCode;
        if (countBadge) countBadge.textContent = 'Memuat soal…';

        const btnAdd = get('btnModalAddQuestion');
        if (btnAdd) {
            btnAdd.onclick = () => window.openQuestionFormModal(false, null, packageId);
        }

        const btnImport = get('btnModalImportExcel');
        if (btnImport) {
            btnImport.href = (window.location.pathname || '') + '?cbt_bank=1&tab=import' + (packageId > 0 ? ('&target_pkg=' + packageId) : '');
        }

        if (container) {
            container.innerHTML = '<div style="padding:40px;text-align:center;color:#64748b;"><div style="font-size:24px;margin-bottom:8px">⏳</div>Memuat butir pertanyaan…</div>';
        }

        window.openModal('modalPackageQuestions');

        // Fetch questions from AJAX endpoint
        const fetchUrl = (window.location.pathname || '') + '?cbt_bank=1&ajax=package_questions&package_id=' + (packageId || 0);
        fetch(fetchUrl)
            .then(res => res.json())
            .then(data => {
                if (data && data.success) {
                    window._currentPackageQuestions = data.questions || [];
                    if (countBadge) {
                        countBadge.textContent = window._currentPackageQuestions.length + ' butir soal';
                    }

                    // Populate categories dropdown in modal filter
                    const catSelect = get('modalQCategory');
                    if (catSelect) {
                        const cats = Array.from(new Set(window._currentPackageQuestions.map(q => q.category).filter(Boolean))).sort();
                        let catHtml = '<option value="">Semua Kategori</option>';
                        cats.forEach(c => {
                            catHtml += '<option value="' + String(c).replace(/"/g, '&quot;') + '">' + String(c).replace(/</g, '&lt;') + '</option>';
                        });
                        catSelect.innerHTML = catHtml;
                    }

                    window.renderModalQuestions('', '', '');
                } else {
                    if (container) container.innerHTML = '<div style="padding:30px;color:#b91c1c;text-align:center;">Gagal memuat soal: ' + (data.message || 'Terjadi kesalahan.') + '</div>';
                }
            })
            .catch(err => {
                if (container) container.innerHTML = '<div style="padding:30px;color:#b91c1c;text-align:center;">Gagal memuat soal: ' + err.message + '</div>';
            });
    };

    // Listen to search and filter events in Question Manager Modal
    document.addEventListener('input', function (e) {
        if (e.target && e.target.id === 'modalQSearch') {
            const cat = get('modalQCategory') ? get('modalQCategory').value : '';
            const stat = get('modalQStatus') ? get('modalQStatus').value : '';
            window.renderModalQuestions(e.target.value, cat, stat);
        }
    });
    document.addEventListener('change', function (e) {
        if (e.target && (e.target.id === 'modalQCategory' || e.target.id === 'modalQStatus')) {
            const query = get('modalQSearch') ? get('modalQSearch').value : '';
            const cat = get('modalQCategory') ? get('modalQCategory').value : '';
            const stat = get('modalQStatus') ? get('modalQStatus').value : '';
            window.renderModalQuestions(query, cat, stat);
        }
    });

    // ── Question Form Modal (Add / Edit) ──────────────────────────────────────
    window.openQuestionFormModal = function (isEdit, data, defaultPackageId) {
        const titleEl = get('modalQuestionFormTitle');
        const subtitleEl = get('modalQuestionFormSubtitle');
        const form = get('formQuestionModal');
        if (!form) return;
        form.reset();

        const pkgSelect = get('qFormPackageId');
        if (pkgSelect) {
            const targetPkg = isEdit ? (data.package_id || defaultPackageId || 1) : (defaultPackageId || 1);
            pkgSelect.value = String(targetPkg);
        }

        if (isEdit && data) {
            if (titleEl) titleEl.textContent = '✏️ Edit Butir Soal #' + (data.id || '');
            if (subtitleEl) subtitleEl.textContent = 'Kode: ' + (data.code || '-') + ' · Mengubah butir soal pada paket';
            get('qFormId').value = data.id || 0;
            get('qFormCategory').value = data.category || 'Umum';
            get('qFormText').value = data.question_text || '';
            get('qFormActive').checked = Number(data.is_active) === 1;

            const options = data.options || {};
            const key = data.correct_answer || 'A';
            ['A', 'B', 'C', 'D', 'E'].forEach(letter => {
                const optInput = get('qFormOpt_' + letter);
                if (optInput) optInput.value = options[letter] || data['option_' + letter] || '';
                const radio = get('qFormRadio_' + letter);
                if (radio) radio.checked = (key === letter);
                const row = get('qFormRow_' + letter);
                if (row) {
                    if (key === letter) row.classList.add('selected-key');
                    else row.classList.remove('selected-key');
                }
            });
        } else {
            if (titleEl) titleEl.textContent = '＋ Tambah Butir Soal Baru';
            if (subtitleEl) subtitleEl.textContent = 'Masukkan pertanyaan dan 5 pilihan jawaban A–E';
            get('qFormId').value = 0;
            get('qFormCategory').value = 'Umum';
            get('qFormText').value = '';
            get('qFormActive').checked = true;

            ['A', 'B', 'C', 'D', 'E'].forEach(letter => {
                const optInput = get('qFormOpt_' + letter);
                if (optInput) optInput.value = '';
                const radio = get('qFormRadio_' + letter);
                if (radio) radio.checked = (letter === 'A');
                const row = get('qFormRow_' + letter);
                if (row) {
                    if (letter === 'A') row.classList.add('selected-key');
                    else row.classList.remove('selected-key');
                }
            });
        }

        window.openModal('modalQuestionForm');
    };

    // Radio button changes to highlight correct answer in Question Form Modal
    document.addEventListener('change', function (e) {
        if (e.target && e.target.name === 'correct_answer') {
            ['A', 'B', 'C', 'D', 'E'].forEach(letter => {
                const row = get('qFormRow_' + letter);
                if (row) {
                    if (e.target.value === letter) row.classList.add('selected-key');
                    else row.classList.remove('selected-key');
                }
            });
        }
    });

    // ── Delete Question Handler ──────────────────────────────────────────────
    window.deleteQuestion = function (questionId, packageId) {
        if (!confirm('PERINGATAN: Yakin ingin menghapus butir soal ini secara permanen?')) {
            return;
        }

        const formData = new URLSearchParams();
        formData.append('action', 'delete_question');
        formData.append('csrf_token', (document.querySelector('input[name=csrf_token]') || {}).value || '');
        formData.append('question_id', String(questionId));

        const card = get('qcard_' + questionId);
        if (card) {
            card.style.opacity = '0.5';
            card.style.pointerEvents = 'none';
        }

        fetch((window.location.pathname || '') + '?cbt_bank=1', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: formData.toString()
        })
        .then(res => res.json())
        .then(data => {
            if (data && data.success) {
                // Remove from local list
                window._currentPackageQuestions = (window._currentPackageQuestions || []).filter(q => Number(q.id) !== Number(questionId));
                if (card) {
                    card.style.transition = 'all 0.3s ease';
                    card.style.transform = 'scale(0.9)';
                    card.style.opacity = '0';
                    setTimeout(() => {
                        if (card.parentNode) card.parentNode.removeChild(card);
                        const countBadge = get('modalQCountBadge');
                        if (countBadge) {
                            countBadge.textContent = window._currentPackageQuestions.length + ' butir soal';
                        }
                    }, 300);
                }
            } else {
                alert('Gagal menghapus soal: ' + (data.message || 'Terjadi kesalahan.'));
                if (card) {
                    card.style.opacity = '1';
                    card.style.pointerEvents = '';
                }
            }
        })
        .catch(err => {
            alert('Gagal menghapus soal: ' + err.message);
            if (card) {
                card.style.opacity = '1';
                card.style.pointerEvents = '';
            }
        });
    };

})();