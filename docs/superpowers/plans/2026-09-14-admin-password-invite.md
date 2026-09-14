# Admin Password Invite (Plane) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let operators create CMS admins via invite mail and resend set-password invites; stop generating `DEAMON_DEFAULT_ADMIN_PASSWORD` in Coolify env sync.

**Architecture:** Extend site Admins tab and `SiteAdminController` with `password_mode=invite` and a `password-invite` agent call. Drop the catalog row so compose deploys no longer invent a seed password. Depends on CMS agent **≥ 1.2.18** (`password_is_set`, invite create, `POST …/password-invite`).

**Tech Stack:** Laravel Plane ops, `SiteAgentClient` HMAC, Blade admins tab, PHPUnit `Http::fake`.

**Spec:** `docs/superpowers/specs/2026-09-14-admin-password-invite-design.md`  
**CMS SoT plan:** `codron-co/deamon` → `docs/superpowers/plans/2026-09-14-admin-password-invite.md`

## Global Constraints

- Never flash or store invite tokens / passwords for invite mode.
- Generate/manual keep existing one-time password flash.
- Audit: `site.admin.password_invite_sent` (email/id only); create audit notes invite when applicable.
- Outdated CMS without password-invite → existing agent error UX (`needs_secret` / outdated patterns).
- Do not implement CMS seed/mail logic in Plane.

## File map

| File | Responsibility |
|------|----------------|
| `app/Support/CoolifyEnvDefaultCatalog.php` | remove admin password rows |
| `app/Support/SecretRedactor.php` | optional: keep redact pattern harmless or remove |
| `app/Services/Agent/SiteAgentClient.php` | create with invite; `sendAdminPasswordInvite` |
| `app/Services/Agent/ControlPlaneAgentContract.php` | path helper |
| `app/Services/Agent/AdminAgentResult.php` | allowlist `password_is_set` if filtered |
| `app/Http/Controllers/Ops/SiteAdminController.php` | invite create + invite action |
| `routes/ops/sites.php` | invite route |
| `resources/views/ops/sites/_admins.blade.php` | UI |
| `lang/{tr,en}/sites.php` | strings |
| `tests/Feature/Agent/SiteAdminAgentTest.php` | invite + resend |
| `tests/Feature/Sites/ProvisionSiteTest.php` (+ Cloudflare twin) | stop expecting admin password env |
| `docs/modules/site-admins.md`, `coolify-client.md`, `runbooks/provision-site.md`, `docs/plans/progress-ledger.md` | docs |

---

### Task 1: Remove Coolify `DEAMON_DEFAULT_ADMIN_PASSWORD` catalog entries

**Files:**
- Modify: `app/Support/CoolifyEnvDefaultCatalog.php`
- Modify: `tests/Feature/Sites/ProvisionSiteTest.php`
- Modify: `tests/Feature/Sites/ProvisionSiteCloudflareTest.php` (any fixtures listing the key)
- Modify: `app/Support/SecretRedactor.php` (leave redact rule OK for leftover envs)

- [x] **Step 1: Write / adjust failing assertions**

In provision tests that currently assert `DEAMON_DEFAULT_ADMIN_PASSWORD` is generated, flip to:

```php
$this->assertFalse($keys->contains('DEAMON_DEFAULT_ADMIN_PASSWORD'));
// or assertNotContains in the synced map
```

Remove fixtures that put empty `DEAMON_DEFAULT_ADMIN_PASSWORD` in Coolify env lists where the test only existed to prove generation.

- [x] **Step 2: Run** `php artisan test --filter=ProvisionSiteTest`

Expected: fail while catalog still generates the key.

- [x] **Step 3: Delete both catalog rows**

Remove from `compose()` and `dockerfile()`:

```php
['DEAMON_DEFAULT_ADMIN_PASSWORD', CoolifyEnvKind::Generated, '{{generated}}', true, 'admin_seed'],
```

- [x] **Step 4: Tests pass**

- [x] **Step 5: Commit**

```bash
git commit -m "fix(coolify): stop generating DEAMON_DEFAULT_ADMIN_PASSWORD"
```

---

### Task 2: Agent client + controller invite create / resend

**Files:**
- Modify: `app/Services/Agent/ControlPlaneAgentContract.php`
- Modify: `app/Services/Agent/SiteAgentClient.php`
- Modify: `app/Services/Agent/AdminAgentResult.php` (ensure `password_is_set` kept in admin allowlist)
- Modify: `app/Http/Controllers/Ops/SiteAdminController.php`
- Modify: `routes/ops/sites.php`
- Test: `tests/Feature/Agent/SiteAdminAgentTest.php`

**Interfaces:**
- Consumes: CMS `POST /admins` with `password_mode=invite`; `POST /admins/{id}/password-invite`
- Produces:
  - `ControlPlaneAgentContract::adminPasswordInvitePath(int $adminId): string`
  - `SiteAgentClient::sendAdminPasswordInvite(Site $site, int $adminId): AdminAgentResult`
  - `SiteAdminController::sendPasswordInvite(Request, Site, int $remoteAdmin): RedirectResponse`
  - `store()` accepts `password_mode` in `generate,manual,invite`

