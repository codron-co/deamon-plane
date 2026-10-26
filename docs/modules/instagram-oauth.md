# Instagram OAuth broker

Plane hosts the single Meta app ("Instagram API with Instagram Login") that every Deamon CMS site uses for its **Instagram Akışı** module (CMS 1.2.76+, `docs/modules/instagram-feed.md` in the CMS repo). The app secret never leaves Plane; sites only ever receive the long-lived token of the account their admin authorised.

## Settings

`Instagram` in the ops nav (`/instagram`, `ops.instagram.*`). Model `InstagramSetting` (singleton, `app_secret` encrypted cast, blank keeps the stored secret, audit `instagram.settings_updated` without the secret). Saving dispatches `DispatchInstagramPushJob`.

The page shows the URLs to enter in the Meta dashboard:

- Valid OAuth redirect URI (Instagram business login settings): `{PublicAppUrl::forAgents()}/instagram/callback`
- Deauthorize callback URL (Instagram business login settings): `{…}/webhooks/instagram/deauthorize`
- Data deletion request URL (Instagram business login settings): `{…}/webhooks/instagram/data-deletion`
- Privacy policy URL (App settings → Basic): `{…}/instagram/gizlilik`
- Terms of Service URL (App settings → Basic): `{…}/instagram/kosullar`
- Data deletion instructions URL (App settings → Basic): `{…}/instagram/veri-silme`

It also lists the last 10 deletion requests from Meta (see below).

## Push to sites

`InstagramAgentClient::pushOAuthConfig()` → `POST /internal/control/v1/instagram/oauth-config` `{enabled, connect_url, plane_site_id}` for sites reporting `deamon_version >= 1.2.76`. `ReconcileInstagramPushJob` runs hourly and re-pushes to sites whose health reports `instagram.oauth_configured = false` (a site that upgraded after the app was configured).

## Flow (public routes, no Plane login)

Routes in `routes/web.php`, exempt from `RestrictOpsByIp`, throttled 30/min:

1. `GET /instagram/connect?site&ts&nonce&return&sig` — the CMS admin opens this. `InstagramConnectLink::verify()`:
   - signature: `ControlPlaneAgentSignature::matches(site agent secret, ts, nonce, "instagram-connect\n{site}\n{return}")`;
   - `|now − ts| ≤ 600 s`; nonce single-use (cache, 20 min);
   - `return` must be `https` and one of the site's operator hosts (or its temporary domain).
   Then a session-bound state (HMAC with `app.key`, 20 min) and a redirect to `https://www.instagram.com/oauth/authorize` (`scope=instagram_business_basic`).
2. `GET /instagram/callback?code&state` — state checked against the session; code → short-lived token (`POST api.instagram.com/oauth/access_token`) → 60-day token (`GET graph.instagram.com/access_token?grant_type=ig_exchange_token`); `InstagramAgentClient::pushToken()` → `POST /internal/control/v1/instagram/connect`. On success the app-scoped `user_id` from the code exchange and the username are stored in `instagram_accounts` (`InstagramAccount::recordConnection()`; connecting another account to the same site retires the old row). The browser goes back to `return?instagram=connected|denied|error&reason=…`. Audit `instagram.connected` / `instagram.connect_failed` (username only, never the token).
3. Public legal pages (Meta App Review): `GET /instagram/gizlilik` (privacy policy), `/instagram/kosullar` (terms), `/instagram/veri-silme` (data deletion instructions), `/instagram/veri-silme/durum/{code}` (status of one deletion request). One view `instagram.legal` renders `instagram.privacy.*` / `instagram.terms.*` from `lang/{en,tr}/instagram.php`; the app locale is `en`, which is what reviewers read.

## Meta callbacks (deauthorize, data deletion)

`routes/webhooks.php` (no session, CSRF or IP allowlist), throttled 60/min, `Webhooks\InstagramMetaController`:

- `POST /webhooks/instagram/deauthorize` and `POST /webhooks/instagram/data-deletion` take Meta's `signed_request`. `InstagramSignedRequest::parse()` checks the HMAC-SHA256 signature with the Instagram app secret and the algorithm; anything else is `400`.
- Each creates an `InstagramDeletionRequest` (12-character confirmation code) and dispatches `ProcessInstagramDeletionRequestJob`. Data deletion answers `{url, confirmation_code}` as Meta requires; `url` is the public status page.
- The job finds the active `instagram_accounts` rows for that user id and calls `InstagramAgentClient::disconnect()` → `POST /internal/control/v1/instagram/disconnect` (CMS 1.2.89+). The site deletes the token and every imported post and image, campaign images included.
  - All sites done → `completed`.
  - No mapped site (connected before the mapping existed, or never), CMS older than 1.2.89, or a site still failing after 3 tries (only unreachable / 5xx are retried) → `manual`, `Log::warning` and an audit row. Ops disconnects that site by hand (site admin → Integrations → Instagram → Disconnect, or the agent call) within the 7 days the public page promises.

## Going live

In development mode only accounts with the app role **Instagram Tester** can authorise (the account must also accept the invite on Instagram). To let every customer connect: complete App Review for `instagram_business_basic`, then switch the app to Live. The checklist, the texts to paste and the screencast script are in `docs/modules/instagram-app-review.md`.

Tests: `tests/Feature/Ops/InstagramOAuthTest.php`, `tests/Feature/Ops/InstagramMetaCallbackTest.php`.
