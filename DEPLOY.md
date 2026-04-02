# DEPLOY

This document describes a practical server deployment model for `xlog.ink`.

## Overview

This project is a PHP-based site generator:

- static public pages are served directly by the web server
- `creat.php` and `creat-article.php` render the creation UI
- `generate.php` and `generate-article.php` handle form submission and write generated HTML files
- runtime data is stored on disk under `site/` and `data/`

Recommended stack:

- Linux server
- Nginx or Apache
- PHP 8.1+ with common extensions enabled
- HTTPS enabled

## Requirements

Minimum runtime requirements:

- PHP 8.1 or newer
- outbound HTTPS access for Cloudflare Turnstile verification
- write permission for runtime directories
- a web server that can route `.php` files to PHP-FPM or mod_php

Recommended PHP extensions:

- `mbstring`
- `json`
- `openssl`

## Required Environment Variables

These values must be configured on the server and must not be stored in the repository:

- `TURNSTILE_SITE_KEY`
- `TURNSTILE_SECRET_KEY`

Optional:

- `XLOG_TRUSTED_PROXIES`

`XLOG_TRUSTED_PROXIES` should be a comma-separated list of reverse proxy IPs. Only requests coming from those IPs are allowed to supply forwarded client IP headers.

## Directory Layout

Typical deployment layout:

```text
/var/www/xlog.ink/
  assets/
  data/
  includes/
  partials/
  site/
  index.html
  recent.html
  manual.html
  creat.php
  creat-article.php
  generate.php
  generate-article.php
```

## Writable Paths

The PHP runtime user must be able to write to:

- `site/`
- `data/`
- `data/ratelimit/`

The PHP runtime user only needs read access to:

- `assets/`
- `includes/`
- `partials/`
- root HTML and PHP entry files

## Deployment Steps

1. Clone the repository to the server.
2. Configure PHP and your web server.
3. Set `TURNSTILE_SITE_KEY` and `TURNSTILE_SECRET_KEY` in the server environment.
4. Ensure `site/` and `data/` are writable by the PHP runtime user.
5. If deployed behind Cloudflare, Nginx, HAProxy, or another reverse proxy, set `XLOG_TRUSTED_PROXIES`.
6. Enable HTTPS and point the domain to the server.

## Nginx Example

Example site block:

```nginx
server {
    listen 80;
    listen [::]:80;
    server_name xlog.ink *.xlog.ink;
    return 301 https://$host$request_uri;
}

server {
    listen 443 ssl http2;
    listen [::]:443 ssl http2;
    server_name xlog.ink *.xlog.ink;

    root /var/www/xlog.ink;
    index index.html index.php;

    client_max_body_size 2m;

    location / {
        try_files $uri $uri/ /index.html;
    }

    location ~ \.php$ {
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        fastcgi_param TURNSTILE_SITE_KEY your_site_key_here;
        fastcgi_param TURNSTILE_SECRET_KEY your_secret_key_here;
        fastcgi_pass unix:/run/php/php8.2-fpm.sock;
    }
}
```

Notes:

- update the PHP-FPM socket path for your server
- if you want environment variables loaded from the service manager instead, remove the `fastcgi_param` lines and configure them at the process level
- if `recent.html` is rebuilt out of band, make sure the deploy user can run `build_recent.py`

## Apache Example

Example virtual host:

```apache
<VirtualHost *:80>
    ServerName xlog.ink
    ServerAlias *.xlog.ink
    Redirect permanent / https://xlog.ink/
</VirtualHost>

<VirtualHost *:443>
    ServerName xlog.ink
    ServerAlias *.xlog.ink
    DocumentRoot /var/www/xlog.ink

    SetEnv TURNSTILE_SITE_KEY your_site_key_here
    SetEnv TURNSTILE_SECRET_KEY your_secret_key_here

    <Directory /var/www/xlog.ink>
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
```

Notes:

- adjust this to match your Apache + PHP integration mode
- if using PHP-FPM with Apache, pass the environment variables through that layer instead of relying on defaults

## Permissions

Typical ownership setup:

- deploy user owns the code checkout
- web server user has read access to the checkout
- web server user has write access to `site/` and `data/`

Example:

```bash
chown -R deploy:www-data /var/www/xlog.ink
find /var/www/xlog.ink -type d -exec chmod 755 {} \;
find /var/www/xlog.ink -type f -exec chmod 644 {} \;
chmod -R 775 /var/www/xlog.ink/site /var/www/xlog.ink/data
```

Adjust user/group names for your environment.

## Reverse Proxy Notes

If the server is behind a reverse proxy, configure `XLOG_TRUSTED_PROXIES` so rate limiting uses the real client IP only when the request came through a trusted proxy.

Without this variable:

- the app falls back to `REMOTE_ADDR`
- forwarded IP headers are ignored

## Operational Notes

- generated pages are written to disk and are intended to be served as static output
- `data/pages.jsonl` acts as an append-only page index for `recent.html`
- `data/ratelimit/` stores per-IP rate-limit state
- `site/` and `data/` should be included in backup planning if production data must be preserved

## Suggested Post-Deploy Checklist

1. Open `/creat.php` and `/creat-article.php`.
2. Confirm the Turnstile widget renders.
3. Submit a test page and confirm a file is created under `site/`.
4. Check that `data/pages.jsonl` is updated.
5. Confirm the generated page loads under the expected domain.
6. Confirm rate limiting writes files under `data/ratelimit/`.
