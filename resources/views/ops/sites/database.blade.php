@extends('layouts.ops')

@section('title', __('databases.site.title', ['name' => $site->name]))

@section('content_class', 'ops-content-wide')

@section('breadcrumbs')
    <a href="{{ route('ops.sites') }}">{{ __('ops.nav.sites') }}</a>
    <span aria-hidden="true">/</span>
    <a href="{{ route('ops.sites.show', $site) }}">{{ $site->name }}</a>
    <span aria-hidden="true">/</span>
    <span>{{ __('databases.site.card_title') }}</span>
@endsection

@section('actions')
    @if ($site->usesSharedDatabase() && $site->databaseServer)
        <a class="btn btn-ghost btn-sm" href="{{ route('ops.databases.show', $site->databaseServer) }}">{{ $site->databaseServer->name }}</a>
    @endif
@endsection

@section('content')
    @if (! $site->usesSharedDatabase())
        <div class="empty-panel">
            <h2>{{ __('databases.site.local') }}</h2>
            <p>{{ __('databases.site.local_lede') }}</p>
            <p class="empty-panel-note"><code>{{ __('databases.site.move_hint') }}</code></p>
        </div>
    @else
        <section class="ops-panel">
            <dl class="site-fact-list">
                <div><dt>{{ __('databases.columns.database') }}</dt><dd><code>{{ $site->db_database }}</code></dd></div>
                <div><dt>{{ __('databases.fields.host') }}</dt><dd><code>{{ $site->databaseServer?->host }}</code></dd></div>
                <div><dt>{{ __('databases.columns.tables') }}</dt><dd>{{ count($tables) }}</dd></div>
                <div><dt>{{ __('databases.columns.size') }}</dt><dd>{{ number_format(collect($tables)->sum('size_mb'), 1) }} MB</dd></div>
            </dl>
        </section>

        <section class="ops-panel" aria-labelledby="db-console-heading">
            <h2 id="db-console-heading">{{ __('databases.site.console') }}</h2>
            <p class="field-hint">{{ __('databases.site.console_lede', ['rows' => $maxRows]) }}</p>

            <form method="POST" action="{{ route('ops.sites.database.query', $site) }}" class="ops-form ops-form-stack" data-ops-pending>
                @csrf
                <div class="field">
                    <label class="field-label" for="db-sql">{{ __('databases.site.sql') }}</label>
                    <textarea id="db-sql" class="field-input field-code" name="sql" rows="6" spellcheck="false" autocomplete="off" required>{{ old('sql', $sql) }}</textarea>
                </div>

                @if ($canDanger)
                    <details class="field" @if (old('allow_write')) open @endif>
                        <summary>{{ __('databases.site.allow_write') }}</summary>
                        <label class="field-check">
                            <input type="hidden" name="allow_write" value="0">
                            <input type="checkbox" name="allow_write" value="1" @checked(old('allow_write'))>
                            <span>{{ __('databases.site.allow_write') }}</span>
                        </label>
                        <label class="field-label" for="db-confirm">{{ __('databases.site.confirm', ['database' => $site->db_database]) }}</label>
                        <input id="db-confirm" class="field-input" type="text" name="confirm" value="" autocomplete="off" spellcheck="false">
                    </details>
                @endif

                @if ($error)
                    <p class="field-error" role="alert">{{ $error }}</p>
                @endif

                <div class="form-actions">
                    <button type="submit" class="btn btn-primary" data-pending-label="{{ __('ops.actions.working') }}">{{ __('databases.site.run') }}</button>
                </div>
            </form>

            @if ($result)
                <h3>{{ __('databases.site.result') }}</h3>
                @if ($result->affected !== null)
                    <p class="muted">{{ __('databases.site.result_affected', ['count' => $result->affected, 'ms' => $result->milliseconds]) }}</p>
                @elseif ($result->rows === [])
                    <p class="muted">{{ __('databases.site.result_empty') }} ({{ $result->milliseconds }} ms)</p>
                @else
                    <p class="muted">
                        {{ __('databases.site.result_rows', ['count' => count($result->rows), 'ms' => $result->milliseconds]) }}
                        @if ($result->truncated) {{ __('databases.site.result_truncated', ['rows' => $maxRows]) }} @endif
                    </p>
                    <div class="sites-table-wrap">
                        <table class="ops-table">
                            <thead>
                                <tr>
                                    @foreach ($result->columns as $column)
                                        <th>{{ $column }}</th>
                                    @endforeach
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($result->rows as $row)
                                    <tr>
                                        @foreach ($row as $value)
                                            <td>@if ($value === null)<span class="muted">NULL</span>@else{{ \Illuminate\Support\Str::limit($value, 300) }}@endif</td>
                                        @endforeach
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            @endif
        </section>

        <section class="ops-panel" aria-labelledby="db-tables-heading">
            <h2 id="db-tables-heading">{{ __('databases.site.tables') }}</h2>
            @if ($tablesError)
                <p class="field-error" role="alert">{{ $tablesError }}</p>
            @elseif ($tables === [])
                <p class="muted">{{ __('databases.site.tables_empty') }}</p>
            @else
                <div class="sites-table-wrap">
                    <table class="ops-table">
                        <thead>
                            <tr>
                                <th>{{ __('databases.columns.name') }}</th>
                                <th>{{ __('databases.columns.rows') }}</th>
                                <th>{{ __('databases.columns.size') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($tables as $table)
                                <tr>
                                    <td><code>{{ $table['name'] }}</code></td>
                                    <td class="muted">{{ number_format($table['rows']) }}</td>
                                    <td class="muted">{{ number_format($table['size_mb'], 2) }} MB</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>
    @endif
@endsection
