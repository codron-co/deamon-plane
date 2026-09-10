<?php

namespace App\Services\Sites;

use App\Models\CoolifyConnection;
use App\Models\Site;
use App\Models\User;
use App\Services\Coolify\CoolifyApiException;
use App\Services\Coolify\CoolifyApplicationService;
use Illuminate\Support\Str;

class SiteAgentSecretInjector
{
    public function inject(Site $site, ?User $actor = null, ?string $ip = null, bool $rotate = false): void
    {
        if (blank($site->coolify_app_uuid)) {
            throw new SiteProvisionException('Coolify uygulama UUID’si yok. Önce provision edin veya mevcut uygulamayı bağlayın.');
        }

        $connection = $site->coolifyConnection ?: CoolifyConnection::default();
        if ($connection === null) {
            throw new SiteProvisionException('Coolify bağlantısı yok. Coolify menüsünden bir bağlantı ekleyin.');
        }

        if ($rotate || blank($site->agent_secret_encrypted)) {
            $site->agent_secret_encrypted = Str::password(64, symbols: false);
            $site->save();
        }

        try {
            CoolifyApplicationService::forConnection($connection)->updateEnvs((string) $site->coolify_app_uuid, [
                'CONTROL_PLANE_AGENT_SECRET' => (string) $site->agent_secret_encrypted,
            ]);
        } catch (CoolifyApiException $exception) {
            throw new SiteProvisionException('Agent secret Coolify env’e yazılamadı: '.$exception->getMessage());
        }

        $site->auditLogs()->create([
            'actor_user_id' => $actor?->id,
            'action' => $rotate ? 'site.agent_secret_rotated' : 'site.agent_secret_injected',
            'after' => [
                'coolify_app_uuid' => $site->coolify_app_uuid,
                'injected' => true,
                'rotated' => $rotate,
            ],
            'ip' => $ip,
        ]);
    }
}
