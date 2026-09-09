<?php

namespace App\Models;

use App\Services\Coolify\CoolifyCredentials;
use Database\Factories\CoolifySettingFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CoolifySetting extends Model
{
    /** @use HasFactory<CoolifySettingFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'base_url',
        'api_token',
        'default_project_uuid',
        'default_server_uuid',
        'github_app_uuid',
        'private_key_uuid',
        'webhook_secret',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'api_token',
        'webhook_secret',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'api_token' => 'encrypted',
            'webhook_secret' => 'encrypted',
        ];
    }

    public static function current(): self
    {
        return static::query()->first() ?? new static;
    }

    public function hasToken(): bool
    {
        return filled($this->api_token);
    }

    public function hasWebhookSecret(): bool
    {
        return filled($this->webhook_secret);
    }

    public static function resolvedWebhookSecret(): ?string
    {
        $row = static::current();
        if ($row->hasWebhookSecret()) {
            return (string) $row->webhook_secret;
        }

        $fromEnv = trim((string) config('ops.coolify.webhook_secret'));

        return $fromEnv !== '' ? $fromEnv : null;
    }

    public function applicationUiUrl(?string $applicationUuid): ?string
    {
        $base = filled($this->base_url)
            ? (string) $this->base_url
            : (string) config('ops.coolify.base_url');
        $base = CoolifyCredentials::normalizeBaseUrl((string) $base);

        if ($base === '' || blank($applicationUuid)) {
            return null;
        }

        $project = filled($this->default_project_uuid)
            ? (string) $this->default_project_uuid
            : (string) config('ops.coolify.default_project_uuid');
        $environment = (string) config('ops.provision.environment_name', 'production');

        if ($project !== '') {
            return $base.'/project/'.$project.'/environment/'.$environment.'/application/'.$applicationUuid;
        }

        return $base;
    }
}
