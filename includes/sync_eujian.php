<?php
/**
 * LPPAI Corner - Helper Sinkronisasi & Import Credentials E-Ujian
 * Menyelaraskan username format {NIM}.22205, password kode akses 4-digit, dan hari ujian.
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

    $validDays = ['Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat'];

    // 1. Pastikan tabel system_settings ada DILUAR transaksi (karena DDL me-trigger implicit commit di MySQL)
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS system_settings (
                setting_key VARCHAR(100) PRIMARY KEY,
                setting_value TEXT,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");
    } catch (Throwable $ignore) {}

    try {
        // Ambil mapping seluruh peserta pretes yang terdaftar
        $stmtMap = $pdo->query("
            SELECT pr.id as reg_id, u.nim, pr.hari_pilihan 
            FROM pretes_registrations pr
            JOIN users u ON pr.user_id = u.id
        ");
        $allRegs = $stmtMap->fetchAll(PDO::FETCH_ASSOC);

        // Buat map pencarian instan berdasarkan NIM (normalisasi spasi & leading zeros)
        $nimMap = [];
        foreach ($allRegs as $row) {
            $rawNim = (string)$row['nim'];
            $cleanNim = trim(str_replace(' ', '', $rawNim));
            $nimMap[$rawNim] = (int)$row['reg_id'];
            $nimMap[$cleanNim] = (int)$row['reg_id'];
            $nimMap[ltrim($cleanNim, '0')] = (int)$row['reg_id'];
        }

        $matched = 0;
        $updated = 0;

        // Gunakan positional parameter '?' agar 100% kompatibel dengan PDO::ATTR_EMULATE_PREPARES => false
        $stmtUpdateWithHari = $pdo->prepare("
            UPDATE pretes_registrations 
            SET username_tes = ?, password_tes = ?, hari_pilihan = ?
            WHERE id = ?
        ");

        $stmtUpdateNoHari = $pdo->prepare("
            UPDATE pretes_registrations 
            SET username_tes = ?, password_tes = ?
            WHERE id = ?
        ");

        $pdo->beginTransaction();

        foreach ($records as $r) {
            $nim = trim((string)$r['nim']);
            $cleanNim = str_replace(' ', '', $nim);
            $regId = $nimMap[$nim] ?? ($nimMap[$cleanNim] ?? ($nimMap[ltrim($cleanNim, '0')] ?? null));

            if (!$regId) {
                continue;
            }

            $matched++;
            $username = trim((string)$r['username']);
            $password = trim((string)$r['password']);
            $hari = ucfirst(strtolower(trim((string)($r['hari'] ?? ''))));

            if (in_array($hari, $validDays)) {
                $stmtUpdateWithHari->execute([$username, $password, $hari, $regId]);
                $updated += $stmtUpdateWithHari->rowCount();
            } else {
                $stmtUpdateNoHari->execute([$username, $password, $regId]);
                $updated += $stmtUpdateNoHari->rowCount();
            }
        }

        // Simpan setting status sinkronisasi
        try {
            $stmtSet = $pdo->prepare("REPLACE INTO system_settings (setting_key, setting_value) VALUES (?, ?)");
            $stmtSet->execute(['eujian_sync_official_v3', '1']);
        } catch (Throwable $ignore) {}

        if ($pdo->inTransaction()) {
            $pdo->commit();
        }

        return [
            'success' => true,
            'updated' => $updated,
            'matched' => $matched,
            'total'   => count($records),
            'message' => "Berhasil menyinkronkan data E-Ujian resmi. Sebanyak $matched peserta terdaftar telah diselaraskan dengan akun resmi E-Ujian ({NIM}.22205)."
        ];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            try {
                $pdo->rollBack();
            } catch (Throwable $ignore) {}
        }
        return [
            'success' => false,
            'message' => "Kesalahan saat sinkronisasi: " . $e->getMessage()
        ];
    }
}

/**
 * Cek dan lakukan auto-sync sekali jika belum pernah disinkronkan ke v3
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
        $stmtCheck = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = ? LIMIT 1");
        $stmtCheck->execute(['eujian_sync_official_v3']);
        $isSynced = $stmtCheck->fetchColumn();

        if ($isSynced !== '1') {
            $res = runSyncEujianOfficial($pdo);
            return $res;
        }
    } catch (Throwable $e) {}
    return null;
}
