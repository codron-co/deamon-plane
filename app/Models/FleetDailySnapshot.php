<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * The Sites summary tile counts as they stood at the end of one day.
 *
 * `snapshot_date` stays a plain `Y-m-d` string (no date cast) so lookups and
 * the upsert key compare the same text on every driver.
 *
 * @property string $snapshot_date
 * @property int $total
 * @property int $unhealthy
 * @property int $failed_deploys
 * @property int $app_issues
 * @property int $git_themes
 */
class FleetDailySnapshot extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'snapshot_date',
        'total',
        'unhealthy',
        'failed_deploys',
        'app_issues',
        'git_themes',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'total' => 'integer',
            'unhealthy' => 'integer',
            'failed_deploys' => 'integer',
            'app_issues' => 'integer',
            'git_themes' => 'integer',
        ];
    }
}
