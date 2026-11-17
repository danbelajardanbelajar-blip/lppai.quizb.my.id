<link rel="stylesheet" href="<?= BASE_URL ?>/pretes-ujian.php?cbt_style=bank&amp;v=<?= filemtime(__DIR__ . '/../assets/css/pretes-cbt-admin.css') ?>">
<div class="bank-app">
    <header class="bank-hero">
        <div>
            <div class="bank-eyebrow">LPPAI / Pusat Asesmen &amp; Pengatur Sesi CBT</div>
            <h2>Bank, Paket &amp; Pengatur Sesi CBT Pretes</h2>
            <p>Atur sesi pelaksanaan ujian, tentukan paket soal aktif, kadar proporsi kategori materi, waktu, kewajiban kamera, dan token ujian dalam satu sistem terintegrasi.</p>
        </div>
        <div class="bank-actions">
            <button type="button" class="bank-button gold" data-action="create-package">📦 ＋ Buat Paket Soal</button>
            <button type="button" class="bank-button primary" data-action="create-session">🎯 ＋ Buat Sesi Ujian</button>
            <button type="button" class="bank-button" data-action="add-question">＋ Tambah Soal</button>
            <a data-no-spa class="bank-button ghost" href="<?= $bankUrl ?>&amp;tab=import">Impor Excel</a>
        </div>
    </header>

    <div class="bank-stats">
        <div class="bank-stat">
            <small>Sesi Ujian Aktif</small>
            <strong style="font-size:18px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis" title="<?= $activeSession ? sanitize($activeSession['title']) : 'Belum Ada Sesi Aktif' ?>">
                <?= $activeSession ? sanitize($activeSession['title']) : 'Belum Ada Sesi' ?>
            </strong>
            <span>
                <?php if ($activeSession): ?>
                    <?= (int)$activeSession['duration_minutes'] ?> mnt · 
                    <?= $activeSession['camera_mode'] === 'wajib' ? '📷 Kamera Wajib' : ($activeSession['camera_mode'] === 'tidak_aktif' ? '🚫 Tanpa Kamera' : '📷 Kamera Opsional') ?>
                    <?= !empty($activeSession['token_required']) ? ' · 🔑 Token: ' . sanitize($activeSession['token']) : '' ?>
                <?php else: ?>
                    Buat sesi ujian untuk mahasiswa
                <?php endif; ?>
            </span>
        </div>
        <div class="bank-stat">
            <small>Paket Soal Aktif</small>
            <strong style="font-size:18px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis" title="<?= $activePackage ? sanitize($activePackage['name']) : 'Belum Ada Paket' ?>">
                <?= $activePackage ? sanitize($activePackage['name']) : 'Belum Ada' ?>
            </strong>
            <span><?= $activePackage ? ((int)$activePackage['duration_minutes'] . ' menit · Target ' . (int)$activePackage['question_count'] . ' butir') : 'Buat paket terlebih dahulu' ?></span>
        </div>
        <div class="bank-stat">
            <small>Total Bank Soal</small>
            <strong><?= count($questions) ?></strong>
            <span><?= $active ?> soal aktif di <?= count($packages) ?> paket</span>
        </div>
        <div class="bank-stat">
            <small>Peserta CBT Selesai</small>
            <strong><?= (int)$stats['finished'] ?></strong>
            <span>Dari <?= (int)$stats['total'] ?> ujian resmi<?= $stats['average_score'] !== null ? (' (Rerata: ' . number_format((float)$stats['average_score'], 1) . ')') : '' ?><?= !empty($tryoutStats['total']) ? ('<br><span style="color:#1d4ed8;font-size:11px">+' . (int)$tryoutStats['total'] . ' sesi tryout (' . (int)$tryoutStats['finished'] . ' selesai)</span>') : '' ?></span>
        </div>
    </div>

    <?php if ($flash): ?><div class="bank-notice" role="status"><?= sanitize($flash) ?></div><?php endif; ?>
    <?php if ($message): ?><div class="bank-notice error" role="alert"><?= sanitize($message) ?><?php if ($errors): ?><ul><?php foreach ($errors as $error): ?><li><?= sanitize($error) ?></li><?php endforeach; ?></ul><?php endif; ?></div><?php endif; ?>

    <nav class="bank-tabs" aria-label="Kelola CBT">
        <?php foreach ([
            'packages' => 'Paket Soal',
            'sessions' => 'Sesi Ujian',
            'import' => 'Impor Excel',
            'results' => 'Hasil Ujian'
        ] as $tab => $label): ?>
            <a data-no-spa href="<?= $bankUrl ?>&amp;tab=<?= $tab ?>" class="<?= $activeTab === $tab ? 'active' : '' ?>" <?= $activeTab === $tab ? 'aria-current="page"' : '' ?>>
                <?= $label ?>
                <?php if ($tab === 'packages'): ?><span class="bank-tab-count"><?= count($packages) ?></span><?php endif; ?>
                <?php if ($tab === 'sessions'): ?><span class="bank-tab-count"><?= count($sessions) ?></span><?php endif; ?>
                <?php if ($tab === 'results'): ?><span class="bank-tab-count" title="Total pengerjaan pretes dan tryout"><?= (int)$totalAttemptCount ?></span><?php endif; ?>
            </a>
        <?php endforeach; ?>
    </nav>

    <!-- ═══════════════════════════════════════════════════════════════════
         TAB 1: PAKET SOAL
         ═══════════════════════════════════════════════════════════════════ -->
    <?php if ($activeTab === 'packages'): ?>
    <section class="bank-panel">
        <div class="bank-panel-head">
            <div>
                <h3>Daftar Paket Soal CBT</h3>
                <p>Kelola wadah materi soal ujian. Tambah dan edit paket soal dilakukan langsung di jendela modal tanpa berpindah halaman.</p>
            </div>
            <button type="button" class="bank-button gold" data-action="create-package">📦 ＋ Buat Paket Soal Baru</button>
        </div>
        <form id="bulkDeletePackagesForm" method="post" action="<?= $bankUrl ?>&amp;tab=packages" data-bulk-delete-form="packages" data-no-spa>
            <input type="hidden" name="action" value="delete_packages">
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
            <div class="bank-panel-head" data-bulk-toolbar="packages" hidden style="padding-top:10px;padding-bottom:10px">
                <span data-bulk-count="packages" aria-live="polite">0 paket dipilih</span>
                <button type="submit" class="bank-button danger" data-bulk-delete-button="packages">🗑️ Hapus Terpilih</button>
            </div>
        </form>
        <div class="bank-table-scroll">
            <table class="bank-table no-datatable">
                <thead>
                    <tr>
                        <th style="width:38px;text-align:center"><input type="checkbox" aria-label="Pilih semua paket soal" data-bulk-select-all="packages"></th>
                        <th style="width:40px">No.</th>
                        <th>Kode</th>
                        <th>Nama Paket &amp; Deskripsi</th>
                        <th>Durasi</th>
                        <th>Target Soal</th>
                        <th>Soal Terdaftar</th>
                        <th>Status</th>
                        <th style="text-align:right">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($packages)): ?>
                    <tr><td colspan="9" class="bank-empty">Belum ada paket soal. Klik tombol <strong>＋ Buat Paket Soal Baru</strong> untuk membuat paket pertama.</td></tr>
                <?php else: ?>
                    <?php foreach ($packages as $idx => $pkg): ?>
                    <tr>
                        <td style="text-align:center"><input type="checkbox" name="package_ids[]" value="<?= (int)$pkg['id'] ?>" form="bulkDeletePackagesForm" aria-label="Pilih paket <?= htmlspecialchars($pkg['name'], ENT_QUOTES, 'UTF-8') ?>" data-bulk-select-item="packages" data-item-label="<?= htmlspecialchars($pkg['name'], ENT_QUOTES, 'UTF-8') ?>"></td>
                        <td style="color:#8b978e"><?= $idx + 1 ?></td>
                        <td><span class="bank-code"><?= sanitize($pkg['code']) ?></span></td>
                        <td>
                            <strong><?= sanitize($pkg['name']) ?></strong>
                            <?php if (!empty($pkg['description'])): ?>
                                <div style="font-size:12px;color:#6b7972;margin-top:4px"><?= sanitize($pkg['description']) ?></div>
                            <?php endif; ?>
                        </td>
                        <td><span class="bank-badge"><?= (int)$pkg['duration_minutes'] ?> menit</span></td>
                        <td><span class="bank-badge"><?= (int)$pkg['question_count'] ?> butir</span></td>
                        <td>
                            <strong><?= (int)$pkg['question_count_actual'] ?></strong> aktif
                            <span style="color:#8b978e;font-size:11px">(dari <?= (int)$pkg['total_questions'] ?> total)</span>
                        </td>
                        <td>
                            <?php if ($pkg['is_active']): ?>
                                <span class="bank-badge active">● SEDANG AKTIF</span>
                            <?php else: ?>
                                <span class="bank-badge inactive">Tidak Aktif</span>
                            <?php endif; ?>
                        </td>
                        <td style="text-align:right;white-space:nowrap">
                            <?php if (!$pkg['is_active']): ?>
                                <form method="post" action="<?= $bankUrl ?>&amp;tab=packages" style="display:inline;margin:0 4px">
                                    <input type="hidden" name="action" value="activate_package">
                                    <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                                    <input type="hidden" name="package_id" value="<?= (int)$pkg['id'] ?>">
                                    <button type="submit" class="bank-button" style="min-height:28px;padding:4px 8px;font-size:11px" title="Jadikan paket ini aktif untuk ujian peserta">Jadikan Aktif</button>
                                </form>
                            <?php endif; ?>
                            <button type="button" class="bank-button primary-sm" style="min-height:28px;padding:4px 9px;font-size:11px;font-weight:600" data-action="manage-questions" data-package-id="<?= (int)$pkg['id'] ?>" data-package-name="<?= htmlspecialchars($pkg['name'], ENT_QUOTES, 'UTF-8') ?>" data-package-code="<?= htmlspecialchars($pkg['code'], ENT_QUOTES, 'UTF-8') ?>" title="Lihat &amp; kelola butir pertanyaan paket ini">📋 Kelola Soal (<?= (int)$pkg['total_questions'] ?>)</button>
                            <button type="button" class="bank-button gold-sm" style="min-height:28px;padding:4px 9px;font-size:11px" data-action="add-question" data-package-id="<?= (int)$pkg['id'] ?>" title="Tambah soal baru ke paket ini">＋ Soal</button>
                            <button type="button" class="bank-button" style="min-height:28px;padding:4px 8px;font-size:11px" data-action="edit-package" data-package-id="<?= (int)$pkg['id'] ?>" onclick='openPackageModal(true, <?= htmlspecialchars(json_encode($pkg, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE), ENT_QUOTES, "UTF-8") ?>)'>✏️ Edit</button>
                            <form method="post" action="<?= $bankUrl ?>&amp;tab=packages" style="display:inline;margin:0 0 0 4px" onsubmit="return confirm('PERINGATAN: Yakin ingin menghapus Paket Soal \'<?= htmlspecialchars(addslashes($pkg['name']), ENT_QUOTES, 'UTF-8') ?>\'?\n\nSemua butir soal (<?= (int)$pkg['total_questions'] ?> butir) di dalam paket ini juga akan dihapus!')">
                                <input type="hidden" name="action" value="delete_package">
                                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                                <input type="hidden" name="package_id" value="<?= (int)$pkg['id'] ?>">
                                <button type="submit" class="bank-button danger-sm" title="Hapus paket beserta seluruh butir soalnya">🗑️ Hapus</button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>

    <!-- ═══════════════════════════════════════════════════════════════════
         TAB 2: SESI UJIAN (PENGATUR SESI)
         ═══════════════════════════════════════════════════════════════════ -->
    <?php elseif ($activeTab === 'sessions'): ?>
    <section class="bank-panel">
        <div class="bank-panel-head">
            <div>
                <h3>Pengatur Sesi Ujian CBT</h3>
                <p>Atur paket soal yang ditampilkan, kadar kuota masing-masing kategori, kewajiban kamera, waktu, penilaian, jadwal mulai/selesai, dan token ujian.</p>
            </div>
            <div style="display:flex;gap:8px;flex-wrap:wrap">
                <button type="button" class="bank-button primary" data-action="create-session">🎯 ＋ Buat Sesi Baru</button>
                <button type="button" class="bank-button" data-action="create-tryout" style="background:#eff6ff;color:#1e3a8a;border-color:#bfdbfe">📝 ＋ Buat Tryout</button>
            </div>
        </div>
        <?php $examStatusAdmin = pretesCbtExamStatus($pdo); $examSes = $examStatusAdmin['session']; $examSwitchOpen = $examSes && (int)($examSes['exam_open'] ?? 0) === 1; ?>
        <div class="bank-exam-switch <?= $examStatusAdmin['open'] ? 'is-open' : 'is-closed' ?>" id="examOpenSwitch" role="region" aria-label="Status pengerjaan ujian pretes resmi">
            <div class="bank-exam-switch-info">
                <div class="bank-exam-switch-state"><?= $examStatusAdmin['open'] ? '🟢 PENGERJAAN UJIAN PRETES DIBUKA' : '🔴 PENGERJAAN UJIAN PRETES DITUTUP' ?></div>
                <div class="bank-exam-switch-desc">
                    <?php if (!$examSes): ?>
                        Belum ada sesi resmi yang aktif. Aktifkan salah satu sesi resmi terlebih dahulu.
                    <?php else: ?>
                        Sesi resmi aktif: <strong><?= sanitize($examSes['title']) ?></strong>.
                        <?php if ($examStatusAdmin['open']): ?>
                            Peserta pretes aktif dapat menekan <strong>"Kerjakan Soal Pretes"</strong> dan memulai ujian.
                        <?php elseif ($examSwitchOpen): ?>
                            Sakelar sudah <strong>dibuka</strong>, tetapi <?= sanitize($examStatusAdmin['notice']) ?> (atur di Edit Sesi › jadwal).
                        <?php else: ?>
                            Peserta melihat link pengerjaan terkunci 🔒 dan tidak dapat memulai ujian.
                        <?php endif; ?>
                        <span style="display:block;margin-top:4px;font-size:11.5px;opacity:.85">Menutup ujian tidak menghentikan peserta yang sedang mengerjakan; mereka tetap dapat menyelesaikan sampai waktunya habis.</span>
                    <?php endif; ?>
                </div>
            </div>
            <?php if ($examSes): ?>
                <form method="post" action="<?= $bankUrl ?>&amp;tab=sessions" data-no-spa style="margin:0"
                      onsubmit="return confirm(<?= htmlspecialchars(json_encode($examSwitchOpen ? 'TUTUP pengerjaan ujian pretes sekarang? Peserta tidak akan bisa memulai ujian baru.' : 'BUKA pengerjaan ujian pretes sekarang? Peserta pretes aktif akan bisa memulai ujian.'), ENT_QUOTES, 'UTF-8') ?>)">
                    <input type="hidden" name="action" value="set_exam_open">
                    <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                    <input type="hidden" name="session_id" value="<?= (int)$examSes['id'] ?>">
                    <input type="hidden" name="open" value="<?= $examSwitchOpen ? 0 : 1 ?>">
                    <button type="submit" class="bank-button <?= $examSwitchOpen ? 'danger' : 'primary' ?>" id="examOpenToggle" style="min-height:42px;padding:8px 18px;font-weight:800">
                        <?= $examSwitchOpen ? '🔴 Tutup Ujian' : '🟢 Buka Ujian' ?>
                    </button>
                </form>
            <?php endif; ?>
        </div>
        <form id="bulkDeleteSessionsForm" method="post" action="<?= $bankUrl ?>&amp;tab=sessions" data-bulk-delete-form="sessions" data-no-spa>
            <input type="hidden" name="action" value="delete_sessions">
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
            <div class="bank-panel-head" data-bulk-toolbar="sessions" hidden style="padding-top:10px;padding-bottom:10px">
                <span data-bulk-count="sessions" aria-live="polite">0 sesi dipilih</span>
                <button type="submit" class="bank-button danger" data-bulk-delete-button="sessions">🗑️ Hapus Terpilih</button>
            </div>
        </form>
        <div class="bank-table-scroll">
            <table class="bank-table no-datatable">
                <thead>
                    <tr>
                        <th style="width:38px;text-align:center"><input type="checkbox" aria-label="Pilih semua sesi ujian" data-bulk-select-all="sessions"></th>
                        <th style="width:35px">No.</th>
                        <th>Kode Sesi</th>
                        <th>Nama Sesi &amp; Paket Terpilih</th>
                        <th>Kadar Kategori</th>
                        <th>Kamera</th>
                        <th>Waktu &amp; Nilai</th>
                        <th>Jadwal Pelaksanaan</th>
                        <th>Token</th>
                        <th>Status</th>
                        <th style="text-align:right">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($sessions)): ?>
                    <tr><td colspan="11" class="bank-empty">Belum ada sesi ujian dibuat. Klik tombol <strong>＋ Buat Sesi Baru</strong> untuk mengatur sesi ujian pertama.</td></tr>
                <?php else: ?>
                    <?php foreach ($sessions as $sIdx => $ses): ?>
                    <tr>
                        <td style="text-align:center"><input type="checkbox" name="session_ids[]" value="<?= (int)$ses['id'] ?>" form="bulkDeleteSessionsForm" aria-label="Pilih sesi <?= htmlspecialchars($ses['title'], ENT_QUOTES, 'UTF-8') ?>" data-bulk-select-item="sessions" data-item-label="<?= htmlspecialchars($ses['title'], ENT_QUOTES, 'UTF-8') ?>"></td>
                        <td style="color:#8b978e"><?= $sIdx + 1 ?></td>
                        <td><span class="bank-code"><?= sanitize($ses['code']) ?></span></td>
                        <td>
                            <strong><?= sanitize($ses['title']) ?></strong>
                            <?php if (!empty($ses['description'])): ?>
                                <div style="font-size:11px;color:#6b7972;margin-top:2px"><?= sanitize($ses['description']) ?></div>
                            <?php endif; ?>
                            <div style="margin-top:6px;display:flex;gap:4px;flex-wrap:wrap">
                                <?php foreach ($ses['package_names'] as $pName): ?>
                                    <span class="bank-badge package">📦 <?= sanitize($pName) ?></span>
                                <?php endforeach; ?>
                                <?php if (empty($ses['package_names'])): ?>
                                    <span class="bank-badge" style="color:#b91c1c;background:#fee2e2">Belum ada paket</span>
                                <?php endif; ?>
                            </div>
                        </td>
                        <td>
                            <?php if (!empty($ses['category_quotas'])): ?>
                                <details style="margin:0">
                                    <summary style="font-weight:700;color:#126147;font-size:11.5px"><?= array_sum($ses['category_quotas']) ?> Soal (<?= count($ses['category_quotas']) ?> Kategori)</summary>
                                    <div style="font-size:11px;margin-top:4px;background:#f8faf8;padding:6px 8px;border-radius:6px;line-height:1.6">
                                        <?php foreach ($ses['category_quotas'] as $cat => $quota): ?>
                                            <div>• <strong><?= sanitize($cat) ?></strong>: <?= (int)$quota ?> butir</div>
                                        <?php endforeach; ?>
                                    </div>
                                </details>
                            <?php else: ?>
                                <span class="bank-badge">Acak Penuh Paket (<?= (int)$ses['target_questions'] ?> butir)</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($ses['camera_mode'] === 'wajib'): ?>
                                <span class="bank-badge camera-wajib">📷 WAJIB</span>
                            <?php elseif ($ses['camera_mode'] === 'tidak_aktif'): ?>
                                <span class="bank-badge camera-bebas">🚫 TANPA KAMERA</span>
                            <?php else: ?>
                                <span class="bank-badge camera-opsi">📷 OPSIONAL</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <strong><?= (int)$ses['duration_minutes'] ?> menit</strong>
                            <div style="font-size:11px;color:#6d7f72;margin-top:3px">
                                <?= $ses['scoring_method'] === 'penalti' ? 'UTBK (+4, -1, 0)' : 'Skala 100 Proporsional' ?>
                                (KKM: <?= number_format((float)$ses['passing_grade'], 0) ?>)
                            </div>
                            <div style="margin-top:4px">
                                <?php if (!empty($ses['shuffle_on_violation'])): ?>
                                    <span class="bank-badge" style="background:#dcfce7;color:#15803d;font-size:10px;padding:2px 6px" title="Soal belum dijawab otomatis ditukar dari bank cadangan & opsi diacak jika terdeteksi pelanggaran">🛡️ Anti-Contek (Tukar Cadangan)</span>
                                <?php else: ?>
                                    <span class="bank-badge" style="background:#f1f5f9;color:#64748b;font-size:10px;padding:2px 6px" title="Hanya geser urutan posisi soal tanpa mengambil bank cadangan">Anti-Contek Standar</span>
                                <?php endif; ?>
                            </div>
                        </td>
                        <td>
                            <?php if (!empty($ses['start_time']) || !empty($ses['end_time'])): ?>
                                <div style="font-size:11.5px">
                                    <div>🟢 Mulai: <strong><?= !empty($ses['start_time']) ? (new DateTimeImmutable($ses['start_time']))->format('d/m/Y H:i') : 'Langsung' ?></strong></div>
                                    <div>🔴 Batas: <strong><?= !empty($ses['end_time']) ? (new DateTimeImmutable($ses['end_time']))->format('d/m/Y H:i') : 'Tidak dibatasi' ?></strong></div>
                                </div>
                            <?php else: ?>
                                <span class="bank-badge">Buka Terbuka</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if (!empty($ses['token_required'])): ?>
                                <span class="bank-badge token-badge" title="Token wajib diisi peserta"><?= sanitize($ses['token']) ?></span>
                            <?php else: ?>
                                <span class="bank-badge" style="color:#64748b" title="Token tidak diwajibkan">Tanpa Token</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($ses['is_active']): ?>
                                <span class="bank-badge session-active">● AKTIF</span>
                            <?php else: ?>
                                <span class="bank-badge session-inactive">Tidak Aktif</span>
                            <?php endif; ?>
                            <?php if (($ses['mode'] ?? 'resmi') === 'tryout'): ?>
                                <div style="margin-top:4px"><span class="bank-badge" style="background:#dbeafe;color:#1e3a8a">📝 TRYOUT · <?= (int)($ses['max_attempts'] ?? 0) === 0 ? 'ulang ∞' : ('maks ' . (int)$ses['max_attempts'] . '×') ?></span></div>
                            <?php else: ?>
                                <div style="margin-top:4px"><span class="bank-badge" style="background:#ecfdf5;color:#047857">🎓 RESMI</span></div>
                                <div style="margin-top:4px"><?php if ((int)($ses['exam_open'] ?? 0) === 1): ?><span class="bank-badge" style="background:#dcfce7;color:#166534">🟢 Ujian dibuka</span><?php else: ?><span class="bank-badge" style="background:#fee2e2;color:#991b1b">🔴 Ujian ditutup</span><?php endif; ?></div>
                            <?php endif; ?>
                        </td>
                        <td style="text-align:right;white-space:nowrap">
                            <?php if (!$ses['is_active']): ?>
                                <form method="post" action="<?= $bankUrl ?>&amp;tab=sessions" style="display:inline;margin:0 3px">
                                    <input type="hidden" name="action" value="activate_session">
                                    <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                                    <input type="hidden" name="session_id" value="<?= (int)$ses['id'] ?>">
                                    <button type="submit" class="bank-button" style="min-height:28px;padding:4px 8px;font-size:11px" title="Jadikan sesi ini aktif untuk ujian mahasiswa">Set Aktif</button>
                                </form>
                            <?php endif; ?>
                            <?php if (($ses['mode'] ?? 'resmi') !== 'tryout'): $rowOpen = (int)($ses['exam_open'] ?? 0) === 1; ?>
                                <form method="post" action="<?= $bankUrl ?>&amp;tab=sessions" data-no-spa style="display:inline;margin:0 3px">
                                    <input type="hidden" name="action" value="set_exam_open">
                                    <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                                    <input type="hidden" name="session_id" value="<?= (int)$ses['id'] ?>">
                                    <input type="hidden" name="open" value="<?= $rowOpen ? 0 : 1 ?>">
                                    <button type="submit" class="bank-button <?= $rowOpen ? 'danger-sm' : '' ?>" style="min-height:28px;padding:4px 8px;font-size:11px" title="<?= $rowOpen ? 'Tutup pengerjaan ujian untuk sesi ini' : 'Buka pengerjaan ujian untuk sesi ini' ?>"><?= $rowOpen ? '🔴 Tutup Ujian' : '🟢 Buka Ujian' ?></button>
                                </form>
                            <?php endif; ?>
                            <button type="button" class="bank-button" style="min-height:28px;padding:4px 8px;font-size:11px" data-action="edit-session" data-session-id="<?= (int)$ses['id'] ?>" onclick='openSessionModal(true, <?= htmlspecialchars(json_encode($ses, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE), ENT_QUOTES, "UTF-8") ?>)'>✏️ Edit</button>
                            <form method="post" action="<?= $bankUrl ?>&amp;tab=sessions" style="display:inline;margin:0 0 0 3px" onsubmit="return confirm('PERINGATAN: Yakin ingin menghapus Sesi Ujian \'<?= htmlspecialchars(addslashes($ses['title']), ENT_QUOTES, 'UTF-8') ?>\'?')">
                                <input type="hidden" name="action" value="delete_session">
                                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                                <input type="hidden" name="session_id" value="<?= (int)$ses['id'] ?>">
                                <button type="submit" class="bank-button danger-sm" title="Hapus sesi ujian">🗑️ Hapus</button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>

    <!-- ═══════════════════════════════════════════════════════════════════
         TAB 5: IMPOR EXCEL
         ═══════════════════════════════════════════════════════════════════ -->
    <?php elseif ($activeTab === 'import'): ?>
    <section class="bank-panel">
        <div class="bank-panel-head">
            <div>
                <h3>Impor Bank Soal dari Excel</h3>
                <p>Validasi seluruh baris sebelum menyimpan ke paket soal target.</p>
            </div>
            <div style="display:flex;gap:8px;flex-wrap:wrap">
                <a data-no-spa class="bank-button gold" href="<?= $bankUrl ?>&amp;download=pretes177" title="Unduh master bank soal 177 butir yang sudah terformat sesuai template siap impor">📥 Unduh File Soal Pretes (177 Soal) .xlsx</a>
                <a data-no-spa class="bank-button" href="<?= $bankUrl ?>&amp;download=template" title="Unduh format template kosong">↓ Unduh Template Kosong .xlsx</a>
            </div>
        </div>
        <div class="bank-panel-body bank-import-grid">
            <?php if (empty($packages)): ?>
                <div class="bank-empty-state" style="grid-column:1/-1">
                    <span class="bank-empty-state-icon">⚠️</span>
                    <h3>Buat Paket Soal Terlebih Dahulu</h3>
                    <p>Sebelum mengimpor butir soal dari Excel, Anda wajib membuat minimal satu <strong>Paket Soal</strong> sebagai tujuan alokasi butir-butir pertanyaan.</p>
                    <button type="button" class="bank-button primary" onclick="openPackageModal(false)">📦 Buat Paket Soal Sekarang</button>
                </div>
            <?php else: ?>
                <form method="post" enctype="multipart/form-data" id="bankImportForm">
                    <input type="hidden" name="action" value="import_xlsx">
                    <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                    <div class="bank-field" style="margin-bottom:18px">
                        <label class="bank-label" for="bankImportPackage">Target Paket Soal *</label>
                        <select id="bankImportPackage" class="bank-input" name="package_id" required>
                            <?php foreach ($packages as $pkg): ?>
                                <option value="<?= (int)$pkg['id'] ?>" <?= ((int)($activePackage['id'] ?? 0) === (int)$pkg['id']) ? 'selected' : '' ?>>
                                    [<?= sanitize($pkg['code']) ?>] <?= sanitize($pkg['name']) ?><?= $pkg['is_active'] ? ' — AKTIF' : '' ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <span style="font-size:11px;color:#6d7f72;display:block;margin-top:4px">Semua butir soal yang berhasil diimpor akan dimasukkan ke paket ini.</span>
                    </div>
                    <label class="bank-upload" id="bankDropzone" for="bankFile">
                        <span class="bank-upload-icon">XLSX</span>
                        <strong>Pilih atau tarik file Excel ke sini</strong>
                        <span id="bankFileLabel">Format .xlsx · Maksimal 5 MB · 1.000 soal per file</span>
                        <input id="bankFile" name="question_file" type="file" accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" required>
                    </label>
                    <label class="bank-toggle" style="margin-top:20px">
                        <input type="checkbox" name="update_existing" value="1" <?= isset($_POST['update_existing']) ? 'checked' : '' ?>>
                        Perbarui soal dengan Kode Soal yang sama
                    </label>
                    <p class="bank-note">Tanpa opsi ini, soal lama dilewati. Kode kosong dibuat sebagai soal baru; pertanyaan yang sudah ada dilewati untuk mencegah duplikasi.</p>
                    <div class="bank-form-actions">
                        <button id="bankImportSubmit" class="bank-button primary" type="submit">Validasi &amp; Impor Soal</button>
                        <a data-no-spa class="bank-button" href="<?= $bankUrl ?>&amp;download=bank">Ekspor bank saat ini</a>
                    </div>
                </form>
                <aside class="bank-form-aside">
                    <h4>Tiga langkah impor</h4>
                    <ol class="bank-steps">
                        <li><b>1</b><div><strong>Unduh template</strong>Gunakan lembar Bank Soal dan pertahankan judul kolom.</div></li>
                        <li><b>2</b><div><strong>Isi soal dan kunci</strong>Kategori, pertanyaan, lima pilihan, kunci A–E, dan status wajib diisi. Ganti baris contoh.</div></li>
                        <li><b>3</b><div><strong>Pilih paket &amp; unggah</strong>Pilih paket soal tujuan, validasi baris error jika ada, lalu unggah kembali.</div></li>
                    </ol>
                    <p>Untuk mengedit massal: ekspor bank, pertahankan Kode Soal, lalu impor dengan opsi pembaruan.</p>
                </aside>
            <?php endif; ?>
        </div>
    </section>

    <!-- ═══════════════════════════════════════════════════════════════════
         TAB 6: HASIL UJIAN & LIVE MONITORING CBT
         ═══════════════════════════════════════════════════════════════════ -->
    <?php else: ?>
    <section class="bank-panel">
        <div class="bank-panel-head">
            <div>
                <h3>Hasil Ujian Peserta &amp; Live Monitoring CBT</h3>
                <p>
                    <?php if ($statusFilter === 'live'): ?>
                        <strong>⚡ Mode Live:</strong> Memantau peserta yang sedang aktif mengerjakan ujian secara real-time.
                    <?php elseif ($statusFilter === 'finished'): ?>
                        <strong>✓ Mode Selesai:</strong> Riwayat hasil akhir, nilai, dan lembar jawaban peserta yang telah selesai.
                    <?php else: ?>
                        Semua sesi pengerjaan CBT (berlangsung maupun telah selesai).
                    <?php endif; ?>
                    <?= $resultFilter === 'resmi' ? ' (Filter: Ujian Resmi)' : ($resultFilter === 'tryout' ? ' (Filter: Tryout)' : '') ?>
                </p>
            </div>
            <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
                <?php if (!empty($attempts)): ?>
                    <form method="post" action="<?= $bankUrl ?>&amp;tab=results<?= $resultFilter !== 'all' ? '&amp;result_filter=' . urlencode($resultFilter) : '' ?>" style="margin:0;display:inline;" onsubmit="return confirm('PERINGATAN: Yakin ingin menghapus <?= $resultFilter === 'tryout' ? 'SEMUA sesi Tryout' : ($resultFilter === 'resmi' ? 'SEMUA sesi Ujian Resmi' : 'SEMUA sesi CBT (resmi & tryout)') ?> peserta?\n\nSemua riwayat pengerjaan, jawaban, dan nilai sesuai filter akan dihapus.')">
                        <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                        <input type="hidden" name="action" value="delete_all_attempts">
                        <input type="hidden" name="delete_scope" value="<?= sanitize($resultFilter) ?>">
                        <button type="submit" class="bank-button danger" title="Hapus Semua Hasil CBT peserta sesuai filter">🗑️ Hapus Semua Hasil (<?= $resultFilter === 'tryout' ? 'Tryout' : ($resultFilter === 'resmi' ? 'Resmi' : 'Semua') ?>)</button>
                    </form>
                <?php endif; ?>
                <?php
                    // Ekspor mengikuti filter aktif: hari, lulus/tidak lulus, dan pencarian.
                    $exportQs = http_build_query(array_filter([
                        'day' => $dayFilter ?? '',
                        'pass_filter' => ($passFilter ?? 'all') !== 'all' ? $passFilter : '',
                        'q' => $searchQuery ?? '',
                    ], fn($v) => $v !== ''));
                    $exportSuffix = $exportQs !== '' ? '&amp;' . htmlspecialchars($exportQs, ENT_QUOTES, 'UTF-8') : '';
                    $exportNote = $exportQs !== '' ? ' (mengikuti filter aktif: hari/kelulusan/pencarian)' : '';
                ?>
                <a data-no-spa class="bank-button primary" data-export-scope="resmi" href="<?= $bankUrl ?>&amp;download=results&amp;filter=resmi<?= $exportSuffix ?>" title="Ekspor data nilai ujian resmi pretes<?= $exportNote ?>">🎓 Ekspor Resmi (.xlsx)</a>
                <a data-no-spa class="bank-button" data-export-scope="tryout" href="<?= $bankUrl ?>&amp;download=results&amp;filter=tryout<?= $exportSuffix ?>" style="background:#eff6ff;color:#1e40af;border-color:#bfdbfe" title="Ekspor data riwayat latihan tryout<?= $exportNote ?>">📝 Ekspor Tryout (.xlsx)</a>
                <a data-no-spa class="bank-button" data-export-scope="all" href="<?= $bankUrl ?>&amp;download=results&amp;filter=all<?= $exportSuffix ?>" title="Ekspor gabungan semua sesi<?= $exportNote ?>">↓ Ekspor Semua (.xlsx)</a>
                <?php if ($exportQs !== ''): ?>
                    <span class="cbt-export-note" id="cbtExportFilterNote">⚙️ Ekspor mengikuti filter aktif</span>
                <?php endif; ?>
            </div>
        </div>

        <div style="padding:14px 24px 0;">
            <!-- Status Sub-Tabs: Live Monitoring vs Selesai vs Semua -->
            <div class="cbt-status-tabs" style="display:flex;gap:8px;margin-bottom:14px;border-bottom:2px solid #e2e8f0;padding-bottom:12px;flex-wrap:wrap">
                <a href="<?= buildCbtResultUrl(['status_filter' => 'live', 'pass_filter' => 'all', 'page' => 1]) ?>" class="cbt-status-tab-btn <?= $statusFilter === 'live' ? 'active' : '' ?>">
                    <span>⚡ Sedang Berlangsung (Live)</span>
                    <span id="cbtLiveTabBadge" class="cbt-tab-count <?= $ongoingCount > 0 ? 'live' : '' ?>"><?= $ongoingCount ?></span>
                </a>
                <a href="<?= buildCbtResultUrl(['status_filter' => 'finished', 'page' => 1]) ?>" class="cbt-status-tab-btn <?= $statusFilter === 'finished' ? 'active' : '' ?>">
                    <span>✓ Riwayat Selesai</span>
                    <span id="cbtFinishedTabBadge" class="cbt-tab-count"><?= $finishedCount ?></span>
                </a>
                <a href="<?= buildCbtResultUrl(['status_filter' => 'all', 'page' => 1]) ?>" class="cbt-status-tab-btn <?= $statusFilter === 'all' ? 'active' : '' ?>">
                    <span>📋 Semua Sesi</span>
                    <span id="cbtAllTabBadge" class="cbt-tab-count"><?= $totalAttemptCount ?></span>
                </a>
            </div>

            <!-- Live Monitoring Toolbar -->
            <div class="cbt-live-toolbar" style="display:flex;justify-content:space-between;align-items:center;background:#f8fafc;border:1px solid #e2e8f0;border-radius:12px;padding:12px 18px;margin-bottom:14px;flex-wrap:wrap;gap:12px">
                <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap">
                    <span style="display:inline-flex;align-items:center;gap:6px;font-size:12.5px;font-weight:700;color:#0f172a">
                        <span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:#10b981;box-shadow:0 0 0 3px rgba(16,185,129,0.2)"></span>
                        Live Monitoring CBT
                    </span>
                    <span id="cbtLiveOngoingCounter" class="bank-badge" style="background:#ecfdf5;color:#065f46;border:1px solid #a7f3d0;font-size:11px">
                        ⚡ <strong id="cbtLiveOngoingNum"><?= $ongoingCount ?? 0 ?></strong> peserta sedang mengerjakan
                    </span>
                    <span id="cbtLiveLastUpdated" style="font-size:11.5px;color:#64748b">
                        Pembaruan: <?= (new DateTimeImmutable('now', new DateTimeZone('Asia/Jakarta')))->format('H:i:s') ?> WIB
                    </span>
                </div>
                <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
                    <label style="display:inline-flex;align-items:center;gap:6px;font-size:12px;font-weight:600;color:#334155;cursor:pointer">
                        <input type="checkbox" id="cbtLiveAutoRefreshToggle" <?= $statusFilter === 'finished' ? '' : 'checked' ?> style="accent-color:#126147;width:16px;height:16px;cursor:pointer">
                        Auto-refresh
                    </label>
                    <select id="cbtLiveRefreshInterval" class="bank-input" style="width:auto;min-height:30px;padding:4px 8px;font-size:11.5px;margin:0">
                        <option value="5" selected>Tiap 5 Detik</option>
                        <option value="10">Tiap 10 Detik</option>
                        <option value="30">Tiap 30 Detik</option>
                    </select>
                    <button type="button" id="cbtLiveManualRefreshBtn" class="bank-button" style="min-height:30px;padding:4px 12px;font-size:11.5px" title="Segarkan data sekarang">
                        🔄 Segarkan
                    </button>
                </div>
            </div>

            <!-- Filter Tipe, Hari, Kelulusan & Pencarian -->
            <?php
                $dayFilter = $dayFilter ?? '';
                $passFilter = $passFilter ?? 'all';
                $passCounts = $passCounts ?? ['lulus' => 0, 'tidak_lulus' => 0];
                $dayOptions = $dayOptions ?? [];
                $passMinScore = $passMinScore ?? 80.0;
                $dayInOptions = false;
                foreach ($dayOptions as $dOpt) { if ($dOpt['date'] === $dayFilter) { $dayInOptions = true; break; } }
                $hasExtraFilter = ($searchQuery !== '' || $dayFilter !== '' || $passFilter !== 'all');
            ?>
            <div class="cbt-result-filters" id="cbtResultFilters">
                <div class="cbt-filter-row">
                    <span class="cbt-filter-label">Tipe</span>
                    <div class="cbt-filter-chips">
                        <a href="<?= buildCbtResultUrl(['result_filter' => 'all', 'page' => 1]) ?>" class="cbt-chip <?= $resultFilter === 'all' ? 'active' : '' ?>">Semua <span><?= $totalAttemptCount ?></span></a>
                        <a href="<?= buildCbtResultUrl(['result_filter' => 'resmi', 'page' => 1]) ?>" class="cbt-chip <?= $resultFilter === 'resmi' ? 'active' : '' ?>">🎓 Ujian Resmi <span><?= (int)$stats['total'] ?></span></a>
                        <a href="<?= buildCbtResultUrl(['result_filter' => 'tryout', 'page' => 1]) ?>" class="cbt-chip <?= $resultFilter === 'tryout' ? 'active' : '' ?>">📝 Tryout <span><?= (int)$tryoutStats['total'] ?></span></a>
                    </div>
                </div>
                <div class="cbt-filter-row">
                    <span class="cbt-filter-label">Hasil</span>
                    <div class="cbt-filter-chips" id="cbtPassFilterChips">
                        <a href="<?= buildCbtResultUrl(['pass_filter' => 'all', 'page' => 1]) ?>" class="cbt-chip <?= $passFilter === 'all' ? 'active' : '' ?>" data-pass-filter="all">Semua</a>
                        <a href="<?= buildCbtResultUrl(['pass_filter' => 'lulus', 'page' => 1]) ?>" class="cbt-chip cbt-chip-pass <?= $passFilter === 'lulus' ? 'active' : '' ?>" data-pass-filter="lulus" title="Sesi selesai dengan nilai &ge; <?= (int)$passMinScore ?>">✓ Lulus <span id="cbtPassCountLulus"><?= (int)$passCounts['lulus'] ?></span></a>
                        <a href="<?= buildCbtResultUrl(['pass_filter' => 'tidak_lulus', 'page' => 1]) ?>" class="cbt-chip cbt-chip-fail <?= $passFilter === 'tidak_lulus' ? 'active' : '' ?>" data-pass-filter="tidak_lulus" title="Sesi selesai dengan nilai &lt; <?= (int)$passMinScore ?>">✕ Tidak Lulus <span id="cbtPassCountTidak"><?= (int)$passCounts['tidak_lulus'] ?></span></a>
                    </div>
                </div>

                <form method="get" action="<?= BASE_URL ?>/pretes-ujian.php" class="cbt-filter-form" id="cbtResultSearchForm" data-no-spa role="search">
                    <input type="hidden" name="cbt_bank" value="1">
                    <input type="hidden" name="tab" value="results">
                    <?php if ($statusFilter !== 'all'): ?><input type="hidden" name="status_filter" value="<?= sanitize($statusFilter) ?>"><?php endif; ?>
                    <?php if ($resultFilter !== 'all'): ?><input type="hidden" name="result_filter" value="<?= sanitize($resultFilter) ?>"><?php endif; ?>
                    <?php if ($passFilter !== 'all'): ?><input type="hidden" name="pass_filter" value="<?= sanitize($passFilter) ?>"><?php endif; ?>
                    <?php if ($perPage !== 25): ?><input type="hidden" name="per_page" value="<?= (int)$perPage ?>"><?php endif; ?>
                    <label class="cbt-filter-field cbt-filter-day">
                        <span class="cbt-filter-label">Hari</span>
                        <select name="day" id="cbtDayFilter" class="bank-input" onchange="this.form.submit()">
                            <option value="">Semua hari</option>
                            <?php if ($dayFilter !== '' && !$dayInOptions): ?>
                                <option value="<?= sanitize($dayFilter) ?>" selected><?= sanitize($dayFilter) ?> (0 sesi)</option>
                            <?php endif; ?>
                            <?php foreach ($dayOptions as $dOpt): ?>
                                <option value="<?= sanitize($dOpt['date']) ?>" <?= $dOpt['date'] === $dayFilter ? 'selected' : '' ?>><?= sanitize($dOpt['label']) ?> (<?= (int)$dOpt['count'] ?> sesi)</option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label class="cbt-filter-field cbt-filter-search">
                        <span class="cbt-filter-label">Cari</span>
                        <span class="cbt-search-box">
                            <span class="cbt-search-icon" aria-hidden="true">🔍</span>
                            <input type="search" name="q" id="cbtResultSearchInput" value="<?= sanitize($searchQuery) ?>" placeholder="Nama, NIM, atau username…" maxlength="100" autocomplete="off" enterkeyhint="search" aria-label="Cari peserta berdasarkan nama, NIM, atau username">
                        </span>
                    </label>
                    <div class="cbt-filter-actions">
                        <button type="submit" class="bank-button primary">Cari</button>
                        <?php if ($hasExtraFilter): ?>
                            <a href="<?= buildCbtResultUrl(['q' => '', 'day' => '', 'pass_filter' => 'all', 'page' => 1]) ?>" class="bank-button" id="cbtResultResetFilters" title="Hapus pencarian, filter hari, dan filter kelulusan">✕ Reset</a>
                        <?php endif; ?>
                    </div>
                </form>
                <?php if ($hasExtraFilter): ?>
                    <div class="cbt-active-filters">
                        Filter aktif:
                        <?php if ($dayFilter !== ''): ?><span class="cbt-active-tag">📅 <?php
                            $dLabel = $dayFilter; foreach ($dayOptions as $dOpt) { if ($dOpt['date'] === $dayFilter) { $dLabel = $dOpt['label']; break; } }
                            echo sanitize($dLabel); ?> <a href="<?= buildCbtResultUrl(['day' => '', 'page' => 1]) ?>" aria-label="Hapus filter hari">✕</a></span><?php endif; ?>
                        <?php if ($passFilter !== 'all'): ?><span class="cbt-active-tag"><?= $passFilter === 'lulus' ? '✓ Lulus' : '✕ Tidak Lulus' ?> <a href="<?= buildCbtResultUrl(['pass_filter' => 'all', 'page' => 1]) ?>" aria-label="Hapus filter kelulusan">✕</a></span><?php endif; ?>
                        <?php if ($searchQuery !== ''): ?><span class="cbt-active-tag">🔍 &ldquo;<?= sanitize($searchQuery) ?>&rdquo; <a href="<?= buildCbtResultUrl(['q' => '', 'page' => 1]) ?>" aria-label="Hapus pencarian">✕</a></span><?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="bank-table-scroll">
            <table class="bank-table no-datatable" id="cbtLiveResultsTable">
                <thead>
                    <tr>
                        <th>Peserta</th>
                        <th>NIM</th>
                        <th>Program</th>
                        <th>Jenis</th>
                        <th>Waktu &amp; Sisa</th>
                        <th>Status</th>
                        <th>Progres Dikerjakan</th>
                        <th>Benar / Salah</th>
                        <th>Nilai</th>
                        <th>Aksi</th>
                    </tr>
                </thead>
                <tbody id="cbtLiveResultsTbody">
                <?php 
                $nowTs = time();
                foreach ($attempts as $attempt): 
                    $isSubmitted = ($attempt['submitted_at'] !== null);
                    $deadline = (int)$attempt['deadline_at'];
                    $isExpired = (!$isSubmitted && $nowTs >= $deadline);
                    $isOngoing = (!$isSubmitted && !$isExpired);

                    $totItems = (int)($attempt['total_items'] ?? 25);
                    $ansCount = (int)($attempt['answered_count'] ?? 0);
                    $pct = $totItems > 0 ? min(100, round(($ansCount / $totItems) * 100)) : 0;
                    $flgCount = (int)($attempt['flagged_count'] ?? 0);

                    $corCount = $isSubmitted ? (int)$attempt['correct_count'] : (int)($attempt['live_correct_count'] ?? 0);
                    $wrgCount = $isSubmitted ? max(0, $totItems - $corCount) : (int)($attempt['live_wrong_count'] ?? 0);
                    $unansCount = max(0, $totItems - $ansCount);
                ?>
                    <tr id="attemptRow_<?= (int)$attempt['id'] ?>" data-attempt-id="<?= (int)$attempt['id'] ?>">
                        <td><strong><?= adminUserProfileLink($attempt['nama_lengkap'], $attempt['user_id']) ?></strong></td>
                        <td><?= sanitize($attempt['nim'] ?? '-') ?></td>
                        <td><?= renderJenisProgramBadge($attempt['jenis_program'] ?? 'Reguler', true) ?></td>
                        <td><?php $jenis = pretesTryoutAttemptLabel($pdo, $attempt, $tryoutLabelCache); ?>
                            <span class="bank-badge" style="<?= $jenis['kind'] === 'tryout' ? 'background:#dbeafe;color:#1e3a8a' : 'background:#ecfdf5;color:#047857' ?>"><?= sanitize($jenis['label']) ?></span></td>
                        <td>
                            <div><?= (new DateTimeImmutable('@' . $attempt['started_at']))->setTimezone(new DateTimeZone('Asia/Jakarta'))->format('d/m/Y H:i') ?></div>
                            <?php if ($isSubmitted): ?>
                                <?php 
                                    $durSeconds = max(0, (int)$attempt['submitted_at'] - (int)$attempt['started_at']);
                                    $durMins = floor($durSeconds / 60);
                                    $durSecs = $durSeconds % 60;
                                ?>
                                <div style="margin-top:3px;font-size:11px;color:#64748b" title="Durasi pengerjaan">
                                    ⏱️ <?= $durMins ?>m <?= $durSecs ?>s
                                </div>
                            <?php elseif ($isOngoing): ?>
                                <?php 
                                    $remSeconds = max(0, $deadline - $nowTs);
                                    $remMins = floor($remSeconds / 60);
                                    $remSecs = $remSeconds % 60;
                                ?>
                                <div class="live-countdown" data-deadline="<?= $deadline ?>" style="margin-top:3px;font-size:11px;font-weight:700;color:#d97706" title="Sisa waktu pengerjaan">
                                    ⏳ <span class="countdown-clock"><?= $remMins ?>m <?= sprintf('%02d', $remSecs) ?>s</span>
                                </div>
                            <?php else: ?>
                                <div style="margin-top:3px;font-size:11px;color:#94a3b8">⌛ Waktu Habis</div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($isSubmitted): ?>
                                <span class="bank-badge active">✓ Selesai</span>
                            <?php elseif ($isOngoing): ?>
                                <span class="bank-badge live-pulse-badge" style="background:#fef3c7;color:#92400e;border:1px solid #fde68a">
                                    <span class="pulse-dot"></span> Berlangsung
                                </span>
                            <?php else: ?>
                                <span class="bank-badge inactive">⌛ Waktu Habis</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div style="min-width:130px">
                                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:3px;font-size:11.5px">
                                    <span><strong><?= $ansCount ?></strong> / <?= $totItems ?> Soal</span>
                                    <span style="font-size:10.5px;color:#64748b;font-weight:700"><?= $pct ?>%</span>
                                </div>
                                <div style="background:#e2e8f0;border-radius:999px;height:6px;width:100%;overflow:hidden;margin-bottom:5px">
                                    <div style="background:<?= $isSubmitted ? '#10b981' : '#2563eb' ?>;height:100%;width:<?= $pct ?>%;border-radius:999px;transition:width .3s"></div>
                                </div>
                                <div>
                                    <?php if ($flgCount > 0): ?>
                                        <span class="bank-badge" style="background:#fef9c3;color:#854d0e;border:1px solid #fef08a;font-size:10px;padding:2px 6px">
                                            🟨 <?= $flgCount ?> Ragu
                                        </span>
                                    <?php else: ?>
                                        <span style="font-size:10.5px;color:#94a3b8">0 Ragu</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </td>
                        <td>
                            <div style="display:flex;flex-direction:column;gap:2px;font-size:11.5px;min-width:95px">
                                <span style="color:#059669;font-weight:700">✓ <?= $corCount ?> Benar</span>
                                <span style="color:#dc2626;font-weight:700">✕ <?= $wrgCount ?> Salah</span>
                                <?php if (!$isSubmitted && $unansCount > 0): ?>
                                    <span style="color:#64748b;font-size:10.5px">⋯ <?= $unansCount ?> Belum</span>
                                <?php endif; ?>
                            </div>
                        </td>
                        <td>
                            <?php if ($isSubmitted): ?>
                                <span class="bank-key" title="Nilai Akhir Ujian"><?= $attempt['score'] !== null ? number_format((float)$attempt['score'], 1) : '—' ?></span>
                                <?php $isPass = $attempt['score'] !== null && (float)$attempt['score'] >= ($passMinScore ?? 80.0); ?>
                                <span class="cbt-pass-label <?= $isPass ? 'is-pass' : 'is-fail' ?>"><?= $isPass ? 'Lulus' : 'Tidak lulus' ?></span>
                            <?php else: ?>
                                <?php 
                                    $liveScore = $totItems > 0 ? round(($corCount / $totItems) * 100, 1) : 0.0;
                                ?>
                                <span class="bank-key" style="background:#eff6ff;color:#1e40af;border:1px solid #bfdbfe;font-size:11.5px;width:auto;padding:0 7px" title="Estimasi nilai sementara saat ini">
                                    <?= number_format($liveScore, 1) ?>
                                    <span style="font-size:9px;font-weight:600;display:block;line-height:1;margin-top:1px;color:#3b82f6">(Live)</span>
                                </span>
                            <?php endif; ?>
                        </td>
                        <td style="text-align:center">
                            <div style="display:inline-flex;align-items:center;gap:6px;justify-content:center;flex-wrap:wrap">
                                <a href="<?= BASE_URL ?>/pretes-ujian.php?cbt_review=1&amp;attempt_id=<?= (int)$attempt['id'] ?><?= $resultFilter !== 'all' ? '&amp;result_filter=' . urlencode($resultFilter) : '' ?>" class="bank-button" style="background:#2563eb;color:#ffffff;border-color:#1d4ed8;padding:4px 9px;font-size:11.5px;border-radius:6px;text-decoration:none;display:inline-flex;align-items:center;gap:4px;line-height:1.2;font-weight:600" title="Review lembar jawaban peserta (soal benar, salah, dan ragu)">👁️ Review</a>
                                <form method="post" action="<?= $bankUrl ?>&amp;tab=results" style="margin:0;display:inline-block;" onsubmit="return confirm('Yakin ingin menghapus sesi CBT <?= htmlspecialchars(addslashes($attempt['nama_lengkap']), ENT_QUOTES, 'UTF-8') ?> (NIM: <?= htmlspecialchars($attempt['nim'] ?? '-', ENT_QUOTES, 'UTF-8') ?>)?\n\nData jawaban dan nilai akan dihapus, dan peserta dapat memulai ujian kembali.')">
                                    <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                                    <input type="hidden" name="action" value="delete_attempt">
                                    <input type="hidden" name="attempt_id" value="<?= (int)$attempt['id'] ?>">
                                    <button type="submit" class="bank-button danger-sm" title="Hapus sesi CBT peserta ini">🗑️ Hapus</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$attempts): ?>
                    <?php if ($statusFilter === 'live'): ?>
                        <tr>
                            <td colspan="10" class="bank-empty" style="padding:32px 16px">
                                <div style="font-size:24px;margin-bottom:6px">⚡</div>
                                <div style="font-weight:700;font-size:14px;color:#0f172a;margin-bottom:4px">Tidak ada peserta yang sedang mengerjakan ujian saat ini.</div>
                                <div style="font-size:12px;color:#64748b;margin-bottom:12px">Seluruh peserta telah menyelesaikan ujian atau belum ada sesi baru yang dimulai.</div>
                                <a href="<?= buildCbtResultUrl(['status_filter' => 'finished', 'page' => 1]) ?>" class="bank-button primary" style="font-size:12px;min-height:30px;padding:4px 14px">Lihat Riwayat Selesai (<?= $finishedCount ?>)</a>
                            </td>
                        </tr>
                    <?php elseif ($hasExtraFilter): ?>
                        <tr>
                            <td colspan="10" class="bank-empty" style="padding:28px 16px">
                                <div style="font-size:22px;margin-bottom:6px">🔍</div>
                                <div style="font-weight:700;color:#0f172a;margin-bottom:4px"><?= $searchQuery !== '' ? 'Tidak ditemukan sesi dengan kata kunci &ldquo;' . sanitize($searchQuery) . '&rdquo; pada filter ini.' : 'Tidak ada sesi yang cocok dengan filter ini.' ?></div>
                                <a href="<?= buildCbtResultUrl(['q' => '', 'day' => '', 'pass_filter' => 'all', 'page' => 1]) ?>" class="bank-button" style="font-size:11.5px;min-height:28px;margin-top:8px">Reset Filter</a>
                            </td>
                        </tr>
                    <?php else: ?>
                        <tr><td colspan="10" class="bank-empty">Belum ada peserta yang memulai CBT.</td></tr>
                    <?php endif; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Pagination & Footer Bar -->
        <div class="cbt-pagination-bar" style="display:flex;justify-content:space-between;align-items:center;padding:12px 20px;border-top:1px solid #e2e8f0;background:#fafcfb;flex-wrap:wrap;gap:12px">
            <div class="cbt-pagination-info" style="font-size:12px;color:#475569">
                Menampilkan <strong><?= $totalFiltered > 0 ? ($offset + 1) : 0 ?></strong> &ndash; <strong><?= min($offset + $perPage, $totalFiltered) ?></strong> dari <strong><?= $totalFiltered ?></strong> sesi
                <?php if ($totalPages > 1): ?>
                    <span style="color:#94a3b8;margin-left:6px">(Halaman <strong><?= $page ?></strong> dari <strong><?= $totalPages ?></strong>)</span>
                <?php endif; ?>
            </div>

            <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap">
                <div style="display:inline-flex;align-items:center;gap:6px;font-size:12px;color:#64748b">
                    <label for="cbtPerPageSelect">Per halaman:</label>
                    <select id="cbtPerPageSelect" class="bank-input" style="min-height:28px;padding:2px 8px;font-size:12px;width:auto;margin:0" onchange="window.location.href = this.value">
                        <?php foreach ([10, 25, 50, 100] as $pp): ?>
                            <option value="<?= buildCbtResultUrl(['per_page' => $pp, 'page' => 1]) ?>" <?= $perPage === $pp ? 'selected' : '' ?>><?= $pp ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <?php if ($totalPages > 1): ?>
                <nav class="cbt-pagination-nav" aria-label="Navigasi Halaman CBT" style="display:flex;gap:4px;align-items:center">
                    <?php if ($page > 1): ?>
                        <a href="<?= buildCbtResultUrl(['page' => 1]) ?>" class="cbt-page-link" title="Halaman Pertama">&laquo;</a>
                        <a href="<?= buildCbtResultUrl(['page' => $page - 1]) ?>" class="cbt-page-link" title="Halaman Sebelumnya">&lsaquo;</a>
                    <?php else: ?>
                        <span class="cbt-page-link disabled">&laquo;</span>
                        <span class="cbt-page-link disabled">&lsaquo;</span>
                    <?php endif; ?>

                    <?php
                    $startP = max(1, $page - 2);
                    $endP = min($totalPages, $page + 2);
                    if ($startP > 1) {
                        echo '<a href="' . buildCbtResultUrl(['page' => 1]) . '" class="cbt-page-link">1</a>';
                        if ($startP > 2) echo '<span class="cbt-page-ellipsis">&hellip;</span>';
                    }
                    for ($p = $startP; $p <= $endP; $p++) {
                        if ($p === $page) {
                            echo '<span class="cbt-page-link active" aria-current="page">' . $p . '</span>';
                        } else {
                            echo '<a href="' . buildCbtResultUrl(['page' => $p]) . '" class="cbt-page-link">' . $p . '</a>';
                        }
                    }
                    if ($endP < $totalPages) {
                        if ($endP < $totalPages - 1) echo '<span class="cbt-page-ellipsis">&hellip;</span>';
                        echo '<a href="' . buildCbtResultUrl(['page' => $totalPages]) . '" class="cbt-page-link">' . $totalPages . '</a>';
                    }
                    ?>

                    <?php if ($page < $totalPages): ?>
                        <a href="<?= buildCbtResultUrl(['page' => $page + 1]) ?>" class="cbt-page-link" title="Halaman Berikutnya">&rsaquo;</a>
                        <a href="<?= buildCbtResultUrl(['page' => $totalPages]) ?>" class="cbt-page-link" title="Halaman Terakhir">&raquo;</a>
                    <?php else: ?>
                        <span class="cbt-page-link disabled">&rsaquo;</span>
                        <span class="cbt-page-link disabled">&raquo;</span>
                    <?php endif; ?>
                </nav>
                <?php endif; ?>
            </div>
        </div>

        <div class="bank-footer">
            <span>Nilai CBT dan progres pengerjaan disimpan terpisah dari penilaian praktik dan nilai impor lama.</span>
            <?php if (!empty($attempts)): ?><span>Menampilkan sesi pengerjaan CBT dengan kontrol live monitor &amp; paginasi.</span><?php endif; ?>
        </div>
    </section>
    <?php endif; ?>
