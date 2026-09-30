<?php

namespace Tests\Unit\Agent;

use App\Services\Agent\AgentHealthResult;
use PHPUnit\Framework\TestCase;

class AgentHealthResultHostLoadTest extends TestCase
{
    public function test_host_load_and_cpus_reach_the_summary(): void
    {
        $result = AgentHealthResult::fromCmsPayload([
            'queue_ok' => true,
            'host_load' => [27.36, 27.07, 55.36],
            'host_cpus' => 8,
        ], 200);

        $this->assertSame([27.36, 27.07, 55.36], $result->summary['host_load']);
        $this->assertSame(8, $result->summary['host_cpus']);
    }

    public function test_malformed_or_missing_host_load_is_null(): void
    {
        foreach ([null, [1.0, 2.0], ['a', 'b', 'c'], 'high'] as $load) {
            $result = AgentHealthResult::fromCmsPayload(['queue_ok' => true, 'host_load' => $load, 'host_cpus' => '8'], 200);

            $this->assertNull($result->summary['host_load']);
            $this->assertNull($result->summary['host_cpus']);
        }
    }
}
