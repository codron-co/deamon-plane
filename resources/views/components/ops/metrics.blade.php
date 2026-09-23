@props([
    'label',
    'columns' => null,
])

{{-- A row of summary tiles (x-ops.metric). `columns` sets the desktop grid when it is not five. --}}
<section {{ $attributes->class(['plane-metrics']) }} aria-label="{{ $label }}"@if ($columns !== null) style="--plane-metric-columns: {{ (int) $columns }}"@endif>
    {{ $slot }}
</section>
