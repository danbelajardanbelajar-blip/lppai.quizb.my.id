<?php
// Jalankan: php tests/pretes-helper-test.php (tidak mengakses database).
require_once __DIR__ . '/../includes/pretes-helper.php';
function checkPretes($condition, $message) {
    if (!$condition) throw new RuntimeException($message);
}
checkPretes(count(pretesComponents()) === 12, 'Harus ada tepat 12 komponen nilai.');
$scores = pretesValidateScores(['ujian_tulis' => '0', 'praktik_wudlu' => '95.25', 'shalat_jenazah' => '100']);
checkPretes($scores['ujian_tulis'] === 0.0, 'Nilai nol tidak boleh dianggap kosong.');
checkPretes($scores['praktik_wudlu'] === 95.25, 'Nilai desimal harus dipertahankan.');
checkPretes($scores['shalat_jenazah'] === 100.0, 'Batas atas 100 harus diterima.');
checkPretes($scores['doa_setelah_wudlu'] === null, 'Komponen belum diisi harus null.');
foreach (['-1', '100.01', '101', 'NaN', '8e1', '80.123', ['80']] as $invalid) {
    $rejected = false;
    try { pretesValidateScores(['ujian_tulis' => $invalid]); }
    catch (InvalidArgumentException $e) { $rejected = true; }
    checkPretes($rejected, 'Nilai tidak valid harus ditolak.');
}
$registration = ['periode' => 'Pretes 2026/2027', 'hari_pilihan' => 'senin', 'dosen_penguji' => 'Tutor A', 'ruangan' => 'Ruang A', 'jam' => '13:00'];
$scope = pretesScope($registration);
$hash = pretesScopeHash($scope);
foreach ([null, '', '13.00 WIB', '13.00 WIB (Jam 1)'] as $time) {
    checkPretes(pretesScopeHash(pretesScope(array_merge($registration, ['jam' => $time]))) === $hash, 'Variasi jam tidak boleh memisahkan sesi.');
}
foreach (['periode' => 'Pretes Lama', 'hari_pilihan' => 'Selasa', 'dosen_penguji' => 'Tutor B', 'ruangan' => 'Ruang B'] as $key => $value) {
    checkPretes(pretesScopeHash(pretesScope(array_merge($registration, [$key => $value]))) !== $hash, 'Perbedaan periode/hari/penguji/ruangan harus memisahkan sesi.');
}
checkPretes(pretesCanAssess(['role' => 'dosen', 'nama_lengkap' => 'Tutor A'], $registration), 'Dosen harus dapat menilai penugasannya sendiri.');
checkPretes(!pretesCanAssess(['role' => 'dosen', 'nama_lengkap' => 'Tutor B'], $registration), 'Dosen tidak boleh menilai penugasan orang lain.');
checkPretes(!pretesCanAssess(['role' => 'mahasiswa', 'nama_lengkap' => 'Tutor A'], $registration), 'Mahasiswa tidak boleh menilai.');
checkPretes(!pretesCanAssess(['role' => 'dosen', 'nama_lengkap' => ''], ['dosen_penguji' => '']), 'Nama dosen kosong tidak boleh mendapatkan akses.');
checkPretes(pretesCanAssess(['role' => 'admin'], $registration), 'Admin harus dapat memeriksa nilai.');
echo "PASS: 12 komponen, validasi nilai, pengelompokan sesi, dan izin penilaian.\n";
