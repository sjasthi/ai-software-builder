<?php
/**
 * Store or clear the visitor's per-use API key (FP8).
 *
 * The key is kept ONLY in the server-side PHP session (memory for this browser
 * session). We never write it to disk, to config, or into the saved session
 * JSON files — so secrets stay out of the open-source data. It clears when the
 * browser session ends or when the user clicks "clear". Saved interviews are a
 * completely separate thing and are unaffected by this.
 */
session_start();

$action = $_POST['action'] ?? 'set';

if ($action === 'clear') {
    unset($_SESSION['api_key']);
} else {
    $key = trim($_POST['api_key'] ?? '');
    if ($key !== '') { $_SESSION['api_key'] = $key; }
}

header('Location: index.php');
exit;
