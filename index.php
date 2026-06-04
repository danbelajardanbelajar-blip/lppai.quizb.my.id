<?php
// Redirect root requests directly to the web entry point.
// This avoids an extra directory-slash redirect and prevents directory listing.
header('Location: /web/index.php');
exit;
