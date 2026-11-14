<?php
/**
 * LPPAI Corner - Mode Tryout CBT Pretes
 *
 * Tryout memakai mesin CBT yang sama (bank soal, paket, kuota kategori, acak soal & pilihan,
 * timer server, penilaian otomatis) tetapi attempt-nya TERPISAH dari ujian resmi:
 *  - Sesi bertanda mode = 'tryout' (bisa beberapa aktif sekaligus, tidak menonaktifkan sesi resmi).
 *  - Attempt tryout disimpan di pretes_cbt_attempts dengan registration_id sintetis NEGATIF
 *    = -(session_id * 1000 + nomor_percobaan), sehingga tidak bentrok dengan UNIQUE(user_id,
 *    registration_id) milik ujian resmi (registration_id > 0) dan bisa diulang hingga max_attempts.
 *  - Tidak memerlukan pendaftaran pretes maupun verifikasi wajah.
 */
require_once __DIR__ . '/pretes-cbt.php';

const PRETES_TRYOUT_SLOT = 1000; // maksimal 999 percobaan per sesi per peserta

function pretesTryoutRegId(int $sessionId, int $attemptNo): int {
    if ($sessionId <= 0 || $attemptNo < 1 || $attemptNo >= PRETES_TRYOUT_SLOT) {
        throw new InvalidArgumentException('Nomor percobaan tryout tidak valid.');
    }
    return -($sessionId * PRETES_TRYOUT_SLOT + $attemptNo);
}

function pretesTryoutIsAttemptRegId(int $registrationId): bool {
    return $registrationId < 0;
}

/** Nomor sesi & percobaan dari registration_id sintetis. */
function pretesTryoutDecodeRegId(int $registrationId): array {
    $v = -$registrationId;
    return ['session_id' => intdiv($v, PRETES_TRYOUT_SLOT), 'attempt_no' => $v % PRETES_TRYOUT_SLOT];
}

/** Sesi tryout berdasarkan id; null bila bukan sesi tryout. */
function pretesTryoutGetSession(PDO $pdo, int $sessionId): ?array {
    $ses = pretesCbtGetSession($pdo, $sessionId);
    if (!$ses || ($ses['mode'] ?? 'resmi') !== 'tryout') return null;
    $ses['max_attempts'] = (int)($ses['max_attempts'] ?? 0);
    return $ses;
}

/** Status jadwal sesi: ['open'=>bool, 'notice'=>string]. */
function pretesTryoutWindow(array $session, ?int $now = null): array {
    $now ??= time();
    if (empty($session['is_active'])) return ['open' => false, 'notice' => 'Tryout ini sedang tidak aktif.'];
    if (!empty($session['start_time']) && ($ts = strtotime($session['start_time'])) !== false && $now < $ts) {
        return ['open' => false, 'notice' => 'Tryout dibuka ' . date('d/m/Y H:i', $ts) . ' WIB.'];
    }
    if (!empty($session['end_time']) && ($ts = strtotime($session['end_time'])) !== false && $now > $ts) {
        return ['open' => false, 'notice' => 'Tryout ditutup sejak ' . date('d/m/Y H:i', $ts) . ' WIB.'];
    }
    return ['open' => true, 'notice' => ''];
}

/** Sesi tryout aktif (untuk daftar mahasiswa), terbaru lebih dulu. */
function pretesTryoutActiveSessions(PDO $pdo): array {
    ensurePretesCbtTables($pdo);
    $rows = $pdo->query("SELECT id FROM pretes_cbt_sessions WHERE is_active = 1 AND mode = 'tryout' ORDER BY id DESC")->fetchAll(PDO::FETCH_COLUMN);
    $out = [];
    foreach ($rows as $id) {
        $ses = pretesTryoutGetSession($pdo, (int)$id);
        if ($ses) $out[] = $ses;
    }
    return $out;
}

/** Riwayat attempt peserta pada satu sesi tryout, urut percobaan ke-1, ke-2, ... */
function pretesTryoutAttempts(PDO $pdo, int $userId, int $sessionId): array {
    $hi = -($sessionId * PRETES_TRYOUT_SLOT + 1);
    $lo = -($sessionId * PRETES_TRYOUT_SLOT + PRETES_TRYOUT_SLOT - 1);
    $stmt = $pdo->prepare('SELECT * FROM pretes_cbt_attempts WHERE user_id = ? AND registration_id BETWEEN ? AND ? ORDER BY registration_id DESC');
    $stmt->execute([$userId, $lo, $hi]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$r) $r['attempt_no'] = pretesTryoutDecodeRegId((int)$r['registration_id'])['attempt_no'];
    unset($r);
    return $rows;
}

/**
 * Tentukan registration_id sintetis untuk aksi peserta.
 * - Ada attempt yang belum dikumpulkan → pakai itu (lanjutkan).
 * - Belum pernah → percobaan ke-1.
 * - $newAttempt (ulangi) dan attempt terakhir sudah dikumpulkan → percobaan berikutnya, bila kuota masih ada.
 * - Selain itu → attempt terakhir (untuk melihat hasil).
 */
function pretesTryoutResolveRegId(PDO $pdo, int $userId, array $session, bool $newAttempt = false): int {
    $sessionId = (int)$session['id'];
    $attempts = pretesTryoutAttempts($pdo, $userId, $sessionId);
    if (!$attempts) return pretesTryoutRegId($sessionId, 1);
    $latest = end($attempts);
    if ($latest['submitted_at'] === null) {
        // Attempt yang lewat waktu akan difinalisasi oleh pretesCbtState/Start
        return (int)$latest['registration_id'];
    }
    if (!$newAttempt) return (int)$latest['registration_id'];
    $max = (int)($session['max_attempts'] ?? 0);
    if ($max > 0 && count($attempts) >= $max) {
        throw new RuntimeException("Batas pengerjaan tryout ini sudah tercapai ({$max} kali).");
    }
    return pretesTryoutRegId($sessionId, (int)$latest['attempt_no'] + 1);
}

/** Masih boleh mengulang? */
function pretesTryoutCanRetake(array $session, array $attempts): bool {
    if (!$attempts) return false;
    $latest = end($attempts);
    if ($latest['submitted_at'] === null) return false;
    $max = (int)($session['max_attempts'] ?? 0);
    return $max === 0 || count($attempts) < $max;
}

/** Label jenis attempt untuk admin: ['kind'=>'tryout'|'resmi', 'label'=>string]. */
function pretesTryoutAttemptLabel(PDO $pdo, array $attempt, array &$cache = []): array {
    $reg = (int)($attempt['registration_id'] ?? 0);
    if (!pretesTryoutIsAttemptRegId($reg)) return ['kind' => 'resmi', 'label' => 'Resmi'];
    $d = pretesTryoutDecodeRegId($reg);
    if (!array_key_exists($d['session_id'], $cache)) {
        $ses = pretesCbtGetSession($pdo, $d['session_id']);
        $cache[$d['session_id']] = $ses['title'] ?? ('Sesi #' . $d['session_id']);
    }
    return ['kind' => 'tryout', 'label' => 'Tryout · ' . $cache[$d['session_id']] . ' · ke-' . $d['attempt_no']];
}
