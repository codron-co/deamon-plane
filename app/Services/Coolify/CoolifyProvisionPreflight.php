<?php

namespace App\Services\Coolify;

use App\Enums\CoolifyGitSourceKind;
use App\Models\CoolifyConnection;
use App\Models\Site;
use App\Services\Coolify\Dto\CoolifyGitSource;
use App\Services\Coolify\Dto\CoolifyServer;
use App\Services\Sites\SiteProvisionException;

class CoolifyProvisionPreflight
{
    public function assert(Site $site, CoolifyConnection $connection): void
    {
        $coolify = CoolifyApplicationService::forConnection($connection);

        $serverUuid = trim((string) ($site->coolify_server_uuid ?: $connection->default_server_uuid));
        if ($serverUuid === '') {
            throw new SiteProvisionException('Sunucu seçilmedi. Coolify menüsünden aktif bir sunucu seçin.');
        }

        try {
            $servers = $coolify->listServers();
        } catch (CoolifyApiException $exception) {
            throw new SiteProvisionException('Coolify sunucu listesi alınamadı: '.$exception->getMessage());
        }

        $serverKnown = $servers->contains(
            static fn (CoolifyServer $server): bool => $server->uuid === $serverUuid,
        );

        if (! $serverKnown) {
            throw new SiteProvisionException(
                'Seçilen sunucu Coolify API listesinde yok. UUID’yi elle yazmayın — Coolify menüsünden senkronlayıp aktif sunucu seçin.',
            );
        }

        $kind = $site->coolify_git_source_kind instanceof CoolifyGitSourceKind
            ? $site->coolify_git_source_kind
            : CoolifyGitSourceKind::tryFrom((string) $site->coolify_git_source_kind);
        $sourceUuid = trim((string) $site->coolify_git_source_uuid);

        if ($sourceUuid === '') {
            $kind = $connection->default_git_source_kind instanceof CoolifyGitSourceKind
                ? $connection->default_git_source_kind
                : CoolifyGitSourceKind::tryFrom((string) $connection->default_git_source_kind);
            $sourceUuid = trim((string) $connection->default_git_source_uuid);
        }

        if ($sourceUuid === '' || $kind === null) {
            return;
        }

        $known = $this->gitSourceExists($coolify, $kind, $sourceUuid);
        if (! $known) {
            throw new SiteProvisionException(
                'Seçilen Git kaynağı Coolify API listesinde yok. GitHub App veya deploy key’i Coolify menüsünden senkronlayın — tema kataloğu GitHub App’i değil.',
            );
        }
    }

    private function gitSourceExists(
        CoolifyApplicationService $coolify,
        CoolifyGitSourceKind $kind,
        string $uuid,
    ): bool {
        try {
            $sources = $kind === CoolifyGitSourceKind::GithubApp
                ? $coolify->listGithubApps()
                : $coolify->listPrivateKeys();
        } catch (CoolifyApiException $exception) {
            throw new SiteProvisionException('Coolify Git kaynak listesi alınamadı: '.$exception->getMessage());
        }

        if ($sources === null) {
            throw new SiteProvisionException(
                'Bu Coolify instance GitHub App listesi vermiyor. Deploy key seçin veya Super Admin gelişmiş alandan Coolify GitHub App UUID’sini yapıştırın.',
            );
        }

        return $sources->contains(
            static fn (CoolifyGitSource $source): bool => $source->uuid === $uuid && $source->kind === $kind,
        );
    }
}
