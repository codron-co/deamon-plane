@php
    $extra = $extra ?? null;
@endphp

<section class="ops-panel" aria-labelledby="allow-{{ $param }}-heading">
    <h2 id="allow-{{ $param }}-heading">{{ $title }}</h2>
    <p>{{ $hint }}</p>

    @if ($rows->isEmpty())
        <p class="muted">Liste boş. Sync düğmesiyle API’den çekin.</p>
    @else
        <div class="sites-table-wrap">
            <table class="ops-table">
                <thead>
                    <tr>
                        <th>Ad</th>
                        @if ($extra)
                            <th>{{ $extra === 'kind' ? 'Tür' : 'Proje' }}</th>
                        @endif
                        <th>Durum</th>
                        @if ($canWrite)
                            <th></th>
                        @endif
                    </tr>
                </thead>
                <tbody>
                    @foreach ($rows as $row)
                        <tr>
                            <td>
                                <span class="site-name">{{ $row->name ?: $row->uuid }}</span>
                                <div class="site-slug">{{ $row->uuid }}</div>
                            </td>
                            @if ($extra === 'kind')
                                <td class="muted">{{ $row->kind?->label() ?? $row->kind }}</td>
                            @elseif ($extra)
                                <td class="muted">{{ $row->{$extra} }}</td>
                            @endif
                            <td>
                                <span class="status-chip status-{{ $row->is_active ? 'active' : 'error' }}">
                                    {{ $row->is_active ? 'aktif' : 'pasif' }}
                                </span>
                            </td>
                            @if ($canWrite)
                                <td class="ops-row-actions">
                                    <form method="POST" action="{{ route($toggleRoute, ['connection' => $connection, $param => $row]) }}">
                                        @csrf
                                        <button type="submit" class="btn btn-ghost btn-sm">{{ $row->is_active ? 'Pasif yap' : 'Aktif yap' }}</button>
                                    </form>
                                </td>
                            @endif
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</section>
