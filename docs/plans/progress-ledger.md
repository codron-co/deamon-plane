# Plane progress ledger

Durable orchestrator state. Do not re-dispatch completed tasks.

## Stale core theme + storefront smoke guard (2026-09-23)

- Status: **committed on `alpha`**. Trigger: moonagro.com `/timeline` 500 (`View [partials.timeline.feed] not found`) after the new theme was installed. Root cause in the CMS: `themes/default` on the persistent volume was only seeded when missing, so the August copy never got the timeline partials.
- CMS 1.2.30 (`codron-co/deamon` alpha `f429410d`): Dockerfile stamps the seed (`.seed-stamp`), entrypoint `sync_default_theme_from_seed` refreshes a drifted copy on boot, health reports `core_theme`.
- Plane: `core_theme_in_sync` in the health summary, `CoreThemeHealer` (auto restart, 6 h guard), App-health `core_theme_stale`; `ThemeSmokeCheck` after install/update/sync with automatic files/sync rollback; assign refused below `minimum_deamon_version`; `themes.smoke_paths` from `theme.json`. premium-moonagro declares `/timeline`.
- Docs: [../modules/theme-catalog.md](../modules/theme-catalog.md), [../modules/agent-client.md](../modules/agent-client.md). Tests: `ThemeStorefrontGuardTest` (8).

## Deploy failure diagnosis + automatic safe fixes (2026-09-19)

- Status: **committed on `alpha`**. Trigger: WetSan (`alaibjmbwyug8jpj1uigndk1`) failing since 2026-09-10 with `dependency failed to start: container mysql-… exited (1)` and the operator asking why Plane only shows the raw Coolify dump; Bizim Usta / Çınar Oto / Çukurova Profil on the placeholder page with a "finished" deploy.
- `DeploymentFailureClassifier` (24 codes, ordered container-evidence → git/registry → host → app → compose symptom → build → Coolify/Plane) + `DeploymentDiagnoser` (Coolify `GET /applications/{uuid}/logs`, redaction, 8 KB tail, one `AUTO_SAFE` fix with a 24 h repeat guard) + `DiagnoseDeploymentJob` from the `Deployment::saved` hook. Column `deployments.diagnosis` (json). UI: Teşhis card on deploy detail, headline on list/widget/report, `deploy_diagnosed` / `app_not_running` App-health issues, fixes `restart_app` / `sync_deployments` / `stop_then_redeploy` / `rollback_last_good` / `follow_head`. Agent health: `proxy_fallback` when the CodRon placeholder answers. Coolify client: `restartApplication`, `getApplicationLogs`.
- Config `ops.diagnosis.*` (`OPS_DIAGNOSIS_ENABLED`, `OPS_DIAGNOSIS_AUTO_FIX`, log lines/bytes, repeat window); phpunit disables the job, tests opt in.
- Server access stays outside Plane: every manual code carries paste-ready `docker` commands for whoever has SSH. Roadmap and volume rules: [../runbooks/deploy-failure-triage.md](../runbooks/deploy-failure-triage.md). CMS side (1.2.29): compose `restart: on-failure:3` so a crashed container comes back without a reboot stampede.
- Docs: [../modules/ops-sites.md](../modules/ops-sites.md), [../modules/coolify-client.md](../modules/coolify-client.md). Tests: `DeploymentFailureClassifierTest` (11), `DeploymentDiagnosisTest` (8).

## Coolify env catalog from CMS `.env.production.example` per branch (2026-09-14)

- Status: **code** (Deamon Git + prune). Build-pack catalogs (`dockercompose` / `dockerfile`) and in-Plane editing are gone; `coolify_env_defaults` is keyed by git **channel**. Source per channel is `coolify_env_catalog_sources` (repo, commit, fetched_at, last_error).
- `CoolifyEnvCatalogSync` + `EnvExampleParser` read `.env.production.example` from `config('ops.deamon.repository')` at `ref=<channel>` via `GitHubAppClient::fetchTextFile` (**Settings → Deamon Git** only). Triggers: CMS repo `push` webhook (`GitHubWebhookHandler`, same secret), hourly `ops:sync-env-catalog`, Settings **Refresh from GitHub** (`POST /settings/env-defaults/sync`), on-demand when a channel is empty at deploy.
- Value tokens: `{{generated}}`, `{{site.app_key|name|channel|app_env|agent_secret}}`, `{{plane.host}}` (`CONTROL_PLANE_HOST_ALLOWLIST`). Coolify inject rows are not listed. Compose constants and `DEAMON_PLATFORM_MAIL_*` are not catalog rows — compose / `/platform-mail/configure` own them.
- Credentials: Settings → **Deamon Git** (App install on `github_settings.installation_id` / PAT) — not Themes connections.
- Deploy sync **upserts + prunes** leftovers (keeps `SERVICE_*` / `COOLIFY_*` / `APP_URL` / `DEAMON_SITE_HOST` / `SOURCE_COMMIT`; never rotates live secrets).
- Tests seed every channel from `tests/Fixtures/deamon/env-production.example` (base `TestCase::setUp`). Keep that fixture in step with the CMS file. Tests: `EnvExampleParserTest`, `CoolifyEnvCatalogSyncTest`, `CoolifyAppEnvSyncTest`, `SettingsEnvDefaultsTest`, `DeamonGitConnectionTest`, `ProvisionSiteTest`, `ComposePackMigrateTest`, `SiteAppHealthTest`.
- Ops action: Settings → Deamon Git connect on CMS owner; webhook on `codron-co/deamon` → `https://plane.codron.co/webhooks/github` (push); Refresh once per branch. Docs: [../modules/coolify-client.md](../modules/coolify-client.md), [../modules/deployment.md](../modules/deployment.md), [../runbooks/provision-site.md](../runbooks/provision-site.md), [../modules/ops-sites.md](../modules/ops-sites.md).

## Domains on any apex + redeploy after bind; mail configure queued with state (2026-09-14)

- Status: **committed on `alpha`**. Fixes two operator reports: “Ek domain’ler … üzerinde kalmalı” (same-apex rule dropped; a foreign apex gets its own Cloudflare zone recorded on `site_domains`) and “domain ekledikten sonra Coolify deploy gerekiyor” (every domain bind now ends in `CoolifyDeploySettings::redeploy`; the edit form pushes alias changes too, it used to write only the DB).
- Mail: “Mail configure timed out” after saving mailbox domains. Configure moved off the request into `ConfigureSiteMailJob` (retry once on 429 / connection drop), outcome on `sites.mail_configure*`, chip + **CMS’e tekrar gönder** on the Infrastructure card. Save flash no longer depends on the CMS answering.
- Root cause note: the CMS `mail/configure` install step is cheap (the module migrations live in `database/migrations` and already ran at deploy; no install hook). The timeout came from a slow CMS container, not from code we can change — check CMS `laravel.log` next to Plane’s `Site mail configure failed` warning if it recurs. Theme-driven module installs already run in a CMS background task. No CMS change needed. Bulk **Bind on Coolify** stays PATCH-only.
- Docs: [../modules/ops-sites.md](../modules/ops-sites.md), [../modules/mail-servers.md](../modules/mail-servers.md), [../runbooks/provision-site.md](../runbooks/provision-site.md). Tests: `SiteLandingFlowTest` (+3, apex accept, own zone, edit push), `SiteMailAssignTest` (+2), new `RetriesThrottledAgentRequestsTest`. `Http::failedConnection()` segfaults on this PHP 8.2 Windows build — connection retry is unit-tested without Guzzle.

## Admin password invite on site admins (2026-09-14)

- Status: **committed on `alpha`** (4 commits: catalog, agent + controller, UI, docs). Spec: [../superpowers/specs/2026-09-14-admin-password-invite-design.md](../superpowers/specs/2026-09-14-admin-password-invite-design.md). Plan: [../superpowers/plans/2026-09-14-admin-password-invite.md](../superpowers/plans/2026-09-14-admin-password-invite.md).
- Coolify env packs no longer carry `DEAMON_DEFAULT_ADMIN_PASSWORD`; migration `2026_09_14_120000` deletes leftover catalog rows. Provision tests assert the key is not synced.
- Admins tab create has **Davet** next to generate/manual (`password_mode=invite`, no password sent or flashed). `password_is_set=false` rows show **Şifre yok** and **Şifre oluşturma maili gönder** → `POST …/admins/{id}/password-invite` (audit `site.admin.password_invite_sent`, id + email only). `ControlPlaneAgentContract::CMS_VERSION` → 1.2.18; older CMS keeps the outdated flash.
- Not done here: CMS seed / mail side lives in `codron-co/deamon` (CMS plan Tasks 1–5). Live use needs CMS ≥ 1.2.18 deployed.
- Docs: [../modules/site-admins.md](../modules/site-admins.md), [../modules/coolify-client.md](../modules/coolify-client.md), [../modules/deployment.md](../modules/deployment.md), [../modules/ops-sites.md](../modules/ops-sites.md), [../runbooks/provision-site.md](../runbooks/provision-site.md). Tests: `SiteAdminAgentTest` (+5), `ProvisionSiteTest`, `ProvisionSiteCloudflareTest`.

## Overnight morning finalize (2026-09-14 ~01:00 Istanbul)

- Status: **report only, uncommitted**. Operator handoff filled in [../superpowers/plans/2026-09-13-overnight-morning-report.md](../superpowers/plans/2026-09-13-overnight-morning-report.md). `php artisan test` **847 passed** / 0 failed (4619 assertions). `node --test` **18 passed**. Pint `--dirty --test` fails on `PaletteFilters.php` `line_ending` only. No commit, no PR, no push. Do not re-dispatch closed P0/P1/P2 items.

## ADR-11 fifth slice: list fetch / replaceState (2026-09-13)

- Status: **code, uncommitted** (overnight ~03:40; worktree left dirty on purpose). Report: [../superpowers/plans/2026-09-13-overnight-morning-report.md](../superpowers/plans/2026-09-13-overnight-morning-report.md).
- `PlaneOpsContracts` now also exports `isSameList`, `listQueryChanged`, `shouldPatchListRegion`, `listFetchHeaders`, `listHistoryMode` / `listHistoryWrite`, `isListRequeryLink` / `shouldInterceptListHref`. `ops-list.js` calls those instead of closed-over copies. Tests pass header / `{ origin, pathname }` ducks — still no jsdom, no `package.json`.
- `PlaneUI.refresh()` after a region swap still waits for a fixture.
- Docs: [../decisions/adr-11-dom-test-harness.md](../decisions/adr-11-dom-test-harness.md), [../modules/ops-list-async.md](../modules/ops-list-async.md). Tests: `node --test tests/js/*.js`.

## Coolify deep-link copy, domain leftover clear, env-row filter (2026-09-13)

- Status: **code, uncommitted** (overnight ~03:15; worktree left dirty on purpose). Report: [../superpowers/plans/2026-09-13-overnight-morning-report.md](../superpowers/plans/2026-09-13-overnight-morning-report.md).
- Extends the sibling UUID-copy slice: site detail also copies the Coolify UI deep link (hero, Technical identifiers, Deploy menu). Open in Coolify stays.
- `/domains` **Kayıttan sil** clears leftover unbound aliases only. Primaries, temporary hosts, and Coolify-bound rows are skipped. No Coolify HTTP. Danger confirm names the P0-1 count. Fetch refreshes the list.
- Extends Settings jump: the same box filters env-default **rows** in place. Not a ListFragment — Save still posts the whole catalog. `PlaneOpsContracts.envRowVisible` keeps a section-only needle from emptying the table.
- Docs: [../modules/ops-sites.md](../modules/ops-sites.md), [../modules/ops-list-async.md](../modules/ops-list-async.md), [../modules/coolify-client.md](../modules/coolify-client.md). Tests: `SiteDetailTest` (deep-link copy), new `DomainBulkClearTest` (7), `SettingsEnvDefaultsTest` (row haystack), `ConfirmMatrixTest` (domains danger), `node --test` `textMatches` / `envRowVisible`.
- Full suite after this tick: first run **1 failed / 844** (`AgentSecretInjectTest` apostrophe vs Blade `e()`); after the assert fix **845 passed**, 0 failed (4611 assertions). `node --test` **13**. No product change for the flake.

