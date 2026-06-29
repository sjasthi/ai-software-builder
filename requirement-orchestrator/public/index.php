<?php
// Requirement Orchestrator — public entry point
// Session init and token assignment will be wired here in FP6 (InterviewSession.php)
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Requirement Orchestrator</title>
    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >
    <style>
        html, body { height: 100%; margin: 0; }

        #app {
            height: 100vh;
            display: flex;
            flex-direction: column;
        }

        /* ── Left panel ─────────────────────────────────────── */
        #chat-panel {
            display: flex;
            flex-direction: column;
            height: 100%;
            min-height: 0;
            background: #fff;
        }

        #chat-stream {
            flex: 1;
            overflow-y: auto;
            padding: 1.25rem;
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
        }

        .bubble-row        { display: flex; flex-direction: column; }
        .bubble-row.user   { align-items: flex-end; }
        .bubble-row.agent  { align-items: flex-start; }

        .bubble-label {
            font-size: 0.68rem;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            color: #6c757d;
            margin-bottom: 0.2rem;
            padding: 0 0.25rem;
        }

        .bubble {
            max-width: 74%;
            padding: 0.65rem 1rem;
            border-radius: 1.1rem;
            line-height: 1.55;
            font-size: 0.95rem;
            word-wrap: break-word;
        }

        .bubble-agent {
            background: #f1f3f5;
            color: #212529;
            border-bottom-left-radius: 0.3rem;
        }

        .bubble-user {
            background: #0d6efd;
            color: #fff;
            border-bottom-right-radius: 0.3rem;
        }

        #input-area {
            flex-shrink: 0;
            border-top: 1px solid #dee2e6;
            padding: 0.85rem 1.25rem;
            background: #fff;
        }

        /* ── Right panel ────────────────────────────────────── */
        #right-panel {
            display: flex;
            flex-direction: column;
            height: 100%;
            background: #f8f9fa;
            border-left: 1px solid #dee2e6;
            overflow-y: auto;
        }

        /* ── Spinner inside Send button ─────────────────────── */
        .spinner-border-sm { width: 0.9rem; height: 0.9rem; border-width: 0.15em; }
    </style>
</head>
<body>
<div id="app">

    <!-- ── Top navbar ──────────────────────────────────────── -->
    <nav class="navbar navbar-dark bg-dark px-3 py-2 flex-shrink-0">
        <span class="navbar-brand mb-0 fw-semibold" style="font-size:1rem;">
            Requirement Orchestrator
        </span>
        <span class="badge bg-secondary" id="session-badge">New Session</span>
    </nav>

    <!-- ── Split pane ──────────────────────────────────────── -->
    <div class="row g-0 flex-grow-1 overflow-hidden">

        <!-- Left: Chat Interface (Cox — FP5) -->
        <div class="col-12 col-md-7 h-100" id="chat-panel">

            <div id="chat-stream">
                <!-- Initial agent greeting -->
                <div class="bubble-row agent">
                    <div class="bubble-label">Agent</div>
                    <div class="bubble bubble-agent">
                        Hello! I'm going to ask you a series of questions to help
                        define your software project. Let's start — what specific
                        problem are you trying to solve?
                    </div>
                </div>
            </div>

            <div id="input-area">
                <div class="input-group">
                    <input
                        type="text"
                        id="user-input"
                        class="form-control"
                        placeholder="Type your answer…"
                        autocomplete="off"
                        aria-label="Your answer"
                    >
                    <button id="send-btn" class="btn btn-primary px-4" type="button">
                        Send&nbsp;→
                    </button>
                </div>
            </div>

        </div>

        <!-- Right: Domain Coverage Matrix (Port — FP5) -->
        <div class="col-12 col-md-5 h-100" id="right-panel">
            <?php include __DIR__ . '/partials/domain_matrix.php'; ?>
        </div>

    </div><!-- /row -->

</div><!-- /app -->

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
    // Left-panel wiring.
    // Full AJAX chain (endpoint.php) implemented in FP9; domain badge updates in app.js (FP9).
    const chatStream = document.getElementById('chat-stream');
    const userInput  = document.getElementById('user-input');
    const sendBtn    = document.getElementById('send-btn');

    function appendBubble(text, role) {
        const row    = document.createElement('div');
        const label  = document.createElement('div');
        const bubble = document.createElement('div');

        row.className    = `bubble-row ${role}`;
        label.className  = 'bubble-label';
        label.textContent = role === 'user' ? 'You' : 'Agent';
        bubble.className  = `bubble bubble-${role}`;
        bubble.textContent = text;

        row.appendChild(label);
        row.appendChild(bubble);
        chatStream.appendChild(row);
        chatStream.scrollTop = chatStream.scrollHeight;
    }

    function setLoading(on) {
        sendBtn.disabled   = on;
        userInput.disabled = on;
        sendBtn.innerHTML  = on
            ? '<span class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span>…'
            : 'Send&nbsp;→';
    }

    function sendMessage() {
        const text = userInput.value.trim();
        if (!text) return;

        appendBubble(text, 'user');
        userInput.value = '';
        setLoading(true);

        fetch('endpoint.php', {
            method:  'POST',
            headers: { 'Content-Type': 'application/json' },
            body:    JSON.stringify({ message: text }),
        })
        .then(r => r.json())
        .then(data => {
            appendBubble(data.response ?? '[no response field]', 'agent');
            // domain badge updates wired in app.js (Jaffer — FP9)
        })
        .catch(() => {
            appendBubble('(endpoint.php not yet connected — FP9)', 'agent');
        })
        .finally(() => {
            setLoading(false);
            userInput.focus();
        });
    }

    sendBtn.addEventListener('click', sendMessage);
    userInput.addEventListener('keydown', e => {
        if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); sendMessage(); }
    });
</script>
</body>
</html>
