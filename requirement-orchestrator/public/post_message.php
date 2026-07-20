<?php
/**
 * Handle a submitted answer.
 *
 * This is the sequential agent chain (FP8): record the answer, then
 *   Routing Agent (scope) → in-scope: Extraction → gate → next question
 *                         → out-of-scope: redirect, no advance.
 * At FP10 this same logic lifts into public/endpoint.php for AJAX, unchanged.
 *
 * Graceful degradation: if no LLM key is configured (or any agent call throws),
 * we fall back to the FP6 placeholder — mechanically advance one domain and ask
 * the static opening question — so the app still demos without a key.
 */
session_start();  // so the agents can read the visitor's per-use API key
require_once __DIR__ . '/../src/InterviewSession.php';
require_once __DIR__ . '/../src/RequirementParser.php';
require_once __DIR__ . '/../src/AgentEngine.php';

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
    $engine = new AgentEngine();

    // ── Routing Agent: is this message about the project, or drift? ──
    $scope = $engine->classifyScope($id, $msg);

    if (!AgentEngine::route($scope)['advance']) {
        // OUT-OF-SCOPE (Port): steer back to the current domain, do NOT advance.
        $domain = AgentEngine::nextOpenDomain(InterviewSession::readDomainState($id)) ?? 'pain_points';
        InterviewSession::writeExchange($id, 'agent', $engine->redirect($id, $msg, $domain));
    } else {
        // IN-SCOPE (Cox): extraction → gate → tailored next question.
        $parser   = new RequirementParser();
        $newState = $parser->extract($id, $msg);
        if ($newState !== null) {
            InterviewSession::writeDomainState($id, $newState);
        }

        $state = InterviewSession::readDomainState($id);
        if (RequirementParser::gate($state)['all_covered']) {
            InterviewSession::writeExchange($id, 'agent',
                'That covers all 8 areas — your build plan is ready on the right.');
        } else {
            InterviewSession::writeExchange($id, 'agent', $engine->nextQuestion($id, $state));
        }
    }
} catch (Throwable $e) {
    // ── No key / agent failure → FP6 placeholder behavior ──
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
