<?php
/**
 * New Session action (FP6). Creates a fresh session .json (Cox's createSession)
 * and redirects into the workspace at the opening question.
 */
require_once __DIR__ . '/../src/InterviewSession.php';

$id = InterviewSession::createSession();
header('Location: session.php?id=' . urlencode($id));
exit;
