/**
 * LPPAI Corner - Activity Tracker (Click Activity Logger)
 * Merekam seluruh interaksi klik pengguna (menu, tombol, aksi, link) secara real-time & non-blocking
 */
(function() {
    'use strict';

    var lastTarget = null;
    var lastClickTime = 0;

    /**
     * Dapatkan URL endpoint log klik yang selalu berada pada origin yang sama (mencegah CORS issue)
     */
    function getLogUrl() {
        var origin = window.location.origin || (window.location.protocol + '//' + window.location.host);
        var pathname = window.location.pathname;

        // Jika sistem berjalan di dalam subdirektori (misal: /lppai.quizb.my.id/admin/...)
        var rootFolder = '';
        var match = pathname.match(/^(.*?)(\/(admin|dosen|pages|api))(\/|$)/i);
        if (match && match[1]) {
            rootFolder = match[1];
        }

        return origin + rootFolder + '/ajax-log-click.php';
    }

    /**
     * Ekstrak teks deskriptif dari elemen interaktif
     */
    function extractElementText(el) {
        if (!el) return '';

        // Elemen Input
        if (el.tagName === 'INPUT') {
            return (el.value || el.placeholder || el.name || '').trim();
        }

        // Cek aria-label atau title terlebih dahulu (berguna untuk tombol ikon)
        var ariaLabel = el.getAttribute('aria-label') || '';
        var titleAttr = el.getAttribute('title') || '';

        // Ambil text content
        var text = (el.innerText || el.textContent || '').trim();

        if (!text && ariaLabel) return ariaLabel.trim();
        if (!text && titleAttr) return titleAttr.trim();

        // Bersihkan spasi ganda dan baris baru
        text = text.replace(/\s+/g, ' ');
        if (text.length > 150) {
            text = text.substring(0, 147) + '...';
        }
        return text;
    }

    /**
     * Tentukan klasifikasi tipe elemen yang diklik
     */
    function determineElementType(el) {
        if (!el) return 'click';
        if (el.closest('.sidebar-menu') || el.classList.contains('page-nav')) return 'menu';
        if (el.closest('.stat-card')) return 'card';
        if (el.classList.contains('tab') || el.hasAttribute('data-tab')) return 'tab';

        var tag = el.tagName ? el.tagName.toLowerCase() : '';
        if (tag === 'a') return 'link';
        if (tag === 'button') return 'button';
        if (tag === 'input') return 'input';
        if (el.classList.contains('btn') || el.classList.contains('button')) return 'button';
        if (el.getAttribute('role') === 'button') return 'button';

        return 'action';
    }

    /**
     * Kirim data klik ke server menggunakan FormData (paling kompatibel & stabil)
     */
    function sendClickLog(payload) {
        try {
            var url = getLogUrl();
            var formData = new FormData();
            formData.append('element_text', payload.element_text || '');
            formData.append('element_type', payload.element_type || 'click');
            formData.append('element_id', payload.element_id || '');
            formData.append('element_class', payload.element_class || '');
            formData.append('target_url', payload.target_url || '');
            formData.append('page_url', payload.page_url || '');
            formData.append('page_title', payload.page_title || '');

            var sent = false;

            // 1. Coba navigator.sendBeacon
            if (navigator.sendBeacon) {
                try {
                    sent = navigator.sendBeacon(url, formData);
                } catch (beaconErr) {
                    sent = false;
                }
            }

            // 2. Fallback fetch keepalive
            if (!sent && window.fetch) {
                try {
                    fetch(url, {
                        method: 'POST',
                        body: formData,
                        keepalive: true
                    }).catch(function() {});
                    sent = true;
                } catch (fetchErr) {
                    sent = false;
                }
            }

            // 3. Fallback jQuery ajax jika ada
            if (!sent && window.jQuery && window.jQuery.ajax) {
                window.jQuery.ajax({
                    url: url,
                    type: 'POST',
                    data: payload,
                    async: true
                });
            }
        } catch (err) {
            // Abaikan kesalahan agar sistem utama tidak terganggu
        }
    }

    /**
     * Fungsi publik untuk melacak klik secara eksplisit
     */
    window.trackUserClick = function(target, customType) {
        if (!target) return;
        try {
            var now = Date.now();

            // Hindari pencatatan dobel jika target sama diklik dalam < 350ms
            if (lastTarget === target && (now - lastClickTime) < 350) {
                return;
            }
            lastTarget = target;
            lastClickTime = now;

            var text = extractElementText(target);
            var type = customType || determineElementType(target);
            var href = target.getAttribute('href') || target.getAttribute('data-href') || target.action || '';
            var elemId = target.id || '';
            var elemClass = (typeof target.className === 'string') ? target.className.trim() : '';

            // Jika berupa DataTables pagination
            if (target.classList.contains('paginate_button') && !text) {
                text = 'Navigasi Paginasi Tabel';
            }

            // Fallback deskripsi jika elemen tidak memiliki teks tertulis
            if (!text) {
                if (elemId) {
                    text = '#' + elemId;
                } else if (href && href !== '#' && href.indexOf('javascript:') === -1) {
                    text = href.replace(/^.*\//, '');
                } else {
                    text = 'Klik ' + (target.tagName ? target.tagName.toUpperCase() : 'Elemen');
                }
            }

            var payload = {
                element_text: text,
                element_type: type,
                element_id: elemId ? elemId.substring(0, 100) : '',
                element_class: elemClass ? elemClass.substring(0, 200) : '',
                target_url: href ? href.substring(0, 255) : '',
                page_url: (window.location.pathname + window.location.search).substring(0, 255),
                page_title: (document.title || '').substring(0, 200)
            };

            sendClickLog(payload);
        } catch (err) {
            // Abaikan error
        }
    };

    /**
     * Listener global capture-phase untuk menangkap semua klik interaktif di aplikasi
     */
    document.addEventListener('click', function(e) {
        try {
            var target = e.target.closest(
                'a, button, input[type="submit"], input[type="button"], [role="button"], .btn, .stat-card, .sidebar-menu a, .paginate_button, .tab, [data-confirm]'
            );

            if (!target) return;

            // Lewati klik penutup toast dan backdrop dialog
            if (target.classList.contains('toast-close') || target.id === 'confirm-backdrop' || target.id === 'alert-backdrop') {
                return;
            }

            window.trackUserClick(target);
        } catch (err) {
            // Abaikan error
        }
    }, true);
})();
