<?php
require_once __DIR__ . '/../includes/auth.php';
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: private, no-store');
if (!isLoggedIn() || !isAdmin()) { http_response_code(403); exit(json_encode(['error' => 'Akses khusus admin.'])); }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); header('Allow: POST'); exit(json_encode(['error' => 'Metode tidak diizinkan.'])); }
$raw = file_get_contents('php://input', false, null, 0, 100001);
if (strlen($raw) > 100000) { http_response_code(413); exit(json_encode(['error' => 'Permintaan terlalu besar.'])); }
$input = json_decode($raw, true);
if (!is_array($input) || !is_string($input['csrf_token'] ?? null) || !verifyCsrf($input['csrf_token'])) {
    http_response_code(403); exit(json_encode(['error' => 'Sesi tidak valid.']));
}
$names = $input['names'] ?? null;
if (!is_array($names) || count($names) > 100) { http_response_code(400); exit(json_encode(['error' => 'Daftar nama tidak valid.'])); }
foreach ($names as $name) {
    if (!is_string($name) || trim($name) === '' || mb_strlen($name, 'UTF-8') > 200) {
        http_response_code(400); exit(json_encode(['error' => 'Nama tidak valid.']));
    }
}
require_once __DIR__ . '/../includes/admin-user-profile.php';
echo json_encode(['links' => adminProfileLookupNames(getDBConnection(), $names)], JSON_UNESCAPED_UNICODE);
