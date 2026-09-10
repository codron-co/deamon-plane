<?php

namespace Tests\Unit\Enums;

use App\Enums\SiteStatus;
use PHPUnit\Framework\TestCase;

class SiteStatusTest extends TestCase
{
    public function test_draft_moves_to_provisioning_or_active(): void
    {
        $this->assertTrue(SiteStatus::Draft->canTransitionTo(SiteStatus::Provisioning));
        $this->assertTrue(SiteStatus::Draft->canTransitionTo(SiteStatus::Active));
        $this->assertFalse(SiteStatus::Draft->canTransitionTo(SiteStatus::Archived));
    }

    public function test_active_deploys_stops_and_archives(): void
    {
        $this->assertTrue(SiteStatus::Active->canTransitionTo(SiteStatus::Deploying));
        $this->assertTrue(SiteStatus::Active->canTransitionTo(SiteStatus::Stopped));
        $this->assertTrue(SiteStatus::Active->canTransitionTo(SiteStatus::Archived));
        $this->assertFalse(SiteStatus::Active->canTransitionTo(SiteStatus::Draft));
    }

    public function test_stopped_starts_or_archives(): void
    {
        $this->assertTrue(SiteStatus::Stopped->canTransitionTo(SiteStatus::Active));
        $this->assertTrue(SiteStatus::Stopped->canTransitionTo(SiteStatus::Archived));
        $this->assertFalse(SiteStatus::Stopped->canTransitionTo(SiteStatus::Draft));
    }

    public function test_deploying_returns_to_active_or_error(): void
    {
        $this->assertTrue(SiteStatus::Deploying->canTransitionTo(SiteStatus::Active));
        $this->assertTrue(SiteStatus::Deploying->canTransitionTo(SiteStatus::Error));
        $this->assertFalse(SiteStatus::Deploying->canTransitionTo(SiteStatus::Draft));
    }

    public function test_error_retries_via_deploying_or_provisioning(): void
    {
        $this->assertTrue(SiteStatus::Error->canTransitionTo(SiteStatus::Deploying));
        $this->assertTrue(SiteStatus::Error->canTransitionTo(SiteStatus::Provisioning));
    }

    public function test_archived_is_terminal(): void
    {
        $this->assertSame([], SiteStatus::Archived->allowedTransitions());
    }
}
