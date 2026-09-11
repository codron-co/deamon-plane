<?php

namespace App\Http\Requests\Ops;

use App\Services\Cloudflare\CloudflareHostname;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreFleetDomainRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->canWriteOps() ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'domain' => [
                'required',
                'string',
                'max:255',
                'regex:/^(?=.{1,253}$)([a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/',
                Rule::unique('site_domains', 'domain'),
            ],
            'site_id' => ['required', 'ulid', 'exists:sites,id'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->filled('domain')) {
            $this->merge([
                'domain' => CloudflareHostname::normalize((string) $this->input('domain')),
            ]);
        }
    }
}
