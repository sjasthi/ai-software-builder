# FP3 PROJECT PLAN: AGENTIC RUNTIME ORCHESTRATOR
### Multi-Agent Requirement Interview System → Prompt Build Plan Generator

---

## What This System Is

A PHP web application that conducts an adaptive interview with a user through 8 architectural requirement domains. Multiple specialized agents handle different responsibilities — extracting requirements from natural language, generating the next question, managing session state, and compiling the final output. The final output is a **sequenced series of prompts** the user pastes directly into Claude Code, ChatGPT, or Gemini to build their software. The system is **universal** — any user, any software idea, different answers produce a different prompt plan.

**This system is the product. It does not build the software itself. It produces the plan that builds the software.**

**Agentic Workflow Pattern: Orchestrator-Workers**
This system implements the **Orchestrator-Workers** pattern from Anthropic's *Building Effective Agents* framework. A central Orchestrator receives each user message, identifies which domain agent should be active (the first OPEN domain), and delegates to that specialized agent for both evaluation and question generation. Each of the 8 domain agents is a worker with a narrow, focused responsibility — it knows exactly what "covered" means for its domain and probes for that specific detail across multiple turns before marking it satisfied. The Orchestrator manages state transitions between agents and triggers the Compiler Agent when all 8 are COVERED.

---

## 0. The 8 Requirement Domains

The interview cannot terminate until all 8 domains are marked `COVERED` by the LLM extraction agent.

| # | Domain | What It Captures |
|---|--------|-----------------|
| 1 | **Pain Points** | The specific problem the software solves |
| 2 | **Data Sources** | The data the software consumes or stores |
| 3 | **Data Access** | How data enters the system (upload, API, manual entry) |
| 4 | **End Result** | The primary output delivered to the user |
| 5 | **Stakeholders & Consumers** | Who owns and uses the system |
| 6 | **Audience Type** | Technical literacy level of end-users |
| 7 | **Current Process** | The manual workflow this software replaces |
| 8 | **Interaction Model** | How the user engages (chat, batch, scheduled automation) |

---

## 1. Technology Stack

| Layer | Technologies |
|-------|-------------|
| Front End | HTML5, CSS3, JavaScript, jQuery, Bootstrap 5 |
| Server | PHP |
| Backend | MySQL |
| Intelligence | LLM API (Claude or Gemini) called from PHP src/ agents |

---

## 2. Agentic Workflow Pattern: Orchestrator-Workers

### 2a. Why Orchestrator-Workers

Each of the 8 requirement domains has distinct expertise requirements. What makes a pain point "covered" is completely different from what makes an interaction model "covered" — the PainPoints agent needs a specific problem plus a real consequence; the InteractionModel agent needs a trigger type and frequency. A single extraction agent evaluating all 8 domains in one LLM call must split its attention across 8 different definitions of "done," which produces shallow coverage and premature COVERED marks.

**Orchestrator-Workers** solves this by giving each domain its own dedicated agent with:
- A focused extraction prompt that specifies exactly what "covered" means for that domain
- A focused question generation prompt that probes only for what that domain needs
- Multi-turn capability — the agent stays active on its domain until the bar is met, not just for one exchange

The Orchestrator handles state: it knows which domain is active, delegates to that agent, writes COVERED + saves the extracted detail when satisfied, and advances to the next agent. A turn cap (5 agent questions per domain) prevents any agent from looping indefinitely.

### 2b. The Orchestrator-Workers Flow

```
User Message
     │
     ▼
┌─────────────────────────────────────────┐
│  ORCHESTRATOR                           │
│  Identifies active domain (first OPEN)  │
└─────────────────────────────────────────┘
     │
     ▼
┌─────────────────────────────────────────┐
│  ACTIVE DOMAIN AGENT (1 of 8)           │  ← LLM Call #1
│  evaluate(message, full_transcript)     │
│  → covered: bool, detail: string        │
└─────────────────────────────────────────┘
     │
     ▼
┌─────────────────────────────────────────┐
│  ORCHESTRATOR: Write State              │
│  If covered → writeDomainState(COVERED) │
│               writeDomainAnswer(detail) │
│  After 5 agent turns → force COVERED   │
└─────────────────────────────────────────┘
     │
     ▼
┌─────────────────────────────────────────┐
│  NEXT DOMAIN AGENT                      │  ← LLM Call #2
│  nextQuestion(full_transcript)          │
│  → focused probing question             │
└─────────────────────────────────────────┘
     │
     ▼
┌─────────────────────────────────────────┐
│  Response returned to user              │
│  Domain matrix updated in UI            │
│  Loop restarts on next user message     │
└─────────────────────────────────────────┘
     │ (when all 8 COVERED)
     ▼
┌─────────────────────────────────────────┐
│  COMPILER AGENT (ManifestGenerator)     │  ← LLM Call #3
│  readDomainAnswers() → 5-prompt plan    │
└─────────────────────────────────────────┘
```

