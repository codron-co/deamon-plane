<?php

namespace App\Enums;

enum FleetRolloutStatus: string
{
    case Canary = 'canary';
    case Fanout = 'fanout';
    case Done = 'done';
    case Halted = 'halted';
    case Superseded = 'superseded';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $status) => $status->value, self::cases());
    }

    /**
     * Still moving: the advance job owns it.
     */
    public function isOpen(): bool
    {
        return $this === self::Canary || $this === self::Fanout;
    }

    public function label(): string
    {
        return __('rollouts.status.'.$this->value);
    }

    public function chipClass(): string
    {
        return match ($this) {
            self::Done => 'status-active',
            self::Halted => 'status-error',
            self::Canary, self::Fanout => 'status-deploying',
            self::Superseded => '',
        };
    }
}
