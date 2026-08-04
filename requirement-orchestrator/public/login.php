<?php
/**
 * Landing page — login / register.
 *
 * This is the app's public entry point now that a login is required. Already
 * logged in? Bounce straight to the workspace. Two tabbed forms (Sign in /
 * Create account) POST back here; on success we redirect to ?next (or index.php).
 */
require_once __DIR__ . '/../src/Auth.php';

// Where to send the user after auth. Only allow local relative paths (no open redirect).
$next = $_GET['next'] ?? $_POST['next'] ?? 'index.php';
if (!preg_match('#^[A-Za-z0-9_./?=&%-]+$#', $next) || str_starts_with($next, '//')) {
    $next = 'index.php';
}

if (Auth::check()) {
    header('Location: ' . $next);
    exit;
}

$error  = null;
$notice = ($_GET['reset'] ?? '') === '1'
    ? 'Your password has been updated. Please sign in.'
    : null;
$mode   = $_POST['mode'] ?? 'login';          // which tab to show / which form was posted
$prefill      = trim($_POST['username'] ?? '');
$prefillEmail = trim($_POST['email'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';

    if ($mode === 'register') {
        $email           = $_POST['email'] ?? '';
        $passwordConfirm = $_POST['password_confirm'] ?? '';
        $res = Auth::register($username, $email, $password, $passwordConfirm);
        if ($res['ok']) {
            Auth::login($username, $password);   // auto-login the new account
            header('Location: ' . $next);
            exit;
        }
        $error = $res['error'];
    } else {
        $res = Auth::login($username, $password);
        if ($res['ok']) {
            header('Location: ' . $next);
            exit;
        }
        $error = $res['error'];
    }
}

$showRegister = ($mode === 'register');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sign in — Requirement Orchestrator</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background: #f1f3f5; }
        .auth-wrap { max-width: 420px; margin: 0 auto; padding: 3.5rem 1rem 4rem; }
        .brand-mark { font-weight: 600; }
    </style>
</head>
<body>
    <nav class="navbar navbar-dark bg-dark px-3 py-2">
        <span class="navbar-brand mb-0 brand-mark" style="font-size:1rem;">Requirement Orchestrator</span>
        <span class="navbar-text text-white-50 small d-none d-sm-inline">AI Software Build-Plan Generator</span>
    </nav>

    <div class="auth-wrap">
        <div class="text-center mb-4">
            <h1 class="h4 fw-semibold mb-1">Welcome</h1>
            <p class="text-muted small mb-0">Sign in to see your sessions and build plans.</p>
        </div>

        <ul class="nav nav-pills nav-justified mb-3" id="authTabs" role="tablist">
            <li class="nav-item" role="presentation">
                <button class="nav-link <?= $showRegister ? '' : 'active' ?>" id="login-tab"
                        data-bs-toggle="pill" data-bs-target="#login-pane" type="button" role="tab">Sign in</button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link <?= $showRegister ? 'active' : '' ?>" id="register-tab"
                        data-bs-toggle="pill" data-bs-target="#register-pane" type="button" role="tab">Create account</button>
            </li>
        </ul>

        <?php if ($notice): ?>
            <div class="alert alert-success py-2 small"><?= htmlspecialchars($notice) ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-danger py-2 small"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <div class="tab-content">
            <!-- Sign in -->
            <div class="tab-pane fade <?= $showRegister ? '' : 'show active' ?>" id="login-pane" role="tabpanel">
                <form method="post" class="card card-body shadow-sm border-0">
                    <input type="hidden" name="mode" value="login">
                    <input type="hidden" name="next" value="<?= htmlspecialchars($next) ?>">
                    <label class="form-label small fw-semibold">Username</label>
                    <input type="text" name="username" class="form-control mb-3" required autofocus
                           value="<?= $showRegister ? '' : htmlspecialchars($prefill) ?>">
                    <label class="form-label small fw-semibold">Password</label>
                    <input type="password" name="password" class="form-control mb-3" required>
                    <button class="btn btn-primary w-100" type="submit">Sign in&nbsp;→</button>
                    <div class="text-center mt-3">
                        <a href="forgot_password.php" class="small">Forgot your password?</a>
                    </div>
                </form>
            </div>

            <!-- Create account -->
            <div class="tab-pane fade <?= $showRegister ? 'show active' : '' ?>" id="register-pane" role="tabpanel">
                <form method="post" class="card card-body shadow-sm border-0">
                    <input type="hidden" name="mode" value="register">
                    <input type="hidden" name="next" value="<?= htmlspecialchars($next) ?>">
                    <label class="form-label small fw-semibold">Choose a username</label>
                    <input type="text" name="username" class="form-control mb-1" required
                           value="<?= $showRegister ? htmlspecialchars($prefill) : '' ?>">
                    <div class="form-text mb-3">3–64 characters: letters, numbers, and . _ -</div>
                    <label class="form-label small fw-semibold">Email</label>
                    <input type="email" name="email" class="form-control mb-1" required
                           value="<?= $showRegister ? htmlspecialchars($prefillEmail) : '' ?>">
                    <div class="form-text mb-3">Used to recover your account if you forget your password.</div>
                    <label class="form-label small fw-semibold">Choose a password</label>
                    <input type="password" name="password" class="form-control mb-3" required minlength="8">
                    <label class="form-label small fw-semibold">Confirm password</label>
                    <input type="password" name="password_confirm" class="form-control mb-1" required minlength="8">
                    <div class="form-text mb-3">Re-enter the same password. Must be at least 8 characters.</div>
                    <button class="btn btn-success w-100" type="submit">Create account&nbsp;→</button>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