---

## 3. File & Module Architecture

```
/requirement-orchestrator/
│
├── config/
│   └── database.php              # DB connection & shared utilities
│
├── src/
│   ├── InterviewSession.php      # SNAPSHOT AGENT: session state, transcript, domain answers
│   ├── Orchestrator.php          # ORCHESTRATOR: routes messages to active domain agent
│   ├── agents/
│   │   ├── DomainAgent.php       # Abstract base: shared evaluate() + nextQuestion() logic
│   │   ├── PainPointsAgent.php   # Covers: specific problem + consequence + one concrete detail
│   │   ├── DataSourcesAgent.php  # Covers: named APIs, DBs, or file types
│   │   ├── DataAccessAgent.php   # Covers: push/pull/upload method + frequency
│   │   ├── EndResultAgent.php    # Covers: concrete output format and content
│   │   ├── StakeholdersAgent.php # Covers: named owner/role with accountability
│   │   ├── AudienceTypeAgent.php # Covers: user type + technical level
│   │   ├── CurrentProcessAgent.php  # Covers: named current steps or tools being replaced
│   │   └── InteractionModelAgent.php # Covers: trigger type + frequency
│   ├── LlmClient.php             # Provider abstraction (Claude, OpenAI, mock)
│   └── ManifestGenerator.php     # COMPILER AGENT: 5-prompt build plan (FP9)
│
└── public/
    ├── index.php                 # Split-pane UI: chat + live domain matrix
    ├── post_message.php          # Controller: calls Orchestrator::dispatch() on each submit
    └── js/
        └── app.js                # AJAX pipeline: UI lockdown, badge updates
```

**Why one agent per domain:** Each domain has a different definition of "covered." A single extractor evaluating all 8 at once must generalize its bar, which produces shallow answers. Dedicated agents can be opinionated — PainPointsAgent won't accept "it's slow" without a concrete consequence; DataSourcesAgent won't accept "we have data" without a named source. Narrower system prompts produce more accurate extraction, and each agent can probe its domain across multiple turns before advancing.

---

## 3. LLM Prompt Architecture (Agent Intelligence Layer)

Each domain agent has two prompt types. These are the functional specification of what the system does intelligently.

### 3a. Extraction Prompt (per domain agent)

Each agent's extraction prompt is domain-specific and opinionated. The key difference from a single shared extractor: each agent defines its own binary covered/not-covered rule. The LLM evaluates the **full conversation** (up to 30 turns) and returns:

```json
{"covered": true|false, "detail": "one-line summary of what was learned"}
```

Example — PainPointsAgent covered rule:
```
COVERED means ALL THREE present anywhere in the conversation:
1. A specific problem is named (not just "inefficiency")
2. A consequence is described (lost revenue, errors, missed orders, etc.)
3. At least one concrete detail: a number, frequency, dollar amount, or scenario

Once all three are present, mark COVERED immediately.
```

The `detail` string is saved via `InterviewSession::writeDomainAnswer()` and used by the Compiler Agent to populate the build plan.

### 3b. Question Generation Prompt (per domain agent)

Each agent's question prompt knows only about its own domain. It injects the recent transcript and generates one focused probing question targeting exactly what's still missing for that domain.

```
You are interviewing a user about [domain] — [domain description].
You need: [specific criteria for this domain].
Ask one focused question. Reference what they've already said.
Output only the question text.
```

### 3c. Compiler Agent Output Format (ManifestGenerator.php)

When all 8 domains equal `COVERED`, the Compiler Agent generates a sequenced series of prompts the user pastes into any external LLM to build their software.

