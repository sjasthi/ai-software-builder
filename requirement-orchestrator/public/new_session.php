<?php
/**
 * New Session action (FP6). Creates a fresh session .json (Cox's createSession)
 * and redirects into the workspace at the opening question.
 */
require_once __DIR__ . '/../src/InterviewSession.php';
require_once __DIR__ . '/../src/Auth.php';

Auth::requireLogin();

// Stamp the creating account as the owner so it's scoped to this user.
$id = InterviewSession::createSession('', Auth::currentUserId());
header('Location: session.php?id=' . urlencode($id));
exit;
