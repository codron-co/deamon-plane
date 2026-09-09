<?php

namespace App\Http\Controllers\Ops;

use App\Enums\Channel;
use App\Enums\SiteStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Ops\StoreSiteRequest;
use App\Http\Requests\Ops\UpdateSiteRequest;
use App\Models\Site;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SiteController extends Controller
{
    public function index(Request $request): View
    {
        $this->authorize('viewAny', Site::class);

        $search = trim((string) $request->query('q', ''));
        $channel = (string) $request->query('channel', '');
        $status = (string) $request->query('status', '');

        $allowedChannels = config('ops.channels', []);
        $channel = in_array($channel, $allowedChannels, true) ? $channel : '';
        $status = in_array($status, SiteStatus::values(), true) ? $status : '';

        $query = Site::query()->orderBy('name');

        if ($search !== '') {
            $term = addcslashes($search, '%_\\');

            $query->where(function ($builder) use ($term): void {
                $builder->where('name', 'like', "%{$term}%")
                    ->orWhere('slug', 'like', "%{$term}%")
                    ->orWhere('primary_domain', 'like', "%{$term}%");
            });
        }

        if ($channel !== '') {
            $query->where('channel', $channel);
        }

        if ($status !== '') {
            $query->where('status', $status);
        }

        return view('ops.sites.index', [
            'sites' => $query->paginate(25)->withQueryString(),
            'search' => $search,
            'channel' => $channel,
            'status' => $status,
            'channels' => $allowedChannels,
            'statuses' => SiteStatus::values(),
            'filtersActive' => $search !== '' || $channel !== '' || $status !== '',
            'canCreate' => $request->user()?->can('create', Site::class) ?? false,
        ]);
    }

    public function create(Request $request): View
    {
        $this->authorize('create', Site::class);

        return view('ops.sites.create', [
            'site' => new Site([
                'channel' => Channel::Main,
                'coolify_server_uuid' => config('ops.coolify.default_server_uuid'),
            ]),
            'channels' => config('ops.channels', []),
            'readonly' => false,
        ]);
    }

    public function store(StoreSiteRequest $request): RedirectResponse
    {
        $data = $request->validated();

        $site = DB::transaction(function () use ($request, $data): Site {
            $site = Site::query()->create([
                'slug' => $data['slug'],
                'name' => $data['name'],
                'primary_domain' => $data['domain'],
                'channel' => $data['channel'],
                'status' => SiteStatus::Draft,
                'coolify_server_uuid' => $data['coolify_server_uuid']
                    ?? $this->defaultServerUuid(),
                'notes' => $data['notes'] ?? null,
            ]);

            $this->syncPrimaryDomain($site, $data['domain']);

            $site->auditLogs()->create([
                'actor_user_id' => $request->user()?->id,
                'action' => 'site.created',
                'after' => $this->auditSnapshot($site->fresh() ?? $site),
                'ip' => $request->ip(),
            ]);

            return $site;
        });

        return redirect()
            ->route('ops.sites.edit', $site)
            ->with('status', 'Draft site saved. Provisioning is Task 4 — no Coolify call was made.');
    }

    public function edit(Request $request, Site $site): View
    {
        $this->authorize('view', $site);

        $site->load('primaryDomainRecord');

        return view('ops.sites.edit', [
            'site' => $site,
            'channels' => config('ops.channels', []),
            'readonly' => ! ($request->user()?->can('update', $site) ?? false),
            'canDelete' => $request->user()?->can('delete', $site) ?? false,
            'channelLocked' => $site->status !== SiteStatus::Draft,
        ]);
    }

    public function update(UpdateSiteRequest $request, Site $site): RedirectResponse
    {
        $data = $request->validated();
        $site->load('primaryDomainRecord');

        DB::transaction(function () use ($request, $site, $data): void {
            $before = $this->auditSnapshot($site);
            $domainChanged = $site->primary_domain !== $data['domain'];

            $payload = [
                'name' => $data['name'],
                'primary_domain' => $data['domain'],
                'coolify_server_uuid' => $data['coolify_server_uuid'] ?? null,
                'notes' => $data['notes'] ?? null,
            ];

            if ($site->status === SiteStatus::Draft) {
                $payload['slug'] = $data['slug'];
                $payload['channel'] = $data['channel'];
            }

            $site->fill($payload);
            $site->save();

            $this->syncPrimaryDomain($site, $data['domain']);

            $after = $this->auditSnapshot($site->fresh() ?? $site);

            $site->auditLogs()->create([
                'actor_user_id' => $request->user()?->id,
                'action' => 'site.updated',
                'before' => $before,
                'after' => $after,
                'ip' => $request->ip(),
            ]);

            if ($domainChanged) {
                $site->auditLogs()->create([
                    'actor_user_id' => $request->user()?->id,
                    'action' => 'site.domain_changed',
                    'before' => ['primary_domain' => $before['primary_domain']],
                    'after' => ['primary_domain' => $after['primary_domain']],
                    'ip' => $request->ip(),
                ]);
            }
        });

        return redirect()
            ->route('ops.sites.edit', $site)
            ->with('status', 'Site desired state updated. Status is unchanged.');
    }

    public function destroy(Request $request, Site $site): RedirectResponse
    {
        $this->authorize('delete', $site);

        DB::transaction(function () use ($request, $site): void {
            $before = $this->auditSnapshot($site);

            $site->auditLogs()->create([
                'actor_user_id' => $request->user()?->id,
                'action' => 'site.deleted',
                'before' => $before,
                'ip' => $request->ip(),
            ]);

            $site->delete();
        });

        return redirect()
            ->route('ops.sites')
            ->with('status', 'Site archived (soft delete). Coolify was not contacted.');
    }

    private function syncPrimaryDomain(Site $site, string $domain): void
    {
        $primary = $site->primaryDomainRecord;

        if ($primary === null) {
            $site->domains()->create([
                'domain' => $domain,
                'is_primary' => true,
            ]);

            $site->unsetRelation('primaryDomainRecord');

            return;
        }

        if ($primary->domain !== $domain) {
            $primary->update(['domain' => $domain]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function auditSnapshot(Site $site): array
    {
        return [
            'slug' => $site->slug,
            'name' => $site->name,
            'primary_domain' => $site->primary_domain,
            'channel' => $site->channel instanceof Channel ? $site->channel->value : $site->channel,
            'status' => $site->status instanceof SiteStatus ? $site->status->value : $site->status,
            'coolify_server_uuid' => $site->coolify_server_uuid,
            'notes' => $site->notes,
        ];
    }

    private function defaultServerUuid(): ?string
    {
        $uuid = trim((string) config('ops.coolify.default_server_uuid'));

        return $uuid === '' ? null : $uuid;
    }
}
