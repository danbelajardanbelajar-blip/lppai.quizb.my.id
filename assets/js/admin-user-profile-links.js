(function () {
    'use strict';
    const script = document.currentScript;
    if (!script || window.adminUserProfileLinksInitialized) return;
    window.adminUserProfileLinksInitialized = true;
    const root = document.querySelector('.content-area');
    if (!root) return;
    const lookup = script.dataset.profileLookup;
    const profile = script.dataset.profilePage;
    const csrf = script.dataset.profileCsrf;
    const cache = new Map();
    let timer, loading = false;
    const normalize = value => value.trim().replace(/\s+/gu, ' ').toLocaleLowerCase('id-ID');
    const excluded = 'a,button,input,textarea,select,option,label,script,style,noscript,svg,nav,thead,dialog,[contenteditable="true"],[data-no-user-profile-links],.badge,.bank-badge,.stat-info';
    const style = document.createElement('style');
    style.textContent = 'a.admin-user-profile-link{color:inherit;text-decoration:none;cursor:pointer}a.admin-user-profile-link:hover,a.admin-user-profile-link:focus-visible{text-decoration:underline;text-underline-offset:3px}';
    document.head.appendChild(style);
    function schedule() { clearTimeout(timer); timer = setTimeout(scan, 150); }
    async function scan() {
        if (loading) return;
        const candidates = new Map();
        const walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT);
        let node;
        const nodes = [];
        while ((node = walker.nextNode())) nodes.push(node);
        for (const node of nodes) {
            if (!node.parentElement || node.parentElement.closest(excluded)) continue;
            const name = node.nodeValue.trim();
            if (!name || name.length > 200 || !/\p{L}/u.test(name)) continue;
            const key = normalize(name);
            if (cache.has(key)) {
                const match = cache.get(key);
                if (!match) continue;
                const link = document.createElement('a');
                const url = new URL(profile, location.href);
                if (match.id) url.searchParams.set('id', String(match.id));
                else url.searchParams.set('nama', match.name);
                link.href = url.href;
                link.className = 'admin-user-profile-link';
                link.setAttribute('data-no-spa', '');
                link.title = match.id ? 'Lihat profil pengguna' : 'Pilih akun pengguna yang dimaksud';
                link.textContent = node.nodeValue;
                node.replaceWith(link);
            } else if (!candidates.has(key)) candidates.set(key, name);
        }
        if (!candidates.size) return;
        const names = [...candidates.values()].slice(0, 100);
        loading = true;
        try {
            const response = await fetch(lookup, { method:'POST', credentials:'same-origin',
                headers:{'Content-Type':'application/json'}, body:JSON.stringify({csrf_token:csrf,names}) });
            if (!response.ok) return;
            const data = await response.json();
            if (!Array.isArray(data.links)) return;
            names.forEach(name => cache.set(normalize(name), null));
            data.links.forEach(match => {
                if (typeof match.name === 'string' && (match.id === null || (Number.isInteger(match.id) && match.id > 0))) {
                    cache.set(normalize(match.name), match);
                }
            });
            schedule();
        } catch (error) {
            console.warn('Tautan profil pengguna belum dapat dimuat.');
        } finally { loading = false; }
    }
    new MutationObserver(schedule).observe(root, {childList:true,subtree:true,characterData:true});
    schedule();
})();
