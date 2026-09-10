@extends('layouts.ops')

@section('title', $connection->name)

@section('content_class', 'ops-content-wide')

@section('breadcrumbs')
    <a href="{{ route('ops.coolify.index') }}">{{ __('coolify.title') }}</a>
    <span aria-hidden="true">/</span>
    <span>{{ $connection->name }}</span>
@endsection

@section('actions')
    <a class="btn btn-ghost btn-sm" href="{{ route('ops.coolify.index') }}">{{ __('coolify.back') }}</a>
    @if ($canWrite)
        <form method="POST" action="{{ route('ops.coolify.sync', $connection) }}" data-ops-pending>
            @csrf
            <button type="submit" class="btn btn-primary btn-sm" data-pending-label="{{ __('ops.actions.working') }}">{{ __('coolify.show.sync') }}</button>
        </form>
    @endif
@endsection

@section('content')
    <p class="page-lede">{{ __('coolify.show.lede') }}</p>

    <section class="settings-panel" aria-labelledby="coolify-creds-heading">
        <h2 id="coolify-creds-heading">{{ __('coolify.show.connection') }}</h2>
        <form method="POST" action="{{ route('ops.coolify.update', $connection) }}" class="ops-form settings-form">
            @csrf
            @method('PUT')
            @include('ops.coolify._connection-fields', ['connection' => $connection, 'canWrite' => $canWrite, 'requireToken' => false])

            <div class="field">
                <span class="field-label">{{ __('coolify.show.webhook') }}</span>
                <input class="field-input" type="text" value="{{ $webhookUrl }}" readonly>
            </div>

            @if ($canWrite)
                <div class="form-actions">
                    <button type="submit" class="btn btn-primary">{{ __('coolify.show.save') }}</button>
                </div>
            @else
                <p class="field-hint">{{ __('ops.viewer_readonly') }}</p>
            @endif
        </form>
        @if ($canWrite)
            <div class="form-actions">
                <form method="POST" action="{{ route('ops.coolify.test', $connection) }}" data-ops-pending>
                    @csrf
                    <button type="submit" class="btn btn-secondary" data-pending-label="{{ __('ops.actions.working') }}">{{ __('coolify.show.test') }}</button>
                </form>
                <form method="POST" action="{{ route('ops.coolify.sync', $connection) }}" data-ops-pending>
                    @csrf
                    <button type="submit" class="btn btn-secondary" data-pending-label="{{ __('ops.actions.working') }}">{{ __('coolify.show.sync') }}</button>
                </form>
                @unless ($connection->is_default)
                    <form method="POST" action="{{ route('ops.coolify.default', $connection) }}">
                        @csrf
                        <button type="submit" class="btn btn-ghost">{{ __('coolify.show.make_default') }}</button>
                    </form>
                @endunless
            </div>
        @endif
    </section>

    @if ($connection->github_apps_list_available === false)
        <p class="ops-alert ops-alert-warning" role="status">
            {{ __('coolify.show.github_apps_missing') }}
        </p>
    @endif

    <section class="ops-panel" aria-labelledby="coolify-defaults-heading">
        <h2 id="coolify-defaults-heading">{{ __('coolify.show.defaults') }}</h2>
        <p>{{ __('coolify.show.defaults_lede') }}</p>
        <form method="POST" action="{{ route('ops.coolify.update', $connection) }}" class="ops-form settings-form">
            @csrf
            @method('PUT')
            <input type="hidden" name="name" value="{{ $connection->name }}">
            <input type="hidden" name="base_url" value="{{ $connection->base_url }}">
            <input type="hidden" name="is_enabled" value="{{ $connection->is_enabled ? '1' : '0' }}">
            <input type="hidden" name="is_default" value="{{ $connection->is_default ? '1' : '0' }}">

            <div class="field">
                <label class="field-label" for="default-server">{{ __('coolify.show.default_server') }}</label>
                <select id="default-server" class="field-input" name="default_server_uuid" data-coolify-servers @disabled(! $canWrite)>
                    <option value="">{{ __('ops.none') }}</option>
                    @foreach ($connection->servers->where('is_active', true) as $server)
                        <option value="{{ $server->uuid }}" title="{{ $server->uuid }}" @selected($connection->default_server_uuid === $server->uuid)>{{ $server->label() }}</option>
                    @endforeach
                </select>
            </div>
            <div class="field">
                <label class="field-label" for="default-project">{{ __('coolify.show.default_project') }}</label>
                <select id="default-project" class="field-input" name="default_project_uuid" data-coolify-projects @disabled(! $canWrite)>
                    <option value="">{{ __('ops.none') }}</option>
                    @foreach ($connection->projects->where('is_active', true) as $project)
                        <option value="{{ $project->uuid }}" title="{{ $project->uuid }}" @selected($connection->default_project_uuid === $project->uuid)>{{ $project->label() }}</option>
                    @endforeach
                </select>
            </div>
            <div class="field">
                <label class="field-label" for="default-env">{{ __('coolify.show.default_env') }}</label>
                <select
                    id="default-env"
                    class="field-input"
                    name="default_environment_uuid"
                    data-coolify-environments
                    data-environment-options="{{ Js::from($environmentOptions ?? []) }}"
                    @disabled(! $canWrite)
                >
                    <option value="">{{ __('ops.none') }}</option>
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
                <label class="field-label" for="default-git">{{ __('coolify.show.default_git') }}</label>
                <p class="field-hint">{{ __('coolify.show.default_git_hint') }}</p>
                <select id="default-git" class="field-input" name="default_git_source" @disabled(! $canWrite)>
                    <option value="">{{ __('coolify.show.git_none') }}</option>
                    @foreach ($connection->gitSources->where('is_active', true) as $source)
                        <option value="{{ $source->formValue() }}" @selected($connection->default_git_source_uuid === $source->uuid && (string) $connection->default_git_source_kind?->value === $source->kind->value)>{{ $source->label() }}</option>
                    @endforeach
                </select>
            </div>
            @if ($canWrite)
                <div class="form-actions">
                    <button type="submit" class="btn btn-primary">{{ __('coolify.show.save_defaults') }}</button>
                </div>
            @endif
        </form>
    </section>

    @include('ops.coolify._allowlist', [
        'title' => __('coolify.allowlist.servers'),
        'hint' => __('coolify.allowlist.servers_hint'),
        'rows' => $connection->servers,
        'toggleRoute' => 'ops.coolify.servers.toggle',
        'param' => 'server',
        'extra' => 'ip',
        'canWrite' => $canWrite,
        'connection' => $connection,
    ])

    @include('ops.coolify._allowlist', [
        'title' => __('coolify.allowlist.projects'),
        'hint' => __('coolify.allowlist.projects_hint'),
        'rows' => $connection->projects,
        'toggleRoute' => 'ops.coolify.projects.toggle',
        'param' => 'project',
        'canWrite' => $canWrite,
        'connection' => $connection,
    ])

    @include('ops.coolify._allowlist', [
        'title' => __('coolify.allowlist.environments'),
        'hint' => __('coolify.allowlist.environments_hint'),
        'rows' => $connection->environments,
        'toggleRoute' => 'ops.coolify.environments.toggle',
        'param' => 'environment',
        'extra' => 'project_uuid',
        'canWrite' => $canWrite,
        'connection' => $connection,
    ])

    @include('ops.coolify._allowlist', [
        'title' => __('coolify.allowlist.git'),
        'hint' => __('coolify.allowlist.git_hint'),
        'rows' => $connection->gitSources,
        'toggleRoute' => 'ops.coolify.git-sources.toggle',
        'param' => 'source',
        'extra' => 'kind',
        'canWrite' => $canWrite,
        'connection' => $connection,
    ])

    @if ($canWrite)
        <div class="danger-zone">
            <h2>{{ __('coolify.danger.title') }}</h2>
            <p>{{ __('coolify.danger.lede') }}</p>
            <form
                method="POST"
                action="{{ route('ops.coolify.destroy', $connection) }}"
                data-confirm="{{ __('coolify.danger.confirm', ['name' => $connection->name]) }}"
                data-confirm-title="{{ __('coolify.danger.confirm_title') }}"
                data-confirm-label="{{ __('coolify.danger.label') }}"
            >
                @csrf
                @method('DELETE')
                <button type="submit" class="btn btn-danger">{{ __('coolify.danger.button') }}</button>
            </form>
        </div>
    @endif
@endsection

@section('scripts')
    <script src="{{ asset('js/ops-coolify-form.js') }}" defer></script>
@endsection
