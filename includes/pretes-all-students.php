<?php
/**
 * LPPAI Corner - Tab "Semua Mahasiswa" di admin/pretes-peserta.php (+ ekspor admin/pretes-mahasiswa-export.php).
 *
 * Status pretes per mahasiswa pada periode terpilih:
 *  - belum_daftar : tidak punya pendaftaran pretes pada periode tsb (atau sama sekali bila "__all__")
 *  - belum_pretes : sudah daftar, tetapi belum punya nilai pretes resmi (CBT resmi terkumpul / ujian_tulis)
 *  - sudah_pretes : sudah daftar dan punya nilai pretes resmi
 * Akun uji (username/NIM 12345) dikecualikan, sama seperti ekspor peserta.
 * Angkatan: users.angkatan; bila kosong diturunkan dari 2 digit awal NIM ("24…" → 2024), sama seperti kartu pretes.
 */
require_once __DIR__ . '/pretes-helper.php';   // pretesOfficialScoresByRegistration, pretesParseHariFilter, pretesFormatScore
require_once __DIR__ . '/user-helper.php';     // normalizeJenisProgram

const PRETES_ALL_STATUS_LABELS = [
    'sudah_pretes' => 'Sudah Pretes',
    'belum_pretes' => 'Belum Pretes (sudah daftar)',
    'belum_daftar' => 'Belum Daftar Pretes',
];

/** Angkatan mahasiswa: kolom angkatan, atau '20' + 2 digit awal NIM; '' bila tidak diketahui. */
function pretesStudentAngkatan(?string $angkatan, ?string $nim): string {
    $a = trim((string)$angkatan);
    if (preg_match('/^(19|20)\d{2}$/', $a)) return $a;
    $n = trim((string)$nim);
    if (strlen($n) >= 2 && ctype_digit(substr($n, 0, 2))) return '20' . substr($n, 0, 2);
    return '';
}

/** Filter dari GET: status (sudah_pretes|belum_pretes|belum_daftar|belum_semua), prodi, jenis, angkatan, hari (multi), q. */
function pretesAllStudentsFilters(array $src): array {
    $status = (string)($src['status_pretes'] ?? '');
    if (!in_array($status, ['sudah_pretes', 'belum_pretes', 'belum_daftar', 'belum_semua'], true)) $status = '';
    $jenis = trim((string)($src['jenis_program'] ?? ''));
    if ($jenis !== '' && function_exists('normalizeJenisProgram')) $jenis = normalizeJenisProgram($jenis);
    $q = trim((string)($src['q'] ?? ''));
    return [
        'status_pretes' => $status,
        'prodi' => mb_substr(trim((string)($src['prodi'] ?? '')), 0, 150),
        'jenis_program' => $jenis,
        'angkatan' => preg_match('/^\d{4}$/', trim((string)($src['angkatan'] ?? ''))) ? trim((string)$src['angkatan']) : '',
        'hari' => pretesParseHariFilter($src['hari'] ?? ''),
        'q' => mb_substr($q, 0, 100),
    ];
}

/**
 * Seluruh mahasiswa + status pretes pada periode ($periode '' atau '__all__' = semua periode; pendaftaran terbaru dipakai).
 * @return array<int, array> baris: user_id, nama_lengkap, nim, program_studi, jenis_program, foto, reg_id, periode,
 *         hari_pilihan, jam, status_pretes, score
 */
