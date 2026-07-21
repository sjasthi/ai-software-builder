# ai-software-builder

A PHP web application that conducts an adaptive interview with a user across 8 architectural requirement domains and compiles the results into a sequenced 5-prompt build plan the user pastes into Claude Code, ChatGPT, or Gemini to build their software.

**This system is the product. It does not build software. It produces the plan that builds the software.**

---

## Tech Stack

| Layer | Technologies |
|-------|-------------|
| Front End | HTML5, CSS3, JavaScript, jQuery, Bootstrap 5 |
| Server | PHP (via XAMPP) |
| Storage | JSON session files (`sessions/*.json`) |
| Database | MySQL (schema deliverable; runtime uses JSON files) |
| Intelligence | Claude or OpenAI via `src/LlmClient.php` |

---

## Run Locally

1. Install [XAMPP](https://www.apachefriends.org/) and start Apache + MySQL
2. Place the project in `C:\xampp\htdocs\` (or symlink it)
3. Open: **`http://localhost/requirement-orchestrator/public/`**

---

## Agent Architecture

The system uses the **Orchestrator-Workers** pattern:

```
src/
├── Orchestrator.php          # Identifies active domain, dispatches to agent, writes state
├── agents/
│   ├── DomainAgent.php       # Abstract base: shared evaluate() + nextQuestion()
│   ├── PainPointsAgent.php
│   ├── DataSourcesAgent.php
│   ├── DataAccessAgent.php
│   ├── EndResultAgent.php
│   ├── StakeholdersAgent.php
│   ├── AudienceTypeAgent.php
│   ├── CurrentProcessAgent.php
│   └── InteractionModelAgent.php
├── InterviewSession.php      # Snapshot Agent: session, transcript, domain answers
├── LlmClient.php             # Provider abstraction (Claude, OpenAI, mock)
└── ManifestGenerator.php     # Compiler Agent: 5-prompt build plan (FP9)
```

Each domain agent owns one of the 8 requirement domains. It has its own focused extraction prompt (with a domain-specific definition of "covered") and its own question generation prompt. The Orchestrator routes each user message to the active domain agent, saves the extracted detail when a domain is marked COVERED, and advances to the next agent.

---

## API Key

Get your Claude API key at **console.anthropic.com** → API Keys.

Paste it into the key input on the app's landing page. It is held in server memory only — never written to disk.

---

## Demo Mode (no key required)

```powershell
$env:LLM_MOCK = "1"
```

Set this before starting the server. The app runs with a scripted mock LLM — shows the full interview flow at no cost.

---

## Tests

```powershell
cd requirement-orchestrator
C:\xampp\php\php.exe tests\boundary_deviation_test.php   # 16 passed
C:\xampp\php\php.exe tests\gate_check_test.php           # 12 passed
C:\xampp\php\php.exe tests\session_recovery_test.php     # 11 passed
C:\xampp\php\php.exe tests\fp7_verification.php          # 19 passed
```
