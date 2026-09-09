# Ops Sites (desired state)

Draft CRUD for Coolify-hosted Deamon sites. Create/edit still write desired state only. **Provision** (`POST /sites/{site}/provision`) is the Coolify orchestration path (Task 4). **Channel switch** (`POST /sites/{site}/channel`) is Task 5.

## Routes

| Method | Path | Name | Who |
|--------|------|------|-----|
| GET | `/sites` | `ops.sites` | All ops roles |
| GET | `/sites/create` | `ops.sites.create` | operator, super_admin |
| POST | `/sites` | `ops.sites.store` | operator, super_admin |
| GET | `/sites/{site}` | `ops.sites.edit` | All ops roles (viewer read-only) |
| PUT | `/sites/{site}` | `ops.sites.update` | operator, super_admin |
| POST | `/sites/{site}/provision` | `ops.sites.provision` | operator, super_admin; draft or error only |
| POST | `/sites/{site}/channel` | `ops.sites.channel` | operator, super_admin; active or error with `coolify_app_uuid`; blocked while `deploying` |
| POST | `/sites/{site}/health` | `ops.sites.health` | operator, super_admin; on-demand agent poll |
| POST | `/sites/{site}/agent-secret` | `ops.sites.agent-secret` | operator, super_admin; Coolify env inject |
| DELETE | `/sites/{site}` | `ops.sites.destroy` | operator, super_admin |

Coolify connections live under `/coolify` (`ops.coolify.*`) — left nav **Coolify**. See [coolify-client.md](coolify-client.md).

Routes live in `routes/ops/sites.php` (required from `routes/web.php`).

## Fields

Create/edit desired state: `slug`, `name`, `domain` (`sites.primary_domain` + primary `site_domains` row), `channel` (`main` \| `beta` \| `alpha` only — no free-typed branch), Coolify **selects** (connection, active server / project / environment / Git source), optional attach of an existing `codron-co/deamon` app, `notes`.

Coolify UUIDs are **not** free-text on site create. Super Admin may open a collapsed, warned “Gelişmiş” paste. Compose file is never an operator field — always `/docker-compose.coolify.yml`.

**Attach existing:** dropdown of customer apps on the selected connection (`CoolifyFleetClassifier`). Sets `coolify_app_uuid` + domain + channel from `git_branch` if it is `main|beta|alpha`; otherwise `channel_needs_review` (channel stays an allowlisted pick). Does **not** `POST` a second create.

**Agent secret:** site edit **Generate & inject secret** (`POST /sites/{site}/agent-secret`) writes `CONTROL_PLANE_AGENT_SECRET` via Coolify `updateEnvs`. Encrypted on the site; never shown again. Provision also attempts inject after create. Runbook: [agent-secret-inject.md](../runbooks/agent-secret-inject.md).

- Status is always **draft** on create. The form cannot change status.
- `APP_KEY` / `agent_secret` are generated on **Provision**, stored encrypted, never shown in the form or audit payloads.
- Channel and slug are locked on the CRUD form once status is not `draft`. Live switches use the Channel switch panel (`ChannelSwitcher`).
- Destroy is a **soft delete** with the ops confirm modal (`data-confirm` / `PlaneConfirm.ask`). `window.confirm` is not used. Provision is not a confirm-gated destroy. Leaving **main** for beta/alpha uses the same confirm modal.

## Provision

`SiteProvisioner` + `ProvisionSiteJob` + `PollDeploymentJob`. Runbook: [provision-site.md](../runbooks/provision-site.md).

- Eligible statuses: `draft`, `error` (retry). Viewer is forbidden.
- Coolify: git + `build_pack=dockercompose` + `docker_compose_location=/docker-compose.coolify.yml`; env `APP_KEY` + `DEAMON_SITE_NAME` (+ `CONTROL_PLANE_AGENT_SECRET` inject). Domain on compose service `app`.
- Preflight before create POST (Turkish): selected server in live `listServers`; Git source in live sources list. 422 `errors` are shown on the provision flash and in the audit error field.
- Success → `coolify_app_uuid` + status `active`. Agent health is a separate poll (does not gate provision). Failure → `error` + audit.
- Retry reuses an existing Coolify app uuid (does not DELETE the app).

## Channel switch

`ChannelSwitcher` + `SwitchSiteChannelJob` + shared `PollDeploymentJob` (trigger `channel_switch`). Runbook: [channel-switch.md](../runbooks/channel-switch.md).

- Eligible: `active` or `error` with `coolify_app_uuid`. Status `deploying` / `provisioning` is rejected.
- Coolify: `updateBranch` (`PATCH git_branch`) + `deploy`. Never `DELETE` the application. Volumes persist (ADR-7).
- During the switch: `desired_channel` is set and status is `deploying`. Success copies it to `channel` and clears `desired_channel`.
- Policy config `config/ops.php` → `channel_switch`: `main` → `beta`/`alpha` requires confirm; `alpha`/`beta` → `main` is a version gate. When last health has `deamon_version`, the minimum is enforced. Missing health does **not** block. Force is Super Admin only.
- Audit: `site.channel_switch_started`, `site.channel_switched`, `site.channel_switch_failed`.

## Deployments

Site detail includes a **Deployments** section (`ops/deployments/index`): last 25 rows, status chip, duration, commit, **Open in Coolify**. Rows are written by provision/channel jobs and updated by Coolify webhooks or `PollDeploymentJob`. See [coolify-webhooks.md](coolify-webhooks.md).

## Policy

`App\Policies\SitePolicy`: viewer can `viewAny` / `view`. `create` / `update` / `delete` / `provision` / `switchChannel` / `checkHealth` require `User::canWriteOps()` (operator or super_admin). Force on the version gate is Super Admin only (enforced in `ChannelSwitcher`, not a separate Gate).

## Agent health

`SiteAgentClient` + `CheckSiteHealthJob` (schedule 5–15 min) + on-demand **Check health**. Persists `last_health_at` / `last_health_payload` summary. Sites without `agent_secret` are `needs_secret` (import does not invent secrets). Contract and header names: [agent-client.md](agent-client.md). Secret inject: [agent-secret-inject.md](../runbooks/agent-secret-inject.md).

## List

GET filters with `withQueryString`: `q` (name / slug / domain), `channel`, `status`. Search input debounces a GET submit (300 ms). No Coolify client on the list page.

Imported sites whose Coolify `build_pack` is `dockerfile` keep a `dockerfile_build_pack` line in `notes`. The list shows a **Dockerfile (eski pack)** chip next to the name; edit shows a warning that pack is dockerfile (not dockercompose), that provision/channel still use the existing app UUID, and that compose migration is later — not a skip. Fleet home keeps the same 5 KPI cards and lists those sites in a left attention row (**Dockerfile (eski pack)** / Compose'a geçirilmedi) — pack flag only, not CMS `deamon_version`.

## Import existing Coolify apps

Artisan `ops:import-coolify-apps` (Task 7). Default is **dry-run**; `--apply` upserts. Classification, domain parse, and status rules: [import-coolify-apps.md](../runbooks/import-coolify-apps.md).

- Customer filter: `DEAMON_GIT_REPOSITORY` / `codron-co/deamon`, excluding `deamon-plane`, `entron`, `webapp-transfer`, theme repos.
- Upsert by `coolify_app_uuid` then primary domain. Slug from host / app name on create only.
- No agent secrets. `dockerfile` is imported with a notes flag + visible list/edit warning; compose preferred.
- Duplicate domain in one import (two Coolify uuids, same host): first write wins, second **skip** — not merged.
