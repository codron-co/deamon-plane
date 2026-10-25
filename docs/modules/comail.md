# CoMail (shared mailcow)

CoMail is the customer-facing name ("E-posta" in Turkish UI) of the shared mailcow server on main.codron (`https://mail.codron.co`). Customers never see "mailcow". Plane holds the mailcow API key, enforces package limits, writes DNS and proxies every mailbox operation for the CMS `comail` module. The CMS never sees the key.

Contract (shared with the CMS repo): `C:\www\tek-seferlik-isler\comail\CONTRACT.md` (v1, 2026-10-26). Hostinger mail ([mail-servers.md](mail-servers.md)) keeps working unchanged next to it.

## Data

| Table / column | Meaning |
|---|---|
| `mail_servers.provider = mailcow`, `base_url`, `mail_hostname`, `api_token` (encrypted, hidden) | The CoMail server. `MailServer::comail()` = first enabled mailcow server with key + base URL. `mail_hostname` empty = host of `base_url`. |
| `mail_packages` | Limits: `mailbox_limit`, `storage_mb` (total per site, all domains), `mailbox_max_quota_mb`, `alias_limit`, `domain_limit` (null = any own domain), price (`price_monthly`, `currency`), `is_default` (exactly one), `is_active`, `sort`. The migration seeds **Ücretsiz** (`free`, default: 1 box, 1024 MB, 10 aliases) and two inactive examples **Başlangıç** (`starter`) and **İş** (`business`). `MailPackage::default()` recreates a default if every row was removed. |
| `sites.mail_package_id` | Null = default package. |
| `sites.comail_configured_at` / `comail_configure_failed_at` / `comail_configure_error` / `comail_configure_message` | Last CMS configure push outcome (same pattern as `mail_configure_*`). |
| `site_comail_domains` | `domain` (unique across sites), `is_primary`, `status` (pending / active / failed), `mailcow_synced_at`, `dkim_selector`, `dkim_txt`, `mx_active_at`, `dns_checked_at`, `dns_check` (json), `last_error`. |

## Services (`app/Services/Comail`)

- `MailcowClient`: thin HTTP client (`{base_url}/api/v1`, header `X-API-Key`, 15 s timeout, `ops.comail.timeout_seconds`). mailcow answers writes with HTTP 200 and `[{type, msg}]`; any `danger`/`error` element throws `MailcowException` with mailcow's translation key (`providerKey`), mapped to Turkish by `ComailErrors`. 401/403/5xx and non-JSON throw too. Logs carry method, path (addresses redacted) and status only.
- `ComailLimits`: package lookup, mailcow domain ceilings (`mailboxes`, `quota` = storage, `maxquota`, `defquota = min(1024, max)`, `aliases`), usage, and the checks (`limit_mailboxes`, `limit_storage`, `limit_aliases`, `limit_domains`). New mailbox quota default = `min(remaining storage, mailbox_max_quota_mb)`.
- `ComailDomainProvisioner`: `ensure()` creates or re-syncs the mailcow domain with the package limits, then DKIM. **`add/domain` already creates the DKIM key** (selector `dkim`, 2048) and answers two success elements; a later `add/dkim` answers danger (`dkim_domain_or_sel_invalid`), so the provisioner reads `GET /get/dkim/{domain}` first and only adds a key when it is empty. `eligibleHosts()` = the site's non-temporary, non-www hosts that are not preview hosts under the Plane wildcard domain (`codron.co`).
- `ComailDirectory`: one site's view (domains, mailboxes, aliases). Every address/alias/sync job is checked against the site's own CoMail domains; a mailbox's internal alias row (address = goto) is not listed.
- `ComailDns`: plan / apply / activate / check (below). `DnsResolver` interface (`NativeDnsResolver` wraps `dns_get_record`; tests bind a fake).
- `ComailSiteConfigurer` + `ConfigureSiteComailJob` + `ReconcileComailPushJob` (hourly, :35).
- `ComailAutoreply`: builds the sieve prefilter (`script_desc = comail-autoreply`): `require ["vacation","date","relational"]; vacation :days 1 :subject "…" "…";`, optionally inside `if allof(currentdate :value "ge" "date" "Y-m-d", currentdate :value "le" …)`. User text only lands in quoted strings with `\` and `"` escaped and control characters removed; the settings read back come from a base64 JSON comment on line 1.

