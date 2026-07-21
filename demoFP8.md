# FP8 Demo — Making the Interview Smart

**Week 10 · Due Jul 20**

## The big idea (plain English)

Our app interviews a person about the software they want built. It works across **8 topics** it must understand (the problem, the data, who uses it, and so on). When all 8 are answered, it produces a build plan.

**Before this week**, the app asked the same fixed questions in the same order, no matter what you typed — like a paper form that can't react to your answers.

**This week (FP8)** we made it *react* using the **Orchestrator-Workers** pattern:
- A dedicated agent handles each of the 8 domains — each one knows exactly what "covered" means for its topic
- The active agent reads your answer and asks a **follow-up tailored to your project** (not a canned line)
- An agent only checks a domain off when you've given **real, specific detail** — it digs for *more* information, not less
- A **turn cap** (5 agent questions per domain) prevents any domain from looping indefinitely
- The **Orchestrator** manages which agent is active, writes state when satisfied, and advances to the next domain

> Everything below runs from the app folder:
> `...\ICS499\ai-software-builder\requirement-orchestrator`, using the PHP already installed
> at `C:\xampp\php\php.exe`.
> Or open directly: **http://localhost/requirement-orchestrator/public/**

---

## What we changed this week

**New files**
| File | What it does, in plain terms |
|------|------------------------------|
| `src/Orchestrator.php` | The "traffic controller." Knows which domain agent is active, delegates to it, writes state when satisfied, advances to the next agent. |
| `src/agents/DomainAgent.php` | Shared base class for all 8 domain agents — handles LLM calls, transcript formatting, and fallback behavior. |
| `src/agents/PainPointsAgent.php` | Focuses only on extracting: specific problem + consequence + one concrete detail. |
| `src/agents/DataSourcesAgent.php` | Focuses only on: named APIs, databases, or file types. |
| `src/agents/DataAccessAgent.php` | Focuses only on: push/pull/upload method + frequency. |
| `src/agents/EndResultAgent.php` | Focuses only on: concrete output format and content. |
| `src/agents/StakeholdersAgent.php` | Focuses only on: named owner/role with accountability. |
| `src/agents/AudienceTypeAgent.php` | Focuses only on: user type + technical level. |
| `src/agents/CurrentProcessAgent.php` | Focuses only on: current manual steps or tools being replaced. |
| `src/agents/InteractionModelAgent.php` | Focuses only on: trigger type and frequency. |
| `src/LlmClient.php` | The "phone line" to the AI. All AI calls go through here — switch Claude↔OpenAI in one config line. |
| `tests/boundary_deviation_test.php` | Automated proof that off-topic messages don't corrupt domain state. |
| `config/local.php.example` | Template for optional settings (AI model per task, demo mode). |

**Changed files**
| File | What changed |
|------|--------------|
| `public/post_message.php` | Rewired to call `Orchestrator::dispatch()` instead of the old AgentEngine + RequirementParser pipeline. |
| `src/InterviewSession.php` | Added `writeDomainAnswer()` and `readDomainAnswers()` — the Orchestrator saves what each agent extracted so the Compiler Agent has concrete answers to populate the build plan. |

**The logic change in one line:** every answer now goes to a *specialized domain agent* that knows exactly what it needs, instead of a single general extractor that has to guess across all 8 topics at once.

---

## Try it yourself — free, no AI key needed (this is the demo) 🆓

We built a **free offline "demo mode"** so you can show the smart behavior without buying an AI key.

1. **Turn on demo mode + start the app:**
   ```powershell
   $env:LLM_MOCK = "1"
   C:\xampp\php\php.exe -S localhost:8000 -t public
   ```
2. Open **http://localhost:8000/index.php** and click **New Session**.
3. **Focused questions** — type:
   *Customers email me orders and I retype them into a spreadsheet every morning.*
   → The active domain agent asks a follow-up **specific to your answer** and one topic on the right turns green.
4. **Digs for detail** — type a vague answer like *idk* → **nothing advances**; the agent keeps asking until it has enough.
5. **Moves through domains** — after the Pain Points agent is satisfied, the Data Sources agent takes over with a completely different line of questioning.

Stop the app with **Ctrl+C**. Turn demo mode off with `$env:LLM_MOCK = "0"`.

---

## Optional: run it on the real AI 🔑

Each person uses their **own** key — you never pay for the whole class. Supports **Anthropic (Claude)** keys from **console.anthropic.com → API Keys** and **OpenAI (ChatGPT)** keys from **platform.openai.com → API Keys**.

Turn demo mode **off** (`$env:LLM_MOCK = "0"`), open the site, and enter your key in the form that appears. The key is stored only in the browser tab's `sessionStorage` — **never sent to a server session or saved to disk.** It is cleared automatically when you close the tab. Opening a new tab requires re-entering the key.

---

## Proof it works (automated tests — no key needed)

```powershell
C:\xampp\php\php.exe tests\schema_migration_test.php     # expect: 12 passed, 0 failed
C:\xampp\php\php.exe tests\session_recovery_test.php     # expect: 11 passed, 0 failed
```

## If something looks off
- **Stuck on the "enter key" screen** → paste your own key there, or turn on free demo mode (`$env:LLM_MOCK = "1"`).
- **`php.exe` not found** → use the full path `C:\xampp\php\php.exe`.
- **Want a clean slate** → delete the files in `requirement-orchestrator\sessions\`.
- **Domain not advancing** → the active agent needs a more specific answer. Give it a concrete detail (a number, a name, a frequency) and it will move on.
