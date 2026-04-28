# Expansion Log

## 2026-04-12

This document records the current project architecture, the conventions we agreed on, and the rules future tool work should follow.
The main day-to-day development handbook now lives in `docs/DEVELOPMENT_GUIDE.md`.

## Current Architecture

- The project is a lightweight PHP site, not a full framework.
- `config.php` is only the bootstrap entry.
- `app/bootstrap.php` owns shared runtime behavior:
  language detection, translation loading, SEO helpers, runtime storage, cleanup, and temporary download tokens.
- `app/tools.php` is the single source of truth for tool registration.
- `index.php` renders the homepage from the tool registry.
- `inc/header.php` and `inc/footer.php` handle shared layout, language switching, theme switching, canonical tags, and hreflang tags.
- `tools/` contains one page entry per tool.
- `lang/` contains the translation dictionaries.
  We currently maintain both Traditional Chinese (`zh`) and Simplified Chinese (`zh-cn`) alongside other locales.
- `download.php` serves generated files through session-backed temporary download tokens.
- `sitemap.php` builds the sitemap from the tool registry.

## Tool Registration Rules

When adding a new tool, always do the work in this order:

1. Add the tool definition to `app/tools.php`.
2. Use an ASCII slug such as `qr-generator` or `json-formatter`.
3. Create the tool page in `tools/<slug>.php`.
4. Add the required translation keys to every file in `lang/`.
   That includes both Chinese variants when the UI copy changes.
5. If an old URL must still work, keep the old file as a thin compatibility wrapper that requires the new file.

Do not hardcode new tool cards directly into `index.php`.
Do not build a separate sitemap list by hand.
Do not invent one-off title logic inside tool pages when the registry metadata can provide it.

## SEO Rules

- Canonical URLs must match the currently rendered language version.
- Default language pages use the clean URL without `?lang=`.
- Non-default language pages use `?lang=<code>`.
- Chinese language detection must distinguish Traditional (`zh`) from Simplified (`zh-cn`) for explicit selection, cookies, and browser language hints.
- Hreflang entries are generated centrally and should not be duplicated inside tool pages.
- Every tool should have:
  a translated title,
  a translated description,
  translated keywords,
  and a stable slug.
- `sitemap.php` should remain registry-driven.
- Avoid relying on `meta keywords` as a primary SEO tactic.
- Shared Open Graph, Twitter Card, and baseline structured data already ship from the shared header.
- Future SEO work should focus on richer per-tool content and schema where it adds real value.

## Frontend Rules

- Do not use the Tailwind runtime CDN compiler in production.
- Utility styles are compiled into `assets/css/tailwind.min.css`.
- Tailwind source lives in `assets/css/tailwind.source.css`.
- Tailwind scan paths are configured in `tailwind.config.js`.
- Rebuild command:
  `npx --yes tailwindcss@3.4.17 -c ./tailwind.config.js -i ./assets/css/tailwind.source.css -o ./assets/css/tailwind.min.css --minify`
- Keep `custom.css` for project-specific component styling that does not fit well into utility classes.

### Shared Tool UI Classes

- We now keep a reusable tool-page component layer in `assets/css/custom.css`.
- When adding or updating tools, prefer these shared classes before writing another long one-off utility string.
- Core panel classes:
  `tool-panel`,
  `tool-panel-compact`,
  `tool-muted-panel`,
  `tool-soft-panel`,
  `tool-preview-panel`
- Shared text classes:
  `tool-title-lg`,
  `tool-title-xl`,
  `tool-hero-eyebrow`,
  `tool-hero-title`,
  `tool-hero-copy`,
  `tool-badge`,
  `tool-copy`,
  `tool-label`,
  `tool-field-hint`,
  `tool-status-text`,
  `tool-note-title`,
  `tool-meta-label`,
  `tool-meta-value`
- Shared form classes:
  `tool-control`,
  `tool-control-sm`,
  `tool-control-lg`,
  `tool-control-filled`,
  `tool-control-mono`,
  `tool-textarea`,
  `tool-output-display`,
  `tool-color-input`,
  `tool-checkbox`
- Shared action classes:
  `tool-button-secondary`,
  `tool-button-primary`,
  `tool-keypad-button`,
  `tool-status-pill`
- Shared option / toggle classes:
  `tool-toggle-row`
