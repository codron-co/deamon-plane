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

    /**
     * @return list<string>
     */
    public static function resolvedWebhookSecrets(): array
    {
        $secrets = [];

        foreach (CoolifyConnection::query()->whereNotNull('webhook_secret')->get() as $connection) {
            if ($connection->hasWebhookSecret()) {
                $secrets[] = (string) $connection->webhook_secret;
            }
        }

        $row = static::current();
        if ($row->hasWebhookSecret()) {
            $secrets[] = (string) $row->webhook_secret;
        }

        $fromEnv = trim((string) config('ops.coolify.webhook_secret'));
        if ($fromEnv !== '') {
            $secrets[] = $fromEnv;
        }

        return array_values(array_unique($secrets));
    }

    public static function resolvedWebhookSecret(): ?string
    {
        $secrets = static::resolvedWebhookSecrets();

        return $secrets[0] ?? null;
    }

    public function applicationUiUrl(
        ?string $applicationUuid,
        ?CoolifyConnection $connection = null,
        ?string $projectUuid = null,
        ?string $environmentUuid = null,
    ): ?string {
        $connection ??= CoolifyConnection::default();
        if ($connection instanceof CoolifyConnection) {
            return $connection->applicationUiUrl($applicationUuid, $projectUuid, $environmentUuid);
        }

        $base = filled($this->base_url)
            ? (string) $this->base_url
            : (string) config('ops.coolify.base_url');
        $base = CoolifyCredentials::normalizeBaseUrl((string) $base);

        if ($base === '' || blank($applicationUuid)) {
            return null;
        }

        return $base;
    }
}
