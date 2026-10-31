<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Admin\SystemConfig;

use App\AI\Catalog\ProviderCatalog;
use App\AI\Credential\ProviderKeyCatalog;
use App\Module\Contract\FeatureModuleInterface;
use App\Module\ModuleRegistry;
use App\Repository\ConfigRepository;
use App\Service\Admin\SystemConfig\ConfigSection;
use App\Service\Admin\SystemConfig\DatabaseRow;
use App\Service\Admin\SystemConfig\FieldDefinition;
use App\Service\Admin\SystemConfig\SystemConfigSchema;
use App\Service\Admin\SystemConfig\SystemConfigTab;
use App\Tests\Support\SystemConfigServiceFactory;
use App\Tests\Unit\Module\Fixture\BuildsAllModules;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ServiceLocator;

final class SystemConfigSchemaTest extends TestCase
{
    use BuildsAllModules;

    public function testEveryFieldIsPlacedInItsTabAndSectionInTheOrderShown(): void
    {
        $schema = new SystemConfigSchema([
            $this->tab('email', 'Email', ['mailer' => new ConfigSection('Primary Mailer', [
                'MAILER_DSN' => new FieldDefinition('text', 'Mailer DSN', sensitive: true),
                'APP_SENDER_NAME' => new FieldDefinition('text', 'Sender name', 'Synaplan'),
            ])]),
            $this->tab('vectordb', 'Vector DB', [
                'qdrant' => new ConfigSection('Qdrant', ['QDRANT_URL' => new FieldDefinition('url', 'Qdrant URL')]),
                'qdrant_search' => new ConfigSection('Search Thresholds', ['MAX_CHAT_MEMORIES' => new FieldDefinition('number', 'Max memories', '5', row: new DatabaseRow())]),
            ]),
        ], new ProviderKeyCatalog(ProviderCatalog::builtIn()));

        self::assertSame([
            'email' => ['label' => 'Email', 'sections' => ['mailer' => ['label' => 'Primary Mailer', 'fields' => ['MAILER_DSN', 'APP_SENDER_NAME']]]],
            'vectordb' => ['label' => 'Vector DB', 'sections' => [
                'qdrant' => ['label' => 'Qdrant', 'fields' => ['QDRANT_URL']],
                'qdrant_search' => ['label' => 'Search Thresholds', 'fields' => ['MAX_CHAT_MEMORIES']],
            ]],
        ], $schema->tabs());
        self::assertSame(['MAILER_DSN', 'APP_SENDER_NAME', 'QDRANT_URL', 'MAX_CHAT_MEMORIES'], array_keys($schema->fields()));
        self::assertSame(['vectordb', 'qdrant_search'], [$schema->field('MAX_CHAT_MEMORIES')?->tab, $schema->field('MAX_CHAT_MEMORIES')?->section]);
        self::assertNull($schema->field('UNKNOWN'));
    }

    public function testAProviderKeyHasOneEditorAndLivesInTheDatabase(): void
    {
        $envVar = (new ProviderKeyCatalog(ProviderCatalog::builtIn()))->managedEnvVars()[0];
        $schema = new SystemConfigSchema([
            $this->tab('ai', 'AI Services', ['cloud' => new ConfigSection('Cloud AI Providers', [$envVar => new FieldDefinition('password', 'API key', sensitive: true)])]),
        ], new ProviderKeyCatalog(ProviderCatalog::builtIn()));

        $field = $schema->field($envVar);
        self::assertNotNull($field);
        self::assertSame(ProviderKeyCatalog::MANAGED_BY, $field->managedBy);
        self::assertTrue($field->isStoredInDatabase());
    }

    public function testASettingDeclaredTwiceIsAWiringError(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Setting QDRANT_URL is declared twice: in vectordb / qdrant and in processing / tika.');

        new SystemConfigSchema([
            $this->tab('vectordb', 'Vector DB', ['qdrant' => new ConfigSection('Qdrant', ['QDRANT_URL' => new FieldDefinition('url', 'Qdrant URL')])]),
            $this->tab('processing', 'Processing', ['tika' => new ConfigSection('Apache Tika', ['QDRANT_URL' => new FieldDefinition('url', 'Again')])]),
        ], new ProviderKeyCatalog(ProviderCatalog::builtIn()));
    }

    public function testTheShippedFieldsAreListedInTheOrderTheScreenShowsThem(): void
    {
        $factories = [];
        foreach ($this->allModules() as $id => $module) {
            $factories[$id] = static fn (): FeatureModuleInterface => $module;
        }
        $schema = SystemConfigServiceFactory::schema($this->createStub(ConfigRepository::class), new ModuleRegistry(new ServiceLocator($factories)));

        $shown = [];
        foreach ($schema->tabs() as $tab) {
            foreach ($tab['sections'] as $section) {
                array_push($shown, ...$section['fields']);
            }
        }

        self::assertSame($shown, array_keys($schema->fields()));
    }

    /**
     * @param array<string, ConfigSection> $sections
     */
    private function tab(string $id, string $label, array $sections): SystemConfigTab
    {
        return new readonly class($id, $label, $sections) implements SystemConfigTab {
            /**
             * @param array<string, ConfigSection> $sections
             */
            public function __construct(private string $id, private string $label, private array $sections)
            {
            }

            public function id(): string
            {
                return $this->id;
            }

            public function label(): string
            {
                return $this->label;
            }

            public function sections(): array
            {
                return $this->sections;
            }
        };
    }
}
