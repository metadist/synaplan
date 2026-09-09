<?php

declare(strict_types=1);

namespace App\Tests\Unit\Bundle;

use App\Bundle\ImportOptions;
use App\Bundle\Section\PromptBundleSection;
use App\Bundle\SectionPreview;
use App\Entity\Model;
use App\Entity\Prompt;
use App\Repository\ModelRepository;
use App\Repository\PromptRepository;
use App\Service\PromptService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Prompt metadata points at rows that only exist in the exporting install:
 * `aiModel` is a BMODELS id and `mcp_servers` is a list of server ids. Neither
 * may cross the bundle boundary — a foreign BID would silently pin the
 * imported prompt to whatever model happens to hold that id here.
 */
final class PromptBundleSectionMetaTest extends TestCase
{
    private const USER = 4;

    private PromptRepository&MockObject $prompts;
    private PromptService&MockObject $promptService;
    private ModelRepository&MockObject $models;
    private PromptBundleSection $section;

    protected function setUp(): void
    {
        $this->prompts = $this->createMock(PromptRepository::class);
        $this->promptService = $this->createMock(PromptService::class);
        $this->models = $this->createMock(ModelRepository::class);
        $this->section = new PromptBundleSection($this->prompts, $this->promptService, $this->models);
    }

    public function testExportReplacesTheModelIdWithACatalogKeyAndDropsMcpServers(): void
    {
        $this->givenPrompt(['aiModel' => 76, 'mcp_servers' => '3,7', 'tool_internet' => true]);
        $this->models->method('find')->willReturnMap([[76, null, null, $this->model('Groq', 'openai/gpt-oss-120b', 'chat')]]);

        $meta = $this->exportedMeta();

        self::assertArrayNotHasKey('aiModel', $meta, 'a foreign BMODELS id must never be exported');
        self::assertArrayNotHasKey('mcp_servers', $meta, 'MCP server ids are instance-local');
        self::assertSame('Groq:openai/gpt-oss-120b:chat', $meta['aiModelKey']);
        self::assertTrue($meta['tool_internet'], 'portable metadata is kept');
    }

    public function testExportDropsAModelThatIsNotInTheCatalog(): void
    {
        $this->givenPrompt(['aiModel' => 76]);
        $this->models->method('find')->willReturn($this->model('Acme', 'private-model', 'chat'));

        $meta = $this->exportedMeta();

        self::assertArrayNotHasKey('aiModelKey', $meta);
        self::assertArrayNotHasKey('aiModel', $meta);
    }

    public function testExportDropsAModelRowThatNoLongerExists(): void
    {
        $this->givenPrompt(['aiModel' => 999]);
        $this->models->method('find')->willReturn(null);

        self::assertArrayNotHasKey('aiModelKey', $this->exportedMeta());
    }

    public function testImportResolvesTheCatalogKeyBackToALocalModelId(): void
    {
        $saved = $this->captureImportedMeta(['aiModelKey' => 'Groq:openai/gpt-oss-120b:chat', 'tool_internet' => true]);

        self::assertArrayHasKey('aiModel', $saved);
        self::assertIsInt($saved['aiModel']);
        self::assertArrayNotHasKey('aiModelKey', $saved, 'the portable key is not stored as metadata');
        self::assertTrue($saved['tool_internet']);
    }

    public function testImportDropsAnUnknownModelKeyInsteadOfGuessing(): void
    {
        $saved = $this->captureImportedMeta(['aiModelKey' => 'Acme:private-model:chat']);

        self::assertArrayNotHasKey('aiModel', $saved);
    }

    public function testImportIgnoresARawModelIdFromAForeignBundle(): void
    {
        $saved = $this->captureImportedMeta(['aiModel' => 76, 'mcp_servers' => '3,7']);

        self::assertArrayNotHasKey('aiModel', $saved);
        self::assertArrayNotHasKey('mcp_servers', $saved);
    }

    public function testPreviewFlagsAModelThisInstanceDoesNotHave(): void
    {
        $preview = $this->section->preview(
            [['key' => 'legal', 'topic' => 'legal', 'meta' => ['aiModelKey' => 'Acme:private-model:chat']]],
            self::USER,
        );

        self::assertInstanceOf(SectionPreview::class, $preview);
        $codes = array_map(static fn (object $row): string => $row->code, $preview->items);
        self::assertContains('needsModel', $codes);
    }

    /**
     * @param array<string, mixed> $meta
     */
    private function givenPrompt(array $meta): void
    {
        $prompt = new Prompt();
        $prompt->setOwnerId(self::USER);
        $prompt->setTopic('legal');
        $prompt->setPrompt('Review NDAs.');
        (new \ReflectionProperty(Prompt::class, 'id'))->setValue($prompt, 11);
        $this->prompts->method('findOwnedForListing')->willReturn([$prompt]);
        $this->promptService->method('loadMetadataForPrompt')->willReturn($meta);
    }

    /**
     * @return array<string, mixed>
     */
    private function exportedMeta(): array
    {
        $items = $this->section->export(self::USER, \App\Bundle\BundleScope::User);
        self::assertCount(1, $items);
        self::assertIsArray($items[0]['meta']);

        return $items[0]['meta'];
    }

    /**
     * @param array<string, mixed> $meta
     *
     * @return array<string, mixed>
     */
    private function captureImportedMeta(array $meta): array
    {
        $this->prompts->method('findByTopicAndUser')->willReturn(null);
        $this->promptService->method('createOwned')->willReturn(new Prompt());
        $saved = [];
        $this->promptService->method('saveMetadataForPrompt')->willReturnCallback(
            static function (Prompt $_prompt, array $metadata) use (&$saved): void {
                $saved = $metadata;
            }
        );

        $this->section->apply(
            [['key' => 'legal', 'topic' => 'legal', 'text' => 'Review NDAs.', 'meta' => $meta]],
            self::USER,
            new ImportOptions(),
        );

        return $saved;
    }

    private function model(string $service, string $providerId, string $tag): Model
    {
        $model = new Model();
        $model->setService($service);
        $model->setProviderId($providerId);
        $model->setTag($tag);

        return $model;
    }
}
