@php
    $kindEnum = $row->kind ?? \App\Enums\CoolifyEnvKind::Static;
    $searchHaystack = mb_strtolower(trim(implode(' ', array_filter([
        (string) $row->key,
        $kindEnum->label(),
        (string) $kindEnum->value,
        (string) $row->developerValue(),
        $row->is_secret ? (string) __('settings.env.columns.secret') : '',
        (string) ($row->description ?? ''),
    ], static fn (string $part): bool => $part !== ''))));
@endphp
<tr data-env-row data-env-search-text="{{ $searchHaystack }}">
    <td>
        <code class="env-key">{{ $row->key }}</code>
        @if (filled($row->description))
            <p class="field-hint">{{ $row->description }}</p>
        @endif
    </td>
    <td>
        <span class="status-chip">{{ $kindEnum->label() }}</span>
    </td>
    <td>
        <code>{{ $row->sourceDisplay() }}</code>
    </td>
    <td>
        @if ($row->is_secret)
            <span class="status-chip status-warning">{{ __('settings.env.columns.secret') }}</span>
        @else
            <span class="field-hint">—</span>
        @endif
    </td>
</tr>
