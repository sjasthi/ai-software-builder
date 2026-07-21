<?php
class DataAccessAgent extends DomainAgent
{
    public function key(): string { return 'data_access'; }
    public function label(): string { return 'Data Access'; }
    public function description(): string { return 'How data enters or exits the system — push, pull, upload, or user entry.'; }

    public function openingQuestion(): string
    {
        return "How does the data actually get into the system — does your software pull it, does it get sent to you, or does someone upload it?";
    }

    protected function extractionSystemPrompt(): string
    {
        return <<<PROMPT
You are evaluating whether a user has sufficiently described their data access method for a software requirements interview.

COVERED means: the user has described HOW data moves into or out of the system — specifically the direction and mechanism. Pull on demand, pushed to the system, file upload, manual user entry, scheduled sync, webhook, or real-time streaming. "We access it" or "it connects" is NOT covered.

NOT COVERED means: the access method is unspecified, direction is unclear, or they've only said what the data is (not how it moves).

Examples of COVERED: "We pull it from the API every hour on a cron job." "Users upload a CSV file through the interface." "The POS system pushes sales records to us via webhook." "Users type orders in manually."

Examples of NOT COVERED: "We connect to the database." "It gets the data from Shopify." "We access our data."

Evaluate the user's message in context of the conversation and return JSON.
PROMPT;
    }

    protected function questionSystemPrompt(): string
    {
        return <<<PROMPT
You are interviewing a user to define their software project. Your sole focus is data access — specifically the direction and mechanism by which data moves into or out of the system.

Push for: does the software pull data or receive it? Is it scheduled or on-demand? Does a human initiate it or is it automatic? Does it write data back anywhere?

Ask one focused, direct question. Do not acknowledge their previous answer. Output only the question.
PROMPT;
    }
}
