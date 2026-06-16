# FP3 PROJECT PLAN: AGENTIC RUNTIME ORCHESTRATOR
### Multi-Agent Requirement Interview System → Prompt Build Plan Generator

---

## What This System Is

A PHP web application that conducts an adaptive interview with a user through 8 architectural requirement domains. Multiple specialized agents handle different responsibilities — extracting requirements from natural language, generating the next question, managing session state, and compiling the final output. The final output is a **sequenced series of prompts** the user pastes directly into Claude Code, ChatGPT, or Gemini to build their software. The system is **universal** — any user, any software idea, different answers produce a different prompt plan.

**This system is the product. It does not build the software itself. It produces the plan that builds the software.**

**Agentic Workflow Pattern: Prompt Chaining with Routing**
This system implements two complementary patterns from Anthropic's *Building Effective Agents* framework. **Prompt chaining** is the primary structure — every user message passes through a fixed sequence of LLM calls where each call processes the output of the previous one, with a programmatic gate (the 8-domain coverage check) ensuring the process stays on track before advancing. **Routing** is layered on top — at the AgentEngine step, every input is classified as either in-scope (route to domain-targeting question) or out-of-scope (route to drift redirection), with entirely different downstream behavior for each path. Together these two patterns produce a system that is both predictable in structure and adaptive in behavior.

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

## 2. Agentic Workflow Pattern: Prompt Chaining with Routing

### 2a. Why These Two Patterns

**Prompt Chaining** is used because the task decomposes cleanly into a fixed sequence of steps: extract what the user said → check the gate → generate the next question → repeat until all 8 domains are covered → compile the output. Each LLM call is smaller and more focused than a single monolithic call would be, which produces higher accuracy at each step. The 8-domain coverage check is the programmatic gate — the chain cannot advance to the Compiler Agent until all 8 domains return `COVERED`.

**Routing** is layered on top because the AgentEngine step has two fundamentally different downstream behaviors depending on input classification. In-scope input routes to a domain-targeting question. Out-of-scope input routes to a drift redirection prompt. These require different prompts and produce different outputs — routing handles the separation cleanly without complicating the chain.

### 2b. The Prompt Chain Flow

```
User Message
     │
     ▼
┌─────────────────────────────────────────┐
│  STEP 1: EXTRACTION (RequirementParser) │  ← LLM Call #1
│  Input:  user message + domain state    │
│  Output: updated domain JSON            │
│  Mode:   JSON mode enforced             │
└─────────────────────────────────────────┘
     │
     ▼
┌─────────────────────────────────────────┐
│  PROGRAMMATIC GATE: COVERAGE CHECK      │  ← No LLM, pure logic
│  All 8 domains COVERED?                 │
│  YES → skip to Compiler Agent           │
│  NO  → continue to Routing step         │
└─────────────────────────────────────────┘
     │ NO
     ▼
┌─────────────────────────────────────────┐
│  STEP 2: ROUTING (AgentEngine)          │  ← LLM Call #2
│  Classify input:                        │
│    IN-SCOPE  → domain-targeting question│
│    OFF-SCOPE → drift redirection prompt │
└─────────────────────────────────────────┘
     │
     ▼
┌─────────────────────────────────────────┐
│  Response returned to user              │
│  Domain matrix updated in UI            │
│  Loop restarts on next user message     │
└─────────────────────────────────────────┘
     │ (when gate = ALL COVERED)
     ▼
┌─────────────────────────────────────────┐
│  STEP 3: COMPILATION (ManifestGenerator)│  ← LLM Call #3
│  Input:  finalized 8-domain data        │
│  Output: sequenced 5-prompt build plan  │
└─────────────────────────────────────────┘
```

**Why not Orchestrator-Workers:** That pattern is for tasks where the subtasks cannot be predicted ahead of time. This system's subtasks are always the same 8 domains in a defined sequence — full predictability makes prompt chaining the correct fit.

**Why not full autonomous Agents:** Agents are for open-ended problems where the path cannot be hardcoded. This system's path is fixed. The intelligence lives in how each step responds, not in deciding what steps to take.

---

## 3. File & Module Architecture

```
/requirement-orchestrator/
│
├── config/
│   └── database.php              # DB connection pooling & shared utilities
│
├── src/                          # The Four Agent Modules
│   ├── InterviewSession.php      # SNAPSHOT AGENT: Persists session state & conversation history
│   ├── RequirementParser.php     # EXTRACTION AGENT: LLM call → maps user text to 8 domains → returns JSON
│   ├── AgentEngine.php           # ROUTING AGENT: LLM call → generates next adaptive question
│   └── ManifestGenerator.php    # COMPILER AGENT: Assembles verified domain data into prompt build plan
│
└── public/                       # User-Facing Layer
    ├── index.php                 # Split-pane UI: Chat Interface + Live Domain Coverage Matrix
    ├── endpoint.php              # Unified Controller: Routes AJAX payloads through agent pipeline
    └── js/
        └── app.js                # Async Pipeline: UI lockdown, AJAX calls, badge state updates
```

**Why four separate agents:** Each agent has a single non-overlapping responsibility. The Extraction Agent answers *what did the user just tell us?* The Routing Agent answers *what should we ask next?* The Snapshot Agent answers *what have we collected so far?* The Compiler Agent answers *how do we turn verified data into an executable plan?* Separating these allows each to be built, tested, and replaced independently without breaking the others.

---

## 3. LLM Prompt Architecture (Agent Intelligence Layer)

All agentic behavior lives in these prompt templates. They are the functional specification of what the system actually does intelligently.

