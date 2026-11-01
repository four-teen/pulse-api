# Connecting university systems to PULSE

Current-term faculty and student counts use the separate [read-only academic API connector](synk-academic-counts.md). PULSE makes an authenticated GET to Synk; only the Synk export reads Synk's existing database. No database is required in PULSE. Summary snapshots and the optional profile detail export remain separate; the profile directory must not supply academic headcounts.

The integration foundation is prepared. **No vendor API has been contacted or enabled.** The three supplied links identify the systems' websites; they are not assumed to be API endpoints.

| Source ID | Name | Website | Initial state |
| --- | --- | --- | --- |
| `sinq` | SINQ | https://sinq.sksu-orms.net/ | Disabled |
| `oracle` | Oracle | https://sksu-oracle.com/ | Disabled |
| `pad` | PAD | https://pad-web-coral.vercel.app/ | Disabled |

## Request flow

```text
President's browser
  -> same-origin Vercel /api/v1/* proxy
  -> authenticated PULSE PHP /v1/* endpoints
  -> private validated snapshots in var/integrations/

Later, an operator-controlled CLI command:
  php bin/sync.php --source=<source-id>
  -> explicitly enabled source's confirmed HTTPS summary endpoint
  -> validation and atomic snapshot replacement
```

Loading or refreshing the dashboard reads saved summary/profile copies and, when enabled, retrieves academic counts from Synk using HTTP GET. There is no public sync, token-editing, connection-testing, or source-activation endpoint. No scheduler is installed. HTTP connectors do not scrape the websites or write into another system.

## What is needed when connection work starts

Obtain each system's documented read-only API base URL, summary endpoint, authentication method, metric definitions, reporting period, and campus identifiers. Credentials belong only in the PHP host's `.env` or process environment. Never put them in frontend `VITE_` variables, GitHub, browser code, or query strings.

`PulseSourceConnector` is the adapter boundary. The prepared `PulseNormalizedJsonConnector` accepts the PULSE v1 format below. If a vendor's actual response differs, implement a source-specific adapter that maps it into this contract before enabling the source. Compatibility with the three vendor APIs has not yet been verified.

## Server configuration

For each source, use its uppercase prefix (`SINQ`, `ORACLE`, or `PAD`):

| Variable suffix | Purpose |
| --- | --- |
| `_ENABLED` | Defaults to `false`. An operator explicitly enables a source later. |
| `_API_BASE_URL` | Confirmed HTTPS API base URL. Initially blank. |
| `_SUMMARY_PATH` | Confirmed relative summary path. Initially blank; no route is guessed. |
| `_API_ALLOWED_HOST` | Exact approved API hostname; defaults to the supplied website's hostname. Change only after the API host is confirmed. |
| `_AUTH_TYPE` | `bearer`, `api_key`, or explicit `none` for a documented public API. |
| `_API_TOKEN` | Private credential; initially blank. |
| `_API_KEY_HEADER` | Defaults to `X-API-Key`; used only with `api_key`. |

The HTTP client verifies TLS, rejects redirects and unapproved hosts, applies connection/request timeouts, caps responses at 2 MiB, and requires JSON. It never forwards the president's session cookie to source systems.

## Synk faculty profiles

Faculty profiles use a separate read-only resource because the dashboard summary contract is limited to campus metrics. The single Synk endpoint is `GET /backend/pulse_api.php?resource=faculty-profiles&limit=250&offset=0`, protected by a bearer token. It only exports the faculty name, campus, college, faculty rank, education degree/institution/specialization/status, and source update time. It excludes date of birth, civil status, salary, contact details, account data, and faculty ID numbers. The source-side endpoint accepts GET only and does not write to Synk.

The PULSE API fetches profiles only when an operator runs `php bin/sync.php --faculty-profiles`. It validates every page and replaces the private local snapshot only after a complete successful fetch. The presidential browser reads that stored snapshot through authenticated `GET /v1/faculty-profiles`; opening or refreshing the page does not contact Synk. No automatic schedule is installed.

Configure the PHP hosting environment for Synk with `SYNK_PULSE_API_TOKEN`. Set the same random secret as `SINQ_API_TOKEN` in the PULSE API `.env`, then set:

```dotenv
SINQ_API_BASE_URL=https://sinq.sksu-orms.net
SINQ_API_ALLOWED_HOST=sinq.sksu-orms.net
SINQ_FACULTY_PATH=/backend/pulse_api.php?resource=faculty-profiles
SINQ_FACULTY_ENABLED=true
```

Keep `SINQ_API_TOKEN` private on the PULSE PHP host; do not put it in Vercel or a `VITE_` variable. The Synk endpoint file must be deployed to the live app before enabling this source. Set the source token in the cPanel PHP environment, never in the endpoint source.

### Local-only test mode

The local integration is isolated from the live source. The PULSE API loads `.env.local` only when `APP_ENV=development`; production ignores it. Copy `.env.local.example` to `.env.local`, set a private test token of at least 32 characters, and keep `SINQ_FACULTY_ENABLED=false` in the base `.env`. The local source is hard-limited to `http://127.0.0.1:8081`, requires a local Synk database config, and writes to `var/integrations/sinq-faculty-profiles.local-test.json` rather than the production snapshot filename.

