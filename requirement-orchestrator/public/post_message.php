<?php
/**
 * Handle a submitted answer (FP6). Persists the user's message, then advances
 * the interview one domain.
 *
 * NOTE: the "advance to the next domain in order" logic here is a simple,
 * non-LLM placeholder so persistence and the wizard flow are demoable now.
 * It gets replaced by the real intelligence at FP7–FP8 (RequirementParser
 * extraction + AgentEngine routing). The persistence calls it uses
 * (writeExchange / writeDomainState) are the real FP6 methods and don't change.
 */
require_once __DIR__ . '/../src/InterviewSession.php';

$id  = $_POST['id'] ?? '';
$msg = trim($_POST['message'] ?? '');

$session = InterviewSession::readSession($id);
if ($session === null || $msg === '') {
    header('Location: ' . ($session ? 'session.php?id=' . urlencode($id) : 'index.php'));
    exit;
}

// Use the first user answer as the session title.
if (($session['title'] ?? '') === 'Untitled session') {
    $short = mb_strlen($msg) > 48 ? mb_substr($msg, 0, 48) . '…' : $msg;
    InterviewSession::setTitle($id, $short);
}

// Record the user's answer.
InterviewSession::writeExchange($id, 'user', $msg);

// Mark the current (first OPEN) domain covered, then ask the next one.
$state = InterviewSession::readDomainState($id);
$current = null;
foreach (InterviewSession::DOMAINS as $d) {
    if (($state[$d] ?? 'OPEN') === 'OPEN') { $current = $d; break; }
}

if ($current !== null) {
    InterviewSession::writeDomainState($id, [$current => 'COVERED']);

    // Find the next still-open domain to ask about.
    $state[$current] = 'COVERED';
    $next = null;
    foreach (InterviewSession::DOMAINS as $d) {
        if (($state[$d] ?? 'OPEN') === 'OPEN') { $next = $d; break; }
    }

    if ($next !== null) {
        InterviewSession::writeExchange($id, 'agent', InterviewSession::OPENING[$next]);
    } else {
        InterviewSession::writeExchange($id, 'agent',
            "That covers all 8 areas — your build plan is ready on the right.");
    }
}

header('Location: session.php?id=' . urlencode($id));
exit;
