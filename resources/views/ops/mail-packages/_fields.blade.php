@php
    /** @var \App\Models\MailPackage $package */
    $canWrite = $canWrite ?? true;
@endphp

<div class="field">
    <label class="field-label" for="pkg-name">{{ __('comail.packages.fields.name') }}</label>
    <input id="pkg-name" class="field-input" type="text" name="name" value="{{ old('name', $package->name) }}" maxlength="120" required @disabled(! $canWrite)>
    @error('name') <p class="field-error" role="alert">{{ $message }}</p> @enderror
</div>

<div class="field">
    <label class="field-label" for="pkg-slug">{{ __('comail.packages.fields.slug') }}</label>
    <p class="field-hint">{{ __('comail.packages.fields.slug_hint') }}</p>
    <input id="pkg-slug" class="field-input" type="text" name="slug" value="{{ old('slug', $package->slug) }}" maxlength="64" required pattern="[a-z0-9][a-z0-9-]*" autocomplete="off" spellcheck="false" @disabled(! $canWrite)>
    @error('slug') <p class="field-error" role="alert">{{ $message }}</p> @enderror
</div>

<div class="field">
    <label class="field-label" for="pkg-description">{{ __('comail.packages.fields.description') }}</label>
    <textarea id="pkg-description" class="field-input" name="description" rows="2" maxlength="1000" @disabled(! $canWrite)>{{ old('description', $package->description) }}</textarea>
    @error('description') <p class="field-error" role="alert">{{ $message }}</p> @enderror
</div>

@foreach ([
    'mailbox_limit' => ['min' => 0, 'hint' => false],
    'storage_mb' => ['min' => 1, 'hint' => true],
    'mailbox_max_quota_mb' => ['min' => 1, 'hint' => true],
    'alias_limit' => ['min' => 0, 'hint' => false],
] as $field => $meta)
    <div class="field">
        <label class="field-label" for="pkg-{{ $field }}">{{ __('comail.packages.fields.'.$field) }}</label>
        @if ($meta['hint'])
            <p class="field-hint">{{ __('comail.packages.fields.'.$field.'_hint') }}</p>
        @endif
        <input id="pkg-{{ $field }}" class="field-input" type="number" name="{{ $field }}" value="{{ old($field, $package->{$field}) }}" min="{{ $meta['min'] }}" required @disabled(! $canWrite)>
        @error($field) <p class="field-error" role="alert">{{ $message }}</p> @enderror
    </div>
@endforeach

<div class="field">
    <label class="field-label" for="pkg-domain_limit">{{ __('comail.packages.fields.domain_limit') }}</label>
    <p class="field-hint">{{ __('comail.packages.fields.domain_limit_hint') }}</p>
    <input id="pkg-domain_limit" class="field-input" type="number" name="domain_limit" value="{{ old('domain_limit', $package->domain_limit) }}" min="1" @disabled(! $canWrite)>
    @error('domain_limit') <p class="field-error" role="alert">{{ $message }}</p> @enderror
</div>

<div class="field">
    <label class="field-label" for="pkg-price">{{ __('comail.packages.fields.price_monthly') }}</label>
    <p class="field-hint">{{ __('comail.packages.fields.price_hint') }}</p>
    <input id="pkg-price" class="field-input" type="number" step="0.01" min="0" name="price_monthly" value="{{ old('price_monthly', $package->price_monthly) }}" @disabled(! $canWrite)>
    @error('price_monthly') <p class="field-error" role="alert">{{ $message }}</p> @enderror
</div>

<div class="field">
    <label class="field-label" for="pkg-price-yearly">{{ __('comail.packages.fields.price_yearly') }}</label>
    <p class="field-hint">{{ __('comail.packages.fields.price_yearly_hint') }}</p>
    <input id="pkg-price-yearly" class="field-input" type="number" step="0.01" min="0" name="price_yearly" value="{{ old('price_yearly', $package->price_yearly) }}" @disabled(! $canWrite)>
    @error('price_yearly') <p class="field-error" role="alert">{{ $message }}</p> @enderror
</div>

<div class="field">
    <label class="field-label" for="pkg-currency">{{ __('comail.packages.fields.currency') }}</label>
    <input id="pkg-currency" class="field-input" type="text" name="currency" value="{{ old('currency', $package->currency ?? 'TRY') }}" maxlength="3" required @disabled(! $canWrite)>
    @error('currency') <p class="field-error" role="alert">{{ $message }}</p> @enderror
</div>

<div class="field">
    <label class="field-label" for="pkg-sort">{{ __('comail.packages.fields.sort') }}</label>
    <input id="pkg-sort" class="field-input" type="number" name="sort" value="{{ old('sort', $package->sort ?? 0) }}" min="0" @disabled(! $canWrite)>
    @error('sort') <p class="field-error" role="alert">{{ $message }}</p> @enderror
</div>

<div class="field">
    <label class="field-check">
        <input type="hidden" name="is_active" value="0">
        <input type="checkbox" name="is_active" value="1" @checked(old('is_active', $package->is_active ?? true)) @disabled(! $canWrite)>
        <span>{{ __('comail.packages.fields.is_active') }}</span>
    </label>
</div>

<div class="field">
    <label class="field-check">
        <input type="hidden" name="is_default" value="0">
        <input type="checkbox" name="is_default" value="1" @checked(old('is_default', $package->is_default ?? false)) @disabled(! $canWrite)>
        <span>{{ __('comail.packages.fields.is_default') }}</span>
    </label>
    <p class="field-hint">{{ __('comail.packages.fields.is_default_hint') }}</p>
</div>
