# Domain registry + Coolify domain binding reconcile

**Date:** 2026-09-11  
**Status:** draft — amended 2026-09-11 (domain silent gap emphasized)  
**Repo:** Deamon Plane only  
**Order:** Wave 3 of 3 (after bulk App health fixes and deploy-status sync)

## Context

Sites already have `site_domains` (primary, www, aliases, temporary wildcard hosts), create/edit alias validation (same registrable apex), and site-detail **Add domain**. Coolify binds hosts on compose service `app` via `docker_compose_domains` (`CoolifyDomainParser`, `CoolifyClient::setDomains`).

Gaps operators reported:

1. **Primary pain:** Plane lists domains (primary / www / aliases) but Coolify `docker_compose_domains` does **not** include them — and **nothing in Plane App health / list / detail currently says so**. Operators only notice when the public site is wrong.
2. No fleet-level **domain management** screen to add domains and attach them to sites.
3. Coolify Sync does not ingest Coolify-bound hosts into `site_domains` when Plane is missing them.
4. No automatic rebind when Coolify drops a Plane-owned host.

## Goals

- Ops UI to list Plane domains, create a domain (and optionally assign a site), and bind/unbind to a site within existing apex rules.
- On Coolify site sync / inventory fill: parse Coolify `docker_compose_domains` (+ fqdn fallback), upsert missing non-generated hosts onto the matched site’s `site_domains`, and link orphan hosts when the site is unambiguous.
- **Always surface** Plane-has / Coolify-missing hosts: App health issue `domain_unbound` (per site), Domains index **Unbound** filter, site detail domain rows with a clear unbound chip — never silent.
- Fix / Sync path: `setDomains` with Plane’s desired binding string (primary + www + aliases + temp rules already used by provision/landing).
- i18n, policies, `Http::fake` tests.

## Non-goals

- Multi-tenant customer self-service domain marketplace.
- Changing Cloudflare zone purchase flows (reuse existing Free zone helpers when operator explicitly adds CF).
- Auto-buying domains or DNS at registrars.
- Binding a host that already belongs to another Plane site (keep uniqueness).
- Wave 1/2 App health bulk/deploy cache work (already specified).

## Approaches considered

| Approach | Pros | Cons |
|----------|------|------|
| A. Only improve site-detail domains card | Small | No fleet domain list; hard to find unbound hosts |
| B. **Fleet Domains page + sync reconcile + App health issue** (recommended) | Matches request; discoverable; automated rebind | New routes/nav |
| C. Domains only as Coolify inventory rows without Plane ownership | Matches Coolify | Breaks Plane source-of-truth for provision/CF |

**Recommendation:** B. Plane remains source of truth for desired hosts; Coolify sync **imports** unknown hosts onto the site and **repairs** missing Coolify bindings toward Plane desired set.

## Design

### Data model

Keep `site_domains`. Optional additive columns (only if needed by UI):

| Column | Notes |
|--------|--------|
| `coolify_bound_at` | Nullable timestamp; last time Sync verified host present on Coolify app |
| `source` | Optional enum/string: `plane` \| `coolify_import` \| `temporary` — helps UI; `is_temporary` already covers temp |

Prefer minimal migration: if `coolify_bound_at` alone is enough, skip `source`.

Uniqueness: `domain` globally unique across `site_domains` (enforce if not already).

### Desired binding

Reuse existing helpers that build Coolify bind lists from a site (provision / landing / compose migrate). Single service method:

`SiteDomainBinding::desiredHosts(Site $site): list<string>`  
`SiteDomainBinding::coolifyPayload(Site $site): array` → `CoolifyDomainParser::forPatch(...)`

Include primary, www siblings, aliases, and active temporary host when landing still pending. Exclude Coolify generate-wildcard noise from **import** (existing `isGeneratedWildcardHost`).

### Coolify Sync reconcile (`CoolifySiteSync` / fill)

After `getApp`:

1. Parse hosts from `docker_compose_domains` / fqdn (customer hosts only).
2. For each host:
   - If `site_domains` already points at this site → touch `coolify_bound_at`.
   - If unknown globally → `updateOrCreate` on this site (non-primary unless site has no primary); do not steal another site’s domain.
   - If owned by another site → skip + count `domain_conflicts` in sync result (flash/job result).
3. Compare Plane desired hosts vs Coolify set:
   - Missing on Coolify → record issue / optional auto-repair flag.
