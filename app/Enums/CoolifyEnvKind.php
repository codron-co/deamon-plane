<?php

namespace App\Enums;

enum CoolifyEnvKind: string
{
    case Static = 'static';
    case Required = 'required';
    case Generated = 'generated';
    case Site = 'site';
    case Skip = 'skip';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $kind) => $kind->value, self::cases());
    }

    public function label(): string
    {
        return __('settings.env.kinds.'.$this->value);
    }
}
