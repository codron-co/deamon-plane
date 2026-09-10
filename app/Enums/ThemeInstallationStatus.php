<?php

namespace App\Enums;

enum ThemeInstallationStatus: string
{
    case Pending = 'pending';
    case Installing = 'installing';
    case Active = 'active';
    case Error = 'error';
    case Updating = 'updating';

    public function label(): string
    {
        return __('ops.theme_install_status.'.$this->value);
    }
}
