<?php
/**
 * Workspace (FP6) — the split-pane interview screen, opened as session.php?id=<id>.
 *
 * Ownership:
 *   - Left chat panel markup + bubble styles: Cox (FP5) — reused here as-is.
 *   - Right domain-matrix panel: Port (FP5) via partials/domain_matrix.php.
 *   - Hamburger drawer + previous-session loading + persistence wiring: Port (FP6).
 *
 * Loads the session from its .json file, renders the saved transcript and the
 * live domain state, and persists each answer via post_message.php.
 */
require_once __DIR__ . '/../src/InterviewSession.php';

$id      = $_GET['id'] ?? '';
$session = InterviewSession::readSession($id);
if ($session === null) {                 // bad/old id → back to the landing list
    header('Location: index.php');
    exit;
}

$complete     = ($session['status'] ?? 'in_progress') === 'complete';
$domainState  = $session['domain_state'];   // consumed by the matrix partial
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($session['title']) ?> — Requirement Orchestrator</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        /* ── Chat panel styles: Cox (FP5) ─────────────────────── */
        html, body { height: 100%; margin: 0; }
        #app { height: 100vh; display: flex; flex-direction: column; }

        #chat-panel { display: flex; flex-direction: column; height: 100%; min-height: 0; background: #fff; }
        #chat-stream { flex: 1; overflow-y: auto; padding: 1.25rem; display: flex; flex-direction: column; gap: 0.5rem; }

        .bubble-row        { display: flex; flex-direction: column; }
        .bubble-row.user   { align-items: flex-end; }
        .bubble-row.agent  { align-items: flex-start; }
        .bubble-label { font-size: 0.68rem; text-transform: uppercase; letter-spacing: 0.06em; color: #6c757d; margin-bottom: 0.2rem; padding: 0 0.25rem; }
        .bubble { max-width: 74%; padding: 0.65rem 1rem; border-radius: 1.1rem; line-height: 1.55; font-size: 0.95rem; word-wrap: break-word; }
        .bubble-agent { background: #f1f3f5; color: #212529; border-bottom-left-radius: 0.3rem; }
        .bubble-user  { background: #0d6efd; color: #fff;     border-bottom-right-radius: 0.3rem; }

        #input-area { flex-shrink: 0; border-top: 1px solid #dee2e6; padding: 0.85rem 1.25rem; background: #fff; }

        #right-panel { display: flex; flex-direction: column; height: 100%; background: #f8f9fa; border-left: 1px solid #dee2e6; overflow-y: auto; }
        @media (max-width: 767.98px) { #right-panel { border-left: none; border-top: 1px solid #dee2e6; } }
    </style>
</head>
<body>
<div id="app">

    <!-- ── Top navbar: hamburger (drawer) + brand + New Session ── -->
    <nav class="navbar navbar-dark bg-dark px-3 py-2 flex-shrink-0">
        <div class="d-flex align-items-center gap-2">
            <button class="btn btn-dark btn-sm border-0" type="button"
                    data-bs-toggle="offcanvas" data-bs-target="#sessionsDrawer" aria-label="Previous sessions">
                <span style="font-size:1.2rem; line-height:1;">&#9776;</span>
            </button>
            <span class="navbar-brand mb-0 fw-semibold" style="font-size:1rem;">Requirement Orchestrator</span>
        </div>
        <a href="new_session.php" class="btn btn-outline-light btn-sm">＋ New Session</a>
    </nav>

    <!-- ── Hamburger drawer: previous sessions (Bootstrap Offcanvas) ── -->
    <div class="offcanvas offcanvas-start" tabindex="-1" id="sessionsDrawer" aria-labelledby="sessionsDrawerLabel">
        <div class="offcanvas-header">
            <h5 class="offcanvas-title" id="sessionsDrawerLabel">Sessions</h5>
            <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Close"></button>
        </div>
        <div class="offcanvas-body">
            <a href="new_session.php" class="btn btn-primary w-100 mb-3">＋ New Session</a>
            <?php $activeId = $id; include __DIR__ . '/partials/session_list.php'; ?>
        </div>
    </div>

    <!-- ── Split pane ──────────────────────────────────────────── -->
    <div class="row g-0 flex-grow-1 overflow-hidden">

        <!-- Left: Chat Interface (Cox — FP5) -->
        <div class="col-12 col-md-7 h-100" id="chat-panel">
            <div id="chat-stream">
                <?php foreach ($session['transcript'] as $m):
                    $role = ($m['role'] ?? 'agent') === 'user' ? 'user' : 'agent'; ?>
                    <div class="bubble-row <?= $role ?>">
                        <div class="bubble-label"><?= $role === 'user' ? 'You' : 'Agent' ?></div>
                        <div class="bubble bubble-<?= $role ?>"><?= htmlspecialchars($m['content'] ?? '') ?></div>
                    </div>
                <?php endforeach; ?>
            </div>

            <form id="input-area" method="post" action="post_message.php" autocomplete="off">
                <input type="hidden" name="id" value="<?= htmlspecialchars($id) ?>">
                <div class="input-group">
                    <input type="text" name="message" class="form-control" placeholder="Type your answer…"
                           aria-label="Your answer" <?= $complete ? 'disabled' : 'autofocus' ?>>
                    <button class="btn btn-primary px-4" type="submit" <?= $complete ? 'disabled' : '' ?>>Send&nbsp;→</button>
                </div>
                <?php if ($complete): ?>
                    <small class="text-success">All 8 areas covered — your build plan is on the right.</small>
                <?php endif; ?>
            </form>
        </div>

        <!-- Right: matrix while in progress → build plan when complete -->
        <div class="col-12 col-md-5 h-100" id="right-panel">
            <?php if ($complete): ?>
                <?php include __DIR__ . '/partials/build_plan.php'; ?>
            <?php else: ?>
                <?php include __DIR__ . '/partials/domain_matrix.php';  // Port — FP5 ?>
            <?php endif; ?>
        </div>

    </div><!-- /row -->
</div><!-- /app -->

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
    // Keep the chat scrolled to the latest message.
    var cs = document.getElementById('chat-stream');
    if (cs) { cs.scrollTop = cs.scrollHeight; }
</script>
</body>
</html>