function pretesAllStudentsRows(PDO $pdo, string $periode): array {
    // SELECT * agar tetap jalan bila kolom angkatan belum ada; hanya kolom yang perlu yang dipakai
    $users = $pdo->query("SELECT * FROM users
        WHERE role = 'mahasiswa'
          AND LOWER(TRIM(COALESCE(username, ''))) <> '12345'
          AND TRIM(COALESCE(nim, '')) <> '12345'
        ORDER BY nama_lengkap ASC")->fetchAll(PDO::FETCH_ASSOC);
    $allPeriods = ($periode === '' || $periode === '__all__');
    if ($allPeriods) {
        $regs = $pdo->query('SELECT id, user_id, periode, hari_pilihan, jam, foto FROM pretes_registrations ORDER BY id DESC')->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $st = $pdo->prepare('SELECT id, user_id, periode, hari_pilihan, jam, foto FROM pretes_registrations WHERE periode = ? ORDER BY id DESC');
        $st->execute([$periode]);
        $regs = $st->fetchAll(PDO::FETCH_ASSOC);
    }
    $regByUser = [];
    foreach ($regs as $r) {
        $uid = (int)$r['user_id'];
        if (!isset($regByUser[$uid])) $regByUser[$uid] = $r; // terbaru (id DESC)
    }
    $scores = pretesOfficialScoresByRegistration($pdo, array_map(static fn($r) => (int)$r['id'], array_values($regByUser)));
    $rows = [];
    foreach ($users as $u) {
        $uid = (int)$u['id'];
        $reg = $regByUser[$uid] ?? null;
        $regId = $reg ? (int)$reg['id'] : 0;
        $score = $regId && isset($scores[$regId]) ? $scores[$regId]['score'] : null;
        $rows[] = [
            'user_id' => $uid,
            'nama_lengkap' => (string)$u['nama_lengkap'],
            'nim' => (string)($u['nim'] ?? ''),
            'angkatan' => pretesStudentAngkatan($u['angkatan'] ?? null, $u['nim'] ?? null),
            'program_studi' => trim((string)($u['program_studi'] ?? '')),
            'jenis_program' => function_exists('normalizeJenisProgram') ? normalizeJenisProgram($u['jenis_program'] ?? 'Reguler') : (string)($u['jenis_program'] ?? ''),
            'foto' => (string)($reg['foto'] ?? ''),
            'reg_id' => $regId,
            'periode' => (string)($reg['periode'] ?? ''),
            'hari_pilihan' => $reg ? ucfirst(strtolower(trim((string)$reg['hari_pilihan']))) : '',
            'jam' => (string)($reg['jam'] ?? ''),
            'status_pretes' => !$reg ? 'belum_daftar' : ($score === null ? 'belum_pretes' : 'sudah_pretes'),
            'score' => $score,
        ];
    }
    return $rows;
}

/** Terapkan filter (status, prodi, program, hari, kata kunci nama/NIM). */
function pretesAllStudentsApply(array $rows, array $f): array {
    $q = function_exists('mb_strtolower') ? mb_strtolower($f['q']) : strtolower($f['q']);
    return array_values(array_filter($rows, static function ($r) use ($f, $q) {
        if ($f['status_pretes'] === 'belum_semua') {
            if ($r['status_pretes'] === 'sudah_pretes') return false;
        } elseif ($f['status_pretes'] !== '' && $r['status_pretes'] !== $f['status_pretes']) {
            return false;
        }
        if ($f['prodi'] !== '' && $r['program_studi'] !== $f['prodi']) return false;
        if ($f['jenis_program'] !== '' && $r['jenis_program'] !== $f['jenis_program']) return false;
        if ($f['angkatan'] !== '' && $r['angkatan'] !== $f['angkatan']) return false;
        if ($f['hari'] && !in_array($r['hari_pilihan'], $f['hari'], true)) return false;
        if ($q !== '') {
            // Sama dengan kotak cari tabel: nama, NIM, prodi, program, hari, periode
            $hayRaw = implode(' ', [$r['nama_lengkap'], $r['nim'], $r['program_studi'], $r['jenis_program'], $r['hari_pilihan'], $r['periode']]);
            $hay = function_exists('mb_strtolower') ? mb_strtolower($hayRaw) : strtolower($hayRaw);
            if (!str_contains($hay, $q)) return false;
        }
        return true;
    }));
}

/** Jumlah per status. */
function pretesAllStudentsStats(array $rows): array {
    $out = ['total' => count($rows), 'sudah_pretes' => 0, 'belum_pretes' => 0, 'belum_daftar' => 0];
    foreach ($rows as $r) $out[$r['status_pretes']]++;
    return $out;
}

/** Deskripsi filter aktif (untuk judul ekspor). */
function pretesAllStudentsFilterText(array $f): string {
    $parts = [];
    if ($f['status_pretes'] === 'belum_semua') $parts[] = 'Belum Pretes + Belum Daftar';
    elseif ($f['status_pretes'] !== '') $parts[] = PRETES_ALL_STATUS_LABELS[$f['status_pretes']];
    if ($f['prodi'] !== '') $parts[] = 'Prodi ' . $f['prodi'];
    if ($f['jenis_program'] !== '') $parts[] = 'Program ' . $f['jenis_program'];
    if ($f['angkatan'] !== '') $parts[] = 'Angkatan ' . $f['angkatan'];
    if ($f['hari']) $parts[] = 'Hari ' . implode(', ', $f['hari']);
    if ($f['q'] !== '') $parts[] = 'Cari "' . $f['q'] . '"';
    return $parts ? implode(' • ', $parts) : 'Tanpa filter (semua mahasiswa)';
}
