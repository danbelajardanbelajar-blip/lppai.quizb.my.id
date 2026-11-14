(function () {
    'use strict';
    const get = id => document.getElementById(id);

    // Use the browser's form ownership: row checkboxes live outside the bulk
    // form because each row has its own action forms. Never collect modal inputs.
    function bulkItems(kind) {
        const form = document.querySelector('[data-bulk-delete-form="' + kind + '"]');
        return form ? Array.from(form.elements).filter(item => item.matches('[data-bulk-select-item="' + kind + '"]') && !item.disabled) : [];
    }
    function refreshBulkSelection(kind) {
        const items = bulkItems(kind);
        const selected = items.filter(item => item.checked);
        const toolbar = document.querySelector('[data-bulk-toolbar="' + kind + '"]');
        const count = document.querySelector('[data-bulk-count="' + kind + '"]');
        const selectAll = document.querySelector('[data-bulk-select-all="' + kind + '"]');
        if (toolbar) { toolbar.hidden = selected.length === 0; toolbar.style.display = selected.length ? 'flex' : 'none'; }
        if (count) count.textContent = selected.length + (kind === 'packages' ? ' paket dipilih' : ' sesi dipilih');
        if (selectAll) {
            selectAll.checked = items.length > 0 && selected.length === items.length;
            selectAll.indeterminate = selected.length > 0 && selected.length < items.length;
            selectAll.disabled = items.length === 0;
        }
    }
    function refreshBulkTables() { ['packages', 'sessions'].forEach(refreshBulkSelection); }
    // SPA visits may evaluate this script again. Bind delegated bulk listeners once.
    if (!window.__pretesCbtBulkBound) {
        window.__pretesCbtBulkBound = true;
        document.addEventListener('change', function (event) {
            const selectAll = event.target.closest('[data-bulk-select-all]');
            if (selectAll) {
                const kind = selectAll.dataset.bulkSelectAll;
                bulkItems(kind).forEach(item => { item.checked = selectAll.checked; });
                refreshBulkSelection(kind);
                return;
            }
            const item = event.target.closest('[data-bulk-select-item]');
            if (item) refreshBulkSelection(item.dataset.bulkSelectItem);
        });
        // Capture runs before app.js's submit handler: cancel must leave buttons usable.
        document.addEventListener('submit', function (event) {
            const form = event.target.closest('[data-bulk-delete-form]');
            if (!form || event.defaultPrevented) return;
            const kind = form.dataset.bulkDeleteForm;
            const selected = bulkItems(kind).filter(item => item.checked);
            if (!selected.length) {
                event.preventDefault();
                alert(kind === 'packages' ? 'Pilih paket yang ingin dihapus terlebih dahulu.' : 'Pilih sesi yang ingin dihapus terlebih dahulu.');
                return;
            }
            const labels = selected.map(item => item.dataset.itemLabel || '').filter(Boolean);
            const preview = labels.slice(0, 5).join(', ') + (labels.length > 5 ? ', dan ' + (labels.length - 5) + ' lainnya' : '');
            const message = kind === 'packages'
                ? 'Hapus ' + selected.length + ' paket berikut beserta seluruh soal di dalamnya?\n\n' + preview + '\n\nTindakan ini tidak dapat dibatalkan.'
                : 'Hapus ' + selected.length + ' sesi ujian berikut?\n\n' + preview + '\n\nRiwayat pengerjaan peserta tetap tersimpan. Tindakan ini tidak dapat dibatalkan.';
            if (!window.confirm(message)) event.preventDefault();
            // Confirmed submissions use native POST/redirect/GET (data-no-spa).
        }, true);
        window.addEventListener('pageshow', refreshBulkTables);
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', refreshBulkTables, { once: true });
    else refreshBulkTables();

    // ── Global Modal Controller ──────────────────────────────────────────────
    function sanitizeBackdrops() {
        document.querySelectorAll('.bank-modal-backdrop').forEach(el => {
            // .content-area memakai transform (animasi masuk halaman) sehingga position:fixed
            // di dalamnya mengacu ke content-area, bukan viewport: backdrop setinggi konten,
            // modal tergeser ke bawah layar dan tidak bisa di-scroll. Pindahkan ke <body>.
            if (el.parentElement !== document.body) document.body.appendChild(el);
            if (!el.classList.contains('open')) {
                el.style.display = 'none';
                el.style.pointerEvents = 'none';
                el.style.visibility = 'hidden';
            }
        });
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', sanitizeBackdrops);
    } else {
        sanitizeBackdrops();
    }

    // CATATAN: app.js (dimuat di footer SETELAH file ini) mendeklarasikan fungsi global
    // openModal()/closeModal() untuk modal .show lain, sehingga window.openModal/closeModal
    // milik CBT tertimpa dan modal tidak pernah terbuka. Karena itu CBT memakai nama unik
    // cbtOpenModal/cbtCloseModal dan tidak lagi bergantung pada window.openModal/closeModal.
    function cbtOpenModal(id) {
        const modal = typeof id === 'string' ? get(id) : id;
        if (modal) {
            modal.style.display = 'flex';
            modal.style.pointerEvents = 'auto';
            modal.style.visibility = 'visible';
            modal.classList.add('open');
            document.body.style.overflow = 'hidden';
            const firstInput = modal.querySelector('input:not([type=hidden]), textarea, select');
            if (firstInput) setTimeout(() => firstInput.focus(), 80);
        }
    }

    function cbtCloseModal(id) {
        const modal = typeof id === 'string' ? get(id) : id;
        if (modal) {
            modal.classList.remove('open');
            modal.style.display = 'none';
            modal.style.pointerEvents = 'none';
            modal.style.visibility = 'hidden';
            if (!document.querySelector('.bank-modal-backdrop.open')) {
                document.body.style.overflow = '';
            }
        }
    }
    window.cbtOpenModal = cbtOpenModal;
    window.cbtCloseModal = cbtCloseModal;

    document.addEventListener('click', function (e) {
        const closeBtn = e.target.closest('[data-close-modal]');
        if (closeBtn) {
            const backdrop = closeBtn.closest('.bank-modal-backdrop');
            if (backdrop) cbtCloseModal(backdrop);
            return;
        }
        if (e.target.classList && e.target.classList.contains('bank-modal-backdrop')) {
            cbtCloseModal(e.target);
            return;
        }

        // Action event delegation for resilient button handling
        const btnCreatePkg = e.target.closest('[data-action="create-package"]');
        if (btnCreatePkg) {
            e.preventDefault();
            window.openPackageModal(false);
            return;
        }

        const btnCreateSes = e.target.closest('[data-action="create-session"]');
        if (btnCreateSes) {
            e.preventDefault();
            window.openSessionModal(false);
            return;
        }

        const btnAddQ = e.target.closest('[data-action="add-question"]');
        if (btnAddQ) {
            e.preventDefault();
            const pkgId = parseInt(btnAddQ.getAttribute('data-package-id') || '0', 10);
            window.openQuestionFormModal(false, null, pkgId > 0 ? pkgId : null);
            return;
        }

        const btnManageQ = e.target.closest('[data-action="manage-questions"]');
        if (btnManageQ) {
            e.preventDefault();
            const pkgId = parseInt(btnManageQ.getAttribute('data-package-id') || '0', 10);
            const pkgName = btnManageQ.getAttribute('data-package-name') || '';
            const pkgCode = btnManageQ.getAttribute('data-package-code') || '';
            window.openPackageQuestionsModal(pkgId, pkgName, pkgCode);
            return;
        }
    });

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') {
            const openBackdrop = document.querySelector('.bank-modal-backdrop.open');
            if (openBackdrop) cbtCloseModal(openBackdrop);
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
        cbtOpenModal('modalPackageForm');
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
        cbtOpenModal('modalQuickQuestion');
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
            if (get('sesModalShuffleOnViolation')) {
                get('sesModalShuffleOnViolation').value = data.shuffle_on_violation !== undefined ? String(data.shuffle_on_violation) : '1';
            }
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
            if (get('sesModalShuffleOnViolation')) {
                get('sesModalShuffleOnViolation').value = '1';
            }
            get('sesModalActive').checked = true;

            // Default check first package
            const firstCb = document.querySelector('input[name="package_ids[]"]');
            if (firstCb) firstCb.checked = true;
            window.refreshCategoryQuotasForSelectedPackages({});
        }

        cbtOpenModal('modalSessionForm');
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

        cbtOpenModal('modalPackageQuestions');

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

        cbtOpenModal('modalQuestionForm');
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