- Shared metric classes:
  `tool-stat-card`,
  `tool-stat-label`,
  `tool-stat-value`,
  `tool-stat-value-lg`,
  `tool-kpi-card`,
  `tool-kpi-label`,
  `tool-kpi-number`
- Shared inset / result surfaces:
  `tool-inset-panel`,
  `tool-display-board`,
  `tool-preview-glyph`,
  `tool-help-panel`
- Shared copy / alert helpers:
  `tool-copy-list`,
  `tool-alert-warning`,
  `tool-alert-success`,
  `tool-alert-error`
- Use utility classes for layout and unique page composition.
- Use shared component classes for repeated surface, field, button, hint, and metric patterns.
- If a style pattern appears in multiple tools, extract it instead of repeating the same utility stack.
- Legacy pages and the homepage should be periodically pulled forward into the same shared component system instead of leaving the old tools visually behind.

## Runtime and File Handling Rules

- Do not write generated files into public `uploads/` unless there is a very explicit reason.
- Generated downloads should go into the private runtime directory under `sys_get_temp_dir() . '/toolsgualaoshi'`.
- Public downloads must go through `download.php` and tokenized session-backed links.
- Runtime cleanup is throttled through `maybe_cleanup_runtime_storage()` and should stay that way.
- Avoid any endpoint that accepts files without a clear frontend consumer.
- `tools/upload-temp.php` is now retired and should stay retired unless we intentionally reintroduce an async upload flow.

## Tool Design Notes

### Image Compression

- This is the heaviest tool currently.
- Validate file count, size, and extension before processing.
- Prefer bounded work and predictable runtime over adding too many output options.
- Be careful about memory usage if adding larger-image workflows later.

### Unit Converter

- Use explicit unit labels when a unit is region-specific.
- Do not use ambiguous labels like `cup`, `pt`, or `gal` without clarifying the standard.

### Scientific Calculator

- Keep evaluation constrained.
- If this tool becomes more advanced, consider replacing expression execution with a parser instead of growing the current string-rewrite approach indefinitely.
- If trig behavior remains radians-based, the UI should continue to make that clear.

### Time Clock

- It is fine as a client-side tool.
- If we ever add SEO-driven landing content around time zones, keep the real clock behavior lightweight and client-side.

### Color Picker

- It is also fine as a client-side tool.
- If expanded, prefer useful derived outputs such as HSL, CSS variables, contrast ratios, and accessibility helpers.

## Recommended Tool Roadmap

The next batch should stay lightweight, SEO-friendly, and easy to maintain inside the current PHP + shared-template architecture.

Priority legend used below:

- `P1`
  High-value tools that fit the current stack extremely well.
- `P2`
  Still strong additions, but a little less urgent than the first batch.
- `P3`
  Useful follow-ups after the core library is in place.

### Developer Tools

1. `json-formatter` — `P1`
   Category: Developer Tools
   Build as: Mostly client-side
   Why: High search demand, simple logic, excellent fit for multilingual SEO landing pages.
2. `base64-encode-decode` — `P1`
   Category: Developer Tools
   Build as: Client-side
   Why: Extremely lightweight and evergreen.
3. `url-encode-decode` — `P1`
   Category: Developer Tools
   Build as: Client-side
   Why: Small scope, broad usefulness, fast to ship.
4. `timestamp-converter` — `P1`
   Category: Developer Tools
   Build as: Client-side
   Why: Strong utility for developers, operations, and general users.
5. `uuid-generator` — `P1`
   Category: Developer Tools
   Build as: Client-side
   Why: Almost zero backend complexity and consistently useful.
6. `hash-generator` — `P2`
   Category: Developer Tools
   Build as: Client-side or PHP
   Why: Good complement to the rest of the dev toolbox once the core set is live.
7. `jwt-decoder` — `P2`
   Category: Developer Tools
   Build as: Client-side
   Why: Useful for developers, but slightly narrower than JSON/Base64/URL tools.
8. `regex-tester` — `P3`
   Category: Developer Tools
   Build as: Client-side
   Why: Valuable, but the UX needs extra care to feel polished.

### Text Tools

9. `word-counter` — `P1`
   Category: Text Tools
   Build as: Client-side
   Why: Broad audience and strong lightweight utility.
