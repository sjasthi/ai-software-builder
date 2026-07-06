<?php
/**
 * Previous-sessions list (Port, FP6) — shared by the Landing screen and the
 * hamburger drawer. Renders one Bootstrap list-group row per saved .json session.
 *
 * Caller may set $activeId before including, to highlight the current session.
 */
$sessions = InterviewSession::listSessions();
$activeId = $activeId ?? null;
?>
<?php if (empty($sessions)): ?>
    <p class="text-muted small mb-0">No sessions yet. Start a new one to begin.</p>
<?php else: ?>
    <div class="list-group">
        <?php foreach ($sessions as $s):
            $done   = $s['status'] === 'complete';
            $date   = $s['updated_at'] ? date('M j, Y', strtotime($s['updated_at'])) : '';
            $active = $activeId === $s['id'] ? ' active' : '';
        ?>
        <a href="session.php?id=<?= urlencode($s['id']) ?>"
           class="list-group-item list-group-item-action d-flex justify-content-between align-items-center<?= $active ?>">
            <span class="text-truncate me-2">
                <span class="fw-semibold d-block text-truncate"><?= htmlspecialchars($s['title']) ?></span>
                <small class="text-muted"><?= htmlspecialchars($date) ?><?= $done ? ' · plan ready' : ' · in progress' ?></small>
            </span>
            <span class="badge <?= $done ? 'bg-success' : 'bg-secondary' ?> rounded-pill flex-shrink-0">
                <?= $s['covered'] ?>/<?= $s['total'] ?><?= $done ? ' ✓' : '' ?>
            </span>
        </a>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
