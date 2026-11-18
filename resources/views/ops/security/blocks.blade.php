@extends('layouts.ops')

@section('title', __('security.blocks.title'))

@section('content_class', 'ops-content-wide')

@section('content')
    <p class="page-lede">{{ __('security.blocks.lede') }}</p>

    @include('ops.security._tabs')

    @if (! $canWrite)
        <div class="ops-alert ops-alert-warning" role="status">{{ __('security.blocks.read_only') }}</div>
    @endif

    <section class="ops-panel" aria-labelledby="security-blocks-heading">
        <h2 id="security-blocks-heading">{{ __('security.blocks.active') }}</h2>

        @if ($canWrite)
            <form method="POST" action="{{ route('ops.security.block') }}" class="security-add-form" data-ops-pending
                data-confirm="{{ __('security.blocks.block_confirm', ['ip' => __('security.blocks.ip')]) }}"
                data-confirm-title="{{ __('security.blocks.block_confirm_title') }}"
                data-confirm-label="{{ __('security.blocks.block_button') }}">
                @csrf
                <label>
                    <span class="visually-hidden">{{ __('security.blocks.ip') }}</span>
                    <input class="field-input" name="ip" value="{{ old('ip') }}" placeholder="{{ __('security.blocks.ip') }}" required maxlength="64" autocomplete="off">
                </label>
                <label>
                    <span class="visually-hidden">{{ __('security.blocks.reason') }}</span>
                    <input class="field-input" name="reason" value="{{ old('reason') }}" placeholder="{{ __('security.blocks.reason') }}" maxlength="255">
                </label>
                <label>
                    <span class="visually-hidden">{{ __('security.blocks.hours') }}</span>
                    <input class="field-input" type="number" name="hours" min="1" max="8760" placeholder="{{ __('security.blocks.hours') }}">
                </label>
                <button type="submit" class="btn btn-primary btn-sm" data-pending-label="{{ __('ops.actions.working') }}">{{ __('security.blocks.block_button') }}</button>
            </form>
        @endif

        @if ($blocks->isEmpty())
            <p class="muted">{{ __('security.blocks.no_blocks') }}</p>
        @else
            <table class="ops-table">
                <thead>
                    <tr>
                        <th scope="col">{{ __('security.cols.ip') }}</th>
                        <th scope="col">{{ __('security.cols.reason') }}</th>
                        <th scope="col">{{ __('security.cols.expires') }}</th>
                        <th scope="col">{{ __('security.cols.state') }}</th>
                        <th scope="col">{{ __('security.cols.by') }}</th>
                        <th scope="col"><span class="visually-hidden">{{ __('security.blocks.release_button') }}</span></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($blocks as $block)
                        <tr>
                            <td><code>{{ $block->ip }}</code></td>
                            <td>{{ $block->reason ?? '-' }}</td>
                            <td>{{ $block->expires_at ? $block->expires_at->format('Y-m-d H:i') : __('security.blocks.indefinite') }}</td>
                            <td><span class="status-chip status-{{ $block->status === 'applied' ? 'ok' : ($block->status === 'pending' ? 'in_progress' : 'error') }}">{{ __('security.blocks.status.'.$block->status) }}</span></td>
                            <td class="muted">{{ $block->creator?->name ?? '-' }}</td>
                            <td class="ops-row-actions">
                                @if ($canWrite)
                                    <form method="POST" action="{{ route('ops.security.release') }}" data-ops-pending
                                        data-confirm="{{ __('security.blocks.release_confirm', ['ip' => $block->ip]) }}"
                                        data-confirm-title="{{ __('security.blocks.release_confirm_title') }}"
                                        data-confirm-label="{{ __('security.blocks.release_button') }}">
                                        @csrf
                                        <input type="hidden" name="ip" value="{{ $block->ip }}">
                                        <button type="submit" class="btn btn-secondary btn-sm" data-pending-label="{{ __('ops.actions.working') }}">{{ __('security.blocks.release_button') }}</button>
                                    </form>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif

        @if ($recentReleased->isNotEmpty())
            <h3>{{ __('security.blocks.recent_released') }}</h3>
            <ul class="muted">
                @foreach ($recentReleased as $block)
                    <li><code>{{ $block->ip }}</code> · {{ $block->released_at?->format('Y-m-d H:i') }}</li>
                @endforeach
            </ul>
        @endif
    </section>

    <section class="ops-panel" aria-labelledby="security-allow-heading">
        <h2 id="security-allow-heading">{{ __('security.blocks.allowlist') }}</h2>

        @if ($canWrite)
            <form method="POST" action="{{ route('ops.security.allow') }}" class="security-add-form" data-ops-pending>
                @csrf
                <label>
                    <span class="visually-hidden">{{ __('security.blocks.ip') }}</span>
                    <input class="field-input" name="ip" placeholder="{{ __('security.blocks.ip') }}" required maxlength="64" autocomplete="off">
                </label>
                <label>
                    <span class="visually-hidden">{{ __('security.blocks.allow_label') }}</span>
                    <input class="field-input" name="label" placeholder="{{ __('security.blocks.allow_label') }}" maxlength="120">
                </label>
                <button type="submit" class="btn btn-primary btn-sm" data-pending-label="{{ __('ops.actions.working') }}">{{ __('security.blocks.allow_button') }}</button>
            </form>
        @endif

        @if ($allowlist->isEmpty())
            <p class="muted">{{ __('security.blocks.no_allowlist') }}</p>
        @else
            <table class="ops-table">
                <thead>
                    <tr>
                        <th scope="col">{{ __('security.cols.ip') }}</th>
                        <th scope="col">{{ __('security.cols.label') }}</th>
                        <th scope="col">{{ __('security.cols.state') }}</th>
                        <th scope="col">{{ __('security.cols.by') }}</th>
                        <th scope="col"><span class="visually-hidden">{{ __('security.blocks.remove_button') }}</span></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($allowlist as $entry)
                        <tr>
                            <td><code>{{ $entry->ip }}</code></td>
                            <td>{{ $entry->label ?? '-' }}</td>
                            <td><span class="status-chip status-{{ $entry->status === 'applied' ? 'ok' : ($entry->status === 'pending' ? 'in_progress' : 'error') }}">{{ __('security.blocks.status.'.$entry->status) }}</span></td>
                            <td class="muted">{{ $entry->creator?->name ?? '-' }}</td>
                            <td class="ops-row-actions">
                                @if ($canRemoveAllow)
                                    <form method="POST" action="{{ route('ops.security.allow.remove') }}" data-ops-pending
                                        data-confirm="{{ __('security.blocks.remove_confirm', ['ip' => $entry->ip]) }}"
                                        data-confirm-title="{{ __('security.blocks.remove_confirm_title') }}"
                                        data-confirm-label="{{ __('security.blocks.remove_button') }}" data-confirm-danger="true">
                                        @csrf
                                        <input type="hidden" name="ip" value="{{ $entry->ip }}">
                                        <button type="submit" class="btn btn-secondary btn-sm" data-pending-label="{{ __('ops.actions.working') }}">{{ __('security.blocks.remove_button') }}</button>
                                    </form>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </section>
@endsection
