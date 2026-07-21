<?php
/**
 * Handle a submitted answer.
 *
 * Routes every user message through the Orchestrator, which dispatches to
 * the appropriate domain agent, evaluates coverage, and returns the next
 * question. Falls back to the FP6 placeholder if the Orchestrator throws
 * (no LLM key, network error, etc.) so the app demos without a key.
 */
session_start();
require_once __DIR__ . '/../src/InterviewSession.php';
require_once __DIR__ . '/../src/Orchestrator.php';

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

try {
    $orchestrator = new Orchestrator();
    $result       = $orchestrator->dispatch($id, $msg);
    InterviewSession::writeExchange($id, 'agent', $result['response']);
} catch (Throwable $e) {
    // No key / agent failure → FP6 placeholder behavior
    advancePlaceholder($id);
}

header('Location: session.php?id=' . urlencode($id));
exit;

/**
 * FP6 fallback: mark the first OPEN domain COVERED and ask the next static
 * opening question. Keeps the wizard demoable when the LLM path is unavailable.
 */
function advancePlaceholder(string $id): void
{
    $state   = InterviewSession::readDomainState($id);
    $current = null;
    foreach (InterviewSession::DOMAINS as $d) {
        if (($state[$d] ?? 'OPEN') === 'OPEN') { $current = $d; break; }
    }
    if ($current === null) { return; }

    InterviewSession::writeDomainState($id, [$current => 'COVERED']);
    $state[$current] = 'COVERED';

    $next = null;
    foreach (InterviewSession::DOMAINS as $d) {
        if (($state[$d] ?? 'OPEN') === 'OPEN') { $next = $d; break; }
    }

    InterviewSession::writeExchange($id, 'agent', $next !== null
        ? InterviewSession::OPENING[$next]
        : 'That covers all 8 areas — your build plan is ready on the right.');
}
