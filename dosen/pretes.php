<?php
require_once __DIR__ . '/../includes/auth.php';
requireDosen();
// Hanya penguji terjadwal (dicocokkan via NIM akun dosen) yang dapat membuka penilaian & absensi pretes
require_once __DIR__ . '/../includes/pretes-examiner-gate.php';
pretesExaminerGate(getDBConnection(), getCurrentUser());
require __DIR__ . '/../includes/pretes-panel.php';
