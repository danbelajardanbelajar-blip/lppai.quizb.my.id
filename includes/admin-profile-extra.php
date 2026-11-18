<?php
/**
 * LPPAI Corner - Data tambahan halaman Profil Pengguna (admin/profil-pengguna.php).
 *
 * 15 bagian (masing-masing terisolasi: tabel belum ada / query gagal → daftar kosong, halaman tetap tampil):
 *  1 cbt_resmi        Hasil CBT pretes resmi (pretes_cbt_attempts, registration_id > 0)
 *  2 cbt_tryout       Riwayat tryout CBT (registration_id < 0)
 *  3 interruptions    Gangguan saat CBT (pretes_cbt_interruption_events)
 *  4 proctoring       Log pengawas AI + tantangan wajah (pretes_proctoring_logs, pretes_face_challenges)
 *  5 pretes_presence  Presensi pretes (pretes_attendance_records + sessions)
 *  6 credentials      Username tes pretes — PASSWORD TIDAK PERNAH DIAMBIL/DITAMPILKAN
 *  7 pretes_status    Status kelulusan per pendaftaran pretes
 *  8 tutorial_presence Presensi tutorial (tutorial_attendance)
 *  9 rpl              Tutorial RPL Online: keanggotaan kelas & CBT (tutorial_rpl_*)
 * 10 alkhidmah        Presensi Majlis Al Khidmah (absensi_alkhidmah, per NIM)
 * 11 sertifikat       Pengambilan sertifikat (pengambilan_sertifikat, per NIM)
 * 12 jaminan          Jaminan mahasiswa (jaminan_mahasiswa, per NIM)
 * 13 logins           Riwayat login (user_login_logs)
 * 14 clicks           Log aktivitas klik (user_click_logs)
 * 15 messages         Percakapan Pesan (message_threads / messages)
 */
require_once __DIR__ . '/pretes-helper.php'; // pretesOfficialScoresByRegistration, getPretesStatusLulus, pretesProgramKey

if (!function_exists('adminProfileExtraWib')) {
    /** Tanggal/waktu WIB dari unix timestamp (int) atau string tanggal DB. */
    function adminProfileExtraWib($value, string $format = 'd/m/Y H:i'): string {
        if ($value === null || $value === '' || $value === 0 || $value === '0') return '-';
        $tz = new DateTimeZone('Asia/Jakarta');
        try {
            if (is_int($value) || ctype_digit((string)$value)) {
                return (new DateTimeImmutable('@' . (int)$value))->setTimezone($tz)->format($format) . ' WIB';
            }
            return (new DateTimeImmutable((string)$value))->format($format);
        } catch (Throwable $e) {
            return (string)$value;
        }
    }
}

if (!function_exists('adminProfileExtraDuration')) {
    function adminProfileExtraDuration(int $seconds): string {
        $seconds = max(0, $seconds);
        $m = intdiv($seconds, 60);
        $s = $seconds % 60;
        return $m . 'm ' . $s . 's';
    }
}

