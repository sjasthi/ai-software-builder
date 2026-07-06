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
  - `writeDomainState()` — merges updated coverage for the 8 known domains, re-derives `status` (`complete` when all 8 `COVERED`), ignores unknown keys.
  - `readDomainState()` — returns the 8-domain coverage map (blank all-`OPEN` map if absent).
  - `atomicSave()` — crash-safe write: encode to a `.tmp` file, then `rename()` over the live file (atomic replace on POSIX **and** Windows), so an interrupted write can never corrupt a session.
- `tests/session_recovery_test.php` — atomic transaction recovery test. **Result: 11 passed, 0 failed.**

### Notes
- Storage is **JSON files** (`sessions/<id>.json`), per the professor's direction — open-source-friendly, no auth needed. This supersedes the Big Picture Plan's MySQL persistence for the runtime app (the MySQL schema + FP4 migration test still stand for the DB deliverable).
- Recovery proof simulates a mid-write crash by dropping a truncated `.tmp` file: the live session stays valid JSON and fully recoverable. Run: `C:\xampp\php\php.exe tests\session_recovery_test.php`.
- `sessions/` runtime data is now gitignored so it doesn't pollute the repo.

---

## FP7 — Week 9 | Due: Jul 13
**Deliverable:** Implement programmatic gate check (all 8 `COVERED` → trigger Compiler); run Shopify API verification proof

**Status:** `[ ] Not Started` / `[x] In Progress` / `[ ] Complete`  _(started early — FP6/FP7 merged to leave testing time before turn-in)_

### What Was Added
- `src/RequirementParser.php` — Port's `gate()`: pure-logic programmatic gate check. Given the 8-domain state it returns `all_covered`, `covered_count`, `open_domains`, and `next_action` (`COMPILE` when all 8 `COVERED`, else `INTERVIEW`). Defensive: only the 8 known domains count, and any non-`COVERED` value can never trip the gate early. `gateForSession()` runs it against a saved session via Port's FP6 store.
- `tests/gate_check_test.php` — Shopify API verification proof. **Result: 12 passed, 0 failed.** Confirms the Shopify sentence (data_sources + data_access + interaction_model) leaves 5 domains OPEN and does **not** trigger the Compiler; all-8-`COVERED` flips `next_action` to `COMPILE`.

### Notes
- **Cox seam:** `RequirementParser::extract()` (the LLM call in JSON mode) is stubbed with a documented contract and throws until built — Port's gate is independent of it, so the verification proof runs today by injecting the extraction result into the FP6 session store (exactly the state `extract()` will write). Cox drops his LLM logic into `extract()` with no change to the gate.
- Run: `C:\xampp\php\php.exe tests\gate_check_test.php`.
- Remaining for FP7 done: wire `gate()` into the runtime controller after each extraction pass (depends on Cox's `extract()`), and hand `COMPILE` off to the Compiler Agent (later FP).

---

## FP8 — Week 10 | Due: Jul 20
**Deliverable:** Implement out-of-scope routing branch (drift redirection, no domain advance); run boundary deviation test

**Status:** `[ ] Not Started` / `[ ] In Progress` / `[ ] Complete`

### What Was Added
<!-- List files created/modified and what each does -->

### Notes
<!-- Blockers, decisions, deviations -->

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