</div>

<!-- ═══════════════════════════════════════════════════════════════════════
     MODAL 1: BUAT / EDIT PAKET SOAL (TANPA PINDAH HALAMAN)
     ═══════════════════════════════════════════════════════════════════════ -->
<div class="bank-modal-backdrop" id="modalPackageForm" style="display:none;pointer-events:none;visibility:hidden" role="dialog" aria-modal="true" aria-labelledby="modalPackageTitle">
    <div class="bank-modal">
        <div class="bank-modal-head">
            <h3 id="modalPackageTitle">Buat Paket Soal Baru</h3>
            <button type="button" class="bank-modal-close" data-close-modal aria-label="Tutup">✕</button>
        </div>
        <form method="post" action="<?= $bankUrl ?>&amp;tab=packages" id="formPackageModal">
            <input type="hidden" name="action" value="save_package">
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
            <input type="hidden" name="id" id="pkgModalId" value="0">
            <div class="bank-modal-body">
                <div class="bank-field">
                    <label class="bank-label" for="pkgModalName">Nama Paket Soal *</label>
                    <input id="pkgModalName" class="bank-input" name="name" maxlength="150" required placeholder="Contoh: Paket Pretes Gelombang 1 2026 / Pretes Ubudiyah Reguler">
                </div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px">
                    <div class="bank-field">
                        <label class="bank-label" for="pkgModalDuration">Durasi Ujian (Menit) *</label>
                        <input id="pkgModalDuration" class="bank-input" type="number" name="duration_minutes" min="5" max="300" required value="30">
                    </div>
                    <div class="bank-field">
                        <label class="bank-label" for="pkgModalCount">Target Soal Acak per Sesi *</label>
                        <input id="pkgModalCount" class="bank-input" type="number" name="question_count" min="5" max="200" required value="25">
                    </div>
                </div>
                <div class="bank-field">
                    <label class="bank-label" for="pkgModalCode">Kode Paket (Opsional)</label>
                    <input id="pkgModalCode" class="bank-input" name="code" maxlength="64" placeholder="Otomatis jika dikosongkan">
                </div>
                <div class="bank-field">
                    <label class="bank-label" for="pkgModalDesc">Deskripsi / Keterangan</label>
                    <textarea id="pkgModalDesc" class="bank-input" name="description" rows="3" placeholder="Keterangan materi, sasaran peserta, atau catatan kurikulum…"></textarea>
                </div>
                <label class="bank-toggle" style="margin-top:10px">
                    <input type="checkbox" name="is_active" id="pkgModalActive" value="1" checked>
                    Jadikan Paket Aktif
                </label>
            </div>
            <div class="bank-modal-foot">
                <button type="button" class="bank-button" data-close-modal>Batal</button>
                <button type="submit" class="bank-button primary">Simpan Paket Soal</button>
            </div>
        </form>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════════════
     MODAL 2: PENGATUR SESI UJIAN (LARGE MODAL - TANPA PINDAH HALAMAN)
     ═══════════════════════════════════════════════════════════════════════ -->
