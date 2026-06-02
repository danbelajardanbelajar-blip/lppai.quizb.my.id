<?php
define('PAGE_TITLE', 'Kelulusan Tutorial Gelombang 2 (Smt. Genap)');
require_once __DIR__ . '/includes/auth.php';
requireLogin();
require_once __DIR__ . '/includes/announcement-helper.php';
include __DIR__ . '/includes/header.php';
renderAnnouncementPage('kelulusan_gel2', 'gel2', PAGE_TITLE);
include __DIR__ . '/includes/footer.php';
