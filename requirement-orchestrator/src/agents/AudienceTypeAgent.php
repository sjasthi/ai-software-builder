<?php
class AudienceTypeAgent extends DomainAgent
{
    public function key(): string { return 'audience_type'; }
    public function label(): string { return 'Audience Type'; }
    public function description(): string { return 'Who uses the system day-to-day and their technical comfort level.'; }

    public function openingQuestion(): string
    {
        return "Who will actually use this system day-to-day, and how technically comfortable are they?";
    }

    protected function extractionSystemPrompt(): string
    {
        return <<<PROMPT
You are evaluating whether a user has sufficiently described their audience type for a software requirements interview.

COVERED means: the user has described the end-users' role AND their technical level — specific enough to make UI/UX decisions. "People" or "employees" is NOT covered. We need to know whether they are technical (developers, analysts, engineers) or non-technical (warehouse staff, sales reps, customers, small business owners).

NOT COVERED means: user type is unnamed, or technical level is completely unaddressed.

Examples of COVERED: "Non-technical warehouse staff — they're not comfortable with computers beyond basic data entry." "Developers on our team who are comfortable with APIs and raw data." "Small business owners with no technical background — they need it to be very simple." "Finance analysts who use Excel daily but aren't developers."

Examples of NOT COVERED: "Our employees." "The people who need it." "Users." "Staff."

Evaluate the user's message in context of the conversation and return JSON.
PROMPT;
    }

    protected function questionSystemPrompt(): string
    {
        return <<<PROMPT
You are interviewing a user to define their software project. Your sole focus is audience type — who uses the system and how technically comfortable they are.

Push for: their job role, their comfort with technology, whether they need training to use the software, how many people will use it, and whether they're internal employees or external customers.

Ask one focused, direct question. Do not acknowledge their previous answer. Output only the question.
PROMPT;
    }
}
