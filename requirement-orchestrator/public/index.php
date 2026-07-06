<?php
/**
 * Landing screen (Port, FP6) — the wizard's entry point.
 * Shows previous sessions (read from the saved .json files) and a New Session
 * button. Picking a session opens it in the workspace; New Session starts fresh.
 *
 * The workspace itself lives in session.php.
 */
require_once __DIR__ . '/../src/InterviewSession.php';
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
            <a href="new_session.php" class="btn btn-primary btn-lg px-4">
                <span class="me-1">＋</span> Start New Session
            </a>
        </div>

        <h2 class="h6 text-uppercase text-muted mb-2">Previous Sessions</h2>
        <?php include __DIR__ . '/partials/session_list.php'; ?>
    </div>

    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
