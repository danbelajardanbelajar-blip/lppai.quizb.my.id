// LPPAI Corner - Main JavaScript (SPA + Global DataTables)

/* =============================================
   TOAST NOTIFICATION
   ============================================= */
function showToast(message, type, duration) {
    type = type || 'success';
    duration = duration || 4500;

    var icons = {
        success: '✅',
        danger: '❌',
        warning: '⚠️',
        info: 'ℹ️'
    };

    var container = document.getElementById('toast-container');
    if (!container) {
        container = document.createElement('div');
        container.id = 'toast-container';
        document.body.appendChild(container);
    }

    var toast = document.createElement('div');
    toast.className = 'toast toast-' + type;
    toast.innerHTML =
        '<span class="toast-icon">' + (icons[type] || '💬') + '</span>' +
        '<span class="toast-body">' + message + '</span>' +
        '<button class="toast-close" aria-label="Tutup">&times;</button>' +
        '<span class="toast-progress" style="animation-duration:' + duration + 'ms"></span>';

    container.appendChild(toast);

    function dismiss() {
        toast.classList.add('hide');
        setTimeout(function() {
            if (toast.parentNode) {
                toast.parentNode.removeChild(toast);
            }
        }, 360);
    }

    var closeBtn = toast.querySelector('.toast-close');
    if (closeBtn) {
        closeBtn.addEventListener('click', dismiss);
    }
    setTimeout(dismiss, duration);
}

/* =============================================
   CONFIRM DIALOG
   ============================================= */
function showConfirm(message, onConfirm, type) {
    type = type || 'danger';

    var icons = {
        danger: '🗑️',
        warning: '⚠️',
        info: '❓',
        success: '✅'
    };

    var btnColors = {
        danger: 'background:linear-gradient(135deg,#dc2626,#ef4444);color:#fff',
        warning: 'background:linear-gradient(135deg,#d97706,#f59e0b);color:#fff',
        info: 'background:linear-gradient(135deg,#2563eb,#3b82f6);color:#fff',
        success: 'background:linear-gradient(135deg,#1a5632,#2d7a4a);color:#fff'
    };

    var backdrop = document.getElementById('confirm-backdrop');
    if (!backdrop) {
        backdrop = document.createElement('div');
        backdrop.id = 'confirm-backdrop';
        backdrop.innerHTML =
            '<div id="confirm-dialog">' +
            '<div class="confirm-icon" id="confirm-icon"></div>' +
            '<h4 id="confirm-title">Konfirmasi</h4>' +
            '<p id="confirm-message"></p>' +
            '<div class="confirm-actions">' +
            '<button id="confirm-cancel" class="btn btn-secondary btn-sm" style="min-width:100px;">Batal</button>' +
            '<button id="confirm-ok" class="btn btn-danger btn-sm" style="min-width:100px;">Ya, Lanjutkan</button>' +
            '</div></div>';
        document.body.appendChild(backdrop);
    }

    var iconEl = document.getElementById('confirm-icon');
    var msgEl = document.getElementById('confirm-message');
    var okBtn = document.getElementById('confirm-ok');
    var cancelBtn = document.getElementById('confirm-cancel');

    if (iconEl) iconEl.textContent = icons[type] || '❓';
    if (msgEl) msgEl.textContent = message;
    if (okBtn) okBtn.setAttribute('style', (btnColors[type] || btnColors.danger) + ';min-width:100px;');

    backdrop.classList.add('show');

    function close() {
        backdrop.classList.remove('show');
    }

    var newOk = okBtn.cloneNode(true);
    var newCancel = cancelBtn.cloneNode(true);
    okBtn.parentNode.replaceChild(newOk, okBtn);
    cancelBtn.parentNode.replaceChild(newCancel, cancelBtn);

    newOk.addEventListener('click', function() {
        close();
        if (onConfirm) onConfirm();
    });

    newCancel.addEventListener('click', close);

    backdrop.addEventListener('click', function(e) {
        if (e.target === backdrop) close();
    });
}

/* =============================================
   ALERT DIALOG
   ============================================= */
