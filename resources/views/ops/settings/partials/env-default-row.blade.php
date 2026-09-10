@php
    $rowIndex = $index;
    $key = old('rows.'.$rowIndex.'.key', $row?->key);
    $kindValue = old('rows.'.$rowIndex.'.kind', $row?->kind?->value ?? \App\Enums\CoolifyEnvKind::Static->value);
    $value = old('rows.'.$rowIndex.'.value', $row?->value);
    $isSecret = (bool) old('rows.'.$rowIndex.'.is_secret', $row?->is_secret ?? false);
    $notes = old('rows.'.$rowIndex.'.notes', $row?->notes);
    $kindEnum = \App\Enums\CoolifyEnvKind::tryFrom((string) $kindValue) ?? \App\Enums\CoolifyEnvKind::Static;
@endphp
<tr data-env-row>
    <td>
        <label class="visually-hidden" for="env-key-{{ $rowIndex }}">{{ __('settings.env.columns.key') }}</label>
        <input
            id="env-key-{{ $rowIndex }}"
            class="field-input"
            type="text"
            name="rows[{{ $rowIndex }}][key]"
            value="{{ $key }}"
            spellcheck="false"
            autocomplete="off"
            @disabled(! $canWrite)
        >
        @if ($notes)
            <p class="field-hint">{{ __('settings.env.hints.'.$notes) }}</p>
        @endif
        <input type="hidden" name="rows[{{ $rowIndex }}][notes]" value="{{ $notes }}">
    </td>
    <td>
        <label class="visually-hidden" for="env-kind-{{ $rowIndex }}">{{ __('settings.env.columns.kind') }}</label>
        @if ($canWrite)
            <select
                id="env-kind-{{ $rowIndex }}"
                class="field-input"
                name="rows[{{ $rowIndex }}][kind]"
            >
                @foreach ($envKinds as $kind)
                    <option value="{{ $kind->value }}" @selected($kindValue === $kind->value)>{{ $kind->label() }}</option>
                @endforeach
            </select>
        @else
            <span class="status-chip">{{ $kindEnum->label() }}</span>
            <input type="hidden" name="rows[{{ $rowIndex }}][kind]" value="{{ $kindValue }}">
        @endif
    </td>
    <td>
        @if ($canWrite)
            <label class="visually-hidden" for="env-value-{{ $rowIndex }}">{{ __('settings.env.columns.source') }}</label>
            <input
                id="env-value-{{ $rowIndex }}"
                class="field-input"
                type="text"
                name="rows[{{ $rowIndex }}][value]"
                value="{{ $value }}"
                spellcheck="false"
                autocomplete="off"
                placeholder="{{ $kindEnum->label() }}"
            >
        @else
            <code>{{ $row?->sourceDisplay() }}</code>
        @endif
    </td>
    <td>
        <label class="env-secret-flag">
            <input
                type="checkbox"
                name="rows[{{ $rowIndex }}][is_secret]"
                value="1"
                @checked($isSecret)
                @disabled(! $canWrite)
            >
            <span class="visually-hidden">{{ __('settings.env.columns.secret') }}</span>
        </label>
    </td>
    @if ($canWrite)
        <td>
            <button type="button" class="btn btn-ghost btn-sm" data-env-remove aria-label="{{ __('settings.env.remove') }}">{{ __('settings.env.remove') }}</button>
        </td>
    @endif
</tr>
