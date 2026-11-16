<?php if (is_array($fillExistingResult)): ?>
<section class="card" id="fill-existing-result" aria-label="Hasil pengisian kelas lama">
    <div class="card-header">Hasil Pengisian Kelas yang Sudah Ada</div>
    <div class="card-body">
        <p>Periode: <strong><?= sanitize($fillExistingResult['period']) ?></strong></p>
        <p>Batas yang digunakan: <?= (int)($fillExistingResult['tutor_quota'] ?? 30) ?> mahasiswa per dosen per hari, dengan tetap mengikuti kuota masing-masing kelas.</p>
        <p role="status"><strong><?= (int)$fillExistingResult['assigned'] ?> mahasiswa</strong> dimasukkan ke kelas lama.
            <strong><?= count($fillExistingResult['waiting']) ?> mahasiswa</strong> masih menunggu kelas.</p>
        <?php if ($fillExistingResult['waiting']): ?>
            <p>Mahasiswa berikut tetap belum masuk kelas karena belum tersedia kelas atau sisa kuota dosen/kelas yang sesuai. Tidak ada kelas baru yang dibuat.</p>
            <div class="table-responsive" style="max-height:360px;overflow:auto;">
                <table><thead><tr><th>No</th><th>Nama Mahasiswa</th><th>NIM</th><th>Pilihan Hari</th></tr></thead><tbody>
                <?php foreach ($fillExistingResult['waiting'] as $index => $student): ?>
                    <tr><td><?= $index + 1 ?></td><td><?= sanitize($student['nama_lengkap']) ?></td><td><?= sanitize($student['nim'] ?? '-') ?></td><td><?= sanitize($student['hari_pilihan'] ?? '-') ?></td></tr>
                <?php endforeach; ?>
                </tbody></table>
            </div>
        <?php else: ?>
            <p>Semua mahasiswa yang diproses sudah memperoleh kelas.</p>
        <?php endif; ?>
    </div>
</section>
<?php endif; ?>
