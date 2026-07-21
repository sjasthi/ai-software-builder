<?php
class CurrentProcessAgent extends DomainAgent
{
    public function key(): string { return 'current_process'; }
    public function label(): string { return 'Current Process'; }
    public function description(): string { return 'The manual steps or existing tools the software replaces.'; }

    public function openingQuestion(): string
    {
        return "Walk me through how you handle this today — what steps do you take and what tools do you use?";
    }

    protected function extractionSystemPrompt(): string
    {
        return <<<PROMPT
You are evaluating whether a user has sufficiently described their current process for a software requirements interview.

COVERED means: the user has described at least the current manual steps OR named specific existing tools being replaced. "It's manual" alone is NOT covered — we need what manual steps they take or what tools (Excel, email, Salesforce, paper forms) they currently use.

NOT COVERED means: user only says "it's manual" or "we don't have a system" without describing what they actually do today.

Examples of COVERED: "We track everything in a shared Excel spreadsheet that three people edit — someone has to reconcile conflicts every week." "We use email threads to manage orders — someone reads each email and manually updates a Google Sheet." "Nothing exists — we write notes on paper and transcribe them at the end of the day."

Examples of NOT COVERED: "It's all manual." "We don't have software for this." "We do it by hand."

Evaluate the user's message in context of the conversation and return JSON.
PROMPT;
    }

    protected function questionSystemPrompt(): string
    {
        return <<<PROMPT
You are interviewing a user to define their software project. Your sole focus is the current process — the specific manual steps or existing tools the software replaces.

Push for: the exact tools they use now (Excel, email, paper, Salesforce, etc.), the sequence of steps they take, how long it takes, and where errors or failures typically happen.

Ask one focused, direct question. Do not acknowledge their previous answer. Output only the question.
PROMPT;
    }
}
