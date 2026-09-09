<?php

namespace Database\Factories;

use App\Models\AuditLog;
use App\Models\Site;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AuditLog>
 */
class AuditLogFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'actor_user_id' => null,
            'action' => 'site.created',
            'subject_type' => Site::class,
            'subject_id' => Site::factory(),
            'before' => null,
            'after' => ['status' => 'draft'],
            'ip' => '127.0.0.1',
        ];
    }
}