```
═══════════════════════════════════════════════
  YOUR SOFTWARE BUILD PLAN
  Generated by Requirement Orchestrator
═══════════════════════════════════════════════

PROMPT 1 — PROJECT INITIALIZATION
Paste this first into Claude Code / ChatGPT / Gemini:

"You are a senior software architect. Build a [system name] application
that solves [pain_points]. The primary users are [stakeholders] with a
[audience_type] technical background. The system replaces [current_process]."

───────────────────────────────────────────────

PROMPT 2 — DATA LAYER
Paste this after Prompt 1 is confirmed:

"Generate the complete database schema for this system. Data enters via
[data_access] from [data_sources]. Include all tables, column types,
foreign keys, and indexes."

───────────────────────────────────────────────

PROMPT 3 — CORE FEATURE BUILD
Paste this after the schema is confirmed:

"Build the core application logic. The system must produce [end_result]
via a [interaction_model] interface. Include all server-side logic,
validation rules, and error handling."

───────────────────────────────────────────────

PROMPT 4 — UI CONSTRUCTION
Paste this after core logic is confirmed:

"Build the front-end interface appropriate for [audience_type] users.
The UI must present [end_result] clearly and support [interaction_model]."

───────────────────────────────────────────────

PROMPT 5 — INTEGRATION & TESTING
Paste this last:

"Connect all layers. Verify that data flows correctly from [data_access]
through the backend to produce [end_result]. Write tests for all
critical paths."
```

---

## 4. Website Design

### Layout: Split-Pane Interface

```
┌─────────────────────────────┬──────────────────────────┐
│                             │  SYSTEM COVERAGE MATRIX  │
│     CHAT INTERFACE          │                          │
│                             │  Pain Points      ✓      │
│  Agent: "What specific      │  Data Sources     ✓      │
│  problem are you solving?"  │  Data Access      ✓      │
│                             │  End Result       ○      │
│  User: "..."                │  Stakeholders     ○      │
│                             │  Audience Type    ○      │
│  Agent: "..."               │  Current Process  ○      │
│                             │  Interaction Model ○     │
│  [ Type your answer...  ]   │                          │
│                   [Send →]  │  Progress: 3 / 8         │
│                             │  ████░░░░░░░░ 37%        │
└─────────────────────────────┴──────────────────────────┘
```

**Left panel:** Conversational chat stream. Agent messages appear as bubbles. User types responses into a text input field and submits.

**Right panel:** Live domain coverage matrix. Each of the 8 domains displays a `✓` (COVERED) or `○` (OPEN) badge, updated in real time after each exchange. A progress bar shows overall completion percentage.

**Completion state:** When all 8 domains reach `COVERED`, the right panel transitions to display the generated 5-prompt build plan with a one-click copy button.

---

## 5. 8-Week Implementation & Verification Roadmap

---

### Week 1: Database Scaffolding & System Initialization

**Implementation Objective:**
Establish `config/database.php` and the full relational schema. Three tables are required:
- `sessions` — session token (UNIQUE), timestamp, inferred technical level
- `conversation_log` — each exchange (role, content, timestamp) tied to a session token
- `domain_state` — JSON column holding all 8 domain statuses per session

**Why this is first:** Every agent depends on this layer. The Snapshot Agent writes conversation history here, the Extraction Agent reads and writes domain state here, and the Compiler Agent reads finalized domain data from here. Building it first means all subsequent modules are tested against real persistence, not mocked data.

**Verification Proof:** Execute a schema migration test. Confirm the database creates all three tables, enforces a `UNIQUE` constraint on session tokens, and correctly stores and retrieves a JSON object in the `domain_state` column. Pass a test record through each table and verify exact retrieval.

---

### Week 2: Snapshot Agent & Session Persistence

**Implementation Objective:**
Build `src/InterviewSession.php`. This agent handles four operations: creating a new session with a unique token, writing each conversation exchange to `conversation_log`, reading back the full transcript for any active session, and writing updated domain state after each extraction pass.

**Why this is necessary:** The LLM has no memory between API calls. Without a persistence layer, every agent call starts from nothing — no history, no domain progress. The Snapshot Agent is what makes the system stateful. It supplies the Routing Agent with full transcript context and supplies the Compiler Agent with finalized domain data at the end.

**Verification Proof:** Run an atomic transaction recovery test. Simulate a network drop mid-interview by killing the connection after three exchanges. Reload the session using the same token and confirm the agent reconstructs the exact transcript and all domain checklist values with zero data loss.

