<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service\Admin;

use App\Module\ModuleRegistry;
use App\Repository\ConfigRepository;
use App\Service\Admin\SystemConfig\SystemConfigSchema;
use App\Tests\Support\SystemConfigServiceFactory;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The settings tabs as the container collects them: in the established
 * order, every field in exactly one section, and the same tabs the test
 * factory builds.
 */
final class SystemConfigTabOrderTest extends KernelTestCase
{
    private const ORDER = ['ai', 'email', 'branding', 'mobile', 'auth', 'channels', 'processing', 'features', 'sharing', 'routing', 'interface', 'guest_landing', 'vectordb'];

    public function testTheTabsComeInTheEstablishedOrder(): void
    {
        self::assertSame(self::ORDER, array_keys($this->schema()->tabs()));
    }

    public function testEveryFieldBelongsToExactlyOneSection(): void
    {
        $schema = $this->schema();
        $placed = [];
        foreach ($schema->tabs() as $tabId => $tab) {
            foreach ($tab['sections'] as $sectionId => $section) {
                foreach ($section['fields'] as $key) {
                    $placed[] = $key;
                    self::assertSame([$tabId, $sectionId], [$schema->field($key)?->tab, $schema->field($key)?->section], $key);
                }
            }
        }

        self::assertSame(array_keys($schema->fields()), $placed);
    }

    public function testTheTestFactoryBuildsTheSameTabs(): void
    {
        $container = static::getContainer();
        $built = SystemConfigServiceFactory::schema($container->get(ConfigRepository::class), $container->get(ModuleRegistry::class));

        self::assertSame(array_keys($this->schema()->fields()), array_keys($built->fields()));
    }

    private function schema(): SystemConfigSchema
    {
        return static::getContainer()->get(SystemConfigSchema::class);
    }
}
