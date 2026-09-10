# Mail servers (Hostinger)

Plane holds Hostinger Mail API tokens and proxies mailbox operations for Deamon CMS. CMS never receives the token. Mailcow is a disabled “Coming soon” option and cannot be stored.

Vendor endpoint map (CMS repo): `codron-co/deamon` `docs/modules/hostinger-mail-api.md`. Official: https://developers.hostinger.com/#tag-group/mail

## Model

`mail_servers`: name, `provider` (`hostinger` only for now), encrypted `api_token`, `hostinger_order_id`, cached `mail_domain`, `is_enabled`, allowlisted `last_probe_payload` (order id/domain/seats — no token).

`sites.mail_server_id` nullable FK (`nullOnDelete`).

## Ops UI

`/mail-servers` — table rows open **show**, not edit. Token field matches Cloudflare: password input, never rendered after save, blank on update keeps the existing value. Test connection = `GET /api/mail/v1/orders`. Pick an order on the detail page.

Site create/edit: mail server select. Assigning a ready Hostinger server POSTs CMS configure.

Viewer is read-only (`ops.write`). Destroy uses `PlaneConfirm`.

## Plane → CMS configure

`POST {agent_base_url}/internal/control/v1/mail/configure`

HMAC: `{timestamp}.{nonce}.{rawBody}`, headers `X-Deamon-Timestamp` / `X-Deamon-Nonce` / `X-Deamon-Signature`. Compact JSON. **No token fields.**

Enable:

```json
{
  "enabled": true,
  "provider": "hostinger",
  "site_id": "<sites.id ULID>",
  "plane_base_url": "<APP_URL>",
  "mail_domain": "example.com",
  "webmail_url": "https://mail.hostinger.com"
}
```

Disable: `enabled: false`, `provider: null`. No agent secret → skip push, flash `needs_secret`.

## CMS → Plane proxy

Unauthenticated session routes (not ops CSRF):

`/internal/site/v1/mail/mailboxes`

Header `X-Deamon-Site: {site ULID}` + same HMAC against **that** site’s `agent_secret`. Replay nonce cache per site. Throttle 60/min.

| Method | Path | Hostinger |
|--------|------|-----------|
| GET | `/mailboxes` | `GET /api/mail/v1/orders/{orderId}/mailboxes` |
| POST | `/mailboxes` | `POST …/mailboxes` `{local_part, password}` |
| PATCH | `/mailboxes/{id}/password` | `PATCH /api/mail/v1/mailboxes/{id}/password` |
| DELETE | `/mailboxes/{id}` | `DELETE /api/mail/v1/mailboxes/{id}` |

Missing/unready mail server → 422 `mail_not_configured`. Passwords are never stored or audited.

## Tests

`tests/Feature/Ops/MailServerOpsTest.php`, `tests/Feature/Mail/SiteMailAssignTest.php`, `tests/Feature/Mail/SiteMailProxyTest.php`. `Http::fake` only.
