# Success Story — Requirement Orchestrator

**Course:** ICS 499 Capstone, Summer 2026
**Team:** Team Rocket — Cox and Kenan Port
**Repository:** <https://github.com/sjasthi/ai-software-builder>
**Span:** June 8 – August 3, 2026 (FP1 → FP10, with FP-Final on August 10)

---

## What we set out to build

Most people who want software cannot write a specification for it. They can
describe a problem — *"my inventory counts are wrong and it's costing me money"* —
but the gap between that sentence and something a coding AI can act on is the
entire job.

The Requirement Orchestrator closes that gap. It conducts an adaptive interview
across **eight requirement domains**, and when all eight are covered it compiles
them into a sequenced **five-prompt build plan** the user pastes into Claude Code,
ChatGPT, or Gemini.

| The 8 inputs | The 5 outputs |
|---|---|
| Pain Points | Prompt 1 — Project Initialization |
| Data Sources | Prompt 2 — Data Layer |
| Data Access | Prompt 3 — Core Feature Build |
| End Result | Prompt 4 — UI Construction |
| Stakeholders | Prompt 5 — Integration & Testing |
| Audience Type | |
| Current Process | |
| Interaction Model | |

**The system is the product. It does not build software. It produces the plan
that builds the software.** That distinction drove every design decision we made,
and it is the reason our final proof had to be an external artifact rather than
a screenshot of our own app.

---

## The twelve-week plan, and what actually happened

The plan (`weekly_deliverable_plan.xlsx`) scheduled Weeks 6–13 as FP4 through
FP-Final; FP1–FP3 came before it as the CRM exploration, the requirements
elaboration, and the Big Picture Plan.

| Wk | Iter | Milestone | Status |
|---|---|---|---|
| 6 | FP4 | Database scaffolding — schema + migration test | **Delivered.** 12/12 passing |
| 7 | FP5 | Workspace interface — split-pane, 8 badges, progress bar | **Delivered.** Verified at 1440/768/375px |
| 8 | FP6 | Snapshot Agent — session persistence | **Delivered.** 11/11, crash-safe |
| 9 | FP7 | Extraction Agent + programmatic gate | **Delivered.** 19/19 at the time |
| 10 | FP8 | Routing Agent + adaptive question generation | **Delivered.** 16/16, live multi-provider LLM |
| 11 | FP9 | Compiler Agent + build plan validation | **Delivered.** 18/18 |
| 12 | FP10 | Async pipeline + chain execution controller | **Delivered.** 5/5 lock, 14/14 dedupe |
| 13 | FP-Final | End-to-end validation + success story | **Stage 1 and Stage 2 both done early.** Presentation remains |

**Every milestone on the sheet was hit, and every one was demoed live in the
breakout room.** Two honest qualifications:

- FP-Final is dated **August 10** and this is being written on **August 3**. The
  Stage 1 validator, the full-loop test, and the Stage 2 handoff run are all
  finished; what remains is the final presentation itself.
- The plan named modules that no longer exist under those names.
  `RequirementParser.php` and `AgentEngine.php` were both built, demoed, and then
  deleted when the Orchestrator-Workers refactor replaced them with eight
  per-domain agent classes. The *capabilities* shipped; the filenames didn't
  survive. More on that under "what we'd do differently."

### Where the plan bent

Four deviations, all deliberate:

1. **We lost our third member.** The original sheet had three columns — Cox,
   Jaffer, Port. Everything from FP4 onward was re-cut for two people. That is
   also why the UI was pulled forward from FP8 to FP5: with fewer hands we went
   demo-first, so there was always something visible to show the professor.
2. **MySQL gave way to JSON files at runtime**, per the professor's direction —
   open-source-friendly, no auth or DB setup for anyone cloning the repo. The
   MySQL schema and the FP4 migration test still stand as the database
   deliverable, and `MySQLPersister.php` writes through to it when a database is
   present.
3. **The Compiler moved earlier and the pipeline moved later** (FP9/FP10 swapped
   against the original ordering), because a populated build plan is the thing
   worth demoing and async plumbing is not.
4. **The Routing Agent's scope classifier was retired.** `classifyScope()` and
   `redirect()` cost an extra LLM call per turn; the Orchestrator's turn cap
   achieves the same safety property for free.

---

## The proof, part one: the interview that mattered

