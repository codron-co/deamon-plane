# Cloudflare zone + DNS + Coolify provision

**Date:** 2026-09-10  
**Status:** draft — waiting for operator review  
**Repo:** Deamon Plane only. CMS (`deamon`) is untouched.

## Goal

Operator creates a site (draft: slug, name, primary domain, channel). **Provision** then:

1. Register the domain on Cloudflare (create the zone if it is missing).
2. Upsert the panel DNS template (exact records below).
3. Create the Coolify compose app and deploy (existing Plane path).

If the Cloudflare step fails, **do not** create a Coolify app. If Coolify fails later, leave the Cloudflare zone and DNS in place (idempotent retry).

## Scope

**In**

- Plane **Cloudflare** nav (singleton connection, Coolify-like, not N connections).
- Encrypted API token + plaintext Account ID.
- DNS template: origin IPv4, DNS-only (not proxied), Hostinger mail on/off.
- Provision job: Cloudflare first, then existing Coolify create/domain/deploy/poll.
- **Test connection** that probes the token and warns which dashboard permission groups are missing.
- Site row stores Cloudflare zone id + nameservers; UI tells the operator to set NS at the registrar.

**Out**

- Cloudflare Apps / Workers / WAF / SSL / Always HTTPS / cache / Page Shield / Zero Trust.
- Registrar API (NS change at the registrar). Cloudflare cannot change registrar NS.
- Customer-facing DNS editor in Plane.
- CMS Cloudflare code, multi-tenant, ZIP themes.
- Deleting DNS records that the operator added by hand.

## Architecture

```
Ops browser → Plane
  → Cloudflare API v4  (Bearer token; zone create + DNS upsert)
  → Coolify API         (existing)
  → GitHub / site agent  (unchanged)
```

Trust boundary today (`docs/security.md`): Ops → Plane → (Coolify | GitHub | Site agent).  
Implementation must add **Cloudflare** as a fourth outbound. Token never logs, never appears in audit `before`/`after`, never re-rendered after save (same as Coolify / GitHub).

HTTP: `Authorization: Bearer {token}` to `https://api.cloudflare.com/client/v4`. No Global API Key. No Cloudflare App.

## Cloudflare settings (singleton)

Table `cloudflare_settings` (one row, like `github_settings`):

| Column | Notes |
|--------|--------|
| `account_id` | 32-char hex, not secret |
| `api_token` | encrypted + `$hidden` |
| `origin_ipv4` | default `72.62.117.147` |
| `proxied` | always `false` in v1 (column may exist but UI does not offer orange-cloud) |
| `mail_template_enabled` | boolean, default true (Hostinger DKIM/MX/SPF/DMARC) |
| `last_probe_at` / `last_probe_payload` | optional; no token in payload |

Nav: **Cloudflare** (next to Coolify). `ops.write` to save/test; `ops.view` can see the form with token blank.

Blank token on save = keep existing (GitHub pattern).

## Token permission matrix (dashboard labels)

Cloudflare does **not** return granted permission groups to the token (`GET /user/tokens/verify` is only valid/active). Plane infers capabilities by probing. The settings page always shows this checklist (before Test), using the **exact Cloudflare dashboard labels** from the token UI.

### Resource (required)

