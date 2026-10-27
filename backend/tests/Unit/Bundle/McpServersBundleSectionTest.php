<?php

declare(strict_types=1);

namespace App\Tests\Unit\Bundle;

use App\Bundle\ImportOptions;
use App\Bundle\Section\McpServersBundleSection;
use App\Entity\McpServerConfig;
use App\Repository\McpServerConfigRepository;
use App\Service\Security\EncryptionService;
use App\Service\Tool\ToolsConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class McpServersBundleSectionTest extends TestCase
{
    private const USER_ID = 7;

    private EncryptionService $encryption;

    protected function setUp(): void
    {
        $this->encryption = new EncryptionService('test-secret', new NullLogger());
    }

    public function testReplaceSkipsAServerWhoseAddressDiffers(): void
    {
        $existing = $this->existing('https://api.github.example/mcp', allowWrite: false);
        $repo = $this->repository([$existing]);
        $repo->expects(self::never())->method('save');

        $result = $this->section($repo)->apply(
            [['key' => 'GitHub', 'name' => 'GitHub', 'url' => 'https://elsewhere.example/mcp', 'authMode' => 'bearer']],
            self::USER_ID,
            new ImportOptions(ImportOptions::CONFLICT_OVERWRITE),
        );

        self::assertSame(['GitHub'], $result->toArray()['skipped']);
        self::assertSame('https://api.github.example/mcp', $existing->getUrl());
        self::assertSame('secret', $existing->getDecryptedAuthToken($this->encryption));
    }

    public function testReplaceAtTheSameAddressKeepsTheWriteSwitchAsThePersonSetIt(): void
    {
        $existing = $this->existing('https://api.github.example/mcp', allowWrite: false);
        $repo = $this->repository([$existing]);
        $repo->expects(self::once())->method('save')->with($existing);

        $result = $this->section($repo)->apply(
            [['key' => 'GitHub', 'name' => 'GitHub', 'url' => 'https://api.github.example/mcp', 'authMode' => 'bearer', 'allowWrite' => true]],
            self::USER_ID,
            new ImportOptions(ImportOptions::CONFLICT_OVERWRITE),
        );

        self::assertSame(['GitHub'], $result->toArray()['created']);
        self::assertFalse($existing->allowsWrite());
        self::assertTrue($existing->hasAuthToken());
    }

    public function testANewServerFromAFileHasWriteActionsOff(): void
    {
        $saved = null;
        $repo = $this->repository([]);
        $repo->expects(self::once())->method('save')->willReturnCallback(
            static function (McpServerConfig $server) use (&$saved): void {
                $saved = $server;
            }
        );

        $this->section($repo)->apply(
            [['key' => 'Wiki', 'name' => 'Wiki', 'url' => 'https://wiki.example/mcp', 'allowWrite' => true]],
            self::USER_ID,
            new ImportOptions(),
        );

        self::assertInstanceOf(McpServerConfig::class, $saved);
        self::assertFalse($saved->allowsWrite());
    }

    public function testPreviewNamesTheAddressAndTheWriteSwitch(): void
    {
        $repo = $this->createStub(McpServerConfigRepository::class);
        $repo->method('findByUser')->willReturn([$this->existing('https://api.github.example/mcp', allowWrite: false)]);

        $preview = $this->section($repo)->preview([
            ['key' => 'GitHub', 'name' => 'GitHub', 'url' => 'https://elsewhere.example/mcp', 'authMode' => 'none'],
            ['key' => 'Wiki', 'name' => 'Wiki', 'url' => 'https://wiki.example/mcp', 'authMode' => 'none', 'allowWrite' => true],
        ], self::USER_ID);

        $codes = array_map(
            static fn (array $row): string => $row['code'].':'.$row['itemKey'],
            $preview->toArray()['items'],
        );
        self::assertContains('addressDiffers:GitHub', $codes);
        self::assertNotContains('conflict:GitHub', $codes);
        self::assertContains('writeOff:Wiki', $codes);
    }

    public function testPreviewDoesNotPromiseOffWhenThePersonAlreadyAllowsWrites(): void
    {
        $repo = $this->createStub(McpServerConfigRepository::class);
        $repo->method('findByUser')->willReturn([$this->existing('https://api.github.example/mcp', allowWrite: true)]);

        $preview = $this->section($repo)->preview([
            ['key' => 'GitHub', 'name' => 'GitHub', 'url' => 'https://api.github.example/mcp', 'authMode' => 'none', 'allowWrite' => true],
        ], self::USER_ID);

        $codes = array_column($preview->toArray()['items'], 'code');
        self::assertNotContains('writeOff', $codes);
    }

    /**
     * @param list<McpServerConfig> $servers
     */
    private function repository(array $servers): McpServerConfigRepository&MockObject
    {
        $repo = $this->createMock(McpServerConfigRepository::class);
        $repo->method('findByUser')->willReturn($servers);

        return $repo;
    }

    private function section(McpServerConfigRepository $repo): McpServersBundleSection
    {
        return new McpServersBundleSection($repo, $this->createStub(ToolsConfig::class));
    }

    private function existing(string $url, bool $allowWrite): McpServerConfig
    {
        $server = (new McpServerConfig())
            ->setUserId(self::USER_ID)
            ->setName('GitHub')
            ->setUrl($url)
            ->setAuthHeader('Authorization')
            ->setAllowWrite($allowWrite);
        $server->setDecryptedAuthToken('secret', $this->encryption);

        return $server;
    }
}
