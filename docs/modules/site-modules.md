# Site modules (CMS module catalog)

Site detail → **Modüller** tab lists the site's installable CMS modules and turns them on or off. Plane stores nothing about modules: the list is read live from the CMS over the signed agent every time.

## CMS endpoints

Same prefix, signing and headers as the other agent calls ([agent-client.md](agent-client.md)); path `ControlPlaneAgentContract::MODULES_PATH` (`ops.agent.modules_path` overrides).

| Method | Path | Body | Answer |
|--------|------|------|--------|
| GET | `/internal/control/v1/modules` | — | `{ok, modules: [{slug, name, tagline, category, installed, enabled, locked, requires[]}]}` |
| POST | `/internal/control/v1/modules` | `{slug, enabled}` | `{ok, slug, enabled, changed[], message}`; errors 422 `{ok:false, error: validation_failed \| module_change_failed, message}` |

Turning a module on installs it with its dependencies when it is not installed (`changed` lists every module that changed). Turning it off only disables it; its data stays. A CMS without the endpoint answers 404 → "Bu site sürümü modül kataloğunu desteklemiyor" (`ModuleAgentResult::$unsupported`).

## Plane

| Piece | Role |
|-------|------|
| `SiteAgentClient::listModules()` / `setModule()` | Signed GET / POST. GET uses `ops.agent.timeout_seconds`; POST uses `ops.agent.module_toggle_timeout_seconds` (default 45, env `CONTROL_PLANE_AGENT_MODULE_TIMEOUT`) because an install runs migrations. 429 is retried like the other agent calls. |
| `ModuleAgentResult` | Allowlists module rows; maps 404 → unsupported, 401/403 → bad signature, 422 → the CMS message. |
| `Ops\SiteModuleController` | `panel` (HTML fragment, viewer+), `index` (JSON, viewer+), `update` (JSON, `update` policy = operator+). `update` reads the catalog first and refuses unknown slugs and turning a locked (core) module off. |
| `_modules.blade.php` / `_modules_body.blade.php` | Tab + lazy panel (`ops-lazy-panels.js` loads it when the tab is first shown). Modules are grouped by module-store category (`SiteModuleController::CATEGORY_ORDER`, unknown → Diğer) and sorted by name. |
| `public/js/sites-modules.js` | Switch click → `fetch` POST JSON, row updated in place (modules in `changed` turn on too), inline error under the row. Turning off, or turning on a module that is not installed, asks first through `PlaneConfirm` (in-page modal). No page reload. |

Audit (`site.module.enabled`, `site.module.disabled`, `site.module.toggle_failed`): `before` = `{module, installed, enabled}`, `after` = `{module, enabled, changed}` or `{module, enabled, error, message}`. `list_site_activity` shows them.

## MCP

- `list_site_modules` (read): `site`.
- `set_site_module` (write): `site`, `module` (slug), `enabled` (bool).

Both run the panel routes above through `OpsRouteInvoker`. Copy: `lang/{tr,en}/site_modules.php`. Tests: `tests/Feature/Sites/SiteModulesTest.php`.
