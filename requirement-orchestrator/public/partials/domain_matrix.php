<?php
/**
 * Right Domain-Coverage Matrix Panel  —  FP5 deliverable (Port)
 *
 * Per weekly_deliverable_plan.xlsx (Week 7 / FP5), Port owns ONLY this panel:
 * the 8 data-domain badges + progress bar. The split-pane skeleton and the left
 * chat pane are Cox's FP5 deliverable.
 *
 * This is a self-contained, drop-in component. Cox's public/index.php includes
 * it inside the right panel container:
 *
 *     <?php include __DIR__ . '/partials/domain_matrix.php'; ?>
 *
 * Optional: the host page may define $domainState before including this file
 * (key => 'COVERED'|'OPEN') to seed live state. With nothing passed, it renders
 * the FP5 demo states. Live state from the DB gets wired in at FP6.
 *
 * Requires jQuery on the host page (already part of the mandated stack) for the
 * setDomainState()/updateProgress() helpers below.
 *
 * ── EXTENSION POINTS (how the later plan items plug into this, per the xlsx) ──
 *   FP6  (live state):  pass $domainState read from the DB (domain_state JSON)
 *                       instead of the demo defaults — keys already match.
 *   FP10 (pipeline):    public/js/app.js calls setDomainState(domain, state)
 *                       after each AJAX response to flip badges live. Those two
 *                       functions are the only hooks it needs; nothing else changes.
 *   FP9  (completion):  when all 8 are COVERED, the host swaps the contents of
 *                       #domain-matrix-panel for the 5-prompt build plan view.
 * Build on these — don't rewrite the panel.
 */

// The 8 architectural requirement domains (key => label).
// Keys match the Extraction Agent JSON schema (Big Picture Plan §3a) so live
// domain_state JSON maps straight onto these badges with no translation.
$matrixDomains = [
    'pain_points'       => 'Pain Points',
    'data_sources'      => 'Data Sources',
    'data_access'       => 'Data Access',
    'end_result'        => 'End Result',
    'stakeholders'      => 'Stakeholders',
    'audience_type'     => 'Audience Type',
    'current_process'   => 'Current Process',
    'interaction_model' => 'Interaction Model',
];

// Host page may pass $domainState; otherwise use FP5 demo states for screenshots.
$domainState = $domainState ?? [
    'pain_points'  => 'COVERED',
    'data_sources' => 'COVERED',
    'data_access'  => 'COVERED',
];

$matrixCovered = 0;
foreach ($matrixDomains as $k => $_) {
    if (($domainState[$k] ?? 'OPEN') === 'COVERED') { $matrixCovered++; }
}
$matrixTotal   = count($matrixDomains);
$matrixPercent = (int) round(($matrixCovered / $matrixTotal) * 100);
?>
<style>
    /* Scoped to the matrix component so it can drop into any container. */
    #domain-matrix-panel .domain-row {
        display: flex; align-items: center; justify-content: space-between;
        padding: .6rem .75rem; border-radius: .5rem; margin-bottom: .5rem;
        background: #ffffff; border: 1px solid #e9ecef;
    }
    #domain-matrix-panel .domain-row .label { font-weight: 500; }
    #domain-matrix-panel .domain-badge {
        width: 1.6rem; height: 1.6rem;
        display: inline-flex; align-items: center; justify-content: center;
        border-radius: 50%; font-weight: 700; font-size: .9rem; flex: 0 0 auto;
    }
    #domain-matrix-panel .domain-row[data-state="COVERED"] .domain-badge { background: #198754; color: #fff; }
    #domain-matrix-panel .domain-row[data-state="OPEN"]    .domain-badge { background: #e9ecef; color: #adb5bd; }
    #domain-matrix-panel .domain-row[data-state="COVERED"] { border-color: #198754; }
</style>

<div id="domain-matrix-panel" class="p-3 p-md-4">
    <h2 class="h6 text-uppercase text-muted mb-3">System Coverage Matrix</h2>

    <div id="domain-matrix">
        <?php foreach ($matrixDomains as $key => $label):
            $state = ($domainState[$key] ?? 'OPEN') === 'COVERED' ? 'COVERED' : 'OPEN';
            $mark  = $state === 'COVERED' ? '&#10003;' : '&#9675;'; // ✓ or ○
        ?>
        <div class="domain-row" data-domain="<?= htmlspecialchars($key) ?>" data-state="<?= $state ?>">
            <span class="label"><?= htmlspecialchars($label) ?></span>
            <span class="domain-badge" aria-label="<?= $state ?>"><?= $mark ?></span>
        </div>
        <?php endforeach; ?>
    </div>

    <div class="mt-4">
        <div class="d-flex justify-content-between small text-muted mb-1">
            <span>Progress</span>
            <span><span id="progress-count"><?= $matrixCovered ?></span> / <?= $matrixTotal ?></span>
        </div>
        <div class="progress" role="progressbar" aria-label="Domain coverage"
             aria-valuenow="<?= $matrixPercent ?>" aria-valuemin="0" aria-valuemax="100" style="height: 1.25rem;">
            <div id="progress-bar" class="progress-bar bg-success" style="width: <?= $matrixPercent ?>%;"><?= $matrixPercent ?>%</div>
        </div>
    </div>
</div>

<script>
    // FP5 demo helper — flips a badge by its data-domain attribute and recomputes
    // the progress bar. Proves each badge is independently addressable (the FP5
    // DevTools check). The real AJAX pipeline (app.js) replaces this at FP10.
    // Try in the console:  setDomainState('end_result', 'COVERED')
    var TOTAL_DOMAINS = <?= $matrixTotal ?>;

    function setDomainState(domain, state) {
        var $row = $('.domain-row[data-domain="' + domain + '"]');
        if (!$row.length) { console.warn('No domain badge for:', domain); return; }
        state = (state === 'COVERED') ? 'COVERED' : 'OPEN';
        $row.attr('data-state', state);
        $row.find('.domain-badge')
            .html(state === 'COVERED' ? '✓' : '○')
            .attr('aria-label', state);
        updateProgress();
    }

    function updateProgress() {
        var covered = $('.domain-row[data-state="COVERED"]').length;
        var pct = Math.round((covered / TOTAL_DOMAINS) * 100);
        $('#progress-count').text(covered);
        $('#progress-bar')
            .css('width', pct + '%')
            .text(pct + '%')
            .parent().attr('aria-valuenow', pct);
    }
</script>
