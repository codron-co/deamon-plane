<?php

namespace App\Http\Requests\Ops\Concerns;

use App\Models\Site;
use App\Services\Cloudflare\CloudflareHostname;
use Illuminate\Validation\Validator;

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

    protected function validateAliasApex(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $primary = CloudflareHostname::normalize((string) $this->input('domain'));
            if ($primary === '') {
                return;
            }

            foreach ((array) $this->input('aliases', []) as $index => $alias) {
                $host = CloudflareHostname::normalize((string) $alias);
                if ($host === '' || $host === $primary) {
                    continue;
                }

                if (! CloudflareHostname::sameRegistrableApex($primary, $host)) {
                    $validator->errors()->add(
                        'aliases.'.$index,
                        __('sites.form.alias_apex', ['apex' => CloudflareHostname::apex($primary)]),
                    );
                }
            }
        });
    }

    protected function routeSite(): ?Site
    {
        $site = $this->route('site');

        return $site instanceof Site ? $site : null;
    }
}
