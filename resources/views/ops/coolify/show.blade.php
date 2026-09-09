@extends('layouts.ops')

@section('title', $connection->name)

@section('actions')
    <a class="btn btn-ghost btn-sm" href="{{ route('ops.coolify.index') }}">Tüm Coolify</a>
    @if ($canWrite)
        <form method="POST" action="{{ route('ops.coolify.sync', $connection) }}">
            @csrf
            <button type="submit" class="btn btn-primary btn-sm">Sync</button>
        </form>
    @endif
@endsection

@section('content')
    <p class="page-lede">
        Token şifreli. Test <code>listServers</code> kullanır.
        Senkron sunucu / proje / ortam / Git kaynaklarını çeker; aktif olanlar site formunda görünür.
    </p>

    <section class="settings-panel" aria-labelledby="coolify-creds-heading">
        <h2 id="coolify-creds-heading">Bağlantı</h2>
        <form method="POST" action="{{ route('ops.coolify.update', $connection) }}" class="ops-form settings-form">
            @csrf
            @method('PUT')
            @include('ops.coolify._connection-fields', ['connection' => $connection, 'canWrite' => $canWrite, 'requireToken' => false])

            <div class="field">
                <span class="field-label">Deploy webhook URL</span>
                <input class="field-input" type="text" value="{{ $webhookUrl }}" readonly>
            </div>

            @if ($canWrite)
                <div class="form-actions">
                    <button type="submit" class="btn btn-primary">Kaydet</button>
                </div>
            @else
                <p class="field-hint">Viewer salt okunur.</p>
            @endif
        </form>
        @if ($canWrite)
            <div class="form-actions">
                <form method="POST" action="{{ route('ops.coolify.test', $connection) }}">
                    @csrf
                    <button type="submit" class="btn btn-secondary">Bağlantıyı test et</button>
                </form>
                <form method="POST" action="{{ route('ops.coolify.sync', $connection) }}">
                    @csrf
                    <button type="submit" class="btn btn-secondary">Sync</button>
                </form>
                @unless ($connection->is_default)
                    <form method="POST" action="{{ route('ops.coolify.default', $connection) }}">
                        @csrf
                        <button type="submit" class="btn btn-ghost">Varsayılan yap</button>
                    </form>
                @endunless
            </div>
        @endif
    </section>

    @if ($connection->github_apps_list_available === false)
        <p class="ops-alert ops-alert-warning" role="status">
            Bu Coolify instance GitHub App listesi (<code>GET /github-apps</code>) vermiyor.
            Deploy key’ler <code>/security/keys</code> ile gelir. GitHub App UUID için Super Admin gelişmiş alan
            veya Coolify UI. Bu, Plane’deki tema kataloğu GitHub App’i <strong>değil</strong>.
        </p>
    @endif

    <section class="ops-panel" aria-labelledby="coolify-defaults-heading">
        <h2 id="coolify-defaults-heading">Site kurma varsayılanları</h2>
        <p>Yeni site formu bu seçimleri doldurur. Hepsi aktif listeden.</p>
        <form method="POST" action="{{ route('ops.coolify.update', $connection) }}" class="ops-form settings-form">
            @csrf
            @method('PUT')
            <input type="hidden" name="name" value="{{ $connection->name }}">
            <input type="hidden" name="base_url" value="{{ $connection->base_url }}">
            <input type="hidden" name="is_enabled" value="{{ $connection->is_enabled ? '1' : '0' }}">
            <input type="hidden" name="is_default" value="{{ $connection->is_default ? '1' : '0' }}">

            <div class="field">
                <label class="field-label" for="default-server">Varsayılan sunucu</label>
                <select id="default-server" class="field-input" name="default_server_uuid" data-coolify-servers @disabled(! $canWrite)>
                    <option value="">—</option>
                    @foreach ($connection->servers->where('is_active', true) as $server)
                        <option value="{{ $server->uuid }}" title="{{ $server->uuid }}" @selected($connection->default_server_uuid === $server->uuid)>{{ $server->label() }}</option>
                    @endforeach
                </select>
            </div>
            <div class="field">
                <label class="field-label" for="default-project">Varsayılan proje</label>
                <select id="default-project" class="field-input" name="default_project_uuid" data-coolify-projects @disabled(! $canWrite)>
                    <option value="">—</option>
                    @foreach ($connection->projects->where('is_active', true) as $project)
                        <option value="{{ $project->uuid }}" title="{{ $project->uuid }}" @selected($connection->default_project_uuid === $project->uuid)>{{ $project->label() }}</option>
                    @endforeach
                </select>
            </div>
            <div class="field">
                <label class="field-label" for="default-env">Varsayılan ortam</label>
                <select
                    id="default-env"
                    class="field-input"
                    name="default_environment_uuid"
                    data-coolify-environments
                    data-environment-options="{{ Js::from($environmentOptions ?? []) }}"
                    @disabled(! $canWrite)
                >
                    <option value="">—</option>
                    @foreach ($connection->environmentsForProject($connection->default_project_uuid) as $environment)
                        <option
                            value="{{ $environment->uuid }}"
                            data-project="{{ $environment->project_uuid }}"
                            title="{{ $environment->uuid }}"
                            @selected($connection->default_environment_uuid === $environment->uuid)
                        >{{ $environment->label() }}</option>
                    @endforeach
                </select>
                <input type="hidden" name="default_environment_name" value="{{ $connection->default_environment_name }}">
            </div>
            <div class="field">
                <label class="field-label" for="default-git">Varsayılan Git (Coolify)</label>
                <p class="field-hint">Tema kataloğu GitHub App’i değil. Coolify’deki GitHub App veya deploy key.</p>
                <select id="default-git" class="field-input" name="default_git_source" @disabled(! $canWrite)>
                    <option value="">— public / yok</option>
                    @foreach ($connection->gitSources->where('is_active', true) as $source)
                        <option value="{{ $source->formValue() }}" @selected($connection->default_git_source_uuid === $source->uuid && (string) $connection->default_git_source_kind?->value === $source->kind->value)>{{ $source->label() }}</option>
                    @endforeach
                </select>
            </div>
            @if ($canWrite)
                <div class="form-actions">
                    <button type="submit" class="btn btn-primary">Varsayılanları kaydet</button>
                </div>
            @endif
        </form>
    </section>

    @include('ops.coolify._allowlist', [
        'title' => 'Sunucular',
        'hint' => 'Pasif sunucu site oluştururken seçilemez.',
        'rows' => $connection->servers,
        'toggleRoute' => 'ops.coolify.servers.toggle',
        'param' => 'server',
        'canWrite' => $canWrite,
        'connection' => $connection,
    ])

    @include('ops.coolify._allowlist', [
        'title' => 'Projeler',
        'hint' => 'Coolify project. Ortamlar proje altında.',
        'rows' => $connection->projects,
        'toggleRoute' => 'ops.coolify.projects.toggle',
        'param' => 'project',
        'canWrite' => $canWrite,
        'connection' => $connection,
    ])

    @include('ops.coolify._allowlist', [
        'title' => 'Ortamlar',
        'hint' => 'Coolify environment (git channel değil).',
        'rows' => $connection->environments,
        'toggleRoute' => 'ops.coolify.environments.toggle',
        'param' => 'environment',
        'extra' => 'project_uuid',
        'canWrite' => $canWrite,
        'connection' => $connection,
    ])

    @include('ops.coolify._allowlist', [
        'title' => 'Git kaynakları',
        'hint' => 'Coolify GitHub App ve deploy key. Plane tema kataloğu değil.',
        'rows' => $connection->gitSources,
        'toggleRoute' => 'ops.coolify.git-sources.toggle',
        'param' => 'source',
        'extra' => 'kind',
        'canWrite' => $canWrite,
        'connection' => $connection,
    ])

    @if ($canWrite)
        <div class="danger-zone">
            <h2>Bağlantıyı kes</h2>
            <p>Plane kaydı silinir. Coolify’deki uygulamalar silinmez. SSH yok.</p>
            <form
                method="POST"
                action="{{ route('ops.coolify.destroy', $connection) }}"
                data-confirm="{{ $connection->name }} bağlantısını kes? Coolify uygulamaları silinmez."
                data-confirm-title="Coolify bağlantısını kes"
                data-confirm-label="Kes"
            >
                @csrf
                @method('DELETE')
                <button type="submit" class="btn btn-danger">Bağlantıyı kes</button>
            </form>
        </div>
    @endif
@endsection

@section('scripts')
    <script src="{{ asset('js/ops-coolify-form.js') }}" defer></script>
@endsection
