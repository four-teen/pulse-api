# Synk current-term faculty and enrolled-student counts

This is the authoritative headcount source for PULSE. The older faculty-profile export is an all-time profile directory and must not supply academic headcounts.

## Counting rules

The academic year and semester come from the single row in `tbl_academic_settings`, joined to `tbl_academic_years`. PULSE reads the administrator's setting on every dashboard request. It never derives the academic term from the calendar, a scheduler's personal working-term selection, or Management Midterm/Final settings.

**Faculty:** active `tbl_college_faculty` assignments joined to existing `tbl_faculty`, `tbl_college`, and `tbl_campus` records, where `ay_id` and `semester` exactly match the administrator setting. The faculty member does not need a teaching load to count: membership in the Scheduler college faculty list is the criterion. Count distinct `faculty_id` independently university-wide, per campus, and per college. Use the assignment's college and that college's campus, not the faculty profile's permanent affiliation. Inactive assignment rows, other terms, and undated legacy assignments are excluded. A faculty ID that no longer resolves to a faculty record is excluded, matching the Scheduler list's inner join.

**Enrolled students:** active (`is_active=1`) rows of `tbl_student_management_enrolled_subjects`, joined to `tbl_student_management`, in that same academic year and semester. Count unique complete student names independently university-wide, per campus, per college, and per program. Use the enrollment membership's program/college/campus, and reject conflicting unit mappings. Do not count subject rows, class-size estimates, all-time directory entries, inactive memberships, retained cleared-class history, draft enrollment forms, or registrar-approved forms without an active imported class-list membership.

The student identity key preserves the separate last, first, middle, and suffix name fields. Unicode accents are composed, case is normalized, and spacing/punctuation separators are normalized. No fuzzy matching, accent removal, or middle-initial expansion is used. Identical normalized complete names under different student IDs are counted once under the requested name-based rule. Different people with identical complete names cannot be distinguished by this rule; the aggregate `studentNamesWithMultipleIds` count exposes potential collisions without disclosing identities. If institutional headcount later needs person-level identity, adopt validated student IDs as a separate explicit rule.

Each level is deduplicated independently. A faculty member serving two colleges counts once in each college, once in each relevant campus, and once in the university total. The same principle applies to student names with multiple program memberships. Do not calculate university totals by summing unit rows.

Zero means no qualifying source records in that scope, not an estimate of unrecorded enrollment. The dashboard labels the student basis as active class lists. Missing configuration, an invalid administrator term, unsupported schema, missing campus mappings, and conflicting memberships produce unavailable headcounts rather than a partial or stale substitute.

## API-only data flow

```text
PULSE Web -> authenticated PULSE API -> HTTPS GET to Synk's pulse_api.php
                                      -> Synk uses its existing database connection
                                      -> distinct aggregate counts returned as JSON
```

PULSE needs no database, database user, database credentials, or direct SQL connection. `src/SynkAcademics.php` is an HTTP client. It sends a bearer token server-to-server, checks the response size, freshness, schema, period and unit hierarchy, and reconstructs an aggregates-only response. Source extras, names, hashes, and credentials are discarded. Production requires HTTPS with certificate validation and an approved hostname; redirects are rejected.

The prepared Synk helper uses Synk's own existing `backend/db.php` connection. It calculates the same counts in a read-only transaction. It does not create tables, edit source records, or run schema helpers. Nothing is installed into the local Synk reference app by these PULSE changes.

## Live setup: three small changes

### 1. Deploy the updated PULSE API

Push/deploy this PULSE API version to `/home/tnrmssks25/pulse_api.sksu-orms.net`. The earlier direct-database version cannot use this new API-only configuration. No frontend code change is needed: the dashboard JSON contract is unchanged.

### 2. Upload the prepared Synk export

From this repository's `integrations/synk/backend/`, upload both files to `/home/tnrmssks25/sinq.sksu-orms.net/backend/`:

- `pulse_api.php` (replace the older profile-only export)
- `pulse_academic_counts.php` (new helper)

Keep Synk's existing `db.php` and its database configuration unchanged. The new resource is:

`https://sinq.sksu-orms.net/backend/pulse_api.php?resource=academic-counts`

Both source files belong in Synk's `backend/`, not PULSE API's `public/`. The old file alone supported faculty profiles, not current-term academic counts. The new endpoint preserves the existing `faculty-profiles` resource as well.

### 3. Set the same private API token on both servers

Choose a private random token of at least 32 characters; 64 random hexadecimal characters is a convenient choice. Do not use the placeholder text below as a token. If Synk already has `SYNK_PULSE_API_TOKEN` configured in its PHP environment, use that existing private value.

