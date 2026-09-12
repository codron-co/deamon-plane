# Platform Mail Plane — Save Feedback, Test Mail, Ops Unsubscribe

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make Plane `/platform-mail` always show save/validation/error feedback, send test mail, push sites asynchronously, and support one-click unsubscribe for Plane-sent ops mails.

**Architecture:** Save persists `PlatformMailSetting` and flashes immediately; `PushPlatformMailJob` (or per-site unique jobs) syncs CMS in the background. Test mail uses the same runtime SMTP as `PlatformOpsMailer`. Ops mail opt-outs live on Plane `users.mail_notification_opt_outs`; signed unsubscribe routes flip one key off.

**Tech Stack:** Laravel Blade, PHPUnit, Laravel queues (`ShouldQueue`), encrypted SMTP settings, i18n `lang/{en,tr}/platform_mail.php`.

**Spec:** `docs/superpowers/specs/2026-09-12-platform-mail-feedback-prefs-unsubscribe-design.md`  
**Companion plan (CMS):** `deamon` → `docs/superpowers/plans/2026-09-12-platform-mail-cms-prefs-unsubscribe.md`

## Global Constraints

- Never block success flash on HTTP site configure.
- Never put SMTP passwords in flash, logs, or Blade.
- All new UI strings in `lang/en` + `lang/tr` (`platform_mail.*`).
- `ops.write` for save / push / test; unsubscribe routes are signed + public (no auth).
- Out of scope: Hostinger mailboxes, CMS per-admin prefs (other plan).

---

## File map

| File | Responsibility |
|------|----------------|
| `app/Http/Controllers/Ops/PlatformMailSettingsController.php` | save (no sync), push, test; flash keys |
| `app/Jobs/PushPlatformMailJob.php` | sync one site via `PlatformMailConfigurer` |
| `app/Jobs/DispatchPlatformMailPushJob.php` | fan-out unique jobs for all sites with agent secret |
| `app/Services/Mail/PlatformOpsMailer.php` | send + respect user opt-outs; HTML/raw with unsubscribe |
| `app/Services/Mail/PlatformMailUnsubscribe.php` | sign/verify tokens; apply opt-out |
| `app/Http/Controllers/Ops/PlatformMailUnsubscribeController.php` | GET+POST one-click |
| `app/Models/User.php` | `mail_notification_opt_outs` JSON helpers |
| `database/migrations/*_add_mail_notification_opt_outs_to_users_table.php` | column |
| `resources/views/ops/platform-mail/edit.blade.php` | errors, test form, updated save label |
| `resources/views/mail/platform-ops.blade.php` | ops mail body + unsubscribe footer |
| `resources/views/ops/platform-mail/unsubscribed.blade.php` | confirmation / invalid |
| `routes/ops/mail-servers.php` (+ guest unsubscribe routes file if needed) | routes |
| `lang/{en,tr}/platform_mail.php` | copy |
| `tests/Feature/Ops/PlatformMailSettingsTest.php` | save flash, validation, job, test mail |
| `tests/Feature/Ops/PlatformMailUnsubscribeTest.php` | opt-out + token |
| `docs/modules/platform-mail.md` | behavior |

---

### Task 1: Save returns flash immediately (no sync in request)

**Files:**
- Modify: `app/Http/Controllers/Ops/PlatformMailSettingsController.php`
- Modify: `resources/views/ops/platform-mail/edit.blade.php`
- Modify: `lang/en/platform_mail.php`, `lang/tr/platform_mail.php`
- Test: `tests/Feature/Ops/PlatformMailSettingsTest.php`

**Interfaces:**
- Produces: `update()` redirects with `status` = `__('platform_mail.flash.saved')` **without** calling `syncAllSites()`.
- Produces: edit view shows `$errors` when validation fails.

- [ ] **Step 1: Extend failing assertions for flash + validation UI**

Add to `PlatformMailSettingsTest.php`:

