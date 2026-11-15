<?php
/**
 * LPPAI Corner - Pemasang Widget Pesan Melayang (dipanggil dari includes/footer.php)
 *
 * Tidak dirender bila: belum login, atau halaman mendefinisikan HIDE_MESSAGE_WIDGET = true
 * (mis. ruang ujian CBT pretes & ruang tryout). Penyembunyian dinamis lain (kamera aktif,
 * upload file, layar penuh, modal) ditangani assets/js/messages-widget.js.
 */
if (!function_exists('isLoggedIn') || !isLoggedIn()) return;
if (defined('HIDE_MESSAGE_WIDGET') && HIDE_MESSAGE_WIDGET) return;

$lmwUser = getCurrentUser();
if (!$lmwUser) return;
$lmwBase = __DIR__ . '/../assets/';
$lmwVer = static fn(string $f) => file_exists($lmwBase . $f) ? filemtime($lmwBase . $f) : time();
$lmwConfig = [
    'api' => BASE_URL . '/pesan-api.php', // via pages/pesan-api.php (folder /api/ tidak dapat diakses langsung di produksi)
    'csrf' => csrfToken(),
    'userId' => (int)$lmwUser['id'],
    'isAdmin' => strtolower((string)$lmwUser['role']) === 'admin',
    'icon' => BASE_URL . '/assets/logo.svg',
];
?>
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/messages-widget.css?v=<?= $lmwVer('css/messages-widget.css') ?>">
<script>window.LPPAI_MSG = <?= json_encode($lmwConfig, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;</script>
<script src="<?= BASE_URL ?>/assets/js/messages-widget.js?v=<?= $lmwVer('js/messages-widget.js') ?>" defer></script>
