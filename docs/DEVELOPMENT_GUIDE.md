# Development Guide

This document is the working development handbook for the toolbox site.
Use it as the default rulebook when adding tools, adjusting UI, updating SEO, or preparing future maintenance work.

## 1. Project Positioning

- The site is a lightweight multilingual PHP toolbox, not a full-stack framework project.
- We optimize for:
  lightweight tools,
  fast page loads,
  low maintenance cost,
  reusable UI,
  and SEO-friendly public pages.
- Prefer tools that are:
  useful,
  easy to understand,
  easy to localize,
  and possible to run mostly client-side or with very small PHP support.

## 2. Source of Truth

- `app/tools.php`
  Single source of truth for tool registration.
- `app/bootstrap.php`
  Shared runtime behavior, language detection, SEO helpers, runtime storage, and download helpers.
- `lang/*.php`
  Single source of truth for user-facing copy.
- `assets/css/custom.css`
  Shared component layer for recurring tool UI patterns.
- `docs/DEVELOPMENT_GUIDE.md`
  Main development rules.
- `docs/EXPANSION_LOG.md`
  Historical decisions, roadmap notes, and architecture changes.

## 3. Core Development Principles

- Do not hardcode tool cards in the homepage.
- Do not hardcode sitemap entries by hand.
- Do not scatter visible copy across page files when it can live in language files.
- Do not create one-off CSS patterns when an existing shared class is close enough.
- Do not add backend complexity unless the tool truly needs it.
- Do not sync to the production server unless explicitly requested.
- Do not break old public URLs when a compatibility wrapper can preserve them cheaply.

## 4. Project Structure

- `index.php`
  Homepage rendered from the tool registry.
- `tools/*.php`
  One entry page per tool.
- `inc/header.php` and `inc/footer.php`
  Shared shell, global SEO tags, language switcher, and theme behavior.
- `download.php`
  Controlled download endpoint for generated files.
- `sitemap.php`
  Registry-driven sitemap output.
- `assets/css/tailwind.min.css`
  Compiled utility stylesheet.
- `assets/css/custom.css`
  Shared project-specific component styles.

## 5. New Tool Workflow

When adding a new tool, use this order every time:

1. Define the tool in `app/tools.php`.
2. Choose a stable ASCII slug such as `json-formatter`.
3. Create `tools/<slug>.php`.
4. Add required translation keys to every language file.
5. Verify homepage discovery and sitemap inclusion.
6. Review SEO metadata output for the new page.
7. Reuse shared UI classes before inventing new visual patterns.
8. Run syntax and functional checks locally.

### Available Scaffolds

- `scaffolds/tool-page.php.stub`
  Starting point for a new tool page.
- `scaffolds/tool-registry.php.stub`
  Copy-paste block for `app/tools.php`.
- `scaffolds/tool-lang.php.stub`
  Copy-paste translation key starter for every language file.

Use the scaffolds as a starting point, then adapt them to the real tool instead of shipping the placeholders unchanged.

## 6. Tool Slug and File Rules

- Use ASCII-only slugs and filenames.
- Prefer lowercase words joined by hyphens.
- Keep slugs short, readable, and stable.
- If replacing an older route:
  keep the old file as a thin wrapper that includes the new page.
- Avoid Unicode punctuation in filenames.

## 7. Language System Rules

- All visible UI copy should come from `lang/*.php` unless the value is a standard technical literal.
- Maintain every locale together when adding a tool or new UI text.
- Current supported languages include:
  `zh`,
  `zh-cn`,
  `en`,
  `fr`,
  `ko`,
  `ja`.
- `zh` and `zh-cn` must both be maintained intentionally.
- Technical literals that can reasonably stay shared include examples such as:
  `HEX`,
  `RGB`,
  `ISO 8601`,
  common URL examples,
  and timezone identifiers.
- Avoid mixing Simplified Chinese into `zh`.
- Avoid mixing English placeholders into non-English locales unless the text is truly a technical standard term.

## 8. SEO Rules

- Each tool must have translated:
  title,
  description,
  keywords.
- Canonical URLs must match the rendered language.
- Default language uses the clean URL without `?lang=`.
- Non-default languages use `?lang=<code>`.
- Hreflang is generated centrally and should not be duplicated inside tool pages.
- Shared Open Graph, Twitter Card, and baseline JSON-LD already come from shared layout.
- Future SEO improvements should focus on:
  stronger per-tool explanatory copy,
  richer schema where useful,
  and better category-level content.
- Do not treat `meta keywords` as a primary SEO strategy.

## 9. UI and CSS Rules

- Tailwind runtime CDN must not be used in production pages.
- Rebuild CSS with:
  `npx --yes tailwindcss@3.4.17 -c ./tailwind.config.js -i ./assets/css/tailwind.source.css -o ./assets/css/tailwind.min.css --minify`
