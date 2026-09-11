# Runbook — Coolify "Too Many Attempts." (429)

Spec: [2026-09-11-coolify-throttle-cure-design.md](../superpowers/specs/2026-09-11-coolify-throttle-cure-design.md)

## What used to happen

A bulk action (pin / deploy / sync / App health) fired 3–4 Coolify calls per site
with no spacing, so Coolify's Laravel `throttle` middleware answered
`429 Too Many Attempts.` partway through the sweep. Worse, every site that *did*
deploy started a `PollDeploymentJob` that re-polled every 15 s with no jitter; those
polls arrived as one burst, got throttled, and the poll job wrote the throttle into
the deployment row. Healthy builds ended up as **failed** with an English message.

## What happens now

| Layer | Behavior |
|-------|----------|
| Poll job | A 429 / 5xx / timeout on a status read **reschedules** the poll. It never marks the deployment failed. Polls are jittered and grow from 15 s toward 60 s, and stop early when the webhook already finished the row. |
| Client | `CoolifyClient` paces calls per Coolify host and retries 429 honoring `Retry-After`. One 429 puts the whole host on cooldown, so the rest of the sweep waits. |
| Bulk | A throttled site goes to the **back of the queue** and is retried up to `COOLIFY_BULK_SITE_ATTEMPTS` (6) times. The sweep finishes every site instead of failing the tail. |
| Concurrency | Only one Coolify-touching ops job runs at a time (`ops:coolify-bulk` cache lock); a second one stays queued and retries. |
| Copy | Rate-limit text is Turkish everywhere. `Too Many Attempts.` is never shown. |

Retries never replay a `POST /deploy` after a server error — only after a 429, which
Coolify rejects before the controller runs. So a bulk action cannot double-deploy.

## Operatör notu — takılı kalan satırlar

Olaydan kalan **Too Many Attempts.** satırları gerçek deploy hatası değil; sadece
durum okuması engellenmişti. Coolify o build'leri büyük ihtimalle bitirdi.

1. Önce listeyi görün (hiçbir şey yazmaz):

   ```
   php artisan ops:heal-throttled-deploys --dry-run
   ```

2. Sonra Coolify gerçeğinden düzeltin:

   ```
   php artisan ops:heal-throttled-deploys
   ```

   Komut her satırı Coolify'den yeniden okur, doğru durumu yazar ve son deploy
   başarılıysa `sites.status` değerini `error` → `active` yapar.

3. `manuel · kuyrukta` kalmış ama Coolify'de bitmiş satırlar da aynı komutla
   düzelir. Tek site için site detayında **Sync** de yeter.

4. Yalnızca komuttan sonra hâlâ **hata** görünen siteler için toplu işlemi tekrar
   çalıştırın. Tümünü yeniden pinlemeye gerek yok.

> Coolify yükü veya kuyruğu yüksekken filo geneli senkron başlatmayın; toplu
> işlemler zaten sıraya girer ve kendini yavaşlatır.

## Tuning

| Env | Default | Meaning |
|-----|---------|---------|
| `COOLIFY_MIN_INTERVAL_MS` | 120 | Spacing between calls to one Coolify host |
| `COOLIFY_RETRY_ATTEMPTS` | 3 | Attempts per single HTTP call |
| `COOLIFY_BULK_SITE_ATTEMPTS` | 6 | Times one site may be re-queued in a sweep |
| `COOLIFY_DEPLOY_POLL_SECONDS` | 15 | Starting poll interval |
| `COOLIFY_DEPLOY_POLL_MAX_SECONDS` | 60 | Poll interval ceiling |
| `COOLIFY_DEPLOY_POLL_TRANSIENT_ATTEMPTS` | 8 | Throttled status reads tolerated before failing |

Raise `COOLIFY_MIN_INTERVAL_MS` if Coolify still throttles; bulk gets slower but
does not fail.
