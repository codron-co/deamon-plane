<?php

namespace App\Http\Requests\Ops;

use Illuminate\Foundation\Http\FormRequest;

class BulkDomainIdsRequest extends FormRequest
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
            'domain_ids' => ['required_without:all', 'array'],
            'domain_ids.*' => ['required', 'integer', 'exists:site_domains,id'],
            'all' => ['sometimes', 'boolean'],
            'filter_q' => ['sometimes', 'nullable', 'string', 'max:255'],
            'filter_unbound' => ['sometimes', 'nullable'],
        ];
    }
}
