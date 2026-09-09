<section class="settings-panel" aria-labelledby="coolify-connection-heading">
    <h2 id="coolify-connection-heading">Coolify</h2>
    <p class="field-hint">
        Birden fazla Coolify bağlanır. Sunucu, proje, ortam ve Git kaynağı
        <a href="{{ route('ops.coolify.index') }}">Coolify menüsünden</a> API ile çekilir — UUID elle yazılmaz.
        Token şifreli saklanır; log ve Blade’de görünmez.
    </p>
    <p class="field-hint">Deploy webhook: <code>{{ $webhookUrl }}</code></p>
    <div class="form-actions">
        <a class="btn btn-primary" href="{{ route('ops.coolify.index') }}">Coolify bağlantıları</a>
    </div>
</section>
