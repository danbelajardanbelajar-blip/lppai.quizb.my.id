<?php
/** Read-only profile link; record IDs must be users.id, never registration/class IDs. */
function adminUserProfileLink($name, $userId = null): string
{
    $name = trim((string)$name);
    $label = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
    $id = filter_var($userId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if (!function_exists('isAdmin') || !isAdmin() || $name === '' || (!$id && in_array(mb_strtolower($name, 'UTF-8'),
        ['-', 'tamu', 'admin', 'belum ditentukan', 'menunggu pembagian', 'menunggu plotting'], true))) return $label;
    $query = $id ? 'id=' . $id : 'nama=' . rawurlencode($name);
    $url = rtrim(BASE_URL, '/') . '/admin/profil-pengguna.php?' . $query;
    return '<a class="admin-user-profile-link" data-no-spa href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8')
        . '" style="color:inherit;text-decoration:underline;text-underline-offset:3px;" title="Lihat profil pengguna">' . $label . '</a>';
}

function adminProfileNameKey(string $name): string
{
    return mb_strtolower(preg_replace('/\s+/u', ' ', trim($name)), 'UTF-8');
}

/** Exact names only. Ambiguous names are routed to an account chooser, never guessed. */
function adminProfileLookupNames(PDO $pdo, array $names): array
{
    if (!$names) return [];
    $values = array_values(array_unique(array_map(static fn($name) => mb_strtolower(trim($name), 'UTF-8'), $names)));
    $stmt = $pdo->prepare('SELECT id, nama_lengkap FROM users WHERE LOWER(TRIM(nama_lengkap)) IN (' . implode(',', array_fill(0, count($values), '?')) . ')');
    $stmt->execute($values);
    $matches = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) $matches[adminProfileNameKey($row['nama_lengkap'])][] = (int)$row['id'];
    $links = [];
    foreach ($names as $name) {
        $ids = $matches[adminProfileNameKey($name)] ?? [];
        if ($ids) $links[] = ['name' => $name, 'id' => count($ids) === 1 ? $ids[0] : null];
    }
    return $links;
}

