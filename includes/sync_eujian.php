<?php
/**
 * LPPAI Corner - Helper Sinkronisasi & Import Credentials E-Ujian
 */

function runSyncEujianOfficial($pdo) {
    $dataFile = __DIR__ . '/eujian_credentials_data.php';
    if (!file_exists($dataFile)) {
        return ['success' => false, 'message' => 'File data credentials E-Ujian tidak ditemukan.'];
    }

    $records = require $dataFile;
    if (!is_array($records) || empty($records)) {
        return ['success' => false, 'message' => 'Data credentials E-Ujian kosong.'];
    }

    $updated = 0;
    $validDays = ['Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat'];

    // Update pretes_registrations berdasarkan NIM mahasiswa di tabel users
    $stmtUpdate = $pdo->prepare("
        UPDATE pretes_registrations pr
        JOIN users u ON pr.user_id = u.id
        SET pr.username_tes = :username,
            pr.password_tes = :password,
            pr.hari_pilihan = CASE 
                WHEN :hari IN ('Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat') THEN :hari 
                ELSE pr.hari_pilihan 
            END
        WHERE u.nim = :nim OR REPLACE(u.nim, ' ', '') = :nim OR TRIM(u.nim) = :nim
    ");

    foreach ($records as $r) {
        $nim = trim($r['nim']);
        $username = trim($r['username']);
        $password = trim($r['password']);
        $hari = ucfirst(strtolower(trim($r['hari'] ?? '')));
        if (!in_array($hari, $validDays)) {
            $hari = '';
        }

        $stmtUpdate->execute([
            ':username' => $username,
            ':password' => $password,
            ':hari'     => $hari,
            ':nim'      => $nim
        ]);
        $updated += $stmtUpdate->rowCount();
    }

    // Catat flag migrasi di database (tabel system_settings)
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS system_settings (
                setting_key VARCHAR(100) PRIMARY KEY,
                setting_value TEXT,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");
        $stmtSet = $pdo->prepare("REPLACE INTO system_settings (setting_key, setting_value) VALUES ('eujian_sync_official_v2', '1')");
        $stmtSet->execute();
    } catch (Exception $e) {}

    return [
        'success' => true,
        'updated' => $updated,
        'total'   => count($records),
        'message' => "Berhasil menyinkronkan data E-Ujian resmi. Sebanyak $updated baris data pendaftaran peserta diperbarui."
    ];
}

/**
 * Cek dan lakukan auto-sync sekali jika belum pernah disinkronkan
 */
function checkAutoSyncEujian($pdo) {
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS system_settings (
                setting_key VARCHAR(100) PRIMARY KEY,
                setting_value TEXT,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");
        $stmtCheck = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'eujian_sync_official_v2' LIMIT 1");
        $stmtCheck->execute();
        $isSynced = $stmtCheck->fetchColumn();

        if ($isSynced !== '1') {
            $res = runSyncEujianOfficial($pdo);
            return $res;
        }
    } catch (Exception $e) {}
    return null;
}
