<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Config;
use App\Module\Gate\ModuleGateConfig;
use App\Module\Http\ModuleGateListener;
use App\Module\Sidecar\OfficeEditorModule;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The editor is off unless both URLs are set. Its public WOPI route is gated,
 * so a call then answers 404 feature_not_configured. The edit-session route
 * is not public, so a missing login is 401 from the firewall.
 *
 * The dev container may have the URLs set. This test removes them for its
 * own kernel boot and puts them back afterwards.
 */
final class WopiRouteTest extends WebTestCase
{
    private ?string $publicUrl = null;

    private ?string $wopiUrl = null;

    protected function setUp(): void
    {
        $public = getenv('OFFICE_PUBLIC_URL');
        $wopi = getenv('OFFICE_WOPI_URL');
        $this->publicUrl = is_string($public) && '' !== $public ? $public : null;
        $this->wopiUrl = is_string($wopi) && '' !== $wopi ? $wopi : null;
    }

    protected function tearDown(): void
    {
        $this->restore('OFFICE_PUBLIC_URL', $this->publicUrl);
        $this->restore('OFFICE_WOPI_URL', $this->wopiUrl);
        parent::tearDown();
    }

    public function testCheckFileInfoWithoutTheEditorIsAbsent(): void
    {
        self::ensureKernelShutdown();
        $this->clear('OFFICE_PUBLIC_URL');
        $this->clear('OFFICE_WOPI_URL');

        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $setting = ModuleGateConfig::settingFor(OfficeEditorModule::ID);
        $row = $em->getRepository(Config::class)->findOneBy([
            'ownerId' => 0,
            'group' => ModuleGateConfig::GROUP,
            'setting' => $setting,
        ]);
        if (!$row instanceof Config) {
            $row = (new Config())->setOwnerId(0)->setGroup(ModuleGateConfig::GROUP)->setSetting($setting);
            $em->persist($row);
        }
        $row->setValue('1');
        $em->flush();
        static::getContainer()->get(ModuleGateConfig::class)->reset();

        $client->request('GET', '/api/v1/wopi/files/1');

        self::assertSame(404, $client->getResponse()->getStatusCode(), (string) $client->getResponse()->getContent());
        $body = json_decode($client->getResponse()->getContent(), true);
        self::assertSame(ModuleGateListener::ERROR_CODE, $body['error'] ?? null);
        self::assertSame(OfficeEditorModule::ID, $body['module'] ?? null);
    }

    public function testEditSessionRequiresALogin(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/v1/files/1/edit-session');

        self::assertSame(401, $client->getResponse()->getStatusCode());
    }

    private function clear(string $name): void
    {
        putenv($name);
        unset($_ENV[$name], $_SERVER[$name]);
    }

    private function restore(string $name, ?string $value): void
    {
        if (null === $value) {
            $this->clear($name);

            return;
        }
        putenv($name.'='.$value);
        $_ENV[$name] = $value;
        $_SERVER[$name] = $value;
    }
}
