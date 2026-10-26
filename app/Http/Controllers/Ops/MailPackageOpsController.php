<?php

namespace App\Http\Controllers\Ops;

use App\Http\Controllers\Controller;
use App\Jobs\ApplyComailPackageJob;
use App\Models\MailPackage;
use App\Models\Site;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * CoMail packages: mailbox / storage / alias / domain limits per site.
 * Exactly one package is the default (sites with no package use it).
 */
class MailPackageOpsController extends Controller
{
    public function index(Request $request): View
    {
        MailPackage::default();

        return view('ops.mail-packages.index', [
            'packages' => MailPackage::query()->withCount('sites')->orderBy('sort')->orderBy('name')->get(),
            'defaultSites' => Site::query()->whereNull('mail_package_id')->whereHas('comailDomains')->count(),
            'canWrite' => $request->user()?->can('ops.write') ?? false,
            'canDanger' => $request->user()?->can('ops.danger') ?? false,
        ]);
    }

    public function create(): View
    {
        $this->authorize('ops.write');

        return view('ops.mail-packages.create', [
            'package' => new MailPackage([
                'mailbox_limit' => 5,
                'storage_mb' => 10240,
                'mailbox_max_quota_mb' => 5120,
                'alias_limit' => 50,
                'currency' => 'TRY',
                'is_active' => true,
                'sort' => (int) MailPackage::query()->max('sort') + 10,
            ]),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('ops.write');

        $package = new MailPackage;
        $this->fill($package, $this->validated($request, null));
        $package->save();
        if ($package->is_default) {
            $package->markAsDefault();
        }

        $this->audit($package, $request, 'mail_package.created', null, $this->snapshot($package));

        return redirect()->route('ops.mail-packages.edit', $package)->with('status', __('comail.packages.flash.saved'));
    }

    public function edit(Request $request, MailPackage $mailPackage): View
    {
        return view('ops.mail-packages.edit', [
            'package' => $mailPackage->loadCount('sites'),
            'canWrite' => $request->user()?->can('ops.write') ?? false,
            'canDanger' => $request->user()?->can('ops.danger') ?? false,
        ]);
    }

    public function update(Request $request, MailPackage $mailPackage): RedirectResponse
    {
        $this->authorize('ops.write');

        $before = $this->snapshot($mailPackage);
        $wasDefault = $mailPackage->is_default;
        $this->fill($mailPackage, $this->validated($request, $mailPackage));
        if ($wasDefault && ! $mailPackage->is_default) {
            // Exactly one default: pick another package as default instead of unsetting it here.
            $mailPackage->is_default = true;
        }
        $mailPackage->save();
        if ($mailPackage->is_default && ! $wasDefault) {
            $mailPackage->markAsDefault();
        }

        $this->audit($mailPackage, $request, 'mail_package.updated', $before, $this->snapshot($mailPackage));
        $this->reapply($mailPackage, $wasDefault || $mailPackage->is_default);

        return redirect()->route('ops.mail-packages.edit', $mailPackage)->with('status', __('comail.packages.flash.saved'));
    }

    public function destroy(Request $request, MailPackage $mailPackage): RedirectResponse
    {
        $this->authorize('ops.danger');

        if ($mailPackage->is_default) {
            return back()->with('error', __('comail.packages.errors.default'));
        }

        if ($mailPackage->sites()->exists()) {
            return back()->with('error', __('comail.packages.errors.in_use'));
        }

        if ($mailPackage->subscriptions()->exists() || $mailPackage->orders()->exists()) {
            return back()->with('error', __('comail.packages.errors.has_orders'));
        }

        $this->audit($mailPackage, $request, 'mail_package.deleted', $this->snapshot($mailPackage), null);
        $mailPackage->delete();

        return redirect()->route('ops.mail-packages.index')->with('status', __('comail.packages.flash.deleted'));
    }

    /**
     * Limits changed: the mailcow ceilings follow on every site using the package.
     */
    private function reapply(MailPackage $package, bool $includeDefaultSites): void
    {
        $query = Site::query()->whereHas('comailDomains')->where(function ($sites) use ($package, $includeDefaultSites): void {
            $sites->where('mail_package_id', $package->id);
            if ($includeDefaultSites) {
                $sites->orWhereNull('mail_package_id');
            }
        });

        $query->orderBy('id')->pluck('id')->each(static function (mixed $siteId): void {
            ApplyComailPackageJob::dispatch((string) $siteId);
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?MailPackage $package): array
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'slug' => ['required', 'string', 'max:64', 'regex:/\A[a-z0-9][a-z0-9-]*\z/', Rule::unique('mail_packages', 'slug')->ignore($package?->id)],
            'description' => ['nullable', 'string', 'max:1000'],
            'mailbox_limit' => ['required', 'integer', 'min:0', 'max:100000'],
            'storage_mb' => ['required', 'integer', 'min:1', 'max:10000000'],
            'mailbox_max_quota_mb' => ['required', 'integer', 'min:1', 'lte:storage_mb'],
            'alias_limit' => ['required', 'integer', 'min:0', 'max:100000'],
            'domain_limit' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'price_monthly' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'price_yearly' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'currency' => ['required', 'string', 'size:3', 'alpha'],
            'is_default' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
            'sort' => ['nullable', 'integer', 'min:0', 'max:100000'],
        ]);

        if ((bool) ($validated['is_default'] ?? false) && ! (bool) ($validated['is_active'] ?? true)) {
            $validated['is_active'] = true;
        }

        return $validated;
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function fill(MailPackage $package, array $validated): void
    {
        $package->fill([
            'name' => $validated['name'],
            'slug' => strtolower((string) $validated['slug']),
            'description' => $validated['description'] ?? null,
            'mailbox_limit' => (int) $validated['mailbox_limit'],
            'storage_mb' => (int) $validated['storage_mb'],
            'mailbox_max_quota_mb' => (int) $validated['mailbox_max_quota_mb'],
            'alias_limit' => (int) $validated['alias_limit'],
            'domain_limit' => filled($validated['domain_limit'] ?? null) ? (int) $validated['domain_limit'] : null,
            'price_monthly' => filled($validated['price_monthly'] ?? null) ? $validated['price_monthly'] : null,
            'price_yearly' => filled($validated['price_yearly'] ?? null) ? $validated['price_yearly'] : null,
            'currency' => strtoupper((string) $validated['currency']),
            'is_default' => (bool) ($validated['is_default'] ?? $package->is_default ?? false),
            'is_active' => (bool) ($validated['is_active'] ?? $package->is_active ?? true),
            'sort' => (int) ($validated['sort'] ?? $package->sort ?? 0),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(MailPackage $package): array
    {
        return $package->only([
            'name', 'slug', 'mailbox_limit', 'storage_mb', 'mailbox_max_quota_mb', 'alias_limit',
            'domain_limit', 'price_monthly', 'price_yearly', 'currency', 'is_default', 'is_active', 'sort',
        ]);
    }

    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     */
    private function audit(MailPackage $package, Request $request, string $action, ?array $before, ?array $after): void
    {
        $package->auditLogs()->create([
            'actor_user_id' => $request->user()?->id,
            'action' => $action,
            'before' => $before,
            'after' => $after,
            'ip' => $request->ip(),
        ]);
    }
}
