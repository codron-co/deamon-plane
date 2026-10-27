# Meta App Review — Instagram Akışı (`instagram_business_basic`)

Meta app **Deamon** (App ID `2241231169755818`, business portfolio **Kanat Medya**, verified). Instagram app **Deamon-IG** (Instagram app ID `1798943774714182`). Use case: *Manage messaging & content on Instagram* → API setup with Instagram login. The app stays in development mode until this review passes; until then only accounts with the **Instagram Tester** role who accepted the invite can connect.

We request **one permission**: `instagram_business_basic`. Nothing else (no publishing, comments, messages, insights, webhooks).

## 1. Checklist

| Step | Where | Value |
| --- | --- | --- |
| App icon 1024×1024 | App settings → Basic | `docs/modules/assets/deamon-app-icon-1024.png` |
| Privacy policy URL | App settings → Basic | `https://plane.codron.co/instagram/gizlilik` |
| Terms of Service URL | App settings → Basic | `https://plane.codron.co/instagram/kosullar` |
| Data deletion instructions URL | App settings → Basic | `https://plane.codron.co/instagram/veri-silme` |
| App domains | App settings → Basic | `plane.codron.co` |
| Category | App settings → Basic | Business and Pages |
| OAuth redirect URI | Instagram business login settings | `https://plane.codron.co/instagram/callback` |
| Deauthorize callback URL | Instagram business login settings | `https://plane.codron.co/webhooks/instagram/deauthorize` |
| Data deletion request URL | Instagram business login settings | `https://plane.codron.co/webhooks/instagram/data-deletion` |
| Permission added | Use case → Permissions and features | `instagram_business_basic` → Add to App Review |
| Test API calls | Review → Testing | at least 1 call in the last 30 days (counted per permission) |
| Business verification | Review → Verification | Kanat Medya (done) |
| Screencast | App Review request | section 4 |
| Reviewer instructions | App Review request | section 5 |
| Data handling questions | App Review request | section 6 |

The two callback URLs need Plane with the Meta callbacks (`docs/modules/instagram-oauth.md`); automatic deletion on a site needs CMS 1.2.89+, older sites are handled by hand from the Plane Instagram page.

## 2. Permission usage — `instagram_business_basic`

Paste into "Tell us how your app uses this permission":

```text
Deamon is a website builder (CMS) for small businesses. Each business manages its own website in the Deamon admin panel.

In the admin panel (Integrations → Instagram) the site owner clicks "Connect with Instagram" and logs in with the business's own Instagram professional account. We use instagram_business_basic only to read that same account's profile (user ID, username, account type) and its own media (caption, media type, media URL, thumbnail URL, permalink, timestamp and the items of album posts) through GET /me and GET /me/media on graph.instagram.com.

We use this data to show the account's latest posts as an "Instagram feed" section on the business's own public website. Each post links back to Instagram. The admin panel shows the connected username, the last refresh time and the list of fetched posts; the owner chooses how many posts to show, can hide single posts and can disconnect at any time, which deletes the token and the imported posts and images.

We do not publish, comment, send messages or read other users' data, and we do not use the data for advertising or analytics. Without this permission the business could not show its own Instagram posts on its website.
```

Türkçesi (bilgi için): Deamon küçük işletmeler için bir web sitesi platformudur. Site sahibi yönetim panelinde «Instagram ile bağlan»a basıp işletmenin kendi Instagram profesyonel hesabıyla giriş yapar. `instagram_business_basic` yalnız o hesabın profilini ve kendi gönderilerini okumak için kullanılır; gönderiler işletmenin kendi sitesinde Instagram akışı olarak gösterilir. Paylaşım, yorum, mesaj, başka kullanıcı verisi, reklam ya da analiz yok.

## 3. App details (if asked)

```text
App name: Deamon
Platform: Website (Deamon CMS sites; the Instagram login runs through https://plane.codron.co)
Operator: CodRon / Kanat Medya, Türkiye — info@codron.co
```

## 4. Screencast script