- Use Tailwind utilities for layout and one-off placement.
- Use `assets/css/custom.css` for shared recurring patterns.
- If a class combination appears in multiple tools, extract it.
- Prefer shared classes such as:
  `tool-panel`,
  `tool-panel-compact`,
  `tool-display-board`,
  `tool-badge`,
  `tool-title-lg`,
  `tool-title-xl`,
  `tool-hero-eyebrow`,
  `tool-hero-title`,
  `tool-hero-copy`,
  `tool-copy`,
  `tool-label`,
  `tool-field-hint`,
  `tool-control`,
  `tool-textarea`,
  `tool-output-display`,
  `tool-color-input`,
  `tool-button-secondary`,
  `tool-button-primary`,
  `tool-keypad-button`,
  `tool-status-text`,
  `tool-status-pill`,
  `tool-stat-card`,
  `tool-kpi-card`,
  `tool-inset-panel`,
  `tool-toggle-row`,
  `tool-preview-glyph`,
  `tool-help-panel`,
  `tool-copy-list`,
  `tool-alert-warning`,
  `tool-alert-success`,
  `tool-alert-error`.
- Watch contrast carefully.
  Do not use pale text on pale panels.
- Prefer a reusable component fix over patching one page at a time.

## 10. Backend and Runtime Rules

- Keep most tools client-side when possible.
- Use PHP when needed for:
  file handling,
  server-side transformations,
  controlled downloads,
  or shared runtime logic.
- Generated files should go into private runtime storage under:
  `sys_get_temp_dir() . '/toolsgualaoshi'`
- Public download access must flow through `download.php`.
- Runtime cleanup should stay throttled through shared bootstrap helpers.
- Do not create upload endpoints without a real frontend consumer.
- `tools/upload-temp.php` is retired and should remain retired unless intentionally redesigned.

## 11. Tool Design Guidelines

- Keep the primary task obvious within a few seconds.
- Avoid feature creep in early versions.
- Prefer small tools that solve one job well.
- Keep the page useful on both desktop and mobile.
- Prefer deterministic results over “smart” behavior that is hard to explain.
- For document conversion tools such as `word-pdf-converter`, define the boundary clearly in the UI and docs:
  text-first conversion is the target,
  and complex page layout, embedded fonts, tables, images, or exact print fidelity are not guaranteed.
- If a tool has regional ambiguity, label it clearly.
  Example:
  `cup (US)` instead of `cup`.
- If a tool has technical assumptions, surface them in the UI.
  Example:
  calculator trig mode or local timezone behavior.

## 12. Reuse Patterns

- Use registry-driven metadata.
- Use shared header/footer behavior.
- Use shared translation keys for common actions:
  copy,
  clear,
  generate,
  download,
  ready,
  success,
  failure.
- For new conversion tools, keep the page shell aligned with the shared tool layout and avoid adding new one-off shells unless the workflow truly needs them.
- When a tool depends on a conversion boundary, document it in both the page copy and the docs so future changes do not silently promise fidelity we do not support.
- Reuse JS patterns where practical:
  input validation,
  copy-to-clipboard,
  count updates,
  status messaging.

## 13. Testing and Review Checklist

Before considering a local change ready:

1. Run `php -l` on each changed PHP file.
2. Check the tool renders without PHP warnings.
3. Check the tool in both light and dark appearance.
4. Check mobile spacing for obvious breakage.
5. Check language switching for the updated page.
6. Check title, description, canonical, and hreflang output.
7. Check contrast for helper text, muted panels, and result boxes.
8. If files are generated, check download and cleanup behavior.

## 14. Documentation Rules

- Update this guide when a workflow rule changes.
- Update `docs/EXPANSION_LOG.md` when architecture, roadmap, or major decisions change.
- If a new shared UI pattern is introduced, document it here and in the expansion log.
- If a new tool introduces a reusable pattern, prefer documenting the pattern instead of only documenting the page.
- Keep scaffolds in `scaffolds/` aligned with the current preferred structure.

## 15. Current Recommended Priorities

- Finish polishing and standardizing the existing tool library before adding too much surface area.
- Keep improving:
  UI consistency,
  translation quality,
  SEO quality,
  and shared CSS reuse.
- Add new tools only when they still fit the lightweight toolbox model.

## 16. Quick Start Checklist

For the next tool or refactor session, follow this compact checklist:

1. Confirm the tool belongs in the lightweight toolbox scope.
2. Register it in `app/tools.php`.
3. Build the page with shared layout and shared CSS classes.
4. Add translations for every locale.
5. Verify SEO output.
6. Run local checks.
7. Update docs if a new pattern or rule was introduced.
