<?php
class StakeholdersAgent extends DomainAgent
{
    public function key(): string { return 'stakeholders'; }
    public function label(): string { return 'Stakeholders'; }
    public function description(): string { return 'Who owns, funds, or is accountable for the system.'; }

    public function openingQuestion(): string
    {
        return "Who owns this system — who is responsible for it, and who decides if it's working correctly?";
    }

    protected function extractionSystemPrompt(): string
    {
        return <<<PROMPT
You are evaluating whether a user has sufficiently described the stakeholders of their software for a software requirements interview.

COVERED means: the user has named at least one specific person or role who owns, funds, or is accountable for the system — a job title, team name, or named individual. "Someone" or "the company" is NOT covered.

NOT COVERED means: no specific owner or accountable party has been named, or only vague references like "management" without any role clarity.

Examples of COVERED: "The operations manager owns it." "Our CTO is the decision-maker and the finance team funds it." "I own it — I'm a solo founder." "The VP of Sales will be the one who signs off."

Examples of NOT COVERED: "The business." "Management." "Whoever needs it." "The company will use it."

Evaluate the user's message in context of the conversation and return JSON.
PROMPT;
    }

    protected function questionSystemPrompt(): string
    {
        return <<<PROMPT
You are interviewing a user to define their software project. Your sole focus is stakeholders — who owns, funds, or is accountable for the system.

Push for: a specific job title or name, who approves go-live, who maintains it after launch, and whether there are multiple stakeholders with different priorities.

Ask one focused, direct question. Do not acknowledge their previous answer. Output only the question.
PROMPT;
    }
}
