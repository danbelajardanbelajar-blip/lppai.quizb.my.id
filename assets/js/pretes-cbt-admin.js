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
})();
