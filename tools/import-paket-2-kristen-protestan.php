<?php
/**
 * LPPAI Corner - Impor 25 Soal Ujian Resmi "Paket 2 - Kristen Protestan" ke CBT (KHUSUS CLI / terminal server)
 *
 * Pemakaian (dari folder root aplikasi):
 *   php tools/import-paket-2-kristen-protestan.php                # impor soal + buat sesi ujian resmi
 *   php tools/import-paket-2-kristen-protestan.php --tanpa-sesi    # impor ke paket saja
 *   php tools/import-paket-2-kristen-protestan.php --aktifkan      # aktifkan paket sebagai paket utama
 *
 * Yang dilakukan (idempoten / aman dijalankan berulang):
 *  1. Membuat/memperbarui paket "Paket 2 - Kristen Protestan" (kode PKT-2-KRISTEN-PROTESTAN, 25 soal, 30 menit).
 *  2. Memasukkan/memperbarui 25 butir soal (kode PKT2-KP-001..025) dari data/paket-2-kristen-protestan.php.
 *  3. Membuat sesi ujian resmi "Sesi Pretes Resmi – Kristen Protestan" (kode SESI-RESMI-PROTESTAN-2026, mode resmi, wajib kamera, passing grade 65.00).
 */

require_once __DIR__ . '/../includes/pretes-cbt.php';

const PAKET_2_KP_PKG_CODE = 'PKT-2-KRISTEN-PROTESTAN';
const PAKET_2_KP_SES_CODE = 'SESI-RESMI-PROTESTAN-2026';

/**
 * @return array{package_id:int, added:int, updated:int, session_id:?int, session_created:bool}
 */
