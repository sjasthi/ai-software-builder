<?php
/**
 * Delete Session action. Permanently removes a saved session's JSON file (the
 * runtime source of truth) and its MySQL rows. Reached by POST from the delete
 * button in the previous-sessions list (landing screen + hamburger drawer), after
 * a browser confirm().
 *
 * `return` carries the session the user was viewing when they clicked delete: if
 * they deleted a *different* session, send them back to it; otherwise (or when on
 * the landing screen) send them to the landing list.
 */
require_once __DIR__ . '/../src/InterviewSession.php';
require_once __DIR__ . '/../src/MySQLPersister.php';
require_once __DIR__ . '/../src/Auth.php';

Auth::requireLogin();

$id     = trim($_POST['id'] ?? '');
$return = trim($_POST['return'] ?? '');

// Only let a user delete a session they own (orphaned sessions remain deletable).
if ($id !== '' && Auth::ownsSession($id)) {
    InterviewSession::deleteSession($id);
    MySQLPersister::deleteSession($id);
}

if ($return !== '' && $return !== $id) {
    header('Location: session.php?id=' . urlencode($return));
} else {
    header('Location: index.php');
}
exit;
