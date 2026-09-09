<?php

namespace Database\Factories;

use App\Enums\Channel;
use App\Enums\DeploymentStatus;
use App\Enums\DeploymentTrigger;
use App\Models\Deployment;
use App\Models\Site;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Deployment>
 */
class DeploymentFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'site_id' => Site::factory(),
            'channel' => Channel::Main,
            'trigger' => DeploymentTrigger::Manual,
            'status' => DeploymentStatus::Queued,
            'commit_sha' => null,
            'log_excerpt' => null,
            'error_message' => null,
            'requested_by' => null,
        ];
    }
}