## Enrollment

`EnsureComailDomainJob(site, domain, primary)` (idempotent, unique per site+domain): mailcow domain + limits + DKIM → DNS on Plane-managed zones (MX only on a fresh domain) → audit `comail.domain_enrolled` → CMS configure push.

Dispatched automatically (only when `MailServer::comail()` exists, primary domain only, never a temporary/preview host, never a domain another site owns):

- `SiteController::store()` after the transaction.
- `SiteProvisioner::markSucceeded()` before the success audit (the Cloudflare zone exists by then).

Existing sites: `php artisan ops:comail-enroll --site=<slug> [--site=…] | --all [--dry-run]`. Without `--dry-run` it queues the jobs 15 s apart; `--dry-run` only lists. It never runs on its own and never replaces an existing MX.

## DNS

For domain D (all DNS only, `proxied: false`), mail host H = `mail_hostname` (`mail.codron.co`):

| Key | Record |
|---|---|
| `mx` | MX D → H, priority 10 |
| `spf` | TXT D `v=spf1 mx a:H ~all` |
| `dkim` | TXT `dkim._domainkey.D` = `dkim_txt` |
| `dmarc` | TXT `_dmarc.D` `v=DMARC1; p=none` |
| `cname_mail`, `cname_autodiscover`, `cname_autoconfig` | CNAME `mail.D` / `autodiscover.D` / `autoconfig.D` → H |
| `srv_autodiscover` | SRV `_autodiscover._tcp.D` 0 1 443 H |

- Zone lookup: the site's Cloudflare account first, then every enabled account; longest covering zone (`CloudflareHostname::zoneCandidates`). Result cached 10 min for `dns_managed`.
- **Apply** (managed zones only, else 422 `validation_failed`): DKIM upserted; CNAMEs/DMARC/SRV only when the name has no record; MX + SPF only when D has **no MX at all**. On such a fresh domain an existing SPF keeps its senders (`mx a:H` is inserted after `v=spf1`).
- **Activate** (explicit, managed zones only): deletes every non-CoMail MX, adds CoMail's, replaces the SPF (exactly one remains), points `autodiscover`/`autoconfig` to H (CNAME updated, other record types at that name replaced), then the rest as in apply; sets `mx_active_at`.
- **Check**: resolves every record → `ok` / `missing` / `different` (+ `current`). MX → H sets `mx_active_at`; MX elsewhere clears it; no answer leaves it.
- Non-Cloudflare zones are never written; the plan is returned for manual entry.
- `CloudflareZoneService::upsertTemplate()` leaves the template's mail rows (Hostinger MX/SPF/DKIM CNAMEs/DMARC) out while a CoMail server exists, so new zones get CoMail MX instead. Existing zones are not touched.

## Plane → CMS configure

`POST {agent_base_url}/internal/control/v1/comail/configure` (`ControlPlaneAgentContract::comailConfigurePath()`), same HMAC and compact JSON as `mail/configure`, no key/token fields:

```json
{"enabled":true,"provider":"comail","site_id":"<ULID>","plane_base_url":"<APP_URL>","webmail_url":"https://mail.codron.co"}
{"enabled":false,"provider":null,"site_id":"<ULID>","plane_base_url":"<APP_URL>"}
```

Enabled while a CoMail server exists and the site has an active CoMail domain. Queued (`ConfigureSiteComailJob`, one retry on 429/connection like the Hostinger job); outcome on the `comail_configure_*` columns; the hourly reconcile re-pushes sites whose push failed or never happened. Saving/deleting the CoMail server re-queues every enrolled site.

## CMS → Plane API (`/internal/site/v1/comail/*`)