4. Default Sync behavior: **detect + import**; **auto-rebind** when `ops.coolify.auto_rebind_domains` is true (default **true** for internal Plane) OR when operator runs fix `bind_domains`. Spec locks default **true** for missing Plane→Coolify hosts on Sync for sites that already have `coolify_app_uuid` and at least one non-temporary domain — avoids silent proxy loss. Document clearly; Settings toggle optional follow-up if too aggressive.

### App health

New issue (mandatory — this is the silent-failure case operators already hit):

| Code | Fix | When |
|------|-----|------|
| `domain_unbound` | `bind_domains` | Any **non-temporary** Plane desired host is absent from Coolify `docker_compose_domains` / fqdn for the linked app. Message must name at least one missing host (e.g. `example.com`). |
| `domain_missing_plane` | *(none — Sync imports)* | Coolify has a customer host Plane lacks; after Sync import this should clear. Do not hide `domain_unbound` behind this. |

Rules:

- Live inspect **and** Sync must be able to set/clear `domain_unbound`.
- List App column / detail App card must show it like env issues (Wave 1 bulk can fix category `bind_domains`).
- Temporary landing hosts: if Plane still expects the temp host, missing on Coolify counts; if landing already promoted to customer hosts, temp is not required on Coolify.

`SiteAppHealthFixer` adds `bind_domains` → `CoolifyApplicationService::setDomains` with **full** desired payload (not a single host only — Coolify replace semantics); then re-inspect.

Bulk Wave 1 fix order appends `bind_domains` after `sync_env` and before `inject_secret` once this wave lands (update Wave 1 runner when implementing Wave 3).

### UI — Domains

**Nav:** Ops sidebar item **Domains** (`/domains`), writer+viewer read; writer mutate.

**Index:**

- Table: domain, site (name link or “Unassigned”), primary/www/temp badges, Coolify bound (yes/no/unknown), actions.
- Filters: q, assigned/unassigned, unbound-only.
- Header: **Add domain** → modal/form: hostname, optional site select (sites without conflicting apex rules).
- Row actions: Assign site, Unassign (only aliases / non-primary), Open site, Copy host.

**Assign rules:**

- Same registrable apex as site primary when site already has a primary.
- Creating a domain with a site and empty primary → can become primary (explicit confirm).
- Unassign primary forbidden; change primary via site edit.

**Site detail:** keep existing domains card; show Coolify bound chip per row; button **Bind on Coolify** calling `bind_domains`.

### API

| Method | Route | Name |
|--------|-------|------|
| GET | `/domains` | `ops.domains` |
| POST | `/domains` | `ops.domains.store` |
| PATCH | `/domains/{siteDomain}` | `ops.domains.update` (assign/unassign site) |
| POST | `/domains/{siteDomain}/bind` | `ops.domains.bind` (site must be set) |
| existing | `POST /sites/{site}/domains` | unchanged add-alias |
| existing | app-health fix | `bind_domains` |

### Tests

- Sync imports Coolify host onto site `site_domains`.
- Sync does not steal domain owned by another site.
- Live inspect reports `domain_unbound` when Coolify list omits primary; `bind_domains` PATCHes `docker_compose_domains`.
- Domains index authorization; assign apex validation.
- Generated wildcard hosts not imported as customer domains.

### Docs

- `docs/modules/ops-sites.md` — domains fleet + reconcile.
- `docs/modules/coolify-client.md` — sync import + rebind.
- Progress ledger Wave 3.
- Sidebar / README pointer if docs index lists modules.

## Acceptance

- A site with Plane domains missing from Coolify shows `domain_unbound` in App health (list + detail) — never silent.
- Operator can add a domain in `/domains` and attach it to a site; unbound filter finds Plane→Coolify gaps.
- Coolify Sync adds missing Coolify hosts to Plane for that site and rebinds Plane hosts missing in Coolify (per default auto-rebind).
- `bind_domains` / Sync rebind restores Coolify `docker_compose_domains` to the full Plane desired set.
- No cross-site domain theft; secrets unchanged.

## Live debug

When Coolify API responses disagree with the UI, implementers may inspect the Coolify host over SSH (`ssh coolify` / project convention) to read compose proxy domains — do not put SSH into product code paths.

## Dependency note

Implement after Waves 1–2 so `bind_domains` plugs into bulk fix menus and App health display merge without rework.
