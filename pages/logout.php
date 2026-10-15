<?php
require_once __DIR__ . '/../includes/auth.php';

$redirectParam = $_GET['redirect'] ?? '';
$safeRedirect = '';
if (!empty($redirectParam)) {
    $parsed = parse_url($redirectParam);
    if (!isset($parsed['host']) && !isset($parsed['scheme']) && substr($redirectParam, 0, 2) !== '//') {
        $safeRedirect = ltrim($redirectParam, '/');
    }
}

session_unset();
session_destroy();

if (!empty($safeRedirect)) {
    header('Location: ' . BASE_URL . '/index.php?redirect=' . urlencode($safeRedirect));
} else {
    header('Location: ' . BASE_URL . '/index.php');
}
exit;

