<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\PlatformLink;

use App\Entity\PlatformInstance;
use App\Service\PlatformLink\DeliveryOriginPolicy;
use App\Service\PlatformLink\OidcCloud;
use App\Service\PlatformLink\RedirectUriPolicy;
use App\Service\Security\SsrfGuard;
use PHPUnit\Framework\TestCase;

final class DeliveryOriginPolicyTest extends TestCase
{
    public function testHttpsOnTheRegisteredHostIsAccepted(): void
    {
        $policy = new DeliveryOriginPolicy(new RedirectUriPolicy(new SsrfGuard(), 'prod'), new SsrfGuard(), new OidcCloud('', '', '', ''));
        $instance = new PlatformInstance('nextcloud', 'pi_abc', 'files.example.org');

        self::assertTrue($policy->accepts($instance, 'https://files.example.org/index.php/apps/synaplan_integration/api/v1/inbound'));
        self::assertFalse($policy->accepts($instance, 'https://other.example.org/inbound'));
        self::assertFalse($policy->accepts($instance, 'http://files.example.org/inbound'));
    }

    public function testTheDevStackMayUseTheNextcloudServiceName(): void
    {
        $policy = new DeliveryOriginPolicy(new RedirectUriPolicy(new SsrfGuard(), 'dev'), new SsrfGuard(), new OidcCloud('', '', '', ''));
        $instance = new PlatformInstance('nextcloud', 'pi_abc', 'localhost:8081');

        self::assertTrue($policy->accepts($instance, 'http://nextcloud/index.php/apps/synaplan_integration/api/v1/inbound'));
    }

    public function testAnApprovedHostnameMayBePrivateAndPlainHttp(): void
    {
        $policy = new DeliveryOriginPolicy(new RedirectUriPolicy(new SsrfGuard(), 'prod'), new SsrfGuard(), new OidcCloud('', '', '', ''));
        $instance = new PlatformInstance('nextcloud', 'pi_abc', 'files.example.org');
        $instance->setApprovedHosts(['files.example.lan', '192.168.10.20']);

        self::assertTrue($policy->accepts($instance, 'http://files.example.lan/index.php/apps/synaplan_integration/api/v1/inbound'));
        self::assertTrue($policy->accepts($instance, 'https://192.168.10.20/inbound'));
        self::assertFalse($policy->accepts($instance, 'http://other.example.lan/inbound'));
    }

    public function testAnApprovedHostnameNeverCoversLoopbackOrLinkLocal(): void
    {
        $policy = new DeliveryOriginPolicy(new RedirectUriPolicy(new SsrfGuard(), 'prod'), new SsrfGuard(), new OidcCloud('', '', '', ''));
        $instance = new PlatformInstance('nextcloud', 'pi_abc', 'files.example.org');
        $instance->setApprovedHosts(['localhost', '127.0.0.1', '169.254.169.254', '::1']);

        self::assertFalse($policy->accepts($instance, 'http://localhost:8081/inbound'));
        self::assertFalse($policy->accepts($instance, 'http://127.0.0.1/inbound'));
        self::assertFalse($policy->accepts($instance, 'http://169.254.169.254/latest/meta-data'));
        self::assertFalse($policy->accepts($instance, 'http://[::1]/inbound'));
    }

    public function testMappedIpv6LoopbackIsRefusedForAnApprovedHostname(): void
    {
        $policy = new DeliveryOriginPolicy(new RedirectUriPolicy(new SsrfGuard(), 'prod'), new SsrfGuard(), new OidcCloud('', '', '', ''));
        $instance = new PlatformInstance('nextcloud', 'pi_abc', 'files.example.org');
        $instance->setApprovedHosts(['::ffff:127.0.0.1', '::ffff:169.254.169.254', 'files.example.lan']);

        self::assertFalse($policy->accepts($instance, 'http://[::ffff:127.0.0.1]/inbound'));
        self::assertFalse($policy->accepts($instance, 'http://[::ffff:169.254.169.254]/latest/meta-data'));
        self::assertTrue($policy->accepts($instance, 'http://files.example.lan/inbound'));
    }

    public function testTheDevListCountsOnlyWhileDeveloping(): void
    {
        $instance = new PlatformInstance('nextcloud', 'pi_abc', 'files.example.org');
        $prod = new DeliveryOriginPolicy(new RedirectUriPolicy(new SsrfGuard(), 'prod'), new SsrfGuard(), new OidcCloud('', '', '', ''));
        $dev = new DeliveryOriginPolicy(new RedirectUriPolicy(new SsrfGuard(), 'dev'), new SsrfGuard(), new OidcCloud('', '', '', ''));

        self::assertFalse($prod->accepts($instance, 'http://nextcloud/inbound'));
        self::assertTrue($dev->accepts($instance, 'http://nextcloud/inbound'));
    }

    public function testTheConfiguredCloudIsCalledOnlyAtItsConfiguredAddress(): void
    {
        $cloud = new OidcCloud('opencloud', 'cloud.example.com', 'people', 'http://synaplan-opencloud:9106/api/synaplan/inbound');
        $policy = new DeliveryOriginPolicy(new RedirectUriPolicy(new SsrfGuard(), 'prod'), new SsrfGuard(), $cloud);
        $configured = new PlatformInstance('opencloud', PlatformInstance::OIDC_CLOUD_ID, 'cloud.example.com');

        self::assertTrue($policy->accepts($configured, 'http://synaplan-opencloud:9106/api/synaplan/inbound'));
        self::assertFalse($policy->accepts($configured, 'http://synaplan-opencloud:9106/other'));
        self::assertFalse($policy->accepts($configured, 'https://cloud.example.com/inbound'));
        self::assertFalse($policy->accepts(new PlatformInstance('opencloud', 'pi_abc', 'cloud.example.com'), 'http://synaplan-opencloud:9106/api/synaplan/inbound'));
    }
}
