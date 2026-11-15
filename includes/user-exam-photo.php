<?php
/** A single, freshly read reference for the profile, official CBT and tryout proctoring. */
function getUserExamReferencePhoto(PDO $pdo, int $userId): string
{
    if ($userId <= 0) return '';
    $photo = '';
    try {
        $stmt = $pdo->prepare('SELECT foto FROM users WHERE id = ?');
        $stmt->execute([$userId]);
        $photo = trim((string)$stmt->fetchColumn());
    } catch (PDOException $error) {}
    if ($photo === '') {
        // Existing students without a custom profile photo already use this as their avatar.
        try {
            $stmt = $pdo->prepare("SELECT foto FROM pretes_registrations WHERE user_id = ? AND foto IS NOT NULL AND TRIM(foto) <> '' ORDER BY id DESC LIMIT 1");
            $stmt->execute([$userId]);
            $photo = trim((string)$stmt->fetchColumn());
        } catch (PDOException $error) {}
    }
    if ($photo === '') return '';
    $uploads = realpath(__DIR__ . '/../uploads');
    $root = realpath(__DIR__ . '/..');
    $file = realpath(__DIR__ . '/../' . ltrim(str_replace('\\', '/', $photo), '/'));
    if (!$uploads || !$root || !$file || !is_file($file) || !str_starts_with($file, $uploads . DIRECTORY_SEPARATOR)) return '';
    return str_replace(DIRECTORY_SEPARATOR, '/', substr($file, strlen($root) + 1));
}

function invalidateUserPhotoVerification(): void
{
    foreach (array_keys($_SESSION ?? []) as $key) {
        if (str_starts_with((string)$key, 'pretes_cbt_verified_')) unset($_SESSION[$key]);
    }
}

/** Server-authoritative validation: no browser-provided "verified" flag is accepted. */
function saveVerifiedUserPhoto(PDO $pdo, int $userId, array $file, string $photoData = ''): array
{
    $candidate = null;
    $final = null;
    try {
        if ($userId <= 0) throw new RuntimeException('Identitas pengguna tidak valid.');
        if ($photoData !== '') {
            if (strlen($photoData) > 7100000 || !preg_match('~^data:image/jpeg;base64,([A-Za-z0-9+/=]+)$~', $photoData, $match)) {
                throw new RuntimeException('Foto hasil pemrosesan tidak valid. Pilih ulang foto.');
            }
            $binary = base64_decode($match[1], true);
        } else {
            if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
                throw new RuntimeException('Pilih atau ambil foto terlebih dahulu.');
            }
            if (filesize($file['tmp_name']) > 5 * 1024 * 1024) throw new RuntimeException('Ukuran foto maksimal 5 MB.');
            $binary = file_get_contents($file['tmp_name']);
        }
        if (!is_string($binary) || $binary === '' || strlen($binary) > 5 * 1024 * 1024) throw new RuntimeException('Ukuran foto maksimal 5 MB.');
        $info = @getimagesizefromstring($binary);
        if (!$info || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) {
            throw new RuntimeException('Gunakan gambar JPG, PNG, atau WEBP yang valid.');
        }
        if ($info[0] < 240 || $info[1] < 320 || $info[0] * $info[1] > 12000000) {
            throw new RuntimeException('Gunakan foto minimal 240 × 320 piksel dan maksimal 12 megapiksel.');
        }
        if (!function_exists('imagecreatefromstring')) throw new RuntimeException('Pengolah foto server belum tersedia. Hubungi admin.');
        $source = @imagecreatefromstring($binary);
        if (!$source) throw new RuntimeException('Foto tidak dapat diproses. Pilih gambar lain.');
        $cropWidth = min($info[0], (int)floor($info[1] * 3 / 4));
        $cropHeight = min($info[1], (int)floor($info[0] * 4 / 3));
        $canvas = imagecreatetruecolor(480, 640);
        imagefill($canvas, 0, 0, imagecolorallocate($canvas, 255, 255, 255));
        imagecopyresampled($canvas, $source, 0, 0, (int)(($info[0] - $cropWidth) / 2), (int)(($info[1] - $cropHeight) / 2), 480, 640, $cropWidth, $cropHeight);
        ob_start(); imagejpeg($canvas, null, 90); $jpeg = ob_get_clean();
        imagedestroy($source); imagedestroy($canvas);
        $privateDir = __DIR__ . '/../uploads/.profile-validation';
        if (!is_dir($privateDir) && !mkdir($privateDir, 0755, true) && !is_dir($privateDir)) throw new RuntimeException('Folder pemeriksaan foto tidak tersedia.');
        if (!is_file($privateDir . '/.htaccess') && file_put_contents($privateDir . '/.htaccess', "Require all denied\n") === false) throw new RuntimeException('Folder pemeriksaan foto tidak dapat diamankan.');
        $name = 'avatar_' . $userId . '_' . bin2hex(random_bytes(16)) . '.jpg';
        $candidate = $privateDir . '/' . $name;
        if (file_put_contents($candidate, $jpeg, LOCK_EX) === false) throw new RuntimeException('Foto tidak dapat diperiksa.');
        require_once __DIR__ . '/pretes-face-engine.php';
        // Comparing the photo with itself invokes the engine's one-face, size, lighting and sharpness checks.
        $result = comparePretesFaces('uploads/.profile-validation/' . $name, ['data:image/jpeg;base64,' . base64_encode($jpeg)]);
        if (empty($result['match']) || ($result['status'] ?? '') !== 'MATCH') {
            throw new RuntimeException($result['reason'] ?: 'Wajah pada foto belum memenuhi persyaratan. Pilih foto lain.');
        }
        $directory = __DIR__ . '/../uploads/profil';
        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) throw new RuntimeException('Folder foto profil tidak tersedia.');
        if (!is_file($directory . '/.htaccess')) file_put_contents($directory . '/.htaccess', "Options -ExecCGI -Indexes\n<FilesMatch \"\\.(php|phtml|phar|cgi|sh)$\">\nRequire all denied\n</FilesMatch>\n");
        $final = $directory . '/' . $name;
        if (!rename($candidate, $final)) throw new RuntimeException('Foto tidak dapat disimpan.');
        $candidate = null;
        ensureUserPhotoColumn($pdo);
        $pdo->beginTransaction();
        $stmt = $pdo->prepare('SELECT id FROM users WHERE id = ?');
        $stmt->execute([$userId]);
        if (!$stmt->fetchColumn()) throw new RuntimeException('Akun tidak ditemukan.');
        $path = 'uploads/profil/' . $name;
        $pdo->prepare('UPDATE users SET foto = ? WHERE id = ?')->execute([$path, $userId]);
        $pdo->commit();
        $_SESSION['foto'] = $path;
        invalidateUserPhotoVerification();
        // Keep earlier files: historic registration photos may still refer to them.
        return ['success' => true, 'path' => $path];
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if ($final && is_file($final)) unlink($final);
        return ['success' => false, 'error' => $error instanceof PDOException ? 'Foto belum tersimpan. Silakan coba lagi.' : $error->getMessage()];
    } finally {
        if ($candidate && is_file($candidate)) unlink($candidate);
    }
}