if (!function_exists('adminProfileExtraData')) {
    /**
     * @param array $user        baris users (id, nim, jenis_program, role, ...)
     * @param array $registrations baris pretes_registrations milik user (dari adminProfileHistory)
     */
    function adminProfileExtraData(PDO $pdo, array $user, array $registrations = [], ?int $now = null): array {
        $now ??= time();
        $userId = (int)($user['id'] ?? 0);
        $nim = trim((string)($user['nim'] ?? ''));
        $regIds = array_values(array_filter(array_map(static fn($r) => (int)($r['id'] ?? 0), $registrations)));
        $regById = [];
        foreach ($registrations as $r) $regById[(int)($r['id'] ?? 0)] = $r;

        $out = [
            'cbt_resmi' => [], 'cbt_tryout' => [], 'interruptions' => [],
            'proctoring' => ['logs' => [], 'counts' => [], 'total' => 0, 'challenges_issued' => 0, 'challenges_passed' => 0],
            'pretes_presence' => [], 'credentials' => [], 'pretes_status' => [],
            'tutorial_presence' => ['rows' => [], 'counts' => []],
            'rpl' => ['members' => [], 'cbt' => []],
            'alkhidmah' => [], 'sertifikat' => [], 'jaminan' => [],
            'logins' => ['rows' => [], 'last_success' => null, 'total' => 0, 'failed' => 0],
            'clicks' => ['rows' => [], 'total' => 0],
            'messages' => ['thread' => null, 'total' => 0, 'from_user' => 0, 'from_admin' => 0],
        ];
        if ($userId <= 0) return $out;

        $run = static function (callable $fn) {
            try { $fn(); } catch (Throwable $e) { /* tabel belum ada / skema berbeda: bagian dibiarkan kosong */ }
        };
        $in = static fn(array $ids): string => implode(',', array_fill(0, count($ids), '?'));

        // 1–3) CBT resmi, tryout, dan gangguan
        $attemptIds = [];
        $run(function () use ($pdo, $userId, $now, &$out, &$attemptIds, $regById, $in) {
            $st = $pdo->prepare('SELECT * FROM pretes_cbt_attempts WHERE user_id = ? ORDER BY started_at DESC, id DESC LIMIT 60');
            $st->execute([$userId]);
            $attempts = $st->fetchAll(PDO::FETCH_ASSOC);
            // Jumlah soal per sesi dari pretes_cbt_items (skema produksi tidak punya kolom total_questions)
            $itemCounts = [];
            $ids = array_map(static fn($a) => (int)$a['id'], $attempts);
            if ($ids) {
                try {
                    $sc = $pdo->prepare('SELECT attempt_id, COUNT(*) AS n FROM pretes_cbt_items WHERE attempt_id IN (' . $in($ids) . ') GROUP BY attempt_id');
                    $sc->execute($ids);
                    foreach ($sc->fetchAll(PDO::FETCH_ASSOC) as $c) $itemCounts[(int)$c['attempt_id']] = (int)$c['n'];
                } catch (Throwable $e) {}
            }
            foreach ($attempts as $a) {
                $submitted = $a['submitted_at'] !== null && $a['submitted_at'] !== '';
                $deadline = (int)($a['deadline_at'] ?? 0);
                $total = $itemCounts[(int)$a['id']] ?? (int)($a['total_questions'] ?? 0);
                $correct = $a['correct_count'] !== null && $a['correct_count'] !== '' ? (int)$a['correct_count'] : null;
                $row = [
                    'id' => (int)$a['id'],
                    'registration_id' => (int)$a['registration_id'],
                    'periode' => $regById[(int)$a['registration_id']]['periode'] ?? '',
                    'started_at' => (int)$a['started_at'],
                    'submitted_at' => $submitted ? (int)$a['submitted_at'] : null,
                    'status' => $submitted ? 'Selesai' : ($now >= $deadline ? 'Waktu habis' : 'Berlangsung'),
                    'duration' => $submitted ? max(0, (int)$a['submitted_at'] - (int)$a['started_at']) : null,
                    'score' => ($submitted && $a['score'] !== null && $a['score'] !== '') ? (float)$a['score'] : null,
                    'correct' => $submitted ? $correct : null,
                    'wrong' => ($submitted && $correct !== null && $total > 0) ? max(0, $total - $correct) : null,
                    'total' => $total,
                ];
                $attemptIds[] = $row['id'];
                if ((int)$a['registration_id'] > 0) $out['cbt_resmi'][] = $row; else $out['cbt_tryout'][] = $row;
            }
        });
        if ($attemptIds) {
            $run(function () use ($pdo, $attemptIds, $in, &$out) {
                $st = $pdo->prepare('SELECT attempt_id, reason, interrupted_position, paused_at, resumed_at FROM pretes_cbt_interruption_events
                    WHERE attempt_id IN (' . $in($attemptIds) . ') ORDER BY paused_at DESC LIMIT 50');
                $st->execute($attemptIds);
                $out['interruptions'] = $st->fetchAll(PDO::FETCH_ASSOC);
            });
        }

        // 4) Pengawas AI
        $run(function () use ($pdo, $userId, &$out) {
            $st = $pdo->prepare('SELECT status, COUNT(*) AS n FROM pretes_proctoring_logs WHERE user_id = ? GROUP BY status');
            $st->execute([$userId]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $c) {
                $out['proctoring']['counts'][(string)$c['status']] = (int)$c['n'];
                $out['proctoring']['total'] += (int)$c['n'];
            }
            $st = $pdo->prepare('SELECT event_type, similarity_score, status, notes, created_at FROM pretes_proctoring_logs
                WHERE user_id = ? ORDER BY id DESC LIMIT 15');
            $st->execute([$userId]);
            $out['proctoring']['logs'] = $st->fetchAll(PDO::FETCH_ASSOC);
        });
        $run(function () use ($pdo, $userId, &$out) {
            $st = $pdo->prepare('SELECT COUNT(*) AS issued, SUM(CASE WHEN consumed_at IS NOT NULL THEN 1 ELSE 0 END) AS passed
                FROM pretes_face_challenges WHERE user_id = ?');
            $st->execute([$userId]);
            $c = $st->fetch(PDO::FETCH_ASSOC) ?: [];
            $out['proctoring']['challenges_issued'] = (int)($c['issued'] ?? 0);
            $out['proctoring']['challenges_passed'] = (int)($c['passed'] ?? 0);
        });

        // 5) Presensi pretes
        if ($regIds) {
            $run(function () use ($pdo, $regIds, $in, $regById, &$out) {
                $st = $pdo->prepare('SELECT r.registration_id, r.attended_at, s.hari, s.ruangan, s.penguji, s.periode
                    FROM pretes_attendance_records r LEFT JOIN pretes_attendance_sessions s ON s.id = r.session_id
                    WHERE r.registration_id IN (' . $in($regIds) . ') ORDER BY r.attended_at DESC');
                $st->execute($regIds);
                $seen = [];
                foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $p) {
                    $seen[(int)$p['registration_id']] = true;
                    $out['pretes_presence'][] = $p + ['sumber' => 'Scan QR presensi'];
                }
                foreach ($regById as $rid => $reg) {
                    if ($rid > 0 && !isset($seen[$rid]) && strtolower((string)($reg['status'] ?? '')) === 'hadir') {
                        $out['pretes_presence'][] = ['registration_id' => $rid, 'attended_at' => null, 'hari' => $reg['hari_pilihan'] ?? '',
                            'ruangan' => $reg['ruangan'] ?? '', 'penguji' => $reg['dosen_penguji'] ?? '', 'periode' => $reg['periode'] ?? '',
                            'sumber' => 'Ditandai hadir oleh admin/penguji'];
                    }
                }
            });
        }

        // 6) Kredensial tes — hanya username & keberadaan password (nilai password tidak pernah dikeluarkan)
        foreach ($registrations as $reg) {
            $out['credentials'][] = [
                'registration_id' => (int)($reg['id'] ?? 0),
                'periode' => (string)($reg['periode'] ?? ''),
                'username_tes' => (string)($reg['username_tes'] ?? ''),
                'password_set' => trim((string)($reg['password_tes'] ?? '')) !== '',
            ];
        }

        // 7) Status kelulusan per pendaftaran pretes
        if ($regIds) {
            $run(function () use ($pdo, $regIds, $registrations, $user, &$out) {
                $official = function_exists('pretesOfficialScoresByRegistration') ? pretesOfficialScoresByRegistration($pdo, $regIds) : [];
                $program = function_exists('pretesProgramKey') ? pretesProgramKey($user['jenis_program'] ?? '') : null;
                $cbtOnly = $program === 'rpl' && function_exists('pretesRplCbtOnly') && pretesRplCbtOnly();
                $scoresByReg = [];
                $st = $pdo->prepare('SELECT registration_id, scores_json FROM pretes_assessments WHERE registration_id IN (' . implode(',', array_fill(0, count($regIds), '?')) . ')');
                $st->execute($regIds);
                foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $a) $scoresByReg[(int)$a['registration_id']] = json_decode((string)$a['scores_json'], true) ?: [];
                foreach ($registrations as $reg) {
                    $rid = (int)($reg['id'] ?? 0);
                    $scores = $scoresByReg[$rid] ?? [];
                    if ($cbtOnly && isset($official[$rid])) $scores['ujian_tulis'] = $official[$rid]['score'];
                    $status = function_exists('getPretesStatusLulus') ? getPretesStatusLulus($scores, $program) : 'menunggu';
                    $out['pretes_status'][] = [
                        'registration_id' => $rid,
                        'periode' => (string)($reg['periode'] ?? ''),
                        'official_score' => $official[$rid]['score'] ?? null,
                        'status' => $status,
                        'basis' => $cbtOnly ? 'RPL: cukup CBT resmi ≥ 80' : 'Semua komponen aktif ≥ 80',
                    ];
                }
            });
        }

        // 8) Presensi tutorial
        $run(function () use ($pdo, $userId, &$out) {
            $st = $pdo->prepare('SELECT ta.pertemuan_ke, ta.tanggal, ta.status_hadir, tc.nama_kelas
                FROM tutorial_attendance ta LEFT JOIN tutorial_classes tc ON tc.id = ta.tutorial_class_id
                WHERE ta.user_id = ? ORDER BY ta.tanggal DESC, ta.pertemuan_ke DESC LIMIT 60');
            $st->execute([$userId]);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
            $out['tutorial_presence']['rows'] = $rows;
            foreach ($rows as $r) {
                $k = strtolower(trim((string)$r['status_hadir'])) ?: 'lainnya';
                $out['tutorial_presence']['counts'][$k] = ($out['tutorial_presence']['counts'][$k] ?? 0) + 1;
            }
        });

        // 9) Tutorial RPL Online
        $run(function () use ($pdo, $userId, &$out) {
            $st = $pdo->prepare('SELECT m.class_id, m.drive_submitted, m.drive_verified, m.exam_submitted, m.nilai_ujian_tulis, m.nilai_praktik,
                    m.nilai_akhir, m.status_lulus, m.catatan, m.created_at, c.nama_kelompok, c.periode, c.gelombang, c.dosen_nama
                FROM tutorial_rpl_members m LEFT JOIN tutorial_rpl_classes c ON c.id = m.class_id
                WHERE m.user_id = ? ORDER BY m.created_at DESC');
            $st->execute([$userId]);
            $out['rpl']['members'] = $st->fetchAll(PDO::FETCH_ASSOC);
        });
        $run(function () use ($pdo, $userId, &$out) {
            $st = $pdo->prepare('SELECT class_id, started_at, submitted_at, correct_count, total_questions, score, submitted
                FROM tutorial_rpl_cbt_attempts WHERE user_id = ? ORDER BY started_at DESC');
            $st->execute([$userId]);
            $out['rpl']['cbt'] = $st->fetchAll(PDO::FETCH_ASSOC);
        });

        // 10–12) Data per NIM
        if ($nim !== '') {
            $run(function () use ($pdo, $nim, &$out) {
                $st = $pdo->prepare('SELECT tanggal, waktu_hadir, foto_hadir, waktu_pulang, foto_pulang FROM absensi_alkhidmah
                    WHERE nim = ? ORDER BY tanggal DESC LIMIT 30');
                $st->execute([$nim]);
                $out['alkhidmah'] = $st->fetchAll(PDO::FETCH_ASSOC);
            });
            $run(function () use ($pdo, $nim, &$out) {
                $st = $pdo->prepare('SELECT nomor_sertifikat, tahun_ajaran, status_pengambilan, tanggal_pengambilan, metode_pengambilan,
                        nama_penerima, hubungan_penerima, catatan FROM pengambilan_sertifikat WHERE nim = ? ORDER BY tanggal_pengambilan DESC');
                $st->execute([$nim]);
                $out['sertifikat'] = $st->fetchAll(PDO::FETCH_ASSOC);
            });
            $run(function () use ($pdo, $nim, &$out) {
                $st = $pdo->prepare('SELECT jaminan_atas, jenis_jaminan, status_pengambilan, created_at, updated_at FROM jaminan_mahasiswa
                    WHERE nim = ? ORDER BY created_at DESC');
                $st->execute([$nim]);
                $out['jaminan'] = $st->fetchAll(PDO::FETCH_ASSOC);
            });
        }

        // 13) Riwayat login
        $run(function () use ($pdo, $userId, &$out) {
            $st = $pdo->prepare("SELECT COUNT(*) AS total, SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) AS failed,
                    MAX(CASE WHEN status = 'success' THEN created_at END) AS last_success
                FROM user_login_logs WHERE user_id = ?");
            $st->execute([$userId]);
            $c = $st->fetch(PDO::FETCH_ASSOC) ?: [];
            $out['logins']['total'] = (int)($c['total'] ?? 0);
            $out['logins']['failed'] = (int)($c['failed'] ?? 0);
            $out['logins']['last_success'] = $c['last_success'] ?? null;
            $st = $pdo->prepare('SELECT created_at, status, ip_address, device, browser, keterangan FROM user_login_logs
                WHERE user_id = ? ORDER BY id DESC LIMIT 10');
            $st->execute([$userId]);
            $out['logins']['rows'] = $st->fetchAll(PDO::FETCH_ASSOC);
        });

        // 14) Log aktivitas
        $run(function () use ($pdo, $userId, &$out) {
            $st = $pdo->prepare('SELECT COUNT(*) FROM user_click_logs WHERE user_id = ?');
            $st->execute([$userId]);
            $out['clicks']['total'] = (int)$st->fetchColumn();
            $st = $pdo->prepare('SELECT created_at, page_title, page_url, element_text, target_url FROM user_click_logs
                WHERE user_id = ? ORDER BY id DESC LIMIT 15');
            $st->execute([$userId]);
            $out['clicks']['rows'] = $st->fetchAll(PDO::FETCH_ASSOC);
        });

        // 15) Pesan
        $run(function () use ($pdo, $userId, &$out) {
            $st = $pdo->prepare('SELECT * FROM message_threads WHERE user_id = ?');
            $st->execute([$userId]);
            $thread = $st->fetch(PDO::FETCH_ASSOC) ?: null;
            $out['messages']['thread'] = $thread;
            if ($thread) {
                $st = $pdo->prepare("SELECT COUNT(*) AS total, SUM(CASE WHEN sender_role = 'admin' THEN 1 ELSE 0 END) AS from_admin
                    FROM messages WHERE thread_id = ?");
                $st->execute([(int)$thread['id']]);
                $c = $st->fetch(PDO::FETCH_ASSOC) ?: [];
                $out['messages']['total'] = (int)($c['total'] ?? 0);
                $out['messages']['from_admin'] = (int)($c['from_admin'] ?? 0);
                $out['messages']['from_user'] = $out['messages']['total'] - $out['messages']['from_admin'];
            }
        });

        return $out;
    }
}
