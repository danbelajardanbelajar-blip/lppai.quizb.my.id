<?php
require_once __DIR__ . '/../includes/auth.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method Not Allowed']);
    exit;
}

$reg_ids = [];
if (isset($_POST['reg_ids']) && is_array($_POST['reg_ids'])) {
    $reg_ids = array_map('intval', $_POST['reg_ids']);
} elseif (isset($_POST['reg_id'])) {
    $reg_ids = [(int)$_POST['reg_id']];
}

if (empty($reg_ids)) {
    echo json_encode(['status' => 'error', 'message' => 'Tidak ada data yang dipilih.']);
    exit;
}

try {
    $pdo = getDBConnection();
    
    // Get max number
    $stmtMax = $pdo->query("SELECT MAX(CAST(SUBSTRING_INDEX(nomor_sertifikat, '/', 1) AS UNSIGNED)) as max_num FROM tutorial_registrations WHERE nomor_sertifikat REGEXP '^[0-9]+/'");
    $maxRes = $stmtMax->fetch(PDO::FETCH_ASSOC);
    $maxNum = $maxRes['max_num'] ?? null;
    
    if ($maxNum === null || $maxNum < 5479) {
        $nextNum = 5479;
    } else {
        $nextNum = $maxNum + 1;
    }
    
    $bulanRomawi = [1=>"I","II","III","IV","V","VI","VII","VIII","IX","X","XI","XII"];
    $bln = date('n');
    $thnNow = date('Y');
    
    $lockedCount = 0;
    
    // Begin transaction
    $pdo->beginTransaction();
    
    $stmtCheck = $pdo->prepare("SELECT id, nomor_sertifikat FROM tutorial_registrations WHERE id = ? FOR UPDATE");
    $stmtUpdate = $pdo->prepare("UPDATE tutorial_registrations SET nomor_sertifikat = ? WHERE id = ?");
    
    foreach ($reg_ids as $id) {
        if (!$id) continue;
        
        $stmtCheck->execute([$id]);
        $row = $stmtCheck->fetch(PDO::FETCH_ASSOC);
        
        if ($row && empty($row['nomor_sertifikat'])) {
            $newNumber = sprintf("%d/U/L.3.11/A.2/%s/%s", $nextNum, $bulanRomawi[$bln], $thnNow);
            $stmtUpdate->execute([$newNumber, $id]);
            $nextNum++;
            $lockedCount++;
        }
    }
    
    $pdo->commit();
    
    if ($lockedCount > 0) {
        if (count($reg_ids) === 1) {
            echo json_encode([
                'status' => 'success', 
                'message' => 'Sertifikat berhasil di-lock.',
                'nomor_sertifikat' => sprintf("%d/U/L.3.11/A.2/%s/%s", $nextNum - 1, $bulanRomawi[$bln], $thnNow)
            ]);
        } else {
            echo json_encode([
                'status' => 'success', 
                'message' => "$lockedCount sertifikat berhasil di-lock secara massal."
            ]);
        }
    } else {
        echo json_encode([
            'status' => 'error', 
            'message' => 'Semua data yang dipilih sudah memiliki nomor sertifikat atau tidak ditemukan.'
        ]);
    }

} catch (PDOException $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $e->getMessage()]);
}
?>
