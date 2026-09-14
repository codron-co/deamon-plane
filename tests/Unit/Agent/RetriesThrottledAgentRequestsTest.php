<?php

namespace Tests\Unit\Agent;

use App\Services\Agent\Concerns\RetriesThrottledAgentRequests;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Sleep;
use Tests\TestCase;

class RetriesThrottledAgentRequestsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Sleep::fake();
        config(['ops.agent.retry.max_attempts' => 2]);
    }

    public function test_connection_failure_is_retried_once_when_opted_in(): void
    {
        $calls = 0;
        $result = $this->subject()->run(function () use (&$calls): Response {
            $calls++;
            if ($calls === 1) {
                throw new ConnectionException('cURL error 28: Operation timed out');
            }

            return new Response(new PsrResponse(200, [], '{"ok":true}'));
        }, retryConnection: true);

        $this->assertSame(2, $calls);
        $this->assertSame(200, $result->status());
        Sleep::assertSleptTimes(1);
    }

    public function test_connection_failure_is_not_retried_by_default(): void
    {
        $calls = 0;

        $this->expectException(ConnectionException::class);

        try {
            $this->subject()->run(function () use (&$calls): Response {
                $calls++;

                throw new ConnectionException('cURL error 28: Operation timed out');
            });
        } finally {
            $this->assertSame(1, $calls);
            Sleep::assertNeverSlept();
        }
    }

    public function test_connection_failure_is_rethrown_after_the_last_attempt(): void
    {
        $calls = 0;

        $this->expectException(ConnectionException::class);

        try {
            $this->subject()->run(function () use (&$calls): Response {
                $calls++;

                throw new ConnectionException('cURL error 28: Operation timed out');
            }, retryConnection: true);
        } finally {
            $this->assertSame(2, $calls);
        }
    }

    public function test_429_is_retried_with_retry_after(): void
    {
        $calls = 0;
        $result = $this->subject()->run(function () use (&$calls): Response {
            $calls++;

            return $calls === 1
                ? new Response(new PsrResponse(429, ['Retry-After' => '1']))
                : new Response(new PsrResponse(200));
        });

        $this->assertSame(2, $calls);
        $this->assertSame(200, $result->status());
        Sleep::assertSleptTimes(1);
    }

    private function subject(): object
    {
        return new class
        {
            use RetriesThrottledAgentRequests;

            public function run(callable $send, bool $retryConnection = false): Response
            {
                return $this->sendWithRetry($send, $retryConnection);
            }
        };
    }
}
