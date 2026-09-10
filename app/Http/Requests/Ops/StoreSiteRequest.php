<?php

namespace App\Http\Requests\Ops;

use App\Http\Requests\Ops\Concerns\ValidatesCoolifySiteTargets;
use App\Http\Requests\Ops\Concerns\ValidatesSiteDomains;
use App\Models\Site;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreSiteRequest extends FormRequest
{
    use ValidatesCoolifySiteTargets;
    use ValidatesSiteDomains;

    public function authorize(): bool
    {
        return $this->user()?->can('create', Site::class) ?? false;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'slug' => strtolower(trim((string) $this->input('slug'))),
            'domain' => strtolower(trim((string) $this->input('domain'))),
            'aliases' => $this->normalizedAliases(),
            'coolify_server_uuid' => $this->normalizedOptional('coolify_server_uuid'),
            'coolify_project_uuid' => $this->normalizedOptional('coolify_project_uuid'),
            'coolify_environment_uuid' => $this->normalizedOptional('coolify_environment_uuid'),
            'coolify_git_source' => $this->normalizedOptional('coolify_git_source'),
            'attach_app_uuid' => $this->normalizedOptional('attach_app_uuid'),
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
        return array_merge([
            'slug' => ['required', 'string', 'min:2', 'max:64', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', Rule::unique('sites', 'slug')->whereNull('deleted_at')],
            'name' => ['required', 'string', 'max:255'],
            'domain' => ['required', 'string', 'max:255', 'regex:'.$this->hostnamePattern(), Rule::unique('sites', 'primary_domain')->whereNull('deleted_at'), Rule::unique('site_domains', 'domain')],
            'aliases' => ['nullable', 'array', 'max:20'],
            'aliases.*' => ['nullable', 'string', 'max:255', 'distinct', 'regex:'.$this->hostnamePattern(), Rule::unique('sites', 'primary_domain')->whereNull('deleted_at'), Rule::unique('site_domains', 'domain')],
            'channel' => ['required', 'string', Rule::in(config('ops.channels', []))],
            'notes' => ['nullable', 'string', 'max:5000'],
            'mail_server_id' => ['nullable', 'string', Rule::exists('mail_servers', 'id')],
            'cloudflare_setting_id' => ['nullable', 'integer', Rule::exists('cloudflare_settings', 'id')->where('is_enabled', true)],
        ], $this->coolifyTargetRules());
    }

    public function withValidator(Validator $validator): void
    {
        $this->withCoolifyTargetValidator($validator);
        $this->validateAliasApex($validator);
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'slug.regex' => 'Slug may contain lowercase letters, numbers, and hyphens.',
            'domain.regex' => 'Enter a valid hostname (for example shop.example.com).',
            'aliases.*.regex' => 'Enter a valid hostname (for example shop.example.com).',
            'channel.in' => 'Channel must be one of: '.implode(', ', config('ops.channels', [])).'.',
        ];
    }

    private function normalizedOptional(string $key): ?string
    {
        $value = trim((string) $this->input($key));

        return $value === '' ? null : $value;
    }
}