## Copy site ID and Coolify UUIDs on detail (2026-09-13)

- Status: **code, uncommitted** (overnight ~03:30; worktree left dirty on purpose). Report: [../superpowers/plans/2026-09-13-overnight-morning-report.md](../superpowers/plans/2026-09-13-overnight-morning-report.md).
- Site detail hero has **Site ID kopyala** and **Uygulama UUID kopyala** (when attached). Technical identifiers list the ULID plus Coolify app / server / project / environment UUIDs with the same `data-copy-value` primitive. Missing Coolify UUIDs stay `—` and have no copy control.
- Docs: [../modules/ops-sites.md](../modules/ops-sites.md). Tests: `SiteDetailTest` (copy present / absent / Turkish).

## Settings jump / search (2026-09-13)

- Status: **code, uncommitted** (overnight ~03:30; worktree left dirty on purpose). Report: [../superpowers/plans/2026-09-13-overnight-morning-report.md](../superpowers/plans/2026-09-13-overnight-morning-report.md).
- `/settings` has a hash jump nav (env / GitHub / customer defaults / Coolify) and a filter box over section titles plus env keys. A key substring also hides other env rows (`envRowVisible`); a section needle does not blank the catalog. No-JS still uses the hashes. Ctrl/⌘+K `webhook` / `ortam` opens `/settings#…` (`SettingsJump`); empty palette query still lists only pages.
- `PlaneOpsContracts.textMatches` — empty query keeps every section. Tests: new `SettingsJumpTest`. Neighbours: `PaletteSearchTest`, `SettingsEnvDefaultsTest`.

## Domains unbound bulk copy (2026-09-13)

- Status: **code, uncommitted** (overnight ~03:30; worktree left dirty on purpose). Report: [../superpowers/plans/2026-09-13-overnight-morning-report.md](../superpowers/plans/2026-09-13-overnight-morning-report.md).
- Unbound-only confirm names unbound hosts. A mixed list confirm + bar hint say already-bound hosts are skipped and how many in the current filter are still unbound. Sweep behavior unchanged (bound = no-op).
- Docs: [../modules/ops-sites.md](../modules/ops-sites.md). Tests: `DomainBulkBindTest` (unbound confirm + mixed skip copy).

## ADR-11 fourth slice: hidden-tab poll skip (2026-09-13)

- Status: **code, uncommitted** (overnight ~03:30; worktree left dirty on purpose). Report: [../superpowers/plans/2026-09-13-overnight-morning-report.md](../superpowers/plans/2026-09-13-overnight-morning-report.md).
- `PlaneOpsContracts.pageIsHidden` / `shouldSchedulePoll` are what `ops-jobs.js` uses instead of a closed-over `document.hidden`. Tests pass `{ hidden: true }` — still no jsdom, no `package.json`.
- Docs: [../decisions/adr-11-dom-test-harness.md](../decisions/adr-11-dom-test-harness.md). Tests: `node --test tests/js/*.js`.

## Jobs widget failed-only filter (2026-09-13)

- Status: **code, uncommitted** (overnight ~03:15; worktree left dirty on purpose). Report: [../superpowers/plans/2026-09-13-overnight-morning-report.md](../superpowers/plans/2026-09-13-overnight-morning-report.md).
- **Sadece hatalılar** on the jobs widget polls `GET /jobs?failed=1`: this operator's failed jobs and deploys in the two-hour window. Unknown `failed` values widen. The live Coolify queue is not merged (no `GET /deployments` on that lane). Toggle persists in `sessionStorage`; an empty failed-only list keeps the panel so the toggle stays reachable.
- `PlaneOpsContracts.jobsIndexUrl` / `isFailedStatus` — ADR-11 third slice. Tests still `require` the production file.
- Docs: [../modules/ops-sites.md](../modules/ops-sites.md), [../decisions/adr-11-dom-test-harness.md](../decisions/adr-11-dom-test-harness.md). Tests: new `JobsFailedFilterTest` (5). Neighbours: `JobsPollPressureTest`, `DeploymentPollWidgetTest`.

## Activity CSV export (2026-09-13)

- Status: **code, uncommitted** (overnight ~03:15; worktree left dirty on purpose). Report: [../superpowers/plans/2026-09-13-overnight-morning-report.md](../superpowers/plans/2026-09-13-overnight-morning-report.md).
- **CSV indir** on `/activity` downloads the current filters (`GET /activity/export`). Same `ActivityFilters` as the page. UTF-8 BOM, redacted title/detail, no payloads. Capped at `ops.activity.export_limit` (1000). Viewer can export; guest goes to sign-in.
- Docs: [../modules/ops-activity.md](../modules/ops-activity.md). Tests: `ActivityFeedTest` now 14.

## Coolify test hints name the server list (2026-09-13)

- Status: **code, uncommitted** (overnight ~02:55; worktree left dirty on purpose). Report: [../superpowers/plans/2026-09-13-overnight-morning-report.md](../superpowers/plans/2026-09-13-overnight-morning-report.md).
- Operator Coolify show copy no longer says `listServers`. The overview hint and the next-action test hint say **sunucu listesini sorgular** / “queries the server list”. The test route is still `listServers` only.
- Docs: [../modules/coolify-client.md](../modules/coolify-client.md). Tests: `CoolifyConnectionsTest` (connection show under `locale=tr` must not contain `listServers`).

## DOM harness second slice: bulk interpolate + typing guards (2026-09-13)

- Status: **code, uncommitted** (overnight ~02:55; worktree left dirty on purpose). Report: [../superpowers/plans/2026-09-13-overnight-morning-report.md](../superpowers/plans/2026-09-13-overnight-morning-report.md).
- **ADR-11 second slice.** `PlaneOpsContracts` now also exports `bulkScope`, `interpolateConfirm`, `isTyping`, `confirmOpen`. `ops-ui.js` substitutes `__COUNT__` / `__TARGET__`; `ops-shortcuts.js` and `ops-palette.js` call the guards (they were never in `ops-list.js`). Tests still `require` the production file — no algorithm copy, no Playwright, no jsdom, no `package.json`.
- Still later: `document.hidden`, list `fetch` / `replaceState`. ADR-10 `health=` / `app=` stays closed.
- Docs: [../decisions/adr-11-dom-test-harness.md](../decisions/adr-11-dom-test-harness.md), [../modules/ops-sites.md](../modules/ops-sites.md), [../modules/ops-list-async.md](../modules/ops-list-async.md).

## Health and app-issue list filters are SQL (2026-09-13)

- Status: **code, uncommitted** (overnight ~02:50; worktree left dirty on purpose). Backlog **P1-7 leftovers** / [ADR-10](../decisions/adr-10-health-app-filter-verdict.md). Report: [../superpowers/plans/2026-09-13-overnight-morning-report.md](../superpowers/plans/2026-09-13-overnight-morning-report.md).
- **`health=unhealthy` and `app=issues`** are Sites list filters. Verdicts live on `sites.health_unhealthy` / `health_verdict_at` and `app_has_issues` / `app_health_issue_count` / `app_health_verdict_at`, stamped by `SiteFilterVerdict` on the same save as the health / inspect payload (and status, secret, dockerfile notes, Coolify uuid). The list path does not walk the fleet.
- **One predicate for unhealthy.** `Site::scopeUnhealthy()` is the card and the filter: persisted flag, or `status=error`, or stored agent-fail JSON, or the stale window. A site that goes stale after a healthy write still matches without a second job. `needs_secret` / `unknown` stay out.
- **`app=issues`** is sites with at least one needed fix. The header **Fix App issues** menu links **Sorunlu siteleri gör**. Category counts stay the 60s P0-2 cache.
- Fleet **Tümünü gör** and `+N site daha` on the unhealthy card link the way failed deploys already do. `filter_health` / `filter_app` ride with bulk `all=1`. Unknown values widen. Saved views persist both keys.
- `InspectSiteAppHealthJob` is not inspect-only: `inspect()` / `refreshLocalCached()` and `SiteHealthChecker::check()` call `SiteFilterVerdict` on the same save as the payload.
- Docs: [../modules/ops-sites.md](../modules/ops-sites.md) ("Drill down from the unhealthy card"), [../modules/agent-client.md](../modules/agent-client.md), [../modules/ops-list-async.md](../modules/ops-list-async.md). Tests: new `HealthAppFilterTest` (18). Neighbours: `FailedDeployFilterTest`, `FleetDashboardTest`, `FleetFailedDeployWindowTest`, `SiteListAppHealthCountCostTest`, `SiteHealthEvaluatorTest`.

## Empty-state copy stops leaking test jargon (2026-09-13)

- Status: **code, uncommitted** (overnight ~02:45; worktree left dirty on purpose). Report: [../superpowers/plans/2026-09-13-overnight-morning-report.md](../superpowers/plans/2026-09-13-overnight-morning-report.md).
- Operator empty hints no longer say `Http::fake`, «Liste boş», or «kutu açabilmesi». Themes catalog-empty, Coolify per-kind allowlist empty, Coolify connections empty (Jeton / Ayarlar), and mail-servers registry-empty are operator Turkish. Platform-mail state stays on the two mail pages — not in the nav (P2-19 cost).
- **Left alone then; now landed.** ADR-10 verdict columns + `health=` / `app=` are in the tree (see the Health and app-issue section above). [ADR-11](../decisions/adr-11-dom-test-harness.md) already decided skip Playwright / `node --test` later.
- Docs: [../modules/theme-catalog.md](../modules/theme-catalog.md), [../modules/coolify-client.md](../modules/coolify-client.md). Tests: `ThemeListEmptyStatesTest` (no `Http::fake` in the hint); `MailListEmptyStatesTest` Turkish `posta kutusu`.

## DOM harness first slice: `node --test tests/js/*.js` (2026-09-13)

- Status: **code, uncommitted** (overnight ~02:50; worktree left dirty on purpose). Report: [../superpowers/plans/2026-09-13-overnight-morning-report.md](../superpowers/plans/2026-09-13-overnight-morning-report.md).
- **ADR-11 first slice.** `public/js/ops-contracts.js` is the shared UMD (`PlaneOpsContracts` / `module.exports`). `ops-list.js` uses `toolbarUrl`; `ops-jobs.js` uses `jobStateSignature`, `advanceBackoff`, `jobRowKey`, `diffJobRows`. Tests require that file — no algorithm copy in `tests/js/`. Run: `node --test tests/js/*.js`. No `package.json`, no jsdom, no Playwright.
- Second slice (~02:55) added interpolate + typing / confirm guards — see the entry above. Still later: `document.hidden`. ADR-10 `health=` / `app=` landed separately (`HealthAppFilterTest` 18).
- Docs: [../decisions/adr-11-dom-test-harness.md](../decisions/adr-11-dom-test-harness.md), [../modules/ops-sites.md](../modules/ops-sites.md), [../modules/ops-list-async.md](../modules/ops-list-async.md).

## DOM harness is decided: Node tests, not Playwright (2026-09-13)

