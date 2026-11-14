<?php
if (!isset($scheduledLecturerClasses)) {
    http_response_code(404);
    exit;
}
?>
<div class="card" id="scheduledTutorialLecturers" style="margin-top:24px;">
    <div class="card-header" style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;">
        <span>👨‍🏫 Dosen Terjadwal Tutorial Aktif</span>
        <span style="font-size:13px;"><?= count($scheduledLecturerClasses) ?> jadwal kelas</span>
    </div>
    <div class="card-body">
        <?php if (!$scheduledTutorialPeriod): ?>
            <p style="margin:0;color:#64748b;">Belum ada periode tutorial aktif.</p>
        <?php else: ?>
            <p style="margin:0 0 16px;color:#64748b;">Periode <?= sanitize($scheduledTutorialPeriod['tahun_ajaran'] . ' / ' . $scheduledTutorialPeriod['semester'] . ' / ' . $scheduledTutorialPeriod['gelombang']) ?>. Satu baris untuk setiap kelas yang diampu dosen. Tabel ini selalu mengikuti periode tutorial aktif.</p>
            <?php if (!$scheduledLecturerClasses): ?>
                <p style="margin:0;color:#64748b;">Belum ada dosen yang terjadwal pada periode ini.</p>
            <?php else: ?>
            <div style="overflow-x:auto;">
                <table id="scheduledTutorialLecturerTable" style="width:100%;">
                    <thead><tr><th>No.</th><th>Nama Dosen</th><th>Kelas</th><th>Hari</th><th>Jam</th><th>Ruangan</th><th>Aksi</th></tr></thead>
                    <tbody>
                    <?php foreach ($scheduledLecturerClasses as $index => $schedule): ?>
                        <tr data-schedule-id="<?= (int)$schedule['id'] ?>">
                            <td><?= $index + 1 ?></td>
                            <td><?= sanitize($schedule['dosen_pengampu']) ?></td>
                            <td><?= sanitize($schedule['nama_kelas']) ?></td>
                            <td><?= sanitize($schedule['hari'] ?: 'Belum ditentukan') ?></td>
                            <td><?= sanitize($schedule['jam'] ?: 'Belum ditentukan') ?></td>
                            <td><?= sanitize($schedule['ruangan'] ?: 'Belum ditentukan') ?></td>
                            <td><button type="button" class="btn btn-sm btn-warning" data-edit-tutorial-schedule
                                data-class-id="<?= (int)$schedule['id'] ?>" data-class-name="<?= sanitize($schedule['nama_kelas']) ?>"
                                data-day="<?= sanitize($schedule['hari'] ?? '') ?>" data-room="<?= sanitize(trim($schedule['ruangan'] ?? '')) ?>" data-lecturer="<?= sanitize($schedule['dosen_pengampu']) ?>">Edit</button></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>
<?php if ($scheduledTutorialPeriod && $scheduledLecturerClasses): ?>
<dialog id="tutorialScheduleDialog" aria-labelledby="tutorialScheduleTitle" style="width:min(92vw,480px);max-height:90vh;overflow:auto;margin:auto;border:0;border-radius:12px;padding:24px;box-shadow:0 12px 48px #0003;">
    <h3 id="tutorialScheduleTitle" style="margin-top:0;">Edit Jadwal Tutorial</h3>
    <p id="tutorialScheduleLecturer" style="color:#475569;margin:0 0 16px;"></p>
    <form method="POST" action="tutorial-hasil-plotting.php?<?= sanitize(http_build_query(['gelombang_id' => $selected_gel_id])) ?>" data-no-spa>
        <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
        <input type="hidden" name="action" value="edit_tutorial_schedule">
        <input type="hidden" name="schedule_class_id" id="tutorialScheduleClassId">
        <input type="hidden" name="schedule_period_id" value="<?= (int)$scheduledTutorialPeriod['id'] ?>">
        <div class="form-group">
            <label for="tutorialScheduleClassName">Nama Kelas</label>
            <input type="text" name="nama_kelas" id="tutorialScheduleClassName" required maxlength="100">
        </div>
        <div class="form-group">
            <label for="tutorialScheduleDay">Hari Tutorial</label>
            <select name="hari" id="tutorialScheduleDay" required>
                <option value="">Pilih hari</option>
                <?php foreach (['Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat'] as $scheduleDay): ?>
                <option value="<?= $scheduleDay ?>"><?= $scheduleDay ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group">
            <label for="tutorialScheduleRoom">Ruangan</label>
            <select name="ruangan" id="tutorialScheduleRoom">
                <option value="">Belum ditentukan</option>
                <?php foreach ($scheduledTutorialRooms as $scheduleRoom): ?>
                <option value="<?= sanitize($scheduleRoom) ?>"><?= sanitize($scheduleRoom) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <p style="font-size:13px;color:#64748b;margin:0 0 16px;">Perubahan berlaku untuk seluruh peserta di kelas ini.</p>
        <div style="display:flex;justify-content:flex-end;gap:12px;">
            <button type="button" class="btn btn-secondary" id="cancelTutorialSchedule">Batal</button>
            <button type="submit" class="btn btn-primary">Simpan Jadwal</button>
        </div>
    </form>
</dialog>
<style>#tutorialScheduleDialog::backdrop { background:rgba(15,23,42,.5); }</style>
<script>
(function() {
    const card = document.getElementById('scheduledTutorialLecturers');
    const dialog = document.getElementById('tutorialScheduleDialog');
    card.addEventListener('click', function(event) {
        const button = event.target.closest('[data-edit-tutorial-schedule]');
        if (!button) return;
        document.getElementById('tutorialScheduleClassId').value = button.dataset.classId;
        document.getElementById('tutorialScheduleClassName').value = button.dataset.className;
        document.getElementById('tutorialScheduleDay').value = button.dataset.day;
        document.getElementById('tutorialScheduleRoom').value = button.dataset.room;
        document.getElementById('tutorialScheduleLecturer').textContent = button.dataset.lecturer;
        dialog.showModal();
    });
    document.getElementById('cancelTutorialSchedule').addEventListener('click', function() { dialog.close(); });
})();
</script>
<?php endif; ?>
