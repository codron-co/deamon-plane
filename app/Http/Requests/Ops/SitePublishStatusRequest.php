<?php

namespace App\Http\Requests\Ops;

use App\Enums\CmsPublishStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SitePublishStatusRequest extends FormRequest
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
            'publish_status' => ['required', 'string', Rule::in(CmsPublishStatus::values())],
        ];
    }
}
