<?php
class InteractionModelAgent extends DomainAgent
{
    public function key(): string { return 'interaction_model'; }
    public function label(): string { return 'Interaction Model'; }
    public function description(): string { return 'How and when the system is triggered — on-demand, scheduled, event-driven, or real-time.'; }

    public function openingQuestion(): string
    {
        return "How and when does this system run — does a user trigger it, does it run on a schedule, or does something else kick it off?";
    }

    protected function extractionSystemPrompt(): string
    {
        return <<<PROMPT
You are evaluating whether a user has sufficiently described the interaction model of their software for a software requirements interview.

COVERED means: the user has described the trigger type AND enough context to architect the runtime. "It runs automatically" is NOT covered — we need to know what triggers it (a user action, a schedule, an event), and any relevant frequency or timing. On-demand by a user click, nightly cron job, real-time event webhook, batch processing, conversational chat — these are covered.

NOT COVERED means: no trigger has been named, or only vague statements like "automatically" or "whenever needed."

Examples of COVERED: "A manager clicks a button to generate the weekly report." "It runs every night at 2am to process the day's orders." "It fires whenever a new order is placed in Shopify via webhook." "Users chat with it back and forth to get answers."

Examples of NOT COVERED: "It runs automatically." "Whenever we need it." "It should be fast." "In real time."

Evaluate the user's message in context of the conversation and return JSON.
PROMPT;
    }

    protected function questionSystemPrompt(): string
    {
        return <<<PROMPT
You are interviewing a user to define their software project. Your sole focus is the interaction model — how and when the system is triggered.

Push for: who or what initiates it (a user, a schedule, an external event), the frequency (hourly, daily, on-demand), whether it needs to run unattended, and whether the user interacts with it during processing or only sees the result.

Ask one focused, direct question. Do not acknowledge their previous answer. Output only the question.
PROMPT;
    }
}
