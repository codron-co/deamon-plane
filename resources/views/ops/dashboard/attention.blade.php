@if ($dockerfilePackSites->isNotEmpty())
    <section class="fleet-body" aria-label="Fleet attention">
        <aside class="fleet-attention" aria-labelledby="fleet-attention-heading">
            <div class="fleet-attention-head">
                <div>
                    <h2 id="fleet-attention-heading" class="fleet-attention-title">Dockerfile (eski pack)</h2>
                    <p class="fleet-attention-lede">Compose'a geçirilmedi. Coolify build pack hâlâ dockerfile; CMS sürümü değil.</p>
                </div>
                <span class="status-chip status-dockerfile">{{ $dockerfilePackSites->count() }}</span>
            </div>
            <ul class="fleet-attention-list">
                @foreach ($dockerfilePackSites as $site)
                    <li>
                        <a href="{{ route('ops.sites.edit', $site) }}">
                            <span class="fleet-attention-name">{{ $site->name }}</span>
                            <code>{{ $site->primary_domain }}</code>
                        </a>
                        <span class="status-chip status-dockerfile">Dockerfile (eski pack)</span>
                    </li>
                @endforeach
            </ul>
        </aside>
    </section>
@endif
