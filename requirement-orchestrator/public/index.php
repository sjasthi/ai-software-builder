<?php
/**
 * Landing screen — entry point.
 * API key is handled entirely client-side (sessionStorage) — never stored in
 * PHP session or on disk. Entering a key here stores it only in the browser tab's
 * sessionStorage, which is cleared when the tab or window is closed.
 */
require_once __DIR__ . '/../src/InterviewSession.php';
require_once __DIR__ . '/../src/LlmClient.php';
require_once __DIR__ . '/../src/Auth.php';

Auth::requireLogin();

$mockMode    = LlmClientFactory::isMock();
$username    = Auth::currentUsername();
$keyStorage  = Auth::keyStorageAvailable();          // is "remember my key" offered?
$savedKey    = $keyStorage ? Auth::getApiKey() : null;  // decrypted, in-memory only
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Requirement Orchestrator</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background: #f1f3f5; }
        .landing-wrap { max-width: 640px; margin: 0 auto; padding: 3rem 1rem 4rem; }
        #key-gate, #main-ui { transition: opacity .2s; }
        #main-ui.hidden { display: none; }
    </style>
</head>
<body>
    <nav class="navbar navbar-dark bg-dark px-3 py-2">
        <span class="navbar-brand mb-0 fw-semibold" style="font-size:1rem;">Requirement Orchestrator</span>
        <div class="d-flex align-items-center gap-3">
            <span class="navbar-text text-white-50 small d-none d-sm-inline">
                Signed in as <span class="text-white fw-semibold"><?= htmlspecialchars((string) $username) ?></span>
            </span>
            <a href="logout.php" class="btn btn-outline-light btn-sm">Log out</a>
        </div>
    </nav>

    <div class="landing-wrap">
        <div class="text-center mb-4">
            <h1 class="h4 fw-semibold">Start building your plan</h1>
            <p class="text-muted">Answer a short interview and get a sequenced build plan you can paste into any AI coding tool.</p>

            <?php if ($mockMode): ?>
                <!-- Mock mode: no key needed, show main UI immediately -->
                <div id="key-gate" style="display:none;"></div>
                <div id="main-ui">
                    <a href="new_session.php" class="btn btn-primary btn-lg px-4">
                        <span class="me-1">＋</span> Start New Session
                    </a>
                    <div class="small text-muted mt-2">Running in free demo mode — no API key required.</div>
                </div>
            <?php else: ?>
                <!-- Key gate: shown until user enters a key -->
                <div id="key-gate">
                    <form id="key-form" class="mx-auto text-start" style="max-width:440px;">
                        <label for="api_key_input" class="form-label small fw-semibold">Enter your API key to begin</label>
                        <div class="input-group">
                            <input type="password" id="api_key_input" class="form-control"
                                   placeholder="sk-ant-... or sk-..." autocomplete="off" required>
                            <button class="btn btn-primary" type="submit">Begin&nbsp;→</button>
                        </div>
                        <div class="form-text">
                            Accepts Anthropic (Claude) or OpenAI (ChatGPT) keys. By default stored only in
                            this browser tab — cleared when you close the tab or window.
                        </div>
                        <?php if ($keyStorage): ?>
                        <div class="form-check mt-2">
                            <input class="form-check-input" type="checkbox" id="remember_key">
                            <label class="form-check-label small" for="remember_key">
                                Remember my key on this account (stored encrypted)
                            </label>
                        </div>
                        <?php endif; ?>
                        <div id="key-error" class="text-danger small mt-1" style="display:none;">Please enter a valid API key.</div>
                    </form>
                </div>

                <!-- Main UI: hidden until key is entered -->
                <div id="main-ui" class="hidden">
                    <a href="new_session.php" class="btn btn-primary btn-lg px-4">
                        <span class="me-1">＋</span> Start New Session
                    </a>
                    <div class="small text-muted mt-2">
                        API key set for this tab ✓
                        <button id="clear-key-btn" class="btn btn-link btn-sm p-0 align-baseline ms-1">clear key</button>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <h2 class="h6 text-uppercase text-muted mb-2">Previous Sessions</h2>
        <?php include __DIR__ . '/partials/session_list.php'; ?>
    </div>

    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    (function () {
        <?php if (!$mockMode): ?>
        var gate   = document.getElementById('key-gate');
        var mainUi = document.getElementById('main-ui');
        var form   = document.getElementById('key-form');
        var input  = document.getElementById('api_key_input');
        var errMsg = document.getElementById('key-error');
        var clearBtn = document.getElementById('clear-key-btn');

        function showMain() {
            gate.style.display   = 'none';
            mainUi.classList.remove('hidden');
        }
        function showGate() {
            gate.style.display   = '';
            mainUi.classList.add('hidden');
            sessionStorage.removeItem('api_key');
        }

        var rememberBox = document.getElementById('remember_key');
        // Key saved to this account (decrypted server-side), or '' if none.
        var savedKey = <?= json_encode($savedKey ?? '') ?>;

        function persistPreference(key, remember) {
            // Tell the server to store (encrypted) or forget the key for this account.
            try {
                var body = new URLSearchParams();
                body.set('remember', remember ? '1' : '0');
                if (remember) { body.set('api_key', key); }
                fetch('save_key.php', { method: 'POST', body: body });
            } catch (e) { /* non-fatal: key still works for this tab */ }
        }

        // On load: prefer a key already in this tab; otherwise fall back to the
        // account's saved key so returning users skip the gate entirely.
        if (sessionStorage.getItem('api_key')) {
            showMain();
        } else if (savedKey) {
            sessionStorage.setItem('api_key', savedKey);
            if (rememberBox) { rememberBox.checked = true; }
            showMain();
        }

        form.addEventListener('submit', function (e) {
            e.preventDefault();
            var key = input.value.trim();
            if (!key) { errMsg.style.display = ''; return; }
            errMsg.style.display = 'none';
            sessionStorage.setItem('api_key', key);
            if (rememberBox) { persistPreference(key, rememberBox.checked); }
            showMain();
        });

        if (clearBtn) {
            clearBtn.addEventListener('click', function () {
                // "Clear key" forgets it everywhere: this tab AND the account.
                if (rememberBox) { rememberBox.checked = false; persistPreference('', false); }
                showGate();
            });
        }
        <?php endif; ?>
    })();
    </script>
</body>
</html>
