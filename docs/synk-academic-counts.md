# Synk current-term faculty and enrolled-student counts

This is the authoritative headcount source for PULSE. The older faculty-profile export is an all-time profile directory and must not supply academic headcounts.

## Counting rules

The academic year and semester come from the single row in `tbl_academic_settings`, joined to `tbl_academic_years`. PULSE reads the administrator's setting on every dashboard request. It never derives the academic term from the calendar, a scheduler's personal working-term selection, or Management Midterm/Final settings.

**Faculty:** active `tbl_college_faculty` assignments joined to existing `tbl_faculty`, `tbl_college`, and `tbl_campus` records, where `ay_id` and `semester` exactly match the administrator setting. The faculty member does not need a teaching load to count: membership in the Scheduler college faculty list is the criterion. Count distinct `faculty_id` independently university-wide, per campus, and per college. Use the assignment's college and that college's campus, not the faculty profile's permanent affiliation. Inactive assignment rows, other terms, and undated legacy assignments are excluded. A faculty ID that no longer resolves to a faculty record is excluded, matching the Scheduler list's inner join.

**Enrolled students:** active (`is_active=1`) rows of `tbl_student_management_enrolled_subjects`, joined to `tbl_student_management`, in that same academic year and semester. Count unique complete student names independently university-wide, per campus, per college, and per program. Use the enrollment membership's program/college/campus, and reject conflicting unit mappings. Do not count subject rows, class-size estimates, all-time directory entries, inactive memberships, retained cleared-class history, draft enrollment forms, or registrar-approved forms without an active imported class-list membership.

The student identity key preserves the separate last, first, middle, and suffix name fields. Unicode accents are composed, case is normalized, and spacing/punctuation separators are normalized. No fuzzy matching, accent removal, or middle-initial expansion is used. Identical normalized complete names under different student IDs are counted once under the requested name-based rule. Different people with identical complete names cannot be distinguished by this rule; the aggregate `studentNamesWithMultipleIds` count exposes potential collisions without disclosing identities. If institutional headcount later needs person-level identity, adopt validated student IDs as a separate explicit rule.

Each level is deduplicated independently. A faculty member serving two colleges counts once in each college, once in each relevant campus, and once in the university total. The same principle applies to student names with multiple program memberships. Do not calculate university totals by summing unit rows.

Zero means no qualifying source records in that scope, not an estimate of unrecorded enrollment. The dashboard labels the student basis as active class lists. Missing configuration, an invalid administrator term, unsupported schema, missing campus mappings, and conflicting memberships produce unavailable headcounts rather than a partial or stale substitute.

## PULSE-owned read-only flow

```text
PULSE Web /api/v1/president/dashboard
  -> authenticated PULSE API
  -> local Synk database in a READ ONLY, REPEATABLE READ transaction
  -> distinct university/campus/college/program aggregates
  -> browser receives counts, unit labels, term, and retrieval time only
```

`src/SynkAcademics.php` belongs to PULSE API. It runs fixed parameterized SELECT queries and a read-only transaction; it does not call Synk bootstrap/schema helpers, create tables, update academic settings, or alter records. All reads share one consistent transaction snapshot. Names and transient identity hashes stay inside the PHP request; they are not returned or saved by this reader. Connections are restricted to a local database host (`localhost`, `127.0.0.1`, or `::1`).

Academic counts are separate from the configured research, extension, production, and other snapshot feeds. When the administrator term is available, summary snapshots must match its `YYYY-YYYY:semester` key to contribute. The existing faculty-profile snapshot remains available to its authenticated detail endpoint but is never a headcount fallback.

## Local development

PULSE Web: `C:\VertrigoServ\www\pulse-web`, `http://127.0.0.1:5173`.

PULSE API: `C:\VertrigoServ\www\pulse-api`, `http://127.0.0.1:8000`.

Synk reference app: `C:\VertrigoServ\www\synk`. No Synk file or schema change is required.

In PULSE API's ignored `.env.local`:

```dotenv
SINQ_ACADEMICS_ENABLED=true
```

