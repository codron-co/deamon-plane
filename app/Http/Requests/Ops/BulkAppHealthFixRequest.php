<?php

namespace App\Http\Requests\Ops;

use App\Services\Sites\SiteAppHealthFixer;

class BulkAppHealthFixRequest extends BulkSiteIdsRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $keys = array_merge(SiteAppHealthFixer::FIX_ORDER, ['all']);

        return array_merge(parent::rules(), [
            'fix' => ['required', 'string', 'in:'.implode(',', $keys)],
        ]);
    }
}
