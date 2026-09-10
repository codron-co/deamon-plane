<?php

namespace App\Http\Requests\Ops;

use Illuminate\Foundation\Http\FormRequest;

class BulkSiteIdsRequest extends FormRequest
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
            'site_ids' => ['required_without_all:all_dockerfile,all', 'array'],
            'site_ids.*' => ['required', 'ulid'],
            'all_dockerfile' => ['sometimes', 'boolean'],
            'all' => ['sometimes', 'boolean'],
            'enabled' => ['sometimes', 'boolean'],
        ];
    }
}