---

### Week 3: Domain Agents & Orchestrator
*(Orchestrator-Workers — Core Intelligence)*

**Implementation Objective:**
Build `src/agents/DomainAgent.php` (abstract base) and 8 concrete domain agents, plus `src/Orchestrator.php`. Each domain agent implements two methods: `evaluate()` evaluates the full conversation and returns `{covered, detail}`; `nextQuestion()` generates one focused probing question for its domain. The Orchestrator's `dispatch()` method: reads session state, finds the first OPEN domain, calls that agent's `evaluate()`, writes COVERED + `writeDomainAnswer(detail)` when satisfied, then calls `nextQuestion()` on the now-active agent.

**Why one agent per domain:** Each domain has a different definition of "covered." A single extractor splitting attention across 8 criteria generalizes its bar, which produces shallow answers. Dedicated agents are opinionated — PainPointsAgent won't accept "it's slow" without a consequence; DataSourcesAgent won't accept "we have data" without a named source.

**Why evaluate the full transcript:** Early concrete answers fall out of a short window as the conversation grows. Each agent evaluates up to 30 turns so detail given 10 exchanges ago still counts toward coverage.

**Turn cap:** After 5 agent questions on the same domain, the Orchestrator force-marks it COVERED to prevent infinite loops.

**Verification Proof:** Pass *"I want to automatically pull my inventory from a Shopify API every morning"* through the Orchestrator. DataSourcesAgent, DataAccessAgent, and InteractionModelAgent should each mark COVERED. The remaining 5 domains stay OPEN and the Compiler Agent is not triggered.

---

### Week 4: Adaptive Question Generation & Domain Probing

**Implementation Objective:**
Each domain agent's `nextQuestion()` method generates a probing follow-up tailored to what the user has already said — not a generic script. The question generation prompt injects the recent transcript so the agent can reference specific details the user gave and push for what's still missing in that domain.

**Why dynamic generation over a scripted question tree:** A fixed sequence cannot adapt when a user partially covers a domain or answers obliquely. LLM-generated questions target exactly what's still missing for that specific domain in real time.

**Why domain-scoped question prompts:** A question prompt that knows only about its own domain produces more focused, specific questions than a general "ask about OPEN domains" prompt. The DataSourcesAgent asks about API names and formats; it doesn't wander into stakeholder questions.

**Verification Proof:** Run a boundary deviation test. Confirm that an off-topic message (e.g., "How do I run marketing ads?") does not advance any domain state — the turn cap absorbs it and no COVERED flag is written.

---

### Week 5: Workspace Interface Construction

**Implementation Objective:**
Build `public/index.php` using HTML5, CSS3, and Bootstrap 5. Implement the split-pane layout defined in Section 4: left chat stream container and right domain coverage matrix panel. Each domain badge must use explicit `data-domain` attribute tags (e.g., `data-domain="pain_points"`) so JavaScript can target and update them independently without re-rendering the full page.

**Why the right panel is functionally necessary:** Without live domain visibility, users have no signal for which requirements are still open or when the interview ends. The matrix panel reduces redundant answers and sets a clear completion expectation, both of which improve the specificity of the final output.

**Verification Proof:** Run a visual rendering check across desktop (1440px), tablet (768px), and mobile (375px) viewports. The split-pane layout must hold at all breakpoints. Confirm each domain badge is independently addressable via its `data-domain` attribute using browser DevTools element inspection.

---

### Week 6: Asynchronous Pipeline & Chain Execution Controller
*(Prompt Chain — Runtime Implementation)*

**Implementation Objective:**
Build `public/js/app.js` and `public/endpoint.php`. `endpoint.php` is the runtime controller that executes the prompt chain on every user submission. On receiving a payload, it runs the chain in strict sequence: call the Extraction Agent (Step 1) → check the programmatic gate → if gate passes, call the Routing Agent (Step 2) → return both the updated domain JSON and the next question in a single response. If the gate fires (all 8 `COVERED`), skip Step 2 and call the Compiler Agent instead. On the frontend, `app.js` immediately locks the interface on submission — disabling the text input and replacing the submit button with a loading spinner — then fires a single AJAX POST and waits. On response, it unlocks, appends the new message, and updates the domain matrix badges.

