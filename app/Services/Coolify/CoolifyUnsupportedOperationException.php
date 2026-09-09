<?php

namespace App\Services\Coolify;

use App\Services\Coolify\Dto\ManualChecklist;

class CoolifyUnsupportedOperationException extends CoolifyApiException
{
    public function __construct(
        public readonly string $operation,
        public readonly ManualChecklist $checklist,
    ) {
        parent::__construct(
            "Coolify operation [{$operation}] is not available via API. Follow the manual checklist.",
            501,
        );
    }
}
