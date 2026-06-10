<?php
/**
 * LPPAI Corner - Pendaftaran Tutorial Gelombang 1
 */
define('PAGE_TITLE', 'Pendaftaran Tutorial Gelombang 1 (Smt. Ganjil)');
require_once __DIR__ . '/../includes/auth.php';
requireLogin();

if (isAdmin()) {
    header('Location: ' . BASE_URL . '/admin/dashboard.php');
    exit;
}

require_once __DIR__ . '/../includes/tutorial-registration-helper.php';
include __DIR__ . '/../includes/header.php';

renderTutorialRegistration('gel1', 'pendaftaran_gel1', PAGE_TITLE);

include __DIR__ . '/../includes/footer.php';