Meta wants one video per permission showing the full flow, from login to where the data is used. Record at 1080p, 2–3 minutes, no sound needed. The admin panel is Turkish, so add **English captions** (text overlay) for every step; reviewers reject videos they cannot follow.

Before recording, on deamon-test (`https://deamon.testdomain.tr`, CMS 1.2.76+):
- the **Instagram Akışı** module is on and an **Instagram Akışı** block is placed on a public page (e.g. the home page);
- the Instagram test account is a professional (Business or Creator) account, has the **Instagram Tester** role and has accepted the invite, and has a few posts;
- the account is not connected yet (disconnect first if needed).

| # | Do | Caption (English) |
| --- | --- | --- |
| 1 | Show the browser on `https://deamon.testdomain.tr/admin`, already signed in as site admin | "Deamon CMS — the business's website admin panel" |
| 2 | Open **Entegrasyonlar → Instagram** | "Integrations → Instagram: not connected yet" |
| 3 | Click **Instagram ile bağlan** | "The site owner clicks Connect with Instagram" |
| 4 | Instagram login page opens (URL bar shows instagram.com); log in with the test account | "Instagram Business Login" |
| 5 | Consent screen: show the requested access and click **Allow** | "The owner grants instagram_business_basic (profile and media)" |
| 6 | Back in the admin: the success message, **@username**, last refresh, the grid of fetched posts | "Data read with instagram_business_basic: the account's username and its own posts" |
| 7 | Hide one post with its toggle; change the post count | "The owner chooses which posts appear" |
| 8 | Open the public page with the Instagram block; click a post — it opens on instagram.com | "The posts are shown on the business's own website and link back to Instagram" |
| 9 | Back in the admin, click **Bağlantıyı kaldır** and confirm; the posts disappear | "Disconnect deletes the token and the imported posts" |
| 10 | Open `https://plane.codron.co/instagram/gizlilik` for a second | "Privacy policy and data deletion instructions" |

Do not show passwords (type them off-camera or let the field mask them).

## 5. Reviewer instructions

Create a dedicated admin user on deamon-test for Meta (e.g. `meta-review@codron.co`, Plane → test site → admins) and an Instagram professional test account with the Instagram Tester role. Put the passwords only into the Meta form.

```text
The admin panel is in Turkish; English names are in brackets.

1. Open https://deamon.testdomain.tr/admin and sign in:
   Email: <reviewer email>
   Password: <reviewer password>
2. In the left menu open "Entegrasyonlar" (Integrations) → "Instagram".
3. Click "Instagram ile bağlan" (Connect with Instagram). You are taken to Instagram's login page.
   Log in with an Instagram professional (Business or Creator) account. You can use our test account:
   Username: <test instagram username>
   Password: <test instagram password>
4. Approve the request. You return to the admin page, which now shows the connected username and the account's latest posts (read with instagram_business_basic).
5. Open https://deamon.testdomain.tr/<page with the Instagram block> to see the posts in the "Instagram" section of the public website. Each post links to Instagram.
6. "Bağlantıyı kaldır" (Disconnect) removes the token and deletes the imported posts.

Privacy policy: https://plane.codron.co/instagram/gizlilik
Data deletion: https://plane.codron.co/instagram/veri-silme
```

## 6. Data handling questions

Answers for the "Data handling" / Data Protection section:

- **Responsible entity:** Kanat Medya (business portfolio owner), Türkiye. Contact info@codron.co.
- **Data processors / service providers with access to Platform Data:** Hostinger International Ltd. (VPS hosting the websites and Plane; servers in Vilnius, Lithuania, EU). No other processor receives Instagram data.
- **Countries where Platform Data is processed or stored:** Lithuania (servers), Türkiye (operator).
- **Public authority requests in the past 12 months:** No.
- **Policies for public authority requests:** we review the legality of each request, challenge unlawful ones, disclose only the minimum required, and document every request.
- **Data use:** only to display the account's own posts on its own website; no sale, no advertising, no sharing with third parties.

## 7. After approval

1. App settings → **Publish** (switch the app to Live).
2. Check with a non-tester professional account that "Instagram ile bağlan" works on a customer site.
3. Customers no longer need tester invites; existing testers keep working.
