<?php
require_once __DIR__ . '/../includes/auth.php';
requireDosen();
// Rekap untuk penguji terjadwal; dosen dengan riwayat penugasan (periode lain) tetap dapat membuka rekapnya
require_once __DIR__ . '/../includes/pretes-examiner-gate.php';
pretesExaminerGate(getDBConnection(), getCurrentUser(), true);
require __DIR__ . '/../admin/pretes-rekap-nilai.php';
