<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Bearer token for the Plane MCP endpoint. The plain token is shown once at
 * creation; only its SHA-256 is stored.
 */
class McpToken extends Model
{
    use HasUlids;

    public const PREFIX = 'plane_mcp_';

    /** Read tools: lists, site detail, jobs, deployments, DNS records. */
    public const ABILITY_READ = 'read';

    /** Everything the user's role allows in the panel (create, deploy, theme, DNS). */
    public const ABILITY_WRITE = 'write';

    /** Archive, hard delete, DNS record delete, theme file rollback. */
    public const ABILITY_DANGER = 'danger';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'name',
        'token_hash',
        'abilities',
        'last_used_at',
        'last_used_ip',
        'expires_at',
        'revoked_at',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'token_hash',
    ];

    protected function casts(): array
    {
        return [
            'abilities' => 'array',
            'last_used_at' => 'datetime',
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /**
     * @return list<string>
     */
    public static function abilityValues(): array
    {
        return [self::ABILITY_READ, self::ABILITY_WRITE, self::ABILITY_DANGER];
    }

    /**
     * @param  list<string>  $abilities
     * @return array{0: self, 1: string} the token row and the plain token (shown once)
     */
    public static function issue(User $user, string $name, array $abilities, ?\DateTimeInterface $expiresAt = null): array
    {
        $plain = self::PREFIX.Str::random(48);

        $token = self::query()->create([
            'user_id' => $user->id,
            'name' => $name,
            'token_hash' => self::hash($plain),
            'abilities' => array_values(array_intersect(self::abilityValues(), $abilities)),
            'expires_at' => $expiresAt,
        ]);

        return [$token, $plain];
    }

    public static function hash(string $plain): string
    {
        return hash('sha256', $plain);
    }

    public static function findActive(string $plain): ?self
    {
        if (! str_starts_with($plain, self::PREFIX)) {
            return null;
        }

        $token = self::query()->with('user')->where('token_hash', self::hash($plain))->first();

        return $token?->isActive() ? $token : null;
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The user an active token acts as (findActive only returns tokens that have one).
     */
    public function owner(): User
    {
        $user = $this->user;
        if (! $user instanceof User) {
            throw new \LogicException("MCP token {$this->id} has no user.");
        }

        return $user;
    }

    public function isActive(): bool
    {
        return $this->revoked_at === null
            && ($this->expires_at === null || $this->expires_at->isFuture())
            && $this->user !== null;
    }

    public function can(string $ability): bool
    {
        $abilities = $this->abilities;

        // write implies read; danger implies write.
        return match ($ability) {
            self::ABILITY_READ => $abilities !== [],
            self::ABILITY_WRITE => in_array(self::ABILITY_WRITE, $abilities, true) || in_array(self::ABILITY_DANGER, $abilities, true),
            default => in_array($ability, $abilities, true),
        };
    }

    public function revoke(): void
    {
        $this->forceFill(['revoked_at' => now()])->save();
    }
}