function adminProfileHistory(PDO $pdo, array $user): array
{
    $result = ['pretes' => [], 'tutorial' => [], 'results' => [], 'teaching' => [], 'ambiguous_teacher' => false, 'errors' => []];
    if ($user['role'] === 'mahasiswa') {
        try {
            $stmt = $pdo->prepare('SELECT * FROM pretes_registrations WHERE user_id = ? ORDER BY id DESC');
            $stmt->execute([$user['id']]); $result['pretes'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $error) { $result['errors'][] = 'Riwayat pendaftaran pretes belum dapat dimuat.'; }
        try {
            $stmt = $pdo->prepare('SELECT tr.*,
                tc.nama_kelas, tc.hari, tc.jam, tc.ruangan, tc.dosen_pengampu, tc.semester AS class_semester, tc.gelombang AS class_gelombang
                FROM tutorial_registrations tr LEFT JOIN tutorial_classes tc ON tc.id = tr.tutorial_class_id
                WHERE tr.user_id = ? ORDER BY tr.id DESC');
            $stmt->execute([$user['id']]); $result['tutorial'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $error) {
            $result['errors'][] = 'Detail kelas tutorial belum dapat dimuat.';
            try {
                $stmt = $pdo->prepare('SELECT * FROM tutorial_registrations WHERE user_id = ? ORDER BY id DESC');
                $stmt->execute([$user['id']]); $result['tutorial'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
            } catch (PDOException $error) { $result['errors'][] = 'Riwayat pendaftaran tutorial belum dapat dimuat.'; }
        }
        try {
            $stmt = $pdo->prepare('SELECT * FROM pretes_results WHERE user_id = ? ORDER BY id DESC');
            $stmt->execute([$user['id']]); $result['results'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $error) { $result['errors'][] = 'Hasil resmi pretes belum dapat dimuat.'; }
        try {
            $stmt = $pdo->prepare('SELECT pa.registration_id, pa.scores_json FROM pretes_assessments pa
                JOIN pretes_registrations pr ON pr.id = pa.registration_id WHERE pr.user_id = ?');
            $stmt->execute([$user['id']]);
            $assessments = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        } catch (PDOException $error) {
            $assessments = [];
            $result['errors'][] = 'Rincian nilai pretes belum dapat dimuat.';
        }
        try {
            $stmt = $pdo->prepare('SELECT mp.* FROM master_pretes mp WHERE mp.periode IN
                (SELECT periode FROM pretes_registrations WHERE user_id = ?) ORDER BY mp.id DESC');
            $stmt->execute([$user['id']]);
            $periods = [];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $period) $periods[$period['periode']] ??= $period;
        } catch (PDOException $error) {
            $periods = [];
            $result['errors'][] = 'Status periode pretes belum dapat dimuat.';
        }
        require_once __DIR__ . '/pretes-helper.php';
        foreach ($result['pretes'] as &$registration) {
            $scores = json_decode($assessments[$registration['id']] ?? '{}', true);
            $registration['scores'] = is_array($scores) ? $scores : [];
            $registration['assessment_status'] = getPretesStatusLulus($registration['scores']);
            $registration['period_status'] = $periods[$registration['periode']]['status'] ?? '';
        }
        unset($registration);
    } elseif ($user['role'] === 'dosen') {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE role = 'dosen' AND LOWER(TRIM(nama_lengkap)) = LOWER(?)");
        $stmt->execute([trim($user['nama_lengkap'])]);
        $result['ambiguous_teacher'] = (int)$stmt->fetchColumn() !== 1;
        if (!$result['ambiguous_teacher']) {
            try {
                $stmt = $pdo->prepare('SELECT nama_kelas, hari, jam, ruangan, gelombang, semester FROM tutorial_classes
                    WHERE LOWER(TRIM(dosen_pengampu)) = LOWER(?) ORDER BY id DESC');
                $stmt->execute([trim($user['nama_lengkap'])]); $result['teaching'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
            } catch (PDOException $error) { $result['errors'][] = 'Kelas yang diampu belum dapat dimuat.'; }
        }
    }
    return $result;
}

/** Keep registration, assessment, and official result statuses separate. */
function adminProfileStatus($status): string
{
    $status = strtolower(trim((string)$status));
    return [
        'aktif' => 'Aktif', 'terdaftar' => 'Terdaftar', 'lulus' => 'Lulus',
        'tidak_lulus' => 'Tidak Lulus', 'belum_lulus' => 'Belum Lulus',
        'belum_diumumkan' => 'Belum Diumumkan', 'menunggu' => 'Menunggu Penilaian Lengkap',
        'mengundurkan_diri' => 'Mengundurkan Diri', 'selesai' => 'Selesai',
        'berlangsung' => 'Sedang Berlangsung', 'nonaktif' => 'Nonaktif',
    ][$status] ?? ($status !== '' ? ucwords(str_replace('_', ' ', $status)) : 'Belum Ada Data');
}

function adminProfilePretesActivity(?array $registration): string
{
    if (!$registration) return 'Belum Terdaftar Pretes';
    $status = $registration['status'] ?? '';
    if ($status !== '' && !in_array($status, ['aktif', 'terdaftar'], true)) return adminProfileStatus($status);
    if (($registration['assessment_status'] ?? 'menunggu') !== 'menunggu') return 'Penilaian Pretes Selesai';
    if (($registration['period_status'] ?? '') === 'aktif') return 'Sedang Mengikuti Pretes';
    if (($registration['period_status'] ?? '') !== '') return 'Periode Pretes Berakhir';
    return 'Terdaftar Pretes · Status Periode Belum Tersedia';
}

function adminProfileTutorialActivity(?array $registration): string
{
    if (!$registration) return 'Belum Terdaftar Tutorial';
    $status = $registration['status'] ?? '';
    if (in_array($status, ['aktif', 'terdaftar'], true)) {
        return !empty($registration['nama_kelas']) ? 'Sedang Mengikuti Tutorial' : 'Terdaftar Tutorial · Menunggu Pembagian Kelas';
    }
    return adminProfileStatus($status);
}
