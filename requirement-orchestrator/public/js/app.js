/**
 * FP10 — Async pipeline client (Port).
 *
 * Hijacks the interview form's submit so each answer is sent as a single
 * background POST to endpoint.php instead of a full-page reload. Responsibilities:
 *
 *   1. UI lockdown — disable input + Send + show a spinner the instant a submit
 *      starts, and refuse to fire a second request while one is in flight. This
 *      is the client half of the race-condition guard: without it, a fast double
 *      Send (or Enter-mashing) could launch parallel chain executions that both
 *      write domain_state and corrupt coverage. The server half is endpoint.php's
 *      per-session flock (returns 409 {busy:true}).
 *
 *      session.php now renders the input and Send button disabled and this script
 *      unlocks them, so the form is never live before the handler below is bound.
 *      Every submit also carries a client_msg_id: if a duplicate reaches the server
 *      anyway, endpoint.php replays the first response rather than re-running the
 *      chain. Three layers, because the lock alone only covers overlap.
 *   2. Apply the JSON reply in place — append the agent bubble, flip the domain
 *      badges via the matrix partial's setDomainState(), and on completion swap
 *      the right panel to the server-rendered build plan.
 *
 * Requires jQuery (already on the page) and the matrix partial's global
 * setDomainState()/updateProgress() helpers. Loaded after both.
 */
(function () {
    'use strict';

    var form       = document.getElementById('input-area');   // the <form>
    if (!form) { return; }                                     // not the workspace page

    var input      = form.querySelector('input[name="message"]');
    var keyField   = document.getElementById('api_key_field');
    var sendBtn    = form.querySelector('button[type="submit"]');
    var chatStream = document.getElementById('chat-stream');
    var rightPanel = document.getElementById('right-panel');

    var inFlight = false;   // client-side lock: at most one request at a time.

    // ── DOM helpers ─────────────────────────────────────────────────────────

    /** Append a chat bubble matching session.php's server-rendered markup. */
    function appendBubble(role, text) {
        var row = document.createElement('div');
        row.className = 'bubble-row ' + role;

        var label = document.createElement('div');
        label.className = 'bubble-label';
        label.textContent = role === 'user' ? 'You' : 'Agent';

        var bubble = document.createElement('div');
        bubble.className = 'bubble bubble-' + role;
        bubble.textContent = text;   // textContent = XSS-safe, mirrors htmlspecialchars

        row.appendChild(label);
        row.appendChild(bubble);
        chatStream.appendChild(row);
        chatStream.scrollTop = chatStream.scrollHeight;
        return bubble;
    }

    /** Lock or unlock the input surface and toggle the Send spinner. */
    function setBusy(busy) {
        inFlight = busy;
        input.disabled  = busy;
        sendBtn.disabled = busy;
        sendBtn.classList.toggle('is-loading', busy);
        if (!busy) { input.focus(); }
    }

    /**
     * Re-execute <script> tags inside a freshly-injected fragment. innerHTML does
     * not run scripts, so this revives the build-plan partial's own copy/download
     * logic (FP9) after we swap it into the right panel — no duplicated JS here.
     */
    function runScripts(container) {
        container.querySelectorAll('script').forEach(function (old) {
            var s = document.createElement('script');
            if (old.src) { s.src = old.src; } else { s.textContent = old.textContent; }
            old.parentNode.replaceChild(s, old);
        });
    }

    /**
     * Identify one submission, so the server can tell a duplicate from a new answer.
     * randomUUID() where available; the fallback only needs to be unique within a
     * session's dedupe window, not cryptographically strong.
     */
    function newMessageId() {
        if (window.crypto && typeof crypto.randomUUID === 'function') {
            return crypto.randomUUID();
        }
        return 'm-' + Date.now() + '-' + Math.random().toString(16).slice(2, 10);
    }

    /** Flip every domain badge to match the server's authoritative domain_state. */
    function applyDomainState(state) {
        if (!state || typeof setDomainState !== 'function') { return; }
        Object.keys(state).forEach(function (domain) {
            setDomainState(domain, state[domain]);
        });
    }

    // ── Submit flow ─────────────────────────────────────────────────────────

    form.addEventListener('submit', function (e) {
        e.preventDefault();

        if (inFlight) { return; }                 // hard stop on double-submit
        var message = (input.value || '').trim();
        if (!message) { return; }

        // Sync the key from sessionStorage in case the modal set it this session.
        if (keyField && !keyField.value) {
            keyField.value = sessionStorage.getItem('api_key') || '';
        }

        setBusy(true);
        appendBubble('user', message);
        input.value = '';

        var body = new FormData(form);
        body.set('message', message);             // FormData snapshots the now-cleared input
        body.set('client_msg_id', newMessageId());

        fetch('endpoint.php', { method: 'POST', body: body })
            .then(function (res) {
                if (res.status === 409) {
                    // Server rejected an overlap — should be unreachable behind the
                    // client lock, but handle it rather than swallow.
                    return { busy: true };
                }
                return res.json();
            })
            .then(function (data) {
                if (!data || data.busy) { return; }
                if (!data.ok) {
                    appendBubble('agent', 'Something went wrong processing that answer. Please try again.');
                    return;
                }

                appendBubble('agent', data.agent_message);
                applyDomainState(data.domain_state);

                if (data.done) {
                    form.dataset.done = '1';      // keep the input locked for good
                    sendBtn.classList.remove('is-loading');   // disabled, not spinning
                    if (data.plan_html) {
                        rightPanel.innerHTML = data.plan_html;
                        runScripts(rightPanel);   // rebind copy/download (FP9)
                    }
                }
            })
            .catch(function () {
                appendBubble('agent', 'Network error — your answer was not saved. Please try again.');
            })
            .finally(function () {
                if (form.dataset.done === '1') { return; }   // stay locked once complete
                setBusy(false);
            });
    });

    // ── Unlock ──────────────────────────────────────────────────────────────
    // The submit handler is bound, so every click is managed from here on. Only now
    // do the controls session.php rendered disabled become usable — and never for a
    // session that is already complete.
    if (form.dataset.done !== '1') { setBusy(false); }
})();
