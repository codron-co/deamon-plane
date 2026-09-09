<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CoolifyProjectRecord extends Model
{
    protected $table = 'coolify_projects';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'coolify_connection_id',
        'uuid',
        'name',
        'is_active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(CoolifyConnection::class, 'coolify_connection_id');
    }

    public function label(): string
    {
        $name = trim((string) $this->name);

        return $name !== '' ? $name : (string) $this->uuid;
    }
}
