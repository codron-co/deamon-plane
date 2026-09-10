<?php

namespace App\Http\Requests\Ops;

class BulkPinSiteRequest extends BulkSiteIdsRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return array_merge(parent::rules(), [
            'ref' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9._\/-]+$/'],
        ]);
    }
}
