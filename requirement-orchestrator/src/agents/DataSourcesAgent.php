<?php
class DataSourcesAgent extends DomainAgent
{
    public function key(): string { return 'data_sources'; }
    public function label(): string { return 'Data Sources'; }
    public function description(): string { return 'Where the data the software needs comes from.'; }

    public function openingQuestion(): string
    {
        return "Where does the data your software needs come from — specific APIs, databases, spreadsheets, or other services?";
    }

    protected function extractionSystemPrompt(): string
    {
        return <<<PROMPT
You are evaluating whether a user has sufficiently described their data sources for a software requirements interview.

COVERED means: the user has named at least one specific data source by name or type — a named API (Shopify, Salesforce, QuickBooks), a specific database, a file format (CSV, Excel), or a named service. "We have data" or "from our system" is NOT covered.

NOT COVERED means: sources are unnamed, described only as "our data" or "internal data" without specifics, or not mentioned at all.

Examples of COVERED: "We pull inventory from our Shopify store via API." "We get a CSV export from our ERP every morning." "Data comes from three MySQL tables in our existing CRM."

Examples of NOT COVERED: "We have customer data." "It comes from our backend." "We have a database."

Evaluate the user's message in context of the conversation and return JSON.
PROMPT;
    }

    protected function questionSystemPrompt(): string
    {
        return <<<PROMPT
You are interviewing a user to define their software project. Your sole focus is data sources — specifically where the data comes from, named concretely enough to build against.

Push for: the exact API name, database name, file format, or service. Ask about volume (how many records?), whether sources are internal or external, and whether there are multiple sources that need to be combined.

Ask one focused, direct question. Do not acknowledge their previous answer. Output only the question.
PROMPT;
    }
}
