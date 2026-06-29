# Domain Matrix Panel — Handoff (Port, FP5)

This is the **right-side domain coverage matrix** (the 8 badges + progress bar).
It's built as a drop-in piece so it slots straight into your `index.php` skeleton.

## Files
```
public/
├── partials/
│   └── domain_matrix.php   <- the panel (this is the deliverable)
└── matrix_preview.php      <- a tiny test page I used to check it; reference only
```

## How to drop it into your index.php (one line)
Inside the **right** panel container of your split-pane, add:

```php
<?php include __DIR__ . '/partials/domain_matrix.php'; ?>
```

That's the whole integration. The panel brings its own styles and a small jQuery
helper with it. Just make sure jQuery + Bootstrap are loaded on the page (they're
part of our stack already).

## How to run/test it
From the `public/` folder's parent, start PHP's built-in server pointed at `public`:

```
C:\xampp\php\php.exe -S localhost:8000 -t "PATH\TO\requirement-orchestrator\public"
```
Then open: http://localhost:8000/matrix_preview.php

To see a badge flip (and the progress bar update), open the browser console (F12)
and run:  `setDomainState('end_result', 'COVERED')`

## Notes for building on it later (matches our weekly_deliverable_plan.xlsx)
- **The 8 `data-domain` keys match the Extraction Agent JSON schema**
  (`pain_points`, `data_sources`, `data_access`, `end_result`, `stakeholders`,
  `audience_type`, `current_process`, `interaction_model`). So live `domain_state`
  JSON from the DB maps onto the badges with no translation.
- **FP6 (live data):** the panel optionally takes a `$domainState` array
  (key => 'COVERED'|'OPEN'). Set it before the include to show real state instead
  of the demo defaults.
- **FP10 (pipeline):** `app.js` just calls `setDomainState(domain, state)` after
  each AJAX response to update badges live — those functions are already here.
- **FP9 (completion):** when all 8 are COVERED, swap the contents of
  `#domain-matrix-panel` for the 5-prompt build plan view.

Please build on these hooks rather than rewriting the panel — keeps us in sync.

— Port