### 3a. Extraction Agent System Prompt (RequirementParser.php)

```
You are a requirement extraction engine. Evaluate the user's latest message
against the 8 architectural domains and determine which have been addressed.

Current domain state:
{domain_state_json}

User's latest message:
"{user_input}"

Return ONLY a valid JSON object using this exact schema with no prose,
explanation, or markdown fencing:
{
  "pain_points":       "COVERED" | "OPEN",
  "data_sources":      "COVERED" | "OPEN",
  "data_access":       "COVERED" | "OPEN",
  "end_result":        "COVERED" | "OPEN",
  "stakeholders":      "COVERED" | "OPEN",
  "audience_type":     "COVERED" | "OPEN",
  "current_process":   "COVERED" | "OPEN",
  "interaction_model": "COVERED" | "OPEN"
}

Rules:
- Only update domains the user explicitly addressed. Do not infer or assume.
- Domains already marked COVERED must remain COVERED.
- A domain is COVERED only when the user provides concrete, specific detail.
```

### 3b. Routing Agent System Prompt (AgentEngine.php)

```
You are an adaptive software requirements interviewer. Ask the next most
logical clarifying question to help the user define their software.

Active transcript:
{conversation_history}

Current domain coverage:
{domain_state_json}

User's inferred technical level: {technical_level}

Rules:
- Ask exactly one question. No compound questions.
- Only target OPEN domains. Never revisit COVERED domains.
- If the user's message is off-topic (marketing, legal, branding), do not
  advance any domain. Generate one redirection prompt back to software scope.
- Match vocabulary to technical level. Plain language for non-technical
  users. Precise terminology for developers.
- Output only the question. No preamble, no acknowledgment, no filler.
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

### Week 3: Extraction Agent & LLM Semantic Evaluation
*(Prompt Chain — Step 1)*

**Implementation Objective:**
Build `src/RequirementParser.php`. This is Step 1 of the prompt chain. It constructs the Section 4a system prompt, injects the user's latest message and current domain JSON state, and calls the LLM API using **JSON mode** (structured output enforcement). The returned JSON is validated against the 8-domain schema before being written to the database via the Snapshot Agent. After writing, the agent performs the **programmatic gate check** — if all 8 domains equal `COVERED`, the chain skips the Routing step and triggers the Compiler Agent directly.

**Why LLM extraction over keyword matching:** Keyword matching fails on paraphrase and multi-domain statements. A user saying *"I want to pull my inventory from a Shopify API every morning"* simultaneously covers Data Sources, Data Access, and Interaction Model in one sentence. No static rule set reliably detects this. LLM semantic evaluation handles natural language at a level rules cannot.

**Why JSON mode is mandatory:** Without structured output enforcement, the LLM may wrap its response in prose or markdown fencing, which breaks the JSON parser and corrupts domain state. JSON mode guarantees a machine-readable response on every call — a requirement for a programmatic gate to function reliably.

**Verification Proof:** Pass *"I want to automatically pull my inventory from a Shopify API every morning"* through the agent. It passes if the LLM returns a schema-compliant JSON object marking `data_sources`, `data_access`, and `interaction_model` as `COVERED` in a single pass, the database reflects those changes immediately, and the gate check correctly identifies that 5 domains remain `OPEN` and does not trigger the Compiler Agent.

---

### Week 4: Routing Agent & Adaptive Question Generation
*(Prompt Chain — Step 2 with Routing)*

**Implementation Objective:**
Build `src/AgentEngine.php`. This is Step 2 of the prompt chain and is where the **routing pattern** is implemented. The agent constructs the Section 4b system prompt, injects the full transcript and current domain state, and calls the LLM API to classify the user's input and generate a response. The routing decision has two branches: if the input is in-scope, the LLM generates a domain-targeting question aimed at the next `OPEN` domain; if the input is out-of-scope (marketing, legal, branding), the LLM generates a drift redirection prompt without advancing any domain states. These are two entirely different downstream outputs handled by a single classification step.

**Why routing instead of two separate prompts:** A separate out-of-scope detector would require an extra LLM call before every routing decision. Combining classification and response generation into a single prompt keeps the chain at two LLM calls per turn instead of three, reducing latency and cost without sacrificing accuracy.

**Why dynamic generation over a scripted question tree:** A fixed sequence cannot adapt when a user covers multiple domains in one message or answers out of order. LLM-generated questions target exactly what is still missing in real time, shortening the interview and producing more specific, usable answers.

**Why this agent is separate from the Extraction Agent:** RequirementParser answers *what did the user just tell us?* AgentEngine answers *what should we ask next?* These are distinct steps in the chain. Separating them means each can be modified, tested, or swapped independently without breaking the other.

**Verification Proof:** Run a boundary deviation test. Feed the engine *"How do I run marketing ads for this?"* The routing step passes if the LLM classifies this as out-of-scope, generates a redirection prompt back to software architecture, and a database read confirms all 8 domain states are unchanged — proving the routing branch fired correctly and the chain did not advance.

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
3. The Extraction Agent identifies Pain Points as `COVERED`. The right panel badge flips live.
4. The Routing Agent generates: *"What data does your inventory system need to track — products, suppliers, stock levels, or something else?"*
5. The interview continues. Domain badges flip from `○` to `✓` in real time as requirements are covered.
6. All 8 domains reach `COVERED`. The right panel transitions to display the 5-prompt build plan.
7. User copies Prompt 1 and pastes it into Claude Code.
8. Claude Code begins building the inventory management system with no structural clarifying questions.

**The system works for any software idea. Different answers to the 8 questions produce an entirely different build plan every time.**
