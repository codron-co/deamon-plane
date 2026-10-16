# Instagram OAuth broker

Plane hosts the single Meta app ("Instagram API with Instagram Login") that every Deamon CMS site uses for its **Instagram Akışı** module (CMS 1.2.76+, `docs/modules/instagram-feed.md` in the CMS repo). The app secret never leaves Plane; sites only ever receive the long-lived token of the account their admin authorised.

## Settings

`Instagram` in the ops nav (`/instagram`, `ops.instagram.*`). Model `InstagramSetting` (singleton, `app_secret` encrypted cast, blank keeps the stored secret, audit `instagram.settings_updated` without the secret). Saving dispatches `DispatchInstagramPushJob`.

The page shows the two URLs to enter in the Meta dashboard:

- Valid OAuth redirect URI: `{PublicAppUrl::forAgents()}/instagram/callback`
- Data deletion instructions URL: `{PublicAppUrl::forAgents()}/instagram/veri-silme`

## Push to sites

`InstagramAgentClient::pushOAuthConfig()` → `POST /internal/control/v1/instagram/oauth-config` `{enabled, connect_url, plane_site_id}` for sites reporting `deamon_version >= 1.2.76`. `ReconcileInstagramPushJob` runs hourly and re-pushes to sites whose health reports `instagram.oauth_configured = false` (a site that upgraded after the app was configured).

## Flow (public routes, no Plane login)

Routes in `routes/web.php`, exempt from `RestrictOpsByIp`, throttled 30/min:

1. `GET /instagram/connect?site&ts&nonce&return&sig` — the CMS admin opens this. `InstagramConnectLink::verify()`:
   - signature: `ControlPlaneAgentSignature::matches(site agent secret, ts, nonce, "instagram-connect\n{site}\n{return}")`;
   - `|now − ts| ≤ 600 s`; nonce single-use (cache, 20 min);
   - `return` must be `https` and one of the site's operator hosts (or its temporary domain).
   Then a session-bound state (HMAC with `app.key`, 20 min) and a redirect to `https://www.instagram.com/oauth/authorize` (`scope=instagram_business_basic`).
2. `GET /instagram/callback?code&state` — state checked against the session; code → short-lived token (`POST api.instagram.com/oauth/access_token`) → 60-day token (`GET graph.instagram.com/access_token?grant_type=ig_exchange_token`); `InstagramAgentClient::pushToken()` → `POST /internal/control/v1/instagram/connect`. The browser goes back to `return?instagram=connected|denied|error&reason=…`. Audit `instagram.connected` / `instagram.connect_failed` (username only, never the token).
3. `GET /instagram/veri-silme` — data deletion instructions (required by Meta).

## Going live

In development mode only accounts with the app role **Instagram Tester** can authorise. To let every customer connect: complete App Review for `instagram_business_basic` (privacy policy URL, data deletion URL, screencast of the connect flow and the feed on a site), business verification (Kanat Medya portfolio), then switch the app to Live.

Tests: `tests/Feature/Ops/InstagramOAuthTest.php`.
