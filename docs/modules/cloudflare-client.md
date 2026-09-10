# Cloudflare HTTP adapter

Singleton Plane connection for zone create + DNS template upsert. Spec: [2026-09-10-cloudflare-provision-design.md](../superpowers/specs/2026-09-10-cloudflare-provision-design.md). Runbook: [provision-site.md](../runbooks/provision-site.md).

Ops browser → Plane → Cloudflare API v4 (`Authorization: Bearer`). No Global API Key. No Cloudflare App. Token is encrypted + `$hidden`; never logged, audited, or re-rendered after save.

## Classes

| Class | Role |
|-------|------|
| `App\Services\Cloudflare\CloudflareClient` | Bearer HTTP to `config('ops.cloudflare.api_base')` |
| `App\Services\Cloudflare\CloudflarePermissionProbe` | Test connection: verify token, Zone Read, Zone Edit (POST `/zones` **without `name`**), DNS Read/Edit |
| `App\Services\Cloudflare\CloudflareZoneService` | Attach hostname to a covering zone, or create a Free full zone on the registrable apex; persist NS on the site |
| `App\Services\Cloudflare\CloudflareHostname` | Normalize host; walk parent labels (`a.b.c.example.com` → parent zones); apex for new customer zones |
| `App\Services\Cloudflare\CloudflareDnsTemplate` | A `@`/`www`/`*` DNS-only + optional Hostinger DKIM/MX/SPF/DMARC |
| `App\Models\CloudflareSetting` | Account ID, encrypted token, origin IPv4, preview wildcard zone, mail toggle, last probe |

Do not call Cloudflare from tests with a live token. Use `Http::fake`. Never send a real-looking zone `name` on the permission probe.

## Settings (ops menu)

Left nav **Cloudflare**. `ops.write` to save/test; `ops.view` can read the form with token blank. Blank token on save keeps the existing value.

Required token resource: **all zones including future**. Required groups: **DNS & Zones → DNS** Read+Edit and **DNS & Zones → Zone** Read+Edit. The ready-made “DNS Write” template is not enough (no Zone Edit).

## Provision order

`SiteProvisioner` talks to Cloudflare **before** Coolify, using the site’s `cloudflare_setting_id` when set, otherwise the default enabled account. If Cloudflare fails, no Coolify app is created. If Coolify fails later, leave the zone and DNS. Registrar NS change is operator work — Plane cannot change NS at the registrar.

Operators can create the zone first from the site detail card (`POST /sites/{site}/cloudflare/zone`) and copy nameservers before Provision. That call does not start Coolify.

## Hostname attach (nested, unlimited labels)

`*.codron.co` already points at this Plane/Coolify origin. Nested hosts under that covering zone only write DNS and never ask the operator to change registrar NS for the wildcard.

1. Normalize `primary_domain` (strip scheme, port, path, trailing dot, leading `www.`).
2. Walk parent labels longest-first (`test.deamon.codron.co` → `test.deamon.codron.co`, `deamon.codron.co`, `codron.co`) and `GET /zones?name=` with an exact match. First hit wins.
3. **Always** upsert a DNS-only A `*` on that zone (create if missing; skip if it already points at origin). Cloudflare `*` is one label: `foo.codron.co` is covered, `test.deamon.codron.co` is not.
4. Also upsert an explicit DNS-only A for the relative hostname (`test.deamon` on zone `codron.co`, or `a.b.c` on zone `deamon.codron.co`) so nested names resolve even when `*` cannot.
5. If the hostname **is** the zone apex, also upsert the Deamon DNS template (`@` / `www` / mail). Do **not** apply that template when attaching onto a shared parent/wildcard zone.
6. Duplicate-record responses (record already exists) are treated as success: re-read and skip or update.

## Customer zone (unbound apex)

When no covering zone exists, Plane creates a **Free** full zone (`POST /zones` `{ account.id, name, type: full }` — no paid plan) on the registrable 2-label apex (`shop.customer.example` → `customer.example`). Deamon DNS defaults are applied. The site keeps the requested hostname. Cloudflare returns `name_servers`; store them on `sites.cloudflare_nameservers` and show them as copyable values. Zone status is usually `pending` until the registrar NS change propagates.

When the new zone is `pending` / `initializing`, provision still creates the Coolify app. Plane allocates a `PreviewHostname` on the account `wildcard_domain` (usually `codron.co`), writes that A record, stores `sites.temporary_domain` + a `site_domains` row (`is_temporary`), and binds Coolify to the temp host only. Customer `primary_domain` does not change. After the operator sets registrar NS and clicks **I updated DNS**, Plane GETs the zone: still pending → keep temp; `active` → bind operator hosts + www and drop the temp host (optional A cleanup on the wildcard). Zone create AJAX does **not** allocate the temp host — that happens at provision.
