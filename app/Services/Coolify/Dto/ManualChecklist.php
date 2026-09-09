<?php

namespace App\Services\Coolify\Dto;

final class ManualChecklist
{
    /**
     * @param  list<string>  $steps
     */
    public function __construct(
        public readonly string $operation,
        public readonly array $steps,
        public readonly ?string $deepLinkHint = '{base}/project/{project}/environment/{env}/application/{uuid}',
    ) {}

    public static function createComposeApp(): self
    {
        return new self('createComposeApp', [
            'Create the compose app in Coolify UI: Git + Docker Compose build pack.',
            'Set compose file to docker-compose.coolify.yml (not docker-compose.yml, not Nixpacks).',
            'Use a GitHub App or deploy key for private codron-co/deamon.',
        ]);
    }

    public static function setDomains(): self
    {
        return new self('setDomains', [
            'Bind the domain on compose service app in the Coolify UI if PATCH docker_compose_domains returns 409/400.',
            'Do not set force_domain_override unless Super Admin + audit.',
        ]);
    }

    public static function webhook(): self
    {
        return new self('webhook', [
            'If the instance has no register-webhook API, enable the outbound deploy webhook in Coolify UI.',
            'Paste the Plane webhook URL and signing secret.',
        ]);
    }
}
