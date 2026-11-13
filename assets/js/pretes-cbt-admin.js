(function () {
    'use strict';
    const get = id => document.getElementById(id);
    if (get('bankSearch')) {
        const rows = Array.from(document.querySelectorAll('[data-bank-row]'));
        let page = 0;
        function filter(reset) {
            if (reset) page = 0;
            const query = get('bankSearch').value.trim().toLocaleLowerCase();
            const category = get('bankCategory').value, status = get('bankStatus').value;
            const matches = rows.filter(row => (!query || row.textContent.toLocaleLowerCase().includes(query)) && (!category || row.dataset.category === category) && (!status || row.dataset.status === status));
            const size = Number(get('bankPageSize').value);
            const pages = Math.max(1, Math.ceil(matches.length / size));
            page = Math.min(page, pages - 1);
            rows.forEach(row => { row.hidden = true; });
            matches.slice(page * size, (page + 1) * size).forEach(row => { row.hidden = false; });
            get('bankEmpty').hidden = matches.length > 0;
            get('bankCount').textContent = matches.length ? 'Menampilkan ' + (page * size + 1) + '–' + Math.min((page + 1) * size, matches.length) + ' dari ' + matches.length + ' soal' : 'Tidak ada soal yang cocok';
            get('bankPage').textContent = (page + 1) + ' / ' + pages;
            get('bankPrevious').disabled = page === 0;
            get('bankNext').disabled = page + 1 >= pages;
        }
        get('bankSearch').addEventListener('input', () => filter(true));
        ['bankCategory', 'bankStatus', 'bankPageSize'].forEach(id => get(id).addEventListener('change', () => filter(true)));
        get('bankPrevious').onclick = () => { page--; filter(false); };
        get('bankNext').onclick = () => { page++; filter(false); };
        filter(true);
    }
    if (get('bankFile')) {
        const input = get('bankFile'), zone = get('bankDropzone');
        function selected() {
            input.setCustomValidity('');
            const file = input.files[0];
            if (!file) return;
            if (!/\.xlsx$/i.test(file.name)) input.setCustomValidity('Gunakan file Excel .xlsx.');
            else if (file.size > 5 * 1024 * 1024) input.setCustomValidity('File maksimal 5 MB.');
            get('bankFileLabel').textContent = file.name + ' · ' + (file.size / 1024).toFixed(1) + ' KB';
        }
        input.addEventListener('change', selected);
        zone.addEventListener('dragover', event => { event.preventDefault(); zone.classList.add('dragover'); });
        zone.addEventListener('dragleave', () => zone.classList.remove('dragover'));
        zone.addEventListener('drop', event => {
            event.preventDefault(); zone.classList.remove('dragover');
            if (event.dataTransfer.files.length !== 1) { input.setCustomValidity('Pilih satu file Excel.'); input.reportValidity(); return; }
            input.files = event.dataTransfer.files; selected();
        });
        get('bankImportForm').addEventListener('submit', () => { get('bankImportSubmit').disabled = true; get('bankImportSubmit').textContent = 'Memeriksa file…'; });
    }
})();
