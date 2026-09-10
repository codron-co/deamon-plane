<?php

namespace App\Models;

use App\Enums\CoolifyEnvKind;
use App\Enums\CoolifyEnvPack;
use Database\Factories\CoolifyEnvDefaultFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CoolifyEnvDefault extends Model
{
    /** @use HasFactory<CoolifyEnvDefaultFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'pack',
        'key',
        'kind',
        'value',
        'is_secret',
        'sort',
        'notes',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'pack' => CoolifyEnvPack::class,
            'kind' => CoolifyEnvKind::class,
            'is_secret' => 'boolean',
            'sort' => 'integer',
        ];
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeForPack(Builder $query, CoolifyEnvPack|string $pack): Builder
    {
        $value = $pack instanceof CoolifyEnvPack ? $pack->value : $pack;

        return $query->where('pack', $value)->orderBy('sort')->orderBy('key');
    }

    public function developerValue(): string
    {
        $value = $this->value;

        return is_string($value) ? $value : '';
    }

    public function sourceDisplay(): string
    {
        return match ($this->kind) {
            CoolifyEnvKind::Static => filled($this->value) ? (string) $this->value : '—',
            CoolifyEnvKind::Required => filled($this->value)
                ? (string) $this->value
                : __('settings.env.source.required_empty'),
            CoolifyEnvKind::Generated => __('settings.env.source.generated'),
            CoolifyEnvKind::Site => $this->developerValue() !== ''
                ? $this->developerValue()
                : __('settings.env.source.site'),
            CoolifyEnvKind::Skip => __('settings.env.source.skip'),
        };
    }

    /**
     * @return array<string, string>
     */
    public function __debugInfo(): array
    {
        return [
            'id' => (string) $this->id,
            'pack' => $this->pack?->value,
            'key' => (string) $this->key,
            'kind' => $this->kind?->value,
            'value' => $this->is_secret ? '[redacted]' : $this->value,
            'is_secret' => $this->is_secret,
        ];
    }
}