- Status: **decision, uncommitted** (overnight tick 5 / ~02:40; worktree left dirty on purpose). Report: [../superpowers/plans/2026-09-13-overnight-morning-report.md](../superpowers/plans/2026-09-13-overnight-morning-report.md).
- **ADR-11.** Pest keeps walking the server-rendered `data-*` / fragment / locale contract. Browser-only behavior (`ops-jobs` backoff, `__COUNT__` interpolate, `ops-list` fetch, palette/shortcuts) lands under `node --test` in `tests/js/`, with jsdom only when a test needs `document`. Playwright, Dusk, Cypress, and Pest Browser are rejected for Plane v1. Do not add a JS bundler. Do not duplicate the algorithms inside the test tree.
- First slice landed later the same night (~02:50) — see the entry above. ADR-10 `health=` / `app=` landed separately (see the Health and app-issue section above).
- **Also:** `SiteListAppHealthCountCostTest` counts-age was a `diffForHumans()` clock race; the test now freezes `Carbon` / `CarbonImmutable`. No product change.
- Docs: [../decisions/adr-11-dom-test-harness.md](../decisions/adr-11-dom-test-harness.md), [../decisions/README.md](../decisions/README.md).

## Fleet attention is searchable; Cloudflare deletes are danger (2026-09-13)

- Status: **code, uncommitted** (overnight wave 4 leftover; worktree left dirty on purpose). Backlog items **P1-14 leftover (fleet dashboard)** and **P2-17 leftover (Cloudflare confirms)**. Report: [../superpowers/plans/2026-09-13-overnight-morning-report.md](../superpowers/plans/2026-09-13-overnight-morning-report.md).
- **P1-14 fleet.** `/` was the last page with no list controls. It is still not a second Sites list. The attention cards now have the Sites toolbar: `q` on name / domain / slug / failed-deploy error, `kind=unhealthy|failed|agent|dockerfile`, async region (`Vary: X-Ops-List-Fragment`). KPIs stay the unfiltered snapshot and are skipped on a fragment request. Search runs before the attention cap so a site in `+N` is findable. Clean-fleet and filtered-miss are different empty states. Pagination is omitted — the cards are already capped. Unknown `kind` is dropped. Agent-secret bulk inject stays on the unfiltered card only (fleet-wide `filter_agent=missing`), so a search cannot shrink that confirm.
- **P2-17 Cloudflare.** Account / zone / live DNS / Deamon-DNS-default deletes now set `data-confirm-danger="true"`. Apply-defaults and reset-to-builtin stay `false`. `ConfirmMatrixTest` walks those pages.
- **ADR-10.** `health=unhealthy` and `app=issues` landed after this tick (persisted verdict + SQL). Do not reintroduce a fleet scan. [adr-10-health-app-filter-verdict.md](../decisions/adr-10-health-app-filter-verdict.md).
- Docs: [../modules/ops-list-async.md](../modules/ops-list-async.md), [../modules/ops-sites.md](../modules/ops-sites.md), [../modules/cloudflare-client.md](../modules/cloudflare-client.md). Tests: new `FleetDashboardListTest` (6); `ConfirmMatrixTest` (7); `OpsListFragmentTest` (7) now covers `/`. Neighbours `FleetDashboardTest` 4, `FleetFailedDeployWindowTest` 6, `AgentSecretFleetTest` 11 green.

## Saved site views restore a triage set without F5 (2026-09-13)

- Status: **code, uncommitted** (overnight wave 4 / tick ~02:15; worktree left dirty on purpose). Backlog item **P1-6**. Report: [../superpowers/plans/2026-09-13-overnight-morning-report.md](../superpowers/plans/2026-09-13-overnight-morning-report.md).
- A chip row under the Sites toolbar offers built-in views (`Tümü`, `Sorunlu`, `Yayında değil`, `Dockerfile kalanları`) plus up to five operator-named presets. Saving captures filters + columns + sort in `users.list_preferences` (DB). One saved view can be the default: bare `/sites` 302s to it; a region request applies it in place.
- **`?view=all` is how you leave a default.** Filtreleri temizle uses that sentinel so clearing does not immediately re-apply the default. A saved view naming a removed channel drops that key. `pack=dockerfile` is the leftover-pack predicate; `filter_pack` rides with bulk `all=1`.
- Chips are plain GET links (`ops-list.js` swaps the region). The save form stays in the toolbar so a search keystroke does not wipe the name. Views are per user; a Viewer may save their own.
- Docs: [../modules/ops-sites.md](../modules/ops-sites.md) ("Saved views"), [../modules/ops-list-async.md](../modules/ops-list-async.md). Tests: new `SiteSavedViewsTest` (14); `SiteListPreferencesTest` (16, reset does not wipe views); `SiteListEmptyStatesTest` clear URL is `view=all`.

## Confirm matrix covers Coolify, mail and Themes (2026-09-13)

- Status: **code, uncommitted** (overnight wave 4 leftover; worktree left dirty on purpose). Backlog item **P2-17 leftover**. Report: [../superpowers/plans/2026-09-13-overnight-morning-report.md](../superpowers/plans/2026-09-13-overnight-morning-report.md).
- Sites already had title / label / explicit danger. The leftover pages did not: Coolify disconnect, mail-server delete, theme-allowlist revoke and theme-git disconnect now set `data-confirm-danger="true"`. Coolify sync / make-default stay `false`.
- `ConfirmMatrixTest` walks those four surfaces the same way it walks Sites. Docs: [../modules/ops-sites.md](../modules/ops-sites.md) ("Confirm matrix"), [../modules/coolify-client.md](../modules/coolify-client.md).

## Agent secret is a fleet answer, not a per-site scavenger hunt (2026-09-13)

- Status: **code, uncommitted** (overnight wave 4; worktree left dirty on purpose). Backlog item **P1-12**. Report: [../superpowers/plans/2026-09-13-overnight-morning-report.md](../superpowers/plans/2026-09-13-overnight-morning-report.md).
- The fleet had no answer to "which sites have no `CONTROL_PLANE_AGENT_SECRET`, and which have one the CMS has never accepted?" — that fact lived only inside each site's App health card. The dashboard now has an **Agent gizli anahtarı** KPI and attention card splitting `yok` / `doğrulanmamış` / `tamam`.
- **One predicate, not two.** `Site::missingAgentSecret` / `unverifiedAgentSecret` / `verifiedAgentSecret` are SQL. Verified means a stored secret plus a CMS 200 (`http_status = 200` or payload `status = ok`). A secret with no 200 is `doğrulanmamış`, never `tamam`. `FleetDashboardKpis` and `/sites?agent=` share those scopes.
- **Bulk inject never rotates.** `POST /sites/bulk/agent-secret` (job `sites.bulk_inject_agent_secret`) writes Coolify env only when the site has none. Existing secrets are `atlandı`. The value is never in the response, widget, audit `after`, or flash. Plane-finished (PATCH env, not a deploy) → **Bitti**. Confirm is danger (P2-17: inject is danger).
- Docs: [../modules/ops-sites.md](../modules/ops-sites.md) ("Agent secret across the fleet"), [../modules/agent-client.md](../modules/agent-client.md) (sixth KPI card), [../runbooks/agent-secret-inject.md](../runbooks/agent-secret-inject.md). Tests: `AgentSecretFleetTest` (11); neighbours `FailedDeployFilterTest` (10), `FleetFailedDeployWindowTest` (6), `FleetDashboardTest` (4), `ConfirmMatrixTest`, `SiteBulkDangerMenuTest`, `SiteBulkSelectionScopeTest` green.

## Activity is the fleet log the jobs widget is not (2026-09-13)

- Status: **code, uncommitted** (overnight wave 4 / tick 4; worktree left dirty on purpose). Backlog item **P1-13**. Report: [../superpowers/plans/2026-09-13-overnight-morning-report.md](../superpowers/plans/2026-09-13-overnight-morning-report.md).
- `GET /jobs` stays JSON-only, `actor = me`, last two hours, 20 rows. `/activity` is a reverse-chronological table merging ops jobs, deployments and audit rows — filterable by search / kind / outcome / actor / site, async region, shared pager. Row click opens the existing deployment show page, a job detail, or a read-only audit detail.
- **Visibility rule.** Every ops role that can see Sites can read the feed, including another operator's jobs. A Viewer still cannot call `GET /jobs`. Payloads are redacted (`SecretRedactor` plus secret/password/token keys) before they leave the server.
- The merge is a SQL `UNION ALL` paginated at 25, not an unpaginated `->get()`. Unknown filter values are dropped. `%`/`_` stay literal.
- Docs: [../modules/ops-activity.md](../modules/ops-activity.md), [../modules/ops-list-async.md](../modules/ops-list-async.md). Tests: new `ActivityFeedTest` (11); `PaletteSearchTest`, `KeyboardShortcutsTest`, `NavPagesTest`, `AuditLogTest` unchanged and green.

## Bulk pin and auto-deploy stop guessing; confirms name danger (2026-09-13)

- Status: **code, uncommitted** (overnight wave 4; worktree left dirty on purpose). Backlog items **P1-15** and **P2-17**. Report: [../superpowers/plans/2026-09-13-overnight-morning-report.md](../superpowers/plans/2026-09-13-overnight-morning-report.md).
- **P1-15.** The bulk pin dropdown was built from the current page of latest deployments, so `all=1` over a multi-page filter offered SHAs that did not cover the selection. Pin is now always a typed SHA/tag; page commits are a `<datalist>` only when the filtered set fits on this page. Auto-deploy is no longer one toggle that resolved mixed → off (and GETs Coolify to decide). **Aç** and **Kapat** are explicit, `enabled` is required, and `toggleEnabledFor()` is gone.
- **P2-17.** Sites list and site detail confirms all carry title, label, and an explicit danger flag. Row App-health confirms are `sites.app_health.confirm_fix` (`:name` / `:label`), not Blade concatenation. Danger is delete / unpublish / stop / secret rotate-or-inject / bulk build.
- Docs: [../modules/ops-sites.md](../modules/ops-sites.md) ("Bulk defaults match the selection", "Confirm matrix"). Tests: `SiteBulkActionsTest` (12), `ConfirmMatrixTest` (3); `SiteBulkSelectionScopeTest`, `SiteBulkDangerMenuTest`, `SiteDetailTest` updated and green.

## Coolify inventory is searchable; Güncellendi is a relative age (2026-09-13)

- Status: **code, uncommitted** (overnight wave 4; worktree left dirty on purpose). Backlog items **P1-14 leftover (Coolify inventory)**, **P2-18 leftover (Coolify)**, and the **P1-10 leftover `updated` column**. Report: [../superpowers/plans/2026-09-13-overnight-morning-report.md](../superpowers/plans/2026-09-13-overnight-morning-report.md).
- **P1-14 / P2-18 Coolify.** The connection show inventory tab was four bare allowlists. Finding one server among many was Ctrl+F, and an empty tab reused a muted "Liste boş" with no next action. The tab now has the Sites toolbar: `q` on name / uuid / IP, `kind` and `status` allowlists, async region (`Vary: X-Ops-List-Fragment`). Defaults still load the unfiltered collections. A fragment request does not persist `applyUnambiguousDefaults()`. Registry-empty offers **Sync** (or `ops.viewer_readonly`); filter-empty names the inventory size, reuses `ops.partials.filter-chips`, and leads with **Filtreleri temizle**. Pagination is omitted — four collections cannot share one pager without becoming a different page. Inventory-show with no linked sites now offers Sync + back, not a title alone.
- **P1-10 leftover.** The optional Sites `updated` column printed `Y-m-d H:i`. It now uses `<x-ops.freshness>` with `markStale=false`: relative age, absolute time in the tooltip, never **Eski**, because `updated_at` is not a poll.
- Docs: [../modules/ops-list-async.md](../modules/ops-list-async.md), [../modules/coolify-client.md](../modules/coolify-client.md), [../modules/ops-sites.md](../modules/ops-sites.md). Tests: new `CoolifyInventoryListTest` (10); `OpsListFragmentTest` now covers `/coolify/{connection}`; `OpsFreshnessTest` / `SiteFreshnessBadgeTest` extended; `CoolifyConnectionsTest` / `CoolifyInventoryDetailTest` unchanged.

