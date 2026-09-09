<?php

namespace App\Http\Controllers\Ops;

use App\Http\Controllers\Controller;
use App\Models\CoolifyConnection;
use App\Models\CoolifyEnvironment;
use App\Models\CoolifyGitSource;
use App\Models\CoolifyProjectRecord;
use App\Models\CoolifyServer;
use App\Services\Coolify\CoolifyApiException;
use App\Services\Coolify\CoolifyApplicationService;
use App\Services\Coolify\CoolifyClient;
use App\Services\Coolify\CoolifyCredentials;
use App\Services\Coolify\CoolifyInventorySync;
use App\Services\Sites\SiteAttacher;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class CoolifyConnectionController extends Controller
{
    public function index(): View
    {
        $this->authorize('viewAny', CoolifyConnection::class);

        $connections = CoolifyConnection::query()
            ->withCount([
                'servers',
                'servers as active_servers_count' => static fn ($query) => $query->where('is_active', true),
            ])
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get();

        return view('ops.coolify.index', [
            'connections' => $connections,
            'canWrite' => request()->user()?->can('create', CoolifyConnection::class) ?? false,
        ]);
    }

    public function create(): View
    {
        $this->authorize('create', CoolifyConnection::class);

        return view('ops.coolify.create', [
            'connection' => new CoolifyConnection([
                'is_enabled' => true,
                'is_default' => CoolifyConnection::query()->doesntExist(),
            ]),
            'canWrite' => true,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', CoolifyConnection::class);

        $validated = $this->validatedConnection($request, requireToken: true);

        $connection = new CoolifyConnection;
        $this->fillConnection($connection, $validated, $request);
        $connection->save();

        if ($connection->is_default || CoolifyConnection::query()->count() === 1) {
            $connection->markAsDefault();
        }

        return redirect()
            ->route('ops.coolify.show', $connection)
            ->with('status', 'Coolify bağlantısı kaydedildi. Sunucu ve proje listesini senkronlayın.');
    }

    public function show(CoolifyConnection $connection): View
    {
        $this->authorize('view', $connection);

        $connection->load(['servers', 'projects', 'environments', 'gitSources']);

        return view('ops.coolify.show', [
            'connection' => $connection,
            'canWrite' => request()->user()?->can('update', $connection) ?? false,
            'webhookUrl' => url('/webhooks/coolify'),
            'hasToken' => $connection->hasToken(),
            'hasWebhookSecret' => $connection->hasWebhookSecret(),
        ]);
    }

    public function update(Request $request, CoolifyConnection $connection): RedirectResponse
    {
        $this->authorize('update', $connection);

        $validated = $this->validatedConnection($request, requireToken: false);
        $this->fillConnection($connection, $validated, $request);

        $connection->default_project_uuid = $this->nullableString($request->input('default_project_uuid'));
        $connection->default_server_uuid = $this->nullableString($request->input('default_server_uuid'));
        $connection->default_environment_uuid = $this->nullableString($request->input('default_environment_uuid'));
        $connection->default_environment_name = $this->nullableString($request->input('default_environment_name'));

        $git = $this->nullableString($request->input('default_git_source'));
        if ($git !== null && str_contains($git, ':')) {
            [$kind, $uuid] = explode(':', $git, 2);
            $connection->default_git_source_kind = $kind;
            $connection->default_git_source_uuid = $uuid;
        } elseif ($git === null) {
            $connection->default_git_source_kind = null;
            $connection->default_git_source_uuid = null;
        }

        $connection->save();

        if ($request->boolean('is_default')) {
            $connection->markAsDefault();
        }

        return back()->with('status', 'Coolify bağlantısı güncellendi.');
    }

    public function destroy(CoolifyConnection $connection): RedirectResponse
    {
        $this->authorize('delete', $connection);

        $wasDefault = $connection->is_default;
        $connection->delete();

        if ($wasDefault) {
            CoolifyConnection::query()->where('is_enabled', true)->orderBy('id')->first()?->markAsDefault();
        }

        return redirect()
            ->route('ops.coolify.index')
            ->with('status', 'Coolify bağlantısı koparıldı. Coolify uygulamaları silinmedi.');
    }

    public function test(Request $request, CoolifyConnection $connection): RedirectResponse
    {
        $this->authorize('test', $connection);

        $base = $this->nullableUrl($request->input('base_url')) ?: (string) $connection->base_url;
        $token = filled($request->input('api_token'))
            ? (string) $request->input('api_token')
            : (string) $connection->api_token;

        if ($base === '' || $token === '') {
            return back()->with('error', 'Test için Coolify URL ve API token gerekli.');
        }

        try {
            $client = new CoolifyApplicationService(
                new CoolifyClient(new CoolifyCredentials($base, $token)),
            );
            $servers = $client->listServers();
        } catch (CoolifyApiException $exception) {
            return back()->with('error', 'Coolify bağlantısı başarısız: '.$exception->getMessage());
        }

        return back()->with('status', 'Coolify bağlantısı OK — '.$servers->count().' sunucu.');
    }

    public function sync(CoolifyConnection $connection, CoolifyInventorySync $sync): RedirectResponse
    {
        $this->authorize('sync', $connection);

        if (! $connection->hasToken()) {
            return back()->with('error', 'Senkron için API token kaydedin.');
        }

        try {
            $result = $sync->sync($connection);
        } catch (CoolifyApiException $exception) {
            return back()->with('error', 'Coolify senkron başarısız: '.$exception->getMessage());
        }

        $githubNote = $result['github_apps_available']
            ? ''
            : ' GitHub App listesi bu instance’ta yok — deploy key’leri kullanın veya Super Admin gelişmiş alandan UUID yapıştırın.';

        return back()->with(
            'status',
            'Senkron: '.$result['servers'].' sunucu, '.$result['projects'].' proje, '.$result['environments'].' ortam, '.$result['git_sources'].' Git kaynağı.'.$githubNote,
        );
    }

    public function makeDefault(CoolifyConnection $connection): RedirectResponse
    {
        $this->authorize('update', $connection);

        $connection->markAsDefault();

        return back()->with('status', 'Yeni siteler bu Coolify bağlantısını varsayılan kullanır.');
    }

    public function toggleServer(CoolifyConnection $connection, CoolifyServer $server): RedirectResponse
    {
        $this->authorize('update', $connection);
        $this->assertChild($connection, $server->coolify_connection_id);

        $server->is_active = ! $server->is_active;
        $server->save();

        return back()->with('status', $server->is_active ? 'Sunucu aktif.' : 'Sunucu pasif. Site oluştururken seçilemez.');
    }

    public function toggleProject(CoolifyConnection $connection, CoolifyProjectRecord $project): RedirectResponse
    {
        $this->authorize('update', $connection);
        $this->assertChild($connection, $project->coolify_connection_id);

        $project->is_active = ! $project->is_active;
        $project->save();

        return back()->with('status', $project->is_active ? 'Proje aktif.' : 'Proje pasif.');
    }

    public function toggleEnvironment(CoolifyConnection $connection, CoolifyEnvironment $environment): RedirectResponse
    {
        $this->authorize('update', $connection);
        $this->assertChild($connection, $environment->coolify_connection_id);

        $environment->is_active = ! $environment->is_active;
        $environment->save();

        return back()->with('status', $environment->is_active ? 'Ortam aktif.' : 'Ortam pasif.');
    }

    public function toggleGitSource(CoolifyConnection $connection, CoolifyGitSource $source): RedirectResponse
    {
        $this->authorize('update', $connection);
        $this->assertChild($connection, $source->coolify_connection_id);

        $source->is_active = ! $source->is_active;
        $source->save();

        return back()->with('status', $source->is_active ? 'Git kaynağı aktif.' : 'Git kaynağı pasif.');
    }

    public function options(CoolifyConnection $connection, SiteAttacher $attacher): JsonResponse
    {
        $this->authorize('view', $connection);

        $connection->load(['servers', 'projects', 'environments', 'gitSources']);

        return response()->json([
            'id' => $connection->id,
            'defaults' => [
                'server' => $connection->default_server_uuid,
                'project' => $connection->default_project_uuid,
                'environment' => $connection->default_environment_uuid,
                'git' => $connection->default_git_source_kind && $connection->default_git_source_uuid
                    ? $connection->default_git_source_kind->value.':'.$connection->default_git_source_uuid
                    : null,
            ],
            'servers' => $connection->servers->where('is_active', true)->values()->map(fn (CoolifyServer $row) => [
                'uuid' => $row->uuid,
                'label' => $row->label(),
            ]),
            'projects' => $connection->projects->where('is_active', true)->values()->map(fn (CoolifyProjectRecord $row) => [
                'uuid' => $row->uuid,
                'label' => $row->label(),
            ]),
            'environments' => $connection->environments->where('is_active', true)->values()->map(fn (CoolifyEnvironment $row) => [
                'uuid' => $row->uuid,
                'project_uuid' => $row->project_uuid,
                'label' => $row->label(),
            ]),
            'git_sources' => $connection->gitSources->where('is_active', true)->values()->map(fn (CoolifyGitSource $row) => [
                'value' => $row->formValue(),
                'label' => $row->label(),
            ]),
            'github_apps_list_available' => $connection->github_apps_list_available,
            'apps' => $attacher->attachableApps($connection),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedConnection(Request $request, bool $requireToken): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'base_url' => ['required', 'string', 'max:255'],
            'api_token' => [$requireToken ? 'required' : 'nullable', 'string', 'max:2000'],
            'webhook_secret' => ['nullable', 'string', 'max:2000'],
            'is_enabled' => ['sometimes', 'boolean'],
            'is_default' => ['sometimes', 'boolean'],
        ]);
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function fillConnection(CoolifyConnection $connection, array $validated, Request $request): void
    {
        $connection->name = trim((string) $validated['name']);
        $connection->base_url = $this->nullableUrl($validated['base_url'] ?? null);
        $connection->is_enabled = $request->boolean('is_enabled', true);

        if (filled($validated['api_token'] ?? null)) {
            $connection->api_token = $validated['api_token'];
        }

        if (filled($validated['webhook_secret'] ?? null)) {
            $connection->webhook_secret = $validated['webhook_secret'];
        }
    }

    private function assertChild(CoolifyConnection $connection, ?int $childConnectionId): void
    {
        if ((int) $childConnectionId !== (int) $connection->id) {
            abort(404);
        }
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed !== '' ? $trimmed : null;
    }

    private function nullableUrl(mixed $value): ?string
    {
        $url = $this->nullableString($value);
        if ($url === null) {
            return null;
        }

        return CoolifyCredentials::normalizeBaseUrl($url) ?: null;
    }
}
