# CoMail (shared mailcow)

CoMail is the customer-facing name ("E-posta" in Turkish UI) of the shared mailcow server on main.codron (`https://mail.codron.co`). Customers never see "mailcow". Plane holds the mailcow API key, enforces package limits, writes DNS and proxies every mailbox operation for the CMS `comail` module. The CMS never sees the key.

Contract (shared with the CMS repo): `C:\www\tek-seferlik-isler\comail\CONTRACT.md` (v1, 2026-10-26) and `CONTRACT-v2.md` (billing + webmail SSO, see [v2](#v2-billing-and-webmail-sso)). Hostinger mail ([mail-servers.md](mail-servers.md)) keeps working unchanged next to it.

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
| GET | `/overview` | – | `package{name,slug,mailbox_limit,storage_mb,mailbox_max_quota_mb,alias_limit,domain_limit}`, `usage{mailboxes,aliases,domains,storage_allocated_mb,storage_used_bytes}`, `webmail_url` (`{base}`: Roundcube at the web root), `panel_url` (`{base}/?panel=1`: mail user panel — spam filter, app passwords, 2FA), `client{imap,pop3,smtp,smtp_alt,managesieve}`, `domains[{domain,is_primary,status,mx_active,dns_managed,webmail_url}]` (`https://mail.D` once MX is on CoMail, unless the last DNS check saw the `mail.D` CNAME missing or different; else null), `available_domains[]` |
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

## Mail security (v3)

Contract: `C:\www\tek-seferlik-isler\comail\CONTRACT-v3-security.md`. Already live on the server (not Plane's job): ClamAV, rspamd, the `dmarc@` / `tlsrpt@codron.co` report aliases, `ADDITIONAL_SAN` with `mta-sts.*`.

- **Local mode** = CoMail receives the domain's mail (`mx_active_at` set, or no MX at all). **Relay mode** domains (MX still elsewhere) keep exactly the v1 DNS set; their security checks are `not_applicable` except SPF/DKIM.
- Records (`ComailSecurityRecords`, written by `ComailSecurity::writeRecords()` from `ComailDns::write()` whenever CoMail receives the mail; also listed in the DNS plan of local domains as `mta_sts`, `mta_sts_txt`, `tls_rpt` for manual zones):
  - `_dmarc.D` TXT `v=DMARC1; p=<dmarc_policy>; rua=mailto:dmarc@codron.co; fo=1`. An existing record is merged: policy set, CoMail's `rua` added next to the customer's, `fo=1` only when no `fo`, other tags kept. A **customer** record (no CoMail `rua`) with a stricter policy than requested is never touched and reports `different`; Plane's own record may go either way.
  - `mta-sts.D` A `ops.comail.mail_ipv4` (31.97.79.49, DNS only) and `_mta-sts.D` TXT `v=STSv1; id=<mta_sts_id>` — only after the mailcow policy exists and only when `mta-sts.D` is free or already ours (a customer's own policy host stays).
  - `_smtp._tls.D` TXT `v=TLSRPTv1; rua=mailto:tlsrpt@codron.co` when D has no TLS-RPT record.
  - `D._report._dmarc.codron.co` TXT `v=DMARC1` in Plane's codron.co zone (external report authorization; `report_auth_at`). Not needed for codron.co and its subdomains. Removed when the domain leaves CoMail.
- **Wildcard guard** (same rule as #29): before writing, every hostname-like ancestor below the zone apex (D itself for `mta-sts.D`, `_dmarc.D`, …) gets the parent wildcard's A/AAAA/CNAME pinned (`ComailDns::pinWildcardAddress`). Underscore labels are skipped.
- **mailcow MTA-STS policy**: `POST /add/mta-sts {domain, version: STSv1, mode: testing, mx: <mail host>, max_age: 86400, active: 1}` once per domain (`mta_sts_id` = Plane's YmdHis). mailcow has no read route; an existing policy answers danger with a duplicate-entry SQL text and counts as present (ops' mode is kept). Also created when a DNS check first sees the MX on CoMail (unmanaged zones).
- **Rate limit**: `add/domain` sends `rl_value = mail_packages.rate_limit_per_hour` (default 300), `rl_frame = h`. `edit/domain` never sends rate-limit keys (codron.co keeps its manual 1000/h). No ops form field yet.
- `GET /security` runs a fresh DNS check per active domain (cached 60 s, `ComailDns::check()` clears the cache). `mta_sts` is `ok` only when both records are right **and** `https://mta-sts.D/.well-known/mta-sts.txt` answers `version: STSv1` over valid TLS.

| Method | Path | Body | Response |
|---|---|---|---|
| GET | `/security` | – | `{domains[{domain, mode: local|relay, dns_managed, checks{mx,spf,dkim,dmarc,mta_sts,tls_rpt: {status: ok|missing|different|not_applicable, current?, expected?}}, dmarc_policy, rate_limit{value,frame}}], antivirus: true, spam{default_score: "8,15"}}` |
| POST | `/domains/{domain}/dmarc` | `{policy: none|quarantine|reject}` | the domain's security row. 422 `dns_not_managed` (zone not in Plane's Cloudflare), `validation_failed` (relay mode, bad policy) |
| GET / PUT | `/mailboxes/{address}/spam` | `{level: relaxed|normal|strict}` | `{level (or custom), score{low,high}, allow[{id,value}], block[{id,value}]}` — relaxed 10/20, normal 8/15, strict 5/10 (mailcow `spam-score` "low,high") |
| POST | `/mailboxes/{address}/spam/{allow|block}` | `{value}` (`user@x.com`, `*@x.com`; `@x.com` / `x.com` become `*@x.com`) | same, 201 |
| DELETE | `/mailboxes/{address}/spam/{allow|block}/{id}` | – | same (only the mailbox's own entries; mailcow also lists domain-wide ones, they are hidden) |
| GET | `/quarantine` | – | `{items[{id, mailbox, sender, subject, score, virus, created_at}]}` (recipient in the site's domains, newest first, max 200) |
| POST | `/quarantine/{id}/release` | – | `{ok:true}` (`edit/qitem action=release`) |
| DELETE | `/quarantine/{id}` | – | `{ok:true}` (`delete/qitem [id]`) |

Audit: `comail.dmarc_policy`, `comail.spam_level`, `comail.spam_list`, `comail.quarantine_release`, `comail.quarantine_deleted`.

mailcow shapes verified live (2026-10-26, version 2026-10): `get/spam-score/{a}` → `{"score":"8,15"}` (server default when none); `get/rl-domain/{d}` → `{"value":"1000","frame":"h"}` or `{}`; `get/policy_*_mailbox/{a}` → `[{object, value, prefid}]` or `{}`; `get/quarantine/all` → `[{id, qid, subject, virus_flag (LOCATE position, 0 = clean), score, rcpt, sender, action, created (unix), notified}]`; no `get/mta-sts` route.

## Ops UI

- `/mail-servers`: provider select offers CoMail (mailcow): base URL (`https://`), mail hostname, API key (password input, never rendered). Creating a CoMail server needs `ops.danger`. **Bağlantıyı dene** = `GET /get/status/version`. The show page lists every CoMail domain. The provider of a saved server is fixed. Site mail-server selects and `mail_server_id` validation only offer Hostinger servers.
- `/mail-packages` (Posta nav group): list, create, edit, delete (`ops.write`; delete `ops.danger`, never the default or a package in use). Editing re-applies limits to every site using it (`ApplyComailPackageJob`).
- Site detail → Infrastructure → **CoMail (E-posta)** card (`#site-comail`): package select, domain table (status, DNS `ok/total`, MX chip), **DNS’i denetle**, **DNS uygula**, **MX’i CoMail’e geçir** (PlaneConfirm), add domain from the site's eligible hosts, **CMS’e tekrar gönder**. Gate: site `update`.
- Audit: `mail_package.*`, `mail_server.*`, `comail.package_changed`, `comail.domain_added|removed|enrolled`, `comail.dns_applied`, `comail.mx_activated`, `comail.configure_requeued`, `comail.mailbox_*`, `comail.alias_*`, `comail.autoreply_updated`, `comail.migration_*`, `comail.hposta_migrated`, `comail.access_denied`. Never a password or key.
- MCP (`RouteToolCatalog::comail()`): `set_site_mail_package`, `add_comail_domain`, `apply_comail_dns`, `check_comail_dns`, `activate_comail_mx`, `resend_comail_configure`, `create_mail_package`, `update_mail_package`, `delete_mail_package`.

## v2: billing and webmail SSO

Contract: `C:\www\tek-seferlik-isler\comail\CONTRACT-v2.md`. Scope is the e-mail package only; card (iyzico and/or PayTR, ops picks) and EFT; monthly and yearly.

### Data (migration `2026_10_27_100000_create_comail_billing_tables`)

| Table / column | Meaning |
|---|---|
| `mail_packages.price_yearly` | Nullable. A period without a price cannot be ordered (`price_missing`). |
| `mail_subscriptions` | One per site (`site_id` unique): package, `period` (monthly/yearly), `status` active → grace → expired (canceled unused in v2), `current_period_start/end`, `auto_renew` (always false, no stored cards). No row = default package. `sites.mail_package_id` stays the effective package. |
| `mail_orders` | `reference` `CM-XXXXXX` (PayTR `merchant_oid` = the reference without the dash), `kind` new/renewal/change, `amount`, `method` iyzico/paytr/eft, `status` pending / awaiting_transfer / transfer_reported / paid / failed / canceled / expired, `billing` json, `provider_token` (iyzico CF token or PayTR iframe token), `provider_payment_id`, `provider_payload` (whitelisted, never card data), `return_url`, `paid_at`, `confirmed_by`, `notes`, `transfer_reported_at`, `expires_at` (card 30 min, EFT 7 days). |
| `billing_settings` | Singleton `BillingSetting::current()`: iyzico (enabled, sandbox, key/secret encrypted), PayTR (enabled, test mode, merchant id, key/salt encrypted), EFT (enabled, bank, holder, IBAN, note), `vat_included`, `grace_days` (7), `distance_sales_url`. Secrets: encrypted cast + `$hidden`, never rendered back; a blank form field keeps the stored value. |
| `mail_servers.sso_secret` | Encrypted, 48 random chars generated when a mailcow server is saved. `php artisan ops:comail-sso-secret [--rotate]` prints it for the webmail configuration. |

### Behaviour (`app/Services/Billing`)

- `MailBilling::createOrder`: active package by slug, method must be in `BillingSetting::methods()` (`method_disabled`), price for the period (`price_missing`). Kind: renewal when the live (active/grace) subscription has the same package, change when another, else new. `return_url` is kept only when https and on one of the site's own hosts (domains, primary, temporary, agent host, with or without www); otherwise Plane's result page is used.
- `markPaid` (verified card result or ops EFT confirm): transaction with a row lock; an already paid order returns false (callbacks repeat). Renewal of the live same package extends from `current_period_end`; new/change starts now. Sets `sites.mail_package_id`, dispatches `ApplyComailPackageJob`, audits `comail.order_paid`. A late card payment for an expired/canceled order still activates (the money moved).
- `ExpireMailSubscriptionsJob` daily 03:55: active past end → grace; grace past end + grace_days → expired, `sites.mail_package_id = null` (only while it is still the subscription's package), limits re-applied. Pending card orders and awaiting EFT orders past `expires_at` → expired (`transfer_reported` waits for ops).
- Downgrade safety: `ComailLimits::safeDomainAttributes` sets every mailcow domain ceiling to max(current usage, package limit), so `edit/domain` never fails and nothing is deleted. `overLimit()` → `over_limit` in `GET /overview` and `GET /billing`; new mailboxes/aliases/domains are refused with the v1 `limit_*` errors; shrinking a mailbox quota is always allowed.

### Hosted checkout (public, no session/CSRF/IP allowlist: `routes/pay.php`)

| Route | Purpose |
|---|---|
| `GET /pay/{order}/{token}` (`pay.show`) | Token = HMAC(app key, order id + expiry), 48 hex. Order summary + iyzico CF `paymentPageUrl` iframe or PayTR iframe (`/odeme/guvenli/{token}`); EFT orders show the bank card. An open checkout is reused for 25 min on reload. |
| `POST /pay/callback/iyzico` | iyzico posts `token`; Plane retrieves the CF result server-to-server, checks the response HMAC, `basketId` = reference, paid price and currency, then pays or fails the order and sends the top window to `return_url?comail_order=<id>&status=paid|failed`. |
| `POST /pay/callback/paytr` | PayTR notification (set it as **Bildirim URL** in the PayTR panel): hash check (400 `bad hash`), amount check, answers plain `OK`. |
| `GET/POST /pay/{order}/{token}/done/{ok,fail}` | PayTR `merchant_ok_url` / `merchant_fail_url`: waits up to ~10 s for the notification, then hands over (`status=paid`, `failed`, or `pending` when the notification is late). |
| `GET /pay/{order}/{token}/result` | Plane's own result page (no valid return_url). |

Request/signature code is ported from the CMS gateways (`IyzicoSignatureBuilder`, `PaytrTokenBuilder`); iyzico requests send exactly the signed JSON bytes. Sandbox: `iyzico_sandbox` → `sandbox-api.iyzipay.com`; PayTR `test_mode=1`. nginx sends no `X-Frame-Options` under `/pay/` (the providers load the result pages inside their frame); Laravel sets `frame-ancestors 'self'` on the checkout page and `'self' https://www.paytr.com https://*.iyzipay.com` on the pages loaded inside the provider frame.

### Site API additions (same auth)

| Method | Path | Response |
|---|---|---|
| GET | `/billing` | `current{package{…limits, description}, period, status, period_start, period_end, grace_ends_at, auto_renew, over_limit, usage}`, `packages[{slug,name,description,mailbox_limit,storage_mb,mailbox_max_quota_mb,alias_limit,domain_limit,price_monthly,price_yearly,currency,is_default,is_current}]`, `methods[]`, `vat_included`, `bank{bank_name,account_holder,iban,note}` or null, `distance_sales_url`, `orders[]` (last 20). No subscription: `period/status/period_end` null. |
| POST | `/billing/orders` | `{package, period, method, billing{name, company?, tckn_or_vkn (10–11 digits), tax_office?, address, city, district?, phone, email}, return_url?}` → 201 card `{order, checkout_url}` / EFT `{order, bank, reference, amount, currency, expires_at}` |
| POST | `/billing/orders/{id}/transfer-reported` | `{note?}` → order (flat and under `order`) |
| POST | `/billing/orders/{id}/cancel` | pending / awaiting_transfer only (`order_not_cancellable`) |
| POST | `/mailboxes/{address}/webmail-login` | `{url, expires_in: 120}` |

Order row: `{id, reference, package{slug,name}, package_slug, package_name, period, kind, amount (number), currency, method, status, created_at, paid_at, expires_at}`. `GET /overview` also carries `over_limit` and `subscription{status, period, period_end, grace_ends_at}` or null.

### Webmail SSO

`webmail-login` deletes the mailbox's previous `CoMail tek tık giriş` app passwords, creates a new one (32 chars, imap + smtp), stores `{address, password}` encrypted in the cache under a random 64-hex token for 120 s, audits `comail.webmail_login` (address only) and returns `{base_url}/webmail/?_task=login&_comail_sso={token}`.

`POST /internal/comail/v1/sso/redeem` (webmail server only, `throttle:comail-sso` 30/min/IP): body `{token}`, headers `X-CoMail-Timestamp` + `X-CoMail-Signature` = hex HMAC-SHA256 of `{timestamp}.{rawBody}` with `sso_secret`, ±60 s → `{username, password}` once (`Cache::pull`), then 404 `not_found`; bad signature 401 `unauthorized`.

### Ops

- Posta → **Posta siparişleri** (`/billing/orders`, filters status/method/site; row → detail with billing details, notes, history). Confirm EFT = `ops.danger`; cancel / mark failed = `ops.write`. Audits: `comail.order_created|paid|failed|canceled|expired`, `comail.transfer_reported`, `comail.subscription_grace|expired`.
- Posta → **Ödeme ayarları** (`/billing/settings`, edit = `ops.danger`, audit `billing.settings_updated` with `has_*` flags only). Shows the iyzico callback and the PayTR notification URL.
- Package form: yearly price. A package with orders/subscriptions cannot be deleted. Site CoMail card: subscription status, period end, grace end, link to the site's orders.
- MCP: `list_mail_orders` (read), `confirm_mail_order` (danger, `confirmed`), `cancel_mail_order` (write).

## Deploy

1. Deploy Plane; `php artisan migrate --force` (creates the tables and seeds the three packages).
2. `/mail-servers` → New: provider **CoMail (mailcow)**, base URL `https://mail.codron.co`, mail hostname `mail.codron.co`, the mailcow read-write API key (allowed only from 72.62.117.147) → **Bağlantıyı dene**.
3. Cloudflare: keep `mail_template_enabled` on (the CoMail check already skips the Hostinger rows while CoMail exists) or turn it off on accounts that should never get template mail rows.
4. New sites enroll by themselves. Existing ones: `php artisan ops:comail-enroll --all --dry-run`, then per site or `--all` (try deamon-test first).
5. The CMS needs the `comail` module (CMS PR) before the configure push succeeds; until then the hourly reconcile keeps retrying.
6. v2 billing: Posta → Ödeme ayarları (iyzico keys, PayTR merchant id/key/salt, IBAN; start in sandbox/test mode), PayTR panel Bildirim URL = `https://<plane>/pay/callback/paytr`, package yearly prices; webmail SSO secret via `php artisan ops:comail-sso-secret` into the webmail plugin config.

## Tests

`tests/Feature/Comail/*` (API, DNS, enrollment, ops, H-Posta, billing API, checkout, expiry, billing ops, webmail SSO) and `tests/Unit/Comail/*`. `Http::fake` only: `Support/FakeMailcow` (stateful mailcow), `Support/FakeCloudflare`, `Support/FakeDnsResolver`, `Support/FakeIyzico`, `Support/FakePaytr`.
