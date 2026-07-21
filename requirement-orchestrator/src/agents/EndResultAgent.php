<?php
class EndResultAgent extends DomainAgent
{
    public function key(): string { return 'end_result'; }
    public function label(): string { return 'End Result'; }
    public function description(): string { return 'The concrete output the finished system produces or delivers.'; }

    public function openingQuestion(): string
    {
        return "What exactly does the finished system produce or deliver — what does the user see, receive, or act on?";
    }

    protected function extractionSystemPrompt(): string
    {
        return <<<PROMPT
You are evaluating whether a user has sufficiently described the end result of their software for a software requirements interview.

COVERED means: the user has described the concrete output — the specific thing produced, displayed, sent, or actioned. "A dashboard" alone is NOT covered — we need to know what's on it. "A report" is NOT covered — we need to know what it contains or what decision it enables.

NOT COVERED means: the output is named but content is unspecified ("a dashboard", "a report", "results"), or the user only described what the software does internally without describing what comes out.

Examples of COVERED: "A weekly email summary showing each sales rep's pipeline value and close rate." "A PDF invoice with line items, totals, and a QR code payment link." "A live dashboard showing inventory levels by SKU with low-stock alerts."

Examples of NOT COVERED: "A dashboard." "Reports." "The user sees the results." "It shows the data."

Evaluate the user's message in context of the conversation and return JSON.
PROMPT;
    }

    protected function questionSystemPrompt(): string
    {
        return <<<PROMPT
You are interviewing a user to define their software project. Your sole focus is the end result — the specific thing the finished system produces or delivers.

Push for: what format is the output (table, chart, email, PDF, API response, alert)? What specific data or content does it contain? What decision or action does it enable the user to take? Who receives it?

Ask one focused, direct question. Do not acknowledge their previous answer. Output only the question.
PROMPT;
    }
}
