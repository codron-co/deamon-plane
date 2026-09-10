<?php

namespace App\Http\Requests\Ops;

use App\Http\Requests\Ops\Concerns\ValidatesCoolifySiteTargets;
use App\Models\Site;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateSiteRequest extends FormRequest
{
    use ValidatesCoolifySiteTargets;

    public function authorize(): bool
    {
        $site = $this->route('site');

        return $site instanceof Site
            && ($this->user()?->can('update', $site) ?? false);
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'slug' => strtolower(trim((string) $this->input('slug'))),
            'domain' => strtolower(trim((string) $this->input('domain'))),
            'coolify_server_uuid' => $this->normalizedOptional('coolify_server_uuid'),
            'coolify_project_uuid' => $this->normalizedOptional('coolify_project_uuid'),
            'coolify_environment_uuid' => $this->normalizedOptional('coolify_environment_uuid'),
            'coolify_git_source' => $this->normalizedOptional('coolify_git_source'),
            'notes' => $this->normalizedOptional('notes'),
            'mail_server_id' => $this->normalizedOptional('mail_server_id'),
            'cloudflare_setting_id' => $this->normalizedOptional('cloudflare_setting_id'),
        ]);
        $this->applyAdvancedOverrides();
    }

    /**
     * @return array<string, list<string|ValidationRule>>
     */
    public function rules(): array
    {
        $site = $this->route('site');
        $siteId = $site instanceof Site ? $site->id : null;
        $domainId = $site instanceof Site ? $site->primaryDomainRecord?->id : null;

        return array_merge([
            'slug' => ['required', 'string', 'min:2', 'max:64', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', Rule::unique('sites', 'slug')->whereNull('deleted_at')->ignore($siteId)],
            'name' => ['required', 'string', 'max:255'],
            'domain' => [
                'required',
                'string',
                'max:255',
                'regex:/^(?=.{1,253}$)([a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/',
                Rule::unique('sites', 'primary_domain')->whereNull('deleted_at')->ignore($siteId),
                Rule::unique('site_domains', 'domain')->ignore($domainId),
            ],
            'channel' => ['required', 'string', Rule::in(config('ops.channels', []))],
            'notes' => ['nullable', 'string', 'max:5000'],
            'mail_server_id' => ['nullable', 'string', Rule::exists('mail_servers', 'id')],
            'cloudflare_setting_id' => ['nullable', 'integer', Rule::exists('cloudflare_settings', 'id')->where('is_enabled', true)],
        ], $this->coolifyTargetRules());
    }

    public function withValidator(Validator $validator): void
    {
        $this->withCoolifyTargetValidator($validator);
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'slug.regex' => 'Slug may contain lowercase letters, numbers, and hyphens.',
            'domain.regex' => 'Enter a valid hostname (for example shop.example.com).',
            'channel.in' => 'Channel must be one of: '.implode(', ', config('ops.channels', [])).'.',
        ];
    }

    private function normalizedOptional(string $key): ?string
    {
        $value = trim((string) $this->input($key));

        return $value === '' ? null : $value;
    }
}
