# FP10 Demo — Async Pipeline & Chain Execution Controller

**Milestone:** Build `endpoint.php` (chain controller) + `app.js` (UI lockdown + AJAX),
then prove one network request AND one server execution per submit.

## What changed from FP9

Answers no longer trigger a full-page reload. The interview form now POSTs in the
background to **`public/endpoint.php`**, which runs the same chain the Orchestrator
drives — Extraction → gate (all 8 COVERED?) → Routing (next question) **or** Compiler
(build plan) — and returns **JSON**. **`public/js/app.js`** applies that JSON in place:
appends the agent bubble, flips the domain badges, and on completion swaps the right
panel to the server-rendered build plan.

## Files

| File | Owner | Role |
|------|-------|------|
| `public/endpoint.php` | Cox | JSON chain controller + per-session `flock` guard |
| `public/js/app.js` | Port | Submit lockdown (spinner + `inFlight`), single AJAX POST, DOM apply |
| `public/session.php` | Cox | Form → `endpoint.php`, Send spinner, loads `app.js` |
| `tests/endpoint_lock_test.php` | Port | Proves the lock yields single server execution |

## The two-layer race guard

- **Client (app.js):** the instant a submit starts, the input + Send are disabled, a
  spinner shows, and an `inFlight` flag rejects any further submit until the reply
  lands → **one network request per submit.**
- **Server (endpoint.php):** a non-blocking exclusive lock scoped to the session
  (`flock(LOCK_EX | LOCK_NB)`). A second request for the same session while the first
  is mid-flight fails the lock and gets `409 {busy:true}` instead of running the chain
  again → **one server execution per submit.** Two requests can never write
  `domain_state` for one session at the same time.

## Stress test — automated

```
C:\xampp\php\php.exe tests\endpoint_lock_test.php
```

Expected: `5 passed, 0 failed` — asserts a second lock attempt is rejected while the
first is held, then succeeds once released (exactly what the endpoint relies on).

## Stress test — manual (DevTools)

1. Start XAMPP (Apache + MySQL) and open
   `http://localhost/requirement-orchestrator/public/`, then open or start a session.
2. Open DevTools → **Network** tab, filter to `endpoint.php`, tick *Preserve log*.
3. Type an answer and **double-click Send as fast as you can** (or press Enter
   repeatedly during the request).
4. **Confirm one request:** exactly one `endpoint.php` row appears per answer — the
   extra clicks are swallowed by the client lock (Send is disabled + spinning).
5. **Confirm one execution:** the coverage badges advance by exactly one step, and the
   chat shows exactly one new agent reply — no duplicate/again-advanced domains.
6. (Optional, to see the server guard fire) temporarily slow the chain, then issue two
   overlapping requests from two tabs on the same session: the second returns HTTP
   **409** with `{"busy":true}` and does not mutate coverage.

## Notes

- Full page reloads are gone: `session.php`'s form now targets `endpoint.php` and the
  app relies on `app.js` (no no-JS fallback — team decision, FP10).
- Lock files live in the gitignored `sessions/` dir (`<session>.lock`); `flock` is
  released automatically when the request ends.
- The FP6 no-key placeholder still works — `endpoint.php` returns it as JSON so the
  app demos end-to-end without an API key.
