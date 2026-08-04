<?php
/**
 * Forgot password — step 1. Enter your email; we create a one-time reset token and
 * either email the link (when APP_MAIL_ENABLED=1) or, in local demo mode, show the
 * link right here so it can be clicked without any mail setup.
 *
 * The response is intentionally the same whether or not the email has an account,
 * so this page can't be used to discover who has registered (no user enumeration).
 */
require_once __DIR__ . '/../src/Auth.php';

if (Auth::check()) { header('Location: index.php'); exit; }

$submitted = false;
$devLink   = null;
$error     = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = $_POST['email'] ?? '';
    $res   = Auth::createPasswordReset($email);
    if ($res['ok']) {
        $submitted = true;
        $devLink   = $res['dev_link'] ?? null;   // populated only in local/demo mode
    } else {
        $error = $res['error'];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset password — Requirement Orchestrator</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background: #f1f3f5; }
        .auth-wrap { max-width: 420px; margin: 0 auto; padding: 3.5rem 1rem 4rem; }
    </style>
</head>
<body>
    <nav class="navbar navbar-dark bg-dark px-3 py-2">
        <span class="navbar-brand mb-0 fw-semibold" style="font-size:1rem;">Requirement Orchestrator</span>
    </nav>

    <div class="auth-wrap">
        <div class="text-center mb-4">
            <h1 class="h4 fw-semibold mb-1">Forgot your password?</h1>
            <p class="text-muted small mb-0">Enter your account email and we'll send you a reset link.</p>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-danger py-2 small"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <?php if ($submitted): ?>
            <div class="alert alert-success small">
                If an account exists for that email, a password-reset link has been sent.
                The link is valid for one hour.
            </div>
            <?php if ($devLink): ?>
                <div class="alert alert-info small">
                    <strong>Demo mode:</strong> email isn't configured, so use this link directly:<br>
                    <a href="<?= htmlspecialchars($devLink) ?>" class="text-break"><?= htmlspecialchars($devLink) ?></a>
                </div>
            <?php endif; ?>
            <a href="login.php" class="btn btn-outline-secondary w-100">← Back to sign in</a>
        <?php else: ?>
            <form method="post" class="card card-body shadow-sm border-0">
                <label class="form-label small fw-semibold">Email</label>
                <input type="email" name="email" class="form-control mb-3" required autofocus>
                <button class="btn btn-primary w-100" type="submit">Send reset link&nbsp;→</button>
                <div class="text-center mt-3">
                    <a href="login.php" class="small">Back to sign in</a>
                </div>
            </form>
        <?php endif; ?>
    </div>
</body>
</html>
