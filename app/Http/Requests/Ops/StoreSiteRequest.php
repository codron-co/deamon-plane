<?php

namespace App\Http\Requests\Ops;

use App\Models\Site;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSiteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Site::class) ?? false;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'slug' => strtolower(trim((string) $this->input('slug'))),
            'domain' => strtolower(trim((string) $this->input('domain'))),
            'coolify_server_uuid' => $this->normalizedOptional('coolify_server_uuid'),
            'notes' => $this->normalizedOptional('notes'),
        ]);
    }

    /**
     * @return array<string, list<string|ValidationRule>>
     */
    public function rules(): array
    {
        return [
            'slug' => ['required', 'string', 'min:2', 'max:64', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', Rule::unique('sites', 'slug')->whereNull('deleted_at')],
            'name' => ['required', 'string', 'max:255'],
            'domain' => ['required', 'string', 'max:255', 'regex:/^(?=.{1,253}$)([a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', Rule::unique('sites', 'primary_domain')->whereNull('deleted_at'), Rule::unique('site_domains', 'domain')],
            'channel' => ['required', 'string', Rule::in(config('ops.channels', []))],
            'coolify_server_uuid' => ['nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9_-]+$/'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
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
