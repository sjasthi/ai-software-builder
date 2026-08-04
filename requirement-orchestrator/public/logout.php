<?php
/**
 * Log out — clear the login session and return to the landing page.
 * The API key in the browser tab's sessionStorage is separate; we also clear it
 * client-side below so the next user on this machine starts clean.
 */
require_once __DIR__ . '/../src/Auth.php';
Auth::logout();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="refresh" content="0;url=login.php">
    <title>Signing out…</title>
</head>
<body>
    <script>
        try { sessionStorage.removeItem('api_key'); } catch (e) {}
        window.location.replace('login.php');
    </script>
    <noscript><a href="login.php">Continue</a></noscript>
</body>
</html>
