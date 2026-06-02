<?php
define('PAGE_TITLE', 'Pembagian Kelas Tutorial Mandiri');
require_once __DIR__ . '/includes/auth.php';
requireLogin();
require_once __DIR__ . '/includes/announcement-helper.php';
include __DIR__ . '/includes/header.php';
renderAnnouncementPage('pembagian_kelas_mandiri', 'mandiri', PAGE_TITLE);
include __DIR__ . '/includes/footer.php';