<div class="bank-modal-backdrop" id="modalSessionForm" style="display:none;pointer-events:none;visibility:hidden" role="dialog" aria-modal="true" aria-labelledby="modalSessionTitle">
    <div class="bank-modal large">
        <div class="bank-modal-head">
            <h3 id="modalSessionTitle">Pengatur Sesi Ujian Baru</h3>
            <button type="button" class="bank-modal-close" data-close-modal aria-label="Tutup">✕</button>
        </div>
        <form method="post" action="<?= $bankUrl ?>&amp;tab=sessions" id="formSessionModal">
            <input type="hidden" name="action" value="save_session">
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
            <input type="hidden" name="id" id="sesModalId" value="0">
            <div class="bank-modal-body">
                <!-- 1. Identitas Sesi -->
                <div style="display:grid;grid-template-columns:2fr 1fr;gap:14px">
                    <div class="bank-field">
                        <label class="bank-label" for="sesModalTitle">Nama Sesi Ujian *</label>
                        <input id="sesModalTitle" class="bank-input" name="title" maxlength="150" required placeholder="Contoh: Sesi 1 - Pretes Gelombang 1 2026">
                    </div>
                    <div class="bank-field">
                        <label class="bank-label" for="sesModalCode">Kode Sesi</label>
                        <input id="sesModalCode" class="bank-input" name="code" maxlength="64" placeholder="Otomatis jika kosong">
                    </div>
                </div>

                <!-- 1b. Jenis Sesi: Resmi / Tryout -->
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;background:#eff6ff;border:1px solid #bfdbfe;border-radius:12px;padding:12px 14px">
                    <div class="bank-field" style="margin:0">
                        <label class="bank-label" for="sesModalMode" style="color:#1e3a8a">Jenis Sesi *</label>
                        <select id="sesModalMode" class="bank-input" name="mode">
                            <option value="resmi">🎓 Ujian Resmi (1× pengerjaan, butuh pendaftaran pretes)</option>
                            <option value="tryout">📝 Tryout / Latihan (terpisah dari ujian resmi)</option>
                        </select>
                        <span style="font-size:11px;color:#1e40af;display:block;margin-top:4px">Tryout tampil di menu <strong>Tryout CBT</strong> mahasiswa yang terdaftar periode pretes aktif, tanpa verifikasi wajah, dan tidak memakan jatah ujian resmi. Beberapa tryout boleh aktif bersamaan dengan sesi resmi.</span>
                    </div>
                    <div class="bank-field" id="sesModalMaxAttemptsWrap" style="margin:0">
                        <label class="bank-label" for="sesModalMaxAttempts" style="color:#1e3a8a">Batas Pengerjaan Tryout</label>
                        <input type="number" id="sesModalMaxAttempts" class="bank-input" name="max_attempts" min="0" max="99" value="0">
                        <span style="font-size:11px;color:#1e40af;display:block;margin-top:4px">0 = boleh diulang tanpa batas; 1 = sekali saja.</span>
                    </div>
                </div>

                <!-- 2. Paket Soal yang Digunakan -->
                <div class="bank-field">
                    <label class="bank-label">Pilih Paket Soal yang Digunakan *</label>
                    <div style="background:#f8faf8;border:1px solid #dce5df;border-radius:10px;padding:12px;display:flex;gap:14px;flex-wrap:wrap">
                        <?php foreach ($packages as $pkg): ?>
                            <label class="bank-toggle" style="background:#fff;padding:6px 12px;border:1px solid #e2e8e2;border-radius:8px">
                                <input type="checkbox" name="package_ids[]" id="pkgCheckbox_<?= (int)$pkg['id'] ?>" value="<?= (int)$pkg['id'] ?>">
                                <span><strong><?= sanitize($pkg['name']) ?></strong> (<?= (int)$pkg['question_count_actual'] ?> soal aktif)</span>
                            </label>
                        <?php endforeach; ?>
                        <?php if (empty($packages)): ?>
                            <div style="color:#b91c1c;font-size:12px">Belum ada paket soal dibuat. Buat paket terlebih dahulu.</div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- 3. Kadar / Kuota per Kategori Materi -->
                <div class="bank-field" style="background:#f9fbf9;border:1px solid #dae5dc;border-radius:12px;padding:14px">
                    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px">
                        <div>
                            <label class="bank-label" style="margin:0">Kadar / Kuota pada Masing-Masing Kategori (Proporsi Materi)</label>
                            <span style="font-size:11px;color:#6d7f72">Tentukan jumlah butir soal yang diacak dari masing-masing kategori untuk tiap peserta.</span>
                        </div>
                        <span id="sesModalTotalQuotaBadge" class="bank-badge package" style="font-size:11px;padding:5px 10px">Bebas / Acak Paket</span>
                    </div>
                    <div id="sesModalCategoryQuotasContainer">
                        <div style="padding:12px;color:#8a998f;font-size:12px;text-align:center">Pilih paket soal di atas untuk mengatur kadar kategori.</div>
                    </div>
                </div>

                <!-- 4. Pengaturan Kamera & Waktu -->
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px">
                    <div class="bank-field">
                        <label class="bank-label" for="sesModalCameraMode">Kewajiban Kamera &amp; Proctoring *</label>
                        <select id="sesModalCameraMode" class="bank-input" name="camera_mode" required>
                            <option value="wajib">📷 Wajib Kamera (Verifikasi Scan Wajah + Proctoring 30s)</option>
                            <option value="tidak_aktif">🚫 Bebas / Tanpa Kamera (Langsung Masuk Lembar Soal)</option>
                            <option value="opsional">📷 Kamera Opsional (Bisa Ujian Tanpa Kamera)</option>
                        </select>
                    </div>
                    <div class="bank-field">
                        <label class="bank-label" for="sesModalDuration">Durasi Waktu Ujian (Menit) *</label>
                        <input id="sesModalDuration" class="bank-input" type="number" name="duration_minutes" min="5" max="360" required value="30">
                    </div>
                </div>

                <!-- 5. Cara Menilai & Nilai KKM -->
                <div style="display:grid;grid-template-columns:1.5fr 1fr;gap:14px">
                    <div class="bank-field">
                        <label class="bank-label" for="sesModalScoringMethod">Cara Menilai (Metode Scoring) *</label>
                        <select id="sesModalScoringMethod" class="bank-input" name="scoring_method" required>
                            <option value="standar">Standar Skala 100: (Benar / Total) × 100</option>
                            <option value="penalti">Sistem Minus UTBK: Benar +4, Salah -1, Kosong 0</option>
                        </select>
                    </div>
                    <div class="bank-field">
                        <label class="bank-label" for="sesModalPassingGrade">Ambang Batas Kelulusan (KKM)</label>
                        <input id="sesModalPassingGrade" class="bank-input" type="number" step="0.01" name="passing_grade" min="0" max="100" value="65.00">
                    </div>
                </div>

                <!-- 6. Kapan Ujian Bisa Dimulai & Kapan Tidak Bisa Memulai -->
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px">
                    <div class="bank-field">
                        <label class="bank-label" for="sesModalStartTime">Kapan Ujian Bisa Dimulai (Jadwal Buka)</label>
                        <input id="sesModalStartTime" class="bank-input" type="datetime-local" name="start_time" placeholder="Pilih waktu mulai">
                        <span style="font-size:10.5px;color:#6b7972;margin-top:3px;display:block">Sebelum waktu ini, peserta belum bisa membuka lembar ujian.</span>
                    </div>
                    <div class="bank-field">
                        <label class="bank-label" for="sesModalEndTime">Kapan Batas Tidak Bisa Memulai (Jadwal Tutup)</label>
                        <input id="sesModalEndTime" class="bank-input" type="datetime-local" name="end_time" placeholder="Pilih batas akhir">
                        <span style="font-size:10.5px;color:#6b7972;margin-top:3px;display:block">Setelah waktu ini, pendaftaran ujian sesi ini ditutup bagi peserta baru.</span>
                    </div>
                </div>

                <!-- 7. Token Akses Ujian -->
                <div style="background:#fefce8;border:1px solid #fef08a;border-radius:12px;padding:14px;margin-bottom:14px">
                    <label class="bank-label" style="color:#854d0e">Token Akses Ujian Peserta</label>
                    <div style="display:flex;align-items:center;gap:14px;flex-wrap:wrap">
                        <div class="bank-token-group">
                            <input id="sesModalToken" class="bank-input" name="token" maxlength="32" placeholder="TOKEN">
                            <button type="button" class="bank-button" onclick="generateRandomToken()" title="Acak token 6 karakter baru">🎲 Acak Token</button>
                        </div>
                        <label class="bank-toggle" style="margin:0">
                            <input type="checkbox" name="token_required" id="sesModalTokenRequired" value="1">
                            <strong>Wajibkan Token Ujian</strong> (Peserta wajib memasukkan token ini sebelum memulai CBT)
                        </label>
                    </div>
                </div>

                <!-- 8. Proteksi Anti-Contek & Pertukaran Soal Pelanggaran -->
                <div class="bank-field" style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:12px;padding:14px;margin-bottom:14px">
                    <label class="bank-label" for="sesModalShuffleOnViolation" style="color:#166534;margin-bottom:6px">🛡️ Proteksi Anti-Contek Saat Pelanggaran Terdeteksi</label>
                    <div style="font-size:11.5px;color:#1e3a2b;margin-bottom:8px;line-height:1.5">
                        Tindakan sistem ketika peserta melakukan pelanggaran (ganti tab, jendela diminimalkan, wajah hilang/berbeda):
                    </div>
                    <select id="sesModalShuffleOnViolation" class="bank-input" name="shuffle_on_violation">
                        <option value="1">✅ Aktif: Seluruh soal belum dijawab diganti soal baru dari bank cadangan &amp; abjad opsi diacak (Anti-Joki/Bocoran)</option>
                        <option value="0">❌ Nonaktif: Posisi soal hanya dipindah dan abjad diacak tanpa mengambil soal cadangan</option>
                    </select>
                    <span style="font-size:11px;color:#4b6354;margin-top:5px;display:block">
                        💡 <strong>Sangat Dianjurkan</strong> bila memiliki bank soal cadangan (misal: upload ~170 soal, dikerjakan 50 soal). Soal yang sudah dijawab peserta tetap aman dan skor tidak berkurang, hanya susunan abjadnya disamarkan ulang.
                    </span>
                </div>

                <!-- 9. Deskripsi & Status Aktif -->
                <div class="bank-field">
                    <label class="bank-label" for="sesModalDesc">Deskripsi / Petunjuk untuk Peserta Sesi Ini</label>
                    <textarea id="sesModalDesc" class="bank-input" name="description" rows="2" placeholder="Keterangan pengawas, ruang lab, atau catatan khusus…"></textarea>
                </div>
                <label class="bank-toggle">
                    <input type="checkbox" name="is_active" id="sesModalActive" value="1" checked>
                    <strong>Jadikan Sesi Aktif Saat Ini</strong> (Peserta pretes yang login otomatis diarahkan ke sesi ini)
                </label>
                <input type="hidden" name="exam_open" value="0">
                <label class="bank-toggle" id="sesModalExamOpenWrap" style="margin-top:8px">
                    <input type="checkbox" name="exam_open" id="sesModalExamOpen" value="1">
                    <strong>Buka Pengerjaan Ujian</strong> (peserta dapat menekan "Kerjakan Soal Pretes"; tetap mengikuti jadwal mulai/batas di atas). Bisa juga diatur dengan tombol 🟢 Buka / 🔴 Tutup Ujian.
                </label>
            </div>
            <div class="bank-modal-foot">
                <button type="button" class="bank-button" data-close-modal>Batal</button>
                <button type="submit" class="bank-button primary">Simpan Sesi Ujian</button>
            </div>
        </form>
    </div>
