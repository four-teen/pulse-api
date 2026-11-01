# Read-only Synk faculty profiles: local and production

> This optional profile feed is separate from academic headcounts. The dashboard retrieves [Synk academic counts](synk-academic-counts.md) through the new `academic-counts` API resource. Do not use this all-time profile snapshot as a faculty count. The local loopback server supports both resources; profile synchronization is not needed for academic counts.

PULSE displays faculty records owned by Synk. PULSE never creates, updates, or deletes a Synk faculty record. The existing source endpoint accepts GET only; the connector also uses GET only. Changes to faculty records remain in Synk.

## Data flow

```text
Retrieval, run by an operator:
  PULSE API CLI -> authenticated GET to Synk -> validate all pages
                -> replace PULSE's private snapshot after complete success

Viewing, after presidential sign-in:
  Optional future profile-detail view -> same-origin /api/v1/faculty-profiles
                                     -> authenticated PULSE API -> private profile snapshot
```

The source token stays on the PHP servers. It is not sent to the president's browser or stored in Vercel. The browser's presidential session is not forwarded to Synk.

PULSE may write its own session files, rate-limit files, and retrieved snapshots. These writes do not change Synk. A failed retrieval preserves the previous successful PULSE snapshot.

## Fields currently available

The existing export supplies faculty name, campus, college, rank, bachelor's/master's/doctoral degree details, and source update time. Its internal record key identifies profiles for paging; it is not an employee number. This feed does not include salaries, birth dates, contact details, account credentials, student records, or workload statistics. Missing education details remain missing.

The established configuration prefix and source ID are `SINQ` and `sinq`, although the application is called Synk. Keep those identifiers unchanged.

## Local development on this workstation

| Component | Location | Address |
| --- | --- | --- |
| PULSE Web | `C:\VertrigoServ\www\pulse-web` | `http://127.0.0.1:5173` |
| PULSE API | `C:\VertrigoServ\www\pulse-api` | `http://127.0.0.1:8000` |
| Existing Synk export | `C:\VertrigoServ\www\synk\backend\pulse_api.php` | `http://127.0.0.1:8081/backend/pulse_api.php?resource=faculty-profiles` |

The Synk source files and database schema do not need to be edited for this existing endpoint. Its existing `backend/db_config.local.php` must connect to the local Synk database.

PULSE API `.env` must use `APP_ENV=development` and `APP_URL=http://127.0.0.1:5173`. Keep the remote faculty flag disabled locally. Its ignored `.env.local` selects the local source:

```dotenv
SINQ_FACULTY_LOCAL_TEST_ENABLED=true
SINQ_FACULTY_LOCAL_TEST_BASE_URL=http://127.0.0.1:8081
SINQ_FACULTY_LOCAL_TEST_PATH=/backend/pulse_api.php?resource=faculty-profiles
SINQ_FACULTY_LOCAL_TEST_TOKEN=YOUR_PRIVATE_LOCAL_TOKEN
```

Use an existing private random local token of at least 32 characters, not the placeholder above. The PULSE helper passes that token only to the local Synk PHP process. Production ignores `.env.local`; local retrieval is limited to loopback port 8081.

If the local source server is not already running, start it in a terminal from `pulse-api`:

```powershell
powershell -ExecutionPolicy Bypass -File bin/start-local-sinq.ps1
```

Leave that terminal running. In another terminal, from `pulse-api`, retrieve the source records:

```powershell
php bin/sync.php --faculty-profiles-local
```

PULSE stores this copy at `var/integrations/sinq-faculty-profiles.local-test.json`, separate from production data. Start the PULSE API if it is not already running:

```powershell
php -S 127.0.0.1:8000 -t public public/router.php
```

From `pulse-web`, start the frontend if it is not already running:

```powershell
npm.cmd run dev
```

The authenticated `/v1/faculty-profiles` endpoint remains available for future detail views. Individual faculty profiles are not rendered below the dashboard, and this snapshot does not contribute faculty or student headcounts.

After changing profile details through Synk's normal interface, rerun the local profile sync command to update this optional detail snapshot. The dashboard's academic counts are fetched independently from current-term records whenever it refreshes.

## Production mapping for the supplied hosting paths

| Component | Server location or URL |
| --- | --- |
| PULSE Web | `https://pulse-web-sksu.vercel.app` on Vercel |
| PULSE API application directory | `/home/tnrmssks25/pulse_api.sksu-orms.net` |
| Preferred PULSE API document root | `/home/tnrmssks25/pulse_api.sksu-orms.net/public` |
| PULSE API public origin | `https://pulse-api.sksu-orms.net` |
| Synk application/document root | `/home/tnrmssks25/sinq.sksu-orms.net` |
| Synk source URL | `https://sinq.sksu-orms.net/backend/pulse_api.php?resource=faculty-profiles` |

The two filesystem paths are separate PHP applications on the same hosting account. The browser does not use `/home/...` paths. PULSE uses the existing HTTPS Synk endpoint, even when both PHP apps share a server; it does not include Synk PHP files or connect directly to Synk's database.

These are deployment instructions, not a record of changes applied to the live account. Confirm the domain document-root mappings in cPanel.

On the live PULSE API host, keep `.env` outside `public/` and configure:

```dotenv
APP_ENV=production
APP_URL=https://pulse-web-sksu.vercel.app
SINQ_FACULTY_LOCAL_TEST_ENABLED=false
SINQ_API_BASE_URL=https://sinq.sksu-orms.net
SINQ_API_ALLOWED_HOST=sinq.sksu-orms.net
SINQ_FACULTY_PATH=/backend/pulse_api.php?resource=faculty-profiles
SINQ_FACULTY_ENABLED=true
SINQ_API_TOKEN=YOUR_PRIVATE_SOURCE_TOKEN
```

The existing live Synk endpoint must be present and its PHP environment must already provide `SYNK_PULSE_API_TOKEN` matching PULSE's `SINQ_API_TOKEN`. Check this hosting prerequisite before enabling retrieval. This PULSE setup does not edit the Synk application or install its endpoint. Do not upload local database configuration, local tokens, or workstation snapshots to production.

Keep the existing presidential credential and matching `PULSE_PROXY_SECRET` on PULSE PHP and Vercel. In Vercel, `PULSE_API_ORIGIN=https://pulse-api.sksu-orms.net`. The Synk token belongs only on the PHP hosts.

From the live PULSE API directory, using the host's PHP 8.1+ CLI with cURL enabled:

```sh
php bin/check.php --production
php bin/sync.php --faculty-profiles
```

The production copy is `var/integrations/sinq-faculty-profiles.json`. Keep `var/` private and writable by the hosting PHP user. Confirm that the web and CLI PHP processes use the same PULSE configuration and storage path.

An operator can rerun retrieval when needed. If automatic updates are wanted later, schedule the production sync command using cPanel Cron Jobs and the host's actual PHP CLI path. Choose the interval deliberately; no schedule is installed by this setup.

## Acceptance checks and limits

1. Signed-out `GET /api/v1/faculty-profiles` returns 401 and no faculty data.
2. Correct presidential sign-in returns 200; the subsequent session check returns 200.
3. The authenticated faculty endpoint returns `status: connected`, the selected mode, an actual retrieval timestamp, records, and pagination totals after a successful sync.
4. Search and pagination work through the PULSE Web origin, without browser requests to Synk.
5. A source failure preserves the last successful PULSE copy. The displayed retrieval time lets the viewer assess its age; `connected` means a stored copy exists, not that it was fetched on this page load.

The current mode is snapshot retrieval, not continuous real-time streaming. Dashboard summary counts are a separate integration contract and are not automatically populated from faculty profiles.
