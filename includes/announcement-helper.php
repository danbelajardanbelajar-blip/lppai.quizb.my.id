<?php
/**
 * LPPAI Corner - Helper untuk halaman pengumuman tutorial
 */

function renderAnnouncementPage($tipe, $gelombang, $title) {
    $pdo = getDBConnection();
    $user = getCurrentUser();

    // Get announcements
    $stmt = $pdo->prepare("SELECT * FROM announcements WHERE tipe = ? AND is_active = 1 ORDER BY created_at DESC");
    $stmt->execute([$tipe]);
    $announcements = $stmt->fetchAll();

    // Get classes if applicable (pembagian kelas)
    $classes = [];
    $myClass = null;
    if (strpos($tipe, 'pembagian') !== false) {
        $stmt = $pdo->prepare("SELECT * FROM tutorial_classes WHERE gelombang = ? ORDER BY nama_kelas");
        $stmt->execute([$gelombang]);
        $classes = $stmt->fetchAll();

        // Get user's class assignment
        $stmt = $pdo->prepare("
            SELECT tr.*, tc.nama_kelas, tc.mata_kuliah, tc.dosen_pengampu, tc.hari, tc.jam, tc.ruangan
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
            SELECT tr.*, tc.nama_kelas, tc.mata_kuliah, u.nama_lengkap, u.nim, u.program_studi
            FROM tutorial_registrations tr
            JOIN tutorial_classes tc ON tr.tutorial_class_id = tc.id
            JOIN users u ON tr.user_id = u.id
            WHERE tc.gelombang = ? AND tr.status IN ('lulus', 'tidak_lulus')
            ORDER BY tr.status ASC, u.nama_lengkap
        ");
        $stmt->execute([$gelombang]);
        $graduationResults = $stmt->fetchAll();

        $stmt = $pdo->prepare("
            SELECT tr.*, tc.nama_kelas, tc.mata_kuliah
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
                    <div>
                        <strong style="color:var(--text-muted);font-size:12px;">MATA KULIAH</strong>
                        <p style="font-size:16px;"><?= sanitize($myClass['mata_kuliah']) ?></p>
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
                                <th>Mata Kuliah</th>
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
                                <td><?= sanitize($c['mata_kuliah']) ?></td>
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
                        <p>Kelas: <?= sanitize($myGraduation['nama_kelas']) ?> - <?= sanitize($myGraduation['mata_kuliah']) ?></p>
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
                                <td><?= sanitize($r['program_studi']) ?></td>
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
