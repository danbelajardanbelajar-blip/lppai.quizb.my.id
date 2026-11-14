<?php
/**
 * LPPAI Corner - Kartu "Menunggu Plotting Tutorial" (dipakai bersama)
 *
 * Mahasiswa yang sudah terdaftar tutorial (status terdaftar/aktif) tetapi belum diplot
 * ke kelas melihat kartu yang sama dengan halaman Info Tutorial di:
 * tutorial-pengumuman, rekap-nilai, ambil-sertifikat.
 * Markup & gaya disalin dari pages/tutorial-info.php (blok .ti-waiting-card);
 * bila tampilan di Info Tutorial diubah, sesuaikan juga berkas ini.
 */

if (!function_exists('getStudentTutorialWaiting')) {
    /** Pendaftaran tutorial terakhir yang masih berjalan namun belum diplot, atau null. */
    function getStudentTutorialWaiting(PDO $pdo, array $user): ?array
    {
        $userId = (int)($user['id'] ?? 0);
        if ($userId <= 0) return null;
        try {
            $stmt = $pdo->prepare("
                SELECT tr.id, tr.user_id, tr.tutorial_class_id, tr.status, tr.tahun_ajaran, tc.nama_kelas,
                       COALESCE(NULLIF(tc.gelombang, ''), tr.gelombang) AS gelombang
                FROM tutorial_registrations tr
                LEFT JOIN tutorial_classes tc ON tr.tutorial_class_id = tc.id
                WHERE tr.user_id = ?
                ORDER BY tr.created_at DESC
                LIMIT 1
            ");
            $stmt->execute([$userId]);
            $reg = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        } catch (Throwable $e) {
            return null;
        }
        if (!$reg || !in_array($reg['status'] ?? '', ['terdaftar', 'aktif'], true)) return null;
        // Mahasiswa dianggap sudah diplot bila sudah memiliki kelas (nama_kelas / tutorial_class_id terisi)
        $isPlotted = !empty($reg['nama_kelas']) || !empty($reg['tutorial_class_id']);
        return $isPlotted ? null : $reg;
    }
}

if (!function_exists('renderTutorialWaitingCard')) {
    function renderTutorialWaitingCard(array $reg, array $user, string $note = ''): void
    {
        $gelombangs = ['gel1' => 'Gelombang 1 (Ganjil)', 'gel2' => 'Gelombang 2 (Genap)', 'mandiri' => 'Mandiri'];
        $gelLabel = $gelombangs[$reg['gelombang'] ?? ''] ?? ($reg['gelombang'] ?: '');
        $e = function ($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); };
        ?>
<style>
.ti-class-wrap { max-width: 980px; margin: 0 auto; }
.ti-class { background:#fff; border:1px solid #e5e7eb; border-radius:16px; overflow:hidden; margin-bottom:16px; box-shadow:0 4px 14px rgba(15,23,42,.04); }
.ti-class-head { display:flex; justify-content:space-between; align-items:center; gap:10px; padding:14px 18px; background:#f8fafc; border-bottom:1px solid #eef2f7; flex-wrap:wrap; }
.ti-class-head h3 { margin:0; font-size:15.5px; font-weight:800; color:#0f172a; }
.ti-badge { font-size:12px; font-weight:700; padding:4px 10px; border-radius:999px; }
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
.ti-wait-note { background:#eff6ff; border:1px solid #bfdbfe; color:#1e3a8a; border-radius:12px; padding:12px 16px; margin-bottom:16px; font-size:13.5px; line-height:1.6; }
</style>
<div class="ti-class-wrap">
    <?php if ($note !== ''): ?><div class="ti-wait-note">ℹ️ <?= $note ?></div><?php endif; ?>
    <div class="ti-class">
        <div class="ti-class-head">
            <h3>Tutorial <?= $e($gelLabel ?: 'PAI') ?><?= !empty($reg['tahun_ajaran']) ? ' · ' . $e($reg['tahun_ajaran']) : '' ?></h3>
            <span class="ti-badge" style="color:#b45309;background:#fef3c7;">Menunggu Plotting</span>
        </div>
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
                    <a href="<?= BASE_URL ?>/tutorial-info.php#diktat" class="btn-diktat-cta">
                        📖 Buka &amp; Pelajari Diktat Sekarang →
                    </a>
                    <span class="ti-diktat-subnote">Dapat dibaca langsung di peramban pada menu <strong>Info Tutorial › Diktat Tutorial</strong> tanpa perlu mengunduh file.</span>
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
    </div>
</div>
        <?php
    }
}

if (!function_exists('tutorialWaitingGate')) {
    /** Bila mahasiswa terdaftar tutorial tetapi belum diplot: tampilkan kartu menunggu plotting lalu hentikan halaman. */
    function tutorialWaitingGate(PDO $pdo, ?array $user, string $note = ''): void
    {
        if (!$user || ($user['role'] ?? 'mahasiswa') !== 'mahasiswa') return;
        $reg = getStudentTutorialWaiting($pdo, $user);
        if (!$reg) return;
        // Mahasiswa yang sudah lulus pretes dibebaskan dari tutorial → jangan ditahan kartu plotting
        require_once __DIR__ . '/pretes-helper.php';
        if (isUserLulusPretes($pdo, (int)$user['id'])) return;

        if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest' || stripos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false) {
            http_response_code(403);
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Kelas tutorial Anda masih menunggu plotting.']);
            exit;
        }

        include __DIR__ . '/header.php';
        renderTutorialWaitingCard($reg, $user, $note);
        include __DIR__ . '/footer.php';
        exit;
    }
}
