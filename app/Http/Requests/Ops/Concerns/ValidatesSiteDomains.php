<?php

namespace App\Http\Requests\Ops\Concerns;

use App\Models\Site;

trait ValidatesSiteDomains
{
    protected function hostnamePattern(): string
    {
        return '/^(?=.{1,253}$)([a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/';
    }

    /**
     * @return list<string>
     */
    protected function normalizedAliases(): array
    {
        return collect($this->input('aliases', []))
            ->map(fn (mixed $value): string => strtolower(trim((string) $value)))
            ->filter()
            ->values()
            ->all();
    }

    protected function routeSite(): ?Site
    {
        $site = $this->route('site');

        return $site instanceof Site ? $site : null;
    }
}
