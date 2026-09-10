# Mail servers (Hostinger)

Plane holds Hostinger Mail API tokens and proxies mailbox operations for Deamon CMS. CMS never receives the token. Mailcow is a disabled “Coming soon” option and cannot be stored.

Vendor endpoint map (CMS repo): `codron-co/deamon` `docs/modules/hostinger-mail-api.md`. Official: https://developers.hostinger.com/#tag-group/mail

## Model

`mail_servers`: name, `provider` (`hostinger` only for now), encrypted `api_token`, `is_enabled`, allowlisted `last_probe_payload` (order id/domain/seats — no token). A mail server is **account credentials**, not a shared mail order.

`sites.mail_server_id` nullable FK (`nullOnDelete`). Each site also stores its own `hostinger_order_id` and `mail_domain` when Plane finds an exact domain match.

## Per-site order matching

Mailbox APIs are scoped to `{orderId}`. Plane never picks one order for every site on a server.

On site create/update (mail server or domain change), provision (after secrets exist), inject agent secret, Test connection, or **Match mail order**:

1. `GET /api/mail/v1/orders?domain={sites.primary_domain}` (paginated).
2. Bind the order only when `domain.name` equals the site primary domain (case-insensitive, no `www` stripping).
3. No match → `sites.hostinger_order_id` / `mail_domain` stay empty; CMS plugin is not enabled.
4. Hostinger Mail has **no create-order endpoint**. Billing `POST /api/billing/v1/orders` purchases catalog items and does not attach a domain — Plane does not auto-purchase. Buy Free Email for that domain in hPanel, then match again.

## Ops UI

`/mail-servers` — table rows open **show**, not edit. Token field matches Cloudflare: password input, never rendered after save, blank on update keeps the existing value. Test connection = list orders and re-match assigned sites. The orders table is a catalog, not a global picker.

Site create/edit **and site detail (Infrastructure)** : mail server select (credentials). Assigning a ready Hostinger server matches the site domain, then POSTs CMS configure when an order exists.

Viewer is read-only (`ops.write`). Destroy uses `PlaneConfirm`.

## Plane → CMS configure

`POST {agent_base_url}/internal/control/v1/mail/configure`

HMAC: `{timestamp}.{nonce}.{rawBody}`, headers `X-Deamon-Timestamp` / `X-Deamon-Nonce` / `X-Deamon-Signature`. Compact JSON. **No token fields.**

Enable (only when the site has a matched order):

```json
{
  "enabled": true,
  "provider": "hostinger",
  "site_id": "<sites.id ULID>",
  "plane_base_url": "<APP_URL>",
  "mail_domain": "shop.example.test",
  "webmail_url": "https://mail.hostinger.com"
}
```

Disable: `enabled: false`, `provider: null`. No agent secret → skip push, flash `needs_secret`.

## CMS → Plane proxy

Unauthenticated session routes (not ops CSRF):

`/internal/site/v1/mail/mailboxes`

Header `X-Deamon-Site: {site ULID}` + same HMAC against **that** site’s `agent_secret`. Replay nonce cache per site. Throttle 60/min. Mailboxes use **that site’s** `hostinger_order_id`.

| Method | Path | Hostinger |
|--------|------|-----------|
| GET | `/mailboxes` | `GET /api/mail/v1/orders/{orderId}/mailboxes` |
| POST | `/mailboxes` | `POST …/mailboxes` `{local_part, password}` |
| PATCH | `/mailboxes/{id}/password` | `PATCH /api/mail/v1/mailboxes/{id}/password` |
| DELETE | `/mailboxes/{id}` | `DELETE /api/mail/v1/mailboxes/{id}` |

Missing/unready mail server or unmatched order → 422 `mail_not_configured`. Passwords are never stored or audited.

## Tests

`tests/Feature/Ops/MailServerOpsTest.php`, `tests/Feature/Mail/SiteMailAssignTest.php`, `tests/Feature/Mail/SiteMailProxyTest.php`. `Http::fake` only.
