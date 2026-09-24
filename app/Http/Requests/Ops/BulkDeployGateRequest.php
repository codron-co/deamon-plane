<?php

namespace App\Http\Requests\Ops;

use App\Enums\DeployGate;

class BulkDeployGateRequest extends BulkSiteIdsRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return array_merge(parent::rules(), [
            'gate' => ['required', 'in:'.implode(',', DeployGate::values())],
        ]);
    }
}
