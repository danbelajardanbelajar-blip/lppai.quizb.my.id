<?php
/** Penyimpanan nilai rinci dan sesi absensi pretes. */
function ensurePretesFeatures($pdo) {
    static $ready = false;
    if ($ready) return;
    $pdo->exec("CREATE TABLE IF NOT EXISTS pretes_assessments (
        registration_id INT NOT NULL PRIMARY KEY,
        scores_json TEXT NOT NULL,
        assessed_by INT NOT NULL,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS pretes_attendance_sessions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        scope_hash CHAR(64) NOT NULL UNIQUE,
        periode VARCHAR(100) NOT NULL,
        hari VARCHAR(20) NOT NULL,
        penguji VARCHAR(255) NOT NULL,
        ruangan VARCHAR(255) NOT NULL,
        token CHAR(48) NOT NULL UNIQUE,
        open_until DATETIME DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS pretes_attendance_records (
        registration_id INT NOT NULL PRIMARY KEY,
        session_id INT NOT NULL,
        attended_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $ready = true;
}

function pretesComponents() {
    return [
        'ujian_tulis' => 'Ujian tulis',
        'praktik_wudlu' => 'Praktik wudlu',
        'doa_setelah_wudlu' => 'Doa setelah wudlu',
        'praktik_tayammum' => 'Praktik tayammum',
        'niat_mandi_besar' => 'Niat mandi besar',
        'niat_jamak_qashar' => 'Niat jamak qashar',
        'bacaan_shalat_subuh' => 'Bacaan shalat Subuh',
        'al_bayyinah' => 'Al Bayyinah',
        'al_alaq' => "Al 'Alaq",
        'al_dluha' => 'Al Dluha',
        'istighasah' => 'Istighasah',
        'shalat_jenazah' => 'Shalat jenazah',
    ];
}

function pretesValidateScores($input) {
    if (!is_array($input)) throw new InvalidArgumentException('Data nilai tidak valid.');
    $scores = [];
    foreach (pretesComponents() as $key => $label) {
        $raw = $input[$key] ?? '';
        if (!is_scalar($raw) && $raw !== null) throw new InvalidArgumentException('Nilai ' . $label . ' tidak valid.');
        $raw = trim((string)$raw);
        if ($raw === '') { $scores[$key] = null; continue; }
        if (!preg_match('/^\d{1,3}(?:\.\d{1,2})?$/D', $raw) || (float)$raw > 100) {
            throw new InvalidArgumentException('Nilai ' . $label . ' harus 0–100, maksimal dua angka desimal.');
        }
        $scores[$key] = (float)$raw;
    }
    return $scores;
}

function pretesScope($row) {
    return [
        'periode' => trim($row['periode'] ?? ''),
        'hari' => ucfirst(strtolower(trim($row['hari_pilihan'] ?? ''))) ?: 'Senin',
        'penguji' => trim($row['dosen_penguji'] ?? ''),
        'ruangan' => trim($row['ruangan'] ?? ''),
    ];
}

function pretesScopeHash($scope) {
    return hash('sha256', json_encode(array_values($scope), JSON_UNESCAPED_UNICODE));
}

function pretesSession($pdo, $scope) {
    $hash = pretesScopeHash($scope);
    // ON DUPLICATE mempertahankan token, sehingga QR cetak dan kartu dosen sama.
    $stmt = $pdo->prepare('INSERT INTO pretes_attendance_sessions
        (scope_hash, periode, hari, penguji, ruangan, token) VALUES (?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE scope_hash = VALUES(scope_hash)');
    $stmt->execute([$hash, $scope['periode'], $scope['hari'], $scope['penguji'], $scope['ruangan'], bin2hex(random_bytes(24))]);
    $stmt = $pdo->prepare('SELECT *, (open_until > NOW()) AS is_open FROM pretes_attendance_sessions WHERE scope_hash = ?');
    $stmt->execute([$hash]);
    return $stmt->fetch();
}

function pretesAttendanceUrl($session) {
    return BASE_URL . '/absensi-pretes.php?token=' . rawurlencode($session['token']);
}

function pretesCanAssess($user, $registration) {
    return $user['role'] === 'admin' || ($user['role'] === 'dosen'
        && trim($user['nama_lengkap']) !== ''
        && trim($registration['dosen_penguji'] ?? '') === trim($user['nama_lengkap']));
}
