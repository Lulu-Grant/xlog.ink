# Deployment Notes

This project requires a PHP runtime and writable data/output directories.

## Required environment variables

- `TURNSTILE_SITE_KEY`
- `TURNSTILE_SECRET_KEY`

Both values must be configured on the server. They are intentionally not stored in the repository.

## Writable paths

The PHP process needs write access to:

- `site/`
- `data/`
- `data/ratelimit/`

## Suggested checklist

1. Configure PHP with outbound HTTPS access for Turnstile verification.
2. Set `TURNSTILE_SITE_KEY` and `TURNSTILE_SECRET_KEY` in the server environment.
3. Ensure `site/` and `data/` are writable by the PHP runtime user.
4. Serve the project behind Nginx or Apache with HTTPS enabled.
5. If running behind a reverse proxy, set `XLOG_TRUSTED_PROXIES` to the proxy IP list.