## Ctrl/⌘+K jumps the fleet without leaving the keyboard (2026-09-13)

- Status: **code, uncommitted** (overnight wave 3; worktree left dirty on purpose). Backlog item **P1-9**. Report: [../superpowers/plans/2026-09-13-overnight-morning-report.md](../superpowers/plans/2026-09-13-overnight-morning-report.md).
- `/` already focused the list search and `g` then `s`/`d`/`t`/`f` already jumped the four list pages. The missing half was a palette: `Ctrl/⌘+K` now opens a labelled dialog that searches top-level pages plus sites, domains and themes.
- **One search definition, not a second.** `GET /palette?q=` (`ops.palette`) reuses `Site::matchingListFilters`, `SiteDomain::matchingListFilters` and `Theme::matchingListFilters` (Themes list search is now that scope too). An empty query returns only nav pages a Viewer can already open — never `/sites/create` or `/jobs`. Bound domains jump to the site; an unbound host opens `/domains?q=`. Limit 8 per group. `%`/`_` stay literal.
- The script never builds a URL: `g` targets stay on the overlay markup; palette hits come from the JSON. Confirm modal still owns the keyboard; `/` and `g` stay off while typing; `Ctrl/⌘+K` still opens from a field.
- Docs: [../modules/ops-sites.md](../modules/ops-sites.md) ("Keyboard shortcuts and the quick-jump palette"), [../modules/theme-catalog.md](../modules/theme-catalog.md). Tests: `PaletteSearchTest` (9), `KeyboardShortcutsTest` (6); `ThemeCatalogPageTest`, `ThemeListEmptyStatesTest`, `SiteFleetSearchTest`, `OpsListFragmentTest`, `NavPagesTest`, `SiteCrudTest` unchanged and green.

## Mail servers get list controls and two-cause empty states (2026-09-13)

- Status: **code, uncommitted** (overnight wave 3; worktree left dirty on purpose). Backlog items **P1-14 (mail servers)** and **P2-18 (mail servers)**. Report: [../superpowers/plans/2026-09-13-overnight-morning-report.md](../superpowers/plans/2026-09-13-overnight-morning-report.md).
- `/mail-servers` was a bare table. Finding one Hostinger account among many was Ctrl+F, and a filtered miss reused the empty-registry hint. The page now has the Sites toolbar: `q` on name / `mail_domain`, `status=enabled|disabled`, async region (`Vary: X-Ops-List-Fragment`), shared pager. The platform-mail chip stays in the shell so a fragment swap cannot drop P2-19's state.
- Registry-empty offers **Yeni posta sunucusu** (or `ops.viewer_readonly`); filter-empty names the registry size, reuses `ops.partials.filter-chips`, and leads with **Filtreleri temizle**. Coolify inventory still has no list controls — that half of P1-14 is open.
- Docs: [../modules/mail-servers.md](../modules/mail-servers.md), [../modules/ops-list-async.md](../modules/ops-list-async.md). Tests: new `MailListEmptyStatesTest` (7); `OpsListFragmentTest` now covers `/mail-servers`; `MailServerOpsTest` (8) unchanged and green.

## Stale timestamps become a word; Themes empty states name their cause (2026-09-13)

- Status: **code, uncommitted** (overnight wave 3; worktree left dirty on purpose). Backlog items **P1-10** and **P2-18 (Themes)**. Report: [../superpowers/plans/2026-09-13-overnight-morning-report.md](../superpowers/plans/2026-09-13-overnight-morning-report.md).
- **P1-10.** Agent health, live and publish hid freshness in a `title` or printed `Y-m-d H:i`, so a four-hour-old poll looked like a healthy site. `OpsFreshness` + `<x-ops.freshness>` now show a relative age (`4 sa önce`) with the absolute time in the tooltip, and a visible **Eski** word when the age is older than 2× the clamped agent poll window. `null` is `Hiç` / `Bilinmiyor` and never stale. The unhealthy KPI still uses `ops.agent.stale_after_minutes` — that is a different, stronger verdict.
- **P2-18 Themes.** Catalog-empty offers **Katalogu senkronla** (or `ops.viewer_readonly`); filter-empty names the catalog size, reuses `ops.partials.filter-chips`, and leads with **Filtreleri temizle**. Domains empty states were already landed with P1-11.
- Docs: [../modules/ops-sites.md](../modules/ops-sites.md) ("Stale-data badges"), [../modules/theme-catalog.md](../modules/theme-catalog.md) ("Empty states"). Tests: `OpsFreshnessTest` (4), `SiteFreshnessBadgeTest` (4), `ThemeListEmptyStatesTest` (5); `SiteDetailTest`, `SiteListPreferencesTest`, `SitePublishStateTest`, `ThemeCatalogPageTest`, `SiteHealthEvaluatorTest` unchanged and green.

## Domains bulk-bind the unbound, and an empty registry is not a filtered miss (2026-09-13)

- Status: **code, uncommitted** (overnight wave 3; worktree left dirty on purpose). Backlog items **P1-11** and **P2-18 for Domains**. Report: [../superpowers/plans/2026-09-13-overnight-morning-report.md](../superpowers/plans/2026-09-13-overnight-morning-report.md).
- `/domains` had a per-row **Coolify’e bağla** and nothing else, so clearing 30 unbound hosts after a Cloudflare change was 30 clicks. The list now has the Sites bulk bar: checkbox column, `all=1` meaning every host matching the current `q` / `unbound` filters (`SiteDomain::matchingListFilters`), a summary that publishes `$domains->total()`, and a confirm that names the count.
- The sweep (`DomainBindSweep`, job `domains.bulk_bind`) talks to Coolify **once per site**. `SiteLanding::syncCoolifyDomains` writes the whole host list, so two aliases of one site are one PATCH. Already-bound selected hosts are a no-op and never touch Coolify. A 429 is `atlandı (istek sınırı)`, not `hata`; a host with no Coolify app is counted as `unready`, not a throttle skip. `setDomains` is a PATCH, not a rebuild, so the widget reports **Bitti**, not **Tetiklendi**.
- **P2-18 (Domains).** An empty registry and a filtered miss are different states, reusing `ops.partials.filter-chips`. Nothing in the table → inline **Domain ekle** plus **Coolify’dan içe aktar**; a viewer sees `ops.viewer_readonly`. Filters excluded everything → registry size, one removable chip per active filter, **Filtreleri temizle** as the primary action.
- Docs: [../modules/ops-sites.md](../modules/ops-sites.md) ("Fleet domains: empty states and bulk bind"), [../modules/ops-list-async.md](../modules/ops-list-async.md). Tests: new `DomainBulkBindTest` (10), new `DomainListEmptyStatesTest` (6); `TruthfulJobCompletionTest` now pins `domains.bulk_bind` as Plane-finished work; `SiteDomainReconcileTest`, `OpsListFragmentTest`, `SiteBulkSelectionScopeTest` unchanged and green.

## Platform mail is in the nav and says whether it works (2026-09-13)

- Status: **code, uncommitted** (overnight wave 3; worktree left dirty on purpose). Backlog item **P2-19**. Report: [../superpowers/plans/2026-09-13-overnight-morning-report.md](../superpowers/plans/2026-09-13-overnight-morning-report.md).
- The sidebar `Mail` item linked to `/mail-servers` while matching `ops.platform-mail*` for its active state, so the Deamon product SMTP — the sender behind every password reset, site-down and deploy-failed mail — was reachable only through a `btn-ghost` link on another page, and the nav highlighted the wrong entry once you were there. The nav now has a **Posta** group with **Posta sunucuları** and **Yazılım maili** as separate always-visible links; each owns its own active state. No JS, no disclosure to open.
- Both mail pages render one state chip from `PlatformMailState::current()`: `Yapılandırılmadı` / `Etkin · sitelere aktarılmadı` / `Son gönderim başarısız · N site` / `Etkin`, each with a visible sentence beside it rather than a `title` tooltip. The two pages cannot drift because they read the same value object.
- **The failed state needed a fact that did not exist.** A push failure only ever reached `Log::warning`, so "son gönderim başarısız" would have been unprovable. `PlatformMailConfigurer::sync()` — the single choke point for the queued push and the site-detail sync — now stamps `sites.platform_mail_pushed_at` / `platform_mail_push_failed_at` / `platform_mail_push_error`. A success clears the failure, so the chip reports the **last** attempt, not history; sites with no agent secret are never stamped and are not counted. Reasons are short codes (`timeout`, `http_500`, `no_base_url`), never a response body.
- The nav deliberately carries **no** state: the chip costs a settings read plus one `count()`, and paying that on every page render in the layout is the class of cost P0-2 removed from the Sites list.
- Docs: [../modules/platform-mail.md](../modules/platform-mail.md) ("Where it lives in the nav", "State chip"). Tests: new `PlatformMailStateTest` (8); `PlatformMailSettingsTest`, `PlatformMailPushJobTest`, `MailServerOpsTest`, `NavPagesTest` unchanged and green.

## The failed-deploy number is a place you can go (2026-09-13)

- Status: **code, uncommitted** (overnight wave 2; worktree left dirty on purpose). Backlog item **P1-7**, and it closes the two gaps **P0-5** deliberately left open. Report: [../superpowers/plans/2026-09-13-overnight-morning-report.md](../superpowers/plans/2026-09-13-overnight-morning-report.md).
- P0-5 made the failed-deploy card honest but dead: it could not become a link and the `+22 site daha` line had to stay plain text, because no list filter resolved that set. `?status=error` is a **different** set, so linking to it would have been a plausible-looking lie.
- **`deploy=failed`** is now a Sites list filter, alongside `q` / `channel` / `status` / `publish`. The card and the `+N` line link to it, the toolbar offers it as a select, and it produces a removable chip like every other filter.
- **One predicate, not two.** `Deployment::scopeFailedInWindow()` is the single definition of "failed recently" — status `failed` and `COALESCE(finished_at, started_at, created_at)` inside `ops.fleet.failed_deploy_window_hours`. `FleetDashboardKpis` and `Site::matchingListFilters()` both call it, so the card and the page it opens cannot drift apart. The filter uses `whereHas`, not a join, so a site that failed five times is one row — the same per-site counting the card does.
- The option and the chip name the window (`Dağıtımı başarısız · son 24 sa`), because "failed" with no timeframe is the ambiguity P0-5 removed from the card. `filter_deploy` rides along in every bulk form, so `all=1` under this filter stays inside it. Unknown values fall back to the unfiltered list rather than emptying it.
- The unhealthy list's `+N` line stays plain text on purpose: the unhealthy verdict is a PHP evaluation over agent payloads (`SiteHealthEvaluator`), not a SQL predicate, so there is nothing to link to yet without a fleet scan in the filter path — which P0-2 just removed.
- Docs: [../modules/ops-sites.md](../modules/ops-sites.md) ("Drill down from the failed-deploy card"). Tests: new `FailedDeployFilterTest` (10), `FleetFailedDeployWindowTest` (6) unchanged and green.

## A site waiting for the build slot is not a failure (2026-09-13)

