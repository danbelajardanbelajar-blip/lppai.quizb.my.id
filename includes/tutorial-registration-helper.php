<?php
/**
 * LPPAI Corner - Helper Pendaftaran Tutorial
 * Menggabungkan alur pendaftaran untuk semua gelombang tutorial.
 */

function renderTutorialRegistration($gelombang, $tipePengumuman, $pageTitle) {
    $user    = getCurrentUser();
    $pdo     = getDBConnection();
    $message = '';
    $msgType = '';

    // ── 1. Cek apakah TU sudah membuka pendaftaran ─────────────────────────────
    $stmtAnn = $pdo->prepare(
        "SELECT * FROM announcements
         WHERE tipe = ? AND is_active = 1
         ORDER BY created_at DESC LIMIT 1"
    );
    $stmtAnn->execute([$tipePengumuman]);
    $announcement = $stmtAnn->fetch();

    // ── 2. Cek apakah sudah terdaftar di gelombang ini ─────────────────────────
    $stmtReg = $pdo->prepare(
        "SELECT tr.*, tc.nama_kelas, tc.mata_kuliah, tc.dosen_pengampu, tc.hari, tc.jam, tc.ruangan
         FROM tutorial_registrations tr
         JOIN tutorial_classes tc ON tr.tutorial_class_id = tc.id
         WHERE tr.user_id = ? AND tc.gelombang = ?
         ORDER BY tr.created_at DESC LIMIT 1"
    );
    $stmtReg->execute([$user['id'], $gelombang]);
    $sudahDaftar = $stmtReg->fetch();

    // ── 3. Ambil kelas yang tersedia untuk gelombang ini ───────────────────────
    $stmtKelas = $pdo->prepare(
        "SELECT * FROM tutorial_classes WHERE gelombang = ? ORDER BY nama_kelas"
    );
    $stmtKelas->execute([$gelombang]);
    $kelasTersedia = $stmtKelas->fetchAll();

    // ── 4. Handle form submission ──────────────────────────────────────────────
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['daftar_tutorial'])) {
        $token   = $_POST['csrf_token'] ?? '';
        $classId = (int)($_POST['class_id'] ?? 0);

        if (!verifyCsrf($token)) {
            $message = 'Sesi tidak valid. Silakan muat ulang halaman.';
            $msgType = 'danger';
        } elseif (!$announcement) {
            $message = 'Pendaftaran tutorial belum dibuka oleh TU.';
            $msgType = 'danger';
        } elseif ($sudahDaftar) {
            $message = 'Anda sudah terdaftar di tutorial ini.';
            $msgType = 'warning';
        } elseif ($classId <= 0) {
            $message = 'Pilih kelas tutorial terlebih dahulu.';
            $msgType = 'danger';
        } else {
            // Validasi kelas ada dan gelombang-nya sesuai
            $stmtCek = $pdo->prepare(
                "SELECT * FROM tutorial_classes WHERE id = ? AND gelombang = ?"
            );
            $stmtCek->execute([$classId, $gelombang]);
            $kelasTarget = $stmtCek->fetch();

            if (!$kelasTarget) {
                $message = 'Kelas yang dipilih tidak ditemukan.';
                $msgType = 'danger';
            } else {
                // Cek apakah sudah pernah daftar di kelas ini (redundant fallback)
                $stmtDuplikat = $pdo->prepare(
                    "SELECT id FROM tutorial_registrations WHERE user_id = ? AND tutorial_class_id = ?"
                );
                $stmtDuplikat->execute([$user['id'], $classId]);
                if ($stmtDuplikat->fetch()) {
                    $message = 'Anda sudah terdaftar di kelas ini sebelumnya.';
                    $msgType = 'warning';
                } else {
                    $stmtInsert = $pdo->prepare(
                        "INSERT INTO tutorial_registrations (user_id, tutorial_class_id, status)
                         VALUES (?, ?, 'terdaftar')"
                    );
                    $stmtInsert->execute([$user['id'], $classId]);
                    $message = 'Pendaftaran tutorial berhasil! Silakan tunggu konfirmasi jadwal dari TU.';
                    $msgType = 'success';

                    // Refresh data
                    $stmtReg->execute([$user['id'], $gelombang]);
                    $sudahDaftar = $stmtReg->fetch();
                }
            }
        }
    }

    // Render HTML UI
    ?>
    <div class="mb-4">
        <h2 class="page-title"><?= htmlspecialchars($pageTitle) ?></h2>
    </div>

    <?php if ($message): ?>
        <div class="alert alert-<?= $msgType ?>"><?= $message ?></div>
    <?php endif; ?>

    <!-- ── Pengumuman TU ──────────────────────────────────────────── -->
    <?php if ($announcement): ?>
    <div class="announcement-card" style="margin-bottom:24px;">
        <div class="ann-title"><?= sanitize($announcement['judul']) ?></div>
        <div class="ann-date">🕐 <?= date('d M Y, H:i', strtotime($announcement['created_at'])) ?></div>
        <div class="ann-content"><?= nl2br(sanitize($announcement['konten'])) ?></div>
    </div>
    <?php else: ?>
    <div class="card" style="margin-bottom:24px;">
        <div class="card-body">
            <div class="empty-state">
                <div class="icon">🔒</div>
                <h3>Pendaftaran Belum Dibuka</h3>
                <p>TU belum membuka pendaftaran tutorial. Silakan cek kembali nanti.</p>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- ── Sudah Terdaftar ────────────────────────────────────────── -->
    <?php if ($sudahDaftar): ?>
    <div class="card" style="border-left:4px solid var(--primary);margin-bottom:24px;">
        <div class="card-header">✅ Pendaftaran Tutorial Anda</div>
        <div class="card-body">
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:16px;">
                <div>
                    <strong style="color:var(--text-muted);font-size:12px;">KELAS</strong>
                    <p style="font-size:18px;font-weight:700;margin-top:4px;"><?= sanitize($sudahDaftar['nama_kelas']) ?></p>
                </div>
                <div>
                    <strong style="color:var(--text-muted);font-size:12px;">MATA KULIAH</strong>
                    <p style="font-size:15px;margin-top:4px;"><?= sanitize($sudahDaftar['mata_kuliah']) ?></p>
                </div>
                <div>
                    <strong style="color:var(--text-muted);font-size:12px;">DOSEN</strong>
                    <p style="font-size:15px;margin-top:4px;"><?= sanitize($sudahDaftar['dosen_pengampu']) ?></p>
                </div>
                <div>
                    <strong style="color:var(--text-muted);font-size:12px;">JADWAL</strong>
                    <p style="font-size:15px;margin-top:4px;"><?= sanitize($sudahDaftar['hari']) ?>, <?= sanitize($sudahDaftar['jam']) ?></p>
                </div>
                <div>
                    <strong style="color:var(--text-muted);font-size:12px;">RUANGAN</strong>
                    <p style="font-size:15px;margin-top:4px;"><?= sanitize($sudahDaftar['ruangan']) ?></p>
                </div>
                <div>
                    <strong style="color:var(--text-muted);font-size:12px;">STATUS</strong>
                    <p style="margin-top:4px;">
                        <?php
                        $statusBadge = [
                            'terdaftar'         => 'badge-info',
                            'aktif'             => 'badge-primary',
                            'lulus'             => 'badge-success',
                            'tidak_lulus'       => 'badge-danger',
                            'mengundurkan_diri' => 'badge-warning',
                        ];
                        $badge = $statusBadge[$sudahDaftar['status']] ?? 'badge-info';
                        ?>
                        <span class="badge <?= $badge ?>" style="font-size:13px;padding:5px 14px;">
                            <?= ucfirst(str_replace('_', ' ', $sudahDaftar['status'])) ?>
                        </span>
                    </p>
                </div>
            </div>
            <div class="alert alert-success" style="margin-top:16px;margin-bottom:0;">
                🎉 Anda sudah terdaftar di tutorial ini. Tunggu jadwal resmi dari TU.
            </div>
        </div>
    </div>

    <!-- ── Form Pendaftaran ────────────────────────────────────────── -->
    <?php elseif ($announcement): ?>
    <div class="card" style="margin-bottom:24px;">
        <div class="card-header">📝 Form Pendaftaran Tutorial</div>
        <div class="card-body">
            <div class="alert alert-info" style="margin-bottom:20px;">
                ℹ️ Pilih kelas yang tersedia sesuai jadwal yang bisa Anda ikuti. Setelah mendaftar, TU akan mengkonfirmasi penempatan Anda.
            </div>

            <?php if (empty($kelasTersedia)): ?>
                <div class="empty-state">
                    <div class="icon">📋</div>
                    <h3>Belum ada kelas tersedia</h3>
                    <p>TU belum menambahkan kelas tutorial untuk gelombang ini. Silakan cek kembali nanti.</p>
                </div>
            <?php else: ?>
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">

                <div class="form-group">
                    <label style="font-weight:600;margin-bottom:12px;display:block;">
                        Pilih Kelas Tutorial <span style="color:#dc3545;">*</span>
                    </label>
                    <div style="display:grid;gap:12px;">
                        <?php foreach ($kelasTersedia as $k): ?>
                        <label style="
                            display:flex;align-items:flex-start;gap:14px;
                            padding:16px 20px;
                            border:2px solid #e0e0e0;
                            border-radius:12px;
                            cursor:pointer;
                            transition:all .2s;
                            background:#fff;
                        " onmouseover="this.style.borderColor='var(--primary)';this.style.background='#f0f7ff'"
                           onmouseout="this.style.borderColor='#e0e0e0';this.style.background='#fff'">
                            <input type="radio" name="class_id" value="<?= $k['id'] ?>" required
                                   style="margin-top:3px;accent-color:var(--primary);width:18px;height:18px;">
                            <div style="flex:1;">
                                <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:6px;">
                                    <strong style="font-size:16px;"><?= sanitize($k['nama_kelas']) ?></strong>
                                    <span class="badge badge-primary"><?= sanitize($k['mata_kuliah']) ?></span>
                                </div>
                                <div style="display:flex;gap:20px;flex-wrap:wrap;color:var(--text-muted);font-size:13px;">
                                    <span>👨‍🏫 <?= sanitize($k['dosen_pengampu'] ?: '-') ?></span>
                                    <span>📅 <?= sanitize($k['hari'] ?: '-') ?>, <?= sanitize($k['jam'] ?: '-') ?></span>
                                    <span>🏫 <?= sanitize($k['ruangan'] ?: '-') ?></span>
                                    <span>👥 Kuota: <?= $k['kuota'] ?></span>
                                </div>
                            </div>
                        </label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div style="margin-top:8px;padding:14px;background:#fff3cd;border-radius:10px;font-size:13px;color:#856404;">
                    ⚠️ Pastikan jadwal yang dipilih tidak berbenturan dengan jadwal kuliah Anda. Pilihan tidak dapat diubah setelah dikirim.
                </div>

                <div style="margin-top:20px;">
                    <button type="submit" name="daftar_tutorial" class="btn btn-primary" style="width:auto;padding:12px 32px;">
                        📝 Kirim Pendaftaran Tutorial
                    </button>
                </div>
            </form>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
    <?php
}
