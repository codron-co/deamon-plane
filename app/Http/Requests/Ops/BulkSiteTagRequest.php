<?php

namespace App\Http\Requests\Ops;

use App\Models\SiteTag;
use Illuminate\Validation\Rule;

class BulkSiteTagRequest extends BulkSiteIdsRequest
{
    /**
     * One submit button carries the whole instruction: `create`, or
     * `attach:<tag id>` / `detach:<tag id>`.
     */
    public const OPERATION_PATTERN = '/^(create|(attach|detach):[0-9a-z]{26})$/';

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return array_merge(parent::rules(), [
            'tag_op' => ['required', 'string', 'regex:'.self::OPERATION_PATTERN],
            'new_tag_name' => ['nullable', 'string', 'max:'.SiteTag::NAME_MAX],
            'new_tag_color' => ['nullable', 'string', Rule::in(SiteTag::COLORS)],
        ]);
    }
}
