# Cox — Weekly Progress Log

---

## FP4 — Week 6 | Due: Jun 22
**Deliverable:** Write `config/database.php`: DB connection setup and shared utilities

**Status:** `[ ] Not Started` / `[ ] In Progress` / `[x] Complete`

### What Was Added
- Created `requirement-orchestrator/config/database.php` — PDO singleton with ERRMODE_EXCEPTION, utf8mb4, emulate_prepares off
- Created `requirement-orchestrator/config/.env.example` — documents required environment variables
- Created `.gitignore` — blocks `.env`, `*.local.php`, `.idea/`, `test_db.php`, `vendor/`
- Created full project folder scaffold: `config/`, `src/`, `public/js/`, `sql/`

### Notes
<!-- Add anything relevant: blockers, decisions made, deviations from plan -->

---

## FP5 — Week 7 | Due: Jun 29
**Deliverable:** Build HTML skeleton of `public/index.php` — Bootstrap 5 split-pane layout with both panel containers; style and wire left chat panel (agent/user bubbles, text input, submit button)

**Status:** `[ ] Not Started` / `[ ] In Progress` / `[x] Complete`

### What Was Added
- `requirement-orchestrator/public/index.php` — Bootstrap 5 split-pane layout. Left panel: scrollable chat stream with agent/user message bubbles, text input, Send button with loading spinner, Enter-key support. Right panel: `#domain-matrix` container with `data-domain` hook structure ready for Port's FP5 badges. JS wires the submit flow (appends bubble, POSTs to `endpoint.php`, graceful fallback until FP9).

### Notes
- Right panel container (`#domain-matrix`) is intentionally empty — Port fills it with 8 `data-domain` badge rows and progress bar in FP5.
- Full AJAX chain (`endpoint.php`) and badge state updates (`app.js`) wired in FP9.

---

## FP6 — Week 8 | Due: Jul 6
**Deliverable:** Implement `createSession()`, `readSession()`, `writeExchange()`, and `readTranscript()` in `InterviewSession.php`

**Status:** `[ ] Not Started` / `[ ] In Progress` / `[x] Complete`

### What Was Added
- `requirement-orchestrator/src/InterviewSession.php` — `createSession()` generates a unique session id, seeds the transcript with the opening pain-points question, and writes the initial JSON file. `readSession()` validates the id against a path-traversal regex before reading. `writeExchange()` appends one role/content/timestamp entry to the transcript array and atomically saves. `readTranscript()` returns the full transcript for a given session. `setTitle()` derives a session title from the user's first answer. `listSessions()` powers the Previous Sessions landing screen.

