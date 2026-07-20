# FP8 Demo — Making the Interview Smart

**Week 10 · Due Jul 20**

## The big idea (plain English)

Our app interviews a person about the software they want built. It works like an intake
form with **8 topics** it must understand (the problem, the data, who uses it, and so on).
When all 8 are answered, it produces a build plan.

**Before this week**, the app asked the same fixed questions in the same order, no matter what
you typed — like a paper form that can't react to your answers.

**This week (FP8)** we made it *react*:
- It reads your answer and asks a **follow-up tailored to your project** (not a canned line).
- If you go **off-topic**, it politely **steers you back** instead of moving on.
- It only checks a topic off when you've given **real, specific detail** — so it digs for
  *more* information, not less, and won't finish until it genuinely understands your project.

> Everything below runs from the app folder:
> `...\ICS499\ai-software-builder\requirement-orchestrator`, using the PHP already installed
> at `C:\xampp\php\php.exe`.

---

## What we changed this week

**New files**
| File | What it does, in plain terms |
|------|------------------------------|
| `src/AgentEngine.php` | The "conversation brain." Decides if your message is on-topic, writes the next tailored question, and redirects you if you drift. |
| `src/LlmClient.php` | The "phone line" to the AI. One place that all AI calls go through, so we can switch which AI model we use (or use a free offline stand-in) without touching anything else. |
| `tests/boundary_deviation_test.php` | Automated proof that off-topic messages never advance the interview. |
| `public/set_key.php` | Saves the visitor's own AI key **in server memory only** (never to disk), and clears it on request. |
| `config/local.php.example` | A template for optional settings (which AI model per task, or free demo mode). |

**Changed files**
| File | What changed |
|------|--------------|
| `src/RequirementParser.php` | Now makes its AI call through the shared "phone line," and holds a **higher bar** for marking a topic answered — vague replies no longer count. |
| `public/index.php` | The launch page now asks each visitor for **their own** AI key to begin (so no one pays for everyone). Saved past sessions still show below, exactly as before. |
| `public/post_message.php` | The step that runs each time you hit Send. Rewired from "just ask the next fixed question" to the real flow: **check on-topic → understand the answer → ask a smart follow-up (or redirect).** |

**The logic change in one line:** every answer now flows through *is this on-topic? → what did
it tell us? → what's the best next question?*, instead of blindly walking a fixed list.

---

## Try it yourself — free, no AI key needed (this is the demo) 🆓

We built a **free offline "demo mode"** so you can show the smart behavior without buying an AI
key. It stands in for the real AI at no cost.

1. **Turn on demo mode + start the app:**
   ```powershell
   $env:LLM_MOCK = "1"
   C:\xampp\php\php.exe -S localhost:8000 -t public
   ```
2. Open **http://localhost:8000/index.php** and click **New Session**.
3. **Tailored question** — type:
   *Customers email me orders and I retype them into a spreadsheet every morning.*
   → The next question **echoes your own words** and **one topic** on the right turns green.
4. **Digs for detail** — type a lazy answer like *idk* → **nothing advances**; it keeps asking.
   (It checks off *one topic per real answer*, so all 8 need a genuine reply — that's the "more
   info, not less" behavior.)
5. **Stays on track** — type *what's the weather today?* → it **politely redirects** you and the
   progress bar **does not move**.

Stop the app with **Ctrl+C**. Turn demo mode off with `$env:LLM_MOCK = "0"`.

---

## Optional: run it on the real AI 🔑

Each person uses their **own** Anthropic key — you never pay for the whole class. Turn demo
mode **off** (`$env:LLM_MOCK = "0"`), start the app, then on the launch page:
1. Paste your key into **"Enter your Anthropic API key to begin"** and click **Begin**.
2. Click **Start New Session** — questions and redirects are now written live by the AI.

Your key is held only in the server's memory for that browser visit — **never saved to disk or
shown in your saved interviews.** Click **clear key** (or close the browser) to remove it.

*(Which AI model handles which task is set in `task_models` in `config/local.php` — that's the
"use the best-fit AI" flexibility our professor asked for.)*

---

## Proof it works (automated tests — no key needed)

```powershell
C:\xampp\php\php.exe tests\boundary_deviation_test.php   # expect: 16 passed, 0 failed
C:\xampp\php\php.exe tests\gate_check_test.php           # expect: 12 passed, 0 failed
```
The first proves the new FP8 routing/redirect logic; the second proves last week's work still
works. Both already pass on this machine.

## If something looks off
- **Stuck on the "enter key" screen** → that's expected until an AI key is active. Paste your
  own key there, or turn on free demo mode (`$env:LLM_MOCK = "1"`) to try it without a key.
- **`php.exe` not found** → use the full path `C:\xampp\php\php.exe`.
- **Want a clean slate** → delete the files in `requirement-orchestrator\sessions\`.
