# CONFIG

This document explains which paths are source code, which paths are runtime data, and which paths should stay out of Git.

## Directory Roles

### Source code and versioned assets

These paths are part of the application source and should normally stay in Git:

- `assets/` — CSS, JavaScript, images, third-party frontend assets
- `includes/` — shared PHP helpers, response rendering, i18n, markdown, rate limiting, Turnstile verification
- `partials/` — reusable HTML fragments such as footers
- `index.html` — homepage
- `manual.html` — manual/help page
- `recent.html` — generated or maintained public listing page
- `creat.php` — link-page creation UI
- `creat-article.php` — article creation UI
- `generate.php` — link-page generation endpoint
- `generate-article.php` — article-page generation endpoint
- `build_recent.py` — script that rebuilds `recent.html`
- `site-samples/` — committed showcase HTML pages
- `README.md`, `DEPLOY.md`, `CONFIG.md` — project documentation

### Runtime data

These paths are written or updated by the running application:

- `site/` — runtime-generated public HTML pages
- `data/pages.jsonl` — append-only page index used to build `recent.html`
- `data/ratelimit/` — per-IP rate-limit state files

These paths are operational data, not pure source code.

## Recommended Git Boundary

### Keep in Git

Keep these in Git:

- application source files
- public assets needed to build or serve the site
- curated sample pages in `site-samples/`
- documentation

### Keep out of Git

Keep these out of Git:

- secrets
- temporary preview files
- local IDE/editor folders
- runtime logs
- generated rate-limit state
- machine-specific memory or agent work files

Current examples already excluded in `.gitignore`:

- `.idea/`
- `.codex/`
- `.claude/`
- `tmp-preview/`
- `memory/`
- `img/`
- `data/ratelimit/`
- `*.log`
- `*.tmp`

## Runtime vs Repository Data

There are two different kinds of files under this project:

1. Repository-controlled files
These are meant to be reviewed, versioned, and deployed as code.

2. Runtime-generated files
These are created or mutated by production traffic and may differ across environments.

`site/` is now intended for runtime-generated output only.

Committed showcase pages belong in `site-samples/`.

## Secret Handling

Do not store these in Git:

- `TURNSTILE_SITE_KEY`
- `TURNSTILE_SECRET_KEY`
- any future API keys, tokens, or private credentials

Set them through:

- web server environment variables
- PHP-FPM pool environment settings
- deployment platform secret management

Use `.env.example` only as a template. Do not commit real secret values.

## Writable Paths

The application expects write access to:

- `site/`
- `data/`
- `data/ratelimit/`

Everything else should generally be treated as read-only at runtime.

## Backup Guidance

If production state matters, back up:

- `site/`
- `data/pages.jsonl`

You usually do not need to preserve:

- `data/ratelimit/`
- temporary files
- local machine folders ignored by Git

## Current Separation

- `site-samples/` for committed showcase pages
- `site/` for runtime-generated pages only
- `data/` for indexes and rate-limit state