- Status: **code, uncommitted** (overnight wave 2; worktree left dirty on purpose). This is the **NEW P0** the wave-1 report escalated. Report: [../superpowers/plans/2026-09-13-overnight-morning-report.md](../superpowers/plans/2026-09-13-overnight-morning-report.md).
- `ops.coolify.deploy.max_concurrent_per_server` is 1, and the gate from `2ccf2ae` counted a bulk sweep's own builds against it: site 1 deployed, its open build then refused site 2, `PacedFanout` retried for `max_site_attempts` short waits and — because a busy gate is not a 429 — counted every remaining site as **`hata`**. A 23-site bulk deploy reported `1 deploy tetiklendi, 22 hata` for sites that were never asked to deploy. The gate's own plan says "bulk path already serial; gate still protects overlapping single-site redeploys", so this was never the intent.
- **The sweep is the queue.** `PacedFanout::run()` wraps the sweep in `CoolifyDeployGate::duringSweep()`, which snapshots the highest `deployments.id` at open; only builds at or below that watermark block a deploy while the sweep runs. What the gate was written for is intact: a foreign build still refuses the whole sweep, and after the sweep closes an unrelated redeploy queues behind the builds it left open. The gate is now a container singleton, like `CoolifyRateGuard`, because the deploy paths resolve it one site at a time.
- **Third outcome bucket.** `waiting` joins `failed` and `skipped`: the site was never sent to Coolify and is unchanged, so it is kept out of `errors` as well as out of `failed`. Copy is its own (`ops.bulk.waiting` → `3 sıra bekliyor`, note `ops.bulk.deploy_busy`), distinct from `hata` and from the rate-limit `atlandı`, and appended as a separate segment because waiting is orthogonal to the other three and clears itself. `deploy_busy` mirrors `rate_limited`; `SiteAppHealthFixer::summarize()` and the app-health flash tone follow.
- Docs: [../modules/ops-sites.md](../modules/ops-sites.md) ("Bulk counting: ok / hata / atlandı / sıra bekliyor", "A sweep does not queue behind its own builds"). Tests: the 4 long-standing `BulkThrottleResilienceTest` failures now pass **unmodified** (8/8), new `BulkDeployWaitingBucketTest` (6), `CoolifyDeployGateTest` (6) unchanged and green.

## Fleet search finds aliases and Coolify uuids; destructive bulk moves behind a menu (2026-09-13)

- Status: **code, uncommitted** (overnight wave 2; worktree left dirty on purpose). Backlog items **P1-8** and **P2-16**. Report: [../superpowers/plans/2026-09-13-overnight-morning-report.md](../superpowers/plans/2026-09-13-overnight-morning-report.md).
- **P1-8.** `Site::scopeMatchingListFilters` searched `name`, `slug`, `primary_domain` only, so pasting a `www.` host, an alias, the temporary preview host or the Coolify app uuid returned "no results" for a site that exists. The search group now also reaches every `site_domains` host through `whereHas('domains')` — an **exists** subquery, so three matching aliases are still one row and the pagination total stays honest — plus an **exact** match on `coolify_app_uuid` (a prefix would drag in every app sharing an id fragment). `addcslashes($search, '%_\\')` still guards the `like` legs; a `%` term stays literal.
- A row matched by something invisible explains itself under the site name: `sites.search_match.alias` (`Aramayla eşleşen adres: www.x.com`) or `.uuid`, from `Site::searchMatchReason()`, which returns `null` when the term is already in the name, slug or primary domain so an ordinary search adds no noise. `domains` is eager-loaded **only** while a search term is present: one extra query when searching, zero when browsing. The placeholder now names the wider reach instead of leaving it undiscoverable.
- **P2-16.** The bulk row was ten flat buttons ending in `btn-danger` **Hard Delete** immediately after **Commite geç**, which with `all=1` put a fleet-wide purge one mis-click from a routine action. **Yayından kaldır** and **Hard Delete** now sit in a separated `Tehlikeli işlemler` disclosure built on the existing `ops-action-menu` primitive (Escape / outside click, `<summary>` works with no JS), inside the bulk form so the buttons stay plain submits with `formaction` and `setupBulkSelection` still finds their `__COUNT__` confirm templates. Nothing was removed and no confirm was weakened; the popover opens upward because the bar ends the page.
- Docs: [../modules/ops-sites.md](../modules/ops-sites.md) ("Search reaches aliases and Coolify uuids", "Destructive bulk actions sit behind a menu"). Tests: new `SiteFleetSearchTest` (10), new `SiteBulkDangerMenuTest` (5); `SiteBulkActionsTest`, `SiteBulkSelectionScopeTest`, `SiteListEmptyStatesTest`, `SiteListAppHealthCountCostTest`, `OpsListFragmentTest`, `SiteCrudTest` unchanged and green.

## A queued deploy says where in line it is, and a forgotten tab stops paying for it (2026-09-13)

- Status: **code, uncommitted** (overnight wave 1; worktree left dirty on purpose). Backlog items **P0-3** and **P0-4**. Report: [../superpowers/plans/2026-09-13-overnight-morning-report.md](../superpowers/plans/2026-09-13-overnight-morning-report.md).
- `ops.coolify.deploy.max_concurrent_per_server` is 1, so a 23-site bulk deploy is one build plus 22 waits — and the widget said only `manuel · kuyrukta` for all 22. The queue state was already known to `OpsCoolifyDeployQueue` and thrown away.
- `queueStanding()` now ranks unfinished deployments per Coolify host (connection, else server uuid, else the site alone — the same grouping `CoolifyDeployGate` counts against) by `created_at` then `id`. Depth is the **whole** host queue, not the widget's 30-row page, so a 200-site sweep cannot report `sırada 4 / 30`. Running builds count toward `running` but never get a position; they already have an elapsed timer.
- Waiting rows read `manuel · kuyrukta · sırada 4 / 22` and the widget header adds `1 derleniyor · 22 kuyrukta`. `GET /jobs` and every cancel / force-start response carry `queue { running, queued, label }`, so promoting or cancelling a row re-ranks everything behind it with no page reload. Copy is translated server-side (`ops.jobs.queue_position` / `queue_building` / `queue_waiting`); `ops-jobs.js` prints the payload and assembles nothing, and it still patches rows rather than rebuilding them.
- **P0-4.** `ops-jobs.js` polled `/jobs` on a flat 1.5s with no `visibilitychange` handling, and every poll is a `GET /deployments` per Coolify connection: one forgotten tab on a ten-minute build was ~400 Coolify requests. Now a hidden tab polls not at all (returning spends exactly one catch-up read), the interval walks `ops.jobs.poll` tiers 1.5s → 5s → 10s after 8 polls that changed nothing, and any real change — status, progress, queue position, or an operator action through `PlaneJobs.track()` — snaps back to the fast tier. `setInterval` is gone; a chained `setTimeout` is what lets the gap move.
- **P0-4 server side.** `OpsCoolifyDeployQueue::runningDeployments()` caches the raw queue payload per connection for `ops.coolify.deploy.queue_cache_seconds` (5), so N tabs and N operators cost one Coolify read per window. Failures are deliberately not cached (`CoolifyRateGuard` owns the cooldown); cancel and force start forget the connection's entry so nobody is handed back the queue they just changed.
- Docs: [../modules/ops-sites.md](../modules/ops-sites.md) ("A queued row says where in line it is", "A poll is a Coolify read, so long waits cost less"). Tests: `DeployQueueStandingTest` (12, includes a Turkish-locale copy check and a 35-deep queue), `JobsPollPressureTest` (5), `DeploymentPollWidgetTest` unchanged and green.
- **Found, not fixed:** the `max_concurrent_per_server` gate from `2ccf2ae` makes `PacedFanout` report every queued site as **`hata`**. See the risk section of the morning report — this is the next P0.

## Bulk bar states its scope, search stops scanning the fleet, KPIs stop counting history (2026-09-13)

- Status: **code, uncommitted** (overnight wave 1; worktree left dirty on purpose). Backlog: [../superpowers/plans/2026-09-12-overnight-plane-backlog.md](../superpowers/plans/2026-09-12-overnight-plane-backlog.md) items **P0-1**, **P0-2**, **P0-5**, plus the Sites half of **P2-18**. Report: [../superpowers/plans/2026-09-13-overnight-morning-report.md](../superpowers/plans/2026-09-13-overnight-morning-report.md).
- **P0-1.** The header checkbox posts `all=1`, which every bulk endpoint resolves to *every site matching the current filters* — and nothing on screen said so, including **Hard delete**, whose confirm read a flat `Seçili siteler`. The bulk bar now publishes `$sites->total()` (`3 site seçildi` / `Filtreye uyan 214 sitenin tümü seçildi`, with scope-switch links), leaves the header box `indeterminate` for partial selections, and interpolates the same count into **every** bulk confirm through `data-confirm-template` / `__COUNT__`. The server-rendered fallback uses the filtered total so a broken `ops-ui.js` over-warns instead of under-warning.
- **P0-2.** `SiteController@index` ran `SiteAppHealthFixer::categoryCounts()` — a `chunkById` walk of the whole `sites` table — on every request, including the region responses `ops-list.js` fires per keystroke, which never render the menu those counts feed. Region requests and non-writers now skip it entirely; page renders read a 60s cache (`ops.app_health.counts_ttl`) and print how old the number is (`sites.app_health.counts_age`); any `fix()` attempt clears the entry.
- **P2-18 (Sites only).** Filtered-empty is now a different state from fleet-empty: fleet size, one removable chip per active filter (`ops.partials.filter-chips`, reusable for Domains / Coolify / Themes), and **Filtreleri temizle** promoted to the primary action. Domains, Coolify, mail servers and Themes still need the same pass.
- **P0-5.** The `failed_deploys` KPI was a lifetime `count()` with no window, so it only ever grew and still counted the throttle storm — while the list under it capped at 8, meaning the number and the list openly disagreed. It is now sites with a failure inside `ops.fleet.failed_deploy_window_hours` (default 24, stated in the label), judged by `COALESCE(finished_at, started_at, created_at)`, counted `distinct site_id`, and the attention list shows the latest failure **per site**. Both attention lists cap at `ops.fleet.attention_limit` (default 8) with `+22 site daha`, the chips keep reporting real totals, and `unhealthySites()` stopped `->get()`ing every column of every row (now memoised, narrow-column). A 60-site / 30-failure fixture renders the dashboard in under 25 queries.
- Docs: [../modules/ops-sites.md](../modules/ops-sites.md), [../modules/coolify-webhooks.md](../modules/coolify-webhooks.md). Tests: `SiteBulkSelectionScopeTest`, `SiteListAppHealthCountCostTest`, `SiteListEmptyStatesTest`, `FleetFailedDeployWindowTest`; `SiteBulkActionsTest` / `SiteDetailTest` updated for the counted confirms. Pre-existing `BulkThrottleResilienceTest` failures (4) are unrelated and reproduce on a clean `HEAD`.

## List controls stop needing F5 (2026-09-12)

- Status: **code**. Saving the Sites column layout wrote `users.list_preferences` and then showed the operator the *old* table: the picker posts over fetch, the controller answered with a `back()` redirect to the page it was already on, and `ops-async.js` had nothing to patch. Both preference routes now answer `{ ok, message, list, columns, refresh_list }` for fetch (plain POST still redirects with a flash), and the table re-renders in place.
- Generalised instead of patched: list URLs answer twice — the full page normally, only `[data-ops-list-region]` when `X-Ops-List-Fragment: region` asks (`App\Support\Lists\ListFragment`, `Vary` + `X-Ops-List-Region` on both). `ops-list.js` intercepts toolbar submits and in-region links that re-query the same path, so **search, filters, sort, paging, clear-filters and the column picker** update without a page load on **Sites**, **Domains** and **Themes**. `replaceState` while typing/filtering, `pushState` for sort and paging, and Back/Forward re-render the region. Coolify inventory, mail servers and fleet have no list controls yet and were left alone.
- Progressive enhancement intact: every control is still a plain link or GET form, and anything that is not a region — sign-in redirect, error page, network failure — hands the URL to the browser. `PlaneUI.refresh()` re-arms the per-element primitives (action menus, favicon marks, bulk selection, hints) after a swap; the toolbar itself is never replaced, so typing is not interrupted. Domains filters (the `unbound` checkbox) auto-apply for the first time, all three lists share `ops.partials.pagination`, and the dead `ops-sites-list.js` copy is gone.
- Docs: [../modules/ops-list-async.md](../modules/ops-list-async.md), [../modules/ops-sites.md](../modules/ops-sites.md). Tests: `OpsListFragmentTest`, `SiteListPreferencesTest`.

