/**
 * LPPAI Corner - Activity Tracker (Click Activity Logger)
 * Merekam aktivitas interaksi klik oleh pengguna secara real-time & non-blocking
 */
(function() {
    'use strict';

    var lastTarget = null;
    var lastClickTime = 0;

    function getBaseUrl() {
        if (window.BASE_URL) return window.BASE_URL;
        var meta = document.querySelector('meta[name="base-url"]');
        if (meta && meta.content) return meta.content;
        return window.location.origin || '';
    }

    function extractElementText(el) {
        if (!el) return '';
        
        // Input submit / button
        if (el.tagName === 'INPUT') {
            return (el.value || el.placeholder || el.name || '').trim();
        }

        // Cek aria-label atau title terlebih dahulu jika elemen berupa icon saja
        var ariaLabel = el.getAttribute('aria-label') || '';
        var titleAttr = el.getAttribute('title') || '';

        // Ambil text content
        var text = (el.innerText || el.textContent || '').trim();

        if (!text && ariaLabel) return ariaLabel.trim();
        if (!text && titleAttr) return titleAttr.trim();

        // Bersihkan whitespace berlebih & batasi panjang karakter
        text = text.replace(/\s+/g, ' ');
        if (text.length > 150) {
            text = text.substring(0, 147) + '...';
        }
        return text;
    }

    function determineElementType(el) {
        if (!el) return 'click';
        if (el.closest('.sidebar-menu')) return 'menu';
        if (el.closest('.stat-card')) return 'card';
        if (el.classList.contains('tab') || el.hasAttribute('data-tab')) return 'tab';

        var tag = el.tagName.toLowerCase();
        if (tag === 'a') return 'link';
        if (tag === 'button') return 'button';
        if (tag === 'input') return 'input';
        if (el.classList.contains('btn') || el.classList.contains('button')) return 'button';
        if (el.getAttribute('role') === 'button') return 'button';

        return 'action';
    }

    function sendClickLog(payload) {
        try {
            var url = getBaseUrl() + '/api/log-click.php';
            var jsonStr = JSON.stringify(payload);

            if (navigator.sendBeacon) {
                var blob = new Blob([jsonStr], { type: 'application/json' });
                navigator.sendBeacon(url, blob);
            } else if (window.fetch) {
                window.fetch(url, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: jsonStr,
                    keepalive: true
                }).catch(function() {
                    // Abaikan error jaringan
                });
            }
        } catch (err) {
            // Abaikan kesalahan agar UX pengguna tidak pernah terganggu
        }
    }

    document.addEventListener('click', function(e) {
        try {
            var now = Date.now();

            // Deteksi elemen interaktif terdekat
            var target = e.target.closest(
                'a, button, input[type="submit"], input[type="button"], [role="button"], .btn, .stat-card, .sidebar-menu a, .paginate_button, .tab'
            );

            if (!target) return;

            // Abaikan klik pada tombol penutup toast atau backdrop modal
            if (target.classList.contains('toast-close') || target.id === 'confirm-backdrop' || target.id === 'alert-backdrop') {
                return;
            }

            // Debounce klik beruntun pada elemen yang persis sama dalam 400ms
            if (lastTarget === target && (now - lastClickTime) < 400) {
                return;
            }
            lastTarget = target;
            lastClickTime = now;

            var text = extractElementText(target);
            var type = determineElementType(target);
            var href = target.getAttribute('href') || target.getAttribute('data-href') || '';
            var elemId = target.id || '';
            var elemClass = (typeof target.className === 'string') ? target.className.trim() : '';

            // Jika berupa DataTables pagination, beri label yang jelas
            if (target.classList.contains('paginate_button') && !text) {
                text = 'Navigasi Halaman Tabel';
            }

            // Jika tidak ada teks, id, maupun tautan, lewati
            if (!text && !elemId && !href) {
                return;
            }

            var payload = {
                element_text: text || ('Elemen ' + target.tagName.toLowerCase()),
                element_type: type,
                element_id: elemId ? elemId.substring(0, 100) : '',
                element_class: elemClass ? elemClass.substring(0, 200) : '',
                target_url: href ? href.substring(0, 255) : '',
                page_url: (window.location.pathname + window.location.search).substring(0, 255),
                page_title: (document.title || '').substring(0, 200)
            };

            sendClickLog(payload);
        } catch (err) {
            // Silent catch
        }
    }, true); // Gunakan fase capture agar tertangkap sebelum stopPropagation
})();
