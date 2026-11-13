<link rel="stylesheet" href="<?= BASE_URL ?>/pretes-ujian.php?cbt_style=bank&amp;v=<?= filemtime(__DIR__ . '/../assets/css/pretes-cbt-admin.css') ?>">
<div class="bank-app">
    <header class="bank-hero">
        <div>
            <div class="bank-eyebrow">LPPAI / Pusat Asesmen &amp; Paket CBT</div>
            <h2>Bank &amp; Paket Soal CBT Pretes</h2>
            <p>Kelola paket soal ujian, atur butir pertanyaan per paket, dan pantau hasil asesmen peserta secara terpusat.</p>
        </div>
        <div class="bank-actions">
            <a data-no-spa class="bank-button gold" href="<?= $bankUrl ?>&amp;tab=packages">📦 Kelola Paket Soal</a>
            <a data-no-spa class="bank-button ghost" href="<?= $bankUrl ?>&amp;tab=import">Impor Excel</a>
            <a data-no-spa class="bank-button" href="<?= $bankUrl ?>&amp;tab=form">＋ Tambah Soal</a>
        </div>
    </header>
    <div class="bank-stats">
        <div class="bank-stat">
            <small>Paket Soal Aktif</small>
            <strong style="font-size:20px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis" title="<?= $activePackage ? sanitize($activePackage['name']) : 'Belum Ada' ?>"><?= $activePackage ? sanitize($activePackage['name']) : 'Belum Ada' ?></strong>
            <span><?= $activePackage ? ((int)$activePackage['duration_minutes'] . ' menit · Target ' . (int)$activePackage['question_count'] . ' soal acak') : 'Belum ada paket aktif' ?></span>
        </div>
        <div class="bank-stat">
            <small>Total Paket Soal</small>
            <strong><?= count($packages) ?></strong>
            <span><?= count($packages) ?> paket soal terdaftar</span>
        </div>
        <div class="bank-stat">
            <small>Soal Paket Aktif</small>
            <strong><?= $activePackage ? (int)($activePackage['question_count_actual'] ?? 0) : 0 ?></strong>
            <span><?= $activePackage ? (((int)($activePackage['question_count_actual'] ?? 0) >= (int)$activePackage['question_count']) ? 'Siap ujian (' . (int)$activePackage['question_count'] . ' soal/sesi)' : 'Kurang ' . max(0, (int)$activePackage['question_count'] - (int)($activePackage['question_count_actual'] ?? 0)) . ' soal aktif') : '—' ?></span>
        </div>
        <div class="bank-stat">
            <small>Total Bank Soal</small>
            <strong><?= count($questions) ?></strong>
            <span><?= $active ?> soal aktif di semua paket</span>
        </div>
    </div>
    <?php if ($flash): ?><div class="bank-notice" role="status"><?= sanitize($flash) ?></div><?php endif; ?>
    <?php if ($message): ?><div class="bank-notice error" role="alert"><?= sanitize($message) ?><?php if ($errors): ?><ul><?php foreach ($errors as $error): ?><li><?= sanitize($error) ?></li><?php endforeach; ?></ul><?php endif; ?></div><?php endif; ?>
    <?php if (empty($packages)): ?>
        <div class="bank-notice warning">Belum ada Paket Soal yang dibuat. Silakan buka tab <strong>Paket Soal</strong> untuk membuat paket soal ujian baru sebelum memasukkan soal.</div>
    <?php elseif ($activePackage && (int)($activePackage['question_count_actual'] ?? 0) < (int)$activePackage['question_count']): ?>
        <div class="bank-notice warning">Paket aktif "<strong><?= sanitize($activePackage['name']) ?></strong>" baru memiliki <strong><?= (int)$activePackage['question_count_actual'] ?></strong> soal aktif dari target minimal <strong><?= (int)$activePackage['question_count'] ?></strong> soal. Peserta baru dapat memulai ujian setelah kuota minimal terpenuhi.</div>
    <?php endif; ?>
    <nav class="bank-tabs" aria-label="Kelola CBT">
        <?php foreach ([
            'packages' => 'Paket Soal',
            'bank' => 'Bank Soal',
            'form' => 'Tambah / Edit Soal',
            'import' => 'Impor Excel',
            'results' => 'Hasil Ujian'
        ] as $tab => $label): ?>
            <a data-no-spa href="<?= $bankUrl ?>&amp;tab=<?= $tab ?>" class="<?= $activeTab === $tab ? 'active' : '' ?>" <?= $activeTab === $tab ? 'aria-current="page"' : '' ?>>
                <?= $label ?>
                <?php if ($tab === 'packages'): ?><span class="bank-tab-count"><?= count($packages) ?></span><?php endif; ?>
                <?php if ($tab === 'bank'): ?><span class="bank-tab-count"><?= count($questions) ?></span><?php endif; ?>
            </a>
        <?php endforeach; ?>
    </nav>

    <?php if ($activeTab === 'packages'): ?>
    <section class="bank-panel">
        <div class="bank-panel-head">
            <div>
                <h3><?= empty($pkgForm['id']) ? 'Buat Paket Soal Baru' : 'Edit Paket Soal: ' . sanitize($pkgForm['name']) ?></h3>
                <p>Buat wadah paket ujian dengan durasi dan kuota soal acak tertentu sebelum memasukkan butir-butir soal.</p>
            </div>
            <?php if (!empty($pkgForm['id'])): ?>
                <a data-no-spa class="bank-button" href="<?= $bankUrl ?>&amp;tab=packages">＋ Buat Paket Baru</a>
            <?php endif; ?>
        </div>
        <div class="bank-panel-body">
            <form method="post" action="<?= $bankUrl ?>&amp;tab=packages">
                <input type="hidden" name="action" value="save_package">
                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                <input type="hidden" name="id" value="<?= (int)($pkgForm['id'] ?? 0) ?>">
                <div class="bank-form-grid">
                    <div>
                        <div class="bank-field">
                            <label class="bank-label" for="pkgName">Nama Paket Soal *</label>
                            <input id="pkgName" class="bank-input" name="name" maxlength="150" required placeholder="Contoh: Paket Pretes Gelombang 1 2026 / Pretes Ubudiyah Reguler" value="<?= sanitize($pkgForm['name'] ?? '') ?>">
                        </div>
                        <div class="bank-field">
                            <label class="bank-label" for="pkgDesc">Deskripsi / Keterangan</label>
                            <textarea id="pkgDesc" class="bank-input" name="description" rows="3" placeholder="Keterangan kurikulum, sasaran peserta, atau catatan pengawas…"><?= sanitize($pkgForm['description'] ?? '') ?></textarea>
                        </div>
                    </div>
                    <aside class="bank-form-aside">
                        <h4>Parameter Ujian</h4>
                        <div class="bank-field">
                            <label class="bank-label" for="pkgCode">Kode Paket</label>
                            <input id="pkgCode" class="bank-input" name="code" maxlength="64" placeholder="Otomatis jika kosong" value="<?= sanitize($pkgForm['code'] ?? '') ?>" <?= !empty($pkgForm['id']) ? 'readonly style="background:#eef2ee;color:#556b5a"' : '' ?>>
                        </div>
                        <div class="bank-field">
                            <label class="bank-label" for="pkgDuration">Durasi Ujian (Menit) *</label>
                            <input id="pkgDuration" class="bank-input" type="number" name="duration_minutes" min="5" max="300" required value="<?= (int)($pkgForm['duration_minutes'] ?? 30) ?>">
                        </div>
                        <div class="bank-field">
                            <label class="bank-label" for="pkgCount">Target Soal Acak per Sesi *</label>
                            <input id="pkgCount" class="bank-input" type="number" name="question_count" min="5" max="200" required value="<?= (int)($pkgForm['question_count'] ?? 25) ?>">
                        </div>
                        <label class="bank-toggle">
                            <input type="checkbox" name="is_active" value="1" <?= (!empty($pkgForm['is_active']) || empty($packages)) ? 'checked' : '' ?>>
                            Jadikan Paket Aktif (Ujian Peserta)
                        </label>
                        <p style="margin-top:6px;font-size:11px;color:#6d7f72">Peserta yang memulai ujian CBT otomatis mengerjakan soal dari paket yang sedang berstatus Aktif.</p>
                    </aside>
                </div>
                <div class="bank-form-actions">
                    <button class="bank-button primary" type="submit"><?= empty($pkgForm['id']) ? 'Simpan Paket Soal' : 'Simpan Perubahan Paket' ?></button>
                    <?php if (!empty($pkgForm['id'])): ?>
                        <a data-no-spa class="bank-button" href="<?= $bankUrl ?>&amp;tab=packages">Batal Edit</a>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </section>

    <section class="bank-panel">
        <div class="bank-panel-head">
            <div>
                <h3>Daftar Paket Soal CBT</h3>
                <p>Klik nama paket untuk melihat butir soal, atau atur paket mana yang aktif digunakan untuk sesi ujian peserta.</p>
            </div>
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
                    <tr><td colspan="8" class="bank-empty">Belum ada paket soal. Isi form di atas untuk membuat paket soal pertama.</td></tr>
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
                            <a data-no-spa class="bank-button gold" href="<?= $bankUrl ?>&amp;tab=form&amp;pkg_id=<?= (int)$pkg['id'] ?>" style="min-height:28px;padding:4px 8px;font-size:11px" title="Tambah butir soal ke paket ini">＋ Soal</a>
                            <a data-no-spa class="bank-button" href="<?= $bankUrl ?>&amp;tab=bank&amp;filter_pkg=<?= (int)$pkg['id'] ?>" style="min-height:28px;padding:4px 8px;font-size:11px" title="Lihat semua soal di paket ini">Lihat Soal</a>
                            <a data-no-spa class="bank-button" href="<?= $bankUrl ?>&amp;tab=packages&amp;edit_pkg=<?= (int)$pkg['id'] ?>" style="min-height:28px;padding:4px 8px;font-size:11px">Edit</a>
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
    <p class="bank-note">Perubahan butir soal dan kunci hanya berlaku untuk sesi ujian baru yang dimulai setelah perubahan disimpan.</p>

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
                    <a data-no-spa class="bank-button primary" href="<?= $bankUrl ?>&amp;tab=packages">📦 Buat Paket Soal Sekarang</a>
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
                    <a data-no-spa class="bank-button primary" href="<?= $bankUrl ?>&amp;tab=packages">📦 Buat Paket Soal Sekarang</a>
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

    <?php else: ?>
    <section class="bank-panel">
        <div class="bank-panel-head">
            <div>
                <h3>Hasil ujian peserta</h3>
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
<script src="<?= BASE_URL ?>/pretes-ujian.php?cbt_admin_asset=1&amp;v=<?= filemtime(__DIR__ . '/../assets/js/pretes-cbt-admin.js') ?>"></script>
