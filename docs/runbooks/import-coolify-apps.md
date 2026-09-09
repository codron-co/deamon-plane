# Runbook: Import existing Coolify apps

One-shot fleet inventory: Coolify `GET /applications` → Plane `sites` upsert. Plan §5.5 / Task 7.

Do not paste API tokens, `APP_KEY`, or agent secrets into tickets or this file. Tests use `Http::fake` only — do not point a laptop at production Coolify to “try” import.

## Preconditions

1. Coolify connection is saved in Plane **Settings** (base URL + API token). Env `COOLIFY_*` is a fallback when the DB row is empty.
2. Plane schema is migrated (`sites`, `site_domains`, `audit_logs`).
3. You are on the Plane host / ops shell (internal). This is not a customer CMS command.

## Always dry-run first

```bash
php artisan ops:import-coolify-apps
# optional tag filter (Coolify ?tag=):
php artisan ops:import-coolify-apps --tag=deamon
```

Default is **dry-run**. It prints a table (`uuid`, `name`, `repo`, `branch`, `pack`, `domain`, `action`) plus notes. **No writes.**

`action`:

| Value | Meaning |
|-------|---------|
| `create` | New `sites` row would be inserted |
| `update` | Match by `coolify_app_uuid`, else primary domain |
| `skip` | Not a Deamon customer site, unsupported pack, missing domain, or conflict |

When the table looks right:

```bash
php artisan ops:import-coolify-apps --apply
```

`--apply` writes. Agent secrets are **not** generated (later env-patch job). Mailcow, ZIP, SSH, and shared DB/Redis are out of scope.

## Classification (customer vs skip)

A Coolify app is a **Deamon customer site** when `git_repository`:

- equals `config('ops.deamon.repository')` / `DEAMON_GIT_REPOSITORY`, **or**
- refers to `codron-co/deamon` (exact repo, not a prefix),

**and** it is **not** `deamon-plane`.

Always skipped:

| Pattern | Why |
|---------|-----|
| `deamon-plane` | This control plane, not a CMS site |
| `entron` | Other product |
| `webapp-transfer` | Transfer tool |
| `deamon-theme-*` / `config('ops.themes.repo_prefix')` | Theme git repos |
| `build_pack` other than `dockercompose` / `dockerfile` | Not a Deamon CMS install shape |
| Empty uuid, or create without `fqdn` / `app.domain` | Cannot upsert safely |
| Soft-deleted site already occupies uuid/domain | Unique indexes; do not restore |

`dockercompose` is preferred. **`dockerfile` is still imported** with a `dockerfile_build_pack` flag in `sites.notes` (many live sites still use Dockerfile). Nixpacks / static / raw compose-without-git are skipped.

## Domain

1. Coolify `fqdn` when present (host only, no scheme).
2. Else parse `docker_compose_domains` — live shape is often a **JSON string** object; read **`app.domain`** (first comma-separated host). Spike: compose apps frequently have `fqdn=null`.

Slug on **create** only: first label of the host (strip `www.`), else slugified app name (strip leading `deamon-`). Collision → `-2`, `-3`. Updates never rename slug.

## Channel and status

- Channel = `git_branch` when it is `main` \| `beta` \| `alpha`.
- Any other branch (or empty): **`needs_review`**. `sites.channel` is stored as **`main`** (placeholder — the enum/allowlist cannot hold `develop`). Notes get `[import] needs_review: …`. On **update**, an existing allowlisted channel is left alone when the remote branch is not allowlisted.
- Status (import sets the column directly; it does not walk the provision state machine):
  - Coolify looks **running** (`running` / `running:healthy`) → **`active`**
  - Clearly broken (`unhealthy`, `exited`, `error`, `failed`, `crashed`, `dead`) → **`error`**
  - Unsure (`starting`, `restarting`, empty) → **`draft`**
- Local `provisioning` / `deploying` is **not** overwritten (do not fight Task 4).

## What import does not do

- No `APP_KEY` / agent secret generation (later Coolify env patch + redeploy).
- No Coolify create/PATCH/deploy, no channel switch, no webhook controller.
- No shared MySQL/Redis, Mailcow, ZIP theme upload, or SSH.
- No token logging. Audit `before`/`after` is slug/name/domain/channel/status/uuids only.

## After apply

1. Open **Sites** and scan `needs_review` / `dockerfile` notes.
2. Point or confirm DNS. Agent `/health` skips until a secret exists (`needs_secret`).
3. Inject `CONTROL_PLANE_AGENT_SECRET` on the CMS Coolify app, then store the same value on the Plane row — [agent-secret-inject.md](agent-secret-inject.md). Do not invent secrets in import. Dalga 5 hardens automation.
