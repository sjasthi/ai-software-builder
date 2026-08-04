# FP-Final Demo — End-to-End System Validation & Success Story

**Milestone:** Prove the whole pipeline works as one system — a raw idea typed into
the chat comes out the other side as a populated 5-prompt build plan that an
external coding agent can act on with no further questions.

Two stages, because "the output is well-formed" and "the output actually works"
are different claims:

- **Stage 1 — Schema validation (controlled).** `validate_manifest.php` checks the
  compiled plan mechanically: 5 sections present, populated, no filler, no
  leftover placeholders. Passes at **zero violations**.
- **Stage 2 — External agent handoff (the success story).** Prompt 1 goes into
  Claude Code. Passes when it begins building **with zero structural clarifying
  questions** back to the user.

## What changed from FP10

FP10 made the interview async and safe under duplicate submissions. FP-Final adds
no new runtime behaviour at all — it adds **proof**. The only new executable is a
validator that reads the plan the pipeline already produces and reports whether it
meets the acceptance bar, with an exit code a grader or a CI step can act on.

## Files

| File | Owner | Role |
|------|-------|------|
| `validate_manifest.php` | Cox | Stage 1 validator — CLI harness over the FP9 rules |
| `src/ManifestValidator.php` | Port (FP9) | The rules themselves — reused, unchanged |
| `SUCCESS_STORY.md` | Port | Stage 2 — the real run and the Claude Code handoff |
| `demoFPFinal.md` | Port | This walkthrough |
| `tests/full_loop_test.php` | Cox | Automated full-loop integration test |

The validator deliberately holds **no rules of its own**. Everything it enforces
lives in `ManifestValidator::validate()`, which the FP9 test and the runtime also
call — so the script, the test suite, and the app can never disagree about what
"valid" means.

## Stage 1 — automated

```powershell
cd requirement-orchestrator
C:\xampp\php\php.exe validate_manifest.php <session-id>     # one session
C:\xampp\php\php.exe validate_manifest.php --all            # every complete session
C:\xampp\php\php.exe validate_manifest.php --file plan.json # a downloaded export
```

`--file` accepts all three formats the right panel downloads — **`.json`, `.md`,
and `.txt`** — because those are the artifacts a plan actually travels in. `-`
reads from stdin.

Expected on a healthy session:

```
Session 20260803182310-d7a2a4 — "Inventory tool for a small retail shop"
  [PASS] Prompt 1 — Project Initialization
  [PASS] Prompt 2 — Data Layer
  [PASS] Prompt 3 — Core Feature Build
  [PASS] Prompt 4 — UI Construction
  [PASS] Prompt 5 — Integration & Testing

  0 violations
```

**Exit codes** — Stage 1 has to be machine-checkable, not eyeballed:

| Code | Meaning |
|------|---------|
| `0` | zero violations — Stage 1 passed |
| `1` | violations found |
| `2` | usage error, session not found, or nothing to validate |

Code `2` on an empty `--all` is deliberate: a run that validated nothing must
never be mistaken for a pass.

**Warnings vs violations.** Any domain the Orchestrator's 5-turn cap
force-advanced prints as a warning and never changes the exit code:

```
  ! warning: 2 domain(s) force-advanced by the turn cap (audience_type, current_process)
    Structurally valid, but these answers were not genuinely covered.
```

The plan is still structurally valid — but the presentation should be honest
about which answers the user actually gave. Read straight from the genuine
per-domain verdicts FP9 persists (`domain_coverage`), which the turn cap cannot
overwrite.

**Negative control.** Hand it a plan with filler, placeholders, an empty section
and a stub, and it must reject all four:

```
  [FAIL] Prompt 1 — Project Initialization
         → begins with conversational filler
  [FAIL] Prompt 2 — Data Layer
         → contains an unfilled placeholder
  [FAIL] Prompt 3 — Core Feature Build
         → missing or empty
  [FAIL] Prompt 4 — UI Construction
         → too short to be domain-specific content
  [PASS] Prompt 5 — Integration & Testing

  4 violations
```

A validator that has never been seen to fail proves nothing — run this before the
passing case.

