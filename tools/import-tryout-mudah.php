<?php
/**
 * LPPAI Corner - Impor 70 Soal Tryout LEVEL MUDAH ke CBT Tryout (KHUSUS CLI / terminal server)
 *
 * Pemakaian (dari folder root aplikasi):
 *   php tools/import-tryout-mudah.php              # impor soal + buat sesi tryout aktif
 *   php tools/import-tryout-mudah.php --tanpa-sesi  # impor soal ke paket saja
 *
 * Yang dilakukan (aman dijalankan berulang / idempoten):
 *  1. Membuat paket "Paket Tryout Level Mudah" (kode PKT-TRYOUT-MUDAH, TIDAK aktif sebagai paket resmi).
 *  2. Memasukkan/memperbarui 70 soal (kode TRY-MDH-001..070) dari data/pretes-tryout-mudah.php.
 *  3. Membuat sesi tryout "Tryout Pretes – Level Mudah" (kode TRYOUT-MUDAH-2026): 70 soal, 70 menit,
 *     tanpa kamera, boleh diulang tanpa batas, langsung aktif. Sesi resmi pretes TIDAK diubah.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../includes/pretes-tryout.php';

const TRYOUT_MUDAH_PKG_CODE = 'PKT-TRYOUT-MUDAH';
const TRYOUT_MUDAH_SES_CODE = 'TRYOUT-MUDAH-2026';

/**
 * @return array{package_id:int, added:int, updated:int, session_id:?int, session_created:bool}
 */
function lppaiImportTryoutMudah(PDO $pdo, array $rows, bool $withSession = true): array {
    ensurePretesCbtTables($pdo);
    if (count($rows) !== 70) throw new RuntimeException('Data soal harus berjumlah 70 (ditemukan ' . count($rows) . ').');

    $pdo->beginTransaction();
    try {
        $find = $pdo->prepare('SELECT id FROM pretes_cbt_packages WHERE code = ?');
        $find->execute([TRYOUT_MUDAH_PKG_CODE]);
        $pkgId = (int)$find->fetchColumn();
        if ($pkgId <= 0) {
            // Insert langsung (is_active = 0) agar paket aktif ujian resmi tidak berubah
            $pdo->prepare('INSERT INTO pretes_cbt_packages (code, name, description, duration_minutes, question_count, is_active, created_at) VALUES (?, ?, ?, ?, ?, 0, ?)')
                ->execute([TRYOUT_MUDAH_PKG_CODE, 'Paket Tryout Level Mudah', '70 soal dasar dari 11 tema pretes untuk tryout/latihan.', 70, 70, time()]);
            $pkgId = (int)$pdo->lastInsertId();
        } else {
            $pdo->prepare('UPDATE pretes_cbt_packages SET question_count = 70, duration_minutes = 70 WHERE id = ?')->execute([$pkgId]);
        }

        $added = 0; $updated = 0;
        $sel = $pdo->prepare('SELECT id FROM pretes_cbt_questions WHERE code = ?');
        $ins = $pdo->prepare('INSERT INTO pretes_cbt_questions (code, package_id, category, question_text, options_json, correct_answer, is_active) VALUES (?, ?, ?, ?, ?, ?, 1)');
        $upd = $pdo->prepare('UPDATE pretes_cbt_questions SET package_id = ?, category = ?, question_text = ?, options_json = ?, correct_answer = ?, is_active = 1 WHERE id = ?');
        foreach ($rows as [$code, $category, $question, $options, $key]) {
            if (count($options) !== 5 || !in_array($key, ['A', 'B', 'C', 'D', 'E'], true)) {
                throw new RuntimeException("Soal $code tidak valid (pilihan harus 5, kunci A–E).");
            }
            $json = json_encode(array_combine(['A', 'B', 'C', 'D', 'E'], $options), JSON_UNESCAPED_UNICODE);
            $sel->execute([$code]);
            $id = (int)$sel->fetchColumn();
            if ($id > 0) { $upd->execute([$pkgId, $category, $question, $json, $key, $id]); $updated++; }
            else { $ins->execute([$code, $pkgId, $category, $question, $json, $key]); $added++; }
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }

    $sessionId = null; $created = false;
    if ($withSession) {
        $find = $pdo->prepare('SELECT id FROM pretes_cbt_sessions WHERE code = ?');
        $find->execute([TRYOUT_MUDAH_SES_CODE]);
        $sessionId = (int)$find->fetchColumn() ?: null;
        if (!$sessionId) {
            $sessionId = pretesCbtSaveSession($pdo, [
                'code' => TRYOUT_MUDAH_SES_CODE,
                'title' => 'Tryout Pretes – Level Mudah',
                'description' => 'Latihan 70 soal dasar dari 11 tema pretes. Boleh diulang; tidak memengaruhi nilai pretes resmi.',
                'package_ids' => [$pkgId],
                'mode' => 'tryout',
                'max_attempts' => 0,
                'duration_minutes' => 70,
                'camera_mode' => 'tidak_aktif',
                'scoring_method' => 'standar',
                'passing_grade' => 65,
                'is_active' => 1,
            ]);
            $created = true;
        }
    }
    return ['package_id' => $pkgId, 'added' => $added, 'updated' => $updated, 'session_id' => $sessionId, 'session_created' => $created];
}

if (defined('LPPAI_IMPORT_TRYOUT_LIB')) return;

require_once __DIR__ . '/../config/database.php';
try {
    $rows = require __DIR__ . '/../data/pretes-tryout-mudah.php';
    $r = lppaiImportTryoutMudah(getDBConnection(), $rows, !in_array('--tanpa-sesi', $argv, true));
    echo "[OK] Paket Tryout Level Mudah (id {$r['package_id']}): {$r['added']} soal ditambahkan, {$r['updated']} soal diperbarui.\n";
    if ($r['session_id']) {
        echo $r['session_created']
            ? "[OK] Sesi tryout 'Tryout Pretes – Level Mudah' dibuat & aktif (id {$r['session_id']}).\n"
            : "[i] Sesi tryout sudah ada (id {$r['session_id']}); pengaturannya tidak diubah.\n";
        echo "Mahasiswa dapat mengerjakan di: " . (defined('BASE_URL') ? BASE_URL : '') . "/tryout.php\n";
    }
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, '[GAGAL] ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
