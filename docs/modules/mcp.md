# MCP endpoint (AI agents)

`POST /mcp` speaks the [Model Context Protocol](https://modelcontextprotocol.io) (Streamable HTTP, JSON responses, no SSE) so an AI agent (Claude Code, Cursor, any MCP client) can run Plane: open a site, provision it, set up Cloudflare, install and update themes, deploy, switch channels, manage CMS admins.

## How a tool runs

- **Read tools** (`list_sites`, `get_site`, `get_setup_options`, `list_themes`, `list_jobs`, `get_deployment`, `list_site_activity`, `list_dns_records`, `list_site_admins`, `whoami`) read models directly through `App\Mcp\McpPresenter`. No secrets leave Plane (agent secret, APP_KEY, DB passwords, Coolify / Cloudflare tokens).
- **Write tools** are one panel route each (`App\Mcp\Tools\RouteToolCatalog`). `App\Mcp\OpsRouteInvoker` runs the route **in-process** as the token's user: same FormRequest validation, policies, services, audit rows and background jobs as an operator's click. Nothing is reimplemented. The request asks for JSON, so controllers that offer it queue an `ops_background_jobs` row (the agent follows it with `list_jobs job_id=…`); redirects are read back like `ConvertOpsAjaxRedirect` (`status` / `warning` / `error` flash, plus one-time values such as a generated admin password under `once`).
- Arguments name things the way people do: a site by slug, domain or id; a theme install by its catalog `theme_id`; a Cloudflare zone by id or apex name (`App\Mcp\McpModels`).
- `update_site` and `update_theme_settings` fill the fields the agent leaves out with the current values. The edit form posts every field and a missing one is cleared (aliases released from DNS, mail server detached).
- Custom tools: `bulk_sites` (one action on many sites through the panel bulk routes, always with explicit `site_ids`) and `update_theme_fleet` (every site behind the catalog, dry run by default).
- Prompt `launch_site` is the new-site checklist; server instructions repeat the house rules (test site first, batch deploys on the shared host, ask before offline/delete).

## Tokens and permissions

```bash
php artisan ops:mcp-token create emin@codron.co --name="Claude Code" --ability=write   # prints the token once
php artisan ops:mcp-token list
php artisan ops:mcp-token revoke <id>
```

| Ability | Tools |
|---------|-------|
| `read` | read tools only |
| `write` | + every change an operator can make (create, provision, deploy, themes, DNS add/edit, admins) |
| `danger` | + deactivate, archive, purge, DNS delete, admin delete, theme rollback/remove, `bulk_sites all:true`, `update_theme_fleet` on every site |

The ability only narrows: the user's role (`super_admin` / `operator` / `viewer`) and policies still decide every call, exactly as in the panel. A viewer with a write token is still refused.

Security:

- `mcp_tokens` stores the SHA-256 of the token; the plain value is shown once. `last_used_at` / `last_used_ip` are kept for `list`.
- `/mcp` has no session and no CSRF; it is outside the `web` group, so `OPS_IP_ALLOWLIST` does not apply (agents run from cloud IPs). `OPS_MCP_IP_ALLOWLIST` (comma-separated) pins tokens to fixed addresses; `OPS_MCP_ENABLED=false` turns the endpoint off.
- Rate limit `mcp`: 240 requests/minute per token.
- Every write lands in the audit log under the token's user, with the client IP.

## Connecting

Claude Code:

```bash
claude mcp add --transport http deamon-plane https://plane.codron.co/mcp --header "Authorization: Bearer plane_mcp_…"
```

Cursor / other clients (`mcp.json`):

```json
{ "mcpServers": { "deamon-plane": { "url": "https://plane.codron.co/mcp", "headers": { "Authorization": "Bearer plane_mcp_…" } } } }
```

## Adding a tool

A new panel action usually needs only a `RouteTool` entry in `RouteToolCatalog`: route name, ability, JSON Schema properties, and `params` mapping route parameters to resolvers (`site`, `installation`, `site_domain`, `deployment`, `theme`, `cloudflare_account`, `zone`, `record`, `admin`, `rollout`). `McpEndpointTest::test_every_route_tool_points_at_a_registered_route` fails if a route is renamed. Write a `ReadTool` subclass only for data the panel has no JSON route for.
