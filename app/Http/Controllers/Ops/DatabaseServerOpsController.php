<?php

namespace App\Http\Controllers\Ops;

use App\Http\Controllers\Controller;
use App\Models\DatabaseServer;
use App\Models\RedisServer;
use App\Services\Databases\DatabaseServerClient;
use App\Services\Databases\DatabaseServerException;
use App\Services\Databases\RedisServerClient;
use App\Services\Databases\RedisServerException;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class DatabaseServerOpsController extends Controller
{
    public function index(Request $request): View
    {
        return view('ops.databases.index', [
            'servers' => DatabaseServer::query()->withCount('sites')->orderByDesc('is_default')->orderBy('name')->get(),
            'redisServers' => RedisServer::query()
                ->withCount(['sites as shared_sites_count' => fn ($query) => $query->where('redis_shared', true)])
                ->orderByDesc('is_default')->orderBy('name')->get(),
            'canWrite' => $request->user()?->can('ops.write') ?? false,
            'canDanger' => $request->user()?->can('ops.danger') ?? false,
        ]);
    }

    /**
     * Re-read INFO of a shared Redis server (servers are registered with ops:redis-server).
     */
    public function probeRedis(RedisServer $redisServer, RedisServerClient $client): RedirectResponse
    {
        $this->authorize('ops.write');

        $redirect = redirect()->to(route('ops.databases.index').'#redis');

        try {
            $client->refresh($redisServer);
        } catch (RedisServerException $e) {
            return $redirect->with('error', __('databases.errors.probe_failed', ['message' => $e->getMessage()]));
        }

        return $redirect->with('status', __('databases.redis.flash.probed', ['name' => $redisServer->name]));
    }

    public function create(): View
    {
        // Holds an admin login that can read every site database on the server.
        $this->authorize('ops.danger');

        return view('ops.databases.create', [
            'server' => new DatabaseServer(['port' => 3306, 'admin_username' => 'plane_admin']),
        ]);
    }

    public function store(Request $request, DatabaseServerClient $client): RedirectResponse
    {
        $this->authorize('ops.danger');

        $server = new DatabaseServer;
        $this->fillServer($server, $this->validated($request, requirePassword: true));
        $server->save();
        $this->audit($server, $request, 'database_server.created', null, $this->auditSnapshot($server));

        return $this->probeAndRedirect($server, $client, __('databases.flash.saved'));
    }

    public function show(Request $request, DatabaseServer $databaseServer): View
    {
        $sites = $databaseServer->sites()->orderBy('name')->get();
        $bySchema = $sites->keyBy('db_database');

        $databases = [];
        foreach ($databaseServer->probedDatabases() as $schema => $row) {
            $databases[] = $row + ['name' => $schema, 'site' => $bySchema->get($schema)];
        }

        return view('ops.databases.show', [
            'server' => $databaseServer,
            'databases' => $databases,
            'sites' => $sites,
            'canWrite' => $request->user()?->can('ops.write') ?? false,
            'canDanger' => $request->user()?->can('ops.danger') ?? false,
        ]);
    }

    public function update(Request $request, DatabaseServer $databaseServer, DatabaseServerClient $client): RedirectResponse
    {
        $this->authorize('ops.danger');

        $before = $this->auditSnapshot($databaseServer);
        $this->fillServer($databaseServer, $this->validated($request, requirePassword: false));
        $databaseServer->save();
        $this->audit($databaseServer, $request, 'database_server.updated', $before, $this->auditSnapshot($databaseServer));

        return $this->probeAndRedirect($databaseServer, $client, __('databases.flash.saved'));
    }

    public function probe(Request $request, DatabaseServer $databaseServer, DatabaseServerClient $client): RedirectResponse
    {
        $this->authorize('ops.write');

        return $this->probeAndRedirect($databaseServer, $client, null);
    }

    public function destroy(Request $request, DatabaseServer $databaseServer): RedirectResponse
    {
        $this->authorize('ops.danger');

        if ($databaseServer->sites()->exists()) {
            return back()->with('error', __('databases.errors.has_sites'));
        }

        $this->audit($databaseServer, $request, 'database_server.deleted', $this->auditSnapshot($databaseServer), null);
        $databaseServer->delete();

        return redirect()->route('ops.databases.index')->with('status', __('databases.flash.deleted'));
    }

    private function probeAndRedirect(DatabaseServer $server, DatabaseServerClient $client, ?string $saved): RedirectResponse
    {
        $redirect = redirect()->route('ops.databases.show', $server);

        if ($saved !== null) {
            $redirect->with('status', $saved);
        }

        try {
            $client->refresh($server);
        } catch (DatabaseServerException $e) {
            return $redirect->with('error', __('databases.errors.probe_failed', ['message' => $e->getMessage()]));
        }

        return $saved !== null
            ? $redirect
            : $redirect->with('status', __('databases.flash.probed', ['count' => count($server->probedDatabases())]));
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, bool $requirePassword): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'host' => ['required', 'string', 'max:190', 'regex:/^[A-Za-z0-9.-]+$/'],
            'port' => ['required', 'integer', 'between:1,65535'],
            'admin_username' => ['required', 'string', 'max:32', 'regex:/^[A-Za-z0-9_]+$/'],
            'admin_password' => [$requirePassword ? 'required' : 'nullable', 'string', 'max:200'],
            'is_default' => ['sometimes', 'boolean'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function fillServer(DatabaseServer $server, array $validated): void
    {
        $server->name = $validated['name'];
        $server->host = strtolower($validated['host']);
        $server->port = (int) $validated['port'];
        $server->admin_username = $validated['admin_username'];
        $server->notes = $validated['notes'] ?? null;
        if (filled($validated['admin_password'] ?? null)) {
            $server->admin_password = $validated['admin_password'];
        }

        $default = (bool) ($validated['is_default'] ?? false);
        if ($default) {
            DatabaseServer::query()->whereKeyNot($server->getKey() ?? '')->update(['is_default' => false]);
        }
        $server->is_default = $default;
    }

    /**
     * @return array<string, mixed>
     */
    private function auditSnapshot(DatabaseServer $server): array
    {
        return [
            'name' => $server->name,
            'host' => $server->host,
            'port' => $server->port,
            'admin_username' => $server->admin_username,
            'is_default' => $server->is_default,
        ];
    }

    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     */
    private function audit(DatabaseServer $server, Request $request, string $action, ?array $before, ?array $after): void
    {
        $server->auditLogs()->create([
            'actor_user_id' => $request->user()?->id,
            'action' => $action,
            'before' => $before,
            'after' => $after,
            'ip' => $request->ip(),
        ]);
    }
}