On August 3 we ran the system against a fresh idea — deliberately not the Shopify
fixture we had been testing with, because using the fixture would prove nothing.
The idea: a two-location auto parts store whose inventory counts never agree.

**Ten prompts covered eight domains.** Six domains closed on the first answer.
Two took a second question, and both for the same reason — the first answer
wasn't detailed enough:

| Domain | First answer | What happened |
|---|---|---|
| Data Sources | *"Honestly, I'm not sure. I've never looked at any of this from the technical side… I couldn't tell you what any of it's actually called."* | Agent asked for the export format specifically → got `.xlsx` on a share, 3,400 rows, plus the Square catalog |
| Data Access | *"Honestly I don't know how that part would work. I just need the number on the screen to be right when somebody asks me for it."* | Agent asked whether the system writes back → got "processed automatically, approved by a human" |

**This is the system working, not failing.** A fixed questionnaire would have
taken "I'm not sure" as an answer and compiled a plan around a hole. The
domain agent held its own bar, asked a narrower question, and got something
buildable on the second pass. The follow-up loop *is* the product.

One result we are not going to smooth over: the session's genuine per-domain
verdict recorded **`data_access: false`** even after the second answer. The
badge said 8/8; the underlying JSON disagreed. The plan was compiled anyway,
and it came out fine — because the missing scheduling detail arrived later,
under **Interaction Model**, when the user volunteered "every 15 minutes during
business hours, 7am to 7pm… Ray's side is on-demand." That detail is what landed
in Prompt 2. The Compiler recovered from a weak domain by drawing on a strong
one, which is a genuinely useful property we did not design on purpose.

---

## The proof, part two: handing the plan to a stranger

Ten answers in, eight domains covered, and the Orchestrator handed back **five
prompts** — the build plan. That plan is only worth something if an agent that
was never part of this project can act on it, so it went to Claude Code in an
empty directory.

**Result: zero structural questions about what to build.** Not one. All eight
domains were specific enough that the agent never came back asking what the
software was supposed to do.

It produced `inventory-demo` — a working system, verified again while writing
this:

| | |
|---|---|
| Python modules | 20 (~4,357 lines) |
| Automated tests | **282 passing**, offline, no Square account, no SMTP |
| Delivered | Excel + Square connectors, reconciliation with confidence flags, price/value discrepancy ranking, 07:30 daily digest, staff web UI, physical-count ledger |

**But it did have to stop and ask three blocking questions before it could
scaffold anything:** which language, where should the project live, and are there
real API credentials. None of the eight domains covers any of those. Our
interview establishes what the software must *do* and never what it is *built
with* — while Prompt 1 already instructs the agent to "set up version control and
project structure," an action that presupposes exactly the answers we never
collect.

That gap is the single most valuable thing this project found, and it is fixable
in one move: a ninth domain, **Technology Constraints** (language, runtime,
hosting, existing codebase), or a stack declaration carried in Prompt 1.

The five prompts the Orchestrator produced, where each landed in the build, and
what the agent refused to fake are all in **Appendix A**. The artifact is at
`ICS499\inventory-demo`.

---

## What was harder than we thought

**Making the interview stop.** We assumed "is this domain covered?" was a
lookup. It is a judgment call, and an LLM asked to judge its own work will
either accept anything or loop forever. It took a per-domain definition of
"covered," a per-domain extraction prompt, *and* a hard turn cap
(`MAX_DOMAIN_TURNS = 5`) to force-advance a domain the user genuinely cannot
answer — plus a separate record of the honest verdict so a force-advance is
never silently reported as success.

**Getting an LLM to be reliably boring.** A prompt that opens "Sure! Here's your
project initialization prompt:" is useless to a coding agent, and a leftover
`[placeholder]` is worse than useless. Nothing about that is intelligence — it is
a regex scan, a placeholder check, and a minimum content length, run against
both the LLM path and the deterministic template fallback.

**Concurrency, in a student web app.** We did not expect to care. Then we
realized a double-clicked Send button fires two chain executions that both write
`domain_state` for the same session, and coverage corrupts. It needed two layers:
a client-side `inFlight` lock for one network request, and a server-side
`flock(LOCK_EX | LOCK_NB)` returning `409 {busy:true}` for one server execution.

