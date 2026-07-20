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
- `requirement-orchestrator/src/RequirementParser.php` — `extract()` builds the system prompt with live domain state + domain definitions injected, calls Claude (`claude-opus-4-8`, 512 max tokens) via PHP's built-in curl (no Composer/SDK required), and routes the raw text through `parseAndValidate()`. Validation: strips markdown fencing, JSON-decodes, confirms all 8 domain keys present, and enforces COVERED-sticky invariant (an already-covered domain cannot regress). `allCovered()` is the programmatic gate — returns true when all 8 domains are COVERED, signaling `endpoint.php` to skip routing and call ManifestGenerator instead.
- `requirement-orchestrator/tests/fp7_verification.php` — combined FP7 verification proof (Cox + Port). Cox section: live LLM call with the Shopify sentence confirms `data_sources`, `data_access`, and `interaction_model` → COVERED in one pass, 5 domains remain OPEN, gate does not fire. Port section: programmatic gate logic (no LLM). **Result: 19 passed, 0 failed.**

### Notes
- Uses PHP's built-in curl — no Composer or external SDK needed. Requires `ANTHROPIC_API_KEY` in the environment (or `config/local.php`).
- Domain definitions added to system prompt so the LLM correctly maps "every morning" → `interaction_model` (scheduled automation).
- No streaming needed — extraction output is always < 512 tokens; latency is negligible.
- Run: `C:\xampp\php\php.exe tests\fp7_verification.php` from the `requirement-orchestrator/` folder.

---

## FP8 — Week 10 | Due: Jul 20
**Deliverable:** Build `AgentEngine.php` — construct routing prompt, inject transcript + domain state, call LLM API; implement in-scope routing branch (LLM generates domain-targeting question for next OPEN domain)

**Status:** `[ ] Not Started` / `[ ] In Progress` / `[x] Complete`

> _Built by Port this week while covering both FP8 parts (Cox made up FP7 last week)._

### What Was Added
- `src/AgentEngine.php` — Routing Agent, **in-scope branch**. `nextQuestion()` injects the
  recent transcript + the next OPEN domain's purpose into a generation prompt and returns a
  single question **tailored to the user's actual project** (references details they already
  gave) instead of the static `InterviewSession::OPENING[]` text used through FP7. Falls back
  to the static opening question if no model is available.
- `src/LlmClient.php` — provider abstraction all agents share: `LlmClient` interface, two live
  implementations — `AnthropicClient` (Claude) and `OpenAIClient` (ChatGPT), both raw-curl, no
  SDK — plus a free offline `ScriptedLlm` for demos, and `LlmClientFactory::forTask()` which
  resolves the provider + per-task model from config. Switching Claude↔ChatGPT is one line
  (`default_provider`); each provider defaults to its low-cost tier. This is the multi-provider
  capability the professor asked for — genuinely two providers, not just a hook.
- `src/RequirementParser.php` — `extract()` now calls the LLM through `LlmClient` instead of
  inline curl. Public API and system prompt are unchanged, so FP7 verification stays green.
- `public/post_message.php` — in-scope path wired: `extract → gate →` on not-yet-complete,
  ask `AgentEngine::nextQuestion()`; on all-8-covered, announce the build plan.
- `config/local.php.example` — template documenting the new multi-provider config shape.

### Notes
- Requires an Anthropic key in `config/local.php` (or `ANTHROPIC_API_KEY`) to generate live
  questions; without one the interview gracefully falls back to the static openings.
- Model: to keep API usage down, each provider defaults to its fast, low-cost tier for **all**
  tasks — Claude `claude-haiku-4-5-20251001` (~5x cheaper than Opus 4.8), OpenAI `gpt-4o-mini`
  ($0.15/$0.60 per 1M). Overridable per task via `task_models` in `config/local.php` (e.g. bump
  `question_generation` to `claude-opus-4-8` or `gpt-4.1-mini` for richer questions).
- **Free offline demo mode** (`ScriptedLlm`, enabled with `'mock' => true` / env `LLM_MOCK=1`):
  shows the dynamic behavior in the browser with no key and no cost; swaps to a real key with
  zero code change.
- **Depth tuning:** extraction sets a HIGH bar for COVERED (concrete, buildable detail; prefer
  OPEN when unsure) and question generation actively probes for specifics — so the interview
  pulls *more* detail and finalizes only when each domain is solidly answered. The mock mirrors
  this: it crosses off one domain per substantive answer and won't advance on a vague reply.
- Demo walkthrough for both FP8 branches: `demoFP8.md`.

---

## FP9 — Week 11 | Due: Jul 27
**Deliverable:** Build `ManifestGenerator.php` — read finalized 8-domain data from DB, populate 5-prompt build plan template; implement placeholder substitution (replace all template vars with user's verified answers, wire output into right panel transition)

**Status:** `[ ] Not Started` / `[ ] In Progress` / `[ ] Complete`

### What Was Added
<!-- List files created/modified and what each does -->

### Notes
<!-- Blockers, decisions, deviations -->

---

## FP10 — Week 12 | Due: Aug 3
**Deliverable:** Build `public/endpoint.php` — sequential chain controller (Extraction → gate → Routing or Compiler)

**Status:** `[ ] Not Started` / `[ ] In Progress` / `[ ] Complete`

### What Was Added
<!-- List files created/modified and what each does -->

### Notes
<!-- Blockers, decisions, deviations -->

---

## FP-Final — Week 13 | Due: Aug 10
**Deliverable:** Build `validate_manifest.php` — Stage 1 validator: 5 prompt sections present, non-empty content, zero filler patterns

**Status:** `[ ] Not Started` / `[ ] In Progress` / `[ ] Complete`

### What Was Added
<!-- List files created/modified and what each does -->

### Notes
<!-- Blockers, decisions, deviations -->
