# Ops Sites (desired state)

Draft CRUD for Coolify-hosted Deamon sites. No Coolify HTTP calls and no provision jobs on these screens.

## Routes

| Method | Path | Name | Who |
|--------|------|------|-----|
| GET | `/sites` | `ops.sites` | All ops roles |
| GET | `/sites/create` | `ops.sites.create` | operator, super_admin |
| POST | `/sites` | `ops.sites.store` | operator, super_admin |
| GET | `/sites/{site}` | `ops.sites.edit` | All ops roles (viewer read-only) |
| PUT | `/sites/{site}` | `ops.sites.update` | operator, super_admin |
| DELETE | `/sites/{site}` | `ops.sites.destroy` | operator, super_admin |

Routes live in `routes/ops/sites.php` (required from `routes/web.php`).

## Fields

Create/edit desired state: `slug`, `name`, `domain` (`sites.primary_domain` + primary `site_domains` row), `channel` (`main` \| `beta` \| `alpha`), optional `coolify_server_uuid`, `notes`.

- Status is always **draft** on create. The form cannot change status.
- `APP_KEY` / `agent_secret` are not generated here (Task 4).
- Channel and slug are locked once status is not `draft` (channel switch is Task 5).
- Destroy is a **soft delete** with the ops confirm modal (`data-confirm` / `PlaneConfirm.ask`). `window.confirm` is not used.

## Policy

`App\Policies\SitePolicy`: viewer can `viewAny` / `view`. `create` / `update` / `delete` require `User::canWriteOps()` (operator or super_admin).

## List

GET filters with `withQueryString`: `q` (name / slug / domain), `channel`, `status`. Search input debounces a GET submit (300 ms). No Coolify client.
