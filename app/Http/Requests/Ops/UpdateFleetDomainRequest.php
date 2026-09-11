<?php

namespace App\Http\Requests\Ops;

use Illuminate\Foundation\Http\FormRequest;

class UpdateFleetDomainRequest extends FormRequest
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
            'site_id' => ['nullable', 'ulid', 'exists:sites,id'],
        ];
    }
}