</div>

<!-- ===================================================================
     MODAL 3: KELOLA & KOLEKSI SOAL PAKET (MODAL CANTIK & LENGKAP)
     =================================================================== -->
<div class="bank-modal-backdrop" id="modalPackageQuestions" style="display:none;pointer-events:none;visibility:hidden" role="dialog" aria-modal="true" aria-labelledby="pkgQuestionsTitle">
    <div class="bank-modal extra-large">
        <div class="modal-header-banner">
            <div>
                <h3>📋 Koleksi Soal — <span id="pkgQuestionsTitle">Paket Soal</span></h3>
                <div style="font-size:12px;opacity:0.9;margin-top:3px;display:flex;align-items:center;gap:8px">
                    <span id="pkgQuestionsCodeBadge" class="bank-code" style="background:rgba(255,255,255,0.2);color:#fff"></span>
                    <span id="pkgQuestionsCountBadge" style="font-weight:600"></span>
                </div>
            </div>
            <div style="display:flex;align-items:center;gap:10px">
                <button type="button" class="bank-button gold" id="btnModalAddQuestion" style="min-height:34px;font-size:12px;font-weight:700">＋ Tambah Soal Baru</button>
                <a class="bank-button" id="btnModalImportExcel" href="<?= $bankUrl ?>&amp;tab=import" style="min-height:34px;font-size:12px;background:rgba(255,255,255,0.15);color:#fff;border-color:rgba(255,255,255,0.3)">📥 Impor Excel</a>
                <a data-no-spa class="bank-button" href="<?= $bankUrl ?>&amp;download=bank" style="min-height:34px;font-size:12px;background:rgba(255,255,255,0.15);color:#fff;border-color:rgba(255,255,255,0.3)">📤 Ekspor .xlsx</a>
                <button type="button" class="bank-modal-close" data-close-modal aria-label="Tutup">✕</button>
            </div>
        </div>

        <div class="modal-q-toolbar">
            <div class="modal-q-toolbar-left">
                <input id="modalQSearch" class="bank-input" type="search" placeholder="🔍 Cari butir pertanyaan, kode, kategori…" style="max-width:320px;padding:7px 12px">
                <select id="modalQCategory" class="bank-input" style="max-width:200px;padding:7px 12px">
                    <option value="">Semua Kategori</option>
                </select>
                <select id="modalQStatus" class="bank-input" style="max-width:140px;padding:7px 12px">
                    <option value="">Semua Status</option>
                    <option value="aktif">Aktif</option>
                    <option value="nonaktif">Nonaktif</option>
                </select>
            </div>
            <span id="modalQCountBadge" style="font-size:12px;color:#64748b;font-weight:600"></span>
        </div>

        <div class="modal-q-list" id="modalQListContainer">
            <!-- Dynamic question cards rendered by JS -->
        </div>

        <div class="bank-modal-foot" style="justify-content:space-between">
            <div style="font-size:12px;color:#64748b">
                💡 <em>Pilihan jawaban yang berlatar hijau adalah <strong>Kunci Jawaban</strong> yang benar.</em>
            </div>
            <button type="button" class="bank-button" data-close-modal>Tutup Jendela</button>
        </div>
    </div>
