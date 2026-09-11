<?php

namespace Tests\Unit\Coolify;

use App\Services\Coolify\Dto\CoolifyDeployment;
use Tests\TestCase;

class CoolifyDeploymentTimeParseTest extends TestCase
{
    public function test_naive_coolify_timestamps_are_parsed_as_utc(): void
    {
        $remote = CoolifyDeployment::fromArray([
            'uuid' => 'dep-1',
            'status' => 'finished',
            'created_at' => '2026-09-10 20:03:00',
            'updated_at' => '2026-09-10 20:05:00',
        ]);

        $this->assertSame('2026-09-10T20:03:00+00:00', $remote->startedAt()?->toIso8601String());
        $this->assertSame('2026-09-10T20:05:00+00:00', $remote->finishedAt()?->toIso8601String());
        $this->assertSame(
            '2026-09-10 23:03:00',
            $remote->startedAt()?->timezone('Europe/Istanbul')->format('Y-m-d H:i:s')
        );
    }

    public function test_zulu_timestamps_remain_utc(): void
    {
        $remote = CoolifyDeployment::fromArray([
            'uuid' => 'dep-2',
            'status' => 'finished',
            'created_at' => '2026-09-01T10:00:00Z',
            'updated_at' => '2026-09-01T10:04:00Z',
        ]);

        $this->assertSame(240, (int) $remote->startedAt()?->diffInSeconds($remote->finishedAt(), true));
    }
}
