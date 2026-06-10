<?php
/**
 * LPPAI Corner - Pendaftaran Tutorial Mandiri oleh Mahasiswa
 */
define('PAGE_TITLE', 'Pendaftaran Tutorial Mandiri');
require_once __DIR__ . '/../includes/auth.php';
requireLogin();

if (isAdmin()) {
    header('Location: ' . BASE_URL . '/admin/dashboard.php');
    exit;
}

require_once __DIR__ . '/../includes/tutorial-registration-helper.php';
include __DIR__ . '/../includes/header.php';

renderTutorialRegistration('mandiri', 'pendaftaran_mandiri', PAGE_TITLE);

include __DIR__ . '/../includes/footer.php';
