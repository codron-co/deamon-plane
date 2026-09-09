<?php

namespace Tests\Unit\Models;

use App\Models\AuditLog;
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

class AuditLogTest extends TestCase
{
    use RefreshDatabase;

    public function test_audit_logs_are_append_only(): void
    {
        $site = Site::factory()->create();

        $log = AuditLog::factory()->create([
            'subject_type' => Site::class,
            'subject_id' => $site->id,
            'action' => 'site.created',
        ]);

        $this->assertNotNull($log->created_at);
        $this->assertArrayNotHasKey('updated_at', $log->getAttributes());

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Audit logs are append-only.');

        $log->update(['action' => 'site.updated']);
    }

    public function test_audit_logs_cannot_be_deleted(): void
    {
        $log = AuditLog::factory()->create();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Audit logs are append-only.');

        $log->delete();
    }
}
