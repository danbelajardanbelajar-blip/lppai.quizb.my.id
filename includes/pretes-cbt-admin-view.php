<link rel="stylesheet" href="<?= BASE_URL ?>/pretes-ujian.php?cbt_style=bank&amp;v=<?= filemtime(__DIR__ . '/../assets/css/pretes-cbt-admin.css') ?>">
<div class="bank-app">
    <header class="bank-hero">
        <div><div class="bank-eyebrow">LPPAI / Pusat Asesmen</div><h2>Bank Soal & CBT Pretes</h2><p>Susun materi, kelola soal, dan pantau hasil ujian dalam satu tempat. Paket peserta berisi 25 soal acak dengan durasi 30 menit.</p></div>
        <div class="bank-actions"><a data-no-spa class="bank-button ghost" href="<?= $bankUrl ?>&amp;tab=import">Impor Excel</a><a data-no-spa class="bank-button gold" href="<?= $bankUrl ?>&amp;tab=form">＋ Tambah Soal</a></div>
    </header>
    <div class="bank-stats">
        <div class="bank-stat"><small>Total bank soal</small><strong><?= count($questions) ?></strong><span><?= count($categories) ?> kategori materi</span></div>
        <div class="bank-stat"><small>Soal aktif</small><strong><?= $active ?></strong><span><?= $active >= 25 ? 'Siap untuk paket 25 soal' : 'Perlu minimal 25 soal aktif' ?></span></div>
        <div class="bank-stat"><small>Sesi ujian</small><strong><?= (int)$stats['total'] ?></strong><span><?= (int)$stats['finished'] ?> sesi sudah dikumpulkan</span></div>
        <div class="bank-stat"><small>Rata-rata nilai</small><strong><?= $stats['average_score'] !== null ? number_format((float)$stats['average_score'], 1) : '—' ?></strong><span>Dari seluruh sesi selesai</span></div>
    </div>
    <?php if ($flash): ?><div class="bank-notice" role="status"><?= sanitize($flash) ?></div><?php endif; ?>
    <?php if ($message): ?><div class="bank-notice error" role="alert"><?= sanitize($message) ?><?php if ($errors): ?><ul><?php foreach ($errors as $error): ?><li><?= sanitize($error) ?></li><?php endforeach; ?></ul><?php endif; ?></div><?php endif; ?>
    <?php if ($active < 25): ?><div class="bank-notice warning">Aktifkan minimal 25 soal agar peserta dapat memulai paket ujian baru.</div><?php endif; ?>
    <nav class="bank-tabs" aria-label="Kelola CBT">
        <?php foreach (['bank' => 'Bank Soal', 'form' => 'Tambah / Edit Soal', 'import' => 'Impor Excel', 'results' => 'Hasil Ujian'] as $tab => $label): ?>
            <a data-no-spa href="<?= $bankUrl ?>&amp;tab=<?= $tab ?>" class="<?= $activeTab === $tab ? 'active' : '' ?>" <?= $activeTab === $tab ? 'aria-current="page"' : '' ?>><?= $label ?><?php if ($tab === 'bank'): ?><span class="bank-tab-count"><?= count($questions) ?></span><?php endif; ?></a>
        <?php endforeach; ?>
    </nav>
    <?php if ($activeTab === 'bank'): ?>
    <section class="bank-panel">
        <div class="bank-panel-head"><div><h3>Koleksi soal</h3><p>Kelola pertanyaan dan kunci. Buka rincian untuk melihat pilihan jawaban.</p></div><div class="bank-actions"><a data-no-spa class="bank-button" href="<?= $bankUrl ?>&amp;download=template">↓ Template .xlsx</a><a data-no-spa class="bank-button primary" href="<?= $bankUrl ?>&amp;download=bank">Ekspor Semua Soal .xlsx</a></div></div>
        <div class="bank-filters">
            <input id="bankSearch" class="bank-input" type="search" placeholder="Cari pertanyaan, kategori, atau kode soal…" aria-label="Cari soal">
            <select id="bankCategory" class="bank-input" aria-label="Filter kategori"><option value="">Semua kategori</option><?php foreach ($categories as $category): ?><option value="<?= sanitize($category) ?>"><?= sanitize($category) ?></option><?php endforeach; ?></select>
            <select id="bankStatus" class="bank-input" aria-label="Filter status"><option value="">Semua status</option><option value="aktif">Aktif</option><option value="nonaktif">Nonaktif</option></select>
        </div>
        <p class="bank-scroll-hint">Geser tabel untuk melihat kategori, kunci, status, dan tombol edit.</p>
        <div class="bank-table-scroll"><table class="bank-table"><thead><tr><th>No.</th><th>Pertanyaan</th><th>Kategori</th><th>Kunci</th><th>Status</th><th>Aksi</th></tr></thead><tbody>
        <?php foreach ($questions as $i => $question): ?><tr data-bank-row data-category="<?= sanitize($question['category']) ?>" data-status="<?= $question['is_active'] ? 'aktif' : 'nonaktif' ?>">
            <td style="color:#8b978e"><?= $i + 1 ?></td>
            <td><div class="bank-question"><?= sanitize($question['question_text']) ?></div><span class="bank-code"><?= sanitize($question['code']) ?></span><details><summary>Lihat pilihan A–E</summary><?php foreach (json_decode($question['options_json'], true) as $letter => $text): ?><div class="bank-choice <?= $letter === $question['correct_answer'] ? 'correct' : '' ?>"><b><?= $letter ?>.</b> <?= sanitize($text) ?></div><?php endforeach; ?></details></td>
            <td><span class="bank-badge"><?= sanitize($question['category']) ?></span></td><td><span class="bank-key"><?= sanitize($question['correct_answer']) ?></span></td>
            <td><span class="bank-badge <?= $question['is_active'] ? 'active' : 'inactive' ?>"><?= $question['is_active'] ? 'Aktif' : 'Nonaktif' ?></span></td>
            <td><a data-no-spa class="bank-button" href="<?= $bankUrl ?>&amp;edit=<?= (int)$question['id'] ?>">Edit</a></td>
        </tr><?php endforeach; ?>
        <tr id="bankEmpty" hidden><td colspan="6" class="bank-empty">Tidak ada soal yang cocok dengan pencarian.</td></tr>
        </tbody></table></div>
        <div class="bank-footer"><span id="bankCount">Menampilkan <?= count($questions) ?> soal</span><div class="bank-pager"><label>Per halaman <select id="bankPageSize" class="bank-input" style="width:auto;padding:6px"><option>10</option><option>20</option><option>50</option></select></label><button id="bankPrevious" class="bank-button" type="button">←</button><span id="bankPage"></span><button id="bankNext" class="bank-button" type="button">→</button></div></div>
    </section>
    <p class="bank-note">Bank awal berisi contoh materi ubudiyah. Tinjau soal dan kunci sebelum digunakan untuk ujian resmi. Perubahan bank hanya berlaku untuk paket ujian baru.</p>
    <?php elseif ($activeTab === 'form'): ?>
    <section class="bank-panel"><div class="bank-panel-head"><div><h3><?= empty($form['id']) ? 'Buat soal baru' : 'Edit soal #' . (int)$form['id'] ?></h3><p>Tulis satu pertanyaan dengan lima pilihan dan satu kunci jawaban.</p></div><span class="bank-badge">Pilihan ganda A–E</span></div><div class="bank-panel-body">
        <form method="post"><input type="hidden" name="action" value="save_question"><input type="hidden" name="csrf_token" value="<?= csrfToken() ?>"><input type="hidden" name="id" value="<?= (int)($form['id'] ?? 0) ?>">
        <div class="bank-form-grid"><div>
            <div class="bank-field"><label class="bank-label" for="bankQuestion">Pertanyaan</label><textarea id="bankQuestion" class="bank-input" name="question_text" rows="4" maxlength="10000" required placeholder="Tulis pertanyaan secara jelas dan spesifik…"><?= sanitize($form['question_text'] ?? '') ?></textarea></div>
            <div class="bank-options"><?php foreach (range('A', 'E') as $letter): ?><label><span class="bank-option-label"><span class="bank-letter"><?= $letter ?></span>Pilihan <?= $letter ?></span><textarea class="bank-input" name="option_<?= $letter ?>" maxlength="2000" rows="2" required placeholder="Isi pilihan <?= $letter ?>"><?= sanitize($form['option_' . $letter] ?? '') ?></textarea></label><?php endforeach; ?></div>
        </div><aside class="bank-form-aside"><h4>Pengaturan soal</h4>
            <div class="bank-field"><label class="bank-label" for="bankFormCategory">Kategori materi</label><input id="bankFormCategory" list="bankCategories" class="bank-input" name="category" maxlength="80" required value="<?= sanitize($form['category'] ?? '') ?>"><datalist id="bankCategories"><?php foreach ($categories as $category): ?><option value="<?= sanitize($category) ?>"><?php endforeach; ?></datalist></div>
            <div class="bank-field"><label class="bank-label" for="bankCorrect">Kunci jawaban</label><select id="bankCorrect" class="bank-input" name="correct_answer" required><option value="">Pilih kunci A–E</option><?php foreach (range('A', 'E') as $letter): ?><option value="<?= $letter ?>" <?= ($form['correct_answer'] ?? '') === $letter ? 'selected' : '' ?>>Pilihan <?= $letter ?></option><?php endforeach; ?></select></div>
            <label class="bank-toggle"><input type="checkbox" name="is_active" value="1" <?= !empty($form['is_active']) ? 'checked' : '' ?>> Aktifkan untuk paket ujian baru</label><p>Soal dan kunci pada paket yang sudah dimulai tetap menggunakan versi saat peserta mulai ujian.</p>
        </aside></div><div class="bank-form-actions"><button class="bank-button primary" type="submit">Simpan Soal</button><a data-no-spa class="bank-button" href="<?= $bankUrl ?>">Kembali ke bank soal</a></div>
        </form>
    </div></section>
    <?php elseif ($activeTab === 'import'): ?>
    <section class="bank-panel"><div class="bank-panel-head"><div><h3>Impor bank soal dari Excel</h3><p>Validasi seluruh baris sebelum menyimpan. Jika ada kesalahan, tidak ada perubahan yang diterapkan.</p></div><a data-no-spa class="bank-button primary" href="<?= $bankUrl ?>&amp;download=template">↓ Unduh Template .xlsx</a></div><div class="bank-panel-body bank-import-grid">
        <form method="post" enctype="multipart/form-data" id="bankImportForm"><input type="hidden" name="action" value="import_xlsx"><input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
            <label class="bank-upload" id="bankDropzone" for="bankFile"><span class="bank-upload-icon">XLSX</span><strong>Pilih atau tarik file Excel ke sini</strong><span id="bankFileLabel">Format .xlsx · Maksimal 5 MB · 1.000 soal per file</span><input id="bankFile" name="question_file" type="file" accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" required></label>
            <label class="bank-toggle" style="margin-top:20px"><input type="checkbox" name="update_existing" value="1" <?= isset($_POST['update_existing']) ? 'checked' : '' ?>> Perbarui soal dengan Kode Soal yang sama</label>
            <p class="bank-note">Tanpa opsi ini, soal lama dilewati. Kode kosong dibuat sebagai soal baru; pertanyaan yang sudah ada dilewati untuk mencegah duplikasi.</p>
            <div class="bank-form-actions"><button id="bankImportSubmit" class="bank-button primary" type="submit">Validasi & Impor Soal</button><a data-no-spa class="bank-button" href="<?= $bankUrl ?>&amp;download=bank">Ekspor bank saat ini</a></div>
        </form>
        <aside class="bank-form-aside"><h4>Tiga langkah impor</h4><ol class="bank-steps"><li><b>1</b><div><strong>Unduh template</strong>Gunakan lembar Bank Soal dan pertahankan judul kolom.</div></li><li><b>2</b><div><strong>Isi soal dan kunci</strong>Kategori, pertanyaan, lima pilihan, kunci A–E, dan status wajib diisi. Ganti baris contoh.</div></li><li><b>3</b><div><strong>Unggah dan validasi</strong>Pesan kesalahan menunjukkan nomor baris. Perbaiki di Excel, lalu unggah kembali.</div></li></ol><p>Untuk mengedit massal: ekspor bank, pertahankan Kode Soal, lalu impor dengan opsi pembaruan.</p></aside>
    </div></section>
    <?php else: ?>
    <section class="bank-panel"><div class="bank-panel-head"><div><h3>Hasil ujian peserta</h3><p>100 sesi terbaru. Ekspor memuat seluruh sesi yang tersedia, maksimal 10.000 baris.</p></div><a data-no-spa class="bank-button primary" href="<?= $bankUrl ?>&amp;download=results">↓ Ekspor Hasil .xlsx</a></div>
        <div class="bank-table-scroll"><table class="bank-table"><thead><tr><th>Peserta</th><th>NIM</th><th>Mulai (WIB)</th><th>Status</th><th>Benar</th><th>Nilai</th></tr></thead><tbody>
        <?php foreach ($attempts as $attempt): ?><tr><td><strong><?= sanitize($attempt['nama_lengkap']) ?></strong></td><td><?= sanitize($attempt['nim'] ?? '-') ?></td><td><?= (new DateTimeImmutable('@' . $attempt['started_at']))->setTimezone(new DateTimeZone('Asia/Jakarta'))->format('d/m/Y H:i') ?></td><td><span class="bank-badge <?= $attempt['submitted_at'] !== null ? 'active' : 'inactive' ?>"><?= $attempt['submitted_at'] !== null ? 'Selesai' : 'Berlangsung' ?></span></td><td><?= $attempt['correct_count'] !== null ? (int)$attempt['correct_count'] . ' / 25' : '—' ?></td><td><span class="bank-key"><?= $attempt['score'] !== null ? number_format((float)$attempt['score'], 0) : '—' ?></span></td></tr><?php endforeach; ?>
        <?php if (!$attempts): ?><tr><td colspan="6" class="bank-empty">Belum ada peserta yang memulai CBT.</td></tr><?php endif; ?>
        </tbody></table></div><div class="bank-footer">Nilai CBT disimpan terpisah dari penilaian praktik dan nilai impor lama.</div>
    </section>
    <?php endif; ?>
</div>
<script src="<?= BASE_URL ?>/pretes-ujian.php?cbt_admin_asset=1&amp;v=<?= filemtime(__DIR__ . '/../assets/js/pretes-cbt-admin.js') ?>"></script>
