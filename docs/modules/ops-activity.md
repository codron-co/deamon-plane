# Activity (jobs, deploys, audits)

`/activity` is the fleet history the jobs widget is not.

The widget at `GET /jobs` is JSON-only, scoped to the signed-in operator, the last
two hours, and twenty rows. Once a row is dismissed it is gone from the UI.
`audit_logs` was written on every theme mutation, force start and publish toggle
and had no page at all.

Activity is one reverse-chronological table that merges:

| Kind | Source | Row opens |
|------|--------|-----------|
| Job | `ops_background_jobs` | `/activity/jobs/{job}` |
| Deploy | `deployments` | the existing site deployment show page |
| Audit | `audit_logs` | `/activity/audits/{audit}` (read-only) |

## Who can see what

Every ops role that can see Sites can open `/activity`. **Another operator's jobs
are visible here.** That is the documented difference from the widget, which stays
`actor_user_id = me` + `ops.write`. A Viewer can read the feed and the job/audit
detail pages; they still cannot call `GET /jobs`.

Audit and job payloads are redacted before render (`SecretRedactor` plus keys
named `secret` / `password` / `token` / `api_key` / `app_key`). The page never
prints a secret value.

## List controls

The page uses the shared async list region (`Vary: X-Ops-List-Fragment`):

- `q` — job title/type/message, site name/slug/domain on a deploy, audit action
- `kind` — `job` \| `deployment` \| `audit` (allowlisted; unknown values drop)
- `outcome` — `ok` \| `failed` \| `cancelled` \| `running` \| `queued`
- `actor` — user id
- `site` — site ULID (jobs match `payload.site_ids` as text)
- `dir` — `asc` \| `desc` on the occurred-at column (default newest first)

A `%` or `_` in `q` is literal (`addcslashes`). Pagination is 25 rows from a
SQL `UNION ALL`, not an unpaginated `->get()`.

Empty vs filtered-empty reuse `ops.partials.filter-chips`. There is no "create"
action — the feed fills as the fleet is used.

## CSV export

`GET /activity/export` downloads the **current filters** as a UTF-8 CSV (BOM
for Excel). Same `ActivityFilters` as the page — a `outcome=failed` download
is the same set the table is showing. Columns are when / kind / outcome /
actor / site / what / detail. Kind and outcome are the operator's locale
labels. Payloads stay out; title and detail are already redacted.

The download is capped at `ops.activity.export_limit` (default 1000) so it
cannot walk the whole audit table. A Viewer can export. A guest is sent to
sign-in. Tests: `ActivityFeedTest`.

## Routes

| Method | Path | Name | Who |
|--------|------|------|-----|
| GET | `/activity` | `ops.activity` | All ops roles |
| GET | `/activity/export` | `ops.activity.export` | All ops roles |
| GET | `/activity/jobs/{job}` | `ops.activity.jobs.show` | All ops roles |
| GET | `/activity/audits/{audit}` | `ops.activity.audits.show` | All ops roles |

Ctrl/⌘+K lists **Geçmiş** / **Activity** with the other top-level pages. It never
offers `GET /jobs`.
