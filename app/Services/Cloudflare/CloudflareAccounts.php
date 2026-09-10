<?php

namespace App\Services\Cloudflare;

use App\Models\CloudflareSetting;
use Illuminate\Support\Facades\DB;

class CloudflareAccounts
{
    public static function default(): ?CloudflareSetting
    {
        return CloudflareSetting::query()
            ->where('is_enabled', true)
            ->where('is_default', true)
            ->first()
            ?? CloudflareSetting::query()->where('is_enabled', true)->orderBy('id')->first();
    }

    public static function markAsDefault(CloudflareSetting $account): void
    {
        DB::transaction(function () use ($account): void {
            CloudflareSetting::query()->whereKeyNot($account->id)->update(['is_default' => false]);
            $account->forceFill(['is_default' => true, 'is_enabled' => true])->save();
        });
    }
}
