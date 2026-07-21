<?php
/**
 * Landing screen — entry point.
 * API key is handled entirely client-side (sessionStorage) — never stored in
 * PHP session or on disk. Entering a key here stores it only in the browser tab's
 * sessionStorage, which is cleared when the tab or window is closed.
 */
require_once __DIR__ . '/../src/InterviewSession.php';
require_once __DIR__ . '/../src/LlmClient.php';

$mockMode = LlmClientFactory::isMock();
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
        <span class="navbar-text text-white-50 small d-none d-sm-inline">AI Software Build-Plan Generator</span>
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
                            Accepts Anthropic (Claude) or OpenAI (ChatGPT) keys. Stored only in this
                            browser tab — cleared when you close the tab or window.
                        </div>
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

        // On load: if key already in sessionStorage, skip the gate.
        if (sessionStorage.getItem('api_key')) { showMain(); }

        form.addEventListener('submit', function (e) {
            e.preventDefault();
            var key = input.value.trim();
            if (!key) { errMsg.style.display = ''; return; }
            errMsg.style.display = 'none';
            sessionStorage.setItem('api_key', key);
            showMain();
        });

        if (clearBtn) {
            clearBtn.addEventListener('click', showGate);
        }
        <?php endif; ?>
    })();
    </script>
</body>
</html>