If configuring a PHP environment variable in cPanel is inconvenient, copy `pulse_api_config.example.php` to the live Synk `backend/` folder, rename it **`pulse_api_config.local.php`**, and edit it:

```php
<?php
return ['token' => 'YOUR_PRIVATE_RANDOM_API_TOKEN'];
```

The actual local config file is ignored by Git. It contains no database settings. The environment variable takes precedence if already configured. Keep the secret private.

In **PULSE API's production `.env`**, add/update each setting once:

```dotenv
SINQ_ACADEMICS_ENABLED=true
SINQ_API_BASE_URL=https://sinq.sksu-orms.net
SINQ_API_ALLOWED_HOST=sinq.sksu-orms.net
SINQ_ACADEMICS_PATH=/backend/pulse_api.php?resource=academic-counts
SINQ_API_TOKEN='YOUR_PRIVATE_RANDOM_API_TOKEN'
```

Use exactly the same token as Synk. Keep `SINQ_ENABLED=false`: that flag controls the separate summary feed, not academic counts. Keep `APP_ENV=production`, the existing presidential credentials, and the proxy secret. Any old `SINQ_ACADEMICS_DB_*` settings can be removed; they are no longer read. Do not place the Synk API token in Vercel or a `VITE_` variable.

Refresh the PULSE dashboard. `academicCounts.status` should be `connected` with the administrator's current academic year/semester. If it is still unavailable, run `php bin/check-academics.php` from the PULSE API folder using the host's PHP CLI; configuration messages print no token or source data.

## Understanding errors

- Source `503 Service is not configured`: the Synk API token is missing, too short, or still a placeholder.
- Source `401 Unauthorized`: PULSE's token and Synk's token do not match, or the host did not forward the Authorization header to PHP.
- Source `404`: the old profile-only export is still deployed or the endpoint path is wrong.
- Source `500`: inspect Synk's private PHP error log for source schema, connection, or academic-setting problems. The API deliberately does not expose internal details.
- PULSE `disabled`: `SINQ_ACADEMICS_ENABLED` is not true on the live PULSE API.
- PULSE `unavailable`: the source request, configuration, response validation, or token check failed.

An anonymous visit to the Synk source URL should not return private counts: a configured endpoint responds 401 without a token. Test through the signed-in PULSE dashboard or the private CLI check, never by putting the token in a URL.

## Local development without editing Synk

Set these in PULSE API's ignored development `.env.local`:

```dotenv
SINQ_ACADEMICS_ENABLED=true
SINQ_ACADEMICS_LOCAL_TEST_ENABLED=true
SINQ_FACULTY_LOCAL_TEST_TOKEN=YOUR_PRIVATE_LOCAL_TEST_TOKEN
```

The existing `.env.local` token can be reused for this loopback exercise. In `pulse-api`, run:

```powershell
powershell -ExecutionPolicy Bypass -File bin/start-local-sinq.ps1
```

The helper serves the prepared export on port 8081 through `bin/synk-export-router.php`, using Synk's existing local database connection without replacing any Synk files. Start the normal PULSE API on port 8000 and PULSE Web on port 5173. The academic client makes a GET to the loopback API; production rejects this development HTTP mode.

Verification:

```powershell
php bin/check.php
php bin/check-academics.php
php tests/academic-counts.test.php
php tests/academic-api-validation.test.php
node --test tests/academic-api-http.test.mjs tests/auth.test.mjs
```

`check-academics.php` prints aggregate counts and period only. Pure counting/validation tests use synthetic data. HTTP tests use isolated loopback source fixtures. `php tests/academic-source-smoke.php --local` explicitly verifies the local HTTP result against independent aggregate SQL; that opt-in test is the only PULSE test utility with direct local source database access.

## President view and contract

Sign in and expand **Faculty & enrollment breakdown** for campus, college, and student-program views. The global campus filter scopes the breakdown. Per-program faculty counts are not inferred from teaching assignments because the requested faculty membership source is the college list. The zero-count toggle reveals units without qualifying records.

`academicCounts` includes `status`, `term`, `retrievedAt`, `studentBasis: active_class_lists`, `faculty`, `students`, `colleges`, `programs`, and count-quality indicators. The legacy `facultyDirectory` field mirrors the academic faculty aggregate for compatibility, not the all-time profile directory.

The counting rules above are unchanged by the move to HTTP. The browser receives no faculty/student records or API tokens. The Synk export performs GET/SELECT only and preserves source records. This implementation does not deploy itself; the live files and shared token must be configured as described above.