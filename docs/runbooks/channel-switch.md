# Runbook: Channel switch

Internal ops only. Do not paste Coolify tokens, `APP_KEY`, or agent secrets into tickets or this file.

Channel = Coolify `git_branch` (`main` | `beta` | `alpha`) + redeploy. **Never delete or recreate the Coolify application to change channel.**

## Preconditions

1. Site status is **active** (or **error** after a failed switch). Draft sites must be provisioned first.
2. `coolify_app_uuid` is set. Coolify menu has a base URL + API token.
3. Target is on the allowlist: `main`, `beta`, `alpha`. Other branches cannot be chosen in the UI.
4. Status is not `deploying` or `provisioning` — concurrent switches are blocked.
5. You accept that volumes (MySQL, Redis, themes, storage) stay attached to this app uuid.

## Policy

| From → to | Gate |
|-----------|------|
| `main` → `beta` / `alpha` | Confirm modal required (downgrade). Server rejects the POST without `confirmed`. |
| `alpha` / `beta` → `main` | Version gate. Reads `sites.last_health_payload.deamon_version` when present. **Missing health does not block.** |
| `beta` ↔ `alpha` | No confirm, no version gate. |
| Force | Super Admin only. Bypasses the version gate. Audited as `forced`. Operators cannot force. |

Viewer can see the site; they cannot POST `/sites/{site}/channel`.

## Steps

1. Plane → **Sites** → open the site. Confirm current channel chip.
2. **Channel switch** panel: pick the target. Read the volume note. Take a customer DB/volume backup before leaving **main** (v1 reminder — Plane does not snapshot Coolify volumes).
3. If leaving **main**, the confirm modal must be accepted (`PlaneConfirm` — not `window.confirm`).
4. Super Admin switching **to main** against a failing version gate may check **Force**.
5. Plane sets `desired_channel`, status **deploying**, writes `site.channel_switch_started`.
6. Job calls Coolify `PATCH /applications/{uuid}` with `{ git_branch }` (and `environment_uuid` when a Coolify environment name matches the channel), writes `APP_ENV` + `DEAMON_CHANNEL` via `/envs/bulk`, then `POST /deploy`. No `DELETE /applications/{uuid}`.
7. A `deployments` row (`trigger=channel_switch`) is stored. Plane polls until finished/failed (same poll job as provision).
8. Success: `channel = desired_channel`, `desired_channel` cleared, status **active**, audit `site.channel_switched`. Live agent health is a separate poll; missing `deamon_version` does not block this switch.
9. Failure: status **error**, `channel` unchanged, `desired_channel` kept so you can see the attempt, audit `site.channel_switch_failed`.

## Volumes (ADR-7)

Compose volume names are scoped to the Coolify app. Branch change does **not** remove MySQL, Redis, themes, or storage.

**Forbidden:** `DELETE /applications/{uuid}`. Coolify `delete_volumes` defaults to **true** — that destroys customer data. There is no shared MySQL/Redis “fix”. Roll back by switching the channel again (manual in v1).

## Failure notes

| Symptom | What to do |
|---------|------------|
| Flash: confirmation required | Leave-main POST without `confirmed`. Use the modal. |
| Flash: version gate | Reported `deamon_version` is below `DEAMON_MAIN_MINIMUM_VERSION`. Wait for a CMS upgrade, or Super Admin force. |
| Flash: only Super Admin can force | Operator sent `force`. |
| Flash: deploy already in progress | Wait until status leaves `deploying`. |
| Status `error`, channel unchanged | Coolify PATCH/deploy/poll failed. App still exists. Retry the switch or switch back to the previous channel. Do **not** delete the app. |
| Wrong branch in Coolify | Re-run switch to the intended allowlist channel. |

ZIP, SSH, and Mailcow are out of scope. Tests use `Http::fake` only — do not point a laptop at production Coolify to “try” a channel switch.
