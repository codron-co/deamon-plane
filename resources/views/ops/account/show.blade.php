@extends('layouts.ops')

@section('title', __('account.title'))

@section('actions')
    <a class="btn btn-ghost btn-sm" href="{{ route('ops.account.preferences') }}">{{ __('account.preferences') }}</a>
@endsection

@section('breadcrumbs')
    <span>{{ __('account.title') }}</span>
@endsection

@section('content')
    <div class="ops-form-stack">
        <section class="ops-form-section" aria-labelledby="account-profile-heading">
            <h2 id="account-profile-heading">{{ __('account.profile') }}</h2>
            <form method="POST" action="{{ route('ops.account.update') }}" class="ops-form">
                @csrf
                @method('PUT')
                <div class="field">
                    <label class="field-label" for="account_name">{{ __('account.name') }}</label>
                    <input id="account_name" class="field-input" type="text" name="name" value="{{ old('name', $user->name) }}" required maxlength="255" autocomplete="name">
                    @error('name') <p class="field-error">{{ $message }}</p> @enderror
                </div>
                <div class="field">
                    <label class="field-label" for="account_email">{{ __('account.email') }}</label>
                    <input id="account_email" class="field-input" type="email" name="email" value="{{ old('email', $user->email) }}" required maxlength="255" autocomplete="email">
                    @error('email') <p class="field-error">{{ $message }}</p> @enderror
                </div>
                <div class="form-actions">
                    <button type="submit" class="btn btn-primary">{{ __('account.save_profile') }}</button>
                </div>
            </form>
        </section>

        <section class="ops-form-section" aria-labelledby="account-avatar-heading">
            <h2 id="account-avatar-heading">{{ __('account.avatar') }}</h2>
            <div class="ops-avatar-row">
                @if ($user->avatarUrl())
                    <img class="ops-avatar-preview" src="{{ $user->avatarUrl() }}" alt="">
                @else
                    <span class="ops-user-avatar ops-avatar-preview-fallback" aria-hidden="true">{{ $user->initials() }}</span>
                @endif
                <div>
                    <p class="field-hint">{{ __('account.photo_hint') }}</p>
                    <form method="POST" action="{{ route('ops.account.avatar') }}" enctype="multipart/form-data" class="ops-form">
                        @csrf
                        <div class="field">
                            <label class="field-label" for="account_avatar">{{ __('account.upload_photo') }}</label>
                            <input id="account_avatar" class="field-input" type="file" name="avatar" accept="image/jpeg,image/png,image/webp" required>
                            @error('avatar') <p class="field-error">{{ $message }}</p> @enderror
                        </div>
                        <div class="form-actions">
                            <button type="submit" class="btn btn-secondary">{{ __('account.upload_photo') }}</button>
                        </div>
                    </form>
                    @if ($user->avatar_path)
                        <form method="POST" action="{{ route('ops.account.avatar.destroy') }}">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="btn btn-ghost btn-sm">{{ __('account.remove_photo') }}</button>
                        </form>
                    @endif
                </div>
            </div>
        </section>

        <section class="ops-form-section" aria-labelledby="account-password-heading">
            <h2 id="account-password-heading">{{ __('account.password') }}</h2>
            <form method="POST" action="{{ route('ops.account.password') }}" class="ops-form">
                @csrf
                @method('PUT')
                <div class="field">
                    <label class="field-label" for="current_password">{{ __('account.current_password') }}</label>
                    <input id="current_password" class="field-input" type="password" name="current_password" required autocomplete="current-password">
                    @error('current_password') <p class="field-error">{{ $message }}</p> @enderror
                </div>
                <div class="field">
                    <label class="field-label" for="password">{{ __('account.new_password') }}</label>
                    <input id="password" class="field-input" type="password" name="password" required autocomplete="new-password">
                    @error('password') <p class="field-error">{{ $message }}</p> @enderror
                </div>
                <div class="field">
                    <label class="field-label" for="password_confirmation">{{ __('account.password_confirmation') }}</label>
                    <input id="password_confirmation" class="field-input" type="password" name="password_confirmation" required autocomplete="new-password">
                </div>
                <div class="form-actions">
                    <button type="submit" class="btn btn-primary">{{ __('account.change_password') }}</button>
                </div>
            </form>
        </section>
    </div>
@endsection
