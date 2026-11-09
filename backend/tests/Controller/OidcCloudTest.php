<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\PlatformInstance;
use App\Repository\ConfigRepository;
use App\Repository\PlatformInstanceRepository;
use App\Seed\OidcCloudSeeder;
use App\Service\Iam\AuditLogWriter;
use App\Service\PlatformLink\OidcCloud;
use App\Service\PlatformLink\PlatformLinksConfig;
use App\Service\PlatformLink\RedirectUriPolicy;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * The cloud configured by environment variables: a fixed row, brought in
 * line with the configuration by the seeder, with no registration and no
 * credential of its own.
 */
final class OidcCloudTest extends WebTestCase
{
    private KernelBrowser $client;

    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->em = static::getContainer()->get('doctrine')->getManager();
        static::getContainer()->get(ConfigRepository::class)
            ->setValue(0, PlatformLinksConfig::CONFIG_GROUP, PlatformLinksConfig::KEY_ENABLED, '1');
        $this->em->flush();
        $existing = $this->instances()->findByInstanceId(PlatformInstance::OIDC_CLOUD_ID);
        if ($existing instanceof PlatformInstance) {
            $this->em->remove($existing);
            $this->em->flush();
        }
    }

    public function testTheSeederKeepsOneRowInLineWithTheConfiguration(): void
    {
        $this->seed('https://cloud.example.com', 'http://synaplan-opencloud:9106');
        $first = $this->row();
        self::assertSame(PlatformInstance::STATUS_ACTIVE, $first->getStatus());
        self::assertSame('cloud.example.com', $first->getHost());
        self::assertSame('zero-setup-people', $first->getOidcClientId());
        $id = $first->getId();

        $this->seed('https://files.example.com', 'http://opencloud-synaplan.cloud.svc:9106');
        $second = $this->row();
        self::assertSame($id, $second->getId());
        self::assertSame('files.example.com', $second->getHost());
        self::assertSame('http://opencloud-synaplan.cloud.svc:9106/api/synaplan/inbound', $second->getDeliveryUrl());
    }

    public function testWithoutTheConfigurationNothingIsWritten(): void
    {
        $seeder = new OidcCloudSeeder(new OidcCloud('', '', ''), $this->instances(), static::getContainer()->get(RedirectUriPolicy::class), static::getContainer()->get(AuditLogWriter::class));
        $seeder->seed();

        self::assertNull($this->instances()->findByInstanceId(PlatformInstance::OIDC_CLOUD_ID));
    }

    public function testTheCloudHasNoCredentialForTheInstanceRoutes(): void
    {
        $this->seed('https://cloud.example.com', 'http://synaplan-opencloud:9106');

        $this->client->request('GET', '/api/v1/platform-links/instances/self', server: [
            'HTTP_X_INSTANCE_ID' => PlatformInstance::OIDC_CLOUD_ID,
            'HTTP_X_INSTANCE_SECRET' => 'guess',
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testTheConfiguredCloudGetsAllCapabilitiesUnlessNamed(): void
    {
        $this->seed('https://cloud.example.com', 'http://synaplan-opencloud:9106');
        self::assertSame(OidcCloud::ALL_CAPABILITIES, $this->row()->getAppCapabilities());
    }

    private function seed(string $url, string $internalUrl): OidcCloud
    {
        $cloud = new OidcCloud('opencloud', $url, 'zero-setup-people', $internalUrl);
        $seeder = new OidcCloudSeeder(
            $cloud,
            $this->instances(),
            static::getContainer()->get(RedirectUriPolicy::class),
            static::getContainer()->get(AuditLogWriter::class),
        );
        $seeder->seed();
        $this->em->clear();

        return $cloud;
    }

    private function row(): PlatformInstance
    {
        $this->em->clear();
        $row = $this->instances()->findByInstanceId(PlatformInstance::OIDC_CLOUD_ID);
        self::assertInstanceOf(PlatformInstance::class, $row);

        return $row;
    }

    private function instances(): PlatformInstanceRepository
    {
        return static::getContainer()->get(PlatformInstanceRepository::class);
    }
}
