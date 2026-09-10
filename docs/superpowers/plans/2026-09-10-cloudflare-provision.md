# Cloudflare Zone + DNS + Provision Implementation Plan

> **For agentic workers:** Implement this plan task-by-task in the current session. Do **not** commit unless the operator explicitly asks (user git rule wins over frequent-commit habit). Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a singleton Plane Cloudflare connection, probe token permissions, and run Cloudflare zone+DNS upsert before the existing Coolify provision path.

**Architecture:** Ops browser → Plane → Cloudflare API v4 (`Authorization: Bearer`) for zone create + DNS upsert, then the existing Coolify create/domain/deploy/poll path. Cloudflare is a fourth outbound (Coolify | GitHub | Site agent | Cloudflare). Token is encrypted, never logged, never in audit `before`/`after`, never re-rendered after save. If Cloudflare fails, do not create a Coolify app. If Coolify fails later, leave the zone/DNS (idempotent retry).

**Tech Stack:** Laravel 12, Blade + `ops.css` + vanilla JS, PHPUnit + `Http::fake`, encrypted Eloquent casts.

## Global Constraints

- Repo: `deamon-plane` only. Do not touch CMS (`deamon`) or copy CMS skills/AGENTS hubs.
- No Cloudflare App, Global API Key, WAF/SSL/cache, registrar NS API, DNS deletes, or customer DNS editor.
- Singleton `cloudflare_settings` (not N connections). `proxied` always `false` in v1.
- DNS template is exact (A `@`/`www`/`*` + optional Hostinger mail). Upsert by name+type (+ content for MX/TXT). No deletes.
- Probe never POSTs `/zones` with a `name`. Never log the raw token.
- User-facing copy goes through `lang/{en,tr}/*.php` (Plane UI skill). Probe flash copy matches the approved spec.
- Tests use `Http::fake` only. No live Cloudflare in CI.
- Do not commit or push unless the operator asks.

## File structure

**Create**

- `database/migrations/2026_09_10_040000_create_cloudflare_settings_table.php`
- `database/migrations/2026_09_10_040100_add_cloudflare_columns_to_sites_table.php`
- `app/Models/CloudflareSetting.php`
- `database/factories/CloudflareSettingFactory.php`
- `app/Services/Cloudflare/CloudflareApiException.php`
- `app/Services/Cloudflare/CloudflareClient.php`
- `app/Services/Cloudflare/CloudflareProbeResult.php`
- `app/Services/Cloudflare/CloudflarePermissionProbe.php`
- `app/Services/Cloudflare/CloudflareDnsTemplate.php`
- `app/Services/Cloudflare/CloudflareZoneService.php`
- `app/Http/Controllers/Ops/CloudflareSettingsController.php`
- `routes/ops/cloudflare.php`
- `resources/views/ops/cloudflare/show.blade.php`
- `lang/en/cloudflare.php`
- `lang/tr/cloudflare.php`
- `tests/Feature/Ops/CloudflareSettingsTest.php`
- `tests/Unit/Cloudflare/CloudflarePermissionProbeTest.php`
- `tests/Unit/Cloudflare/CloudflareDnsTemplateTest.php`
- `docs/modules/cloudflare-client.md`
- `docs/decisions/0010-cloudflare-dns-outbound.md`

**Modify**

- `config/ops.php` — `cloudflare` block
- `app/Models/Site.php` — fillable + casts for zone/NS/dns_applied_at
- `app/Services/Sites/SiteProvisioner.php` — Cloudflare preflight + `provisionOnCloudflare()` before Coolify
- `app/Jobs/ProvisionSiteJob.php` — call Cloudflare then Coolify
- `routes/web.php` — require cloudflare routes
- `resources/views/layouts/ops.blade.php` — Cloudflare nav + `session('warning')`
- `lang/en/ops.php` / `lang/tr/ops.php` — `nav.cloudflare`
- `lang/en/sites.php` / `lang/tr/sites.php` — nameserver / DNS applied copy
- `resources/views/ops/sites/show.blade.php` — Cloudflare NS card
- `tests/Feature/Sites/ProvisionSiteTest.php` — Cloudflare setting + fakes + new cases
- `docs/README.md`, `docs/architecture.md`, `docs/security.md`, `docs/modules/ops-sites.md`
- `docs/runbooks/provision-site.md`, `docs/runbooks/token-rotation.md`, `docs/runbooks/README.md`
- `docs/plans/progress-ledger.md` — Cloudflare slice note
- `docs/superpowers/specs/2026-09-10-cloudflare-provision-design.md` — status → approved

