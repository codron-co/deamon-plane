# Cloudflare HTTP adapter

Singleton Plane connection for zone create + DNS template upsert. Spec: [2026-09-10-cloudflare-provision-design.md](../superpowers/specs/2026-09-10-cloudflare-provision-design.md). Runbook: [provision-site.md](../runbooks/provision-site.md).

Ops browser → Plane → Cloudflare API v4 (`Authorization: Bearer`). No Global API Key. No Cloudflare App. Token is encrypted + `$hidden`; never logged, audited, or re-rendered after save.

## Classes

| Class | Role |
|-------|------|
| `App\Services\Cloudflare\CloudflareClient` | Bearer HTTP to `config('ops.cloudflare.api_base')` |
| `App\Services\Cloudflare\CloudflarePermissionProbe` | Test connection: verify token, Zone Read, Zone Edit (POST `/zones` **without `name`**), DNS Read/Edit |
| `App\Services\Cloudflare\CloudflareZoneService` | Find or create zone for `primary_domain`; upsert DNS template; persist NS on the site |
| `App\Services\Cloudflare\CloudflareDnsTemplate` | A `@`/`www`/`*` DNS-only + optional Hostinger DKIM/MX/SPF/DMARC |
| `App\Models\CloudflareSetting` | One row: Account ID, encrypted token, origin IPv4, mail toggle, last probe |

Do not call Cloudflare from tests with a live token. Use `Http::fake`. Never send a real-looking zone `name` on the permission probe.

## Settings (ops menu)

Left nav **Cloudflare**. `ops.write` to save/test; `ops.view` can read the form with token blank. Blank token on save keeps the existing value.

Required token resource: **all zones including future**. Required groups: **DNS & Zones → DNS** Read+Edit and **DNS & Zones → Zone** Read+Edit. The ready-made “DNS Write” template is not enough (no Zone Edit).

## Provision order

`SiteProvisioner` talks to Cloudflare **before** Coolify. If Cloudflare fails, no Coolify app is created. If Coolify fails later, leave the zone and DNS. Registrar NS change is operator work — Plane cannot change NS at the registrar.
