<?php
require_once __DIR__ . '/InterviewSession.php';
require_once __DIR__ . '/LlmClient.php';
require_once __DIR__ . '/agents/DomainAgent.php';
require_once __DIR__ . '/agents/PainPointsAgent.php';
require_once __DIR__ . '/agents/DataSourcesAgent.php';
require_once __DIR__ . '/agents/DataAccessAgent.php';
require_once __DIR__ . '/agents/EndResultAgent.php';
require_once __DIR__ . '/agents/StakeholdersAgent.php';
require_once __DIR__ . '/agents/AudienceTypeAgent.php';
require_once __DIR__ . '/agents/CurrentProcessAgent.php';
require_once __DIR__ . '/agents/InteractionModelAgent.php';

class Orchestrator
{
    /** @var DomainAgent[] keyed by domain key */
    private array $agents;

    public function __construct(?LlmClient $client = null)
    {
        $this->agents = [
            'pain_points'       => new PainPointsAgent($client),
            'data_sources'      => new DataSourcesAgent($client),
            'data_access'       => new DataAccessAgent($client),
            'end_result'        => new EndResultAgent($client),
            'stakeholders'      => new StakeholdersAgent($client),
            'audience_type'     => new AudienceTypeAgent($client),
            'current_process'   => new CurrentProcessAgent($client),
            'interaction_model' => new InteractionModelAgent($client),
        ];
    }

    /**
     * Main entry point. Process a user message, return response + updated domain state.
     * Returns: ['done' => bool, 'response' => string, 'domain_state' => array, 'active_domain' => string|null]
     */
    public function dispatch(string $sessionId, string $userMessage): array
    {
        $domainState = InterviewSession::readDomainState($sessionId);
        $transcript  = InterviewSession::readTranscript($sessionId);

        $activeKey = $this->nextOpenDomain($domainState);

        if ($activeKey === null) {
            return [
                'done'          => true,
                'response'      => 'That covers all 8 areas — your build plan is ready on the right.',
                'domain_state'  => $domainState,
                'active_domain' => null,
            ];
        }

        // Let the active domain agent evaluate the user's message
        $agent  = $this->agents[$activeKey];
        $result = $agent->evaluate($userMessage, $transcript);

        // Force-advance after 5 agent turns on the same domain to prevent infinite loops.
        if (!$result['covered']) {
            $agentTurnsOnDomain = count(array_filter(
                array_slice($transcript, -12),
                fn($t) => ($t['role'] ?? '') === 'agent'
            ));
            if ($agentTurnsOnDomain >= 5) {
                $result['covered'] = true;
                $result['detail']  = $result['detail'] ?: 'Sufficient detail gathered across multiple exchanges.';
            }
        }

        if ($result['covered']) {
            $domainState[$activeKey] = 'COVERED';
            InterviewSession::writeDomainState($sessionId, $domainState);
            if (($result['detail'] ?? '') !== '') {
                InterviewSession::writeDomainAnswer($sessionId, $activeKey, $result['detail']);
            }
        }

        // Check if all domains are now covered after this update
        $nextKey = $this->nextOpenDomain($domainState);
        if ($nextKey === null) {
            return [
                'done'          => true,
                'response'      => 'That covers all 8 areas — your build plan is ready on the right.',
                'domain_state'  => $domainState,
                'active_domain' => null,
            ];
        }

        // Generate the next question from whichever domain agent is now active
        $nextAgent       = $this->agents[$nextKey];
        $freshTranscript = InterviewSession::readTranscript($sessionId);
        $question        = $nextAgent->nextQuestion($freshTranscript);

        return [
            'done'          => false,
            'response'      => $question,
            'domain_state'  => $domainState,
            'active_domain' => $nextKey,
        ];
    }

    public function nextOpenDomain(array $domainState): ?string
    {
        foreach (array_keys($this->agents) as $key) {
            if (($domainState[$key] ?? 'OPEN') !== 'COVERED') return $key;
        }
        return null;
    }

    /** Return the opening question for the first uncovered domain. */
    public function openingQuestion(string $sessionId): string
    {
        $domainState = InterviewSession::readDomainState($sessionId);
        $key = $this->nextOpenDomain($domainState);
        if ($key === null) return 'All areas are already covered.';
        return $this->agents[$key]->openingQuestion();
    }
}
