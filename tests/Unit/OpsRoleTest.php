<?php

namespace Tests\Unit;

use App\Enums\OpsRole;
use PHPUnit\Framework\TestCase;

class OpsRoleTest extends TestCase
{
    public function test_viewer_cannot_write(): void
    {
        $this->assertFalse(OpsRole::Viewer->canWrite());
        $this->assertTrue(OpsRole::Operator->canWrite());
        $this->assertTrue(OpsRole::SuperAdmin->canWrite());
    }
}