10. `case-converter` — `P1`
   Category: Text Tools
   Build as: Client-side
   Why: Easy to implement, easy to localize, useful for both everyday and developer workflows.
11. `slug-generator` — `P1`
   Category: Text Tools
   Build as: Client-side with optional PHP normalization
   Why: Matches the site's SEO-minded audience and complements content workflows.
12. `line-deduplicator` — `P2`
   Category: Text Tools
   Build as: Client-side
   Why: Practical for lists, logs, keywords, and bulk text cleanup.
13. `line-sorter` — `P2`
   Category: Text Tools
   Build as: Client-side
   Why: Pairs naturally with dedupe and cleanup utilities.
14. `markdown-preview` — `P3`
   Category: Text Tools
   Build as: Client-side
   Why: Good long-term addition, but less universal than the first text tools.

### Daily Utility Tools

15. `qr-code-generator` — `P1`
   Category: Daily Utility
   Build as: Client-side
   Why: One of the strongest lightweight consumer tools for a toolbox site.
16. `qr-code-scanner` — `P2`
   Category: Daily Utility
   Build as: Client-side
   Why: Great companion to the generator, especially on mobile.
17. `password-generator` — `P1`
   Category: Daily Utility
   Build as: Client-side
   Why: Highly practical, easy to ship, and good for repeat visits.
18. `age-calculator` — `P2`
   Category: Daily Utility
   Build as: Client-side
   Why: Broad appeal and easy SEO targeting.
19. `date-difference-calculator` — `P1`
   Category: Daily Utility
   Build as: Client-side
   Why: Strong utility and easy to present clearly across languages.
20. `percentage-calculator` — `P2`
   Category: Daily Utility
   Build as: Client-side
   Why: Broad casual usefulness and almost no maintenance cost.

### Image And PDF Follow-Up Batch

After the first 20 above, the most natural second-wave additions are:

- `image-converter`
  JPG/PNG/WebP conversion. Strong fit with the existing image-processing code path.
- `image-cropper`
  Very compatible with the current image tooling direction.
- `image-resizer`
  Related to the existing compression workflow and easy to cross-link.
- `image-to-pdf`
  Strong user value and a natural bridge into PDF tools.
- `pdf-merge`
  High demand, but should wait until we intentionally choose a PDF processing approach.
- `pdf-split`
  Same note as merge: useful, but better as part of a small PDF batch rather than a one-off.

### What To Avoid For Now

- Heavy AI tools
  They add cost, product complexity, and ongoing maintenance.
- Video downloader tools
  They raise legal, platform, and support risks.
- OCR and advanced PDF editing
  These are valuable, but heavier than the current site architecture needs right now.
- Realtime finance or exchange-rate tools
  They depend on unstable external APIs and require more operational care.

### Build Order Recommendation

If we want the cleanest rollout path, the suggested implementation order is:

1. `qr-code-generator`
2. `json-formatter`
3. `base64-encode-decode`
4. `url-encode-decode`
5. `timestamp-converter`
6. `password-generator`
7. `word-counter`
8. `slug-generator`
9. `uuid-generator`
10. `date-difference-calculator`

### Document Conversion Note

- `word-pdf-converter` should be treated as a lightweight, text-first conversion tool.
- The supported boundary is predictable document export, not pixel-perfect layout reproduction.
- Complex page composition, embedded fonts, tables, images, and print-fidelity edge cases should be called out as best-effort or out of scope.
- When wiring it into the shared architecture, keep the same registry-driven page pattern and add the limitation to page copy, language files, and docs at the same time.

## Local Development Workflow

- Local friendly URLs are handled by `router.php`.
- Local dev server example:
  `php -S 127.0.0.1:8014 router.php`
- Before pushing styling changes, rebuild `assets/css/tailwind.min.css`.
- Before syncing to the server, run `php -l` across changed PHP files.

## Server Sync Notes

- Keep server-specific files like `.user.ini` intact unless there is a deliberate reason to change them.
- Do not sync local `tmp/` or `uploads/` runtime data back to the server.
- Preserve compatibility wrappers so older incoming links do not break.

## Known Follow-Ups

- Consider a small tool-page content block per tool to improve SEO depth.
- Consider a more explicit product/developer changelog if multiple people start shipping tools regularly.
