<?php
/**
 * Previous-sessions list (Port, FP6) — shared by the Landing screen and the
 * hamburger drawer. Renders one Bootstrap list-group row per saved .json session,
 * each with a hover-to-reveal delete button (confirmed before removal).
 *
 * Caller may set $activeId before including, to highlight the current session.
 * $activeId is also passed to delete_session.php so a delete returns the user to
 * whichever session they were viewing (unless that's the one deleted).
 */
$sessions = InterviewSession::listSessions();
$activeId = $activeId ?? null;
?>
<style>
    /* ── Delete-session control (shared landing + drawer) ─────────── */
    .session-row { position: relative; }
    .session-open-link { text-decoration: none; color: inherit; }
    .session-open-link:hover { color: inherit; }
    .session-delete-wrap { position: relative; z-index: 2; }   /* sits above the stretched-link */
    .delete-session-btn {
        border: none; background: transparent; color: #dc3545; opacity: .4;
        width: 1.9rem; height: 1.9rem; padding: 0; border-radius: .35rem;
        font-size: 1.15rem; line-height: 1; font-weight: 600;
        display: inline-flex; align-items: center; justify-content: center;
        transition: background .12s, color .12s, opacity .12s;
    }
    .delete-session-btn:hover, .delete-session-btn:focus {
        opacity: 1; background: #dc3545; color: #fff;
    }
    /* Dark hover tooltip, positioned to the left so it never clips the panel edge. */
    .session-delete-wrap::after {
        content: "Delete this session";
        position: absolute; top: 50%; right: calc(100% + .5rem); transform: translateY(-50%);
        background: #212529; color: #fff; font-size: .72rem; white-space: nowrap;
        padding: .3rem .55rem; border-radius: .35rem;
        opacity: 0; pointer-events: none; transition: opacity .12s; z-index: 10;
    }
    .session-delete-wrap:hover::after { opacity: 1; }
</style>

<?php if (empty($sessions)): ?>
    <p class="text-muted small mb-0">No sessions yet. Start a new one to begin.</p>
<?php else: ?>
    <div class="list-group">
        <?php foreach ($sessions as $s):
            $done   = $s['status'] === 'complete';
            $date   = $s['updated_at'] ? date('M j, Y', strtotime($s['updated_at'])) : '';
            $active = $activeId === $s['id'] ? ' active' : '';
        ?>
        <div class="list-group-item list-group-item-action session-row d-flex justify-content-between align-items-center<?= $active ?>">
            <a href="session.php?id=<?= urlencode($s['id']) ?>"
               class="stretched-link session-open-link text-truncate me-2">
                <span class="fw-semibold d-block text-truncate"><?= htmlspecialchars($s['title']) ?></span>
                <small class="text-muted"><?= htmlspecialchars($date) ?><?= $done ? ' · plan ready' : ' · in progress' ?></small>
            </a>
            <div class="d-flex align-items-center gap-2 flex-shrink-0">
                <span class="badge <?= $done ? 'bg-success' : 'bg-secondary' ?> rounded-pill">
                    <?= $s['covered'] ?>/<?= $s['total'] ?><?= $done ? ' ✓' : '' ?>
                </span>
                <span class="session-delete-wrap">
                    <form method="post" action="delete_session.php" class="m-0"
                          onsubmit="return confirm('Delete this session for good? This cannot be undone.');">
                        <input type="hidden" name="id" value="<?= htmlspecialchars($s['id']) ?>">
                        <input type="hidden" name="return" value="<?= htmlspecialchars((string) $activeId) ?>">
                        <button type="submit" class="delete-session-btn"
                                aria-label="Delete this session" title="">&times;</button>
                    </form>
                </span>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
