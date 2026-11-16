(function () {
    'use strict';
    const input = document.getElementById('sidebar-menu-search');
    if (!input || input.dataset.searchReady) return;
    input.dataset.searchReady = 'true';
    const sidebar = input.closest('.sidebar');
    const menu = sidebar.querySelector('.sidebar-menu');
    const clear = document.getElementById('sidebar-search-clear');
    const status = document.getElementById('sidebar-search-status');
    const links = [...menu.querySelectorAll('a[href]')].filter(link => link.getAttribute('href') !== '#');
    const groups = [...menu.querySelectorAll('.nav-group, .has-submenu')];
    const normalize = value => value.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLocaleLowerCase('id').replace(/\s+/g, ' ').trim();
    const title = element => {
        const copy = element.cloneNode(true);
        copy.querySelectorAll('.icon, .chevron, .arrow, .nav-badge').forEach(node => node.remove());
        return copy.textContent;
    };
    let expanded = [];

    function filter() {
        const query = normalize(input.value);
        const searching = query !== '';
        if (searching && !sidebar.classList.contains('sidebar-searching')) {
            expanded = [...menu.querySelectorAll('.nav-group-toggle, .submenu-toggle')].map(node => [node, node.getAttribute('aria-expanded')]);
        }
        sidebar.classList.toggle('sidebar-searching', searching);
        clear.hidden = !searching;
        status.hidden = !searching;
        menu.querySelectorAll('.sidebar-search-hidden').forEach(node => node.classList.remove('sidebar-search-hidden'));
        if (!searching) {
            expanded.forEach(([node, value]) => value === null ? node.removeAttribute('aria-expanded') : node.setAttribute('aria-expanded', value));
            expanded = [];
            status.textContent = '';
            return;
        }
        const words = query.split(' ');
        let count = 0;
        links.forEach(link => {
            let text = title(link);
            const group = link.closest('.nav-group');
            const parent = link.closest('.has-submenu');
            if (group) text += ' ' + title(group.querySelector('.nav-group-toggle'));
            if (parent) text += ' ' + title(parent.querySelector('.submenu-toggle'));
            const match = words.every(word => normalize(text).includes(word));
            link.classList.toggle('sidebar-search-hidden', !match);
            if (match) count++;
        });
        groups.forEach(group => {
            const matches = links.some(link => group.contains(link) && !link.classList.contains('sidebar-search-hidden'));
            group.classList.toggle('sidebar-search-hidden', !matches);
            const toggle = group.querySelector('.nav-group-toggle, .submenu-toggle');
            if (toggle) toggle.setAttribute('aria-expanded', matches ? 'true' : 'false');
        });
        menu.querySelectorAll('.menu-label').forEach(label => label.classList.add('sidebar-search-hidden'));
        status.textContent = count ? count + ' menu ditemukan' : 'Menu tidak ditemukan. Coba kata lain.';
    }
    input.addEventListener('input', filter);
    input.addEventListener('search', filter);
    function reset() { input.value = ''; filter(); }
    clear.addEventListener('click', () => { reset(); input.focus(); });
    input.addEventListener('keydown', event => {
        if (event.key === 'Escape') { event.preventDefault(); reset(); }
        if (event.key === 'Enter' && normalize(input.value)) {
            event.preventDefault();
            const first = links.find(link => !link.classList.contains('sidebar-search-hidden'));
            if (first) first.focus();
        }
    });
    // Clear before SPA routing updates the active menu; keep regular link behavior.
    menu.addEventListener('click', event => {
        const link = event.target.closest('a[href]');
        if (link && link.getAttribute('href') !== '#') reset();
    }, true);
})();
