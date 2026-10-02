@php
    /** @var string $name */
    /** @var string $selected */
@endphp
{{-- Colour is a radio set so the choice posts with the form; the name is spoken, the dot is only the visual. --}}
<span class="site-tag-swatches" role="radiogroup" aria-label="{{ __('sites.tags.color') }}">
    @foreach (\App\Models\SiteTag::COLORS as $colorKey)
        <label class="site-tag-swatch is-{{ $colorKey }}" title="{{ __('sites.tags.colors.'.$colorKey) }}">
            <input type="radio" name="{{ $name }}" value="{{ $colorKey }}" @checked($selected === $colorKey)>
            <span aria-hidden="true"></span>
            <span class="visually-hidden">{{ __('sites.tags.colors.'.$colorKey) }}</span>
        </label>
    @endforeach
</span>
