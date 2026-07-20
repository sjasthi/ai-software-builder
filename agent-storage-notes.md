# Design note — storing agent definitions (post-FP8, for class discussion)

**Status:** parked for a team decision. **Not required for FP8** — the routing agent and
per-use key are complete and correct no matter where agent definitions live. This is an
*additive* refactor we can do later without changing any routing logic.

## The idea (Kenan)
Agents should be **pre-built and stored** — either in the **database schema** or **in the
program** — and the runtime just loads and runs each one, **regardless of which AI model** is used.

## What's already true
- **Model-agnostic: done.** All AI calls go through `src/LlmClient.php`, so any model (or the
  free mock) can drive any agent. Model choice is config, not code.
- **Agents are pre-built** as PHP classes: the Extraction Agent (`src/RequirementParser.php`)
  and the Routing Agent (`src/AgentEngine.php`).

## What's still inline (the thing to externalize)
Each agent's **definition = its prompt/instructions** is currently a hardcoded string inside
the class methods:
- extraction prompt → `RequirementParser::extract()`
- scope / question / redirect prompts → `AgentEngine::classifyScope()`, `nextQuestion()`, `redirect()`

The refactor: pull those prompts into **one stored registry** and have the classes load their
definition from it (each agent = name + task + prompt template + default model).

## Options to decide in class
| Option | What it means | Trade-off |
|--------|---------------|-----------|
| **Program file** (e.g. `config/agents.php`) | One PHP/JSON file holds every agent's prompt; classes read from it. | Simplest; fits our current file-based (JSON) runtime; no DB needed at runtime. |
| **MySQL schema** (an `agents` table) | Add + seed an `agents` table in `config/schema.sql`; agents load their prompt from the DB. | Matches the "database schema" phrasing, but needs a live DB connection at runtime (our app currently persists to JSON files, not MySQL). |

## Questions for the team
1. Does the professor want agent definitions in the **DB schema** specifically, or is a stored
   **program file** acceptable (given runtime uses JSON files, not MySQL)?
2. Should the registry also hold each agent's **default model** (so "best-fit model per task"
   lives beside the agent definition)?
3. Any agents beyond Extraction + Routing to pre-register now (e.g. the future Compiler Agent)?
