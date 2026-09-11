<?php

namespace Tests\Unit\Agent;

use App\Services\Agent\ThemeAgentResult;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ThemeAgentResultTest extends TestCase
{
    #[Test]
    public function validation_failed_surfaces_cms_message(): void
    {
        $result = ThemeAgentResult::fromCmsError([
            'ok' => false,
            'error' => 'validation_failed',
            'message' => 'Sync applies to the active theme. Activate it first.',
        ], 422);

        $this->assertFalse($result->ok);
        $this->assertSame('validation_failed', $result->errorCode);
        $this->assertSame('Sync applies to the active theme. Activate it first.', $result->safeMessage);
    }

    #[Test]
    public function validation_failed_falls_back_without_cms_message(): void
    {
        $result = ThemeAgentResult::fromCmsError([
            'ok' => false,
            'error' => 'validation_failed',
        ], 422);

        $this->assertSame('Theme agent validation failed.', $result->safeMessage);
    }
}
