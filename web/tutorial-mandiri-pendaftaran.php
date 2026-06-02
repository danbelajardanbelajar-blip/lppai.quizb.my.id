<?php
define('PAGE_TITLE', 'Pendaftaran Tutorial Mandiri');
require_once __DIR__ . '/includes/auth.php';
requireLogin();
require_once __DIR__ . '/includes/announcement-helper.php';
include __DIR__ . '/includes/header.php';
renderAnnouncementPage('pendaftaran_mandiri', 'mandiri', PAGE_TITLE);
include __DIR__ . '/includes/footer.php';
