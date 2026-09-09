<?php

namespace App\Http\Requests\Ops;

use App\Models\Site;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SwitchSiteChannelRequest extends FormRequest
{
    public function authorize(): bool
    {
        $site = $this->route('site');

        return $site instanceof Site
            && ($this->user()?->can('switchChannel', $site) ?? false);
    }

    /**
     * @return array<string, list<string|ValidationRule>>
     */
    public function rules(): array
    {
        return [
            'channel' => ['required', 'string', Rule::in(config('ops.channels', []))],
            'confirmed' => ['sometimes', 'boolean'],
            'force' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'channel.in' => 'Channel must be one of: '.implode(', ', config('ops.channels', [])).'.',
        ];
    }
}
