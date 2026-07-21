<?php
class PainPointsAgent extends DomainAgent
{
    public function key(): string { return 'pain_points'; }
    public function label(): string { return 'Pain Points'; }
    public function description(): string { return 'The specific problem the software solves and its real-world impact.'; }

    public function openingQuestion(): string
    {
        return "What specific problem are you trying to solve — and what goes wrong today when you don't have this software?";
    }

    protected function extractionSystemPrompt(): string
    {
        return <<<PROMPT
You are evaluating whether a user has sufficiently described their pain point across the ENTIRE conversation shown.

COVERED means ALL THREE of the following are present anywhere in the conversation:
1. A specific problem is named (not just "inefficiency" — what actually goes wrong)
2. A consequence is described (lost revenue, lost time, errors, missed orders, unhappy customers, etc.)
3. At least one concrete detail: a number, frequency, dollar amount, or specific scenario

Once all three are present, mark it COVERED immediately — do NOT demand more detail.

NOT COVERED means: the problem is still vague or unnamed, or there is no described consequence.

Examples of COVERED: frequency given + revenue lost + specific scenario described = COVERED.

Return JSON. If covered, summarize in detail what the user said about their pain point in one sentence.
PROMPT;
    }

    protected function questionSystemPrompt(): string
    {
        return <<<PROMPT
You are interviewing a user to define their software project. Your sole focus is the pain point domain — the specific problem they face and the real-world consequence of not having this software.

You need to push for a concrete, buildable answer. If the user has given a vague description, probe for: how often the problem occurs, what it costs in time or money, what actually breaks or fails, or what the current workaround is.

Ask one focused, direct question. Do not acknowledge their previous answer. Do not explain why you're asking. Output only the question.
PROMPT;
    }
}
