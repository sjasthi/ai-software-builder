<?php
/**
 * FP10 — Asynchronous chain controller (JSON).
 *
 * Replaces the synchronous post_message.php redirect flow. public/js/app.js POSTs
 * here in the background; we run the SAME chain the Orchestrator drives —
 * Extraction (domain agent evaluate) -> gate (all 8 COVERED?) -> Routing (next
 * question) OR Compiler (build plan) — and return JSON the client applies in
 * place, with no full-page reload.
 *
 * Concurrency (the point of this milestone): a per-session flock guard rejects
 * an overlapping request with 409 {busy:true}. Combined with app.js's client-side
 * lock, that guarantees exactly one chain execution per session at a time — two
 * requests can never write domain_state for the same session simultaneously and
 * corrupt coverage.
 *
 * Owner: Cox (FP10). Client + stress test: Port (FP10).
 */
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../src/InterviewSession.php';
require_once __DIR__ . '/../src/Orchestrator.php';
require_once __DIR__ . '/../src/MySQLPersister.php';

/** Emit JSON and stop. */
function respond(array $payload, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

$id  = $_POST['id'] ?? '';
$msg = trim($_POST['message'] ?? '');

// Key arrives as a plain POST field — used for this request only, never stored.
$apiKey = trim($_POST['api_key'] ?? '');
if ($apiKey !== '') {
    \LlmClientFactory::setRuntimeKey($apiKey);
}

// readSession() validates $id against a path-traversal regex before touching disk.
$session = InterviewSession::readSession($id);
if ($session === null) {
    respond(['ok' => false, 'error' => 'invalid_session'], 400);
}
if ($msg === '') {
    respond(['ok' => false, 'error' => 'empty_message'], 400);
}

// ── Server-side concurrency guard ───────────────────────────────────────────
// Non-blocking exclusive lock, scoped to this session. If a second request for
// the same session arrives while the first is still running, we bail with 409
// instead of executing the chain twice. $id is already traversal-validated above.
$lockPath = __DIR__ . '/../sessions/' . $id . '.lock';
$lockFp   = @fopen($lockPath, 'c');
if ($lockFp === false || !flock($lockFp, LOCK_EX | LOCK_NB)) {
    respond(['ok' => false, 'busy' => true], 409);
}

// From here on the lock is held. flock is released automatically when the script
// terminates (exit closes the handle), so the respond()/exit paths are safe.
try {
    // Use the first user answer as the session title (parity with post_message.php).
    if (($session['title'] ?? '') === 'Untitled session') {
        $short = mb_strlen($msg) > 48 ? mb_substr($msg, 0, 48) . '…' : $msg;
        InterviewSession::setTitle($id, $short);
    }

    // Record the user's answer.
    InterviewSession::writeExchange($id, 'user', $msg);
    MySQLPersister::ensureSession($id);
    MySQLPersister::logExchange($id, 'user', $msg);

    try {
        $orchestrator = new Orchestrator();
        $result       = $orchestrator->dispatch($id, $msg);
        $agentMessage = $result['response'];
        $domainState  = $result['domain_state'];
        $done         = !empty($result['done']);
        $activeDomain = $result['active_domain'] ?? null;
    } catch (Throwable $e) {
        // No key / agent failure → FP6 placeholder behavior, returned as JSON so
        // the app still demos end-to-end without a key.
        [$agentMessage, $domainState, $done, $activeDomain] = advancePlaceholder($id);
    }

    // Record the agent's reply.
    InterviewSession::writeExchange($id, 'agent', $agentMessage);
    MySQLPersister::logExchange($id, 'agent', $agentMessage);

    $payload = [
        'ok'            => true,
        'done'          => $done,
        'agent_message' => $agentMessage,
        'domain_state'  => $domainState,
        'active_domain' => $activeDomain,
    ];

    // On completion, hand back the server-rendered build-plan panel so the client
    // can swap the right pane in place. dispatch() already ran the Compiler via
    // ensureBuildPlan() and writeDomainState() flipped status to 'complete', so the
    // reloaded session carries the stored plan the partial renders from.
    if ($done) {
        $session = InterviewSession::readSession($id);
        ob_start();
        include __DIR__ . '/partials/build_plan.php';   // expects $session in scope
        $payload['plan_html'] = ob_get_clean();
    }

    respond($payload);
} finally {
    // Explicit release for the (rare) non-exit code path; harmless after exit.
    if (is_resource($lockFp)) {
        flock($lockFp, LOCK_UN);
        fclose($lockFp);
    }
}

/**
 * FP6 fallback: mark the first OPEN domain COVERED and ask the next static
 * opening question. Mirrors post_message.php's placeholder, but returns the
 * pieces the JSON payload needs instead of writing the agent turn itself.
 *
 * @return array{0:string,1:array<string,string>,2:bool,3:?string}
 *         [agentMessage, domainState, done, activeDomain]
 */
function advancePlaceholder(string $id): array
{
    $state   = InterviewSession::readDomainState($id);
    $current = null;
    foreach (InterviewSession::DOMAINS as $d) {
        if (($state[$d] ?? 'OPEN') === 'OPEN') { $current = $d; break; }
    }

    if ($current !== null) {
        InterviewSession::writeDomainState($id, [$current => 'COVERED']);
        $state[$current] = 'COVERED';
    }

    $next = null;
    foreach (InterviewSession::DOMAINS as $d) {
        if (($state[$d] ?? 'OPEN') === 'OPEN') { $next = $d; break; }
    }

    $message = $next !== null
        ? InterviewSession::OPENING[$next]
        : 'That covers all 8 areas — your build plan is ready on the right.';

    return [$message, $state, $next === null, $next];
}
