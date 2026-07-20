<?php
/**
 * Landing screen (Port, FP6) — the wizard's entry point.
 * Shows previous sessions (read from the saved .json files) and a New Session
 * button. Picking a session opens it in the workspace; New Session starts fresh.
 *
 * FP8: each visitor supplies their own AI key here to begin. The key lives only
 * in the server session (never saved to disk); the saved interviews below are a
 * separate thing and persist as .json files as always.
 *
 * The workspace itself lives in session.php.
 */
session_start();
require_once __DIR__ . '/../src/InterviewSession.php';
require_once __DIR__ . '/../src/LlmClient.php';

$hasSessionKey = !empty($_SESSION['api_key']);
$aiReady       = LlmClientFactory::isMock() || LlmClientFactory::hasKey();
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

            <?php if ($aiReady): ?>
                <a href="new_session.php" class="btn btn-primary btn-lg px-4">
                    <span class="me-1">＋</span> Start New Session
                </a>
                <div class="small text-muted mt-2">
                    <?php if (LlmClientFactory::isMock()): ?>
                        Running in free demo mode — no API key used.
                    <?php elseif ($hasSessionKey): ?>
                        AI key set for this session ✓
                        <form action="set_key.php" method="post" class="d-inline ms-1">
                            <input type="hidden" name="action" value="clear">
                            <button class="btn btn-link btn-sm p-0 align-baseline" type="submit">clear key</button>
                        </form>
                    <?php else: ?>
                        Using the server's configured AI key.
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <form action="set_key.php" method="post" class="mx-auto text-start" style="max-width:440px;">
                    <label for="api_key" class="form-label small fw-semibold">Enter your Anthropic API key to begin</label>
                    <div class="input-group">
                        <input type="password" name="api_key" id="api_key" class="form-control"
                               placeholder="sk-ant-..." autocomplete="off" required>
                        <button class="btn btn-primary" type="submit">Begin&nbsp;→</button>
                    </div>
                    <div class="form-text">
                        Your key is held only for this browser session and is never saved to disk
                        or shown in your saved interviews. (For a no-key demo, run in mock mode — see demoFP8.md.)
                    </div>
                </form>
            <?php endif; ?>
        </div>

        <h2 class="h6 text-uppercase text-muted mb-2">Previous Sessions</h2>
        <?php include __DIR__ . '/partials/session_list.php'; ?>
    </div>

    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
