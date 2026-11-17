<?php
/**
 * LPPAI Corner - Diktat Tutorial LPPAI 2026 (Pegangan Dosen Pengampu & Tutor)
 *
 * Menampilkan reader Diktat LPPAI 2026 untuk Dosen Pengampu & Tutor (hanya baca, tanpa unduh/cetak),
 * dilengkapi watermark nama & NIDN dosen, kontrol zoom, navigasi halaman, dan layar penuh.
 */
if (!defined('PAGE_TITLE')) define('PAGE_TITLE', 'Diktat Tutorial LPPAI');
require_once __DIR__ . '/../includes/auth.php';
requireDosen();

$user = getCurrentUser();
$pdo  = getDBConnection();

// Kunci sesi untuk reader diktat (dipakai diktat-stream.php)
if (empty($_SESSION['diktat_key'])) {
    $_SESSION['diktat_key'] = bin2hex(random_bytes(32));
}
$diktatKey   = $_SESSION['diktat_key'];
$diktatReady = is_file(__DIR__ . '/../storage/diktat/diktat-lppai-2026.pdf');
$watermark   = trim(($user['nama_lengkap'] ?? '') . ' · Dosen / Tutor LPPAI');

include __DIR__ . '/../includes/header.php';
?>
<style>
.ti-hero { background: linear-gradient(135deg, var(--primary) 0%, var(--primary-light) 100%); color:#fff; border-radius:18px; padding:24px 26px; margin-bottom:22px; box-shadow:0 12px 30px rgba(11,59,34,.18); }
.ti-hero h2 { margin:0 0 6px; font-size:22px; font-weight:800; color:#fff; }
.ti-hero p { margin:0; opacity:.85; font-size:13.5px; }

/* ── Topik Materi Chips ── */
.ti-diktat-chips { display:flex; flex-wrap:wrap; gap:8px; margin-top:14px; }
.ti-diktat-chip { background:rgba(255,255,255,0.18); border:1px solid rgba(255,255,255,0.3); border-radius:8px; padding:5px 12px; font-size:12px; font-weight:700; color:#ffffff; }

/* ── Reader Diktat ── */
.dr-wrap { background:#0f172a; border-radius:18px; overflow:hidden; box-shadow:0 18px 40px rgba(15,23,42,.25); user-select:none; -webkit-user-select:none; margin-bottom:14px; }
.dr-wrap:fullscreen { border-radius:0; display:flex; flex-direction:column; }
.dr-bar { display:flex; align-items:center; justify-content:space-between; gap:10px; padding:10px 14px; background:rgba(15,23,42,.92); backdrop-filter:blur(8px); border-bottom:1px solid rgba(255,255,255,.08); position:sticky; top:0; z-index:5; flex-wrap:wrap; }
.dr-title { color:#e2e8f0; font-weight:800; font-size:14px; display:flex; align-items:center; gap:8px; min-width:0; }
.dr-title span { white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.dr-ctrl { display:flex; align-items:center; gap:6px; flex-wrap:wrap; }
.dr-btn { background:rgba(255,255,255,.08); color:#f8fafc; border:1px solid rgba(255,255,255,.12); width:34px; height:34px; border-radius:9px; font-size:15px; cursor:pointer; display:inline-flex; align-items:center; justify-content:center; transition:.15s; }
.dr-btn:hover { background:rgba(16,185,129,.25); border-color:rgba(16,185,129,.6); }
.dr-btn:disabled { opacity:.35; cursor:default; }
.dr-page { display:flex; align-items:center; gap:6px; color:#cbd5e1; font-size:13px; font-weight:600; }
.dr-page input { width:52px; height:32px; text-align:center; border-radius:8px; border:1px solid rgba(255,255,255,.15); background:rgba(255,255,255,.06); color:#fff; font-weight:700; }
.dr-zoom { color:#cbd5e1; font-size:12.5px; font-weight:700; min-width:46px; text-align:center; }
.dr-progress { height:3px; background:rgba(255,255,255,.06); }
.dr-progress div { height:100%; width:0; background:linear-gradient(90deg,#10b981,#facc15); transition:width .2s; }
.dr-view { height:78vh; overflow:auto; padding:22px 12px 40px; background:radial-gradient(circle at top, #1e293b 0%, #0f172a 70%); scroll-behavior:smooth; }
.dr-wrap:fullscreen .dr-view { height:auto; flex:1; }
.dr-pagebox { position:relative; margin:0 auto 18px; background:#fff; border-radius:6px; box-shadow:0 10px 28px rgba(0,0,0,.45); overflow:hidden; }
.dr-pagebox canvas { display:block; width:100%; height:100%; pointer-events:none; }
.dr-pagebox .dr-num { position:absolute; bottom:8px; right:10px; font-size:11px; font-weight:700; color:#64748b; background:rgba(255,255,255,.85); padding:2px 8px; border-radius:999px; }
.dr-wm { position:absolute; inset:0; pointer-events:none; display:flex; align-items:center; justify-content:center; }
.dr-wm span { transform:rotate(-32deg); font-size:clamp(14px,3.2vw,30px); font-weight:800; color:rgba(11,59,34,.07); white-space:nowrap; letter-spacing:.04em; }
.dr-note { font-size:12px; color:var(--text-muted); margin:10px 0 20px; }
.dr-loading { color:#cbd5e1; display:flex; align-items:center; justify-content:center; gap:10px; padding:60px 0; font-size:14px; font-weight:600; }
.dr-spin { width:22px; height:22px; border:3px solid rgba(255,255,255,.15); border-top-color:#10b981; border-radius:50%; animation:dr-spin .8s linear infinite; }
@keyframes dr-spin { to { transform:rotate(360deg); } }
.ti-empty { text-align:center; padding:40px 20px; background:#fff; border:1px dashed #cbd5e1; border-radius:16px; color:#475569; }
.ti-empty .big { font-size:40px; }
@media print {
    .dr-wrap, .dr-note { display:none !important; }
}
</style>

<div class="ti-hero">
    <h2>📖 Diktat Resmi LPPAI 2026</h2>
    <p>Buku Panduan &amp; Pegangan Dosen Pengampu / Tutor Tutorial Ubudiyah &amp; PAI LPPAI Universitas Islam Darul 'Ulum Lamongan.</p>
    <div class="ti-diktat-chips">
        <span class="ti-diktat-chip">🕌 Thaharah &amp; Wudhu Sempurna</span>
        <span class="ti-diktat-chip">🤲 Shalat Fardhu &amp; Shalat Jenazah</span>
        <span class="ti-diktat-chip">📿 Dzikir &amp; Wirid Ba'da Shalat</span>
        <span class="ti-diktat-chip">📖 Kaidah Tajwid &amp; Tartil Al-Qur'an</span>
    </div>
</div>

<?php if (!$diktatReady): ?>
    <div class="ti-empty">
        <div class="big">📕</div>
        <h3 style="margin:8px 0 6px;">Berkas Diktat Belum Tersedia</h3>
        <p style="margin:0;">Berkas fisik Diktat LPPAI 2026 belum ditemukan di folder storage sistem. Hubungi administrator.</p>
    </div>
<?php else: ?>
    <div class="dr-wrap" id="dr-wrap" oncontextmenu="return false;">
        <div class="dr-bar">
            <div class="dr-title">📖 <span>Diktat LPPAI 2026 (Pegangan Dosen)</span></div>
            <div class="dr-ctrl">
                <button type="button" class="dr-btn" id="dr-prev" title="Halaman sebelumnya">‹</button>
                <div class="dr-page"><input type="number" id="dr-input" min="1" value="1" aria-label="Nomor halaman"> / <span id="dr-total">–</span></div>
                <button type="button" class="dr-btn" id="dr-next" title="Halaman berikutnya">›</button>
                <span style="width:6px;"></span>
                <button type="button" class="dr-btn" id="dr-zout" title="Perkecil">−</button>
                <span class="dr-zoom" id="dr-zlabel">100%</span>
                <button type="button" class="dr-btn" id="dr-zin" title="Perbesar">+</button>
                <button type="button" class="dr-btn" id="dr-fit" title="Sesuaikan lebar">↔</button>
                <button type="button" class="dr-btn" id="dr-full" title="Layar penuh">⛶</button>
            </div>
        </div>
        <div class="dr-progress"><div id="dr-prog"></div></div>
        <div class="dr-view" id="dr-view">
            <div class="dr-loading" id="dr-loading"><div class="dr-spin"></div>Memuat Diktat LPPAI…</div>
        </div>
    </div>
    <p class="dr-note">🔒 Diktat hanya dapat dibaca di halaman ini dan tidak tersedia untuk diunduh atau dicetak.</p>
<?php endif; ?>

<!-- Tombol Aksi Navigasi -->
<div style="margin-top:20px; display:flex; gap:10px; flex-wrap:wrap; align-items:center;">
    <a href="<?= BASE_URL ?>/dosen/dashboard.php" class="btn btn-secondary" style="text-decoration:none;">🏠 Kembali ke Dashboard Dosen</a>
    <a href="<?= BASE_URL ?>/dosen/kelas.php" class="btn btn-primary" style="text-decoration:none;">🏫 Kelola Kelas Anda</a>
</div>

<?php if ($diktatReady): ?>
<script src="https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js"></script>
<script>
(function () {
    var KEY_HEX = <?= json_encode($diktatKey) ?>;
    var STREAM_URL = <?= json_encode(BASE_URL . '/diktat-stream.php') ?>;
    var WATERMARK = <?= json_encode($watermark) ?>;

    var view = document.getElementById('dr-view');
    var wrap = document.getElementById('dr-wrap');
    var input = document.getElementById('dr-input');
    var totalEl = document.getElementById('dr-total');
    var zlabel = document.getElementById('dr-zlabel');
    var prog = document.getElementById('dr-prog');
    var pdfDoc = null, baseW = 0, baseH = 0, scale = 1, fitMode = true;
    var boxes = [], rendered = {}, observer = null, renderToken = 0;

    // Blokir pintasan simpan / cetak / view-source di halaman ini
    document.addEventListener('keydown', function (e) {
        var k = (e.key || '').toLowerCase();
        if ((e.ctrlKey || e.metaKey) && (k === 's' || k === 'p' || k === 'u')) { e.preventDefault(); e.stopPropagation(); }
    }, true);
    if (wrap) {
        wrap.addEventListener('dragstart', function (e) { e.preventDefault(); });
    }

    function hexToBytes(hex) {
        var out = new Uint8Array(hex.length / 2);
        for (var i = 0; i < out.length; i++) out[i] = parseInt(hex.substr(i * 2, 2), 16);
        return out;
    }

    function fitScale() {
        var avail = view.clientWidth - 24;
        return Math.max(0.3, Math.min(3, avail / baseW));
    }

    function setZoomLabel() { zlabel.textContent = Math.round(scale * 100) + '%'; }

    function layout() {
        var w = Math.floor(baseW * scale), h = Math.floor(baseH * scale);
        boxes.forEach(function (b) { b.style.width = w + 'px'; b.style.height = h + 'px'; });
        rendered = {};
        renderToken++;
        boxes.forEach(function (b) { var c = b.querySelector('canvas'); if (c) c.remove(); });
        setZoomLabel();
        if (observer) { observer.disconnect(); boxes.forEach(function (b) { observer.observe(b); }); }
    }

    function renderPage(num) {
        if (rendered[num]) return;
        rendered[num] = true;
        var token = renderToken;
        pdfDoc.getPage(num).then(function (page) {
            if (token !== renderToken) { rendered[num] = false; return; }
            var dpr = Math.min(window.devicePixelRatio || 1, 2);
            var vp = page.getViewport({ scale: scale * dpr });
            var canvas = document.createElement('canvas');
            canvas.width = vp.width; canvas.height = vp.height;
            var box = boxes[num - 1];
            box.insertBefore(canvas, box.firstChild);
            return page.render({ canvasContext: canvas.getContext('2d'), viewport: vp }).promise;
        }).catch(function () { rendered[num] = false; });
    }

    function currentPage() {
        var mid = view.scrollTop + view.clientHeight / 3, cur = 1;
        for (var i = 0; i < boxes.length; i++) { if (boxes[i].offsetTop <= mid) cur = i + 1; else break; }
        return cur;
    }

    function updateState() {
        if (!pdfDoc) return;
        var cur = currentPage();
        if (document.activeElement !== input) input.value = cur;
        document.getElementById('dr-prev').disabled = cur <= 1;
        document.getElementById('dr-next').disabled = cur >= pdfDoc.numPages;
        var max = view.scrollHeight - view.clientHeight;
        prog.style.width = (max > 0 ? (view.scrollTop / max) * 100 : 100) + '%';
    }

    function goTo(n) {
        if (!pdfDoc) return;
        n = Math.max(1, Math.min(pdfDoc.numPages, n | 0));
        view.scrollTop = boxes[n - 1].offsetTop - 12;
    }

    function startDiktat() {
        fetch(STREAM_URL, { headers: { 'X-Diktat-Key': KEY_HEX }, credentials: 'same-origin', cache: 'no-store' })
            .then(function (res) {
                if (!res.ok) throw new Error('HTTP ' + res.status);
                return res.arrayBuffer();
            })
            .then(function (buf) {
                var bytes = new Uint8Array(buf);
                var mask = hexToBytes(KEY_HEX);
                var mlen = mask.length;
                for (var i = 0; i < bytes.length; i++) bytes[i] ^= mask[i % mlen];
                pdfjsLib.GlobalWorkerOptions.workerSrc = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js';
                return pdfjsLib.getDocument({ data: bytes }).promise;
            })
            .then(function (doc) {
                pdfDoc = doc;
                totalEl.textContent = doc.numPages;
                input.max = doc.numPages;
                return doc.getPage(1);
            })
            .then(function (p1) {
                var vp = p1.getViewport({ scale: 1 });
                baseW = vp.width; baseH = vp.height;
                scale = fitScale();
                setZoomLabel();

                view.innerHTML = '';
                boxes = [];
                for (var i = 1; i <= pdfDoc.numPages; i++) {
                    var box = document.createElement('div');
                    box.className = 'dr-pagebox';
                    box.dataset.page = String(i);
                    box.style.width = Math.floor(baseW * scale) + 'px';
                    box.style.height = Math.floor(baseH * scale) + 'px';

                    var wm = document.createElement('div');
                    wm.className = 'dr-wm';
                    var sp = document.createElement('span');
                    sp.textContent = WATERMARK;
                    wm.appendChild(sp);
                    box.appendChild(wm);

                    var num = document.createElement('div');
                    num.className = 'dr-num';
                    num.textContent = i + ' / ' + pdfDoc.numPages;
                    box.appendChild(num);

                    view.appendChild(box);
                    boxes.push(box);
                }

                observer = new IntersectionObserver(function (entries) {
                    entries.forEach(function (en) {
                        if (en.isIntersecting) renderPage(parseInt(en.target.dataset.page, 10));
                    });
                }, { root: view, rootMargin: '300px 0px' });
                boxes.forEach(function (b) { observer.observe(b); });

                updateState();
            })
            .catch(function (err) {
                var loadingEl = document.getElementById('dr-loading');
                if (loadingEl) {
                    loadingEl.textContent = 'Diktat gagal dimuat (' + err.message + '). Silakan muat ulang halaman.';
                }
            });
    }

    document.getElementById('dr-prev').addEventListener('click', function () { goTo(currentPage() - 1); });
    document.getElementById('dr-next').addEventListener('click', function () { goTo(currentPage() + 1); });
    input.addEventListener('change', function () { goTo(parseInt(this.value, 10)); });
    document.getElementById('dr-zin').addEventListener('click', function () { fitMode = false; scale = Math.min(3, scale * 1.25); layout(); });
    document.getElementById('dr-zout').addEventListener('click', function () { fitMode = false; scale = Math.max(0.3, scale / 1.25); layout(); });
    document.getElementById('dr-fit').addEventListener('click', function () { fitMode = true; scale = fitScale(); layout(); });
    document.getElementById('dr-full').addEventListener('click', function () {
        if (!document.fullscreenElement) { wrap.requestFullscreen().catch(function () {}); }
        else { document.exitFullscreen().catch(function () {}); }
    });
    view.addEventListener('scroll', updateState, { passive: true });
    window.addEventListener('resize', function () { if (fitMode && baseW) { scale = fitScale(); layout(); } });

    startDiktat();
})();
</script>
<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