## Trigger-only jobs stop claiming "Bitti", Force stops reading as "iptal" (2026-09-12)

- Status: **code**. `Tekrar deploy · 1 site` / `1 tamam · Bitti` was a lie: the Plane job that *asked* Coolify for a deploy had finished, the build had not. Sweeps that only enqueue Coolify work (`sites.bulk_deploy`, `sites.bulk_follow_head`, `sites.bulk_pin`, `sites.bulk_channel`, and `sites.bulk_app_health_fix` when the fix set ends in a redeploy) now report `1 deploy tetiklendi — Coolify derlemesi henüz bitmedi; «Coolify deploy» satırlarından izlenir.` with status **Tetiklendi**. `BulkResultSummary::formatTriggered()` covers the widget message and the no-JS flash; `OpsBackgroundJob::triggersRemoteWork()` is the one list; app-health sweeps decide from `result.deploy_triggered` (fixes the targets actually needed), not from the requested fix. Work Plane really finishes (live sync, Coolify/inventory/catalog sync, compose, auto-deploy, publish state) keeps `tamam` / `Bitti`; `hata` and `atlandı (istek sınırı)` wording is untouched.
- Force start no longer reports a cancellation. Dropping the Coolify queue slot is Plane mechanics, so `forceStart()` keeps **one** row: the same `deployments` row is repointed at the instant-started uuid (`in_progress`, fresh `started_at`, cleared `error_message`, `site.deploy_force_started` audit with old + new uuid), and the widget patches `kuyrukta` → `çalışıyor` in place. No `Coolify deploy · izyem.com` / `Coolify deployment cancelled. · iptal` ghost. That English string was `CoolifyDeploymentSync`'s hard-coded fallback; cancelled/failed rows without a Coolify message now use `ops.deploy_failure.*`. A half-way force (queue slot gone, Coolify refused) says `ops.jobs.force_start_aborted`; a webhook that already owns the new uuid wins and the forced row retires with `ops.jobs.force_start_replaced`.
- Docs: [../modules/ops-sites.md](../modules/ops-sites.md). Tests: `TruthfulJobCompletionTest`, `DeploymentPollWidgetTest`, `BulkThrottleResilienceTest`.

## Site publish state + operator-owned sites list (2026-09-12)

- Status: **code**, both repos. Publish stays **CMS-owned**: the CMS `sites.status` (`draft` | `published`) that already drives its maintenance page is the only publish model, and Plane's `sites.status` remains the Coolify lifecycle. Plane mirrors the CMS value into `sites.cms_site_status` / `cms_site_status_at` so the list can sort and filter on a real column, and writes only through the signed agent (`SitePublishStateUpdater`  CMS `POST /internal/control/v1/site/status`, new in CMS 1.2.16). The mirror records what the CMS **echoed**, never what Plane asked for; a failed call writes neither mirror nor audit; an unreachable poll leaves a known state alone. `null` is **Bilinmiyor**, not `draft`. Yay�n chip + filter on the list, a Yay�n durumu card on site detail, and Yay�na al / Yay�ndan kald�r bulk actions (`sites.bulk_publish_status`).
- Fixed along the way: `AgentHealthResult::fromCmsPayload` never put `site_status` in the summary, so the field `SiteHealthChecker` allowlisted could never arrive. `ops-jobs.js` gated `applyAppHealthResults` behind `job.type === "sites.live_sync"` while the function itself required `sites.bulk_app_health_fix`, so it was dead code; the gate is now "just finished" and each applier checks its own type.
- Sites list columns and sorting are now the operator's: `SiteListColumns` catalog + `SiteListView`, layout and last sort persisted per user in `users.list_preferences` JSON (**DB**, not localStorage). `site` is locked. Sorting is real `?sort=&dir=` anchors with `aria-sort`, works without JS, keeps filters, resets `page`, ties break on `name`. A stored sort on a column the operator hid falls back to the default column *and* direction.
- Docs: [../modules/ops-sites.md](../modules/ops-sites.md), [../modules/agent-client.md](../modules/agent-client.md), [../superpowers/specs/2026-09-12-site-publish-state-and-list-preferences-design.md](../superpowers/specs/2026-09-12-site-publish-state-and-list-preferences-design.md). Tests: `SitePublishStateTest`, `SiteListPreferencesTest` (Plane), `ControlPlaneSiteStatusTest` (CMS).

## Jobs widget says what it is, and stops rebuilding itself (2026-09-12)

- Status: **code**. Every widget row now names its kind before anything else: `kind_label` / `subject` / `status_label` / `detail` ship in `OpsBackgroundJob::toWidget`, `Deployment::toWidget` and `OpsCoolifyDeployQueue::remoteWidget`, so a deploy reads `Coolify deploy · beyazlar` + `manuel · kuyrukta` instead of a bare site name over `manuel · kuyrukta`. Bulk sweeps carry a site count (`App hatalarını düzelt · 3 site`); single-target jobs carry `payload.subject`. Coolify queue rows Plane did not start say `Coolify kuyruğu`, not a made-up trigger.
- `ops-jobs.js` patches rows keyed by `data-job-id` instead of `replaceChildren()` on every 1.5s poll, which is what made cancel / force-start / dismiss unclickable: the button was replaced between mousedown and click. Listeners bind once per row and read the current payload from row state; focus is restored if a reorder moves a focused node; the indeterminate bar no longer restarts its animation each tick. Docs: [../modules/ops-sites.md](../modules/ops-sites.md). Tests: `DeploymentPollWidgetTest`, `SiteAppHealthTest`.

## Coolify deploy-row heal — generic API-failure race (2026-09-12)

