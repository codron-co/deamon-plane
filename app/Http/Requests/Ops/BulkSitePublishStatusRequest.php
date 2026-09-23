<?php

namespace App\Http\Requests\Ops;

use App\Enums\CmsPublishStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BulkSitePublishStatusRequest extends FormRequest
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
            'site_ids' => ['required_without:all', 'array'],
            'site_ids.*' => ['required', 'ulid'],
            'all' => ['sometimes', 'boolean'],
            'confirmed' => ['sometimes', 'boolean'],
            'filter_q' => ['sometimes', 'nullable', 'string', 'max:120'],
            'filter_channel' => ['sometimes', 'nullable', 'string', 'max:32'],
            'filter_status' => ['sometimes', 'nullable', 'string', 'max:32'],
            'filter_publish' => ['sometimes', 'nullable', 'string', 'max:32'],
            'filter_deploy' => ['sometimes', 'nullable', 'string', 'max:32'],
            'filter_agent' => ['sometimes', 'nullable', 'string', 'max:32'],
            'filter_pack' => ['sometimes', 'nullable', 'string', 'max:32'],
            'filter_health' => ['sometimes', 'nullable', 'string', 'max:32'],
            'filter_app' => ['sometimes', 'nullable', 'string', 'max:32'],
            'filter_theme' => ['sometimes', 'nullable', 'string', 'max:32'],
            'filter_theme_id' => ['sometimes', 'nullable', 'string', 'max:100'],
            'filter_cms' => ['sometimes', 'nullable', 'string', 'max:32'],
            'filter_auto_deploy' => ['sometimes', 'nullable', 'string', 'max:32'],
            'filter_server' => ['sometimes', 'nullable', 'string', 'max:64'],
            'filter_stale' => ['sometimes', 'nullable', 'string', 'max:32'],
        ];
    }
}
