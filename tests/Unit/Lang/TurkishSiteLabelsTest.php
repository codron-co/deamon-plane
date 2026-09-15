<?php

namespace Tests\Unit\Lang;

use Illuminate\Support\Arr;
use Tests\TestCase;

class TurkishSiteLabelsTest extends TestCase
{
    public function test_site_actions_use_turkish_labels(): void
    {
        $this->assertSame('Arşivle', trans('sites.menu.soft_delete', [], 'tr'));
        $this->assertSame('Kalıcı sil', trans('sites.menu.hard_delete', [], 'tr'));
        $this->assertSame('Senkron', trans('sites.menu.sync', [], 'tr'));
        $this->assertSame('Canlı kontrol', trans('sites.live.sync', [], 'tr'));
    }

    public function test_no_english_action_jargon_remains_in_turkish_site_copy(): void
    {
        $flat = Arr::dot(trans('sites', [], 'tr'));

        foreach (['Hard Delete', 'Soft Delete', 'Live Sync', 'soft-delete', 'hard-delete'] as $english) {
            $hits = array_keys(array_filter($flat, fn (mixed $value): bool => is_string($value) && str_contains($value, $english)));
            $this->assertSame([], $hits, "\"{$english}\" still appears in lang/tr/sites.php");
        }
    }
}
