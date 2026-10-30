<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service\Admin;

use App\Module\ModuleRegistry;
use App\Repository\ConfigRepository;
use App\Service\Admin\SystemConfig\SystemConfigSchema;
use App\Service\Admin\SystemConfig\SystemConfigValues;
use App\Service\Admin\SystemConfigService;
use App\Tests\Support\SystemConfigServiceFactory;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The settings as the container wires them: the tabs in the established
 * order, every field in exactly one section, the same tabs the test factory
 * builds, and a service that shows every field and no secret.
 */
final class SystemConfigTabOrderTest extends KernelTestCase
{
    private const ORDER = ['ai', 'email', 'branding', 'mobile', 'auth', 'channels', 'processing', 'features', 'outbound', 'sharing', 'routing', 'interface', 'guest_landing', 'vectordb'];

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

    public function testTheContainersServiceShowsEveryFieldAndNoSecret(): void
    {
        $service = static::getContainer()->get(SystemConfigService::class);
        $fields = $service->getSchema()['fields'];
        $values = $service->getValues();

        self::assertSame(array_keys($this->schema()->fields()), array_keys($fields));
        self::assertSame(array_keys($fields), array_keys($values));
        foreach ($fields as $key => $field) {
            if ($field['sensitive']) {
                self::assertContains($values[$key]['value'], [SystemConfigValues::MASK, $field['default']], $key);
            }
        }
    }

    private function schema(): SystemConfigSchema
    {
        return static::getContainer()->get(SystemConfigSchema::class);
    }
}
