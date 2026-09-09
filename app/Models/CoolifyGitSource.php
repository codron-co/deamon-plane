<?php

namespace App\Models;

use App\Enums\CoolifyGitSourceKind;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CoolifyGitSource extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'coolify_connection_id',
        'kind',
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
            'kind' => CoolifyGitSourceKind::class,
            'is_active' => 'boolean',
        ];
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(CoolifyConnection::class, 'coolify_connection_id');
    }

    public function label(): string
    {
        $kind = $this->kind instanceof CoolifyGitSourceKind
            ? $this->kind->label()
            : (string) $this->kind;
        $name = trim((string) $this->name);
        $title = $name !== '' ? $name : $this->uuid;

        return $kind.': '.$title;
    }

    public function formValue(): string
    {
        $kind = $this->kind instanceof CoolifyGitSourceKind
            ? $this->kind->value
            : (string) $this->kind;

        return $kind.':'.$this->uuid;
    }
}
