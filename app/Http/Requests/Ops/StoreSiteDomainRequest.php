<?php

namespace App\Http\Requests\Ops;

use App\Http\Requests\Ops\Concerns\ValidatesSiteDomains;
use App\Models\Site;
use App\Rules\NotHeldByArchivedSite;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSiteDomainRequest extends FormRequest
{
    use ValidatesSiteDomains;

    public function authorize(): bool
    {
        $site = $this->routeSite();

        return $site instanceof Site
            && ($this->user()?->can('update', $site) ?? false);
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'domain' => strtolower(trim((string) $this->input('domain'))),
        ]);
    }

    /**
     * @return array<string, list<string|ValidationRule>>
     */
    public function rules(): array
    {
        $site = $this->routeSite();
        $siteId = $site?->id;

        return [
            'domain' => [
                'required',
                'string',
                'max:255',
                'regex:'.$this->hostnamePattern(),
                NotHeldByArchivedSite::host($siteId),
                Rule::unique('sites', 'primary_domain')->whereNull('deleted_at'),
                Rule::unique('site_domains', 'domain')->where(
                    fn ($query) => $siteId ? $query->where('site_id', '!=', $siteId) : $query,
                ),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'domain.regex' => 'Enter a valid hostname (for example shop.example.com).',
        ];
    }
}
