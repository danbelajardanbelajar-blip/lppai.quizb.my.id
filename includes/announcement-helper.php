<?php
/**
 * LPPAI Corner - Helper untuk halaman pengumuman tutorial
 */

require_once __DIR__ . '/user-helper.php';

if (!function_exists('getAllowedAnnouncementTargetPrograms')) {
    /**
     * Mengembalikan daftar target program mahasiswa yang diizinkan untuk pengumuman.
     * @return array<int, string>
     */
    function getAllowedAnnouncementTargetPrograms(): array {
        return ['Semua', 'RPL', 'Reguler'];
    }
}

if (!function_exists('normalizeAnnouncementTargetProgram')) {
    /**
     * Menormalkan nilai target_program pengumuman agar selalu valid ('Semua', 'RPL', atau 'Reguler').
     */
    function normalizeAnnouncementTargetProgram($value): string {
        $val = strtoupper(trim((string)($value ?? '')));
        if ($val === 'RPL' || str_contains($val, 'RPL')) {
            return 'RPL';
        }
        if ($val === 'REGULER' || str_contains($val, 'REGULER')) {
            return 'Reguler';
        }
        return 'Semua';
    }
}

if (!function_exists('getAllowedAnnouncementTargetPeserta')) {
    /**
     * Mengembalikan daftar pilihan target sasaran peserta beserta labelnya.
     * @return array<string, string>
     */
    function getAllowedAnnouncementTargetPeserta(): array {
        return [
            'semua'              => 'Semua Mahasiswa',
            'pretes'             => 'Peserta Pretes',
            'tutorial'           => 'Peserta Tutorial',
            'belum_lulus_pretes' => 'Khusus Belum Lulus Pretes',
            'lulus_pretes'       => 'Khusus Sudah Lulus Pretes',
        ];
    }
}

if (!function_exists('normalizeAnnouncementTargetPeserta')) {
    /**
     * Menormalkan nilai target_peserta pengumuman.
     */
    function normalizeAnnouncementTargetPeserta($value): string {
        $val = strtolower(trim((string)($value ?? 'semua')));
        $allowed = array_keys(getAllowedAnnouncementTargetPeserta());
        return in_array($val, $allowed, true) ? $val : 'semua';
    }
}

if (!function_exists('renderAnnouncementTargetBadges')) {
    /**
     * Merender badge HTML untuk target program dan target peserta pengumuman.
     */
    function renderAnnouncementTargetBadges(string $targetProgram, string $targetPeserta): string {
        $tp = normalizeAnnouncementTargetProgram($targetProgram);
        $tPes = normalizeAnnouncementTargetPeserta($targetPeserta);

        // Badge Program
        if ($tp === 'RPL') {
            $badgeProg = '<span class="badge" style="background:#f3e8ff; color:#6b21a8; font-weight:700; border:1px solid #d8b4fe;">Khusus RPL</span>';
        } elseif ($tp === 'Reguler') {
            $badgeProg = '<span class="badge" style="background:#e0f2fe; color:#0369a1; font-weight:700; border:1px solid #bae6fd;">Khusus Reguler</span>';
        } else {
            $badgeProg = '<span class="badge" style="background:#f1f5f9; color:#475569; border:1px solid #e2e8f0;">Semua Program</span>';
        }

        // Badge Peserta
        $allLabels = getAllowedAnnouncementTargetPeserta();
        $pesLabel = $allLabels[$tPes] ?? 'Semua Mahasiswa';
        if ($tPes === 'pretes') {
            $badgePes = '<span class="badge" style="background:#fef3c7; color:#92400e; font-size:11px;">' . htmlspecialchars($pesLabel) . '</span>';
        } elseif ($tPes === 'tutorial') {
            $badgePes = '<span class="badge" style="background:#dcfce7; color:#166534; font-size:11px;">' . htmlspecialchars($pesLabel) . '</span>';
        } elseif ($tPes === 'belum_lulus_pretes') {
            $badgePes = '<span class="badge" style="background:#fee2e2; color:#991b1b; font-size:11px;">' . htmlspecialchars($pesLabel) . '</span>';
        } elseif ($tPes === 'lulus_pretes') {
            $badgePes = '<span class="badge" style="background:#d1fae5; color:#065f46; font-size:11px;">' . htmlspecialchars($pesLabel) . '</span>';
        } else {
            $badgePes = '<span class="badge" style="background:#f8fafc; color:#64748b; font-size:11px;">' . htmlspecialchars($pesLabel) . '</span>';
        }

        return '<div style="display:flex; flex-direction:column; gap:4px; align-items:flex-start;">' . $badgeProg . $badgePes . '</div>';
    }
}