- [x] **Step 1: Failing tests**

```php
public function test_operator_can_create_admin_via_invite_without_password_flash(): void
{
    Http::fake([
        '*/internal/control/v1/admins' => Http::sequence()
            ->push([
                'ok' => true,
                'admin' => [
                    'id' => 9,
                    'name' => 'Invited',
                    'email' => 'invited@example.com',
                    'is_active' => true,
                    'must_change_password' => true,
                    'password_is_set' => false,
                    'has_two_factor' => false,
                    'created_at' => now()->toIso8601String(),
                ],
            ], 200)
            ->push(['ok' => true, 'admins' => [/* … */]], 200),
    ]);

    $this->actingAs($this->operator)
        ->post(route('ops.sites.admins.store', $this->site), [
            'name' => 'Invited',
            'email' => 'invited@example.com',
            'password_mode' => 'invite',
        ])
        ->assertRedirect()
        ->assertSessionMissing('admin_password_once');
}

public function test_operator_can_resend_password_invite(): void
{
    Http::fake([
        '*/admins/9/password-invite' => Http::response([
            'ok' => true,
            'admin' => [/* password_is_set false */],
        ], 200),
        '*/admins' => Http::response(['ok' => true, 'admins' => []], 200),
    ]);

    $this->actingAs($this->operator)
        ->post(route('ops.sites.admins.password-invite', [$this->site, 9]), [
            'admin_email' => 'invited@example.com',
        ])
        ->assertRedirect()
        ->assertSessionHas('status'); // or sites flash key used by controller
}
```

Match existing test helpers/properties from `SiteAdminAgentTest` (site, operator, Http patterns).

- [x] **Step 2: Run — fail**

- [x] **Step 3: Implement**

`store` validation:

```php
'password_mode' => ['nullable', 'in:generate,manual,invite'],
'password' => ['nullable', 'string', Password::min(12)->mixedCase()->numbers()->symbols()],
```

When mode is `invite`:

```php
$result = $this->agent->createAdmin($site, [
    'name' => …,
    'email' => …,
    'password_mode' => 'invite',
]);
// successRedirect WITHOUT password (null)
```

`resolvePassword` must not run for invite (avoid generating unused password).

New route:

```php
Route::post('/sites/{site}/admins/{remoteAdmin}/password-invite', [SiteAdminController::class, 'sendPasswordInvite'])
    ->name('ops.sites.admins.password-invite');
```

Audit action: `site.admin.password_invite_sent`.

- [x] **Step 4: Tests pass + commit**

```bash
git commit -m "feat(sites): invite-mode admin create and password-invite resend"
```

---

### Task 3: Admins tab UI + i18n

**Files:**
- Modify: `resources/views/ops/sites/_admins.blade.php`
- Modify: `lang/tr/sites.php`, `lang/en/sites.php`
- Modify: `app/Http/Controllers/Ops/SiteDetailController.php` only if list mapping must pass `password_is_set`

- [x] **Step 1: Add radio `invite` next to generate/manual**; hide password field when invite selected (extend existing `data-admin-password-mode` JS).

- [x] **Step 2: For each admin with `empty($admin['password_is_set'])`, show form button** posting to `ops.sites.admins.password-invite` with confirm via existing pending pattern (no `window.confirm`).

- [x] **Step 3: Strings**

```php
// tr
'password_invite' => 'Davet (e-posta ile şifre oluştur)',
'send_password_invite' => 'Şifre oluşturma maili gönder',
'flash.password_invite_sent' => 'Şifre oluşturma bağlantısı e-posta ile gönderildi.',
'password_not_set' => 'Şifre yok',
```

Mirror in `en`.

- [x] **Step 4: Manual UI smoke optional; commit**

```bash
git commit -m "feat(ui): site admins invite mode and resend invite action"
```

---

### Task 4: Docs + ledger

**Files:**
- Modify: `docs/modules/site-admins.md`
- Modify: `docs/modules/coolify-client.md` (remove admin password generation mentions)
- Modify: `docs/runbooks/provision-site.md`
- Modify: `docs/modules/ops-sites.md` if it lists the env
- Modify: `docs/plans/progress-ledger.md` short entry
- Modify: `docs/superpowers/specs/2026-09-14-admin-password-invite-design.md` status → implementing/done when finished

- [x] **Step 1: Update docs to match CMS 1.2.18 contract**
- [x] **Step 2: Commit**

```bash
git commit -m "docs: admin password invite on Plane site admins"
```

---

## Spec coverage checklist

| Spec item (Plane) | Task |
|-------------------|------|
| Drop Coolify admin password env | 1 |
| Create invite mode | 2–3 |
| Resend invite | 2–3 |
| No password flash on invite | 2 |
| Audit + docs | 2, 4 |
| Tests | 1–2 |

## Dependency

Implement **CMS plan Tasks 1–5** (or at least agent surface) before relying on live CMS; Plane tests use `Http::fake` and can land in parallel after contract shapes are frozen.
