@extends('layouts.ops')

@section('title', 'Themes')

@section('content')
    <div class="empty-panel">
        <h2>Git-bound catalog is Faz B</h2>
        <p>Theme source of truth is GitHub org <code>{{ $org }}</code>, repos named <code>{{ $prefix }}{theme_id}</code>. Plane does not accept ZIP uploads. Catalog sync, site assignment, and webhook rollout are Tasks 10–13. Until then this page is the Themes destination — not an empty “coming soon” item.</p>
        <dl class="spec-list">
            <div>
                <dt>Install path</dt>
                <dd>Signed site agent on each Deamon instance (CMS repo), not Coolify SSH.</dd>
            </div>
            <div>
                <dt>Auto-update</dt>
                <dd>Default off. Org push fans out only when a site opts in (later tasks).</dd>
            </div>
        </dl>
    </div>
@endsection
