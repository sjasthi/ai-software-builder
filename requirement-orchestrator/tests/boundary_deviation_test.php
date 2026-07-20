<?php
/**
 * Boundary deviation verification proof — Port (FP8).
 *
 * Proves the Routing Agent's OUT-OF-SCOPE branch: when the user drifts off-topic,
 * the interview redirects them back WITHOUT advancing — no domain flips, the
 * progress bar does not move. Also proves the IN-SCOPE branch is allowed to
 * advance, that a mis-classification never traps the user, and that both LLM
 * branches fall back to templates when no model is available.
 *
 * Like the FP7 gate proof, this needs no live LLM: routing decisions are pure,
 * and the LLM calls take an injected fake client. Run:
 *
 *     C:\xampp\php\php.exe tests\boundary_deviation_test.php
 */
require_once __DIR__ . '/../src/InterviewSession.php';
require_once __DIR__ . '/../src/LlmClient.php';
require_once __DIR__ . '/../src/AgentEngine.php';

$pass = 0; $fail = 0;
function check(string $label, bool $ok) {
    global $pass, $fail;
    echo ($ok ? "  [PASS] " : "  [FAIL] ") . $label . "\n";
    $ok ? $pass++ : $fail++;
}

/** Fake provider: returns a canned string (or null to force the fallback path). */
class FakeLlm implements LlmClient
{
    public function __construct(private ?string $reply) {}
    public function complete(string $system, array $messages, array $opts = []): ?string
    {
        return $this->reply;
    }
}

$dir = __DIR__ . '/../sessions';

// ── pure routing decisions (the heart of the boundary logic) ──────────────────
check('OUT_OF_SCOPE does not advance',        AgentEngine::route('OUT_OF_SCOPE')['advance'] === false);
check('OUT_OF_SCOPE routes to REDIRECT',      AgentEngine::route('OUT_OF_SCOPE')['action'] === 'REDIRECT');
check('IN_SCOPE advances',                    AgentEngine::route('IN_SCOPE')['advance'] === true);
check('IN_SCOPE routes to EXTRACT',           AgentEngine::route('IN_SCOPE')['action'] === 'EXTRACT');
check('label is case/space-insensitive',      AgentEngine::route('  out_of_scope ')['advance'] === false);
check('unknown label never traps the user',   AgentEngine::route('banana')['advance'] === true);

// ── nextOpenDomain walks the domains in order ─────────────────────────────────
check('first open domain is pain_points',
      AgentEngine::nextOpenDomain(InterviewSession::readDomainState('nope')) === 'pain_points');
check('skips covered domains',
      AgentEngine::nextOpenDomain(['pain_points' => 'COVERED', 'data_sources' => 'OPEN']) === 'data_sources');
check('all covered → null (nothing left to ask)',
      AgentEngine::nextOpenDomain(array_fill_keys(InterviewSession::DOMAINS, 'COVERED')) === null);

// ── OUT-OF-SCOPE branch leaves domain state byte-for-byte unchanged ───────────
$id   = InterviewSession::createSession('__boundary_test__');
$file = $dir . '/' . $id . '.json';
InterviewSession::writeDomainState($id, ['pain_points' => 'COVERED']); // partway in

$engine = new AgentEngine(new FakeLlm('Happy to help — but first, back to your project: what data does it use?'));
$before = InterviewSession::readDomainState($id);

// Simulate the controller's out-of-scope branch exactly.
$route = AgentEngine::route(AgentEngine::OUT_OF_SCOPE);
check('drift message is routed as no-advance', $route['advance'] === false);
$domain   = AgentEngine::nextOpenDomain($before);
$redirect = $engine->redirect($id, "what's the weather today?", $domain);
InterviewSession::writeExchange($id, 'agent', $redirect);

$after = InterviewSession::readDomainState($id);
check('domain state is unchanged after a drift turn', $before === $after);

$transcript = InterviewSession::readTranscript($id);
$last       = end($transcript);
check('a redirect turn was appended by the agent',
      $last['role'] === 'agent' && $last['content'] === $redirect);
check('redirect targets the current open domain (data_sources)', $domain === 'data_sources');

// ── graceful fallback when no model is available (client returns null) ────────
$noModel = new AgentEngine(new FakeLlm(null));
$fbRedirect = $noModel->redirect($id, 'random chatter', 'data_sources');
check('redirect falls back to a steer-back template',
      str_contains($fbRedirect, 'focused on your') && str_contains($fbRedirect, InterviewSession::OPENING['data_sources']));

$fbQuestion = $noModel->nextQuestion($id, $before);
check('nextQuestion falls back to the static opening question',
      $fbQuestion === InterviewSession::OPENING['data_sources']);

// ── IN-SCOPE branch: a tailored question comes straight from the model ────────
$live = new AgentEngine(new FakeLlm('Where do those customer orders arrive today — email, a form, somewhere else?'));
check('nextQuestion returns the model-tailored question when available',
      $live->nextQuestion($id, $before) === 'Where do those customer orders arrive today — email, a form, somewhere else?');

// ── teardown ──────────────────────────────────────────────────────────────────
@unlink($file);

echo "\n  $pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
