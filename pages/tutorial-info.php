<?php
/**
 * LPPAI Corner - Info Tutorial (Mahasiswa)
 * Menampilkan jadwal, tutor, dan ruangan kelas tutorial mahasiswa,
 * serta reader Diktat LPPAI 2026 (hanya baca, tanpa unduh/cetak).
 */
define('PAGE_TITLE', 'Info Tutorial');
require_once __DIR__ . '/../includes/auth.php';
requireLogin();

if (isAdmin()) {
    header('Location: ' . BASE_URL . '/admin/dashboard.php');
    exit;
}

$user = getCurrentUser();
$pdo  = getDBConnection();

// Mahasiswa yang sedang di tahap pretes aktif → tampilkan kartu Tahap Pretes
require_once __DIR__ . '/../includes/pretes-stage.php';
pretesStageGate($pdo, $user, 'Info kelas, tutor, ruangan, dan diktat tutorial akan tersedia bila Anda mengikuti program Tutorial.');

$gelombangs = [
    'gel1'    => 'Gelombang 1 (Ganjil)',
    'gel2'    => 'Gelombang 2 (Genap)',
    'mandiri' => 'Mandiri',
];

$stmt = $pdo->prepare("
    SELECT tr.id, tr.status, tr.created_at, tr.tahun_ajaran,
           tc.nama_kelas, tc.dosen_pengampu, tc.hari, tc.jam, tc.ruangan, tc.gelombang
    FROM tutorial_registrations tr
    LEFT JOIN tutorial_classes tc ON tr.tutorial_class_id = tc.id
    WHERE tr.user_id = ?
    ORDER BY tr.created_at DESC
");
$stmt->execute([$user['id']]);
$registrations = $stmt->fetchAll();

// Kunci sesi untuk reader diktat (dipakai diktat-stream.php)
if (empty($_SESSION['diktat_key'])) {
    $_SESSION['diktat_key'] = bin2hex(random_bytes(32));
}
$diktatKey   = $_SESSION['diktat_key'];
$diktatReady = is_file(__DIR__ . '/../storage/diktat/diktat-lppai-2026.pdf');
$watermark   = trim(($user['nama_lengkap'] ?? '') . ' · ' . ($user['nim'] ?? ''));

$statusBadges = [
    'terdaftar'         => ['Menunggu Plotting', '#b45309', '#fef3c7'],
    'aktif'             => ['Aktif', '#047857', '#d1fae5'],
    'lulus'             => ['Lulus', '#15803d', '#dcfce7'],
    'tidak_lulus'       => ['Tidak Lulus', '#b91c1c', '#fee2e2'],
    'mengundurkan_diri' => ['Mengundurkan Diri', '#9a3412', '#ffedd5'],
];

include __DIR__ . '/../includes/header.php';
?>
<style>
.ti-hero { background: linear-gradient(135deg, var(--primary) 0%, var(--primary-light) 100%); color:#fff; border-radius:18px; padding:24px 26px; margin-bottom:22px; box-shadow:0 12px 30px rgba(11,59,34,.18); }
.ti-hero h2 { margin:0 0 6px; font-size:22px; font-weight:800; color:#fff; }
.ti-hero p { margin:0; opacity:.85; font-size:13.5px; }
.ti-tabs { display:flex; gap:8px; margin-bottom:18px; flex-wrap:wrap; }
.ti-tab { border:1px solid #d1d5db; background:#fff; color:#334155; padding:9px 16px; border-radius:999px; font-weight:700; font-size:13.5px; cursor:pointer; transition:.15s; }
.ti-tab.active { background:var(--primary); border-color:var(--primary); color:#fff; box-shadow:0 6px 16px rgba(11,59,34,.2); }
.ti-panel { display:none; }
.ti-panel.active { display:block; }
.ti-class { background:#fff; border:1px solid #e5e7eb; border-radius:16px; overflow:hidden; margin-bottom:16px; box-shadow:0 4px 14px rgba(15,23,42,.04); }
.ti-class-head { display:flex; justify-content:space-between; align-items:center; gap:10px; padding:14px 18px; background:#f8fafc; border-bottom:1px solid #eef2f7; flex-wrap:wrap; }
.ti-class-head h3 { margin:0; font-size:15.5px; font-weight:800; color:#0f172a; }
.ti-badge { font-size:12px; font-weight:700; padding:4px 10px; border-radius:999px; }
.ti-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(190px,1fr)); gap:14px; padding:18px; }
.ti-item { display:flex; gap:12px; align-items:flex-start; padding:12px; border-radius:12px; background:#f8fafc; border:1px solid #eef2f7; }
.ti-ico { width:38px; height:38px; flex:0 0 38px; border-radius:10px; display:flex; align-items:center; justify-content:center; font-size:18px; background:#e7f5ee; }
.ti-lbl { font-size:11px; font-weight:800; letter-spacing:.06em; color:var(--text-muted); text-transform:uppercase; }
.ti-val { font-size:15px; font-weight:700; color:#0f172a; margin-top:3px; word-break:break-word; }
.ti-empty { text-align:center; padding:40px 20px; background:#fff; border:1px dashed #cbd5e1; border-radius:16px; color:#475569; }
.ti-empty .big { font-size:40px; }

/* ── Reader Diktat ─────────────────────────────────────────── */
.dr-wrap { background:#0f172a; border-radius:18px; overflow:hidden; box-shadow:0 18px 40px rgba(15,23,42,.25); user-select:none; -webkit-user-select:none; }
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
.dr-loading { color:#94a3b8; text-align:center; padding:60px 20px; font-weight:600; }
.dr-spin { width:34px; height:34px; border:3px solid rgba(255,255,255,.12); border-top-color:#10b981; border-radius:50%; margin:0 auto 12px; animation:drspin .8s linear infinite; }
@keyframes drspin { to { transform:rotate(360deg); } }
.dr-note { font-size:12px; color:var(--text-muted); margin-top:10px; }
@media (max-width: 640px) {
    .dr-view { height:70vh; padding:14px 6px 30px; }
    .dr-title span { max-width:150px; }
}
@media print {
    .dr-wrap, .ti-panel[data-panel="diktat"] { display:none !important; }
    body::after { content:"Diktat tidak dapat dicetak."; }
}

/* ── Waiting Plotting & Diktat Prompt Styling ──────────────── */
.ti-waiting-card { padding: 22px; background: #ffffff; }
.ti-waiting-header { display: flex; gap: 16px; align-items: flex-start; padding: 18px 20px; background: #fffbeb; border: 1.5px solid #fde68a; border-radius: 14px; margin-bottom: 22px; }
.ti-waiting-icon-pulse { font-size: 32px; line-height: 1; flex-shrink: 0; animation: ti-float 2.5s ease-in-out infinite; }
@keyframes ti-float { 0%, 100% { transform: translateY(0); } 50% { transform: translateY(-4px); } }
.ti-waiting-header-text h4 { margin: 0 0 6px; font-size: 16.5px; font-weight: 800; color: #92400e; }
.ti-waiting-header-text p { margin: 0; font-size: 13.5px; line-height: 1.6; color: #78350f; }

/* Horizontal Stepper */
.ti-stepper { display: flex; align-items: center; justify-content: space-between; padding: 18px 22px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 14px; margin-bottom: 22px; flex-wrap: wrap; gap: 12px; }
.ti-step { display: flex; align-items: center; gap: 10px; }
.ti-step-circle { width: 36px; height: 36px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: 800; font-size: 13px; background: #f1f5f9; color: #94a3b8; border: 2px solid #cbd5e1; flex-shrink: 0; }
.ti-step.completed .ti-step-circle { background: #dcfce7; color: #15803d; border-color: #86efac; font-size: 15px; }
.ti-step.active .ti-step-circle { background: #fef3c7; color: #b45309; border-color: #f59e0b; box-shadow: 0 0 0 4px rgba(245, 158, 11, 0.18); }
.ti-step-label strong { font-size: 13px; color: #1e293b; display: block; }
.ti-step-label span { font-size: 11px; color: #64748b; }
.ti-step-label .pulse-text { color: #b45309; font-weight: 700; }
.ti-step-line { flex: 1; min-width: 24px; height: 3px; background: #e2e8f0; border-radius: 999px; }
.ti-step-line.completed { background: #10b981; }

/* Diktat Educational Prompt Banner */
.ti-diktat-prompt { background: linear-gradient(135deg, #064e3b 0%, #065f46 60%, #047857 100%); color: #ffffff; border-radius: 16px; padding: 26px 28px; margin-bottom: 20px; display: flex; justify-content: space-between; align-items: center; gap: 26px; box-shadow: 0 10px 25px rgba(6, 78, 59, 0.22); }
.ti-diktat-prompt-left { flex: 1; min-width: 0; }
.ti-diktat-badge { display: inline-block; background: rgba(255, 255, 255, 0.16); border: 1px solid rgba(255, 255, 255, 0.28); color: #fef08a; font-size: 11px; font-weight: 800; padding: 4px 12px; border-radius: 999px; letter-spacing: 0.05em; margin-bottom: 10px; }
.ti-diktat-prompt h3 { margin: 0 0 8px; font-size: 19px; font-weight: 800; color: #ffffff; line-height: 1.35; }
.ti-diktat-prompt p { margin: 0 0 16px; font-size: 13.5px; line-height: 1.6; color: #e2e8f0; }
.ti-diktat-topics { display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 20px; }
.ti-topic-tag { background: rgba(255, 255, 255, 0.12); border: 1px solid rgba(255, 255, 255, 0.22); border-radius: 8px; padding: 6px 12px; font-size: 12px; font-weight: 600; color: #f0fdf4; }
.ti-diktat-action { display: flex; align-items: center; gap: 14px; flex-wrap: wrap; }
.btn-diktat-cta { background: #facc15; color: #713f12; border: none; padding: 12px 24px; border-radius: 12px; font-weight: 800; font-size: 14px; cursor: pointer; transition: all 0.2s ease; box-shadow: 0 4px 14px rgba(250, 204, 21, 0.35); display: inline-flex; align-items: center; gap: 8px; text-decoration: none; }
.btn-diktat-cta:hover { background: #fde047; transform: translateY(-2px); box-shadow: 0 6px 20px rgba(250, 204, 21, 0.45); color: #422006; }
.ti-diktat-subnote { font-size: 12px; opacity: 0.85; color: #d1fae5; }

/* 3D Book Visual Effect */
.ti-diktat-prompt-visual { display: flex; align-items: center; justify-content: center; flex: 0 0 150px; }
.ti-diktat-book-card { width: 130px; height: 175px; background: #ffffff; border-radius: 10px; padding: 14px 12px; box-shadow: -6px 8px 24px rgba(0, 0, 0, 0.35); border-left: 7px solid #facc15; display: flex; flex-direction: column; justify-content: space-between; text-align: center; color: #064e3b; transform: rotate(-3deg); transition: transform 0.25s ease; user-select: none; }
.ti-diktat-book-card:hover { transform: rotate(0deg) scale(1.06); }
.ti-diktat-book-title { font-size: 11px; font-weight: 800; letter-spacing: 0.06em; color: #064e3b; margin-top: 4px; }
.ti-diktat-book-sub { font-size: 13px; font-weight: 900; color: #047857; margin-top: 2px; }
.ti-diktat-book-logo { font-size: 28px; margin: 10px 0; }
.ti-diktat-book-label { font-size: 10px; font-weight: 700; color: #64748b; background: #f1f5f9; padding: 3px 6px; border-radius: 6px; }

/* Waiting Footer Info */
.ti-waiting-footer { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 12px; padding: 14px 18px; display: flex; flex-direction: column; gap: 8px; font-size: 12.5px; color: #475569; }
.ti-wf-item { display: flex; align-items: flex-start; gap: 10px; line-height: 1.55; }
.ti-wf-ico { font-size: 16px; flex-shrink: 0; }

@media (max-width: 768px) {
    .ti-diktat-prompt { flex-direction: column; align-items: flex-start; padding: 20px; }
    .ti-diktat-prompt-visual { display: none; }
    .ti-stepper { flex-direction: column; align-items: flex-start; gap: 14px; }
    .ti-step-line { display: none; }
}

</style>

<div class="ti-hero">
    <h2>📘 Info Tutorial</h2>
    <p>Jadwal, tutor, dan ruangan kelas tutorial Anda, beserta Diktat LPPAI 2026 untuk dibaca secara daring.</p>
</div>

<div class="ti-tabs" role="tablist">
    <button type="button" class="ti-tab active" data-tab="jadwal">🗓️ Jadwal &amp; Kelas</button>
    <button type="button" class="ti-tab" data-tab="diktat">📖 Diktat Tutorial</button>
</div>

<!-- Panel Jadwal -->
<div class="ti-panel active" data-panel="jadwal">
<?php if (empty($registrations)): ?>
    <div class="ti-empty">
        <div class="big">🏫</div>
        <h3 style="margin:8px 0 6px;">Belum Terdaftar Tutorial</h3>
        <p style="margin:0 0 14px;">Anda belum mendaftar kelas tutorial. Silakan daftar terlebih dahulu.</p>
        <a href="<?= BASE_URL ?>/tutorial-pendaftaran.php" class="btn btn-primary">Daftar Tutorial</a>
    </div>
<?php else: ?>
    <?php foreach ($registrations as $reg):
        [$stLabel, $stColor, $stBg] = $statusBadges[$reg['status']] ?? [ucfirst(str_replace('_', ' ', (string)$reg['status'])), '#1d4ed8', '#dbeafe'];
        $isPlotted = !empty($reg['nama_kelas']) && $reg['status'] !== 'terdaftar';
        $gelLabel = $gelombangs[$reg['gelombang'] ?? ''] ?? ($reg['gelombang'] ?: '');
    ?>
    <div class="ti-class">
        <div class="ti-class-head">
            <h3>Tutorial <?= sanitize($gelLabel ?: 'PAI') ?><?= !empty($reg['tahun_ajaran']) ? ' · ' . sanitize($reg['tahun_ajaran']) : '' ?></h3>
            <span class="ti-badge" style="color:<?= $stColor ?>;background:<?= $stBg ?>;"><?= sanitize($stLabel) ?></span>
        </div>
        <?php if (!$isPlotted): ?>
            <div class="ti-waiting-card">
                <!-- Status & Penjelasan Plotting -->
                <div class="ti-waiting-header">
                    <div class="ti-waiting-icon-pulse">⏳</div>
                    <div class="ti-waiting-header-text">
                        <h4>Pendaftaran Anda Telah Diterima &amp; Sedang Menunggu Jadwal Plotting</h4>
                        <p>Data registrasi kelas tutorial Anda telah tersimpan resmi di sistem LPPAI. Saat ini panitia dan staf Tata Usaha (TU) sedang memproses pembagian rombongan belajar (rombel), penetapan dosen tutor pengampu, serta alokasi ruangan perkuliahan.</p>
                    </div>
                </div>

                <!-- Timeline Progres Tutorial -->
                <div class="ti-stepper">
                    <div class="ti-step completed">
                        <div class="ti-step-circle">✓</div>
                        <div class="ti-step-label">
                            <strong>1. Pendaftaran</strong>
                            <span>Terdaftar resmi</span>
                        </div>
                    </div>
                    <div class="ti-step-line completed"></div>
                    <div class="ti-step active">
                        <div class="ti-step-circle">⏳</div>
                        <div class="ti-step-label">
                            <strong>2. Plotting Kelas</strong>
                            <span class="pulse-text">Sedang Diproses TU</span>
                        </div>
                    </div>
                    <div class="ti-step-line"></div>
                    <div class="ti-step">
                        <div class="ti-step-circle">🏫</div>
                        <div class="ti-step-label">
                            <strong>3. Perkuliahan</strong>
                            <span>Tatap muka di kelas</span>
                        </div>
                    </div>
                    <div class="ti-step-line"></div>
                    <div class="ti-step">
                        <div class="ti-step-circle">🎓</div>
                        <div class="ti-step-label">
                            <strong>4. Kelulusan</strong>
                            <span>Ujian &amp; Sertifikat</span>
                        </div>
                    </div>
                </div>

                <!-- Banner Edukasi: Silakan Pelajari Diktat -->
                <div class="ti-diktat-prompt">
                    <div class="ti-diktat-prompt-left">
                        <div class="ti-diktat-badge">📖 PERSIAPAN BELAJAR MANDIRI</div>
                        <h3>Sambil Menunggu Jadwal Rilis, Silakan Pelajari Diktat Tutorial</h3>
                        <p>Manfaatkan waktu tunggu Anda dengan membaca dan memahami materi <strong>Diktat Resmi LPPAI 2026</strong>. Mempelajari materi dasar lebih awal akan sangat membantu kelancaran praktikum ubudiyah dan pemahaman perkuliahan bersama tutor.</p>
                        
                        <!-- Poin Pokok Materi Diktat -->
                        <div class="ti-diktat-topics">
                            <div class="ti-topic-tag">🕌 Thaharah &amp; Wudhu Sempurna</div>
                            <div class="ti-topic-tag">🤲 Praktik Shalat Fardhu &amp; Jenazah</div>
                            <div class="ti-topic-tag">📿 Dzikir &amp; Wirid Sesudah Shalat</div>
                            <div class="ti-topic-tag">📖 Kaidah Tajwid &amp; Tartil Al-Qur'an</div>
                        </div>

                        <div class="ti-diktat-action">
                            <button type="button" class="btn-diktat-cta" data-open-tab="diktat" onclick="if(window.openTab) window.openTab('diktat')">
                                📖 Buka &amp; Pelajari Diktat Sekarang →
                            </button>
                            <span class="ti-diktat-subnote">Dapat dibaca langsung di peramban pada tab <strong>Diktat Tutorial</strong> tanpa perlu mengunduh file.</span>
                        </div>
                    </div>
                    <div class="ti-diktat-prompt-visual">
                        <div class="ti-diktat-book-card">
                            <div class="ti-diktat-book-title">DIKTAT TUTORIAL</div>
                            <div class="ti-diktat-book-sub">LPPAI 2026</div>
                            <div class="ti-diktat-book-logo">📗</div>
                            <div class="ti-diktat-book-label">Reader Daring Aktif</div>
                        </div>
                    </div>
                </div>

                <!-- Info Box Keterangan Tambahan -->
                <div class="ti-waiting-footer">
                    <div class="ti-wf-item">
                        <span class="ti-wf-ico">💡</span>
                        <span><strong>Kapan jadwal tampil?</strong> Informasi kelas, nama tutor, hari, jam, dan ruangan akan otomatis tampil di kartu ini begitu proses plotting selesai diumumkan oleh TU LPPAI.</span>
                    </div>
                    <div class="ti-wf-item">
                        <span class="ti-wf-ico">💬</span>
                        <span><strong>Perlu bantuan atau informasi tambahan?</strong> Anda dapat menghubungi narahubung resmi LPPAI: 
                            <a href="https://wa.me/6281515726827?text=<?= rawurlencode("Assalamu'alaikum Bu Umami, saya mahasiswa (" . ($user['nama_lengkap'] ?? '') . " - " . ($user['nim'] ?? '') . ") ingin menanyakan informasi plotting jadwal tutorial PAI.") ?>" target="_blank" rel="noopener noreferrer" style="color:#047857;font-weight:700;text-decoration:none;">
                                Bu Umami (081515726827) 💬
                            </a>.
                        </span>
                    </div>
                </div>
            </div>
        <?php else: ?>
            <div class="ti-grid">
                <div class="ti-item"><div class="ti-ico">🏫</div><div><div class="ti-lbl">Kelas</div><div class="ti-val"><?= sanitize($reg['nama_kelas']) ?></div></div></div>
                <div class="ti-item"><div class="ti-ico">👨‍🏫</div><div><div class="ti-lbl">Tutor</div><div class="ti-val"><?= sanitize($reg['dosen_pengampu'] ?: '-') ?></div></div></div>
                <div class="ti-item"><div class="ti-ico">🗓️</div><div><div class="ti-lbl">Jadwal</div><div class="ti-val"><?= sanitize($reg['hari'] ?: '-') ?><?= !empty($reg['jam']) ? ', ' . sanitize($reg['jam']) : '' ?></div></div></div>
                <div class="ti-item"><div class="ti-ico">📍</div><div><div class="ti-lbl">Ruangan</div><div class="ti-val"><?= sanitize($reg['ruangan'] ?: '-') ?></div></div></div>
            </div>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>
<?php endif; ?>
</div>

<!-- Panel Diktat -->
<div class="ti-panel" data-panel="diktat">
<?php if (!$diktatReady): ?>
    <div class="ti-empty"><div class="big">📕</div><h3 style="margin:8px 0 6px;">Diktat Belum Tersedia</h3><p style="margin:0;">Berkas diktat belum diunggah oleh admin.</p></div>
<?php else: ?>
    <div class="dr-wrap" id="dr-wrap" oncontextmenu="return false;">
        <div class="dr-bar">
            <div class="dr-title">📖 <span>Diktat LPPAI 2026</span></div>
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
            <div class="dr-loading" id="dr-loading"><div class="dr-spin"></div>Memuat diktat…</div>
        </div>
    </div>
    <p class="dr-note">🔒 Diktat hanya dapat dibaca di halaman ini dan tidak tersedia untuk diunduh atau dicetak.</p>
<?php endif; ?>
</div>

<?php if ($diktatReady): ?>
<script src="https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js"></script>
<?php endif; ?>
<script>
(function () {
    // ── Tabs ──
    var tabs = document.querySelectorAll('.ti-tab');
    function openTab(name) {
        tabs.forEach(function (t) { t.classList.toggle('active', t.dataset.tab === name); });
        document.querySelectorAll('.ti-panel').forEach(function (p) { p.classList.toggle('active', p.dataset.panel === name); });
        try { localStorage.setItem('tiTab', name); } catch (e) {}
        if (name === 'diktat' && !window.__diktatStarted && window.__startDiktat) { window.__diktatStarted = true; window.__startDiktat(); }
    }
    window.openTab = openTab;

    document.addEventListener('click', function (e) {
        var btn = e.target.closest('[data-open-tab]');
        if (btn) {
            e.preventDefault();
            openTab(btn.dataset.openTab);
            window.scrollTo({ top: 0, behavior: 'smooth' });
        }
    });
    tabs.forEach(function (t) { t.addEventListener('click', function () { openTab(t.dataset.tab); }); });
    var initial = (location.hash === '#diktat') ? 'diktat' : null;
    if (!initial) { try { initial = localStorage.getItem('tiTab'); } catch (e) {} }
    if (initial === 'diktat') openTab('diktat');
})();

<?php if ($diktatReady): ?>
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
    wrap.addEventListener('dragstart', function (e) { e.preventDefault(); });

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

    function zoomTo(s, keepFit) {
        var page = currentPage();
        fitMode = !!keepFit;
        scale = Math.max(0.3, Math.min(3, s));
        layout();
        goTo(page);
    }

    window.__startDiktat = function () {
        if (!window.pdfjsLib) { document.getElementById('dr-loading').textContent = 'Reader gagal dimuat. Periksa koneksi internet lalu muat ulang halaman.'; return; }
        pdfjsLib.GlobalWorkerOptions.workerSrc = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js';

        fetch(STREAM_URL, { headers: { 'X-Diktat-Key': KEY_HEX }, credentials: 'same-origin', cache: 'no-store' })
            .then(function (r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.arrayBuffer(); })
            .then(function (buf) {
                var data = new Uint8Array(buf), key = hexToBytes(KEY_HEX), kl = key.length;
                for (var i = 0; i < data.length; i++) data[i] ^= key[i % kl];
                return pdfjsLib.getDocument({ data: data, isEvalSupported: false }).promise;
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
                view.innerHTML = '';
                for (var i = 1; i <= pdfDoc.numPages; i++) {
                    var box = document.createElement('div');
                    box.className = 'dr-pagebox';
                    box.dataset.page = i;
                    box.innerHTML = '<div class="dr-wm"><span></span></div><div class="dr-num">' + i + '</div>';
                    box.querySelector('.dr-wm span').textContent = WATERMARK;
                    view.appendChild(box);
                    boxes.push(box);
                }
                observer = new IntersectionObserver(function (entries) {
                    entries.forEach(function (en) { if (en.isIntersecting) renderPage(+en.target.dataset.page); });
                }, { root: view, rootMargin: '600px 0px' });
                scale = fitScale();
                layout();
                updateState();
            })
            .catch(function (err) {
                document.getElementById('dr-loading').textContent = 'Diktat gagal dimuat (' + err.message + '). Silakan muat ulang halaman.';
            });
    };

    view.addEventListener('scroll', updateState, { passive: true });
    input.addEventListener('change', function () { goTo(parseInt(input.value, 10)); });
    document.getElementById('dr-prev').addEventListener('click', function () { goTo(currentPage() - 1); });
    document.getElementById('dr-next').addEventListener('click', function () { goTo(currentPage() + 1); });
    document.getElementById('dr-zin').addEventListener('click', function () { zoomTo(scale + 0.15); });
    document.getElementById('dr-zout').addEventListener('click', function () { zoomTo(scale - 0.15); });
    document.getElementById('dr-fit').addEventListener('click', function () { zoomTo(fitScale(), true); });
    document.getElementById('dr-full').addEventListener('click', function () {
        if (document.fullscreenElement) document.exitFullscreen();
        else if (wrap.requestFullscreen) wrap.requestFullscreen();
    });
    var resizeT;
    function refit() { clearTimeout(resizeT); resizeT = setTimeout(function () { if (pdfDoc && fitMode) zoomTo(fitScale(), true); }, 200); }
    window.addEventListener('resize', refit);
    document.addEventListener('fullscreenchange', refit);

    // Jika tab Diktat sudah aktif saat halaman dibuka, langsung muat reader
    var activePanel = document.querySelector('.ti-panel.active');
    if (activePanel && activePanel.dataset.panel === 'diktat' && !window.__diktatStarted) {
        window.__diktatStarted = true;
        window.__startDiktat();
    }
})();
<?php endif; ?>
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