**Building against a seam that doesn't exist yet.** At FP7 the gate check
depended on Cox's `extract()`, which wasn't written. Waiting would have cost a
week. Stubbing it with a documented contract and injecting the exact state
`extract()` would eventually produce meant the gate was fully tested before its
dependency existed — and when their LLM code landed, nothing about the gate
changed.

**Crash-safe writes.** "Save the session" turned out to be `.tmp` + atomic
`rename()`, a path-traversal whitelist on every method taking a session id, and
a test that simulates a mid-write crash by dropping a truncated `.tmp` next to a
live file.

**Losing a third of the team.** Re-cutting eight weeks of assignments for two
people, mid-semester, was the hardest scheduling problem of the project.

## What was easier than we thought

**Supporting multiple AI providers.** We braced for this and it was one
abstraction. Every call goes through `LlmClient.php`; switching between Claude
and OpenAI is a config line, and per-task model assignment (Opus for extraction,
Haiku for question generation) is config too. Nobody had to pay for everyone
else's key.

**The interface.** Bootstrap 5 split-pane, eight badges carrying `data-domain`
attributes, one progress bar. Because the matrix was built as a self-contained
partial with its own styles and a global `setDomainState()` helper, integration
into the main page was literally **one `include` line** — see
`domain_matrix_handoff\README_FOR_COX.md`. Two people edited two files and never
had a merge conflict over the UI.

**Dropping MySQL for the runtime.** We expected to lose something. We gained:
no setup for anyone cloning the repo, tests that run offline with no database,
and a session format we can read in a text editor while debugging.

**Mock mode.** `$env:LLM_MOCK = "1"` drives the entire flow — interview,
coverage, populated build plan — with no key, no network, and no cost. It took
an afternoon and it saved every demo we gave.

**The Stage 2 build itself.** The part we were most nervous about was the part
that went cleanest. Five prompts, 282 tests, zero requirement clarifications.

## What we learned

1. **Specificity is the whole product.** The two answers that needed a follow-up
   are better evidence than the six that didn't. Anyone can accept a vague answer;
   the value is in refusing one.
2. **Requirements and environment are different things**, and conflating them is
   what left three questions on the table. Eight domains of *what* do not imply
   one line of *with what*.
3. **A test that cannot fail proves nothing.** Every validator we wrote got a
   negative control — a deliberately broken plan the validator must reject —
   before we trusted a passing run.
4. **Some steps cannot be delegated to an agent.** Prompt 5 says "confirm
   functionality with designated stakeholders." No agent can obtain human
   sign-off. The honest output was a UAT script with empty result boxes and a
   signature block, and that is arguably the most useful artifact of the run.
5. **Sequenced prompts find bugs that one big specification would not.** Two
   integration defects surfaced only because Prompt 5 ran after Prompt 4 — a
   counted item never reached the daily email, and a scheduling assumption about
   the 07:30 digest was flat wrong.
6. **The deployed copy is not the repository.** Our live sessions accumulated
   under `C:\xampp\htdocs\requirement-orchestrator`, which now lags the repo — it
   has no `validate_manifest.php` and is missing two test suites. The evidence
   from our most important run lives in the copy that isn't version controlled.

## What we'd do differently

1. **Add the ninth domain.** Technology Constraints — language, runtime, hosting,
   existing codebase. It is one agent following a pattern we have built eight
   times, and it closes the only gap Stage 2 found.
2. **Run the app *from* the repository.** Symlink `htdocs` at the working tree
   instead of keeping a second copy. Every session we generated would then be
   evidence under version control instead of a folder that drifted.
3. **Write the output validator in week one.** `ManifestValidator` arrived at
   FP9. Defining what "a good prompt" means *before* generating any would have
   shaped the Compiler's prompts rather than grading them afterward.
4. **Plan behaviors, not filenames.** The sheet promised `RequirementParser.php`
   and `AgentEngine.php`; both were deleted in refactors that made the system
   better. A plan row should name a capability and its acceptance test, so that
   improving the design doesn't look like missing a deliverable.
5. **Surface the honest verdict in the UI.** The badge showed 8/8 while the JSON
   recorded `data_access: false`. The force-advance warning exists in the
   validator; it should exist on the screen, where the user can still fix it.
