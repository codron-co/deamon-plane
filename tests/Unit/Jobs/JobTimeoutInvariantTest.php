<?php

namespace Tests\Unit\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use ReflectionClass;
use ReflectionNamedType;
use Tests\TestCase;

/**
 * Audit P10 (S10-B05..B08): a job must finish before its connection's
 * retry_after, or Redis hands the still-running job to a worker again. Jobs
 * that run for minutes belong on the long lane, which has its own connection.
 */
class JobTimeoutInvariantTest extends TestCase
{
    /** Worker --timeout per queue (docker/supervisor/supervisord.conf). */
    private const WORKER_TIMEOUT = ['critical' => 170, 'default' => 170, 'health' => 80, 'long' => 950];

    public function test_every_job_finishes_before_its_connection_hands_it_out_again(): void
    {
        foreach ($this->jobs() as $class => $job) {
            $queue = $job->queue ?? 'default';
            $retryAfter = $this->retryAfter($queue);
            $timeout = (new ReflectionClass($class))->getDefaultProperties()['timeout'] ?? self::WORKER_TIMEOUT[$queue];

            $this->assertLessThanOrEqual(
                $retryAfter - 10,
                (int) $timeout,
                sprintf('%s: timeout %ds on the %s lane must stay 10s under retry_after %ds.', $class, $timeout, $queue, $retryAfter),
            );

            if ((int) $timeout > self::WORKER_TIMEOUT['default']) {
                $this->assertSame('long', $queue, $class.' runs longer than the main worker allows; put it on OpsLane::Long.');
            }
        }
    }

    public function test_supervisor_workers_stop_before_retry_after_and_cover_every_lane(): void
    {
        $conf = (string) file_get_contents(base_path('docker/supervisor/supervisord.conf'));
        preg_match_all('/artisan queue:work (\S+) --queue=(\S+) .*--timeout=(\d+)/', $conf, $workers, PREG_SET_ORDER);

        $covered = [];
        foreach ($workers as [, $connection, $queues, $timeout]) {
            $retryAfter = (int) config('queue.connections.'.$connection.'.retry_after');
            $this->assertLessThanOrEqual($retryAfter - 10, (int) $timeout, "Worker on {$connection} ({$queues}) outlives retry_after.");
            $covered = [...$covered, ...explode(',', $queues)];
        }

        $this->assertEqualsCanonicalizing(['critical', 'default', 'health', 'long'], $covered);
        $this->assertMatchesRegularExpression('/queue:work redis-long --queue=long /', $conf);
    }

    private function retryAfter(string $queue): int
    {
        // Production runs on Redis; the long lane has its own connection there.
        $connection = $queue === 'long' ? 'redis-long' : 'redis';

        return (int) config('queue.connections.'.$connection.'.retry_after');
    }

    /**
     * @return array<class-string, object>
     */
    private function jobs(): array
    {
        $jobs = [];
        foreach (glob(app_path('Jobs/*.php')) ?: [] as $file) {
            $class = 'App\\Jobs\\'.basename($file, '.php');
            $reflection = new ReflectionClass($class);
            if ($reflection->isAbstract() || ! $reflection->implementsInterface(ShouldQueue::class)) {
                continue;
            }

            $args = [];
            foreach ($reflection->getConstructor()?->getParameters() ?? [] as $parameter) {
                if ($parameter->isDefaultValueAvailable()) {
                    break;
                }
                $type = $parameter->getType();
                $args[] = match ($type instanceof ReflectionNamedType ? $type->getName() : 'string') {
                    'int' => 1,
                    'bool' => false,
                    'array' => [],
                    default => 'x',
                };
            }

            $jobs[$class] = $reflection->newInstanceArgs($args);
        }

        $this->assertNotEmpty($jobs);

        return $jobs;
    }
}
