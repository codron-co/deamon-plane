<?php

namespace App\Console\Commands\ImportCoolifyApps;

use App\Enums\Channel;
use App\Enums\SiteStatus;
use App\Services\Coolify\CoolifyDomainParser;
use App\Services\Coolify\Dto\CoolifyApplication;
use Illuminate\Support\Str;

class CoolifyFleetClassifier
{
    public function isDeamonCustomer(CoolifyApplication $app): bool
    {
        return $this->isDeamonCustomerRepository($app->gitRepository)
            && ! $this->isExcludedName((string) $app->name);
    }

    public function isDeamonCustomerRepository(?string $repository): bool
    {
        if (! is_string($repository) || trim($repository) === '') {
            return false;
        }

        $normalized = $this->normalizeRepository($repository);

        if ($this->matchesExcludeNeedle($normalized, $repository)) {
            return false;
        }

        if ($this->isThemeRepository($normalized, $repository)) {
            return false;
        }

        $configured = $this->normalizeRepository((string) config('ops.deamon.repository', ''));
        if ($configured !== '' && $normalized === $configured) {
            return true;
        }

        $needle = strtolower((string) config('ops.import.customer_repo_needle', 'codron-co/deamon'));

        if ($needle === '') {
            return false;
        }

        if (! str_contains($normalized, $needle) && ! str_contains(strtolower($repository), $needle)) {
            return false;
        }

        // `codron-co/deamon-plane` / `codron-co/deamon-theme-*` contain the needle as a prefix.
        if (preg_match('#codron-co/deamon[-_][a-z0-9]#i', $normalized) === 1) {
            return false;
        }

        return true;
    }

    /**
     * @return list<string>
     */
    public function skipReasons(CoolifyApplication $app): array
    {
        $reasons = [];

        if (trim($app->uuid) === '') {
            $reasons[] = 'missing Coolify uuid';
        }

        if (! $this->isDeamonCustomer($app)) {
            $reasons[] = $this->exclusionReason($app);
        }

        $pack = strtolower((string) $app->buildPack);
        $allowed = array_map('strtolower', config('ops.import.allowed_build_packs', ['dockercompose', 'dockerfile']));

        if ($pack === '') {
            $reasons[] = 'missing build_pack';
        } elseif (! in_array($pack, $allowed, true)) {
            $reasons[] = "build_pack [{$pack}] is not dockercompose/dockerfile";
        }

        return $reasons;
    }

    public function isDockerfile(CoolifyApplication $app): bool
    {
        return strtolower((string) $app->buildPack) === 'dockerfile';
    }

    public function resolveChannel(?string $branch): Channel
    {
        $normalized = strtolower(trim((string) $branch));
        $channel = Channel::tryFrom($normalized);

        if ($channel instanceof Channel && $channel->isAllowed()) {
            return $channel;
        }

        return Channel::Main;
    }

    public function needsReview(?string $branch): bool
    {
        $normalized = strtolower(trim((string) $branch));
        $channel = Channel::tryFrom($normalized);

        return ! ($channel instanceof Channel && $channel->isAllowed());
    }

    public function resolveStatus(?string $coolifyStatus): SiteStatus
    {
        $status = strtolower(trim((string) $coolifyStatus));

        if ($status === '') {
            return SiteStatus::Draft;
        }

        if ($this->looksFailed($status)) {
            return SiteStatus::Error;
        }

        if (str_contains($status, 'running')) {
            return SiteStatus::Active;
        }

        return SiteStatus::Draft;
    }

    public function primaryHost(CoolifyApplication $app): ?string
    {
        $hosts = [];

        foreach ($app->composeDomains as $row) {
            if (($row['name'] ?? '') !== CoolifyDomainParser::COMPOSE_SERVICE) {
                continue;
            }

            $hosts = array_merge($hosts, $this->hostsFromDomainString((string) ($row['domain'] ?? '')));
        }

        if (is_string($app->fqdn) && $app->fqdn !== '') {
            $hosts = array_merge($hosts, $this->hostsFromDomainString($app->fqdn));
        }

        if ($hosts === []) {
            $first = CoolifyDomainParser::firstDomain($app->composeDomains);

            return $first !== null ? $this->hostFromDomainString($first) : null;
        }

        $hosts = array_values(array_unique($hosts));

        foreach ($hosts as $host) {
            if (! CoolifyDomainParser::isGeneratedWildcardHost($host)) {
                return $host;
            }
        }

        return $hosts[0];
    }

    /**
     * @return list<string>
     */
    public function hostsFromDomainString(string $domain): array
    {
        $hosts = [];

        foreach (array_map('trim', explode(',', $domain)) as $part) {
            $host = $this->parseSingleHost($part);
            if ($host !== null && ! in_array($host, $hosts, true)) {
                $hosts[] = $host;
            }
        }

        return $hosts;
    }

    public function hostFromDomainString(string $domain): ?string
    {
        return $this->hostsFromDomainString($domain)[0] ?? null;
    }

