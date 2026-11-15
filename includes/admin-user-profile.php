<?php
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
    $result = ['pretes' => [], 'tutorial' => [], 'teaching' => [], 'ambiguous_teacher' => false];
    if ($user['role'] === 'mahasiswa') {
        try {
            $stmt = $pdo->prepare('SELECT periode, hari_pilihan, jam, ruangan, status FROM pretes_registrations WHERE user_id = ? ORDER BY id DESC LIMIT 50');
            $stmt->execute([$user['id']]); $result['pretes'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $error) {}
        try {
            $stmt = $pdo->prepare('SELECT tr.status, tr.tahun_ajaran, tr.gelombang, tr.hari_pilihan, tr.nilai_akhir,
                tc.nama_kelas, tc.hari, tc.jam, tc.ruangan, tc.dosen_pengampu
                FROM tutorial_registrations tr LEFT JOIN tutorial_classes tc ON tc.id = tr.tutorial_class_id
                WHERE tr.user_id = ? ORDER BY tr.id DESC LIMIT 50');
            $stmt->execute([$user['id']]); $result['tutorial'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $error) {}
    } elseif ($user['role'] === 'dosen') {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE role = 'dosen' AND LOWER(TRIM(nama_lengkap)) = LOWER(?)");
        $stmt->execute([trim($user['nama_lengkap'])]);
        $result['ambiguous_teacher'] = (int)$stmt->fetchColumn() !== 1;
        if (!$result['ambiguous_teacher']) {
            try {
                $stmt = $pdo->prepare('SELECT nama_kelas, hari, jam, ruangan, gelombang, semester FROM tutorial_classes
                    WHERE LOWER(TRIM(dosen_pengampu)) = LOWER(?) ORDER BY id DESC LIMIT 50');
                $stmt->execute([trim($user['nama_lengkap'])]); $result['teaching'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
            } catch (PDOException $error) {}
        }
    }
    return $result;
}