### Notes
- All four required methods plus two supporting ones are implemented and verified by Port's recovery test (`tests/session_recovery_test.php` — 9 passed, 0 failed).
- Session files are stored as `sessions/<id>.json`; writes go through `atomicSave()` (Port's temp-then-rename) so a crash mid-write never corrupts a live session.

---

## FP7 — Week 9 | Due: Jul 13
**Deliverable:** Build `RequirementParser.php` — construct extraction prompt, inject user message + domain JSON, call LLM in JSON mode; implement response validation (parse and validate returned JSON against 8-domain schema, write to DB)

**Status:** `[ ] Not Started` / `[ ] In Progress` / `[x] Complete`

### What Was Added
- `requirement-orchestrator/src/RequirementParser.php` — `extract()` builds the system prompt with live domain state + domain definitions injected, calls Claude (`claude-opus-4-8`, 512 max tokens) via PHP's built-in curl (no Composer/SDK required), and routes the raw text through `parseAndValidate()`. Validation: strips markdown fencing, JSON-decodes, confirms all 8 domain keys present, and enforces COVERED-sticky invariant (an already-covered domain cannot regress).

### Notes
- Uses PHP's built-in curl — no Composer or external SDK needed. Requires `ANTHROPIC_API_KEY` in the environment (or `config/local.php`).
- Domain definitions added to system prompt so the LLM correctly maps "every morning" → `interaction_model` (scheduled automation).
- No streaming needed — extraction output is always < 512 tokens; latency is negligible.
- `RequirementParser.php` was later superseded by the Orchestrator-Workers architecture (FP8) and removed from the codebase.

---

## FP8 — Week 10 | Due: Jul 20
**Deliverable:** Build `AgentEngine.php` — construct routing prompt, inject transcript + domain state, call LLM API; implement in-scope routing branch (LLM generates domain-targeting question for next OPEN domain).

**Status:** `[ ] Not Started` / `[ ] In Progress` / `[x] Complete`

> _In-scope branch covered by Port this week per team agreement (Cox made up FP7 last week). Cox's contribution this week was the architectural upgrade to Orchestrator-Workers._

### What Was Added
- `src/agents/DomainAgent.php` — abstract base class with shared `evaluate()` (30-turn transcript window, returns `{covered, detail}`) and `nextQuestion()` logic. All 8 domain agents extend this.
- `src/agents/PainPointsAgent.php` through `InteractionModelAgent.php` — 8 specialized domain agents, each with a domain-specific extraction prompt defining exactly what "covered" means for that domain and a focused question-generation prompt.
- `src/Orchestrator.php` — dispatches each user message to the active domain agent, writes `COVERED` + `writeDomainAnswer(detail)` when satisfied, force-advances after 5 agent turns, calls `nextQuestion()` on the next active agent.
- `src/LlmClient.php` — provider abstraction: `AnthropicClient` (Claude) and `OpenAIClient` (ChatGPT), both raw-curl, plus `ScriptedLlm` for demo mode. `LlmClientFactory::setRuntimeKey()` accepts per-request key injection without session storage.
- `public/post_message.php` — rewired to call `Orchestrator::dispatch()`; reads `api_key` from POST and sets runtime key. Old AgentEngine + RequirementParser pipeline replaced.
- `config/local.php.example` — multi-provider config template.
- `src/InterviewSession.php` — added `writeDomainAnswer(id, domain, detail)` and `readDomainAnswers(id)` so the Orchestrator can persist extracted detail per domain for the Compiler Agent.
- `public/index.php` + `public/session.php` — API key entry refactored: key stored in browser `sessionStorage` only (tab-scoped, never server-side), passed as hidden POST field on each submission. `set_key.php` removed.

### Notes
- **Why 8 agents instead of 1:** Each dedicated agent is opinionated about its domain — PainPointsAgent won't accept "it's slow" without a consequence; DataSourcesAgent won't accept "we have data" without a named source. Narrower prompts produce more accurate extraction than a single general extractor.
- **30-turn eval window:** early concrete answers were falling out of the original 6-turn window as conversations grew deep. Expanding to 30 ensures the agent evaluates the full domain conversation.
- **Turn cap:** after 5 agent questions on one domain, the Orchestrator force-marks COVERED to prevent infinite loops.
- Requires an Anthropic or OpenAI key; falls back to static opening questions without one.
- Demo walkthrough: `demoFP8.md`.

---

## FP9 — Week 11 | Due: Jul 27
**Deliverable:** Build `ManifestGenerator.php` — read finalized 8-domain data from DB, populate 5-prompt build plan template; implement placeholder substitution (replace all template vars with user's verified answers, wire output into right panel transition)

**Status:** `[ ] Not Started` / `[ ] In Progress` / `[x] Complete`

### What Was Added

**Compiler Agent (the core deliverable):**
- `src/ManifestGenerator.php` — the **Compiler Agent**, a separate agent from the 8 domain agents. `generate($sessionId)` pulls the finalized answers out of the **DB** (`MySQLPersister::readDomainAnswers()` → `domain_state.domain_json`, the same detail the Orchestrator wrote on each COVERED), then **LLM-composes** a sequenced 5-prompt build plan via the new `plan_generation` task. If there's no key / the call fails / the JSON is malformed, it falls back to deterministic §3c template substitution — so `generate()` always returns a valid, non-empty 5-key plan. Reads from the DB first, falls back to the JSON session so the app still produces a plan with no MySQL. **Prompt 1 (Project Initialization) is guaranteed to open with a persona** — "You are a senior software architect." — via `ensurePersona()` on both the LLM and template paths.
- `src/MySQLPersister.php` — added `readDomainAnswers($jsonId)`: reads and decodes `domain_state.domain_json`; silent-fail → `[]`. (Reuses the existing `writeBuildPlan()`.)
- `src/InterviewSession.php` — added `writeBuildPlan($id, $plan)`: stores the assoc `prompt_1…prompt_5` plan as an ordered indexed array under the session's `build_plan`.
- `src/LlmClient.php` — registered the `plan_generation` task (Anthropic → `claude-opus-4-8`, OpenAI → `gpt-4o`; the one-shot final plan gets the strongest model since it runs at most once per session). Added a `ScriptedLlm::mockPlan()` branch so mock mode demos a populated plan end-to-end.
- `src/Orchestrator.php` — added idempotent `ensureBuildPlan()`, triggered when all 8 are COVERED. Persists the plan to both the JSON session and MySQL `generated_plans`; never regenerates once stored.

**Right-panel build-plan UI (`public/partials/build_plan.php`):**
- Renders the populated plan as numbered green-step cards (Project Initialization → Integration & Testing) under a green header banner, each prompt in a monospace block.
- **Copy** button per prompt (`navigator.clipboard`, legacy fallback, "Copied ✓" flash).
- **Download** the whole plan as **.md / .txt / .json**. The `.json` export carries the source requirements + per-domain coverage alongside the 5 prompts.

**Coverage transparency:**
- `src/InterviewSession.php` — `writeDomainAnswer(..., $covered)` + `readDomainCoverage()` persist the **genuine** extraction verdict per domain. `Orchestrator` captures it *before* the turn cap can force it, so the `.json` export flags any domain that was force-advanced without genuine coverage (`all_requirements_covered` + per-domain `covered`).

**Extraction robustness fix (`src/agents/DomainAgent.php`):**
- `evaluate()` now parses the verdict via `parseVerdict()` — strips ` ```json ` fences, isolates the `{…}`, coerces the boolean. Fixes a bug where fenced model output made every domain read as NOT covered, so the agent looped to the 5-turn cap and force-completed domains while storing the raw JSON blob as the "detail." Bumped `max_tokens` (extraction 120→1000, questions 150→400; Compiler 2000→4000) since each user brings their own key/model.

**Session management:**
- `src/InterviewSession.php` `deleteSession()` (path-traversal guarded) + `public/delete_session.php` endpoint + delete UI in `public/partials/session_list.php` (hover "Delete this session" tooltip + browser confirm). Works from both the landing screen and the hamburger drawer.

### Notes
- **Decisions:** real LLM agent + deterministic fallback; **DB** as the answer source (per FP9 spec) with JSON fallback for "runs without MySQL"; Prompt 1 always leads with a persona; genuine coverage persisted so the JSON export surfaces force-advanced domains.
- The delete UI extends Port's `public/partials/session_list.php` partial — coordinated shared change.
- Verified: `tests/manifest_validation_test.php` (18 passed) and `session_recovery_test.php` (11 passed) — no regressions.

---

## FP10 — Week 12 | Due: Aug 3
**Deliverable:** Build `public/endpoint.php` — sequential chain controller (Extraction → gate → Routing or Compiler)

**Status:** `[ ] Not Started` / `[ ] In Progress` / `[x] Complete`

### What Was Added
- `public/endpoint.php` — the **async chain controller**. Runs the same pipeline as the old `post_message.php` (title-from-first-answer → write user turn → `Orchestrator::dispatch()` → write agent turn) but **returns JSON** instead of a redirect, so `app.js` applies the result in place with no full-page reload. `dispatch()` already returns `{done, response, domain_state, active_domain}` — Extraction (domain-agent evaluate) → gate (all 8 COVERED?) → Routing (next question) or Compiler (`ensureBuildPlan`) — so the endpoint is a thin JSON wrapper over the existing engine. On `done`, it reloads the session (the Compiler already ran + `writeDomainState` flipped `status` to `complete`) and `ob_start()`-captures `partials/build_plan.php` into a `plan_html` field the client injects.
- **Server-side concurrency guard (the milestone's point):** a per-session non-blocking exclusive lock — `flock($fp, LOCK_EX | LOCK_NB)` on `sessions/<id>.lock`. A second request for the same session while the first is mid-flight fails the lock and gets `409 {busy:true}` instead of executing the chain twice, so two requests can never write `domain_state` for one session simultaneously. `$id` is path-traversal-validated by `readSession()` before it touches the lock path.
- Kept the FP6 no-key **placeholder fallback** (`advancePlaceholder`) but refactored it to return the JSON payload pieces, so the app still demos end-to-end without an API key.
- `public/session.php` — form `action` now targets `endpoint.php`; Send button carries a Bootstrap spinner (`.is-loading` toggled by `app.js`); loads `public/js/app.js` after jQuery. **Full replacement** of the synchronous flow — no no-JS fallback (team decision with Port).

### Notes
- **Decisions (made with Port, in-session):** (1) endpoint returns server-rendered build-plan HTML for in-place injection rather than a reload — reuses FP9's copy/download logic untouched; (2) full replacement of `post_message.php` rather than progressive enhancement; (3) client lock **and** server `flock` guard, so both halves of the stress test ("single request" + "single execution") are enforced.
- `post_message.php` is now superseded by `endpoint.php` (left in place for reference; nothing links to it).
- Verified: `php -l` clean on all touched files; `tests/endpoint_lock_test.php` (5 passed) proves the guard; no regressions in `session_recovery_test.php` / `manifest_validation_test.php`.
- Demo walkthrough + DevTools stress procedure: `demoFP10.md`.

---

## FP-Final — Week 13 | Due: Aug 10
**Deliverable:** Build `validate_manifest.php` — Stage 1 validator: 5 prompt sections present, non-empty content, zero filler patterns

**Status:** `[ ] Not Started` / `[ ] In Progress` / `[ ] Complete`

### What Was Added
<!-- List files created/modified and what each does -->

### Notes
<!-- Blockers, decisions, deviations -->