## Stage 2 — the success story

> **Pending the live run.** See `SUCCESS_STORY.md` for the full write-up: the raw
> idea, the 8 extracted requirements, the 5 generated prompts, the Stage 1 output,
> and what Claude Code did with Prompt 1.

Procedure:

1. Real API key, and a **fresh idea** — not the Shopify test fixture. The claim
   being demonstrated is that the system works for *any* software idea, so using
   the fixture would prove nothing.
2. Run the interview to completion, all 8 badges green.
3. Download the `.json` export from the right panel.
4. Run Stage 1 against the session — record the output verbatim.
5. Copy **Prompt 1** and paste it, unedited, into Claude Code in an empty directory.
6. Record its first response: does it start building, or does it ask a structural
   question first?

If it does ask something structural, record it and name the domain that
under-specified it. That is a more useful result than a clean pass — it points at
exactly which agent's coverage bar is too low.

## Live presentation walkthrough

Roughly 6–8 minutes.

| # | Beat | Say |
|---|------|-----|
| 1 | Open the app, split-pane visible | 8 domains, all `○`. The right panel is the interview's progress, live. |
| 2 | Type the opening idea | *"I want to build an inventory management tool for my small business."* |
| 3 | First badge flips `○ → ✓` | PainPointsAgent judged its own bar met — specific problem, consequence, one concrete detail. |
| 4 | Next agent asks a pointed question | Each domain has its own agent and its own definition of "covered." That is why the question is specific rather than generic. |
| 5 | Answer through to 8/8 | Badges flip in real time. No page reloads — one AJAX request per answer. |
| 6 | Right panel becomes the build plan | The Compiler Agent fired the moment the gate saw 8/8. Five prompts, copy buttons, three download formats. |
| 7 | Run Stage 1 in a terminal | `validate_manifest.php <id>` → **0 violations**, exit 0. |
| 8 | Paste Prompt 1 into Claude Code | It starts building. No structural questions. **That is the success story.** |

Close on the point the whole project rests on: *the system does not build the
software — it produces the plan that builds the software*, and different answers
produce an entirely different plan.

## If the key or the network fails mid-demo

```powershell
$env:LLM_MOCK = "1"
```

Set it, restart the server, and the scripted offline LLM drives the full flow —
interview, coverage, and a populated build plan — at no cost and with no network.
Steps 1–7 run identically. Only step 8 needs a live Claude Code session.

Have a **completed session already saved** before presenting. If the live
interview stalls, open it from the Previous Sessions screen and jump to step 6.

## Full verification checklist

```powershell
cd requirement-orchestrator
C:\xampp\php\php.exe tests\schema_migration_test.php      # 12 passed (needs MySQL)
C:\xampp\php\php.exe tests\session_recovery_test.php      # 11 passed
C:\xampp\php\php.exe tests\manifest_validation_test.php   # 18 passed
C:\xampp\php\php.exe tests\endpoint_lock_test.php         #  5 passed
C:\xampp\php\php.exe tests\endpoint_dedupe_test.php       # 14 passed
C:\xampp\php\php.exe tests\full_loop_test.php             # Cox — FP-Final
C:\xampp\php\php.exe validate_manifest.php --all          # exit 0
```

Everything except `schema_migration_test.php` runs offline with no key and no
database.

## Notes

- **Why the validator is CLI-only.** It prints users' requirement content
  verbatim, so it refuses to run under a web SAPI even if the project sits in
  `htdocs`.
- **Four plan shapes.** A plan is stored one way and downloaded three others, so
  the validator normalises all of them before checking: the `.json` export
  envelope, the assoc `prompt_1…prompt_5` the Compiler returns, the indexed array
  `writeBuildPlan()` persists, and the single rendered string sessions from before
  FP9 carry. Self-testing turned up a real July 21 session in the last shape.
- **Stage 1 is not wired into the runtime.** It reports; it never blocks plan
  generation. `ManifestGenerator::generate()` always returns 5 populated keys via
  its template fallback, so a validation failure means something upstream is
  wrong — which is exactly what we want to see rather than silently repair.
