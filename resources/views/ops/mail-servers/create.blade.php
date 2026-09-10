@extends('layouts.ops')

@section('title', __('mail.create.title'))

@section('breadcrumbs')
    <a href="{{ route('ops.mail-servers.index') }}">{{ __('mail.title') }}</a>
    <span aria-hidden="true">/</span>
    <span>{{ __('mail.create.title') }}</span>
@endsection

@section('actions')
    <a class="btn btn-ghost btn-sm" href="{{ route('ops.mail-servers.index') }}">{{ __('ops.actions.back') }}</a>
@endsection

@section('content')
    <p class="page-lede">{{ __('mail.create.lede') }}</p>

    <form method="POST" action="{{ route('ops.mail-servers.store') }}" class="ops-form ops-form-stack">
        @csrf
        @include('ops.mail-servers._fields', ['server' => $server, 'canWrite' => true, 'requireToken' => true])
        <div class="form-actions">
            <button type="submit" class="btn btn-primary">{{ __('mail.create.save') }}</button>
        </div>
    </form>
@endsection
