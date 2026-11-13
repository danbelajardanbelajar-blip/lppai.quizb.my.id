<?php
/**
 * Test Suite: Validasi Pesan Gagal Login & Link Bantuan WhatsApp Bu Umami
 * File: index.php
 */

$file = __DIR__ . '/../index.php';
$content = file_get_contents($file);

assert($content !== false, "File index.php harus dapat dibaca");

$tests = [
    'Syntax check index.php' => function() use ($file) {
        exec('php -l ' . escapeshellarg($file), $out, $ret);
        return $ret === 0;
    },
    'Deteksi variabel $isLoginFailed' => function() use ($content) {
        return strpos($content, '$isLoginFailed') !== false;
    },
    'Pesan kegagalan login memuat referensi Bu Umami' => function() use ($content) {
        return stripos($content, 'Bu Umami') !== false;
    },
    'Format WhatsApp wa.me dengan nomor internasional 6281515726827' => function() use ($content) {
        return strpos($content, 'https://wa.me/6281515726827') !== false;
    },
    'Tautan WhatsApp memiliki target _blank dan rel noopener noreferrer' => function() use ($content) {
        return strpos($content, 'target="_blank"') !== false && strpos($content, 'rel="noopener noreferrer"') !== false;
    },
    'Pesan otomatis memuat username yang diinput' => function() use ($content) {
        return strpos($content, '$waUsername') !== false;
    }
];

echo "=== Menjalankan Validasi Bantuan WA Login index.php ===\n";
$allOk = true;
foreach ($tests as $name => $fn) {
    $res = $fn();
    if ($res) {
        echo " [PASS] $name\n";
    } else {
        echo " [FAIL] $name\n";
        $allOk = false;
    }
}

if (!$allOk) {
    echo "\n❌ Pengujian gagal!\n";
    exit(1);
} else {
    echo "\n✅ Seluruh pengujian bantuan WhatsApp login BERHASIL 100%!\n";
    exit(0);
}
