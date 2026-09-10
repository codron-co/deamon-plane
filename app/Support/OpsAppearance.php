<?php

namespace App\Support;

use App\Enums\Appearance;
use Illuminate\Http\Request;

class OpsAppearance
{
    public const COOKIE = 'plane_appearance';

    public static function fromRequest(Request $request): string
    {
        $user = $request->user();
        if ($user && method_exists($user, 'appearanceValue')) {
            return $user->appearanceValue();
        }

        $cookie = $request->cookie(self::COOKIE);

        return is_string($cookie) && in_array($cookie, Appearance::values(), true)
            ? $cookie
            : Appearance::Dark->value;
    }
}
