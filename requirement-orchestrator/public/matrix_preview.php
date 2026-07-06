<?php
/**
 * FP5 preview harness (Port) — standalone page to verify the right domain
 * matrix panel renders and stays responsive at 1440 / 768 / 375px.
 *
 * This is NOT the production page. The real public/index.php (split-pane
 * skeleton + left chat pane) is Cox's FP5 deliverable; this harness just hosts
 * Port's partial in a right-panel-sized container so the breakpoints can be
 * checked independently. Cox's index.php will include the same partial via:
 *     include __DIR__ . '/partials/domain_matrix.php';
 */
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>FP5 Preview — Domain Matrix Panel</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background: #f1f3f5; }
        /* Mimic the right panel's slot in the real split-pane so widths match. */
        .matrix-slot { background: #f8f9fa; border-left: 1px solid #dee2e6; min-height: 100vh; }
        @media (max-width: 991.98px) {
            .matrix-slot { border-left: none; border-top: 1px solid #dee2e6; }
        }
    </style>
</head>
<body>
<div class="container-fluid px-0">
    <div class="row g-0">
        <!-- Left column is just a placeholder here so the right panel sits where
             it will in Cox's split-pane (col-lg-5). -->
        <div class="col-12 col-lg-7 d-none d-lg-block"></div>
        <div class="col-12 col-lg-5 matrix-slot">
            <?php include __DIR__ . '/partials/domain_matrix.php'; ?>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
