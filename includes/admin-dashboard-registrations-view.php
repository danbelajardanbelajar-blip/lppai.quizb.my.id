<?php /** All active periods remain visible together, including empty periods. */ ?>
<div class="card" style="margin-top:24px;" id="dashboard-recent-registrations">
    <div class="card-header">📋 Pendaftaran Terbaru</div>
    <div class="card-body">
    <?php if (!$registrationGroups): ?>
        <p class="empty-state">Belum ada periode tutorial atau pretes yang aktif.</p>
    <?php endif; ?>
    <?php foreach ($registrationGroups as $registrationGroup): ?>
        <section class="dashboard-registration-period" style="margin-bottom:24px;min-width:0;" aria-labelledby="registration-<?= sanitize($registrationGroup['type']) ?>-<?= (int)$registrationGroup['period']['id'] ?>">
            <h3 id="registration-<?= sanitize($registrationGroup['type']) ?>-<?= (int)$registrationGroup['period']['id'] ?>" style="font-size:16px;overflow-wrap:anywhere;">
                <?= $registrationGroup['type'] === 'tutorial' ? '📚 Tutorial' : '✍️ Pretes' ?> — <?= sanitize($registrationGroup['label']) ?>
                <span class="badge badge-success">Aktif</span>
            </h3>
            <div style="display:flex;justify-content:space-between;gap:8px;flex-wrap:wrap;margin-bottom:14px;">
                <span style="font-size:13px;color:#64748b;">Maksimal 5 pendaftaran terbaru pada periode ini.</span>
                <a class="btn btn-sm btn-outline-primary" href="<?= BASE_URL ?>/admin/<?= $registrationGroup['type'] === 'tutorial' ? 'tutorial-data-pendaftar.php?gelombang_id=' . (int)$registrationGroup['period']['id'] : 'pretes-peserta.php?periode=' . rawurlencode($registrationGroup['period']['periode']) ?>">Buka Data Lengkap <?= $registrationGroup['type'] === 'tutorial' ? 'Pendaftar Tutorial' : 'Peserta Pretes' ?> &rarr;</a>
            </div>
            <?php if ($registrationGroup['error']): ?>
                <p role="status">Pendaftaran periode ini belum dapat dimuat. Silakan muat ulang halaman.</p>
            <?php elseif ($registrationGroup['type'] === 'tutorial'): ?>
            <?php if (empty($registrationGroup['rows'])): ?>
                <div class="empty-state" style="text-align:center; padding:32px;">
                    <div style="font-size:40px; margin-bottom:8px;">📚</div>
                    <h3 style="color:#64748b; font-size:16px;">Belum ada pendaftaran tutorial pada periode ini</h3>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table" style="width:100%; border-collapse:collapse;">
                        <thead>
                            <tr style="background:#f8fafc; text-align:left; border-bottom:2px solid #e2e8f0;">
                                <th style="padding:10px 14px;">Nama Mahasiswa</th>
                                <th style="padding:10px 14px;">NIM</th>
                                <th style="padding:10px 14px;">Program Studi</th>
                                <th style="padding:10px 14px;">Program</th>
                                <th style="padding:10px 14px;">Pilihan Hari / Kelas</th>
                                <th style="padding:10px 14px;">Tgl Daftar</th>
                                <th style="padding:10px 14px;">Status Plotting</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($registrationGroup['rows'] as $r): ?>
                            <tr style="border-bottom:1px solid #e2e8f0;">
                                <td style="padding:10px 14px; font-weight:600; color:#1e293b;">
                                    <?= adminUserProfileLink($r['nama_lengkap'], $r['user_id'] ?? null) ?>
                                    <?php $waUrl = formatWaDashboard($r['no_hp'] ?? ''); ?>
                                    <?php if (!empty($waUrl)): ?>
                                        <a href="<?= $waUrl ?>" target="_blank" rel="noopener noreferrer" title="Chat WhatsApp" style="margin-left:6px; color:#16a34a; text-decoration:none; font-size:13px;" onclick="event.stopPropagation();">
                                            💬
                                        </a>
                                    <?php endif; ?>
                                </td>
                                <td style="padding:10px 14px; color:#475569;"><?= sanitize($r['nim']) ?></td>
                                <td style="padding:10px 14px; color:#475569;"><?= sanitize($r['program_studi'] ?: '-') ?></td>
                                <td style="padding:10px 14px;"><?= renderJenisProgramBadge($r['jenis_program'] ?? 'Reguler', true) ?></td>
                                <td style="padding:10px 14px;">
                                    <?php if (!empty($r['nama_kelas'])): ?>
                                        <span class="badge" style="background:#dbeafe; color:#1e40af; padding:4px 8px; border-radius:6px; font-weight:600; font-size:12px;">
                                            🏫 <?= sanitize($r['nama_kelas']) ?> (<?= sanitize($r['kelas_hari'] ?: $r['hari_pilihan']) ?>)
                                        </span>
                                    <?php elseif (!empty($r['hari_pilihan'])): ?>
                                        <span class="badge" style="background:#f1f5f9; color:#334155; padding:4px 8px; border-radius:6px; font-weight:600; font-size:12px;">
                                            📅 Hari: <?= ucfirst(sanitize($r['hari_pilihan'])) ?>
                                        </span>
                                    <?php else: ?>
                                        <span style="color:#94a3b8; font-size:12px;">-</span>
                                    <?php endif; ?>
                                </td>
                                <td style="padding:10px 14px; color:#64748b; font-size:12px;">
                                    <?= !empty($r['created_at']) ? date('d M Y H:i', strtotime($r['created_at'])) : '-' ?>
                                </td>
                                <td style="padding:10px 14px;">
                                    <?php if (!empty($r['tutorial_class_id'])): ?>
                                        <span class="badge" style="background:#dcfce7; color:#15803d; padding:4px 8px; border-radius:6px; font-weight:600; font-size:11.5px;">
                                            ✓ Sudah Diplot
                                        </span>
                                    <?php else: ?>
                                        <span class="badge" style="background:#fef3c7; color:#b45309; padding:4px 8px; border-radius:6px; font-weight:600; font-size:11.5px;">
                                            ⏳ Menunggu Plotting
                                        </span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
            <?php else: ?>
            <?php if (empty($registrationGroup['rows'])): ?>
                <div class="empty-state" style="text-align:center; padding:32px;">
                    <div style="font-size:40px; margin-bottom:8px;">📋</div>
                    <h3 style="color:#64748b; font-size:16px;">Belum ada pendaftaran pretes pada periode ini</h3>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table" style="width:100%; border-collapse:collapse;">
                        <thead>
                            <tr style="background:#f8fafc; text-align:left; border-bottom:2px solid #e2e8f0;">
                                <th style="padding:10px 14px;">Nama Mahasiswa</th>
                                <th style="padding:10px 14px;">NIM</th>
                                <th style="padding:10px 14px;">Program Studi</th>
                                <th style="padding:10px 14px;">Program</th>
                                <th style="padding:10px 14px;">Periode Pretes</th>
                                <th style="padding:10px 14px;">Pilihan Hari</th>
                                <th style="padding:10px 14px;">Tgl Daftar</th>
                                <th style="padding:10px 14px;">Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($registrationGroup['rows'] as $r): ?>
                            <tr style="border-bottom:1px solid #e2e8f0;">
                                <td style="padding:10px 14px; font-weight:600; color:#1e293b;">
                                    <?= adminUserProfileLink($r['nama_lengkap'], $r['user_id'] ?? null) ?>
                                    <?php $waUrl = formatWaDashboard($r['no_hp'] ?? ''); ?>
                                    <?php if (!empty($waUrl)): ?>
                                        <a href="<?= $waUrl ?>" target="_blank" rel="noopener noreferrer" title="Chat WhatsApp" style="margin-left:6px; color:#16a34a; text-decoration:none; font-size:13px;" onclick="event.stopPropagation();">
                                            💬
                                        </a>
                                    <?php endif; ?>
                                </td>
                                <td style="padding:10px 14px; color:#475569;"><?= sanitize($r['nim']) ?></td>
                                <td style="padding:10px 14px; color:#475569;"><?= sanitize($r['program_studi'] ?: '-') ?></td>
                                <td style="padding:10px 14px;"><?= renderJenisProgramBadge($r['jenis_program'] ?? 'Reguler', true) ?></td>
                                <td style="padding:10px 14px; color:#475569;"><?= sanitize($r['periode']) ?></td>
                                <td style="padding:10px 14px;">
                                    <?php if (!empty($r['hari_pilihan'])): ?>
                                        <span class="badge" style="background:#f1f5f9; color:#334155; padding:4px 8px; border-radius:6px; font-weight:600; font-size:12px;">
                                            📅 <?= ucfirst(sanitize($r['hari_pilihan'])) ?>
                                        </span>
                                    <?php else: ?>
                                        <span style="color:#94a3b8; font-size:12px;">-</span>
                                    <?php endif; ?>
                                </td>
                                <td style="padding:10px 14px; color:#64748b; font-size:12px;">
                                    <?= !empty($r['tanggal_daftar']) ? date('d M Y H:i', strtotime($r['tanggal_daftar'])) : (!empty($r['created_at']) ? date('d M Y H:i', strtotime($r['created_at'])) : '-') ?>
                                </td>
                                <td style="padding:10px 14px;">
                                    <span class="badge badge-success"><?= ucfirst(sanitize($r['status'] ?? 'terdaftar')) ?></span>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
            <?php endif; ?>
        </section>
    <?php endforeach; ?>
    </div>
</div>