Auth exactly like `/internal/site/v1/mail/*`: `X-Deamon-Site` + HMAC with that site's agent secret (`EnsureSiteMailSignature`), 60/min **per site** (`throttle:site-comail`, keyed by `X-Deamon-Site`, because every CMS calls from the same Coolify host IP). JSON in/out.

Errors: `{"ok":false,"error":"<code>","message":"<Turkish>"}` — 422 `validation_failed`, `limit_mailboxes`, `limit_storage`, `limit_aliases`, `limit_domains`, `domain_not_owned`, `domain_has_mailboxes`, `comail_not_configured`, `provider_error`, `domain_not_in_comail` (H-Posta); 404 `not_found`; 502 `provider_unreachable`; 401 `unauthorized` (signature).

Success bodies carry `"ok": true`. A single object (mailbox, alias, migration) is returned **flat and also under its name** (`{ok, address, …, mailbox: {address, …}}`), so either `json.address` or `json.mailbox.address` works. The domain row is flat only (its `domain` field is the name).

| Method | Path | Body | Response |
|---|---|---|---|
| GET | `/overview` | – | `package{name,slug,mailbox_limit,storage_mb,mailbox_max_quota_mb,alias_limit,domain_limit}`, `usage{mailboxes,aliases,domains,storage_allocated_mb,storage_used_bytes}`, `webmail_url` (`{base}`: login lands in the inbox), `panel_url` (`{base}/user`: spam filter, app passwords, 2FA), `client{imap,pop3,smtp,smtp_alt,managesieve}`, `domains[{domain,is_primary,status,mx_active,dns_managed,webmail_url}]` (`https://mail.D` once MX is on CoMail, unless the last DNS check saw the `mail.D` CNAME missing or different; else null), `available_domains[]` |
| POST | `/domains` | `{domain}` | domain row, 201 (`domain_not_owned`, `limit_domains`; an already active own domain answers 200) |
| DELETE | `/domains/{domain}` | – | `{ok:true}` (primary → `validation_failed`; mailboxes → `domain_has_mailboxes`) |
| GET | `/domains/{domain}/dns` | – | `{domain,dns_managed,mx_active,records[{key,type,name,value,priority?,status,current?}]}` (fresh check) |
| POST | `/domains/{domain}/dns` | – | apply, same shape |
| POST | `/domains/{domain}/activate` | – | activate, same shape |
| GET | `/mailboxes` | – | `{mailboxes[{address,local_part,domain,name,quota_mb,used_bytes,percent,messages,active,created_at,last_login_at}]}` (ISO 8601 dates) |
| POST | `/mailboxes` | `{local_part,domain,name?,password,quota_mb?}` | mailbox, 201. Password 8–128 chars with a letter and a digit. |
| PATCH | `/mailboxes/{address}` | `{name?,password?,quota_mb?,active?}` | mailbox |
| DELETE | `/mailboxes/{address}` | – | `{ok:true}` |
| GET / PUT | `/mailboxes/{address}/autoreply` | `{enabled,subject,body,starts_on?,ends_on?}` (Y-m-d; subject/body required when enabled) | `{enabled,subject,body,starts_on,ends_on}` |
| GET | `/aliases` | – | `{aliases[{id,address,goto[],active,kind:alias|forward|catchall}]}` |
| POST | `/aliases` | `{address,goto[]}` (`local@D` or `@D`) | alias, 201 |
| PATCH / DELETE | `/aliases/{id}` | `{goto?,active?}` | alias / `{ok:true}` |
| GET | `/migrations` | – | `{migrations[{id,mailbox,host,port,user,security,active,running,last_run_at,success,exit_status}]}` |
| POST | `/migrations` | `{mailbox,host,port,user,password,security:SSL|TLS|PLAIN}` | migration, 201 |
| DELETE | `/migrations/{id}` | – | `{ok:true}` |
| GET | `/migrations/hposta` | – | `{available, mailboxes[{id,email,domain,in_comail,migration_id}]}` |
| POST | `/migrations/hposta` | `{mailbox_ids[]}` | `{results[{email,ok,error?,message?,password?}]}` |

