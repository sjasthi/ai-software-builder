# Design note — storing agent definitions

**Status:** Decision made and implemented. **Not a pending question.**

## The question (Kenan, post-FP8)

Agents should be **pre-built and stored** — either in the **database schema** or **in the program** — and the runtime just loads and runs each one, regardless of which AI model is used.

## Decision: program files (PHP classes), one per domain

Agents are implemented as PHP classes in `src/agents/`:

```
src/agents/
├── DomainAgent.php          ← abstract base (shared evaluate + nextQuestion logic)
├── PainPointsAgent.php
├── DataSourcesAgent.php
├── DataAccessAgent.php
├── EndResultAgent.php
├── StakeholdersAgent.php
├── AudienceTypeAgent.php
├── CurrentProcessAgent.php
└── InteractionModelAgent.php
```

Each agent class contains its own prompt definitions inline (extraction prompt + question generation prompt). The `Orchestrator` instantiates all 8 at startup and routes to the active one per turn.

## Why program files over a database table

- **No DB needed at runtime.** The app persists sessions as JSON files. Adding a live DB dependency just to load prompt strings adds latency and a failure mode for no gain.
- **Self-contained.** Each agent class is its own unit — prompt, covered criteria, fallback question, and LLM call logic in one place. Readable and independently testable.
- **Model-agnostic: already done.** All LLM calls go through `src/LlmClient.php`. Switching models is one line in `config/local.php` — the agent classes don't change.
- **Simpler to modify.** Editing a domain's extraction criteria means opening one file, not running a DB migration.

## What's still configurable

Per-task model assignment lives in `config/local.php` (`task_models` key) — not hardcoded in the agent classes. So "use Opus for extraction, Haiku for question generation" is a config change, not a code change.
