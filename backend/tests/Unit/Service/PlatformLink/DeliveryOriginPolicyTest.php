<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\PlatformLink;

use App\Entity\PlatformInstance;
use App\Service\PlatformLink\DeliveryOriginPolicy;
use App\Service\PlatformLink\RedirectUriPolicy;
use App\Service\Security\SsrfGuard;
use PHPUnit\Framework\TestCase;

final class DeliveryOriginPolicyTest extends TestCase
{
    public function testHttpsOnTheRegisteredHostIsAccepted(): void
    {
        $policy = new DeliveryOriginPolicy(new RedirectUriPolicy(new SsrfGuard(), 'prod'), new SsrfGuard());
        $instance = new PlatformInstance('nextcloud', 'pi_abc', 'files.example.org');

        self::assertTrue($policy->accepts($instance, 'https://files.example.org/index.php/apps/synaplan_integration/api/v1/inbound'));
        self::assertFalse($policy->accepts($instance, 'https://other.example.org/inbound'));
        self::assertFalse($policy->accepts($instance, 'http://files.example.org/inbound'));
    }

    public function testTheDevStackMayUseTheNextcloudServiceName(): void
    {
        $policy = new DeliveryOriginPolicy(new RedirectUriPolicy(new SsrfGuard(), 'dev'), new SsrfGuard());
        $instance = new PlatformInstance('nextcloud', 'pi_abc', 'localhost:8081');

        self::assertTrue($policy->accepts($instance, 'http://nextcloud/index.php/apps/synaplan_integration/api/v1/inbound'));
    }
}