if (!function_exists('isUserEligibleForAnnouncement')) {
    /**
     * Memeriksa apakah mahasiswa berhak menerima/melihat pengumuman tertentu
     * berdasarkan Target Program (RPL/Reguler) dan Target Sasaran Peserta.
     */
    function isUserEligibleForAnnouncement(array $user, array $ann, ?array $context = null): bool {
        $userProgram = normalizeJenisProgram($user['jenis_program'] ?? 'Reguler');
        $annProgram  = normalizeAnnouncementTargetProgram($ann['target_program'] ?? 'Semua');

        // 1. Filter Program: Jika pengumuman khusus Reguler / RPL dan berbeda dengan user -> TOLAK
        if ($annProgram !== 'Semua' && $annProgram !== $userProgram) {
            return false;
        }

        // Proteksi bawaan untuk RPL: Pengumuman pendaftaran/kelas tutorial umum adalah untuk Reguler
        $tipe = $ann['tipe'] ?? '';
        $isTutorialType = (
            str_starts_with($tipe, 'pendaftaran_gel') ||
            str_starts_with($tipe, 'pembagian_kelas_') ||
            str_starts_with($tipe, 'kelulusan_gel') ||
            $tipe === 'pendaftaran_mandiri'
        );
        if ($userProgram === 'RPL' && $isTutorialType && $annProgram !== 'RPL') {
            return false;
        }

        // 2. Filter Sasaran Peserta
        $targetPeserta = normalizeAnnouncementTargetPeserta($ann['target_peserta'] ?? 'semua');
        if ($targetPeserta === 'semua') {
            return true;
        }

        $isPretesLulus   = $context['is_pretes_lulus'] ?? null;
        $hasPretesReg    = $context['has_pretes_reg'] ?? null;
        $hasTutorialReg  = $context['has_tutorial_reg'] ?? null;

        if ($targetPeserta === 'lulus_pretes') {
            return $isPretesLulus === true;
        }
        if ($targetPeserta === 'belum_lulus_pretes') {
            return $isPretesLulus === false;
        }
        if ($targetPeserta === 'pretes') {
            return $hasPretesReg === true;
        }
        if ($targetPeserta === 'tutorial') {
            return $hasTutorialReg === true;
        }

        return true;
    }
}