### H-Posta one-click migration

For sites still bound to Hostinger mail (`mailServer` is a ready Hostinger server and `mailDomains()` is not empty). `GET` lists the H-Posta boxes of every bound domain (`HostingerMailClient::listAllMailboxes`); `in_comail` = a CoMail mailbox with the same address exists, `migration_id` = its sync job. `available:false` with an empty list without a binding.

`POST`: every id must be one of the site's H-Posta boxes (else 404 `not_found`) and every box's domain must already be a CoMail domain (else 422 `domain_not_in_comail`, nothing changed). Then, one box at a time: package limits first (so a full package never leaves a changed H-Posta password), a generated 16-character password (upper, lower, digit, one of `!#%*-_+=`) is set on H-Posta (`changePassword`) and on CoMail (create with the default quota, or edit the existing box), and a sync job `imap.hostinger.com:993 SSL` with that address and password is added unless one exists for the address. The password is returned **once** in that item's result (also when a later step failed, since the H-Posta password already changed) and is never stored, logged or audited (`comail.hposta_migrated` records email, ok, error).

## Ops UI

- `/mail-servers`: provider select offers CoMail (mailcow): base URL (`https://`), mail hostname, API key (password input, never rendered). Creating a CoMail server needs `ops.danger`. **Bağlantıyı dene** = `GET /get/status/version`. The show page lists every CoMail domain. The provider of a saved server is fixed. Site mail-server selects and `mail_server_id` validation only offer Hostinger servers.
- `/mail-packages` (Posta nav group): list, create, edit, delete (`ops.write`; delete `ops.danger`, never the default or a package in use). Editing re-applies limits to every site using it (`ApplyComailPackageJob`).
- Site detail → Infrastructure → **CoMail (E-posta)** card (`#site-comail`): package select, domain table (status, DNS `ok/total`, MX chip), **DNS’i denetle**, **DNS uygula**, **MX’i CoMail’e geçir** (PlaneConfirm), add domain from the site's eligible hosts, **CMS’e tekrar gönder**. Gate: site `update`.
- Audit: `mail_package.*`, `mail_server.*`, `comail.package_changed`, `comail.domain_added|removed|enrolled`, `comail.dns_applied`, `comail.mx_activated`, `comail.configure_requeued`, `comail.mailbox_*`, `comail.alias_*`, `comail.autoreply_updated`, `comail.migration_*`, `comail.hposta_migrated`, `comail.access_denied`. Never a password or key.
- MCP (`RouteToolCatalog::comail()`): `set_site_mail_package`, `add_comail_domain`, `apply_comail_dns`, `check_comail_dns`, `activate_comail_mx`, `resend_comail_configure`, `create_mail_package`, `update_mail_package`, `delete_mail_package`.

## Deploy

1. Deploy Plane; `php artisan migrate --force` (creates the tables and seeds the three packages).
2. `/mail-servers` → New: provider **CoMail (mailcow)**, base URL `https://mail.codron.co`, mail hostname `mail.codron.co`, the mailcow read-write API key (allowed only from 72.62.117.147) → **Bağlantıyı dene**.
3. Cloudflare: keep `mail_template_enabled` on (the CoMail check already skips the Hostinger rows while CoMail exists) or turn it off on accounts that should never get template mail rows.
4. New sites enroll by themselves. Existing ones: `php artisan ops:comail-enroll --all --dry-run`, then per site or `--all` (try deamon-test first).
5. The CMS needs the `comail` module (CMS PR) before the configure push succeeds; until then the hourly reconcile keeps retrying.

## Tests

`tests/Feature/Comail/*` (API, DNS, enrollment, ops, H-Posta) and `tests/Unit/Comail/*`. `Http::fake` only: `Support/FakeMailcow` (stateful mailcow), `Support/FakeCloudflare`, `Support/FakeDnsResolver`.
