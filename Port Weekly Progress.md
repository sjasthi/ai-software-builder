# Port — Weekly Progress Log

> _Schedule below matches the team's current plan (NEW2Plan): UI is pulled up to
> FP5 (next week); persistence and the agents follow; the async pipeline + the
> race-condition/lock test land at FP10. Team: Cox + Port._

---

## FP4 — Week 6 | Due: Jun 22
**Deliverable:** Write schema migration test — verify tables, UNIQUE token constraint, JSON round-trip in `domain_state`

**Status:** `[ ] Not Started` / `[ ] In Progress` / `[x] Complete`

### What Was Added
- `requirement-orchestrator/tests/schema_migration_test.php` — schema migration test (PHP/PDO). Verifies:
  1. all three core tables exist (`sessions`, `conversation_log`, `domain_state`),
  2. the UNIQUE `session_token` constraint rejects a duplicate insert,
  3. an 8-domain JSON object round-trips through `domain_state` with zero data loss.
- Retrieves the connection via Cox's `config/database.php` (`getDB()`) and applies `config/schema.sql` against an isolated `requirement_orchestrator_test` database (real data never touched). **Result: 12 passed, 0 failed.**

### Notes
- Verified end-to-end against the real team files (Cox's `getDB()` + `config/schema.sql`) — integration is demonstrated, not mocked.
- Test is schema-aware: children link by numeric `session_id` (FK auto-resolved), token is `CHAR(64)` with a length CHECK, JSON lives in `domain_json`.
- Committed and pushed to `main`. Local DB credentials (`config/local.php`) kept gitignored — not committed.
- Run locally: `C:\xampp\php\php.exe tests\schema_migration_test.php` from the `requirement-orchestrator/` folder.

---

## FP5 — Week 7 | Due: Jun 29
**Deliverable:** Build right domain matrix panel — 8 `data-domain` badges + progress bar; verify responsive at 1440/768/375px

**Status:** `[ ] Not Started` / `[ ] In Progress` / `[ ] Complete`

### What Was Added
<!-- List files created/modified and what each does -->

### Notes
<!-- Blockers, decisions, deviations -->

---

## FP6 — Week 8 | Due: Jul 6
**Deliverable:** Implement `writeDomainState()` and `readDomainState()`; run atomic transaction recovery test

**Status:** `[ ] Not Started` / `[ ] In Progress` / `[x] Complete`

### What Was Added
- `src/InterviewSession.php` — Port's persistence methods:
  - `writeDomainState()` — merges updated coverage into the session; only the 8 known domain keys are accepted (unknown keys ignored), re-derives `status` to `complete` when all 8 are `COVERED`, then atomically saves.
  - `readDomainState()` — returns the 8-domain coverage map for any session (blank all-`OPEN` map if the session is absent).
  - `atomicSave()` — crash-safe write shared by both methods: encode to `<id>.json.tmp`, then `rename()` over the live file. `rename()` is atomic on Windows (`MoveFileEx` + `REPLACE_EXISTING`) and POSIX, so an interrupted write leaves the old file fully intact — never a half-written session.
- `tests/session_recovery_test.php` — atomic transaction recovery test. **Result: 11 passed, 0 failed.**

### Notes
- Storage is **JSON files** (`sessions/<id>.json`), per the professor's direction — open-source-friendly, no auth needed. This supersedes the Big Picture Plan's MySQL persistence for the runtime app (the MySQL schema + FP4 migration test still stand as the separate DB deliverable).
- Recovery proof simulates a mid-write crash by dropping a truncated `.tmp` next to the live file: the live session stays valid JSON and fully recoverable, and `status` correctly flips to `complete` once all 8 domains are `COVERED`. A path-traversal guard (`safeId()` whitelist regex) is shared by every method that takes a session id and is exercised by the test.
- `sessions/` runtime data is gitignored so it doesn't pollute the repo.
- Run: `C:\xampp\php\php.exe tests\session_recovery_test.php` from the `requirement-orchestrator/` folder.

---

## FP7 — Week 9 | Due: Jul 13
**Deliverable:** Implement programmatic gate check (all 8 `COVERED` → trigger Compiler); run Shopify API verification proof

**Status:** `[ ] Not Started` / `[ ] In Progress` / `[x] Complete`

### What Was Added
- `src/RequirementParser.php` — Port's `gate()`: pure-logic programmatic gate check. Given the 8-domain state it returns `all_covered`, `covered_count`, `open_domains`, and `next_action` (`COMPILE` when all 8 `COVERED`, else `INTERVIEW`). Defensive: only the 8 known domains count, and any non-`COVERED` value can never trip the gate early. `gateForSession()` runs it against a saved session via Port's FP6 store.
- `tests/fp7_verification.php` — combined FP7 verification proof (Cox + Port). Port section covers gate logic: Shopify partial state (3 covered) → INTERVIEW, all-8-covered → COMPILE, edge cases (empty state, junk values, unknown session → null). **Result: 19 passed, 0 failed.**

### Notes
- **Cox seam:** `RequirementParser::extract()` (the LLM call in JSON mode) is stubbed with a documented contract and throws until built — Port's gate is independent of it, so the verification proof runs today by injecting the extraction result into the FP6 session store (exactly the state `extract()` will write). Cox drops his LLM logic into `extract()` with no change to the gate.
- Run: `C:\xampp\php\php.exe tests\gate_check_test.php`.
- Remaining for FP7 done: wire `gate()` into the runtime controller after each extraction pass (depends on Cox's `extract()`), and hand `COMPILE` off to the Compiler Agent (later FP).
- **Known state (not a bug):** when the gate flips a session to complete, the right panel shows `public/partials/build_plan.php` — a working **placeholder**. It renders the 5 build-plan section headers (Project Initialization, Data Layer, Core Feature Build, UI Construction, Integration & Testing) with filler text and a disabled copy button. The actual prompt CONTENT is produced by the Compiler Agent (`ManifestGenerator.php`), which is a later deliverable and isn't built yet — so `$session['build_plan']` is `null` and each prompt reads "Generated by the Compiler Agent (FP9)." Expected during demo/turn-in: you can review the plan's *structure* but not its populated prompts until the Compiler Agent lands.

---

## FP8 — Week 10 | Due: Jul 20
**Deliverable:** Implement out-of-scope routing branch (drift redirection, no domain advance); run boundary deviation test

**Status:** `[ ] Not Started` / `[ ] In Progress` / `[x] Complete`

> _Port covered both FP8 parts this week (Cox made up FP7 last week); the in-scope
> question-generation half is logged in `Cox Weekly Progress.md`._

### What Was Added
- `src/AgentEngine.php` — Routing Agent, **out-of-scope branch**. `route()` is the pure
  decision function: a scope label in, `{advance, action}` out — anything that isn't an
  explicit `OUT_OF_SCOPE` is treated as in-scope so a mis-classification never traps the
  user. `classifyScope()` (LLM) labels the latest turn `IN_SCOPE`/`OUT_OF_SCOPE` and fails
  toward `IN_SCOPE`. `redirect()` (LLM, with a template fallback) steers a drifted user back
  to the current domain **without touching `domain_state`** — the progress bar cannot move
  on off-topic input. `nextOpenDomain()` is the shared pure helper.
- `public/post_message.php` — wired the branch into the live chain: `classifyScope → route`;
  on no-advance it appends only a redirect turn (no extraction, no `writeDomainState`).
- `tests/boundary_deviation_test.php` — boundary deviation proof. No live LLM (routing is
  pure; LLM calls take an injected fake client). Proves: drift routes to no-advance and
  leaves `domain_state` **byte-for-byte unchanged**, in-scope is allowed to advance, junk
  labels never trap the user, and both LLM branches fall back to templates with no key.
  **Result: 16 passed, 0 failed.**

### Notes
- Same seam split as FP7: the **pure decision logic** (`route`, `nextOpenDomain`) is
  independent of the LLM, so the proof runs today by injecting scope labels / a fake client —
  no key required. The gate refactor kept `RequirementParser`'s public API identical, so
  `gate_check_test.php` still passes **12/12** (verified).
- Fails safe: a missing key or any agent error drops the controller to the FP6 placeholder
  (mechanical advance + static question), so the app always stays demoable.
- Run: `C:\xampp\php\php.exe tests\boundary_deviation_test.php` from `requirement-orchestrator/`.
  Full walkthrough in `demoFP8.md`.

---

## FP9 — Week 11 | Due: Jul 27
**Deliverable:** Run filler-pattern regex scan on output; validate 5 labeled prompt sections with non-empty domain-specific content

**Status:** `[ ] Not Started` / `[ ] In Progress` / `[ ] Complete`

### What Was Added
<!-- List files created/modified and what each does -->

### Notes
<!-- Blockers, decisions, deviations -->

---

## FP10 — Week 12 | Due: Aug 3
**Deliverable:** Run race-condition stress test — confirm single network request per submit in DevTools; verify single server execution per submit

**Status:** `[ ] Not Started` / `[ ] In Progress` / `[ ] Complete`

### What Was Added
<!-- List files created/modified and what each does -->

### Notes
<!-- Blockers, decisions, deviations -->

---

## FP-Final — Week 13 | Due: Aug 10
**Deliverable:** Run Stage 2 success story — paste Prompt 1 into Claude Code, document result; prepare final presentation demo walkthrough

**Status:** `[ ] Not Started` / `[ ] In Progress` / `[ ] Complete`

### What Was Added
<!-- List files created/modified and what each does -->

### Notes
<!-- Blockers, decisions, deviations -->