```php
public function test_save_flashes_status_without_waiting_on_sites(): void
{
    $this->actingAs($this->operator())
        ->put(route('ops.platform-mail.update'), [
            'enabled' => '1',
            'host' => 'smtp.example.com',
            'port' => 465,
            'encryption' => 'ssl',
            'username' => 'u@example.com',
            'password' => 'secret-pass',
            'from_address' => 'noreply@example.com',
            'from_name' => 'Test',
            'default_admin_recipient' => 'ops@example.com',
            'notifications' => [],
        ])
        ->assertRedirect(route('ops.platform-mail.edit'))
        ->assertSessionHas('status');

    $this->actingAs($this->operator())
        ->get(route('ops.platform-mail.edit'))
        ->assertOk()
        ->assertSee(__('platform_mail.flash.saved'), false);
}

public function test_invalid_from_address_redirects_back_with_errors(): void
{
    $this->actingAs($this->operator())
        ->from(route('ops.platform-mail.edit'))
        ->put(route('ops.platform-mail.update'), [
            'enabled' => '1',
            'host' => 'smtp.example.com',
            'port' => 465,
            'encryption' => 'ssl',
            'username' => 'u@example.com',
            'password' => 'secret-pass',
            'from_address' => 'not-an-email',
            'from_name' => 'Test',
        ])
        ->assertRedirect(route('ops.platform-mail.edit'))
        ->assertSessionHasErrors(['from_address']);
}
```

Laravel `validate()` redirects **back** to the `from` URL with `$errors` in the session for that response cycle. Blade `@if ($errors->any())` covers the browser case; the test asserts `assertSessionHasErrors` on the redirect.

- [ ] **Step 2: Run tests — expect flash assertion fail until controller/lang updated**

Run: `php artisan test --filter=PlatformMailSettingsTest`  
Expected: existing save test may still pass; new flash/see assertions fail until Steps 3–4.

- [ ] **Step 3: Controller — save without sync; flash; validation uses back**

In `PlatformMailSettingsController::update`, remove `$configurer->syncAllSites()` (keep `$configurer` unused until Task 2 — or drop param). Change return:

```php
return redirect()
    ->route('ops.platform-mail.edit')
    ->with('status', __('platform_mail.flash.saved'));
```

Update lang:

```php
// en
'save' => 'Save',
'flash' => [
    'saved' => 'Software mail settings saved.',
    'saved_push_queued' => 'Software mail settings saved. Push to sites queued.',
    // keep pushed, site_saved
],
'form_errors' => 'Fix the highlighted fields and try again.',
'test' => [
    'button' => 'Send test email',
    'to' => 'Test recipient',
    'to_hint' => 'Defaults to the default recipient above.',
    'sent' => 'Test email sent to :email.',
    'failed' => 'Test email failed. Check SMTP settings.',
    'not_ready' => 'Enable software mail and fill host, username, password, and from address first.',
],
```

Mirror Turkish in `lang/tr/platform_mail.php`.

- [ ] **Step 4: Blade — form errors block at top of content form**

In `edit.blade.php` inside the main form, before SMTP card:

```blade
@if ($errors->any())
    <p class="ops-alert" role="alert">{{ __('platform_mail.form_errors') }}</p>
    <ul class="ops-alert-list">
        @foreach ($errors->all() as $error)
            <li>{{ $error }}</li>
        @endforeach
    </ul>
@endif
```

Change save button label to `__('platform_mail.save')` (already).

- [ ] **Step 5: Re-run tests**