if (!function_exists('ensureAnnouncementTargetColumns')) {
    /**
     * Memastikan kolom target_program dan target_peserta tersedia di tabel announcements.
     */
    function ensureAnnouncementTargetColumns(PDO $pdo): void {
        static $checked = false;
        if ($checked && !empty($_SESSION['_schema_announcement_target_ok'])) {
            return;
        }

        try {
            $driver = strtolower((string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
            if ($driver === 'sqlite') {
                $cols = $pdo->query("PRAGMA table_info(announcements)")->fetchAll(PDO::FETCH_ASSOC);
                if (empty($cols)) return;
                $colNames = array_map(fn($c) => strtolower((string)($c['name'] ?? '')), $cols);
                if (!in_array('target_program', $colNames, true)) {
                    $pdo->exec("ALTER TABLE announcements ADD COLUMN target_program VARCHAR(20) DEFAULT 'Semua'");
                }
                if (!in_array('target_peserta', $colNames, true)) {
                    $pdo->exec("ALTER TABLE announcements ADD COLUMN target_peserta VARCHAR(50) DEFAULT 'semua'");
                }
            } else {
                $tbl = $pdo->query("SHOW TABLES LIKE 'announcements'")->fetch();
                if (!$tbl) return;
                $cProg = $pdo->query("SHOW COLUMNS FROM announcements LIKE 'target_program'")->fetch();
                if (!$cProg) {
                    $pdo->exec("ALTER TABLE announcements ADD COLUMN target_program VARCHAR(20) NOT NULL DEFAULT 'Semua' AFTER tipe");
                }
                $cPes = $pdo->query("SHOW COLUMNS FROM announcements LIKE 'target_peserta'")->fetch();
                if (!$cPes) {
                    $pdo->exec("ALTER TABLE announcements ADD COLUMN target_peserta VARCHAR(50) NOT NULL DEFAULT 'semua' AFTER target_program");
                }
            }

            // Normalisasi pengumuman lawas agar tidak bocor ke mahasiswa RPL
            $pdo->exec("
                UPDATE announcements 
                SET target_program = 'Reguler', target_peserta = 'tutorial'
                WHERE (target_program IS NULL OR target_program = '' OR target_program = 'Semua')
                  AND tipe IN ('pendaftaran_gel1', 'pembagian_kelas_gel1', 'kelulusan_gel1', 'pendaftaran_gel2', 'pembagian_kelas_gel2', 'kelulusan_gel2', 'pendaftaran_mandiri', 'pembagian_kelas_mandiri')
            ");
            $pdo->exec("
                UPDATE announcements 
                SET target_program = 'Reguler', target_peserta = 'pretes'
                WHERE (target_program IS NULL OR target_program = '' OR target_program = 'Semua')
                  AND (tipe = 'hasil_pretes' OR judul LIKE '%Hasil Pretes%')
            ");

            $_SESSION['_schema_announcement_target_ok'] = true;
            $checked = true;
        } catch (Throwable $e) {
            error_log("ensureAnnouncementTargetColumns error: " . $e->getMessage());
        }
    }
}

function renderAnnouncementPage($tipe, $gelombang, $title) {
    $pdo = getDBConnection();
    ensureAnnouncementTargetColumns($pdo);
    $user = getCurrentUser();

    // Get announcements
    $stmt = $pdo->prepare("SELECT * FROM announcements WHERE tipe = ? AND is_active = 1 ORDER BY created_at DESC");
    $stmt->execute([$tipe]);
    $rawAnnouncements = $stmt->fetchAll();
    $announcements = [];
    foreach ($rawAnnouncements as $ra) {
        if (isUserEligibleForAnnouncement($user, $ra)) {
            $announcements[] = $ra;
        }
    }

    // Get classes if applicable (pembagian kelas)
    $classes = [];
    $myClass = null;
    if (strpos($tipe, 'pembagian') !== false) {
        $stmt = $pdo->prepare("SELECT * FROM tutorial_classes WHERE gelombang = ? ORDER BY nama_kelas");
        $stmt->execute([$gelombang]);
        $classes = $stmt->fetchAll();

        // Get user's class assignment
        $stmt = $pdo->prepare("
            SELECT tr.*, tc.nama_kelas, tc.dosen_pengampu, tc.hari, tc.jam, tc.ruangan
            FROM tutorial_registrations tr
            JOIN tutorial_classes tc ON tr.tutorial_class_id = tc.id
            WHERE tr.user_id = ? AND tc.gelombang = ?
        ");
        $stmt->execute([$user['id'], $gelombang]);
        $myClass = $stmt->fetch();
    }

    // Get graduation results if applicable
    $graduationResults = [];
    $myGraduation     = null;
    $nextStepInfo     = null; // CTA data untuk langkah berikutnya

    if (strpos($tipe, 'kelulusan') !== false) {
        $stmt = $pdo->prepare("
            SELECT tr.*, tc.nama_kelas, u.nama_lengkap, u.nim, u.program_studi, u.jenis_program
            FROM tutorial_registrations tr
            JOIN tutorial_classes tc ON tr.tutorial_class_id = tc.id
            JOIN users u ON tr.user_id = u.id
            WHERE tc.gelombang = ? AND tr.status IN ('lulus', 'tidak_lulus')
            ORDER BY tr.status ASC, u.nama_lengkap
        ");
        $stmt->execute([$gelombang]);
        $graduationResults = $stmt->fetchAll();

        $stmt = $pdo->prepare("
            SELECT tr.*, tc.nama_kelas
            FROM tutorial_registrations tr
            JOIN tutorial_classes tc ON tr.tutorial_class_id = tc.id
            WHERE tr.user_id = ? AND tc.gelombang = ?
        ");
        $stmt->execute([$user['id'], $gelombang]);
        $myGraduation = $stmt->fetch();

        // ── CTA: Langkah berikutnya jika tidak lulus ─────────────────────
        if ($myGraduation && $myGraduation['status'] === 'tidak_lulus') {

            if ($gelombang === 'gel1') {
                // Cek apakah TU sudah membuka pendaftaran Gel 2
                $stmtAnnGel2 = $pdo->prepare(
                    "SELECT * FROM announcements
                     WHERE tipe = 'pendaftaran_gel2' AND is_active = 1
                     ORDER BY created_at DESC LIMIT 1"
                );
                $stmtAnnGel2->execute();
                $annGel2Open = $stmtAnnGel2->fetch();

                // Cek apakah mahasiswa sudah terdaftar di gel2
                $stmtRegGel2 = $pdo->prepare(
                    "SELECT tr.id FROM tutorial_registrations tr
                     JOIN tutorial_classes tc ON tr.tutorial_class_id = tc.id
                     WHERE tr.user_id = ? AND tc.gelombang = 'gel2' LIMIT 1"
                );
                $stmtRegGel2->execute([$user['id']]);
                $alreadyGel2 = $stmtRegGel2->fetchColumn();

                $nextStepInfo = [
                    'gelombang'   => 'gel2',
                    'label'       => 'Tutorial Gelombang 2',
                    'url'         => BASE_URL . '/tutorial-gel2-pendaftaran.php',
                    'ann_open'    => $annGel2Open,
                    'already_reg' => $alreadyGel2,
                    'icon'        => '📋',
                    'color'       => '#1a73e8',
                    'bg'          => '#e8f0fe',
                    'border'      => '#1a73e8',
                ];

            } elseif ($gelombang === 'gel2') {
                // Cek apakah TU sudah membuka pendaftaran Mandiri
                $stmtAnnMandiri = $pdo->prepare(
                    "SELECT * FROM announcements
                     WHERE tipe = 'pendaftaran_mandiri' AND is_active = 1
                     ORDER BY created_at DESC LIMIT 1"
                );
                $stmtAnnMandiri->execute();
                $annMandiriOpen = $stmtAnnMandiri->fetch();

                // Cek apakah mahasiswa sudah mengajukan mandiri
                $stmtRegMandiri = $pdo->prepare(
                    "SELECT tr.id FROM tutorial_registrations tr
                     JOIN tutorial_classes tc ON tr.tutorial_class_id = tc.id
                     WHERE tr.user_id = ? AND tc.gelombang = 'mandiri' LIMIT 1"
                );
                $stmtRegMandiri->execute([$user['id']]);
                $alreadyMandiri = $stmtRegMandiri->fetchColumn();

                $nextStepInfo = [
                    'gelombang'   => 'mandiri',
                    'label'       => 'Tutorial Mandiri',
                    'url'         => BASE_URL . '/tutorial-mandiri-pendaftaran.php',
                    'ann_open'    => $annMandiriOpen,
                    'already_reg' => $alreadyMandiri,
                    'icon'        => '📝',
                    'color'       => '#e65100',
                    'bg'          => '#fff3e0',
                    'border'      => '#e65100',
                ];

            } elseif ($gelombang === 'mandiri') {
                // Tutorial Mandiri adalah tahap terakhir — tampilkan panel informasi
                $nextStepInfo = [
                    'gelombang'   => 'final',
                    'label'       => 'Hubungi Admin',
                    'url'         => null,
                    'ann_open'    => null,
                    'already_reg' => null,
                    'icon'        => '📞',
                    'color'       => '#6c757d',
                    'bg'          => '#f8f9fa',
                    'border'      => '#6c757d',
                ];
            }
        }
    }

    ?>
    <!-- Announcements -->
    <?php if (!empty($announcements)): ?>
        <?php foreach ($announcements as $ann): ?>
        <div class="announcement-card">
            <div class="ann-title"><?= sanitize($ann['judul']) ?></div>
            <div class="ann-date">🕐 <?= date('d M Y, H:i', strtotime($ann['created_at'])) ?></div>
            <div class="ann-content"><?= nl2br(sanitize($ann['konten'])) ?></div>
            <?php if (!empty($ann['link_tujuan'])): ?>
            <div style="margin-top: 15px;">
                <a href="<?= BASE_URL . sanitize($ann['link_tujuan']) ?>" class="btn btn-primary" style="display:inline-block; padding:8px 16px; font-size:14px; width:auto; text-decoration:none; background:var(--primary); color:#fff; border-radius:6px; font-weight:600;">
                    Buka Halaman ➔
                </a>
            </div>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    <?php else: ?>
        <div class="card">
            <div class="card-body">
                <div class="empty-state">
                    <div class="icon">📢</div>
                    <h3>Belum ada pengumuman</h3>
                    <p>Pengumuman akan ditampilkan ketika tersedia.</p>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <!-- Class Assignment (Pembagian Kelas) -->
    <?php if (strpos($tipe, 'pembagian') !== false): ?>
        <?php if ($myClass): ?>
        <div class="card" style="border-left:4px solid var(--primary);">
            <div class="card-header">🏫 Kelas Anda</div>
            <div class="card-body">
                <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:16px;">
                    <div>
                        <strong style="color:var(--text-muted);font-size:12px;">KELAS</strong>
                        <p style="font-size:18px;font-weight:700;"><?= sanitize($myClass['nama_kelas']) ?></p>
                    </div>


                    </div>
                    <div>
                        <strong style="color:var(--text-muted);font-size:12px;">DOSEN</strong>
                        <p><?= sanitize($myClass['dosen_pengampu']) ?></p>
                    </div>
                    <div>
                        <strong style="color:var(--text-muted);font-size:12px;">JADWAL</strong>
                        <p><?= sanitize($myClass['hari']) ?>, <?= sanitize($myClass['jam']) ?></p>
                    </div>
                    <div>
                        <strong style="color:var(--text-muted);font-size:12px;">RUANGAN</strong>
                        <p><?= sanitize($myClass['ruangan']) ?></p>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <?php if (!empty($classes)): ?>
        <div class="card">
            <div class="card-header">📋 Daftar Kelas Tutorial</div>
            <div class="card-body">
                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th>Kelas</th>

                                <th>Dosen</th>
                                <th>Hari</th>
                                <th>Jam</th>
                                <th>Ruangan</th>
                                <th>Kuota</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($classes as $c): ?>
                            <tr>
                                <td><?= sanitize($c['nama_kelas']) ?></td>

                                <td><?= sanitize($c['dosen_pengampu']) ?></td>
                                <td><?= sanitize($c['hari']) ?></td>
                                <td><?= sanitize($c['jam']) ?></td>
                                <td><?= sanitize($c['ruangan']) ?></td>
                                <td><?= $c['kuota'] ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php endif; ?>
    <?php endif; ?>

    <!-- Graduation Results -->
    <?php if (strpos($tipe, 'kelulusan') !== false): ?>
        <?php if ($myGraduation): ?>
        <div class="card" style="border-left:4px solid <?= $myGraduation['status'] === 'lulus' ? '#28a745' : '#dc3545' ?>;">
            <div class="card-header">🎓 Status Kelulusan Anda</div>
            <div class="card-body">
                <div style="text-align:center;padding:20px;">
                    <?php if ($myGraduation['status'] === 'lulus'): ?>
                        <span style="font-size:48px;">🎉</span>
                        <h2 style="color:#28a745;margin:10px 0;">SELAMAT, ANDA LULUS!</h2>
                        <p>Kelas: <?= sanitize($myGraduation['nama_kelas']) ?></p>
                        <?php if ($myGraduation['nilai_akhir']): ?>
                        <p style="font-size:24px;font-weight:700;color:var(--primary);">Nilai: <?= number_format($myGraduation['nilai_akhir'], 1) ?></p>
                        <?php endif; ?>
                    <?php elseif ($myGraduation['status'] === 'tidak_lulus'): ?>
                        <span style="font-size:48px;">📚</span>
                        <h2 style="color:#dc3545;margin:10px 0;">BELUM LULUS</h2>
                        <p>Jangan menyerah! Anda masih bisa melanjutkan ke langkah berikutnya.</p>
                    <?php else: ?>
                        <span style="font-size:48px;">⏳</span>
                        <h2 style="color:var(--text-muted);margin:10px 0;">Belum Diumumkan</h2>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <?php
        // ── Panel CTA: Langkah Berikutnya ────────────────────────────────
        if ($nextStepInfo):
            $ns = $nextStepInfo;
        ?>
        <div class="card" style="border:2px solid <?= $ns['border'] ?>;margin-top:16px;">
            <div class="card-header" style="background:<?= $ns['bg'] ?>;color:<?= $ns['color'] ?>;font-weight:700;">
                <?php if ($ns['gelombang'] === 'final'): ?>
                    <?= $ns['icon'] ?> Informasi Selanjutnya
                <?php else: ?>
                    <?= $ns['icon'] ?> Langkah Selanjutnya: Daftar <?= $ns['label'] ?>
                <?php endif; ?>
            </div>
            <div class="card-body">
                <?php if ($ns['gelombang'] === 'final'): ?>
                    <!-- Tahap mandiri = tahap akhir, tidak ada langkah selanjutnya -->
                    <div style="display:flex;align-items:center;gap:16px;flex-wrap:wrap;">
                        <div style="font-size:40px;">📞</div>
                        <div style="flex:1;">
                            <h3 style="margin:0 0 6px;color:#6c757d;">Tutorial Mandiri adalah Tahap Terakhir</h3>
                            <p style="color:var(--text-muted);margin:0 0 8px;">
                                Anda telah mengikuti seluruh tahapan tutorial. Silakan hubungi admin LPPAI untuk informasi lebih lanjut mengenai status kelulusan Anda.
                            </p>
                            <div style="padding:10px 14px;background:#f8f9fa;border-radius:8px;font-size:13px;color:#6c757d;border:1px solid #dee2e6;">
                                💡 Jika ada pertanyaan, hubungi Lembaga Pengembangan Pendidikan Agama Islam (LPPAI) secara langsung.
                            </div>
                        </div>
                    </div>

                <?php elseif ($ns['already_reg']): ?>
                    <!-- Sudah terdaftar di langkah berikutnya -->
                    <div style="display:flex;align-items:center;gap:16px;flex-wrap:wrap;">
                        <div style="font-size:40px;">✅</div>
                        <div>
                            <h3 style="margin:0 0 6px;color:#28a745;">Anda Sudah Terdaftar</h3>
                            <p style="color:var(--text-muted);margin:0;">Anda sudah mendaftarkan diri ke <?= $ns['label'] ?>. Silakan cek halaman <?= $ns['label'] ?> untuk detail jadwal.</p>
                        </div>
                        <a href="<?= $ns['url'] ?>" class="btn btn-primary" style="width:auto;margin-left:auto;background:<?= $ns['color'] ?>;border-color:<?= $ns['color'] ?>;">
                            <?= $ns['icon'] ?> Lihat <?= $ns['label'] ?>
                        </a>
                    </div>

                <?php elseif ($ns['ann_open']): ?>
                    <!-- Pendaftaran sudah dibuka, belum daftar -->
                    <div style="display:flex;align-items:flex-start;gap:16px;flex-wrap:wrap;">
                        <div style="font-size:40px;"><?= $ns['icon'] ?></div>
                        <div style="flex:1;">
                            <h3 style="margin:0 0 6px;color:<?= $ns['color'] ?>;">
                                Pendaftaran <?= $ns['label'] ?> Sudah Dibuka!
                            </h3>
                            <p style="color:var(--text-muted);margin:0 0 8px;">
                                <?= sanitize($ns['ann_open']['judul']) ?><br>
                                <small>🕐 Dibuka: <?= date('d M Y', strtotime($ns['ann_open']['created_at'])) ?></small>
                            </p>
                            <div style="padding:10px 14px;background:<?= $ns['bg'] ?>;border-radius:8px;font-size:13px;color:<?= $ns['color'] ?>;">
                                ⚠️ Segera daftarkan diri Anda sebelum pendaftaran ditutup.
                            </div>
                        </div>
                        <a href="<?= $ns['url'] ?>" class="btn btn-primary"
                           style="width:auto;background:<?= $ns['color'] ?>;border-color:<?= $ns['color'] ?>;white-space:nowrap;">
                            <?= $ns['icon'] ?> Daftar <?= $ns['label'] ?> Sekarang
                        </a>
                    </div>

                <?php else: ?>
                    <!-- Pendaftaran belum dibuka -->
                    <div style="display:flex;align-items:center;gap:16px;flex-wrap:wrap;">
                        <div style="font-size:40px;">🔒</div>
                        <div style="flex:1;">
                            <h3 style="margin:0 0 6px;color:#6c757d;">Pendaftaran <?= $ns['label'] ?> Belum Dibuka</h3>
                            <p style="color:var(--text-muted);margin:0;">TU akan segera membuka pendaftaran <?= $ns['label'] ?>. Pantau terus halaman ini.</p>
                        </div>
                        <a href="<?= $ns['url'] ?>" class="btn btn-primary"
                           style="width:auto;background:#6c757d;border-color:#6c757d;white-space:nowrap;">
                            <?= $ns['icon'] ?> Lihat <?= $ns['label'] ?>
                        </a>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; // end nextStepInfo ?>
        <?php endif; ?>

        <?php if (!empty($graduationResults)): ?>
        <div class="card">
            <div class="card-header">📋 Daftar Kelulusan</div>
            <div class="card-body">
                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th>No</th>
                                <th>Nama</th>
                                <th>NIM</th>
                                <th>Program Studi</th>
                                <th>Kelas</th>
                                <th>Nilai</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($graduationResults as $i => $r): ?>
                            <tr>
                                <td><?= $i + 1 ?></td>
                                <td><?= sanitize($r['nama_lengkap']) ?></td>
                                <td><?= sanitize($r['nim']) ?></td>
                                <td><?= formatProdiDisplay($r['program_studi'] ?? '', $r['jenis_program'] ?? 'Reguler', true) ?></td>
                                <td><?= sanitize($r['nama_kelas']) ?></td>
                                <td><strong><?= $r['nilai_akhir'] ? number_format($r['nilai_akhir'], 1) : '-' ?></strong></td>
                                <td>
                                    <span class="badge <?= $r['status'] === 'lulus' ? 'badge-success' : 'badge-danger' ?>">
                                        <?= ucfirst(str_replace('_', ' ', $r['status'])) ?>
                                    </span>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php endif; ?>
    <?php endif; ?>
    <?php
}