---

### Task 1: Schema + CloudflareSetting model

**Files:**
- Create: `database/migrations/2026_09_10_040000_create_cloudflare_settings_table.php`
- Create: `database/migrations/2026_09_10_040100_add_cloudflare_columns_to_sites_table.php`
- Create: `app/Models/CloudflareSetting.php`
- Create: `database/factories/CloudflareSettingFactory.php`
- Modify: `app/Models/Site.php`
- Modify: `config/ops.php`

**Interfaces:**
- Produces: `CloudflareSetting::current(): self`, `hasCredentials(): bool`, encrypted `api_token`, hidden from `toArray()`
- Produces: `Site` columns `cloudflare_zone_id` (nullable string), `cloudflare_nameservers` (json array), `dns_applied_at` (datetime)

- [ ] **Step 1: Write the failing model test** (included in Task 3 feature test; factory used immediately)

- [ ] **Step 2: Create `cloudflare_settings`**

```php
Schema::create('cloudflare_settings', function (Blueprint $table) {
    $table->id();
    $table->string('account_id', 32)->nullable();
    $table->text('api_token')->nullable();
    $table->string('origin_ipv4', 45)->default('72.62.117.147');
    $table->boolean('proxied')->default(false);
    $table->boolean('mail_template_enabled')->default(true);
    $table->timestamp('last_probe_at')->nullable();
    $table->json('last_probe_payload')->nullable();
    $table->timestamps();
});
```

- [ ] **Step 3: Add site columns**

```php
$table->string('cloudflare_zone_id')->nullable()->after('coolify_app_uuid');
$table->json('cloudflare_nameservers')->nullable();
$table->timestamp('dns_applied_at')->nullable();
```

- [ ] **Step 4: Model — GitHub pattern**

`api_token` → `encrypted` + `$hidden`. `proxied` / `mail_template_enabled` boolean. `last_probe_payload` array. `current()` returns first row or `new static`. `hasCredentials()` is filled account_id + token. `resolvedOriginIpv4()` falls back to `config('ops.cloudflare.default_origin_ipv4')` (`72.62.117.147`).

- [ ] **Step 5: Config**

```php
'cloudflare' => [
    'api_base' => env('CLOUDFLARE_API_BASE', 'https://api.cloudflare.com/client/v4'),
    'timeout' => (int) env('CLOUDFLARE_HTTP_TIMEOUT', 20),
    'default_origin_ipv4' => env('CLOUDFLARE_ORIGIN_IPV4', '72.62.117.147'),
],
```

---

### Task 2: Cloudflare HTTP client + DNS template + permission probe

**Files:**
- Create: `app/Services/Cloudflare/CloudflareApiException.php`
- Create: `app/Services/Cloudflare/CloudflareClient.php`
- Create: `app/Services/Cloudflare/CloudflareProbeResult.php`
- Create: `app/Services/Cloudflare/CloudflarePermissionProbe.php`
- Create: `app/Services/Cloudflare/CloudflareDnsTemplate.php`
- Create: `app/Services/Cloudflare/CloudflareZoneService.php`
- Test: `tests/Unit/Cloudflare/CloudflarePermissionProbeTest.php`
- Test: `tests/Unit/Cloudflare/CloudflareDnsTemplateTest.php`

