<?php
// Redirect root requests to the public web folder.
// The application is served from /web, so redirecting here prevents directory listing.
header('Location: /web/');
exit;
