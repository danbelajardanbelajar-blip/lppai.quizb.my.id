<link rel="stylesheet" href="<?= BASE_URL ?>/pretes-ujian.php?cbt_style=bank&amp;v=<?= filemtime(__DIR__ . '/../assets/css/pretes-cbt-admin.css') ?>">
<div class="bank-app">
    <header class="bank-hero">
        <div>
            <div class="bank-eyebrow">LPPAI / Pusat Asesmen &amp; Pengatur Sesi CBT</div>
            <h2>Bank, Paket &amp; Pengatur Sesi CBT Pretes</h2>
            <p>Atur sesi pelaksanaan ujian, tentukan paket soal aktif, kadar proporsi kategori materi, waktu, kewajiban kamera, dan token ujian dalam satu sistem terintegrasi.</p>
        </div>
        <div class="bank-actions">
            <button type="button" class="bank-button primary" onclick="openSessionModal(false)">🎯 ＋ Buat Sesi Ujian</button>
            <button type="button" class="bank-button gold" onclick="openPackageModal(false)">📦 ＋ Buat Paket Soal</button>
            <a data-no-spa class="bank-button ghost" href="<?= $bankUrl ?>&amp;tab=import">Impor Excel</a>
            <a data-no-spa class="bank-button" href="<?= $bankUrl ?>&amp;tab=form">＋ Tambah Soal</a>
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
            <span>Dari <?= (int)$stats['total'] ?> sesi pengerjaan (Rata-rata: <?= $stats['average_score'] !== null ? number_format((float)$stats['average_score'], 1) : '—' ?>)</span>
        </div>
    </div>

    <?php if ($flash): ?><div class="bank-notice" role="status"><?= sanitize($flash) ?></div><?php endif; ?>
    <?php if ($message): ?><div class="bank-notice error" role="alert"><?= sanitize($message) ?><?php if ($errors): ?><ul><?php foreach ($errors as $error): ?><li><?= sanitize($error) ?></li><?php endforeach; ?></ul><?php endif; ?></div><?php endif; ?>

    <nav class="bank-tabs" aria-label="Kelola CBT">
        <?php foreach ([
            'sessions' => 'Sesi Ujian',
            'packages' => 'Paket Soal',
            'bank' => 'Bank Soal',
            'form' => 'Tambah / Edit Soal',
            'import' => 'Impor Excel',
            'results' => 'Hasil Ujian'
        ] as $tab => $label): ?>
            <a data-no-spa href="<?= $bankUrl ?>&amp;tab=<?= $tab ?>" class="<?= $activeTab === $tab ? 'active' : '' ?>" <?= $activeTab === $tab ? 'aria-current="page"' : '' ?>>
                <?= $label ?>
                <?php if ($tab === 'sessions'): ?><span class="bank-tab-count"><?= count($sessions) ?></span><?php endif; ?>
                <?php if ($tab === 'packages'): ?><span class="bank-tab-count"><?= count($packages) ?></span><?php endif; ?>
                <?php if ($tab === 'bank'): ?><span class="bank-tab-count"><?= count($questions) ?></span><?php endif; ?>
            </a>
        <?php endforeach; ?>
    </nav>

    <!-- ═══════════════════════════════════════════════════════════════════
         TAB 1: SESI UJIAN (PENGATUR SESI)
         ═══════════════════════════════════════════════════════════════════ -->
    <?php if ($activeTab === 'sessions'): ?>
    <section class="bank-panel">
        <div class="bank-panel-head">
            <div>
                <h3>Pengatur Sesi Ujian CBT</h3>
                <p>Atur paket soal yang ditampilkan, kadar kuota masing-masing kategori, kewajiban kamera, waktu, penilaian, jadwal mulai/selesai, dan token ujian.</p>
            </div>
            <button type="button" class="bank-button primary" onclick="openSessionModal(false)">🎯 ＋ Buat Sesi Baru</button>
        </div>
        <div class="bank-table-scroll">
            <table class="bank-table">
                <thead>
                    <tr>
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
                    <tr><td colspan="10" class="bank-empty">Belum ada sesi ujian dibuat. Klik tombol <strong>＋ Buat Sesi Baru</strong> untuk mengatur sesi ujian pertama.</td></tr>
                <?php else: ?>
                    <?php foreach ($sessions as $sIdx => $ses): ?>
                    <tr>
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
                            <button type="button" class="bank-button" style="min-height:28px;padding:4px 8px;font-size:11px" onclick='openSessionModal(true, <?= htmlspecialchars(json_encode($ses, JSON_UNESCAPED_UNICODE), ENT_QUOTES, "UTF-8") ?>)'>✏️ Edit</button>
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
         TAB 2: PAKET SOAL
         ═══════════════════════════════════════════════════════════════════ -->
    <?php elseif ($activeTab === 'packages'): ?>
    <section class="bank-panel">
        <div class="bank-panel-head">
            <div>
                <h3>Daftar Paket Soal CBT</h3>
                <p>Kelola wadah materi soal ujian. Tambah dan edit paket soal dilakukan langsung di jendela modal tanpa berpindah halaman.</p>
            </div>
            <button type="button" class="bank-button gold" onclick="openPackageModal(false)">📦 ＋ Buat Paket Soal Baru</button>
        </div>
        <div class="bank-table-scroll">
            <table class="bank-table">
                <thead>
                    <tr>
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
                    <tr><td colspan="8" class="bank-empty">Belum ada paket soal. Klik tombol <strong>＋ Buat Paket Soal Baru</strong> untuk membuat paket pertama.</td></tr>
                <?php else: ?>
                    <?php foreach ($packages as $idx => $pkg): ?>
                    <tr>
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
                            <button type="button" class="bank-button gold" style="min-height:28px;padding:4px 8px;font-size:11px" onclick="openQuickQuestionModal(<?= (int)$pkg['id'] ?>, '<?= htmlspecialchars(addslashes($pkg['name']), ENT_QUOTES, 'UTF-8') ?>')">＋ Soal</button>
                            <a data-no-spa class="bank-button" href="<?= $bankUrl ?>&amp;tab=bank&amp;filter_pkg=<?= (int)$pkg['id'] ?>" style="min-height:28px;padding:4px 8px;font-size:11px" title="Lihat semua soal di paket ini">Lihat Soal</a>
                            <button type="button" class="bank-button" style="min-height:28px;padding:4px 8px;font-size:11px" onclick='openPackageModal(true, <?= htmlspecialchars(json_encode($pkg, JSON_UNESCAPED_UNICODE), ENT_QUOTES, "UTF-8") ?>)'>✏️ Edit</button>
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
         TAB 3: BANK SOAL
         ═══════════════════════════════════════════════════════════════════ -->
    <?php elseif ($activeTab === 'bank'): ?>
    <section class="bank-panel">
        <div class="bank-panel-head">
            <div>
                <h3>Koleksi soal</h3>
                <p>Kelola butir pertanyaan, kunci jawaban, dan alokasi paket soal.</p>
            </div>
            <div class="bank-actions">
                <a data-no-spa class="bank-button" href="<?= $bankUrl ?>&amp;download=template">↓ Template .xlsx</a>
                <a data-no-spa class="bank-button primary" href="<?= $bankUrl ?>&amp;download=bank">Ekspor Semua Soal .xlsx</a>
            </div>
        </div>
        <div class="bank-filters">
            <input id="bankSearch" class="bank-input" type="search" placeholder="Cari pertanyaan, kode, atau kategori…" aria-label="Cari soal">
            <select id="bankPackageFilter" class="bank-input" aria-label="Filter paket soal">
                <option value="">Semua paket soal</option>
                <?php foreach ($packages as $pkg): ?>
                    <option value="<?= (int)$pkg['id'] ?>" <?= ((int)($_GET['filter_pkg'] ?? 0) === (int)$pkg['id']) ? 'selected' : '' ?>>
                        <?= sanitize($pkg['name']) ?> (<?= (int)$pkg['question_count_actual'] ?> aktif)<?= $pkg['is_active'] ? ' ★' : '' ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <select id="bankCategory" class="bank-input" aria-label="Filter kategori">
                <option value="">Semua kategori</option>
                <?php foreach ($categories as $category): ?>
                    <option value="<?= sanitize($category) ?>"><?= sanitize($category) ?></option>
                <?php endforeach; ?>
            </select>
            <select id="bankStatus" class="bank-input" aria-label="Filter status">
                <option value="">Semua status</option>
                <option value="aktif">Aktif</option>
                <option value="nonaktif">Nonaktif</option>
            </select>
        </div>
        <p class="bank-scroll-hint">Geser tabel untuk melihat paket, kategori, kunci, status, dan tombol edit.</p>
        <div class="bank-table-scroll">
            <table class="bank-table">
                <thead>
                    <tr>
                        <th style="width:40px">No.</th>
                        <th>Pertanyaan</th>
                        <th>Paket Soal</th>
                        <th>Kategori</th>
                        <th>Kunci</th>
                        <th>Status</th>
                        <th>Aksi</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($questions as $i => $question): ?>
                    <tr data-bank-row data-package-id="<?= (int)($question['package_id'] ?? 1) ?>" data-category="<?= sanitize($question['category']) ?>" data-status="<?= $question['is_active'] ? 'aktif' : 'nonaktif' ?>">
                        <td style="color:#8b978e"><?= $i + 1 ?></td>
                        <td>
                            <div class="bank-question"><?= sanitize($question['question_text']) ?></div>
                            <span class="bank-code"><?= sanitize($question['code']) ?></span>
                            <details>
                                <summary>Lihat pilihan A–E</summary>
                                <?php foreach (json_decode($question['options_json'], true) as $letter => $text): ?>
                                    <div class="bank-choice <?= $letter === $question['correct_answer'] ? 'correct' : '' ?>">
                                        <b><?= $letter ?>.</b> <?= sanitize($text) ?>
                                    </div>
                                <?php endforeach; ?>
                            </details>
                        </td>
                        <td><span class="bank-badge package"><?= sanitize($question['package_name'] ?? ('Paket #' . ($question['package_id'] ?? 1))) ?></span></td>
                        <td><span class="bank-badge"><?= sanitize($question['category']) ?></span></td>
                        <td><span class="bank-key"><?= sanitize($question['correct_answer']) ?></span></td>
                        <td><span class="bank-badge <?= $question['is_active'] ? 'active' : 'inactive' ?>"><?= $question['is_active'] ? 'Aktif' : 'Nonaktif' ?></span></td>
                        <td><a data-no-spa class="bank-button" href="<?= $bankUrl ?>&amp;edit=<?= (int)$question['id'] ?>">Edit</a></td>
                    </tr>
                <?php endforeach; ?>
                <tr id="bankEmpty" hidden><td colspan="7" class="bank-empty">Tidak ada soal yang cocok dengan pencarian / filter.</td></tr>
                </tbody>
            </table>
        </div>
        <div class="bank-footer">
            <span id="bankCount">Menampilkan <?= count($questions) ?> soal</span>
            <div class="bank-pager">
                <label>Per halaman <select id="bankPageSize" class="bank-input" style="width:auto;padding:6px"><option>10</option><option>20</option><option>50</option></select></label>
                <button id="bankPrevious" class="bank-button" type="button">←</button>
                <span id="bankPage"></span>
                <button id="bankNext" class="bank-button" type="button">→</button>
            </div>
        </div>
    </section>

    <!-- ═══════════════════════════════════════════════════════════════════
         TAB 4: FORM TAMBAH / EDIT SOAL
         ═══════════════════════════════════════════════════════════════════ -->
    <?php elseif ($activeTab === 'form'): ?>
    <section class="bank-panel">
        <div class="bank-panel-head">
            <div>
                <h3><?= empty($form['id']) ? 'Buat Soal Baru' : 'Edit Soal #' . (int)$form['id'] ?></h3>
                <p>Tulis pertanyaan, tentukan paket soal tujuan, isi 5 pilihan jawaban, dan tetapkan kunci.</p>
            </div>
            <span class="bank-badge">Pilihan ganda A–E</span>
        </div>
        <div class="bank-panel-body">
            <?php if (empty($packages)): ?>
                <div class="bank-empty-state">
                    <span class="bank-empty-state-icon">⚠️</span>
                    <h3>Buat Paket Soal Terlebih Dahulu</h3>
                    <p>Sebelum Anda dapat memasukkan butir pertanyaan, sistem mewajibkan minimal satu <strong>Paket Soal</strong> telah dibuat agar butir soal memiliki wadah kurikulum dan durasi ujian.</p>
                    <button type="button" class="bank-button primary" onclick="openPackageModal(false)">📦 Buat Paket Soal Sekarang</button>
                </div>
            <?php else: ?>
                <form method="post" action="<?= $bankUrl ?>&amp;tab=form">
                    <input type="hidden" name="action" value="save_question">
                    <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                    <input type="hidden" name="id" value="<?= (int)($form['id'] ?? 0) ?>">
                    <div class="bank-form-grid">
                        <div>
                            <div class="bank-field">
                                <label class="bank-label" for="bankFormPackage">Paket Soal *</label>
                                <select id="bankFormPackage" class="bank-input" name="package_id" required>
                                    <?php 
                                    $selectedPkgId = (int)($form['package_id'] ?? ($_GET['pkg_id'] ?? ($activePackage['id'] ?? ($packages[0]['id'] ?? 1))));
                                    foreach ($packages as $pkg): 
                                    ?>
                                        <option value="<?= (int)$pkg['id'] ?>" <?= $selectedPkgId === (int)$pkg['id'] ? 'selected' : '' ?>>
                                            [<?= sanitize($pkg['code']) ?>] <?= sanitize($pkg['name']) ?> (<?= (int)$pkg['question_count_actual'] ?> soal aktif / target <?= (int)$pkg['question_count'] ?>)<?= $pkg['is_active'] ? ' — AKTIF' : '' ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="bank-field">
                                <label class="bank-label" for="bankQuestion">Pertanyaan *</label>
                                <textarea id="bankQuestion" class="bank-input" name="question_text" rows="4" maxlength="10000" required placeholder="Tulis pertanyaan secara jelas dan spesifik…"><?= sanitize($form['question_text'] ?? '') ?></textarea>
                            </div>
                            <div class="bank-options">
                                <?php foreach (range('A', 'E') as $letter): ?>
                                    <label>
                                        <span class="bank-option-label"><span class="bank-letter"><?= $letter ?></span>Pilihan <?= $letter ?></span>
                                        <textarea class="bank-input" name="option_<?= $letter ?>" maxlength="2000" rows="2" required placeholder="Isi pilihan <?= $letter ?>"><?= sanitize($form['option_' . $letter] ?? '') ?></textarea>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <aside class="bank-form-aside">
                            <h4>Pengaturan Soal</h4>
                            <div class="bank-field">
                                <label class="bank-label" for="bankFormCategory">Kategori Materi *</label>
                                <input id="bankFormCategory" list="bankCategories" class="bank-input" name="category" maxlength="80" required value="<?= sanitize($form['category'] ?? 'Umum') ?>">
                                <datalist id="bankCategories">
                                    <?php foreach ($categories as $category): ?><option value="<?= sanitize($category) ?>"><?php endforeach; ?>
                                </datalist>
                            </div>
                            <div class="bank-field">
                                <label class="bank-label" for="bankCorrect">Kunci Jawaban *</label>
                                <select id="bankCorrect" class="bank-input" name="correct_answer" required>
                                    <option value="">Pilih kunci A–E</option>
                                    <?php foreach (range('A', 'E') as $letter): ?>
                                        <option value="<?= $letter ?>" <?= ($form['correct_answer'] ?? '') === $letter ? 'selected' : '' ?>>Pilihan <?= $letter ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <label class="bank-toggle">
                                <input type="checkbox" name="is_active" value="1" <?= !empty($form['is_active']) ? 'checked' : '' ?>>
                                Aktifkan untuk paket ujian baru
                            </label>
                            <p style="margin-top:6px;font-size:11px;color:#6d7f72">Soal nonaktif disimpan di bank namun tidak diikutkan saat sesi ujian diacak untuk peserta.</p>
                        </aside>
                    </div>
                    <div class="bank-form-actions">
                        <button class="bank-button primary" type="submit">Simpan Soal</button>
                        <a data-no-spa class="bank-button" href="<?= $bankUrl ?>&amp;tab=bank">Kembali ke bank soal</a>
                    </div>
                </form>
            <?php endif; ?>
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
            <a data-no-spa class="bank-button primary" href="<?= $bankUrl ?>&amp;download=template">↓ Unduh Template .xlsx</a>
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
         TAB 6: HASIL UJIAN
         ═══════════════════════════════════════════════════════════════════ -->
    <?php else: ?>
    <section class="bank-panel">
        <div class="bank-panel-head">
            <div>
                <h3>Hasil Ujian Peserta</h3>
                <p>100 sesi terbaru. Ekspor memuat seluruh sesi yang tersedia, maksimal 10.000 baris.</p>
            </div>
            <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
                <?php if (!empty($attempts)): ?>
                    <form method="post" action="<?= $bankUrl ?>&amp;tab=results" style="margin:0;display:inline;" onsubmit="return confirm('PERINGATAN: Yakin ingin menghapus SEMUA sesi CBT peserta (<?= count($attempts) ?> sesi)?\n\nSemua riwayat pengerjaan, jawaban, dan nilai CBT peserta akan dihapus sehingga peserta dapat mengulang ujian kembali.')">
                        <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                        <input type="hidden" name="action" value="delete_all_attempts">
                        <button type="submit" class="bank-button danger" title="Hapus semua sesi hasil CBT peserta">🗑️ Hapus Semua Hasil</button>
                    </form>
                <?php endif; ?>
                <a data-no-spa class="bank-button primary" href="<?= $bankUrl ?>&amp;download=results">↓ Ekspor Hasil .xlsx</a>
            </div>
        </div>
        <div class="bank-table-scroll">
            <table class="bank-table">
                <thead>
                    <tr>
                        <th>Peserta</th>
                        <th>NIM</th>
                        <th>Mulai (WIB)</th>
                        <th>Status</th>
                        <th>Benar</th>
                        <th>Nilai</th>
                        <th style="text-align:center;width:90px">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($attempts as $attempt): ?>
                    <tr>
                        <td><strong><?= sanitize($attempt['nama_lengkap']) ?></strong></td>
                        <td><?= sanitize($attempt['nim'] ?? '-') ?></td>
                        <td><?= (new DateTimeImmutable('@' . $attempt['started_at']))->setTimezone(new DateTimeZone('Asia/Jakarta'))->format('d/m/Y H:i') ?></td>
                        <td><span class="bank-badge <?= $attempt['submitted_at'] !== null ? 'active' : 'inactive' ?>"><?= $attempt['submitted_at'] !== null ? 'Selesai' : 'Berlangsung' ?></span></td>
                        <td><?= $attempt['correct_count'] !== null ? (int)$attempt['correct_count'] . ' / 25' : '—' ?></td>
                        <td><span class="bank-key"><?= $attempt['score'] !== null ? number_format((float)$attempt['score'], 0) : '—' ?></span></td>
                        <td style="text-align:center">
                            <form method="post" action="<?= $bankUrl ?>&amp;tab=results" style="margin:0;display:inline-block;" onsubmit="return confirm('Yakin ingin menghapus sesi CBT <?= htmlspecialchars(addslashes($attempt['nama_lengkap']), ENT_QUOTES, 'UTF-8') ?> (NIM: <?= htmlspecialchars($attempt['nim'] ?? '-', ENT_QUOTES, 'UTF-8') ?>)?\n\nData jawaban dan nilai akan dihapus, dan peserta dapat memulai ujian kembali.')">
                                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                                <input type="hidden" name="action" value="delete_attempt">
                                <input type="hidden" name="attempt_id" value="<?= (int)$attempt['id'] ?>">
                                <button type="submit" class="bank-button danger-sm" title="Hapus sesi CBT peserta ini">🗑️ Hapus</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$attempts): ?><tr><td colspan="7" class="bank-empty">Belum ada peserta yang memulai CBT.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
        <div class="bank-footer">
            <span>Nilai CBT disimpan terpisah dari penilaian praktik dan nilai impor lama.</span>
            <?php if (!empty($attempts)): ?><span>Menampilkan <?= count($attempts) ?> sesi pengerjaan CBT terbaru.</span><?php endif; ?>
        </div>
    </section>
    <?php endif; ?>
