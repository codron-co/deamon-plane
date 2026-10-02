<?php

namespace App\Http\Requests\Ops;

use App\Enums\SiteImportance;
use Illuminate\Validation\Rule;

class BulkSiteImportanceRequest extends BulkSiteIdsRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return array_merge(parent::rules(), [
            'importance' => ['required', 'string', Rule::in(SiteImportance::keys())],
        ]);
    }
}
