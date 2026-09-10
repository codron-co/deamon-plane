<?php

namespace App\Support;

use App\Enums\Appearance;
use Illuminate\Http\Request;

class OpsAppearance
{
    public const COOKIE = 'plane_appearance';

    public static function fromRequest(Request $request): string
    {
        $userValue = $request->user()?->appearance;
        if (is_string($userValue) && in_array($userValue, Appearance::values(), true)) {
            return $userValue;
        }

        $cookie = $request->cookie(self::COOKIE);

        return is_string($cookie) && in_array($cookie, Appearance::values(), true)
            ? $cookie
            : Appearance::Dark->value;
    }
}
