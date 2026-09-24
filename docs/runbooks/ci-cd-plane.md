# Runbook: Plane CI and CI-gated deploy

SoT: [`.github/workflows/ci.yml`](../../.github/workflows/ci.yml) · deploy shape: [deployment.md](../modules/deployment.md) · Coolify API: [coolify-client.md](../modules/coolify-client.md)

This covers **Plane itself** (`codron-co/deamon-plane`). Customer CMS sites are deployed by Plane jobs, not by this workflow.

## What runs

Workflow name: **`CI`**. Do not rename it — branch protection and Plane match on this name.

Triggers: every push to `main`, `beta`, `alpha`, and every pull request.

| Job | Runs on | What it checks |
|-----|---------|----------------|
| `lint` | push + PR | `composer audit --locked --abandoned=report`, `pint --test`, PHPStan (Larastan) level 5 with `phpstan-baseline.neon` |
| `test-sqlite` | push + PR | `php artisan test` with the `phpunit.xml` default (sqlite `:memory:`) |
| `test-mysql` | push + PR | the same suite against a MySQL 8.0 service container (see below) |
| `node` | push + PR | `node --check public/js/*.js` and `node --test tests/js/*.js` ([ADR-11](../decisions/adr-11-dom-test-harness.md)) |
| `docker` | push only | builds `Dockerfile` (buildx, GitHub Actions cache, never pushed), then boots `docker-compose.coolify.yml` (app + MySQL + Redis) from that image with a throwaway `.env` and waits for `/up` = 200 (max 5 min). On failure it prints `docker compose logs`. Always tears down with `down -v`. |
| `deploy` | push to `main` / `beta` / `alpha` only | needs **all** jobs above green, then asks Coolify to deploy Plane (see [Deploy](#deploy)) |

Concurrency: on a PR a new push cancels the stale run. Branch pushes are never cancelled — each pushed commit gets its own verdict. Deploys are serialised per branch (`deploy-plane-<branch>`).

Third-party actions are pinned by full commit SHA with the version in a comment. Dependabot ([`.github/dependabot.yml`](../../.github/dependabot.yml)) opens weekly PRs against `alpha` for Composer and GitHub Actions, minor + patch grouped per ecosystem. There is no npm entry: Plane has no `package.json`.

### `composer audit`

Blocking only on **security advisories** for locked packages. Abandoned packages are reported, not failing (`--abandoned=report`). A new advisory can turn an unchanged branch red on its next push; fix it with `composer update <package>` (or a documented `audit.ignore` entry in `composer.json` with the reason) — do not remove the step.

### PHPStan baseline

`phpstan.neon` is level 5 over `app`, `config`, `database`, `routes`, with `parseModelCastsMethod: true` so enum casts declared in `casts()` are typed correctly. Every finding that existed when CI landed is in `phpstan-baseline.neon`. **New code must be clean**; do not add to the baseline to get a PR green.

After fixing baselined findings, shrink the file with `composer stan:baseline` and commit it. `reportUnmatchedIgnoredErrors` is on, so a fixed finding left in the baseline also fails the job. Known PHPStan quirk (also noted in `phpstan.neon`): a finding inside the `Ops/Concerns/LoadsSiteOpsContext` trait is counted under the using class (`SiteController.php`) — if a regenerated baseline reports `ignore.count … expected N, occurred N+1` there, raise that entry's count by one.

## Reproduce locally

```bash
composer install
composer check        # pint --test, phpstan, php artisan test (sqlite)
node --test tests/js/*.js
```

Single parts: `composer lint`, `composer stan`, `composer test`.

Windows note: `.gitattributes` forces LF, but files written by some Windows tools can sit as CRLF in the working tree (Git still stores LF). `pint --test` reports those as `line_ending`. `git add --renormalize .` shows nothing to commit; re-checking out the file (or letting pint fix it) makes the local check match CI.

### The MySQL job locally

`phpunit.xml` `<env>` entries have no `force="true"`, so a value that already exists in the process environment wins over them. That is how the MySQL job swaps out `DB_CONNECTION=sqlite` / `DB_DATABASE=:memory:` without touching `phpunit.xml`. `DB_URL` stays empty (phpunit sets `""` when unset), so the discrete `DB_*` values are used.

```bash
docker run -d --rm --name plane-test-mysql -p 33061:3306 \
  -e MYSQL_DATABASE=plane_test -e MYSQL_USER=plane -e MYSQL_PASSWORD=plane \
  -e MYSQL_ROOT_PASSWORD=root -e TZ=Europe/Istanbul \
  mysql:8.0 --default-time-zone=+03:00 \
  --character-set-server=utf8mb4 --collation-server=utf8mb4_unicode_ci

DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT=33061 DB_DATABASE=plane_test \
DB_USERNAME=plane DB_PASSWORD=plane DB_TIMEZONE=+03:00 php artisan test

docker stop plane-test-mysql
```

GitHub service containers cannot take `mysqld` flags, so CI sets the server `time_zone` (`+03:00`) and `utf8mb4_unicode_ci` with `SET PERSIST` after the container is healthy, matching `--default-time-zone=+03:00` and the charset flags in `docker-compose.coolify.yml`. Laravel also sets the session time zone from `DB_TIMEZONE`.

## Deploy

**Target state:** Coolify auto-deploy for the Plane app is **off**. The `deploy` job is the only thing that deploys Plane from a push, and it runs only when `lint`, `test-sqlite`, `test-mysql`, `node` and `docker` are all green.

What it does:

1. Reads `COOLIFY_BASE_URL`, `COOLIFY_API_TOKEN`, `COOLIFY_APP_UUID` from the GitHub Environment named after the branch (`main`, `beta`, `alpha`). If any is empty it prints a notice and exits **green** without deploying — safe before setup.
2. Checks the branch HEAD on GitHub still equals the commit this run tested. If the branch moved on, it skips: the newer push has its own CI run and deploys itself once green.
3. Calls the same endpoint as `CoolifyClient::deploy()`: `POST {COOLIFY_BASE_URL}/api/v1/deploy?uuid={COOLIFY_APP_UUID}` with `Authorization: Bearer`. `COOLIFY_BASE_URL` may be given with or without `/api/v1` (same normalisation as `CoolifyCredentials`). The token is passed to `curl` on stdin, not argv; all calls use `--fail-with-body` and timeouts; the POST is never retried (a retry could queue a second deploy).
4. Follows `GET /api/v1/deployments/{deployment_uuid}` every 10 s until `finished` (green) or `failed` / `cancelled` (red), max 20 min. If Coolify reports it built a different commit, the job warns.

### Why HEAD and not a pinned commit

Coolify's `/deploy` has no commit parameter; it builds the application's `git_commit_sha`, which Plane keeps at `HEAD`. Pinning the exact SHA would mean `PATCH /applications/{uuid}` with `git_commit_sha=<sha>` before every deploy. That leaves the Plane app pinned in Coolify (Plane's own app list shows it as **pinned**), and a later manual Redeploy or channel operation would silently rebuild the old SHA until someone resets it. Deploying `HEAD` after the "HEAD still equals this commit" check avoids that. The residual risk is a push landing in the few seconds between the check and Coolify's clone; that newer commit then goes live before its own CI finishes, its run redeploys it when green, and the warning in step 4 flags the case.

## Setup (one time)

### 1. Coolify API token

Coolify → **Keys & Tokens → API tokens** → create a token with the **deploy** and **read** abilities (read is needed to follow the deployment). Prefer a dedicated token for CI, not the fleet token Plane stores in its Coolify menu, so it can be rotated on its own ([token-rotation.md](token-rotation.md)).

### 2. GitHub Environments + secrets

GitHub → `codron-co/deamon-plane` → **Settings → Environments**. Create one environment per deployed branch: `main`, and `alpha` / `beta` only if that branch has its own Plane app. (An environment referenced by a run is auto-created empty; the job then skips with a notice.)

In each environment add these **environment secrets**:

| Secret | Value |
|--------|-------|
| `COOLIFY_BASE_URL` | e.g. `https://coolify.example.com` (no trailing `/api/v1` needed) |
| `COOLIFY_API_TOKEN` | the token from step 1 |
| `COOLIFY_APP_UUID` | the Plane app uuid for that branch (production Plane: see [deploy-plane.md](deploy-plane.md)). **Never** a customer site uuid. |

Recommended for `main`: **Deployment branches and tags → Selected branches → `main`**, so only `main` can use the production secrets. Required reviewers are optional (they pause every production deploy until approved).

### 3. Turn off Coolify auto-deploy for the Plane app

Only after step 2 works (a green `deploy` job on that branch), otherwise nothing deploys Plane:

Coolify → Project → **Deamon Plane** application → **Advanced** → untick **Auto Deploy** (General section) → Save.

API equivalent (same field Plane's `CoolifyClient` maps `is_auto_deploy` to): `PATCH /api/v1/applications/{plane-app-uuid}` with `{"is_auto_deploy_enabled": false}`.

Do this on the Plane application only (for each branch that has one). Customer site apps keep their own settings; Plane manages those. The GitHub → Coolify push webhook can stay; with auto-deploy off Coolify ignores pushes for this app.

### 4. Branch protection (recommended)

GitHub → **Settings → Branches** (or Rulesets) for `main`:

- Require a pull request before merging.
- **Require status checks to pass**: `lint`, `test-sqlite`, `test-mysql`, `node` (all from workflow `CI`). `docker` and `deploy` run only on push, so do not make them required PR checks.
- Require branches to be up to date before merging.

Same rule on `beta` if it receives PRs. `alpha` can stay unprotected for direct pushes; CI still gates its deploy.

## When CI is red

Nothing deploys. The running Plane stays on the last green commit.

- Fix forward: push a fix; its green run deploys it.
- Or revert: `git revert <sha>` and push; the revert's green run deploys it.
- Do not re-enable Coolify auto-deploy or click **Redeploy** in Coolify to get around a red run. A manual Coolify Redeploy builds the branch HEAD — the red commit.
- A red `deploy` job (Coolify build failed) with green checks: read the deployment log in Coolify ([deploy-failure-triage.md](deploy-failure-triage.md)); re-run the `deploy` job from the Actions UI once the cause is fixed outside the repo.
