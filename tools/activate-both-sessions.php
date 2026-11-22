<?php
/**
 * CLI Tool: Mengaktifkan beberapa sesi ujian resmi CBT (Multi-Session Activation)
 *
 * Pemakaian:
 *   php tools/activate-both-sessions.php
 */

require_once __DIR__ . '/../includes/pretes-cbt.php';

if (php_sapi_name() !== 'cli' && empty($_SERVER['argv'])) {
    http_response_code(403);
    echo "Hanya dapat dijalankan via CLI.\n";
    exit(1);
}

$pdo = getDBConnection();
ensurePretesCbtTables($pdo);

echo "=== MENGAKTIFKAN SESI UJIAN RESMI MULTI-SESSION ===\n";

// 1. Pastikan kedua sesi resmi default berstatus aktif
$pdo->exec("UPDATE pretes_cbt_sessions SET is_active = 1 WHERE id IN (1, 2) OR code IN ('SESI-REGULER-2026', 'SESI-RESMI-PROTESTAN-2026')");

// 2. Tampilkan semua sesi yang berstatus aktif
$activeSessions = pretesCbtGetActiveSessions($pdo);
echo "Jumlah sesi resmi yang sedang aktif: " . count($activeSessions) . "\n\n";

foreach ($activeSessions as $ses) {
    $pkgs = implode(', ', $ses['package_names']);
    $openStatus = (int)($ses['exam_open'] ?? 1) === 1 ? '🟢 DIBUKA' : '🔴 DITUTUP';
    echo "• [ID: {$ses['id']}] {$ses['title']} ({$ses['code']})\n";
    echo "  - Durasi: {$ses['duration_minutes']} menit | Kamera: {$ses['camera_mode']} | Token: " . ($ses['token'] ?: '-') . "\n";
    echo "  - Paket Soal: {$pkgs}\n";
    echo "  - Status Pengerjaan: {$openStatus}\n\n";
}

echo "✅ Berhasil! Sesi ujian telah dikonfigurasi untuk berjalan bersamaan.\n";
