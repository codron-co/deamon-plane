@props([
    'name',
    'label',
    'options' => [],
    'value' => '',
    'form' => null,
    'allLabel' => null,
])

{{--
    Segmented quick filter for a list toolbar: one radio per option, posted by the toolbar
    form (`form` when the segment sits outside it). Radios keep it working without JavaScript,
    get arrow-key navigation for free, and ops-list re-checks them after an in-place update.

    @var array<string, string|array{label: string, count?: int|null}> $options
--}}
<div class="plane-segment-wrap">
    <fieldset {{ $attributes->class(['plane-segment', 'plane-segment-radios']) }}>
        <legend class="visually-hidden">{{ $label }}</legend>
        <label class="plane-segment-item">
            <input type="radio" name="{{ $name }}" value="" @if ($form) form="{{ $form }}" @endif data-ops-list-filter @checked((string) $value === '')>
            <span>{{ $allLabel ?? __('ops.filter.all') }}</span>
        </label>
        @foreach ($options as $optionValue => $option)
            @php
                $optionLabel = is_array($option) ? $option['label'] : $option;
                $optionCount = is_array($option) ? ($option['count'] ?? null) : null;
            @endphp
            <label class="plane-segment-item">
                <input type="radio" name="{{ $name }}" value="{{ $optionValue }}" @if ($form) form="{{ $form }}" @endif data-ops-list-filter @checked((string) $value === (string) $optionValue)>
                <span>{{ $optionLabel }}</span>
                @if ($optionCount !== null)
                    <span class="plane-segment-count">{{ $optionCount }}</span>
                @endif
            </label>
        @endforeach
    </fieldset>
</div>