function showAlert(message, type, onClose) {
    type = type || 'info';

    var icons = {
        danger: '❌',
        warning: '⚠️',
        info: 'ℹ️',
        success: '✅'
    };

    var btnColors = {
        danger: 'background:linear-gradient(135deg,#dc2626,#ef4444);color:#fff',
        warning: 'background:linear-gradient(135deg,#d97706,#f59e0b);color:#fff',
        info: 'background:linear-gradient(135deg,#2563eb,#3b82f6);color:#fff',
        success: 'background:linear-gradient(135deg,#1a5632,#2d7a4a);color:#fff'
    };

    var titles = {
        danger: 'Gagal',
        warning: 'Peringatan',
        info: 'Informasi',
        success: 'Berhasil'
    };

    var backdrop = document.getElementById('alert-backdrop');
    if (!backdrop) {
        backdrop = document.createElement('div');
        backdrop.id = 'alert-backdrop';
        backdrop.innerHTML =
            '<div id="alert-dialog">' +
            '<div class="confirm-icon" id="alert-icon"></div>' +
            '<h4 id="alert-title"></h4>' +
            '<p id="alert-message"></p>' +
            '<div class="confirm-actions">' +
            '<button id="alert-ok" class="btn btn-sm" style="min-width:100px;">OK</button>' +
            '</div></div>';
        document.body.appendChild(backdrop);
    }

    var iconEl = document.getElementById('alert-icon');
    var titleEl = document.getElementById('alert-title');
    var msgEl = document.getElementById('alert-message');
    var okBtn = document.getElementById('alert-ok');

    if (iconEl) iconEl.textContent = icons[type] || 'ℹ️';
    if (titleEl) titleEl.textContent = titles[type] || 'Informasi';
    if (msgEl) msgEl.innerHTML = message;
    if (okBtn) okBtn.setAttribute('style', (btnColors[type] || btnColors.info) + ';min-width:100px;');

    backdrop.classList.add('show');

    function close() {
        backdrop.classList.remove('show');
    }

    var newOk = okBtn.cloneNode(true);
    okBtn.parentNode.replaceChild(newOk, okBtn);

    newOk.addEventListener('click', function() {
        close();
        if (onClose) onClose();
    });

    backdrop.addEventListener('click', function(e) {
        if (e.target === backdrop) close();
    });
}

/* =============================================
   GLOBAL DATATABLES
   ============================================= */
function isDataTablesReady() {
    return !!(window.jQuery && window.jQuery.fn && window.jQuery.fn.DataTable);
}

function initAllTables(scope) {
    if (!isDataTablesReady()) return;

    var $ = window.jQuery;
    var root = scope || document;
    var tables = root.querySelectorAll('table');

    tables.forEach(function(table) {
        if (table.classList.contains('no-datatable')) return;
        if (table.closest('#import-result')) return;

        var hasHead = table.querySelectorAll('thead th').length > 0;
        if (!hasHead) return;

        if ($.fn.DataTable.isDataTable(table)) return;

        var actionCols = [];
        var headers = table.querySelectorAll('thead th');
        headers.forEach(function(th, idx) {
            var label = (th.textContent || '').trim().toLowerCase();
            if (label === 'aksi' || label.indexOf('action') !== -1) {
                actionCols.push(idx);
            }
        });

        $(table).DataTable({
            pageLength: 10,
            lengthMenu: [10, 25, 50, 100, 500],
            order: [],
            columnDefs: actionCols.length ? [{ orderable: false, targets: actionCols }] : [],
            language: {
                search: 'Cari:',
                lengthMenu: 'Tampilkan _MENU_ data',
                info: 'Menampilkan _START_ - _END_ dari _TOTAL_ data',
                infoEmpty: 'Tidak ada data',
                infoFiltered: '(difilter dari _MAX_ total data)',
                zeroRecords: 'Data tidak ditemukan',
                paginate: {
                    first: 'Pertama',
                    last: 'Terakhir',
                    next: 'Selanjutnya',
                    previous: 'Sebelumnya'
                }
            }
        });
    });
}

// DataTables kadang belum siap tepat saat halaman swap selesai.
function initAllTablesWithRetry(scope, attempts) {
    var remaining = typeof attempts === 'number' ? attempts : 8;

    if (isDataTablesReady()) {
        initAllTables(scope);
        return;
    }

    if (remaining <= 0) return;

    setTimeout(function() {
        initAllTablesWithRetry(scope, remaining - 1);
    }, 150);
}

/* =============================================
   AJAX DELETE
   ============================================= */
function ajaxDelete(table, id, csrfToken, rowEl, successMsg) {
    // Use window.location.origin as a reliable origin (avoids trailing-slash issues
    // with the base-url meta) and point to the root-level ajax-delete.php which is
    // always reachable regardless of subdirectory deployment status.
    var origin = window.location.origin ||
        (document.querySelector('meta[name="base-url"]')
            ? document.querySelector('meta[name="base-url"]').content
            : '');

    var formData = new FormData();
    formData.append('table', table);
    formData.append('id', id);
    formData.append('csrf_token', csrfToken);

    fetch(origin + '/ajax-delete.php', {
        method: 'POST',
        body: formData
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        if (!data.success) {
            showAlert(data.message || 'Gagal menghapus data.', 'danger');
            return;
        }

        var tableEl = rowEl ? rowEl.closest('table') : null;
        if (tableEl && isDataTablesReady()) {
            var $ = window.jQuery;
            if ($.fn.DataTable.isDataTable(tableEl)) {
                $(tableEl).DataTable().row(rowEl).remove().draw(false);
            } else if (rowEl && rowEl.parentNode) {
                rowEl.parentNode.removeChild(rowEl);
            }
        } else if (rowEl && rowEl.parentNode) {
            rowEl.classList.add('removing');
            setTimeout(function() {
                if (rowEl.parentNode) rowEl.parentNode.removeChild(rowEl);
            }, 420);
        }

        showAlert(successMsg || data.message, 'success');
    })
    .catch(function(err) {
        showAlert('Network error: ' + err.message, 'danger');
    });
}