**Interfaces:**
- Consumes: `CloudflareSetting` credentials
- Produces:
  - `CloudflareClient` — `verifyToken()`, `listZones(accountId, ?name, perPage)`, `createZone(accountId, name)`, `probeZoneCreate(accountId)` (no `name`), `listDnsRecords(zoneId, query)`, `createDnsRecord(zoneId, payload)`, `updateDnsRecord(zoneId, recordId, payload)`
  - `CloudflarePermissionProbe::probe(CloudflareSetting $settings): CloudflareProbeResult`
  - `CloudflareDnsTemplate::records(string $originIpv4, bool $mailEnabled): list<array>`
  - `CloudflareZoneService::ensureZoneAndDns(Site $site, CloudflareSetting $settings): void`

#### Probe (fail-closed, never create a real zone)

| Step | Call | Pass | Missing |
|------|------|------|---------|
| 1 | `GET /user/tokens/verify` | `result.status=active` | Token geçersiz / iptal |
| 2 | `GET /zones?account.id={id}&per_page=1` | HTTP 200 | Zone Read yok veya Account ID yanlış |
| 3 | `POST /zones` body `{ "account": { "id": "<id>" } }` **without `name`** | HTTP **400** (name required) = Zone Edit | **403** = Zone Edit yok |
| 4 | If a zone exists: `GET /zones/{id}/dns_records?per_page=1` | 200 | DNS Read yok |
| 5 | If a zone exists: `POST /zones/{id}/dns_records` empty/`{}` | **400** = DNS Edit | **403** = DNS Edit yok |

Account ID: step 3 HTTP 400 whose error mentions account not found → `accountIdInvalid`, not a permission miss.

Zero zones: skip 4–5; `dnsUnverified=true`; warning copy, do not fail the probe on DNS Edit.

`CloudflareProbeResult` fields: `tokenValid`, `zoneRead`, `zoneEdit`, `dnsRead`, `dnsEdit`, `dnsUnverified`, `accountIdInvalid`, `missingLabels` (dashboard strings), `payload` (no token).

Dashboard labels for missing list:

- `DNS & Zones → Zone → Read`
- `DNS & Zones → Zone → Edit` — include note that DNS Write template does not grant this
- `DNS & Zones → DNS → Read`
- `DNS & Zones → DNS → Edit`

#### DNS template (exact)

| Type | Name | Content | When |
|------|------|---------|------|
| A | `@`, `www`, `*` | `origin_ipv4` | always; TTL 1 (auto); `proxied: false` |
| CNAME | `hostingermail-a/b/c._domainkey` | `dkim.mail.hostinger.com` | mail on |
| MX | `@` | `mx1.hostinger.com` pri 5 | mail on |
| MX | `@` | `mx2.hostinger.com` pri 10 | mail on |
| TXT | `@` | `v=spf1 include:_spf.mail.hostinger.com ~all` | mail on |
| TXT | `_dmarc` | `v=DMARC1; p=none` | mail on |

Upsert: list by type + FQDN name; match existing by content (and priority for MX); PUT if found, POST if not. Never DELETE.

Zone lookup: `GET /zones?name={apex}&account.id={id}`. 0 → `POST /zones` with name+account. 1 → reuse id + nameservers. 2+ → fail “duplicate hostname”. Persist `cloudflare_zone_id`, `cloudflare_nameservers`, `dns_applied_at`.

- [ ] **Step 1: Write probe unit tests** (`Http::fake`)

```php
public function test_probe_success_when_verify_ok_zones_ok_and_post_zones_is_400(): void
public function test_probe_lists_zone_edit_when_post_zones_is_403(): void
public function test_probe_lists_dns_edit_when_dns_post_is_403(): void
public function test_probe_post_zones_never_sends_name(): void
public function test_probe_warns_when_account_has_zero_zones(): void
public function test_probe_never_logs_token(): void
```

- [ ] **Step 2: Run tests — expect FAIL** (classes missing)

Run: `php artisan test --filter=CloudflarePermissionProbeTest`

