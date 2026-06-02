<?php
define('PAGE_TITLE', 'Pembagian Kelas Tutorial Gelombang 1 (Smt. Ganjil)');
require_once __DIR__ . '/includes/auth.php';
requireLogin();
require_once __DIR__ . '/includes/announcement-helper.php';
include __DIR__ . '/includes/header.php';
renderAnnouncementPage('pembagian_kelas_gel1', 'gel1', PAGE_TITLE);
include __DIR__ . '/includes/footer.php';