/* =============================================
   SPA ENGINE
   ============================================= */
var SPA = {
    loading: false,

    init: function() {
        window.history.replaceState({ url: window.location.href }, '', window.location.href);
    },

    navigate: function(url, pushState) {
        var self = this;
        if (self.loading) return;
        self.loading = true;

        var contentArea = document.querySelector('.content-area');
        if (!contentArea) {
            window.location.href = url;
            return;
        }

        contentArea.style.transition = 'opacity 0.2s ease';
        contentArea.style.opacity = '0.3';

        fetch(url, { headers: { 'X-Requested-With': 'SPA' } })
            .then(function(r) {
                if (!r.ok) throw new Error('HTTP ' + r.status);
                return r.text();
            })
            .then(function(html) {
                self.apply(html, url, pushState !== false);
                self.loading = false;
            })
            .catch(function(err) {
                showAlert('Gagal memuat halaman: ' + err.message, 'danger');
                contentArea.style.opacity = '1';
                self.loading = false;
            });
    },

    submitForm: function(form) {
        var self = this;
        if (self.loading) return;
        self.loading = true;

        var contentArea = document.querySelector('.content-area');
        if (!contentArea) {
            form.submit();
            return;
        }

        var action = form.getAttribute('action') || window.location.href;
        var method = (form.getAttribute('method') || 'POST').toUpperCase();
        var formData = new FormData(form);

        contentArea.style.transition = 'opacity 0.2s ease';
        contentArea.style.opacity = '0.3';

        var fetchOptions = {
            method: method,
            headers: { 'X-Requested-With': 'SPA' }
        };

        if (method === 'GET' || method === 'HEAD') {
            var params = new URLSearchParams(formData);
            var separator = action.indexOf('?') !== -1 ? '&' : '?';
            action = action + separator + params.toString();
        } else {
            fetchOptions.body = formData;
        }

        fetch(action, fetchOptions)
            .then(function(r) {
                if (!r.ok) throw new Error('HTTP ' + r.status);
                return r.text();
            })
            .then(function(html) {
                self.apply(html, action, false);
                self.loading = false;
            })
            .catch(function(err) {
                showAlert('Error: ' + err.message, 'danger');
                contentArea.style.opacity = '1';
                self.loading = false;
            });
    },

    apply: function(html, url, pushState) {
        var parser = new DOMParser();
        var doc = parser.parseFromString(html, 'text/html');
        var newContent = doc.querySelector('.content-area');

        if (!newContent) {
            window.location.href = url;
            return;
        }

        var contentArea = document.querySelector('.content-area');
        var newTitle = doc.querySelector('title');
        var pageTitleEl = doc.querySelector('.page-title');

        setTimeout(function() {
            contentArea.innerHTML = newContent.innerHTML;

            if (newTitle) document.title = newTitle.textContent;
            var currentPageTitle = document.querySelector('.page-title');
            if (currentPageTitle && pageTitleEl) {
                currentPageTitle.textContent = pageTitleEl.textContent;
            }

            SPA.updateNav(url);

            // Jalankan script inline halaman (mis. modal import di users page).
            doc.querySelectorAll('script:not([src])').forEach(function(s) {
                var code = s.textContent.trim();
                if (!code) return;
                try {
                    (new Function(code))();
                } catch (e) {
                    // Abaikan error script individual agar SPA tidak berhenti total.
                }
            });

            contentArea.querySelectorAll('.alert').forEach(function(el) {
                var type = 'info';
                if (el.classList.contains('alert-success')) type = 'success';
                else if (el.classList.contains('alert-danger')) type = 'danger';
                else if (el.classList.contains('alert-warning')) type = 'warning';
                showAlert(el.innerHTML, type);
                el.remove();
            });

            initAllTablesWithRetry(contentArea);

            contentArea.style.transition = 'opacity 0.25s ease';
            contentArea.style.opacity = '1';

            if (pushState && url) {
                window.history.pushState({ url: url }, '', url);
            }

            var sidebar = document.querySelector('.sidebar');
            var overlay = document.querySelector('.sidebar-overlay');
            if (sidebar) sidebar.classList.remove('open');
            if (overlay) overlay.classList.remove('open');

            window.scrollTo({ top: 0, behavior: 'smooth' });
        }, 220);
    },

    updateNav: function(url) {
        var path = url.replace(/[?#].*$/, '');
        var urlFile = path.replace(/^.*\//, '');

        document.querySelectorAll('.sidebar-menu a').forEach(function(link) {
            var href = link.getAttribute('href') || '';
            var linkFile = href.replace(/^.*\//, '');
            if (linkFile && linkFile === urlFile) {
                link.classList.add('active');
            } else {
                link.classList.remove('active');
            }
        });
    }
};

window.addEventListener('popstate', function(e) {
    if (e.state && e.state.url) {
        SPA.navigate(e.state.url, false);
    }
});

/* =============================================
   DOM READY
   ============================================= */
document.addEventListener('DOMContentLoaded', function() {
    SPA.init();

    var hamburger = document.querySelector('.hamburger');
    var sidebar = document.querySelector('.sidebar');
    var overlay = document.querySelector('.sidebar-overlay');

    if (hamburger) {
        hamburger.addEventListener('click', function() {
            sidebar.classList.toggle('open');
            overlay.classList.toggle('open');
        });
    }

    if (overlay) {
        overlay.addEventListener('click', function() {
            sidebar.classList.remove('open');
            overlay.classList.remove('open');
        });
    }

    document.querySelectorAll('.alert').forEach(function(el) {
        var type = 'info';
        if (el.classList.contains('alert-success')) type = 'success';
        else if (el.classList.contains('alert-danger')) type = 'danger';
        else if (el.classList.contains('alert-warning')) type = 'warning';
        showAlert(el.innerHTML, type);
        el.remove();
    });

    var contentArea = document.querySelector('.content-area') || document;
    initAllTablesWithRetry(contentArea);
});

/* =============================================
   EVENT DELEGATION — SPA NAVIGATION
   ============================================= */
document.addEventListener('click', function(e) {
    var link = e.target.closest('a.page-nav');
    if (!link) return;

    var href = link.getAttribute('href');
    if (!href || href === '#' || href.indexOf('logout') !== -1) return;

    e.preventDefault();
    e.stopImmediatePropagation();
    SPA.navigate(href, true);
}, true);

/* =============================================
   EVENT DELEGATION — SPA FORM SUBMIT
   ============================================= */
document.addEventListener('submit', function(e) {
    var form = e.target;

    var submitBtns = form.querySelectorAll('button[type="submit"], input[type="submit"]');
    submitBtns.forEach(function(btn) {
        setTimeout(function() {
            btn.disabled = true;
            if (btn.tagName === 'BUTTON') {
                if (!btn.hasAttribute('data-old-text')) {
                    btn.setAttribute('data-old-text', btn.innerHTML);
                }
                btn.innerHTML = 'Memproses...';
            }
        }, 10);
    });


    if (!form.closest('.content-area')) return;
    if (form.getAttribute('enctype') === 'multipart/form-data') return;
    if (form.querySelector('input[type="file"]')) return;
    if (form.hasAttribute('data-no-spa')) return;

    var submitBtn = form.querySelector('[data-confirm]');
    if (submitBtn && submitBtn.dataset.table) return;

    e.preventDefault();
    SPA.submitForm(form);
}, false);

/* =============================================
   EVENT DELEGATION — CONFIRM + AJAX DELETE
   ============================================= */
document.addEventListener('click', function(e) {
    var el = e.target.closest('[data-confirm]');
    if (!el) return;

    e.preventDefault();
    e.stopImmediatePropagation();

    var msg = el.dataset.confirm;
    var table = el.dataset.table || '';
    var id = el.dataset.id || '';
    var isAjaxDelete = !!(table && id);
    var type = el.classList.contains('btn-warning') ? 'warning' : 'danger';
    var form = el.closest('form');

    showConfirm(msg, function() {
        if (isAjaxDelete) {
            var csrf = form ? (form.querySelector('[name=csrf_token]') || {}).value : '';
            if (!csrf) {
                var csrfEl = document.querySelector('input[name=csrf_token]');
                csrf = csrfEl ? csrfEl.value : '';
            }
            var row = el.closest('tr');
            ajaxDelete(table, id, csrf, row);
            return;
        }

        if (form) {
            SPA.submitForm(form);
        } else if (el.tagName === 'A') {
            SPA.navigate(el.href, true);
        }
    }, type);
}, true);

/* =============================================
   MODAL HELPERS
   ============================================= */
function openModal(id) {
    var el = document.getElementById(id);
    if (el) el.classList.add('show');
}

function closeModal(id) {
    var el = document.getElementById(id);
    if (el) el.classList.remove('show');
}