</div>

<!-- ═══════════════════════════════════════════════════════════════════════
     MODAL 1: BUAT / EDIT PAKET SOAL (TANPA PINDAH HALAMAN)
     ═══════════════════════════════════════════════════════════════════════ -->
<div class="bank-modal-backdrop" id="modalPackageForm" role="dialog" aria-modal="true" aria-labelledby="modalPackageTitle">
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
<div class="bank-modal-backdrop" id="modalSessionForm" role="dialog" aria-modal="true" aria-labelledby="modalSessionTitle">
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

                <!-- 8. Deskripsi & Status Aktif -->
                <div class="bank-field">
                    <label class="bank-label" for="sesModalDesc">Deskripsi / Petunjuk untuk Peserta Sesi Ini</label>
                    <textarea id="sesModalDesc" class="bank-input" name="description" rows="2" placeholder="Keterangan pengawas, ruang lab, atau catatan khusus…"></textarea>
                </div>
                <label class="bank-toggle">
                    <input type="checkbox" name="is_active" id="sesModalActive" value="1" checked>
                    <strong>Jadikan Sesi Aktif Saat Ini</strong> (Peserta pretes yang login otomatis diarahkan ke sesi ini)
                </label>
            </div>
            <div class="bank-modal-foot">
                <button type="button" class="bank-button" data-close-modal>Batal</button>
                <button type="submit" class="bank-button primary">Simpan Sesi Ujian</button>
            </div>
        </form>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════════════
     MODAL 3: QUICK ADD SOAL KE PAKET (TANPA PINDAH HALAMAN)
     ═══════════════════════════════════════════════════════════════════════ -->