- [ ] **Step 3: Implement client + probe + template + zone service**

HTTP: `https://api.cloudflare.com/client/v4`, `Authorization: Bearer {token}`, accept JSON. Unwrap `{success, result, errors}`. Redact token in `CloudflareApiException` (same idea as `CoolifyApiException::redact`).

- [ ] **Step 4: Run unit tests — expect PASS**

---

### Task 3: Cloudflare settings UI (singleton)

**Files:**
- Create: `app/Http/Controllers/Ops/CloudflareSettingsController.php`
- Create: `routes/ops/cloudflare.php`
- Create: `resources/views/ops/cloudflare/show.blade.php`
- Create: `lang/en/cloudflare.php`, `lang/tr/cloudflare.php`
- Modify: `routes/web.php`, `resources/views/layouts/ops.blade.php`, `lang/en/ops.php`, `lang/tr/ops.php`
- Test: `tests/Feature/Ops/CloudflareSettingsTest.php`

**Interfaces:**
- Consumes: `CloudflareSetting`, `CloudflarePermissionProbe`
- Routes: `GET /cloudflare` `ops.cloudflare.show`; `POST /cloudflare` `ops.cloudflare.update`; `POST /cloudflare/test` `ops.cloudflare.test`
- Auth: `ops.view` (any ops role) can see form with blank token; `ops.write` to save/test

Form fields: Account ID (32-hex), API token (password, blank = keep), origin IPv4 (default `72.62.117.147`), mail template checkbox. `proxied` not offered; save forces `false`.

Always-visible permissions card (Turkish source in `lang/tr/cloudflare.php`, English in `en`):

1. Kaynak: hesap altındaki **tüm zone’lar** (gelecekteki zone’lar dahil). Tek domain seçmeyin.
2. **DNS & Zones → DNS:** Read + Edit
3. **DNS & Zones → Zone:** Read + Edit
4. Hazır şablon **DNS Write** yetmez: Zone satırında **Edit** de işaretlenmeli.

Flash (locale-aware, spec Turkish in `tr`):

- Success: `Cloudflare bağlantısı tamam. Zone oluşturma ve DNS yazma izinleri doğrulandı.`
- Partial: `Cloudflare token geçerli, ancak şu izinler eksik: …` + dashboard labels
- Zero-zone warning: `DNS Read/Edit henüz doğrulanamadı; yine de DNS Read+Edit verin.`
- Account ID: `Account ID hatalı.`
- Token invalid: `Token geçersiz / iptal.`

Last probe summary on the page (timestamp + missing labels). No token in HTML, logs, or `last_probe_payload`.

Layout: add `session('warning')` → `ops-alert ops-alert-warning`. Nav item **Cloudflare** after Coolify.

- [ ] **Step 1: Write feature tests**

```php
public function test_page_never_renders_api_token(): void
public function test_operator_saves_token_encrypted_blank_keeps_existing(): void
public function test_test_connection_success_flash(): void
public function test_test_connection_zone_edit_403_lists_dashboard_label(): void
public function test_test_connection_dns_edit_403_lists_dashboard_label(): void
public function test_test_never_posts_zone_name(): void
public function test_viewer_can_see_form_cannot_save_or_test(): void
```

- [ ] **Step 2: Run — expect FAIL**
- [ ] **Step 3: Implement controller + view + i18n + nav**
- [ ] **Step 4: Run — expect PASS**

---

### Task 4: Provision order (Cloudflare then Coolify)

**Files:**
- Modify: `app/Services/Sites/SiteProvisioner.php`
- Modify: `app/Jobs/ProvisionSiteJob.php`
- Modify: `resources/views/ops/sites/show.blade.php`
- Modify: `lang/en/sites.php`, `lang/tr/sites.php`
- Test: `tests/Feature/Sites/ProvisionSiteTest.php` (extend existing helper + new cases)

