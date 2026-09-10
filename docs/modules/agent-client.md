# Site agent client

Task 9. Plane polls each Deamon CMS instance over a signed HTTP agent. CMS routes live in `codron-co/deamon` (Task 8 health, Task 11 themes **v1.2.5**). Do not copy CMS middleware here. CMS SoT: `docs/modules/control-plane-agent.md` in the Deamon repo. Theme POSTs: [theme-agent-client.md](theme-agent-client.md).

## Endpoint (CMS)

`GET {agent_base_url}/internal/control/v1/health`

Expected JSON (no secrets):

| Field | Notes |
|-------|--------|
| `deamon_version` | Semver used by the channel version gate |
| `channel_hint` | Optional |
| `active_theme_id` | Optional |
| `php` | Optional |
| `queue_ok` | `false` counts as fleet **unhealthy** |

`sites.agent_base_url` is preferred. If empty, Plane uses `https://{primary_domain}`.

## HMAC (locked to CMS Task 8)

Canonical string: `{timestamp}.{nonce}.{rawBody}` (GET body is empty `""`, so the string ends with `.`).

| Item | Locked value |
|------|----------------|
| MAC | HMAC-SHA256 **lowercase hex**, no `sha256=` prefix |
| Timestamp header | `X-Deamon-Timestamp` (unix seconds, digits only) |
| Nonce header | `X-Deamon-Nonce` (8–128 chars; Plane sends a UUID) |
| Signature header | `X-Deamon-Signature` |
| Skew | ±60s on the CMS (`ops.agent.skew_seconds`) |
| Secret | Per-site `sites.agent_secret_encrypted`; CMS env `CONTROL_PLANE_AGENT_SECRET` |

Header names live in `App\Services\Agent\ControlPlaneAgentContract`. Signing is `App\Support\ControlPlaneAgentSignature`. **Coolify webhook HMAC is a different secret and different headers** (`X-Coolify-Signature`).

## Plane behaviour

| Piece | Role |
|-------|------|
| `SiteAgentClient` | Signs and GET-polls. Never logs the secret. |
| `SiteHealthChecker` | Persists `last_health_at` + allowlisted summary (`deamon_version`, `active_theme_id`, `queue_ok`, …). |
| `CheckSiteHealthJob` | One site. Unique per site for 4 minutes. |
| `DispatchSiteHealthChecksJob` | Scheduler fan-out. |
| Schedule | Every 5–15 minutes (`CONTROL_PLANE_AGENT_POLL_MINUTES`, default 10). |

On-demand: operator / super_admin **Check health** on site edit (`POST /sites/{site}/health`). Viewer is forbidden.

### Sites without `agent_secret`

Import (Task 7) does **not** generate secrets. Poll is skipped. Payload status is `needs_secret` / `unknown`. Plane does not invent a secret. See [agent-secret-inject.md](../runbooks/agent-secret-inject.md).

Health checks never change `sites.status`. A timeout does not flip the site to `error`.

## Fleet unhealthy KPI

Still **5** cards. **Unhealthy** is the union of:

- `sites.status = error` (Task 6, unchanged)
- Agent fail: timeout, bad signature (HTTP 401/403), `queue_ok=false`, HTTP/parse error
- Stale: last successful-or-failed poll older than `CONTROL_PLANE_AGENT_STALE_MINUTES` (default 30) **and** the site has a secret

`needs_secret` and `unknown` do **not** increment unhealthy (import leftovers).

## Version gate (Task 5)

`ChannelSwitcher` reads `last_health_payload.deamon_version` (or `version`). When present and `DEAMON_MAIN_MINIMUM_VERSION` is set, `alpha`/`beta` → `main` is blocked below the floor. **Missing health still does not block.** Force remains Super Admin only.

## Out of scope

CMS route registration (Task 11 lives in `codron-co/deamon`). Theme agent client is [theme-agent-client.md](theme-agent-client.md). ZIP, Mailcow API, SSH, live Coolify env mutate of customer apps are out of scope.

## Mail configure + reverse proxy

Hostinger tokens stay in Plane (`mail_servers.api_token` encrypted). After a site is assigned a Hostinger mail server **and** Plane matches a mail order whose domain equals that site’s primary domain, Plane POSTs `{agent_base_url}/internal/control/v1/mail/configure` with the same HMAC as themes. Body has `enabled`, `provider`, `site_id`, `plane_base_url`, `mail_domain`, `webmail_url` — **never** the API token. `mail_domain` is the matched site order, not a server-wide default.

CMS mailbox UI calls back to Plane `POST/GET/PATCH/DELETE /internal/site/v1/mail/mailboxes…` with `X-Deamon-Site` + HMAC of that site’s agent secret. Plane talks to Hostinger. Details: [mail-servers.md](mail-servers.md).