    private function parseSingleHost(string $part): ?string
    {
        $part = trim($part);
        if ($part === '') {
            return null;
        }

        if (preg_match('#^https?://#i', $part) !== 1) {
            $part = 'https://'.$part;
        }

        $host = parse_url($part, PHP_URL_HOST);
        if (! is_string($host) || $host === '') {
            return null;
        }

        return strtolower(rtrim($host, '.'));
    }

    public function slugFrom(string $host, string $name): string
    {
        $fromHost = $this->slugFromHost($host);
        if ($fromHost !== null) {
            return $fromHost;
        }

        return $this->slugFromName($name);
    }

    public function slugFromHost(string $host): ?string
    {
        $host = strtolower(trim($host));
        $host = preg_replace('/^www\./', '', $host) ?? $host;

        if ($host === '') {
            return null;
        }

        $label = explode('.', $host)[0] ?? '';
        $slug = $this->normalizeSlug($label);

        if ($slug !== null) {
            return $slug;
        }

        return $this->normalizeSlug(str_replace('.', '-', $host));
    }

    public function slugFromName(string $name): string
    {
        $trimmed = trim($name);
        $trimmed = (string) preg_replace('/^deamon[-_]+/i', '', $trimmed);

        return $this->normalizeSlug($trimmed) ?? 'site';
    }

    public function displayRepository(?string $repository): string
    {
        if (! is_string($repository) || trim($repository) === '') {
            return '';
        }

        $normalized = $this->normalizeRepository($repository);
        if (preg_match('#([^/]+/[^/]+)$#', $normalized, $matches) === 1) {
            return $matches[1];
        }

        return $normalized !== '' ? $normalized : trim($repository);
    }

    public function serverUuid(CoolifyApplication $app): ?string
    {
        return $app->serverUuid();
    }

    public function normalizeRepository(string $repository): string
    {
        $value = strtolower(trim($repository));
        $value = preg_replace('#^git@#', '', $value) ?? $value;
        $value = preg_replace('#^ssh://#', '', $value) ?? $value;
        $value = preg_replace('#^https?://#', '', $value) ?? $value;
        $value = str_replace(':', '/', $value);
        $value = preg_replace('#\.git$#', '', $value) ?? $value;

        return rtrim($value, '/');
    }

    private function isExcludedName(string $name): bool
    {
        $haystack = strtolower($name);

        foreach ($this->excludeNeedles() as $needle) {
            if ($needle !== '' && str_contains($haystack, $needle)) {
                return true;
            }
        }

        $themePrefix = strtolower((string) config('ops.themes.repo_prefix', 'deamon-theme-'));

        return $themePrefix !== '' && str_contains($haystack, $themePrefix);
    }

    private function matchesExcludeNeedle(string $normalized, string $original): bool
    {
        $originalLower = strtolower($original);

        foreach ($this->excludeNeedles() as $needle) {
            if ($needle === '') {
                continue;
            }

            if (str_contains($normalized, $needle) || str_contains($originalLower, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function isThemeRepository(string $normalized, string $original): bool
    {
        $prefix = strtolower((string) config('ops.themes.repo_prefix', 'deamon-theme-'));
        if ($prefix === '') {
            return false;
        }

        return str_contains($normalized, $prefix) || str_contains(strtolower($original), $prefix);
    }

    /**
     * @return list<string>
     */
    private function excludeNeedles(): array
    {
        $needles = config('ops.import.exclude_repo_needles', [
            'deamon-plane',
            'entron',
            'webapp-transfer',
        ]);

        if (! is_array($needles)) {
            return ['deamon-plane', 'entron', 'webapp-transfer'];
        }

        return array_values(array_filter(array_map(
            static fn (mixed $needle): string => strtolower(trim((string) $needle)),
            $needles,
        )));
    }

    private function exclusionReason(CoolifyApplication $app): string
    {
        $repository = (string) $app->gitRepository;
        $normalized = $this->normalizeRepository($repository);
        $name = strtolower($app->name);

        if ($repository === '') {
            return 'not a Deamon customer repo';
        }

        if (str_contains($normalized, 'deamon-plane') || str_contains($name, 'deamon-plane')) {
            return 'deamon-plane is not a customer site';
        }

        if (str_contains($normalized, 'entron') || str_contains($name, 'entron')) {
            return 'excluded repo (entron)';
        }

        if (str_contains($normalized, 'webapp-transfer') || str_contains($name, 'webapp-transfer')) {
            return 'excluded repo (webapp-transfer)';
        }

        if ($this->isThemeRepository($normalized, $repository) || $this->isExcludedName($app->name)) {
            return 'theme repo is not a customer site';
        }

        return 'not a Deamon customer repo';
    }

    private function looksFailed(string $status): bool
    {
        foreach (['unhealthy', 'error', 'failed', 'exited', 'crashed', 'dead'] as $token) {
            if (str_contains($status, $token)) {
                return true;
            }
        }

        return false;
    }

    private function normalizeSlug(string $value): ?string
    {
        $slug = Str::slug($value);
        if ($slug === '' || strlen($slug) < 2) {
            return null;
        }

        return substr($slug, 0, 64);
    }
}