6. **Build one canonical end-to-end fixture on day one** and re-run it every
   week. We had six abandoned partial sessions and one good one; a golden session
   from FP4 onward would have caught regressions we found by hand.
7. **Keep the progress log current.** The FP5 and FP-Final entries in
   `Port Weekly Progress.md` are still blank templates even though both were
   delivered. Reconstructing a semester from git history is harder than spending
   five minutes a week.

---

## Verification

Everything below was re-run on 2026-08-03 while writing this document.

```powershell
# The Orchestrator — offline, no key, no database
cd requirement-orchestrator
C:\xampp\php\php.exe tests\session_recovery_test.php     # 11 passed, 0 failed
C:\xampp\php\php.exe tests\manifest_validation_test.php  # 18 passed, 0 failed
C:\xampp\php\php.exe tests\endpoint_lock_test.php        #  5 passed, 0 failed
C:\xampp\php\php.exe tests\endpoint_dedupe_test.php      # 14 passed, 0 failed

# Requires MySQL (not re-run today; 12/12 at FP4)
C:\xampp\php\php.exe tests\schema_migration_test.php

# The artifact the plan produced
cd ..\..\inventory-demo
python -m pytest tests -q                                # 282 passed
python run.py serve                                      # http://127.0.0.1:5000
```

**48 orchestrator assertions verified offline today, plus 282 in the artifact.**

Two proofs cited in the weekly logs are no longer in the tree:
`fp7_verification.php` (19 passed) and `boundary_deviation_test.php` (16 passed)
were removed with the modules they covered. They were demoed and signed off at
the time; they are not reproducible today.

---

## The claim, and whether we met it

> The success story is demonstrated when Claude Code begins constructing a
> working prototype with **zero structural clarifying questions** directed back
> at the user.

**We met it.** Zero structural questions about the requirements, and a 282-test
working system on the other side.

We also found the boundary of it: three questions about the *build environment*
that our eight domains never claimed to cover and never should have been asked to.
The system does what it says. It now has a documented, one-domain-wide gap and a
specific fix — which is a better place to finish a capstone than a clean
demo with nothing learned.

---

# Appendix A — Stage 2 run report

**Agent:** Claude Code (Opus 5), Windows 11, Python 3.13.5
**Run date:** 2026-08-03 · **Artifact:** `ICS499\inventory-demo`

## A.1 What the plan specified without being asked

The agent never had to ask about any of the following, because the plan already
carried them. This is the eight domains doing their job:

| Domain | Supplied by the plan | Where it landed in the build |
|---|---|---|
| Pain Points | oversells, duplicate inventory, failed orders | the whole premise; `reconcile.py` policy |
| Data Sources | `.xlsx` on a share, Square POS API | `connectors/excel.py`, `connectors/square.py` |
| Data Access | on-demand upload + API read every 15 min | `import-excel`, `poll-square`, `scheduler.py` |
| End Result | reconciled counts, ranked discrepancies, daily email | `on-hand`, `discrepancies`, `alerts.py` |
| Stakeholders | Dave Kessler, Denise Alvarez | `email.recipients`; `docs/UAT-script.md` |
| Audience Type | non-technical staff | plain-language UI; a test asserts no jargon renders |
| Current Process | manual reconciliation of spreadsheet vs POS | the variance report replaces exactly this |
| Interaction Model | scheduled automation + on-demand upload | `run-scheduler`, upload form, 07:30 digest |

## A.2 The five prompts the Orchestrator produced

This is the system's actual output for this session — read straight from the
saved `build_plan` in `sessions/20260803183207-45cc6f.json`. Ten answers went in;
these five came back out.

**1.**
> You are a senior software architect. Initialize a project to create a system
> for reconciling inventory counts between an .xlsx file and the Square POS
> system using its API. The system will address issues such as failed orders
> costing $2,000 per month, 8-12 oversells weekly, and $14,000 in duplicate
> inventory. Set up version control and project structure.

**2.**
> Develop the data layer by implementing connectors to access the inventory data
> from both the .xlsx file and Square POS API. Ensure mechanisms are in place for
> importing Excel reports and securely accessing Square's API for real-time reads
> during business hours every 15 minutes, and on-demand uploads.

**3.**
> Build the core features to calculate true on-hand inventory counts for both
> locations, display discrepancies between Square and the Excel sheet, and sort
> mismatched parts by price. Implement functionality to send daily morning email
> summaries of low stock and sold-out items to stakeholders.

