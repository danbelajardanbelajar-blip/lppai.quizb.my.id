<?php
/* Uses the same period, classes and registrations as the plotting table. */
if (!isset($selected_gel_id, $classes)) {
    http_response_code(404);
    exit;
}
?>
<div class="card" style="margin-top: 24px;">
    <div class="card-header">🖨️ Cetak Absensi Tutorial</div>
    <div class="card-body">
        <p style="margin-top:0;color:#475569;">Daftar hadir dengan kop dan logo LPPAI, jadwal, dosen, ruangan, serta kolom tanda tangan. Cetak per kelas atau semua kelas pada periode yang dipilih.</p>
        <form action="tutorial-hasil-plotting.php" method="GET" target="_blank" rel="noopener" data-no-spa style="display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end;">
            <input type="hidden" name="cetak_absensi" value="1">
            <input type="hidden" name="gelombang_id" value="<?= sanitize($current_gel ? (string)$current_gel['id'] : 'all') ?>">
            <div class="form-group" style="margin-bottom:0;flex:1;min-width:200px;">
                <label for="absensi-kelas">Kelas</label>
                <select id="absensi-kelas" name="kelas_id">
                    <option value="">Semua kelas</option>
                    <?php foreach ($classes as $attendanceClass): ?>
                    <option value="<?= (int)$attendanceClass['id'] ?>"><?= sanitize($attendanceClass['nama_kelas'] . ' · ' . $attendanceClass['hari'] . ' · ' . $attendanceClass['semester']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php foreach (['hari' => 'Hari', 'dosen_pengampu' => 'Dosen Pengampu', 'ruangan' => 'Ruangan'] as $field => $label):
                $options = array_values(array_unique(array_filter(array_map(static fn($value) => trim($value ?? ''), array_column($classes, $field)))));
                sort($options, SORT_NATURAL | SORT_FLAG_CASE);
            ?>
            <div class="form-group" style="margin-bottom:0;flex:1;min-width:150px;">
                <label for="absensi-<?= $field ?>"><?= $label ?></label>
                <select id="absensi-<?= $field ?>" name="<?= $field ?>">
                    <option value="">Semua</option>
                    <?php foreach ($options as $option): ?>
                    <option value="<?= sanitize($option) ?>"><?= sanitize($option) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endforeach; ?>
            <button type="submit" class="btn btn-primary" style="width:auto;">🖨️ Cetak Absensi / PDF</button>
        </form>
        <p style="font-size:12px;color:#64748b;margin-bottom:0;">Mengikuti periode di bagian atas halaman. Setiap kelas dicetak terpisah, maksimal 25 peserta per lembar A4. Tanggal dan pertemuan diisi pada lembar cetak.</p>
    </div>
</div>
