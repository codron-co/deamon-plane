# Cloudflare HTTP adapter

Singleton Plane connection for zone create + DNS template upsert. Spec: [2026-09-10-cloudflare-provision-design.md](../superpowers/specs/2026-09-10-cloudflare-provision-design.md). Runbook: [provision-site.md](../runbooks/provision-site.md).

Ops browser → Plane → Cloudflare API v4 (`Authorization: Bearer`). No Global API Key. No Cloudflare App. Token is encrypted + `$hidden`; never logged, audited, or re-rendered after save.

## Classes

| Class | Role |
|-------|------|
| `App\Services\Cloudflare\CloudflareClient` | Bearer HTTP to `config('ops.cloudflare.api_base')` |
| `App\Services\Cloudflare\CloudflarePermissionProbe` | Test connection: verify token, Zone Read, Zone Edit (POST `/zones` **without `name`**), DNS Read/Edit |
| `App\Services\Cloudflare\CloudflareZoneService` | Attach hostname to an **existing** zone only; ensure `*` A; persist NS on the site |
| `App\Services\Cloudflare\CloudflareHostname` | Normalize host; walk parent labels (`a.b.c.example.com` → parent zones) |
| `App\Services\Cloudflare\PreviewHostname` | `{adjective}-{noun}.{wildcard}` when the operator domain cannot bind |
| `App\Services\Cloudflare\CloudflareDnsTemplate` | A `@`/`www`/`*` DNS-only + optional Hostinger DKIM/MX/SPF/DMARC |
| `App\Models\CloudflareSetting` | Account ID, encrypted token, origin IPv4, preview wildcard zone, mail toggle, last probe |

Do not call Cloudflare from tests with a live token. Use `Http::fake`. Never send a real-looking zone `name` on the permission probe.

## Settings (ops menu)

Left nav **Cloudflare**. `ops.write` to save/test; `ops.view` can read the form with token blank. Blank token on save keeps the existing value.

Required token resource: **all zones including future**. Required groups: **DNS & Zones → DNS** Read+Edit and **DNS & Zones → Zone** Read+Edit. The ready-made “DNS Write” template is not enough (no Zone Edit).

## Provision order

`SiteProvisioner` talks to Cloudflare **before** Coolify. If Cloudflare fails, no Coolify app is created. If Coolify fails later, leave the zone and DNS. Registrar NS change is operator work — Plane cannot change NS at the registrar.

## Hostname attach (nested, unlimited labels)

`*.codron.co` already points at this Plane/Coolify origin. Provision **never** creates a Cloudflare zone and never asks the operator to change registrar NS for that wildcard.

1. Normalize `primary_domain` (strip scheme, port, path, trailing dot, leading `www.`).
2. Walk parent labels longest-first (`test.deamon.codron.co` → `test.deamon.codron.co`, `deamon.codron.co`, `codron.co`) and `GET /zones?name=` with an exact match. First hit wins.
3. **Always** upsert a DNS-only A `*` on that zone (create if missing; skip if it already points at origin). Cloudflare `*` is one label: `foo.codron.co` is covered, `test.deamon.codron.co` is not.
4. Also upsert an explicit DNS-only A for the relative hostname (`test.deamon` on zone `codron.co`, or `a.b.c` on zone `deamon.codron.co`) so nested names resolve even when `*` cannot.
5. If the hostname **is** the zone apex, also upsert the Deamon DNS template (`@` / `www` / mail). Do **not** apply that template when attaching onto a shared parent/wildcard zone.
6. Duplicate-record responses (record already exists) are treated as success: re-read and skip or update.

## Preview wildcard

Settings field **Preview wildcard zone** (default `codron.co`, env `CLOUDFLARE_WILDCARD_DOMAIN`). Infra already delegated NS; Plane does not create this zone. When no covering zone exists for the operator domain, provision allocates `{adjective}-{noun}.{wildcard}` (covered by `*` plus an explicit A), promotes it to `sites.primary_domain`, and keeps the requested host on `site_domains` as non-primary. Coolify `setDomains` uses the resolving host.

If the wildcard zone is missing from the Cloudflare account, provision fails and Coolify is not called.