- **All zones in {account email}'s Account** (operator screenshot: `All zones in Coodron@gmail.com's Account`).
- Must include **future zones**. A token scoped to one existing zone **cannot** create a new customer domain (`POST /zones` — the zone does not exist yet).

### Required groups (DNS & Zones)

| Dashboard group | Checkboxes | Why |
|-----------------|------------|-----|
| **DNS** | **Read** + **Edit** | Upsert A / CNAME / MX / TXT |
| **Zone** | **Read** + **Edit** | List zones + **create** a new zone |

Official Cloudflare names: `Zone DNS Edit`, `Zone Zone Edit`. Dashboard: **DNS & Zones → DNS** and **DNS & Zones → Zone**.

**The pre-made “DNS Write” template is not enough.** It typically grants DNS Edit + Zone **Read**, not Zone **Edit**. Without Zone Edit, Plane cannot add a new domain.

### Not required (do not ask; warn if the operator granted them only as “I thought these were needed”)

Do **not** require, probe, or fail Test for:

- Developer Platform (Cloud Connector, Workers Routes)
- AI & Machine Learning
- DNS & Zones extras: Zone Custom Asset, Zone DNS Settings, Zone Settings, Zone Versioning
- App Security (Bot Management, Firewall, WAF, Page Rules, Zaraz, …)
- Rules & Configuration (Transform, Snippets, Custom Errors, …)
- Cloudflare One / Zero Trust (Zone Access)
- Analytics & Logs
- Network Services (Load Balancers, Waiting Rooms, Healthcheck)
- Email & Messaging (Email Routing — Plane writes MX/TXT via **DNS Edit**, not this group)
- Cache & Performance (Cache Purge, Cache Settings, SSL & Certificates)
- Account & Billing → Apps
- Other → Page Shield

### Operator copy (Turkish, always visible)

**Gerekli izinler**

1. Kaynak: hesap altındaki **tüm zone’lar** (gelecekteki zone’lar dahil). Tek domain seçmeyin.
2. **DNS & Zones → DNS:** Read + Edit
3. **DNS & Zones → Zone:** Read + Edit

Hazır şablon **DNS Write** yetmez: Zone satırında **Edit** de işaretlenmeli.

## Test connection — permission probe

`POST /cloudflare/test` (`ops.write`). Never log the raw token.

### Probes (fail-closed)

| Step | Call | Pass | Missing permission |
|------|------|------|--------------------|
| 1. Token | `GET /user/tokens/verify` | `status=active` | Token geçersiz / iptal |
| 2. Zone Read | `GET /zones?account.id={account_id}&per_page=1` | HTTP 200 | **Zone Read** yok veya Account ID yanlış (403) |
| 3. Zone Edit | `POST /zones` body `{ "account": { "id": "<id>" } }` **without `name`** | HTTP **400** (validation: name required) = Edit present | **403** = **Zone Edit** yok |
| 4. DNS Read | If at least one zone: `GET /zones/{id}/dns_records?per_page=1` | 200 | **DNS Read** yok |
| 5. DNS Edit | If a zone exists: `POST /zones/{id}/dns_records` with invalid payload (e.g. empty object / missing `type`) | **400** = Edit present | **403** = **DNS Edit** yok |

**Never** `POST /zones` with a real-looking `name`. The missing-name 400 vs 403 split is the only create-permission check.

If the account has **zero zones**, skip steps 4–5 and flash a warning: DNS Read/Edit henüz doğrulanamadı; yine de DNS Read+Edit verin. Zone Edit probe (step 3) still runs.

Account ID: if step 3 returns 400 “account not found” (or equivalent), flash Account ID hatalı — not a permission miss.

### Flash UX (Turkish)

Success (all required probes pass):

> Cloudflare bağlantısı tamam. Zone oluşturma ve DNS yazma izinleri doğrulandı.

Partial (token valid, some required missing):

> Cloudflare token geçerli, ancak şu izinler eksik: …

List using dashboard labels, e.g.:

- **DNS & Zones → Zone → Edit** — yeni domain kaydı için zorunlu. “DNS Write” şablonu bunu vermez.
- **DNS & Zones → DNS → Edit** — A/MX/TXT kayıtları için zorunlu.

Do not create/update Coolify or sites from Test.

## DNS template (exact; nothing else)

Upsert by `(name, type)`. No deletes.

| Type | Name | Content | Notes |
|------|------|--------|--------|
| A | `@`, `www`, `*` | `origin_ipv4` | TTL auto, `proxied: false` |
| CNAME | `hostingermail-a._domainkey` | `dkim.mail.hostinger.com` | if mail template on |
| CNAME | `hostingermail-b._domainkey` | `dkim.mail.hostinger.com` | if mail template on |
| CNAME | `hostingermail-c._domainkey` | `dkim.mail.hostinger.com` | if mail template on |
| MX | `@` | `mx1.hostinger.com` priority 5 | if mail on |
| MX | `@` | `mx2.hostinger.com` priority 10 | if mail on |
| TXT | `@` | `v=spf1 include:_spf.mail.hostinger.com ~all` | if mail on (match BIND dumps) |
| TXT | `_dmarc` | `v=DMARC1; p=none` | if mail on |

Zone already exists: reuse `id`, still upsert records. Duplicate hostname in Cloudflare: fail with a clear error (do not pick the wrong zone).

## Provision order

Existing: draft → Provision → secrets → Coolify create → domain → deploy → poll.

New:

1. Preflight: Coolify ready **and** Cloudflare token + Account ID present. If Cloudflare missing → do not start (same class of flash as “Coolify is not configured”).
2. Status `provisioning`, audit `site.provision_started`.
3. **Cloudflare:** find or create zone for `primary_domain`; persist `cloudflare_zone_id`, `cloudflare_nameservers` (json) on `sites`.
4. Upsert DNS template.
5. Audit `site.cloudflare_ready` (no secrets).
6. Existing Coolify path (`provisionOnCoolify`).
7. Failure **before** Coolify app uuid: status `error`, no Coolify app. Zone/DNS may exist — retry is idempotent.
8. Failure **after** Coolify create: existing retry (reuse uuid). Do not delete the Cloudflare zone.

Site UI after Cloudflare step: nameserver list + “registrar’da bu NS’leri ayarlayın.” Provision can succeed while the domain is still at the old NS (propagation is operator work).

## Data model (sites)

New columns (not secrets):

- `cloudflare_zone_id` (nullable string)
- `cloudflare_nameservers` (json array, nullable)
- `dns_applied_at` (nullable timestamp)

`primary_domain` already exists. No Cloudflare token on the site row.

## UI

- **Cloudflare** page: Account ID, token (blank after save), origin IPv4, mail toggle, required-permissions card, Test, last probe summary.
- **Sites → New / show:** unchanged create fields. Show Cloudflare NS + “DNS applied” after provision.
- Confirm modal: still not required for Provision (today’s rule).

Reuse `ops.css` / Coolify–GitHub form patterns. No CMS UI skills; this is the Plane ops panel.

## Errors

| Case | Behaviour |
|------|----------|
| No Cloudflare settings | Cannot start provision; flash like Coolify-not-configured |
| Token 403 on zone create | `error`; message names **Zone → Edit** |
| Token 403 on DNS upsert | `error`; message names **DNS → Edit**; zone may already exist |
| Zone name taken / conflict | `error`; do not create Coolify app |
| Coolify fails after DNS | `error`; Coolify retry rules; leave Cloudflare |

Secrets never in logs, flash, or audit.

## Tests (`Http::fake`)

- Probe: verify 200 + zone GET 200 + POST /zones 400 → success flash.
- Probe: POST /zones 403 → warning lists Zone Edit.
- Probe: DNS POST 403 → warning lists DNS Edit.
- Probe: never asserts a `name` field on POST /zones.
- Provision: Cloudflare 403 → Coolify create **not** called.
- Provision: zone exists + DNS upsert → Coolify create called.
- Token not in log / audit / JSON.

No live Cloudflare in CI.

## Docs to update in the same implementation PR (not this spec-only drop)

- `docs/modules/ops-sites.md` — provision includes Cloudflare first
- `docs/runbooks/provision-site.md` — NS at registrar; Cloudflare preconditions
- `docs/runbooks/token-rotation.md` — Cloudflare token
- `docs/security.md` — fourth outbound; encrypted Cloudflare token
- `docs/runbooks/README.md` — index row if a Cloudflare runbook is added
- Optional ADR under `docs/decisions/` if the team wants a numbered ADR

CMS docs/rules/skills: **no**.

## Open questions (none blocking if this spec is approved)

1. SPF string: use the BIND dump `include:_spf.mail.hostinger.com` (this spec). Do not invent a different SPF.
2. `proxied` stays off in v1 even if a future column exists.

---

## Spec self-review

- **Placeholder:** none (`TODO`, `TBD`, `FIXME` avoided). Origin IP default is the known panel address.
- **Contradiction:** Coolify remains the app/TLS path; Cloudflare is DNS/zone only. No SSL API. Registrar NS is operator, not API.
- **Scope:** CMS, Cloudflare Apps, WAF/SSL/cache, registrar API, DNS deletes, multi-connection Cloudflare — out.
- **Ambiguity:** Probe uses 400-vs-403 on incomplete POST; never creates a dummy zone. Zero-zone accounts cannot prove DNS Edit until a zone exists — UI warns instead of failing closed on that one check.
- **Permission list:** mapped to the operator’s dashboard paste; extras are explicitly not required.
