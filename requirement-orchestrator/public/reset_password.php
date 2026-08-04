<?php
/**
 * Reset password — step 2. Reached via the emailed (or demo-displayed) link
 * carrying a one-time token. Validates the token, then takes a new password twice
 * and updates the account. On success the token is burned and the user is sent to
 * the sign-in page.
 */
require_once __DIR__ . '/../src/Auth.php';

if (Auth::check()) { header('Location: index.php'); exit; }

$token = $_POST['token'] ?? $_GET['token'] ?? '';
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $res = Auth::resetPassword($token, $_POST['password'] ?? '', $_POST['password_confirm'] ?? '');
    if ($res['ok']) {
        header('Location: login.php?reset=1');   // success banner shown on sign-in
        exit;
    }
    $error = $res['error'];
}

// Only show the form while the token is still good; otherwise point back to step 1.
$tokenValid = Auth::findUserByResetToken($token) !== null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Choose a new password — Requirement Orchestrator</title>
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
            <h1 class="h4 fw-semibold mb-1">Choose a new password</h1>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-danger py-2 small"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <?php if ($tokenValid): ?>
            <form method="post" class="card card-body shadow-sm border-0">
                <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">
                <label class="form-label small fw-semibold">New password</label>
                <input type="password" name="password" class="form-control mb-3" required minlength="8" autofocus>
                <label class="form-label small fw-semibold">Confirm new password</label>
                <input type="password" name="password_confirm" class="form-control mb-1" required minlength="8">
                <div class="form-text mb-3">Re-enter the same password. At least 8 characters.</div>
                <button class="btn btn-primary w-100" type="submit">Update password&nbsp;→</button>
            </form>
        <?php else: ?>
            <div class="alert alert-warning small">
                This reset link is invalid or has expired. Reset links are valid for one hour.
            </div>
            <a href="forgot_password.php" class="btn btn-primary w-100">Request a new link</a>
        <?php endif; ?>
    </div>
</body>
</html>