</div>

<!-- ===================================================================
     MODAL 4: TAMBAH / EDIT BUTIR SOAL (MODAL CANTIK & LENGKAP)
     =================================================================== -->
<div class="bank-modal-backdrop" id="modalQuestionForm" style="display:none;pointer-events:none;visibility:hidden" role="dialog" aria-modal="true" aria-labelledby="modalQuestionFormTitle">
    <div class="bank-modal large">
        <div class="bank-modal-head">
            <div>
                <h3 id="modalQuestionFormTitle">＋ Tambah Butir Soal Baru</h3>
                <span id="modalQuestionFormSubtitle" style="font-size:12px;color:#557063">Masukkan butir pertanyaan dan 5 pilihan jawaban A–E</span>
            </div>
            <button type="button" class="bank-modal-close" data-close-modal aria-label="Tutup">✕</button>
        </div>
        <form method="post" action="<?= $bankUrl ?>" id="formQuestionModal">
            <input type="hidden" name="action" value="save_question">
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
            <input type="hidden" name="current_tab" value="<?= sanitize($activeTab) ?>">
            <input type="hidden" name="id" id="qFormId" value="0">
            <div class="bank-modal-body">
                <div style="display:grid;grid-template-columns:1.2fr 1fr;gap:14px;margin-bottom:14px">
                    <div class="bank-field" style="margin:0">
                        <label class="bank-label" for="qFormPackageId">Paket Soal Tujuan *</label>
                        <select id="qFormPackageId" class="bank-input" name="package_id" required>
                            <?php foreach ($packages as $pkg): ?>
                                <option value="<?= (int)$pkg['id'] ?>">[<?= sanitize($pkg['code']) ?>] <?= sanitize($pkg['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="bank-field" style="margin:0">
                        <label class="bank-label" for="qFormCategory">Kategori Materi *</label>
                        <input id="qFormCategory" list="bankCategoriesList" class="bank-input" name="category" maxlength="80" required value="Umum" placeholder="Misal: Fiqih, Al-Qur'an, Ubudiyah">
                        <datalist id="bankCategoriesList">
                            <?php foreach ($categories as $category): ?><option value="<?= sanitize($category) ?>"><?php endforeach; ?>
                        </datalist>
                    </div>
                </div>

                <div class="bank-field" style="margin-bottom:16px">
                    <label class="bank-label" for="qFormText">Teks Pertanyaan *</label>
                    <textarea id="qFormText" class="bank-input" name="question_text" rows="3" maxlength="10000" required placeholder="Tuliskan pertanyaan secara jelas dan terstruktur…"></textarea>
                </div>

                <div style="margin-bottom:14px">
                    <label class="bank-label" style="margin-bottom:4px">Pilihan Jawaban (A – E) &amp; Kunci Jawaban *</label>
                    <p style="font-size:11.5px;color:#64748b;margin:0 0 10px 0">Pilih radio button pada pilihan yang merupakan <strong>kunci jawaban yang benar</strong>:</p>
                    
                    <?php foreach (range('A', 'E') as $letter): ?>
                        <div class="question-opt-row <?= $letter === 'A' ? 'selected-key' : '' ?>" id="qFormRow_<?= $letter ?>">
                            <label class="question-opt-radio-label" title="Jadikan pilihan <?= $letter ?> sebagai kunci jawaban">
                                <input type="radio" name="correct_answer" value="<?= $letter ?>" id="qFormRadio_<?= $letter ?>" <?= $letter === 'A' ? 'checked' : '' ?> required>
                                <span class="question-opt-letter-tag">Pilihan <?= $letter ?></span>
                            </label>
                            <div style="flex:1">
                                <textarea id="qFormOpt_<?= $letter ?>" class="bank-input" name="option_<?= $letter ?>" rows="2" maxlength="2000" required placeholder="Teks pilihan jawaban <?= $letter ?>…" style="margin:0;font-size:13px"></textarea>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <label class="bank-toggle" style="margin-top:8px">
                    <input type="checkbox" name="is_active" id="qFormActive" value="1" checked>
                    <strong>Aktifkan butir soal ini</strong> (Diikutkan dalam undian paket ujian peserta)
                </label>
            </div>
            <div class="bank-modal-foot">
                <button type="button" class="bank-button" data-close-modal>Batal</button>
                <button type="submit" class="bank-button primary" id="btnSaveQuestionModal">✓ Simpan Butir Soal</button>
            </div>
        </form>
    </div>
</div>

<script>
window.CBT_PACKAGE_CATEGORIES = <?= json_encode($packageCategoriesMap, JSON_UNESCAPED_UNICODE) ?>;
window.CBT_CSRF_TOKEN = <?= json_encode(csrfToken()) ?>;
window.CBT_BASE_URL = <?= json_encode(BASE_URL) ?>;
window.CBT_BANK_URL = <?= json_encode($bankUrl) ?>;
window.CBT_RESULT_FILTER = <?= json_encode($resultFilter) ?>;
window.CBT_STATUS_FILTER = <?= json_encode($statusFilter ?? 'all') ?>;
window.CBT_SEARCH_QUERY = <?= json_encode($searchQuery ?? '') ?>;
window.CBT_DAY_FILTER = <?= json_encode($dayFilter ?? '') ?>;
window.CBT_PASS_FILTER = <?= json_encode($passFilter ?? 'all') ?>;
window.CBT_PASS_MIN_SCORE = <?= json_encode((float)($passMinScore ?? 80.0)) ?>;
window.CBT_PAGE = <?= (int)($page ?? 1) ?>;
window.CBT_PER_PAGE = <?= (int)($perPage ?? 25) ?>;
</script>
<script src="<?= BASE_URL ?>/pretes-ujian.php?cbt_admin_asset=1&amp;v=<?= filemtime(__DIR__ . '/../assets/js/pretes-cbt-admin.js') ?>"></script>
