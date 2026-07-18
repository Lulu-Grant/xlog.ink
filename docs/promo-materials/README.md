# xlog.ink Promo Materials

This folder contains controllable source materials for the ten xlog.ink promo pages.

## Structure

- `mockups/index.html` - HTML-only product/page mockups for screenshot capture.
- `renders/` - PNG outputs generated from the mockup frames.
- `svg/` - reusable transparent SVG symbols and diagrams.
- `prompts/` - AI image prompts for commercial atmosphere images.
- `ai-images/` - reserved for generated atmosphere images.
- `manifest.json` - material inventory and ownership map.
- `scripts/render-promo-materials.js` - Playwright renderer for deterministic PNG exports.

## Source Rules

- Product UI, delivery cards, page previews, mobile views, and admin views are HTML mockups, not real screenshots.
- Abstract flows, icons, browser/phone shells, QR decorations, and SEO/OG diagrams are SVG.
- AI images are only for atmosphere and commercial context. They should not pretend to be product screenshots.

## Render

```bash
NODE_PATH=/Users/apple/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules \
/Users/apple/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/bin/node \
docs/promo-materials/scripts/render-promo-materials.js
```