Run: `php artisan test --filter=PlatformMailSettingsTest`  
Expected: PASS (adjust Step 1 if `assertSee` flash on second GET is flaky — `assertSessionHas('status')` on redirect is the hard requirement).

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/Ops/PlatformMailSettingsController.php resources/views/ops/platform-mail/edit.blade.php lang/en/platform_mail.php lang/tr/platform_mail.php tests/Feature/Ops/PlatformMailSettingsTest.php
git commit -m "fix(plane): show platform-mail save feedback without blocking on site push"
```

---

### Task 2: Queue push to sites on save + manual push

**Files:**
- Create: `app/Jobs/PushPlatformMailJob.php`
- Create: `app/Jobs/DispatchPlatformMailPushJob.php`
- Modify: `PlatformMailSettingsController.php` (`update`, `push`)
- Modify: `lang/*/platform_mail.php`
- Test: `tests/Feature/Ops/PlatformMailSettingsTest.php`

**Interfaces:**
- Consumes: `PlatformMailConfigurer::sync(Site $site): SiteMailConfigureResult`
- Produces: `DispatchPlatformMailPushJob` queued from save; `push()` still syncs (or also queues — **queue both**; manual push flashes `pushed_queued`)

- [ ] **Step 1: Failing test — Job::fake**

```php
use App\Jobs\DispatchPlatformMailPushJob;
use Illuminate\Support\Facades\Queue;

public function test_save_queues_platform_mail_push_dispatch(): void
{
    Queue::fake();

    $this->actingAs($this->operator())
        ->put(route('ops.platform-mail.update'), [
            'enabled' => '1',
            'host' => 'smtp.example.com',
            'port' => 465,
            'encryption' => 'ssl',
            'username' => 'u@example.com',
            'password' => 'secret-pass',
            'from_address' => 'noreply@example.com',
            'from_name' => 'Test',
            'default_admin_recipient' => 'ops@example.com',
            'notifications' => [],
        ])
        ->assertRedirect(route('ops.platform-mail.edit'))
        ->assertSessionHas('status');

    Queue::assertPushed(DispatchPlatformMailPushJob::class);
}
```

- [ ] **Step 2: Run — expect FAIL (job missing / not dispatched)**

Run: `php artisan test --filter=test_save_queues_platform_mail_push_dispatch`

- [ ] **Step 3: Implement jobs**

```php
// app/Jobs/PushPlatformMailJob.php
namespace App\Jobs;

use App\Models\Site;
use App\Services\Mail\PlatformMailConfigurer;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class PushPlatformMailJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;
    public int $timeout = 30;
    public int $uniqueFor = 120;

    public function __construct(public readonly string $siteId) {}

    public function uniqueId(): string
    {
        return $this->siteId;
    }

    public function handle(PlatformMailConfigurer $configurer): void
    {
        $site = Site::query()->find($this->siteId);
        if ($site === null || ! $site->hasAgentSecret()) {
            return;
        }
        $configurer->sync($site);
    }
}
```

```php
// app/Jobs/DispatchPlatformMailPushJob.php
namespace App\Jobs;

use App\Models\PlatformMailSetting;
use App\Models\Site;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class DispatchPlatformMailPushJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;
    public int $timeout = 60;

    public function handle(): void
    {
        Site::query()->orderBy('id')->each(function (Site $site): void {
            if (! $site->hasAgentSecret()) {
                return;
            }
            PushPlatformMailJob::dispatch($site->id);
        });

        $settings = PlatformMailSetting::current();
        if ($settings->exists) {
            $settings->last_pushed_at = now();
            $settings->save();
        }
    }
}
```

Note: `last_pushed_at` updates when dispatch runs, not when each site succeeds — acceptable; optionally move stamp into a finalizing job later (YAGNI).

- [ ] **Step 4: Wire controller**

```php
use App\Jobs\DispatchPlatformMailPushJob;

// end of update(), after save + audit:
DispatchPlatformMailPushJob::dispatch();

return redirect()
    ->route('ops.platform-mail.edit')
    ->with('status', __('platform_mail.flash.saved_push_queued'));

// push():
DispatchPlatformMailPushJob::dispatch();
return redirect()
    ->route('ops.platform-mail.edit')
    ->with('status', __('platform_mail.flash.pushed_queued'));
```

Add `pushed_queued` lang strings (en/tr).

- [ ] **Step 5: Run tests**

Run: `php artisan test --filter=PlatformMailSettingsTest`  
Expected: PASS

- [ ] **Step 6: Commit**

```bash
git add app/Jobs/PushPlatformMailJob.php app/Jobs/DispatchPlatformMailPushJob.php app/Http/Controllers/Ops/PlatformMailSettingsController.php lang/en/platform_mail.php lang/tr/platform_mail.php tests/Feature/Ops/PlatformMailSettingsTest.php
git commit -m "feat(plane): queue platform-mail push to sites after save"
```

---

### Task 3: Send test email

**Files:**
- Modify: `PlatformMailSettingsController.php` — `test()`
- Modify: `routes/ops/mail-servers.php`
- Modify: `edit.blade.php` — test form
- Modify: `PlatformOpsMailer.php` — add `sendTest(string $to): bool` OR dedicated small service
- Modify: lang
- Test: `PlatformMailSettingsTest.php`

**Interfaces:**
- Produces: `POST ops.platform-mail.test` → flash status/error
- Consumes: `PlatformMailSetting::isReady()`, runtime mailer config

- [ ] **Step 1: Failing tests**

```php
use Illuminate\Support\Facades\Mail;

public function test_test_mail_requires_ready_settings(): void
{
    $this->actingAs($this->operator())
        ->post(route('ops.platform-mail.test'), [])
        ->assertRedirect(route('ops.platform-mail.edit'))
        ->assertSessionHas('error');
}

public function test_test_mail_sends_when_ready(): void
{
    Mail::fake();

    PlatformMailSetting::query()->create([
        'enabled' => true,
        'host' => 'smtp.example.com',
        'port' => 465,
        'encryption' => 'ssl',
        'username' => 'u@example.com',
        'password' => 'secret-pass',
        'from_address' => 'noreply@example.com',
        'from_name' => 'Test',
        'default_admin_recipient' => 'ops@example.com',
        'notifications' => [],
    ]);

    // Force array mailer in test via config if PlatformOpsMailer checks transport
    config(['mail.default' => 'array']);

    $this->actingAs($this->operator())
        ->post(route('ops.platform-mail.test'), ['to' => 'probe@example.com'])
        ->assertRedirect(route('ops.platform-mail.edit'))
        ->assertSessionHas('status');

    Mail::assertSent(\App\Mail\PlatformTestMail::class, function (\App\Mail\PlatformTestMail $mail): bool {
        return $mail->hasTo('probe@example.com');
    });
}
```

- [ ] **Step 2: Run — FAIL (route missing)**

- [ ] **Step 3: Implement mailable + controller + route + UI**

```php
// app/Mail/PlatformTestMail.php
namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class PlatformTestMail extends Mailable
{
    public function envelope(): Envelope
    {
        return new Envelope(subject: __('platform_mail.test.subject'));
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.platform-test');
    }
}
```

```blade
{{-- resources/views/mail/platform-test.blade.php --}}
<x-mail::message>
# {{ __('platform_mail.test.subject') }}

{{ __('platform_mail.test.body') }}
</x-mail::message>
```

Controller method:

```php
public function test(Request $request): RedirectResponse
{
    $this->authorize('ops.write');

    $settings = PlatformMailSetting::current();
    if (! $settings->exists || ! $settings->isReady()) {
        return redirect()
            ->route('ops.platform-mail.edit')
            ->with('error', __('platform_mail.test.not_ready'));
    }

    $validated = $request->validate([
        'to' => ['nullable', 'email', 'max:255'],
    ]);

    $to = trim((string) ($validated['to'] ?? ''));
    if ($to === '') {
        $to = (string) ($settings->default_admin_recipient ?? '');
    }
    if ($to === '' || ! filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return redirect()
            ->route('ops.platform-mail.edit')
            ->with('error', __('platform_mail.test.no_recipient'));
    }

    try {
        app(PlatformOpsMailer::class)->sendTest($to);
    } catch (\Throwable) {
        return redirect()
            ->route('ops.platform-mail.edit')
            ->with('error', __('platform_mail.test.failed'));
    }

    return redirect()
        ->route('ops.platform-mail.edit')
        ->with('status', __('platform_mail.test.sent', ['email' => $to]));
}
```

Add `PlatformOpsMailer::sendTest(string $to): void` that calls `applyRuntimeMailer` then `Mail::mailer('platform_ops')->to($to)->send(new PlatformTestMail)`.

Route: `Route::post('/platform-mail/test', [...])->name('ops.platform-mail.test');`

Blade: secondary form next to save with email input + button (`data-ops-pending`).

- [ ] **Step 4: Run tests — PASS**

- [ ] **Step 5: Commit**

```bash
git add app/Mail/PlatformTestMail.php resources/views/mail/platform-test.blade.php app/Services/Mail/PlatformOpsMailer.php app/Http/Controllers/Ops/PlatformMailSettingsController.php routes/ops/mail-servers.php resources/views/ops/platform-mail/edit.blade.php lang/en/platform_mail.php lang/tr/platform_mail.php tests/Feature/Ops/PlatformMailSettingsTest.php
git commit -m "feat(plane): send platform-mail SMTP test from ops UI"
```

---

### Task 4: Plane ops unsubscribe + respect opt-outs

**Files:**
- Create migration `*_add_mail_notification_opt_outs_to_users_table.php`
- Modify: `app/Models/User.php`
- Create: `app/Services/Mail/PlatformMailUnsubscribe.php`
- Create: `app/Http/Controllers/Ops/PlatformMailUnsubscribeController.php`
- Create: `resources/views/ops/platform-mail/unsubscribed.blade.php`
- Create: `resources/views/mail/platform-ops.blade.php` (or extend raw send)
- Modify: `PlatformOpsMailer.php` — skip opted-out users; attach List-Unsubscribe; footer link
- Routes: guest GET/POST (register outside auth middleware — check `routes/web.php` / ops bootstrap)
- Test: `tests/Feature/Ops/PlatformMailUnsubscribeTest.php`

**Interfaces:**
- `PlatformMailUnsubscribe::url(User $user, string $key): string`
- `PlatformMailUnsubscribe::apply(string $token): bool`
- `User::hasMailOptOut(string $key): bool` / `optOutMail(string $key): void`
- Token payload: `user_id`, `key`, `exp` signed with `URL::temporarySignedRoute` **or** `Crypt`/`hash_hmac` app key

Prefer Laravel `URL::temporarySignedRoute('ops.platform-mail.unsubscribe', now()->addDays(60), ['user' => $id, 'key' => $key])` so no custom crypto.

- [ ] **Step 1: Write failing unsubscribe test**

```php
public function test_signed_unsubscribe_opts_out_user_for_key(): void
{
    $user = User::factory()->create();
    $url = URL::temporarySignedRoute(
        'ops.platform-mail.unsubscribe',
        now()->addDay(),
        ['user' => $user->id, 'key' => 'site_down'],
    );

    $this->get($url)
        ->assertOk()
        ->assertSee(__('platform_mail.unsubscribe.done'), false);

    $user->refresh();
    $this->assertTrue($user->hasMailOptOut('site_down'));
}

public function test_ops_mailer_skips_opted_out_user(): void
{
    Mail::fake();
    // create ready settings + site + user with opt-out
    // call mailer->send(...)
    // assert user email not among recipients (spy or Mail::assertNotSent to that address)
}
```

- [ ] **Step 2: Run — FAIL**

- [ ] **Step 3: Migration + User helpers**

```php
$table->json('mail_notification_opt_outs')->nullable();
```

```php
public function hasMailOptOut(string $key): bool
{
    $bag = is_array($this->mail_notification_opt_outs) ? $this->mail_notification_opt_outs : [];

    return (bool) ($bag[$key] ?? false);
}

public function optOutMail(string $key): void
{
    $bag = is_array($this->mail_notification_opt_outs) ? $this->mail_notification_opt_outs : [];
    $bag[$key] = true;
    $this->mail_notification_opt_outs = $bag;
    $this->save();
}
```

Cast `mail_notification_opt_outs` => `array`.

- [ ] **Step 4: Controller + routes (unsigned guest, signed middleware)**

```php
Route::get('/platform-mail/unsubscribe', [PlatformMailUnsubscribeController::class, 'show'])
    ->middleware('signed')
    ->name('ops.platform-mail.unsubscribe');
Route::post('/platform-mail/unsubscribe', [PlatformMailUnsubscribeController::class, 'show'])
    ->middleware('signed')
    ->name('ops.platform-mail.unsubscribe.post');
```

Place these **outside** the authenticated ops group (same pattern as login). One-click: GET immediately applies opt-out (per spec).

- [ ] **Step 5: PlatformOpsMailer — filter recipients; use mailable with unsubscribe URL per user**

When sending to a `User`, skip if `hasMailOptOut($notificationKey)`. For primary recipient email that is not a User, still send (no Plane opt-out row). For User recipients, pass unsubscribe URL into markdown footer.

Add `List-Unsubscribe` header pointing at the signed URL when recipient is a User.

- [ ] **Step 6: Run tests — PASS**

- [ ] **Step 7: Commit**

```bash
git add database/migrations/*mail_notification_opt_outs* app/Models/User.php app/Services/Mail/PlatformMailUnsubscribe.php app/Http/Controllers/Ops/PlatformMailUnsubscribeController.php app/Services/Mail/PlatformOpsMailer.php resources/views/mail/platform-ops.blade.php resources/views/ops/platform-mail/unsubscribed.blade.php routes/**/*.php lang/en/platform_mail.php lang/tr/platform_mail.php tests/Feature/Ops/PlatformMailUnsubscribeTest.php
git commit -m "feat(plane): one-click unsubscribe for ops platform mails"
```

---

### Task 5: Docs

**Files:**
- Modify: `docs/modules/platform-mail.md`
- Modify: `docs/plans/progress-ledger.md` (one line)
- Modify: `CHANGELOG.md` if present
- Modify: spec status → `planned` / `implementing`

- [ ] **Step 1: Update module doc** — save vs push queue, test endpoint, unsubscribe host = Plane for ops keys.

- [ ] **Step 2: Commit**

```bash
git add docs/modules/platform-mail.md docs/plans/progress-ledger.md docs/superpowers/specs/2026-09-12-platform-mail-feedback-prefs-unsubscribe-design.md CHANGELOG.md
git commit -m "docs(plane): platform-mail save, test, and unsubscribe behavior"
```

---

## Spec coverage (self-review)

| Spec item | Task |
|-----------|------|
| Save flash / validation | T1 |
| Async push | T2 |
| Test mail | T3 |
| Plane ops unsubscribe | T4 |
| Docs | T5 |
| CMS prefs / site unsubscribe | Companion CMS plan |