**4.**
> Construct a user interface tailored for non-technical staff: a visual display
> showing inventory counts and discrepancies directly, facilitating easy lookup
> and manipulation. Allow for manual corrections based on physical counts,
> accommodating different technical comfort levels.

**5.**
> Perform integration and end-to-end testing to ensure the data flow is correct
> and the user interface updates accurately every 15 minutes. Verify the email
> summary delivery and correct sorting according to user requirements. Confirm
> functionality with designated stakeholders, Dave Kessler and Denise Alvarez,
> and adjust per their feedback.

Each prompt maps onto a layer of the finished artifact:

| Prompt | Where it landed in `inventory-demo` |
|---|---|
| 1 | Project scaffold, version control, architecture |
| 2 | `connectors/excel.py`, `connectors/square.py`, SQLite store, `scheduler.py`, CLI |
| 3 | `reconcile.py`, price/value ranking, `alerts.py`, the 07:30 digest |
| 4 | Flask UI, physical-count ledger, two counting modes |
| 5 | End-to-end tests — 282 total, two integration defects found and fixed |

## A.3 The quantified pain never reached the build

Prompt 1 carries three hard numbers — **$2,000 per month in failed orders, 8-12
oversells weekly, $14,000 in duplicate inventory**. They come from the very first
thing the user typed, and they are exactly what `PainPointsAgent` refuses to mark
COVERED without: a specific problem, a consequence, and a concrete detail.

None of the three appears anywhere in the artifact — not in the code, the README,
or the tests (verified by search). The concept of an oversell is all over it; the
cost of one never made it across.

That is worth noting in both directions. The interview did its job: it extracted
quantified business pain that no one would have volunteered to a blank prompt.
The handoff then dropped it, because nothing downstream asks the agent to carry
the *why* into what it builds. A build plan that lands the numbers in the
artifact — in the README, in the acceptance criteria — would let the shop owner
check whether the software actually recovered the $2,000.

## A.4 What the build itself found

Two defects surfaced at Prompt 5 that no single prompt would have caught —
evidence that *sequencing* the prompts produces real integration testing:

1. **Counts never reached the email.** Physical counts arrived at Prompt 4; the
   digest was built at Prompt 3. Nothing connected them until integration
   testing. A counted item whose register still disagrees is the most actionable
   line in the whole email, and it was missing.
2. **A scheduling assumption was wrong.** The 07:30 digest does not coincide with
   a poll, because the shop opens at 09:00 — so the morning email is built from
   the previous evening's final reading. Correct behaviour, but not what the test
   author assumed, and the reason the staleness banner exists.

Also found and fixed at Prompt 3: a multi-job scheduler bug where two jobs due at
the same instant caused one to be silently skipped — which would have dropped the
daily digest every morning it coincided with a poll.

## A.5 What was refused, and why

Three things in the plan could not be honestly completed. Each was flagged during
the run rather than quietly skipped or faked.

1. **Stakeholder confirmation.** Prompt 5 asks to "confirm functionality with
   designated stakeholders… and adjust per their feedback." The agent has no way
   to contact them. Rather than invent feedback or a sign-off, it produced
   `docs/UAT-script.md` — eight scenarios with empty result boxes, a signature
   block, and seven business decisions it made unilaterally that those two should
   ratify or overrule. **A prompt can instruct an agent to obtain human sign-off,
   but no agent can supply it.** Prompt 5's template should probably say "prepare
   a stakeholder acceptance script."
2. **The colour-palette validator could not run** — `node` is not installed on
   this machine. It gated nothing: no multi-series chart was produced.
3. **No visual inspection of the UI.** No browser automation was available. The
   agent wrote structural layout tests and stated plainly that those cannot tell
   you whether the page looks right.

## A.6 Reproducing the artifact

```powershell
cd C:\Users\kenan\Desktop\Summer2026\ICS499\inventory-demo
Get-ChildItem var -Filter "inventory.db*" | Remove-Item -Force
Copy-Item config\settings.example.toml config\settings.toml -Force
python run.py make-sample-workbook
python -m pytest tests -q          # 282 passed
python run.py import-excel
python run.py poll-square
python run.py discrepancies
python run.py serve                # http://127.0.0.1:5000
```
