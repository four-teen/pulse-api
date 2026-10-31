# PULSE API

Dependency-free PHP authentication, presidential dashboard endpoints, and a prepared integration layer for SINQ, Oracle, and PAD. Requires **PHP 8.1+** with sessions, JSON, and cURL enabled. A database is not required for the current private snapshot store.

## Presidential passcode

```powershell
php bin/setup.php
```

This creates a Git-ignored `.env` with a randomly generated `PRESIDENT_PASSCODE`. View or edit that file locally. The passcode is the only credential; no username is entered or sent by the frontend. Keep the passcode out of GitHub and the browser bundle.

`PRESIDENT_PASSCODE_HASH` optionally accepts a `password_hash()` value instead. A nonempty hash takes precedence over `PRESIDENT_PASSCODE`. Single-quote hashes in `.env`. Changing the passcode invalidates existing sessions. `PRESIDENT_NAME` controls the fixed president profile returned after authentication.

## Local server

```powershell
php -S 127.0.0.1:8000 -t public public/router.php
```

Start the frontend separately at `http://127.0.0.1:5173`. The frontend dev server forwards `/api/*` to this API. Its browser origin must exactly match `APP_URL` in `.env`.

## Endpoints

| Method | Path | Purpose |
| --- | --- | --- |
| GET | `/health` | Service availability; no private configuration returned |
| POST | `/auth/login` | Verify JSON `{ "passcode": "…" }` and issue a session |
| GET | `/auth/session` | Return the signed-in president; otherwise HTTP 401 |
| POST | `/auth/logout` | Destroy the session and expire the cookie |
| GET | `/v1/president/dashboard` | Authenticated campus metrics, source states, freshness, and reporting period |
| GET | `/v1/campuses` | Authenticated seven-campus catalogue and available metrics |
| GET | `/v1/integrations` | Authenticated source availability without credentials |

POST routes require an `Origin` header matching `APP_URL`. Responses are not cached. Sessions use HttpOnly, SameSite=Strict cookies, session ID regeneration at login, a fixed expiry (default one hour), and Secure cookies in production. Five incorrect attempts within fifteen minutes temporarily lock the single account for five minutes. Contact the administrator if the account is locked; the temporary lock expires automatically.

## Production hosting

1. Upload the PHP application to your hosting account. Set the API domain document root to **`public/`**. The actual `.env`, `src/`, `bin/`, and `var/` must remain outside the public document root. Root `.htaccess` rules provide additional protection if the project is placed under Apache webroot, but the dedicated `public/` root is preferred.
2. Enable HTTPS and PHP 8.1+. Do not use PHP's built-in development server in production. The older PHP bundled with some VertrigoServ versions is insufficient; the development setup uses the available PHP 8.2 CLI.
3. Set `APP_ENV=production`, `APP_URL` to the exact Vercel/custom-domain browser origin, and a strong `PRESIDENT_PASSCODE` (or `PRESIDENT_PASSCODE_HASH`).
4. Ensure `var/` is writable by the hosting PHP process. Sessions are stored privately in `var/sessions/` and rate limits use a locked file in `var/`. Optional `PULSE_STORAGE_PATH` can point to another private writable directory. A multi-server API deployment will need shared rate-limit/session storage.
5. Set the frontend Vercel variable `PULSE_API_ORIGIN` to the API's HTTPS base URL. The frontend proxy calls PHP server-to-server and forwards session cookies. No browser CORS policy is needed for this setup.
6. Optionally set matching `PULSE_PROXY_SECRET` values on both servers to require traffic through the frontend proxy. Use a random secret; do not expose it to browser code.

The three requested systems are registered and **disabled**. Website links are retained for identification; their API endpoints and credentials are intentionally blank. Dashboard GET requests read validated local snapshots only and never connect to source systems. The prepared sync command is CLI-only, read-only, and requires an explicitly enabled source.

Read [the integration contract](docs/integrations.md) before connecting a source. It documents configuration, adapter boundaries, campus IDs, metric names, reporting periods, category ownership, partial/missing values, freshness, validation and later sync commands. [OpenAPI documentation](docs/openapi.yaml) describes the frontend-facing routes.

## Deployment checks

```powershell
php bin/check.php
php bin/sync.php --check
```

These commands print no credentials and make no remote requests. Before release, configure the actual PHP/Vercel hosts and run:

```powershell
php bin/check.php --production
```

The production check requires HTTPS frontend origin, `APP_ENV=production`, a strong presidential secret, and a shared proxy secret of at least 32 characters on PHP and Vercel. Keep all source flags disabled until the actual source APIs and adapters are agreed. Use `public/` as the production document root, exclude test/docs directories from uploads when possible, and keep writable runtime data private. A single host may use the current local snapshot files; multiple hosts require shared storage.

## Syntax checks

```powershell
php -l public/index.php
php -l public/router.php
php -l src/bootstrap.php
php -l bin/setup.php
php -l bin/sync.php
php -l bin/check.php
```

With Node.js available, run the isolated HTTP authentication tests (each uses its own temporary PHP server and test account):

```powershell
node --test tests/auth.test.mjs
php tests/integrations.test.php
```