function lppaiImportPaket2KristenProtestan(PDO $pdo, ?array $rows = null, bool $withSession = true, bool $activatePackage = false): array {
    ensurePretesCbtTables($pdo);
    if ($rows === null) {
        $rows = require __DIR__ . '/../data/paket-2-kristen-protestan.php';
    }
    if (count($rows) !== 25) {
        throw new RuntimeException('Data soal Paket 2 harus berjumlah 25 butir (ditemukan ' . count($rows) . ').');
    }

    $pdo->beginTransaction();
    try {
        $find = $pdo->prepare('SELECT id FROM pretes_cbt_packages WHERE code = ?');
        $find->execute([PAKET_2_KP_PKG_CODE]);
        $pkgId = (int)$find->fetchColumn();
        $isActive = $activatePackage ? 1 : 0;

        if ($pkgId <= 0) {
            if ($isActive === 1) {
                $pdo->exec('UPDATE pretes_cbt_packages SET is_active = 0');
            }
            $pdo->prepare('INSERT INTO pretes_cbt_packages (code, name, description, duration_minutes, question_count, is_active, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)')
                ->execute([
                    PAKET_2_KP_PKG_CODE,
                    'Paket 2 - Kristen Protestan',
                    'Paket 2 soal resmi pretes pendidikan agama Kristen Protestan (25 butir soal).',
                    30,
                    25,
                    $isActive,
                    time()
                ]);
            $pkgId = (int)$pdo->lastInsertId();
        } else {
            $updateSql = $activatePackage
                ? 'UPDATE pretes_cbt_packages SET name = ?, description = ?, duration_minutes = 30, question_count = 25, is_active = 1 WHERE id = ?'
                : 'UPDATE pretes_cbt_packages SET name = ?, description = ?, duration_minutes = 30, question_count = 25 WHERE id = ?';
            if ($activatePackage) {
                $pdo->exec('UPDATE pretes_cbt_packages SET is_active = 0');
            }
            $pdo->prepare($updateSql)->execute([
                'Paket 2 - Kristen Protestan',
                'Paket 2 soal resmi pretes pendidikan agama Kristen Protestan (25 butir soal).',
                $pkgId
            ]);
        }

        $added = 0; $updated = 0;
        $sel = $pdo->prepare('SELECT id FROM pretes_cbt_questions WHERE code = ?');
        $ins = $pdo->prepare('INSERT INTO pretes_cbt_questions (code, package_id, category, question_text, options_json, correct_answer, is_active) VALUES (?, ?, ?, ?, ?, ?, 1)');
        $upd = $pdo->prepare('UPDATE pretes_cbt_questions SET package_id = ?, category = ?, question_text = ?, options_json = ?, correct_answer = ?, is_active = 1 WHERE id = ?');

        foreach ($rows as $item) {
            $code = $item[0];
            $category = $item[1];
            $question = $item[2];
            $options = $item[3];
            $key = $item[4];

            if (count($options) !== 5 || !in_array($key, ['A', 'B', 'C', 'D', 'E'], true)) {
                throw new RuntimeException("Butir soal $code tidak valid (pilihan harus 5, kunci A–E).");
            }
            $optionsAssoc = array_combine(['A', 'B', 'C', 'D', 'E'], array_values($options));
            $json = json_encode($optionsAssoc, JSON_UNESCAPED_UNICODE);

            $sel->execute([$code]);
            $id = (int)$sel->fetchColumn();
            if ($id > 0) {
                $upd->execute([$pkgId, $category, $question, $json, $key, $id]);
                $updated++;
            } else {
                $ins->execute([$code, $pkgId, $category, $question, $json, $key]);
                $added++;
            }
        }

        // Catat di initialization table agar migrasi otomatis mengetahui Paket 2 sudah terpasang
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $insertIgnore = $driver === 'sqlite' ? 'INSERT OR IGNORE' : 'INSERT IGNORE';
        $pdo->prepare("$insertIgnore INTO pretes_cbt_initialization (seed_key, initialized_at) VALUES (?, ?)")
            ->execute(['paket-2-kristen-protestan-v1', time()]);

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }

    $sessionId = null; $created = false;
    if ($withSession) {
        $find = $pdo->prepare('SELECT id FROM pretes_cbt_sessions WHERE code = ?');
        $find->execute([PAKET_2_KP_SES_CODE]);
        $sessionId = (int)$find->fetchColumn() ?: null;
        if (!$sessionId) {
            $sessionId = pretesCbtSaveSession($pdo, [
                'code' => PAKET_2_KP_SES_CODE,
                'title' => 'Sesi Pretes Resmi – Kristen Protestan',
                'description' => 'Sesi ujian pretes resmi untuk peserta pendidikan agama Kristen Protestan (Paket 2). Wajib kamera aktif.',
                'package_ids' => [$pkgId],
                'mode' => 'resmi',
                'max_attempts' => 1,
                'duration_minutes' => 30,
                'camera_mode' => 'wajib',
                'scoring_method' => 'standar',
                'passing_grade' => 65,
                'shuffle_on_violation' => 1,
                'is_active' => 1,
            ]);
            $created = true;
        } else {
            // Pastikan paket terhubung ke sesi
            $stmtSes = $pdo->prepare('UPDATE pretes_cbt_sessions SET package_ids_json = ? WHERE id = ?');
            $stmtSes->execute([json_encode([$pkgId]), $sessionId]);
        }
    }

    return [
        'package_id' => $pkgId,
        'added' => $added,
        'updated' => $updated,
        'session_id' => $sessionId,
        'session_created' => $created
    ];
}

// Hanya jalankan blok runner jika dipanggil langsung lewat CLI (bukan saat di-include)
if (PHP_SAPI !== 'cli' || defined('LPPAI_IMPORT_PAKET_2_LIB') || !isset($argv[0]) || realpath($argv[0]) !== realpath(__FILE__)) {
    return;
}

require_once __DIR__ . '/../config/database.php';
try {
    $rows = require __DIR__ . '/../data/paket-2-kristen-protestan.php';
    $withSes = !in_array('--tanpa-sesi', $argv, true);
    $activate = in_array('--aktifkan', $argv, true);
    $r = lppaiImportPaket2KristenProtestan(getDBConnection(), $rows, $withSes, $activate);
    echo "[OK] Paket 2 - Kristen Protestan (id {$r['package_id']}): {$r['added']} butir ditambahkan, {$r['updated']} butir diperbarui.\n";
    if ($r['session_id']) {
        echo $r['session_created']
            ? "[OK] Sesi resmi 'Sesi Pretes Resmi – Kristen Protestan' dibuat (id {$r['session_id']}).\n"
            : "[i] Sesi resmi sudah ada (id {$r['session_id']}); paket telah dihubungkan.\n";
    }
    echo "Paket siap digunakan untuk ujian pretes resmi.\n";
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, '[GAGAL] ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
