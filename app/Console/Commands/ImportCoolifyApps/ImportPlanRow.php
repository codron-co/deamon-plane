<?php

namespace App\Console\Commands\ImportCoolifyApps;

use App\Enums\Channel;
use App\Enums\SiteStatus;
use App\Models\Site;

final class ImportPlanRow
{
    public const ACTION_CREATE = 'create';

    public const ACTION_UPDATE = 'update';

    public const ACTION_SKIP = 'skip';

    /**
     * @param  list<string>  $flags
     */
    public function __construct(
        public readonly string $uuid,
        public readonly string $name,
        public readonly string $repo,
        public readonly string $branch,
        public readonly string $pack,
        public readonly string $domain,
        public readonly string $action,
        public readonly string $note = '',
        public readonly array $flags = [],
        public readonly ?Channel $channel = null,
        public readonly ?SiteStatus $status = null,
        public readonly ?string $host = null,
        public readonly ?string $slug = null,
        public readonly ?string $serverUuid = null,
        public readonly ?string $gitRepository = null,
        public readonly ?Site $existing = null,
        public readonly bool $dockerfileWarning = false,
        public readonly bool $needsReview = false,
    ) {}

    /**
     * @return list<string>
     */
    public function tableRow(): array
    {
        return [
            $this->uuid,
            $this->name,
            $this->repo,
            $this->branch,
            $this->pack,
            $this->domain,
            $this->action,
        ];
    }

    public function willWrite(): bool
    {
        return in_array($this->action, [self::ACTION_CREATE, self::ACTION_UPDATE], true);
    }

    public function withExisting(Site $existing): self
    {
        return new self(
            uuid: $this->uuid,
            name: $this->name,
            repo: $this->repo,
            branch: $this->branch,
            pack: $this->pack,
            domain: $this->domain,
            action: self::ACTION_UPDATE,
            note: $this->note,
            flags: $this->flags,
            channel: $this->channel,
            status: $this->status,
            host: $this->host,
            slug: $this->slug,
            serverUuid: $this->serverUuid,
            gitRepository: $this->gitRepository,
            existing: $existing,
            dockerfileWarning: $this->dockerfileWarning,
            needsReview: $this->needsReview,
        );
    }
}
