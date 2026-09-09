<?php

namespace App\Services\Sites;

use App\Console\Commands\ImportCoolifyApps\CoolifyFleetClassifier;
use App\Enums\Channel;
use App\Enums\CoolifyGitSourceKind;
use App\Enums\SiteStatus;
use App\Models\CoolifyConnection;
use App\Models\Site;
use App\Services\Coolify\CoolifyApiException;
use App\Services\Coolify\CoolifyApplicationService;
use App\Services\Coolify\Dto\CoolifyApplication;

class SiteAttacher
{
    public function __construct(
        private readonly CoolifyFleetClassifier $classifier,
    ) {}

    /**
     * @return array{app: CoolifyApplication, domain: ?string, channel: Channel, needs_review: bool, server_uuid: ?string}
     */
    public function describe(CoolifyConnection $connection, string $appUuid): array
    {
        $app = $this->fetchCustomerApp($connection, $appUuid);

        $needsReview = $this->classifier->needsReview($app->gitBranch);
        $channel = $needsReview
            ? Channel::Main
            : $this->classifier->resolveChannel($app->gitBranch);

        return [
            'app' => $app,
            'domain' => $this->classifier->primaryHost($app),
            'channel' => $channel,
            'needs_review' => $needsReview,
            'server_uuid' => $this->classifier->serverUuid($app),
        ];
    }

    public function apply(Site $site, CoolifyConnection $connection, string $appUuid): CoolifyApplication
    {
        $described = $this->describe($connection, $appUuid);
        $app = $described['app'];

        $site->coolify_connection_id = $connection->id;
        $site->coolify_app_uuid = $app->uuid;
        $site->channel_needs_review = $described['needs_review'];

        if (! $described['needs_review']) {
            $site->channel = $described['channel'];
        }

        if (is_string($described['domain']) && $described['domain'] !== '') {
            $site->primary_domain = $described['domain'];
        }

        if (is_string($described['server_uuid']) && $described['server_uuid'] !== '') {
            $site->coolify_server_uuid = $described['server_uuid'];
        }

        if (filled($app->gitRepository)) {
            $site->git_repository = $app->gitRepository;
        }

        $status = $this->classifier->resolveStatus($app->status);
        if ($status === SiteStatus::Active && $site->canTransitionTo(SiteStatus::Active)) {
            $site->status = SiteStatus::Active;
        } elseif ($status === SiteStatus::Error && $site->canTransitionTo(SiteStatus::Error)) {
            $site->status = SiteStatus::Error;
        }

        $site->save();

        if (is_string($described['domain']) && $described['domain'] !== '') {
            $primary = $site->primaryDomainRecord;
            if ($primary === null) {
                $site->domains()->create([
                    'domain' => $described['domain'],
                    'is_primary' => true,
                ]);
            } elseif ($primary->domain !== $described['domain']) {
                $primary->update(['domain' => $described['domain']]);
            }
        }

        return $app;
    }

    public function fetchCustomerApp(CoolifyConnection $connection, string $appUuid): CoolifyApplication
    {
        try {
            $app = CoolifyApplicationService::forConnection($connection)->getApp($appUuid);
        } catch (CoolifyApiException $exception) {
            throw new SiteProvisionException('Coolify uygulaması alınamadı: '.$exception->getMessage());
        }

        if (! $this->classifier->isDeamonCustomer($app)) {
            throw new SiteProvisionException('Seçilen uygulama Deamon müşteri sitesi değil (codron-co/deamon). Plane veya tema reposu bağlanamaz.');
        }

        return $app;
    }

    /**
     * @return list<array{uuid: string, name: string, branch: ?string, domain: ?string, needs_review: bool}>
     */
    public function attachableApps(CoolifyConnection $connection): array
    {
        try {
            $apps = CoolifyApplicationService::forConnection($connection)->listApps();
        } catch (\Throwable) {
            return [];
        }

        return $apps
            ->filter(fn (CoolifyApplication $app): bool => $this->classifier->isDeamonCustomer($app) && $app->uuid !== '')
            ->map(fn (CoolifyApplication $app): array => [
                'uuid' => $app->uuid,
                'name' => $app->name !== '' ? $app->name : $app->uuid,
                'branch' => $app->gitBranch,
                'domain' => $this->classifier->primaryHost($app),
                'needs_review' => $this->classifier->needsReview($app->gitBranch),
            ])
            ->values()
            ->all();
    }

    public function parseGitSource(?string $value): array
    {
        if (! is_string($value) || ! str_contains($value, ':')) {
            return [null, null];
        }

        [$kind, $uuid] = explode(':', $value, 2);
        $enum = CoolifyGitSourceKind::tryFrom($kind);

        return [$enum, $uuid !== '' ? $uuid : null];
    }
}