- Status: **code**. `ops:heal-throttled-deploys` markers now cover every sentence Plane writes about a *status read* rather than a build: the bare `Coolify API request failed.` a raced read leaves behind (empty body mid cancel/restart, so the row lands `failed` while Coolify reports `cancelled-by-user`) and `Timed out waiting for Coolify deployment.` from an exhausted poll. When Coolify still says `failed` and carries no message or logs, the row's original text and `finished_at` are restored instead of the generic `Coolify deployment failed.`. Runbook: [../runbooks/coolify-rate-limit.md](../runbooks/coolify-rate-limit.md). Tests: `HealThrottledDeploysCommandTest`.
- Live: the four rows left from the 2026-09-11 poll storm (izyem #737, Lökçe #738, ltscanta #739, makermak #740) are `cancelled` from Coolify truth, and ltscanta #721 is `finished`. Older `failed` rows on other sites are real build failures, not this race.

## CMS runtime image has no `git` — control-plane theme git is dead on live (2026-09-12)

- Status: **blocker, CMS repo**. `themes/install` → `502 git_failed` and `themes/update` → `404 theme_not_found` on izyem because the CMS runtime image (`php:8.2-fpm-bookworm`, CMS `Dockerfile`) never installs `git`; `ProcessThemeGitRunner` shells out to `git clone`. `themes/.control-plane-git/` is empty on the site and `storage/app/theme-data/` does not exist, so CMS 1.2.14 `themes/data-install` has no source and answers `422 data_package_missing` — the Sync self-heal cannot fire. izyem's live `themes/izyem` carries theme files only (uploaded 2026-09-11 19:40), which is why Sync has always reported `sync.json tanımlı değil`. Fix is a CMS `Dockerfile` change plus a rebuild; not a Plane bug. Plane's error text is accurate and was left in place.

## Theme data package rollout fix (2026-09-11)

- Status: **code**. CMS `themes/install|update` now publish the SoT repo-root data package (`sync.json` + `data/`) into `storage/app/theme-data/{id}` (CMS **1.2.14**), so Sync after Activate works without SSH. New `POST /internal/control/v1/themes/data-install` repairs sites installed by an older agent; `themes/sync` preflights and returns `data_package_missing`. `ThemeRolloutService` retries sync once through data-install (audit `theme.data_installed`). Merge mode no longer unpublishes rows the package stopped shipping. Docs: [modules/theme-agent-client.md](../modules/theme-agent-client.md). Tests: Plane `ThemeAssignTest`; CMS `ControlPlaneThemeDataPackageTest`, `ThemeSyncMergeContractTest`.
- Follow-up (2026-09-12): **Sync now** self-heal is now covered end to end, and two holes on that path are closed — a healed sync clears `last_error` / lifts `error` status, and a failed repair surfaces the CMS data-package message instead of the misleading "CMS older than 1.2.5". `ControlPlaneAgentSignatureTest` asserts a `CMS_VERSION` floor of 1.2.14 rather than an exact pin that broke on every CMS release.

## Site admin management (2026-09-11)

- Status: **code**. Site detail **Admins** tab: list/create/reset (operator); deactivate/delete (Super Admin). HMAC `/internal/control/v1/admins*` (CMS **1.2.13+**). Passwords generate-or-manual, one-time flash only, never stored. Last active admin guarded. Spec: [../superpowers/specs/2026-09-11-site-admin-management-design.md](../superpowers/specs/2026-09-11-site-admin-management-design.md). Module: [modules/site-admins.md](../modules/site-admins.md). Tests: Plane `SiteAdminAgentTest`; CMS `ControlPlaneAdminAgentTest`.

## Software mail (Plane SMTP) — 2026-09-11

- Status: **code**. Global `/platform-mail` SMTP + notification catalog; site Infrastructure overrides; push `POST /internal/control/v1/platform-mail/configure`. Plane sends site down/up/version/deploy-failed. CMS sends password reset, admin welcome, weekly report, member/order, publish toggle. Hostinger mailboxes unchanged. Docs: [modules/platform-mail.md](../modules/platform-mail.md).
- Follow-up (2026-09-12): save/push flashes immediately; site configure via `DispatchPlatformMailPushJob`; `POST /platform-mail/test`; signed ops unsubscribe on Plane + `users.mail_notification_opt_outs`. Spec: [../superpowers/specs/2026-09-12-platform-mail-feedback-prefs-unsubscribe-design.md](../superpowers/specs/2026-09-12-platform-mail-feedback-prefs-unsubscribe-design.md) (Plane slice **code**; CMS prefs/unsubscribe companion pending).

## Site mailbox bindings + CMS requests (2026-09-10)

- Status: **code**. Infrastructure mail card is mailbox-domain selects (existing values pre-selected), not “mail order” auto-match only. `site_mail_bindings` holds many domains; `site_mailbox_requests` is the CMS queue. Configure sends `mail_domains` (CMS `MailConfigureController` validates the array). Proxy lists all bindings; create accepts `domain`. Tests: Plane `SiteMailAssignTest` / `SiteMailProxyTest` / `MailServerOpsTest`; CMS `ControlPlaneMailConfigureTest` / `HostingerMailAdminTest` / `HostingerMailCustomerTest`.

## Sites App health + Coolify job widget (2026-09-10)

- Status: **code**. Sites list **App** column (Healthy / N issues, hover + copy). Detail card with AJAX fixes (env sync, compose migrate, agent secret, redeploy, agent check). Jobs widget polls fleet Coolify `deployments` plus recent ops jobs. Manual redeploy/pin/HEAD now insert a local deployment row. Tests: `SiteAppHealthTest`.

## Bulk App health fixes (2026-09-11)

- Status: **code**. Sites list row + header Fix App issues menus; `fix=all`; bulk job `sites.bulk_app_health_fix`. Spec: [../superpowers/specs/2026-09-11-bulk-app-health-fixes-design.md](../superpowers/specs/2026-09-11-bulk-app-health-fixes-design.md).

## Deploy status App health sync + auto-deploy verify (2026-09-11)

- Status: **code**. `forDisplay` merges local deploy/agent/secret into cached inspect; Sync/poll refresh local cache; latest deploy by `started_at`; auto-deploy verifies Coolify `is_auto_deploy_enabled` after PATCH. Spec: [../superpowers/specs/2026-09-11-deploy-status-app-health-sync-design.md](../superpowers/specs/2026-09-11-deploy-status-app-health-sync-design.md).

## Domain registry + Coolify binding (2026-09-11)

- Status: **code**. `/domains` fleet UI; Coolify Sync import + auto-rebind; App health `domain_unbound` + `bind_domains`. Spec: [../superpowers/specs/2026-09-11-domain-registry-coolify-binding-design.md](../superpowers/specs/2026-09-11-domain-registry-coolify-binding-design.md).

## Theme Git connections — Settings paste → Themes Manifest (2026-09-10)

- Status: **code**. Themes hosts Connect GitHub (Manifest), Connect another (same App), PAT fallback, all vs selected, Sync repos, Disconnect (App stays). Settings GitHub paste removed (one-line pointer; leftover POSTs 302 to Themes). Catalog walks every `theme_git_connections` row. CMS **1.2.7** theme-git `repo` allowlist **landed** (any github.com owner); do not re-edit `deamon` for that contract. Plane `ControlPlaneAgentContract::CMS_VERSION` is **1.2.7**. Connect / connect-another / manifest forms are `data-ops-native` so `ops-async.js` does not swallow the GitHub redirect.
- Product: Coolify-style GitHub App Manifest (one Plane App, many user/org installations). Connections + all/selected picker under **Themes**. Settings GitHub paste removed (one-line pointer). Advanced PAT for local/no public URL. Coolify `/github-apps` UUID never reused. ZIP still out. First sync `private`. `clone_token` = installation token only.
- Spec: [../superpowers/specs/2026-09-10-theme-git-connections-design.md](../superpowers/specs/2026-09-10-theme-git-connections-design.md)
- Plan: [2026-09-10-theme-git-connections.md](2026-09-10-theme-git-connections.md)
- Deferred (not blockers): install callback does not auto-refresh the repo picker (operator clicks **Sync repos**); Disconnect does not delete the GitHub App.

## Coolify env default catalogs (2026-09-10)

- Status: **code**. Settings holds per-pack catalogs (`dockerfile` / `dockercompose`) on `coolify_env_defaults` (seeded). Normal view: static / required / generated / site / Coolify-injects. Developer view is an open KEY=value dump. `CoolifyAppEnvSync` runs on every Plane `deploy()` and after Dockerfile → compose. Empty/placeholder MySQL secrets are filled; filled secrets are not rotated; compose `DB_HOST` is forced to `mysql`. Tests: CoolifyAppEnvSyncTest, SettingsEnvDefaultsTest (`Http::fake`).

## Landing pause + multi-domain / www (2026-09-10)

- Status: **code**. Create/edit `aliases[]` on the same apex; every host gets a `www` sibling on `site_domains`, Cloudflare A records, and Coolify `app` (`https://host,https://www.host`). Pending Cloudflare zone: provision still creates the app on a temporary `{adj}-{noun}.{wildcard}` host; site detail warns and offers **I updated DNS**. Confirm while pending keeps temp; confirm when active binds customer hosts and deletes the temp row. Detail can add another same-apex host. Tests: `SiteLandingFlowTest`, `CloudflareHostnameTest`, updated `ProvisionSiteTest` / `ProvisionSiteCloudflareTest`.

## Europe/Istanbul (+03:00) everywhere (2026-09-10)

- Status: **code**. `APP_TIMEZONE=Europe/Istanbul`, MySQL session/server `+03:00`, container `TZ` on app/MySQL/Redis, PHP `date.timezone`. TIMESTAMP rows are not +3-updated (would double-shift). DATETIME columns shift +3 once in `2026_09_10_160000_shift_mysql_datetime_columns_to_istanbul`. Tests: AppTimezoneTest, MysqlWallClockShiftTest.

## Site detail + list deploy actions (2026-09-10)

- Status: **code**. Site show **Deploy** menu: Redeploy (`POST /deploy?force=true`), Deploy HEAD (follow HEAD), Open in Coolify. Infrastructure auto-deploy card always shows commit pin, update-to-latest, redeploy, follow HEAD. Sites list selection: Redeploy, Deploy HEAD, pin SHA. Tests: CoolifyDeploySettingsTest, SiteBulkActionsTest (`Http::fake`). No Coolify DELETE except hard purge.

## Site Cloudflare account + Free zone NS (2026-09-10)

- Status: **code**. `sites.cloudflare_setting_id` persists the selected account. Create/edit has an account select. Site detail Infrastructure card: account + **Add to Cloudflare (Free)** via AJAX (`POST /sites/{site}/cloudflare/zone`) returns copyable NS without reload. Unbound customer hostnames create a Free full zone on the apex instead of `{adjective}-{noun}.codron.co`. Provision uses the site account when set. Tests: `SiteCloudflareZoneTest`, `ProvisionSiteCloudflareTest`.

## Site detail action menus + Coolify start/stop/hard delete (2026-09-10)

- Status: **code**. Site show topbar is three menus: Sync (Coolify / Live Site / health), Site (homepage / admin), Settings (edit / activate-or-deactivate / soft delete / hard delete). Activate = Coolify `POST .../start` + status `stopped`→`active`. Deactivate = `POST .../stop` + `active`→`stopped`. Soft delete unchanged. Hard delete is the only Coolify `DELETE ?delete_volumes=true`, then `forceDelete`; also on `/sites` bulk. Tests: SiteLifecycleTest, CoolifyClientTest (`Http::fake`).

## Site detail copy in i-hints (2026-09-10)

## Dalga 0 — Discovery / Spike

- Status: **complete — Go**
- OpenAPI + live proof: `docs/plans/2026-08-13-coolify-spike-notes.md`
- Spike script: `tools/coolify-spike/` (read-only then `-Mutate` on Susa DEMO only)
- Staging app: **Susa** `crxguq6nodorlzy88wf9x305` (`codron-co/deamon`, compose, left on **beta**)
- Defaults: project Deamon `z8ocg8k04ww8osssccc088c0`; server localhost `no48ksggg0k8sk4o4w08gks8`
- Go/Hybrid/No-Go: **Go** (list + updateBranch + deploy + getDeployment + volume OK; setDomains OpenAPI + skip live domain PATCH; createComposeApp skipped)
- Coolify Compose sözleşmesi (Dalga 0): `docker-compose.coolify.yml` + `Dockerfile` + `docker/` — Laravel Task 0 scaffold present; first Deploy unblocked
- Laravel create-project: **done** (Task 0 bootstrap — merged non-destructively)
- CMS code in this repo: none
- Mailcow: out of scope
- Commit: `7d6d738 v0` plus this worktree (Dalga 0 notes + Task 0–7 uncommitted)

## Dalga 2 — Coolify Fleet

- Status: **complete** (Tasks 2–7; Http::fake suite **114 passed**)
- API mode: **hybrid** — compose create/branch/deploy/poll/import are automated; Coolify Notifications webhook POSTs are **unsigned**. Plane accepts query `token` matching the webhook signing secret. HMAC (`X-Coolify-Signature`) remains preferred when a signer exists. `PollDeploymentJob` remains the backup.
- Commit: not requested

## Dalga 3 — Agent Health

- Status: **both tracks done**
- Task 8 (CMS `codron-co/deamon`, **v1.1.43**): `/internal/control/v1/health` + HMAC; contract `docs/modules/control-plane-agent.md` in the CMS repo
- Task 9 (this repo): `SiteAgentClient` + jobs/UI; **headers locked to CMS**
- Integration: outgoing Plane requests use `X-Deamon-Timestamp`, `X-Deamon-Nonce`, `X-Deamon-Signature`; canonical `{timestamp}.{nonce}.{rawBody}`; GET body `""`
- Live smoke: later (staging site with injected secret)
- Secret inject: still manual — [agent-secret-inject.md](../runbooks/agent-secret-inject.md)
- Docs: `docs/modules/agent-client.md`
- Commit: not requested

## Dalga 4 — Themes (overnight closer)

- Status: **Plane track complete** (Tasks 10, 12, 13). Task 11 remains CMS repo.
- ZIP in Plane UI: **yok** (asserted: no `type=file` / ZipArchive / `name=zip`)
- Auto-update default: **off**
- Theme agent (historical overnight lock): **CMS v1.2.5** headers/paths/bodies. **Current SoT: CMS 1.2.7** — same HMAC/paths; `repo` now any github.com owner (see [theme-agent-client.md](../modules/theme-agent-client.md)). Paths `/internal/control/v1/themes` + install/update/activate/sync. HMAC `X-Deamon-*`. Install body includes `source=git`. Sync body `{action, mode, theme_id}`. Assign = install → activate → sync as separate POSTs.
- HMAC: same `X-Deamon-*` helper as Task 9. Coolify webhook secret is separate.
- Suite after historical CMS 1.2.5 lock: **163 passed** (805 assertions). Pint `--dirty` clean.
- Commit: not requested

```txt
## Dalga 4 raporu — Deamon Plane
Subagents: closer implemented CATALOG / ASSIGN / WEBHOOKS in-repo (CMS-THEME is the other repo)
CMS handoff Task 11: **done** (historical: deamon v1.2.5 headers/paths; **current: v1.2.7** any github.com `repo`) — Plane Task 12 HMAC/paths/bodies unchanged
ZIP in plane: yok (doğrulandı)
Auto-update default: off
Sonraki: Dalga 5 (done in same overnight pass)
```

## Dalga 5 — Harden + Deploy

- Status: **code + docs complete; live Plane deploy skipped (by design)**
- SECURITY: audit on theme mutations; `OPS_IP_ALLOWLIST`; `ProductionDebugGuard`; webhook throttles; `SecretRedactor`; confirm modals; [security.md](../security.md) checklist closed
- PROD-DEPLOY: compose contract unchanged; [deploy-plane.md](../runbooks/deploy-plane.md) is operator-complete. **No PATCH/deploy** of Plane or customer apps.
- Read-only Coolify GET `d6ovbjzxgpao23faam3vrcve` (2026-09-09): name **Deamon Plane**, `running:healthy`, repo `codron-co/deamon-plane`, branch **alpha**, `build_pack=dockercompose`, compose `/docker-compose.coolify.yml`, **fqdn and docker_compose_domains null**. Did not mutate. Susa not touched.
- Staging smoke: **blocked** until operator binds a Plane domain and deploys the branch that contains this overnight work (live app is still `alpha`).
- Faz A: **met** (fake/test). Faz B: **met** in Plane (CMS Task 11 parallel). Faz C: **partial** (docs + artifacts; live deploy/smoke leftover).

```txt
## Dalga 5 raporu — Deamon Plane
SECURITY: closed (tests + runbooks + IP allowlist + APP_DEBUG gate)
PROD-DEPLOY: artifacts ready; live Coolify mutate SKIPPED
Faz A/B/C: met / met (Plane) / partial (live deploy + import apply leftover)
Open follow-ups: Coolify UI domain + deploy current git; GitHub App/PAT; agent secret inject; import dry-run→apply
Subagent-driven: overnight closer (this track) — CMS Task 11 separate
```

## Task 0–15

| Task | Role | Status |
|------|------|--------|
| Spike | Dalga 0 | **complete — Go** |
| 0 bootstrap | BOOTSTRAP | **done** |
| 1 schema | SCHEMA | **done** |
| 2 Coolify client | COOLIFY-CLIENT | **done** |
| 3 site CRUD | UI-SITES | **done** |
| 4 provision | PROVISION | **done** |
| 5 channel | CHANNEL | **done** |
| 6 webhooks | WEBHOOKS-DEPLOY | **done** |
| 7 import | IMPORT | **done** |
| 8 CMS health | CMS-AGENT-HEALTH (deamon) | **done** (separate repo; health since v1.1.43) |
| 9 plane agent client | PLANE-AGENT-CLIENT | **done** |
| 10 theme catalog | THEME-CATALOG | **done** (2026-09-10): Themes Manifest + `theme_git_connections`; Settings paste retired (historical Task 10 was org+PAT/PEM on Settings) |
| 11 CMS theme agent | CMS-THEME-AGENT (deamon) | **done** (CMS **v1.2.7** theme git `repo` = any github.com owner; HMAC/paths from v1.2.5 lock) |
| 12 assign | THEME-ASSIGN | **done** (headers/paths/bodies locked; install→activate→sync separate; CMS **1.2.7** accepts any github.com `repo`) |
| 13 GH webhooks | THEME-WEBHOOKS | **done** (`POST /webhooks/github`, distinct secret, fan-out + semver skip) |
| 14 security | SECURITY | **done** (checklist closed in `docs/security.md`) |
| 15 prod deploy plane | PROD-DEPLOY | **partial** — runbook complete; **live deploy skipped**; read-only status recorded above |

## Settings: Coolify panel removed (2026-09-10)

- Settings (`/settings`) is customer / Coolify env defaults. Theme GitHub connect lives under **Themes** (Manifest; code shipped 2026-09-10). Coolify token / webhook / UUID / Test connection live under **Coolify** (`/coolify`).
- Leftover `POST /settings` and `POST /settings/coolify/test` redirect to Coolify connections.

## Coolify menu (ops — 2026-09-10)

- Status: **code complete** (this worktree). Multi-connection + server aktif/pasif + project/env/git allowlists + site `<select>` + attach existing + preflight + 422 field errors + agent secret inject.
- Nav: **Coolify**. Default connection for new sites. Super Admin advanced UUID paste (collapsed, warned). Compose path fixed `/docker-compose.coolify.yml`.
- GitHub Apps: `GET /github-apps` (v4.x). 404 → hybrid UI (deploy keys + advanced paste). Not the Plane theme-catalog GitHub App.
- Defaults: option **name** only (uuid = value/title). Single active server/project/env auto-persisted. Env dropdown is **project-scoped** (`project_uuid`), not a global dump.
- Servers: Sync stores Coolify `ip` (prefer `public_ip`/`public`). Connection server table has an IP column.

## Hybrid leftovers (operator on wake)

- Coolify Notifications remain unsigned — paste `https://{plane}/webhooks/coolify?token=<webhook signing secret>`. HMAC still preferred if a signer exists. Poll remains backup.
- Agent secret inject is in the site UI (`Generate & inject secret`) when Coolify env API works; Coolify UI leftover if that PATCH fails.
- Theme GitHub: **Settings paste → Themes Manifest** (code shipped 2026-09-10). Connect user/org under Themes; do not paste PAT/PEM on Settings; do not reuse Coolify `/github-apps` UUID. Theme catalog ≠ Coolify Git source. Coolify API token lives under **Coolify** (`/coolify`), not Settings. Deferred: Sync repos after install; App stays on last disconnect.
- CMS Task 11 is live at **v1.2.7** (any github.com owner/name for `source=git`). Site still needs `CONTROL_PLANE_AGENT_SECRET` injected before theme assign 200s. Historical Task 11 ship was v1.2.5 (headers/paths only).
- Plane app `d6ovbjzxgpao23faam3vrcve` domain **https://plane.codron.co**. Deploy that uuid only. Never touch Susa `crxguq6nodorlzy88wf9x305`.

## Open in Coolify — environment uuid (2026-09-10)

- Status: **ship**. Deep link is `{base}/project/{project_uuid}/environment/{environment_uuid}/application/{app_uuid}`. Site uuids first, then connection defaults. Environment **name** or git channel (`alpha`) is never a path segment.

## Slice 7 — Hostinger mail servers (2026-09-10)

- Status: **code**. `mail_servers` hold Hostinger API tokens only. Each site matches `GET /api/mail/v1/orders?domain=` against `sites.primary_domain` (exact). No server-wide order picker. Hostinger Mail cannot create orders. Assign/provision → bind then signed CMS configure (no token). Tests: MailServerOpsTest, SiteMailAssignTest, SiteMailProxyTest. Docs: [modules/mail-servers.md](../modules/mail-servers.md).

## Slice 6 — dockerfile → compose + auto-deploy + pin (2026-09-10)

- Status: **code**. PATCH existing Coolify app to `dockercompose` + `/docker-compose.coolify.yml` **and** `docker_compose_domains` (from live compose domains, else `fqdn`, else `sites.primary_domain`). Omitting domains wipes the Coolify proxy. No DELETE. `listEnvs` snapshot restores `APP_KEY`/`APP_URL`/`DEAMON_*` via Coolify 4.3 `/envs` `{ key, value, is_literal }` (not `is_literally` / `available_in_services` / env `uuid`); `DB_*` not copied. Recreate → abort. Env restore API errors are flash, not 500. Auto-deploy `is_auto_deploy_enabled` (Coolify rejects `is_auto_deploy`). Pin SHA/tag + auto-deploy off; Follow HEAD sends `git_commit_sha: "HEAD"` (empty string is invalid on Coolify 4.x) + auto-deploy on + branch deploy. Channel switch uses the same `HEAD` SHA then `POST /deploy`. Bulk selected + all Dockerfile, confirm on dangerous actions.

## Site Coolify / theme / confirm parity (2026-09-10)

- Status: **code**. Auto-deploy chip is On / Off / Unknown. GET reads `settings.is_auto_deploy` as well as `is_auto_deploy_enabled`; a missing flag is not shown as Off. Theme tab / overview / list use health `active_theme_id` when Plane has no installation row. Agent secret: generate only when missing; rotate icon when set. Mutating site ops and Coolify connection Sync / Make default use `PlaneConfirm` (`data-confirm` on form or submitter). Mail assign/refresh confirm only — mail backend unchanged.

## Site Coolify sync + deployments pull (2026-09-10)

- Status: **code**. Site detail **Sync Coolify** (`POST /sites/{site}/sync`) GETs the Coolify app (project/env/server/git/channel) and last 25 `GET /deployments/applications/{uuid}` rows. Upsert by `coolify_deployment_uuid`; new rows `trigger=manual`. Does not write secrets or flip `sites.status` from historical failures. Connection inventory Sync does the same deployment pull per site. GET `/sites/{site}/sync` is a 302. Tests: CoolifySiteSyncTest, CoolifyDeploySettingsTest (`Http::fake`).

## Sites list bulk selection (2026-09-10)

- Status: **code**. Header checkbox selects every site matching current filters (`all=1`), not the page. Bulk actions show only when a selection exists: Change branch, Switch to Compose (Dockerfile leftovers only), Auto-deploy on/off (all on → off; all off → on; mixed → off). Channel switch writes `APP_ENV` / `DEAMON_CHANNEL` and `git_branch` + `git_commit_sha: HEAD`. Coolify application PATCH must not send `environment_uuid` (API rejects it). Tests: SiteBulkActionsTest, ChannelSwitchTest.

## Background jobs widget + AJAX ops (2026-09-10)

- Status: **code**. Syncs and list bulk (Coolify / Live / inventory / theme catalog / branch / compose / auto-deploy) queue `OpsBackgroundJob` on JSON and return immediately. HTML POST still redirects and runs inline. Other mutating ops stay in-request but return JSON instead of a reload (`ConvertOpsAjaxRedirect`). Bottom-right widget (`data-ops-jobs`) shows queue/progress/result; Live chips update from `result.sites`. Tests: OpsBackgroundJobTest (`Http::fake`).

## Sites list Sync + Live Sync (2026-09-10)

- Status: **code**. Identity marks use UTF-8 `IdentityMark` (Turkish `İzyem` → `İ`, not a replacement character). List topbar **Sync Coolify** (`POST /sites/bulk/sync`) and **Live Sync** (`POST /sites/bulk/live-sync`). Live column stores homepage status + favicon href. GET variants 302, no outbound HTTP. Viewer forbidden. Tests: IdentityMarkTest, SiteLiveProbeTest, SiteLiveSyncTest (`Http::fake`).

## Slice 5 — sync fills site Coolify targets (2026-09-10)

- Status: **ship**. `CoolifyInventorySync` still fills servers/projects/envs/git, then `CoolifySiteTargetSync` GETs each site’s Coolify app and writes project / env / server / git / allowlisted channel. Secrets and `status` unchanged. `develop` → `channel_needs_review` (channel kept). App 404 skips the site. Inventory `is_active` still not zeroed. Flash: `:sites` filled.

## Slice 3 — deploy show + copyable Coolify error (2026-09-10)

- Status: **ship**. Site Deployments rows open `GET /sites/{site}/deployments/{deployment}`. Poll + webhook failures store Coolify `message` + `errors` JSON and truncated redacted logs (`error_message` / `log_excerpt`). Copyable `<pre>` report. GET `/coolify/{id}/sync` stays a 302 to show (POST Sync button); not 405.

## Slice 2 — compose create without `fqdn` (2026-09-10)

- Status: **ship**. Compose create/PATCH send only `docker_compose_domains` (`app` + `https://{operator-host}`). No `fqdn` field (Coolify: `This field is not allowed.`). `CoolifyApplication::primaryDomain()` prefers operator host over `{uuid}.demo.codron.co` / `.random.codron.co`. Plane does not promote generate-domain to `site_domains` primary. Retry provision if app uuid exists — do not recreate.

## Site detail action menus + Coolify start/stop/hard delete (2026-09-10)

- Status: **code**. Site show topbar is three menus: Sync (Coolify / Live Site / health), Site (homepage / admin), Settings (edit / activate-or-deactivate / soft delete / hard delete). Activate = Coolify `POST .../start` + status `stopped`→`active`. Deactivate = `POST .../stop` + `active`→`stopped`. Soft delete unchanged. Hard delete is the only Coolify `DELETE ?delete_volumes=true`, then `forceDelete`; also on `/sites` bulk. Tests: SiteLifecycleTest, CoolifyClientTest (`Http::fake`).

## Site detail copy in i-hints (2026-09-10)

- Status: **code**. Site show keeps operational facts and actions; explanatory ledes live in `ops.dashboard._hint`. Decorative kickers, the duplicate Live release card, and “installed by agent” chips are gone. Idle “everything looks good” card and duplicate hero Open links are gone. Tests: SiteDetailTest, ChannelSwitchTest.

## Coolify environment name = main (2026-09-10)

- Status: **code + live Deamon env**. Default Coolify project environment name is `main` (1:1 with git channel / `sites.channel`). `config/ops.php` `COOLIFY_ENVIRONMENT_NAME`, `CreateComposeAppRequest`, provision fallback, and connection factory default to `main`. Leftover `production` / `prod` canonicalize to `main` on create; inventory still matches those aliases. Laravel `APP_ENV` stays `production` / `staging` / `local`. Live: Deamon project env `i0sw4kk0cogg4o08oscwcssk` renamed `production` → `main` (same uuid, 18 apps). Other Coolify projects’ `production` envs left alone. Plane inventory row updated. Tests: ChannelEnvironmentMapTest, ProvisionSiteTest, CoolifyClientTest (`Http::fake`).

## Slice 1 — server IP (2026-09-10)

- Status: **ship**. `coolify_servers.ip` from Coolify `public_ip`/`public` else `ip`. Connection show table has IP column. Sync does not write `is_active` (does not zero it).