**Why a unified endpoint implements the chain:** The prompt chain must execute sequentially — Step 2 cannot run before Step 1 completes and the gate is evaluated. Putting this logic in a single server-side controller enforces that sequencing with no risk of out-of-order execution. Splitting into separate HTTP calls would require the frontend to manage chain sequencing and partial failures, which is the wrong layer for orchestration logic.

**Why interface locking is architecturally required:** Without it, a user clicking Submit multiple times fires concurrent AJAX requests while the LLM chain is still processing. Two parallel chain executions writing to `domain_state` simultaneously create a race condition that corrupts domain coverage. The lockdown ensures only one chain execution runs at a time.

**Verification Proof:** Run a race-condition stress test. Confirm via browser DevTools that exactly one network request is pending after Submit is clicked regardless of how fast it was clicked. Verify the input field is disabled and the spinner is active for the full chain processing window. Confirm via server logs that only one chain execution was triggered per submission.

---

### Week 7: Compiler Agent & Prompt Build Plan Generation
*(Prompt Chain — Step 3, Terminal Node)*

**Implementation Objective:**
Build `src/ManifestGenerator.php`. When all 8 domains equal `COVERED`, this agent reads the finalized domain data from the database and compiles it into the sequenced 5-prompt build plan defined in Section 3c. Each prompt is populated with the user's verified, specific answers in place of the template placeholders. All conversational prose is suppressed — the output contains only structured, executable prompt content ready for immediate use in an external LLM.

**Why the output is a sequenced series of prompts rather than a single document:** A monolithic specification overwhelms an LLM and produces inconsistent results. Sequenced prompts with a defined execution order let the user build their software incrementally — confirming each layer before proceeding to the next. This matches how developers actually use coding agents effectively.

**Verification Proof:** Trigger the Compiler Agent on a fully covered session. The output passes if it contains all 5 labeled prompt sections, each populated with domain-specific content from the user's actual answers, with zero lines of conversational filler. Validate programmatically using a regex scan that flags any line beginning with filler patterns (*"Sure," "Here is," "Of course," "Great"*).

---

### Week 8: End-to-End System Validation & Success Story

**Implementation Objective:**
Execute full loop integration across all four agents. Validate that a raw idea entered into the chat produces a complete, populated 5-prompt build plan without manual intervention at any stage of the pipeline.

**Why end-to-end validation is the correct final test:** Unit tests confirm each agent works in isolation. End-to-end validation confirms the full prompt chain executes correctly as a system — that Step 1 (Extraction) feeds the gate correctly, that the gate correctly triggers either Step 2 (Routing) or Step 3 (Compilation), that the routing branches both produce correct outputs, and that the Compiler Agent receives clean, complete data when all 8 domains are satisfied. Chain integration failures — wrong gate logic, wrong routing branch, state corruption between steps — are invisible to per-component tests.

**Verification Proof — Two Stages:**

**Stage 1 — Schema Validation (Controlled):**
After the Compiler Agent generates the build plan, run `validate_manifest.php` against the output. This script confirms all 5 prompt sections are present, each is populated with non-empty domain-specific content, and no filler patterns are detected. The system passes Stage 1 when the validator returns zero violations.

**Stage 2 — External Agent Handoff (The Success Story):**
Take a raw software idea through the full interview until the 5-prompt build plan is generated. Copy Prompt 1 and paste it into Claude Code. The success story is demonstrated when Claude Code begins constructing a working prototype with zero structural clarifying questions directed back at the user.

---

## 6. The Success Story (Final Presentation Demo)

1. User opens the application and sees the split-pane interface
2. User types: *"I want to build an inventory management tool for my small business"*
3. The PainPointsAgent evaluates the message — probes for specific problem + consequence + concrete detail. Right panel badge flips to `✓` once satisfied.
4. The DataSourcesAgent becomes active and asks: *"What specific data sources does your inventory system need — a spreadsheet, a Shopify API, a supplier database, or something else?"*
5. The interview continues. Each domain agent stays active until its specific bar is met. Badges flip from `○` to `✓` in real time.
6. All 8 domains reach `COVERED`. The Orchestrator triggers the Compiler Agent. The right panel transitions to display the 5-prompt build plan.
7. User copies Prompt 1 and pastes it into Claude Code.
8. Claude Code begins building the inventory management system with no structural clarifying questions.

**The system works for any software idea. Different answers to the 8 questions produce an entirely different build plan every time.**