With `APP_ENV=development` and no explicit `SINQ_ACADEMICS_DB_NAME`, the reader reuses the existing sibling `synk/backend/db_config.local.php` connection array. It never includes `backend/db.php`. A dedicated local SELECT-only account can instead be configured using the production variables below. Neither the port 8081 profile server nor a profile-sync command is needed for current-term academic counts.

Run these checks from `pulse-api`:

```powershell
php bin/check.php
php bin/check-academics.php
php tests/academic-counts.test.php
```

`check-academics.php` contacts the source using a read-only transaction and prints aggregate totals and term only. The unit tests use synthetic rows and do not connect to the database.

Sign in to the local frontend. The overview cards show distinct faculty and enrolled-student names. Expand **Faculty & enrollment breakdown** for campus, college, and program views. The global campus filter also scopes the breakdown. **By program** shows students only because faculty membership is defined per college, not inferred from teaching assignments. The zero-count toggle lets the president inspect units with no qualifying imported records.

Refresh the dashboard after source changes or a change to the administrator's academic term. No scheduler or cron job is required for these live database reads.

## Production on the shared cPanel host

PULSE API application: `/home/tnrmssks25/pulse_api.sksu-orms.net`.

Preferred PULSE API document root: `/home/tnrmssks25/pulse_api.sksu-orms.net/public`.

Synk application: `/home/tnrmssks25/sinq.sksu-orms.net`.

The two apps share a machine but have independent configuration. Configure a private database account with **SELECT only** on the Synk database tables below. This work has not created accounts, granted permissions, or modified the live host. An authorized hosting administrator must provision that account before enabling the live reader.

Required tables:

- `tbl_academic_settings`, `tbl_academic_years`
- `tbl_campus`, `tbl_college`, `tbl_program`
- `tbl_college_faculty`, `tbl_faculty`
- `tbl_student_management_enrolled_subjects`, `tbl_student_management`

Set PULSE API's private production `.env` or PHP process environment:

```dotenv
APP_ENV=production
APP_URL=https://pulse-web-sksu.vercel.app
SINQ_ACADEMICS_ENABLED=true
SINQ_ACADEMICS_DB_HOST=localhost
SINQ_ACADEMICS_DB_PORT=3306
SINQ_ACADEMICS_DB_NAME=YOUR_ACTUAL_CPANEL_SYNK_DATABASE
SINQ_ACADEMICS_DB_USER=YOUR_SELECT_ONLY_DATABASE_USER
SINQ_ACADEMICS_DB_PASSWORD=YOUR_PRIVATE_DATABASE_PASSWORD
```

Use the actual database name/user configured in cPanel, not the domain directory path. Keep the existing presidential credential and proxy secret. Do not upload `.env.local`, copy local database credentials to production, or place these variables in Vercel or any `VITE_` setting. Production never imports the sibling Synk configuration file. PHP needs `mysqli` with mysqlnd and `mbstring`; `intl` is optional for full Unicode normalization. The source tables must support consistent snapshots (InnoDB).

Keep Vercel `PULSE_API_ORIGIN=https://pulse-api.sksu-orms.net` and the matching server-side `PULSE_PROXY_SECRET`. No Synk token or database credential belongs on Vercel.

From the production PULSE API folder, using the host's actual PHP 8.1+ CLI binary:

```sh
php bin/check.php --production
php bin/check-academics.php
```

Then verify authenticated `GET /api/v1/president/dashboard` through the deployed frontend: `academicCounts.status=connected`, correct administrator term, nonnegative totals, unit breakdowns, no personal names or source credentials, and HTTP 401 while signed out. Deploy both PULSE API and PULSE Web for the new data contract. No live deployment has been performed here.

## Dashboard contract

`academicCounts` includes `status`, `term`, `retrievedAt`, `studentBasis: active_class_lists`, `faculty`, `students`, `colleges`, `programs`, and count-quality indicators. `faculty` and `students` each contain a distinct `total` and `byCampus` map keyed by PULSE's seven campus IDs. College rows contain source unit IDs/labels, campus ID, faculty count, and student count. Program rows contain source unit IDs/labels, parent college/campus IDs, and student count.

The legacy `facultyDirectory` count field now mirrors the academic faculty aggregate for compatibility; it no longer uses the all-time profile directory. New UI code reads `academicCounts` directly.