**Interfaces:**
- Consumes: `CloudflareSetting::hasCredentials()`, `CloudflareZoneService::ensureZoneAndDns()`
- Produces: `SiteProvisioner::assertCloudflareReady()`, `provisionOnCloudflare(Site): void`, audit `site.cloudflare_ready`

Order in `start()`:

1. Existing `canStart` + `assertCoolifyReady` + Coolify preflight
2. **New** `assertCloudflareReady()` — token + account_id required. Missing → `SiteProvisionException` Turkish: `Cloudflare bağlı değil. Cloudflare menüsünden Account ID ve API token ekleyin.` (same class as Coolify-not-configured; do not start)
3. Secrets, status `provisioning`, audit `site.provision_started`

Order in `ProvisionSiteJob`:

1. `provisionOnCloudflare($site)` — find/create zone, upsert DNS, persist columns, audit `site.cloudflare_ready` (slug, domain, zone_id, nameservers — no token)
2. Existing `provisionOnCoolify($site, …)`
3. Cloudflare exception before Coolify uuid → `markFailed`, no Coolify POST `/applications`
4. Coolify failure after DNS → existing retry; do not delete zone

Existing `fakeCoolifyHappyPath()` must also answer Cloudflare v4 (verify + list zones by name + create or reuse + list/create/update DNS). Add `CloudflareSetting::factory()` in `ProvisionSiteTest::setUp`.

New tests:

```php
public function test_provision_without_cloudflare_settings_does_not_call_coolify(): void
public function test_cloudflare_zone_403_does_not_create_coolify_app(): void
public function test_existing_zone_dns_upsert_then_coolify_create(): void
public function test_cloudflare_token_absent_from_audit_and_logs(): void
```

Site show: after zone exists, card with NS list + “registrar’da bu NS’leri ayarlayın” + `dns_applied_at`.

403 on zone create → failure message names **Zone → Edit**. 403 on DNS → names **DNS → Edit**.

- [ ] **Step 1: Write/extend failing tests**
- [ ] **Step 2: Run ProvisionSiteTest — existing happy path fails until fake includes Cloudflare**
- [ ] **Step 3: Implement provisioner + job + site UI**
- [ ] **Step 4: Run full provision + Cloudflare suites — expect PASS**

---

### Task 5: Docs (same implementation)

**Files:**
- Create: `docs/modules/cloudflare-client.md`
- Create: `docs/decisions/0010-cloudflare-dns-outbound.md`
- Modify: `docs/README.md`, `docs/architecture.md`, `docs/security.md`, `docs/modules/ops-sites.md`
- Modify: `docs/runbooks/provision-site.md`, `docs/runbooks/token-rotation.md`, `docs/runbooks/README.md`
- Modify: `docs/decisions/README.md`, `docs/plans/progress-ledger.md`
- Modify: spec status to approved

Coverage:

- Provision includes Cloudflare first; NS at registrar is operator work
- Token rotation: Cloudflare API token (Account → API Tokens; DNS Read+Edit + Zone Read+Edit; all zones including future)
- Security: fourth outbound; encrypted token; probe never logs token
- ADR-10: Cloudflare API v4 Bearer token; no App; DNS-only; registrar out of scope

- [ ] **Step 1: Update all listed docs in the same change set**
- [ ] **Step 2: Self-review spec coverage** (probe, template, failure table, out-of-scope)

---

## Self-review

1. **Spec coverage:** Settings singleton, probe 1–5, DNS template, provision order, site NS columns, tests, docs — each has a task. Registrar/SSL/Apps/deletes remain out.
2. **Placeholders:** none.
3. **Types:** `CloudflareProbeResult`, `CloudflareZoneService::ensureZoneAndDns`, `SiteProvisioner::provisionOnCloudflare` used consistently.
4. **Existing tests:** `ProvisionSiteTest` must create Cloudflare settings and fake Cloudflare HTTP or every provision test breaks (`Http::preventStrayRequests`).

## Execution

Operator already approved the spec and asked to continue. Implement inline in this session. Do not commit.