<div class="bank-modal-backdrop" id="modalQuickQuestion" role="dialog" aria-modal="true" aria-labelledby="modalQuickQuestionTitle">
    <div class="bank-modal large">
        <div class="bank-modal-head">
            <div>
                <h3 id="modalQuickQuestionTitle">＋ Tambah Soal ke Paket</h3>
                <span id="quickQuestionSubtitle" style="font-size:12px;color:#557063"></span>
            </div>
            <button type="button" class="bank-modal-close" data-close-modal aria-label="Tutup">✕</button>
        </div>
        <form method="post" action="<?= $bankUrl ?>" id="formQuickQuestionModal">
            <input type="hidden" name="action" value="save_question">
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
            <input type="hidden" name="current_tab" value="<?= sanitize($activeTab) ?>">
            <div class="bank-modal-body">
                <div class="bank-field">
                    <label class="bank-label" for="quickQuestionPackageId">Paket Soal Tujuan *</label>
                    <select id="quickQuestionPackageId" class="bank-input" name="package_id" required>
                        <?php foreach ($packages as $pkg): ?>
                            <option value="<?= (int)$pkg['id'] ?>">[<?= sanitize($pkg['code']) ?>] <?= sanitize($pkg['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="bank-field">
                    <label class="bank-label" for="quickQuestionText">Pertanyaan *</label>
                    <textarea id="quickQuestionText" class="bank-input" name="question_text" rows="3" maxlength="10000" required placeholder="Tuliskan butir soal…"></textarea>
                </div>
                <div class="bank-options" style="margin-bottom:14px">
                    <?php foreach (range('A', 'E') as $letter): ?>
                        <label>
                            <span class="bank-option-label"><span class="bank-letter"><?= $letter ?></span>Pilihan <?= $letter ?> *</span>
                            <textarea class="bank-input" name="option_<?= $letter ?>" maxlength="2000" rows="2" required placeholder="Jawaban pilihan <?= $letter ?>…"></textarea>
                        </label>
                    <?php endforeach; ?>
                </div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px">
                    <div class="bank-field">
                        <label class="bank-label" for="quickQuestionCategory">Kategori Materi *</label>
                        <input id="quickQuestionCategory" list="bankCategoriesQuick" class="bank-input" name="category" maxlength="80" required value="Umum" placeholder="Misal: Fiqih, Al-Qur'an, Ubudiyah">
                        <datalist id="bankCategoriesQuick">
                            <?php foreach ($categories as $category): ?><option value="<?= sanitize($category) ?>"><?php endforeach; ?>
                        </datalist>
                    </div>
                    <div class="bank-field">
                        <label class="bank-label" for="quickQuestionCorrect">Kunci Jawaban *</label>
                        <select id="quickQuestionCorrect" class="bank-input" name="correct_answer" required>
                            <option value="">Pilih Kunci A–E</option>
                            <?php foreach (range('A', 'E') as $letter): ?>
                                <option value="<?= $letter ?>">Pilihan <?= $letter ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <label class="bank-toggle">
                    <input type="checkbox" name="is_active" value="1" checked>
                    Aktifkan soal ini untuk ujian peserta
                </label>
            </div>
            <div class="bank-modal-foot">
                <button type="button" class="bank-button" data-close-modal>Batal</button>
                <button type="submit" class="bank-button primary">Simpan Soal</button>
            </div>
        </form>
    </div>
</div>

<script>
window.CBT_PACKAGE_CATEGORIES = <?= json_encode($packageCategoriesMap, JSON_UNESCAPED_UNICODE) ?>;
</script>
<script src="<?= BASE_URL ?>/pretes-ujian.php?cbt_admin_asset=1&amp;v=<?= filemtime(__DIR__ . '/../assets/js/pretes-cbt-admin.js') ?>"></script>
