<?php
/**
 * LPPAI Corner - Admin: Pilih Ruangan untuk Jadwal Tergenerate
 */
define('PAGE_TITLE', 'Pilih Ruangan Jadwal');
require_once __DIR__ . '/../includes/auth.php';
requireAdmin();

$pdo = getDBConnection();
$message = '';
$msgType = '';

// Hitung berapa kelas yang butuh ruangan (ruangan IS NULL)
$unassignedClasses = $pdo->query("SELECT * FROM tutorial_classes WHERE ruangan IS NULL ORDER BY hari, nama_kelas")->fetchAll();

if (empty($unassignedClasses)) {
    header("Location: tutorial-peserta.php?tab=peserta");
    exit;
}

// Hitung max rooms needed (berdasarkan jumlah tutor unik per hari)
$countsPerDay = [];
foreach ($unassignedClasses as $c) {
    $d = $c['hari'];
    $t = $c['dosen_pengampu'] ?: 'NO_TUTOR_' . $c['id']; // Jika tidak ada tutor, beri ID unik agar butuh ruang sendiri
    if (!isset($countsPerDay[$d])) {
        $countsPerDay[$d] = [];
    }
    $countsPerDay[$d][$t] = true;
}

$maxRoomsNeeded = 0;
foreach ($countsPerDay as $d => $tutorsArray) {
    $roomsForThisDay = count($tutorsArray);
    if ($roomsForThisDay > $maxRoomsNeeded) {
        $maxRoomsNeeded = $roomsForThisDay;
    }
}
$totalUnassigned = count($unassignedClasses);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'assign_rooms') {
    $token = $_POST['csrf_token'] ?? '';
    if (!verifyCsrf($token)) {
        $message = 'Sesi tidak valid.';
        $msgType = 'danger';
    } else {
        $selectedRooms = $_POST['rooms'] ?? [];
        if (count($selectedRooms) < $maxRoomsNeeded) {
            $message = "Anda harus memilih minimal $maxRoomsNeeded ruangan.";
            $msgType = 'danger';
        } else {
            // Assign rooms per day
            $pdo->beginTransaction();
            try {
                $stmtUpdate = $pdo->prepare("UPDATE tutorial_classes SET ruangan = ? WHERE id = ?");
                
                // Group by day again to assign
                $classesByDay = [];
                foreach ($unassignedClasses as $c) {
                    $classesByDay[$c['hari']][] = $c;
                }
                
                foreach ($classesByDay as $day => $classes) {
                    $roomIndex = 0;
                    $assignedRoomsForTutor = []; // Melacak ruangan yang sudah diberikan ke tutor di hari ini
                    foreach ($classes as $c) {
                        $tutor = $c['dosen_pengampu'];
                        if (!empty($tutor) && isset($assignedRoomsForTutor[$tutor])) {
                            // Jika tutor yang sama sudah punya ruangan di hari ini, gunakan ruangan tersebut
                            $roomIdOrName = $assignedRoomsForTutor[$tutor];
                        } else {
                            // Berikan ruangan baru
                            $roomIdOrName = $selectedRooms[$roomIndex];
                            if (!empty($tutor)) {
                                $assignedRoomsForTutor[$tutor] = $roomIdOrName;
                            }
                            $roomIndex++;
                        }
                        
                        $stmtUpdate->execute([$roomIdOrName, $c['id']]);
                    }
                }
                
                $pdo->commit();
                // Selesai assign, pindah ke tabel hasil
                header("Location: tutorial-peserta.php?tab=peserta");
                exit;
            } catch (Exception $e) {
                $pdo->rollBack();
                $message = "Gagal menyimpan ruangan: " . $e->getMessage();
                $msgType = 'danger';
            }
        }
    }
}

$allRooms = $pdo->query("SELECT id, ruang FROM rooms ORDER BY ruang ASC")->fetchAll();

include __DIR__ . '/../includes/header.php';
?>

<div class="card" style="border: 2px dashed #eab308; background-color: #fefce8;">
    <div class="card-header" style="background-color: transparent; color: #a16207;">⚠️ Membutuhkan Ruangan</div>
    <div class="card-body">
        <p style="color: #854d0e; font-size: 15px; margin-top: 0;">Sistem telah membuat <strong><?= $totalUnassigned ?> kelas</strong> secara otomatis.</p>
        <p style="color: #854d0e; font-size: 15px;">Karena dalam 1 hari terdapat maksimal <strong><?= $maxRoomsNeeded ?> kelas</strong> yang berjalan bersamaan, maka Anda wajib memilih minimal <strong><?= $maxRoomsNeeded ?> ruangan</strong> di bawah ini agar kelas tidak bentrok.</p>
    </div>
</div>

<?php if ($message): ?>
    <div class="alert alert-<?= $msgType ?>"><?= $message ?></div>
<?php endif; ?>

<div class="card">
    <div class="card-header">Pilih Minimal <?= $maxRoomsNeeded ?> Ruangan</div>
    <div class="card-body">
        <form method="POST" data-no-spa>
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
            <input type="hidden" name="action" value="assign_rooms">

            <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(150px, 1fr)); gap:12px; margin-bottom:20px;">
                <?php foreach ($allRooms as $r): ?>
                    <label style="display:flex; align-items:center; gap:8px; padding:10px; border:1px solid #e2e8f0; border-radius:8px; cursor:pointer; background:#fff;">
                        <input type="checkbox" name="rooms[]" value="<?= sanitize($r['ruang']) ?>" style="width:18px;height:18px;cursor:pointer;">
                        <span><?= sanitize($r['ruang']) ?></span>
                    </label>
                <?php endforeach; ?>
            </div>

            <button type="submit" class="btn btn-primary" style="width:auto;">💾 Simpan & Selesaikan Jadwal</button>
        </form>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
