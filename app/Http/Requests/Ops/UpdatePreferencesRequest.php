<?php

namespace App\Http\Requests\Ops;

use App\Enums\Appearance;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePreferencesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'locale' => ['required', 'string', Rule::in(config('ops.locales', ['en', 'tr']))],
            'appearance' => ['required', 'string', Rule::in(Appearance::values())],
        ];
    }
}