Start local Synk with `powershell -ExecutionPolicy Bypass -File bin/start-local-sinq.ps1` from the PULSE API folder. The helper binds only to loopback and passes the test token to the local PHP process. In another terminal, run `php bin/sync.php --faculty-profiles-local`. Then run the normal local PULSE API and web dev servers; the Faculty profiles page displays a **LOCAL TEST · LOOPBACK ONLY** label. This mode never calls the live Synk subdomain.

## Category ownership and reporting periods

Assign exactly one authoritative source per category after reviewing the fields:

```dotenv
PULSE_INSTRUCTION_SOURCE=
PULSE_RESEARCH_SOURCE=
PULSE_EXTENSION_SOURCE=
PULSE_PRODUCTION_SOURCE=
PULSE_INVENTORY_SOURCE=
PULSE_FINANCE_SOURCE=
DASHBOARD_PERIOD_KEY=
DASHBOARD_PERIOD_LABEL=
```

Ownership values are `sinq`, `oracle`, or `pad`. Blank means unassigned. PULSE does not guess a source's role from its name, combine duplicate source records, or silently fall back to a different source. A snapshot must match `DASHBOARD_PERIOD_KEY` before contributing data. The optional label is the human-readable period shown in the interface.

## Normalized snapshot contract, version 1

The source endpoint or adapter returns:

```json
{
  "schemaVersion": 1,
  "sourceId": "sinq",
  "periodKey": "agreed-reporting-period",
  "observedAt": "2026-10-30T00:00:00Z",
  "campuses": [
    {
      "id": "access",
      "instruction": {
        "students": null,
        "previousStudents": null,
        "faculty": null,
        "programs": null,
        "retention": null
      }
    }
  ]
}
```

Replace the example date and period with the actual records' timestamp and agreed period. `observedAt` is the age of the underlying records, not the time the dashboard was opened. Dates in the future beyond a five-minute clock tolerance are rejected.

The campus list may contain zero to seven records. Supported IDs are `access`, `isulan`, `tacurong`, `kalamansig`, `lutayan`, `palimbang`, and `bagumbayan`. Map external campus codes explicitly; duplicate and unknown IDs are rejected. PULSE always returns its complete seven-campus directory to the frontend.

Groups and fields may be omitted if a source does not supply them. An omitted or explicit `null` value means **unavailable**. A numerical `0` means **a confirmed zero**. Unknown field names within a group are rejected to catch mapping mistakes.

| Group | Fields | Units / interpretation |
| --- | --- | --- |
| `instruction` | `students`, `previousStudents`, `faculty`, `programs`, `retention` | Counts; retention is 0–100%. Programs are campus offerings, not deduplicated university-wide degrees. |
| `research` | `active`, `completed`, `publications`, `funding` | Counts; funding in Philippine pesos. Active and completed studies must be disjoint within the same period. |
| `extension` | `active`, `completed`, `communities`, `beneficiaries`, `sessions` | Counts. Community and beneficiary totals represent campus records unless deduplication is implemented in the source adapter. |
| `production` | `enterprises`, `revenue`, `expenses`, `target` | Enterprise count; monetary values in Philippine pesos. Net income is derived as revenue minus expenses and can be negative. |
| `inventory` | `buildings`, `classrooms`, `laboratories`, `assets`, `serviceable`, `value` | Counts; asset book value in Philippine pesos. Serviceable assets cannot exceed total assets. |
| `finance` | `budget`, `utilization` | Budget in Philippine pesos; utilization is 0–100%. |

Counts must be integers between 0 and 1,000,000,000. Monetary values must be finite, nonnegative JSON numbers no greater than 1,000,000,000,000,000. Formatted strings, booleans, negative counts and invalid percentages are rejected.

University totals remain unavailable until **every selected campus** has reported the required metric. PULSE never presents a partial sum as a complete university total. Retention is weighted by enrollment; budget utilization is weighted by allocation. Zero denominators yield unavailable ratios, not `NaN` or invented zero percentages.

## Sync, freshness and recovery

These checks make no remote requests:

```powershell
php bin/sync.php --check
php bin/check.php
```

After endpoints, adapter mappings, credentials, period and ownership are confirmed, an operator may explicitly enable a source and run:

```powershell
php bin/sync.php --source=sinq
```

This command is prepared for later use; do not enable it until the actual API contract is agreed. Each successful response is validated before storage. Writes use a source lock and atomic replacement. Older snapshots cannot replace newer ones. A failed request or validation leaves the previous successful snapshot intact. Operational errors do not print source response bodies or credentials.

`DASHBOARD_DATA_MAX_AGE_SECONDS` defaults to 86400. Old records remain visible with an overdue indicator and their original timestamp. Disabled sources stop contributing records, even if a cached file exists. Corrupt snapshots and mismatched periods are excluded. Sync failures are reported by the CLI; no automated retry service or remote health monitor is installed. For a multi-server deployment, replace local snapshot/session storage with a shared store before scaling.

## Future adapters

Keep vendor-specific field/campus mapping inside the adapter, not React. Add source or category mappings only after reviewing real documentation or approved example responses. The president-facing interface already consumes the normalized PULSE endpoint, so adapter changes do not require exposing vendor credentials or URLs in frontend code.
