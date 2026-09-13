<?php

namespace App\Http\Requests\Ops;

class BulkAutoDeploySiteRequest extends BulkSiteIdsRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return array_merge(parent::rules(), [
            // Explicit on/off — never infer mixed → off from a Coolify GET per site.
            'enabled' => ['required', 'boolean'],
        ]);
    }
}
