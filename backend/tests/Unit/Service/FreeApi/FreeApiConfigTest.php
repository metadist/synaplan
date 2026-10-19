<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\FreeApi;

use App\Repository\ConfigRepository;
use App\Service\Feature\FeatureFlagEnv;
use App\Service\FreeApi\FreeApiConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FreeApiConfigTest extends TestCase
{
    public function testOffWithoutRowAndWithoutPin(): void
    {
        $config = $this->config(row: null, env: []);

        $this->assertFalse($config->isEnabled());
        $this->assertFalse($config->isConfigured());
    }

    public function testTheAdminRowTurnsItOn(): void
    {
        $this->assertTrue($this->config(row: 'true', env: [])->isConfigured());
        $this->assertFalse($this->config(row: 'garbage', env: [])->isEnabled(), 'an unreadable row never turns the Demo on');
    }

    public function testThePinWinsOverTheRow(): void
    {
        $this->assertFalse($this->config(row: 'true', env: ['FEATURE_FREEAPI_ENABLED' => 'false'])->isEnabled());
        $this->assertTrue($this->config(row: null, env: ['FEATURE_FREEAPI_ENABLED' => 'true'])->isEnabled());
    }

    public function testEnabledWithoutAServerIsNotConfigured(): void
    {
        $this->assertFalse($this->config(row: 'true', env: [], baseUrl: '')->isConfigured());
    }

    /**
     * @return iterable<string, array{string, string, bool, ?string}>
     */
    public static function baseUrls(): iterable
    {
        yield 'https in prod' => ['https://web.synaplan.com/', 'prod', false, 'https://web.synaplan.com'];
        yield 'https in CI is refused' => ['https://web.synaplan.com', 'prod', true, null];
        yield 'https in the test env is refused' => ['https://web.synaplan.com', 'test', false, null];
        yield 'http in prod is refused' => ['http://web.synaplan.com', 'prod', false, null];
        yield 'http fake in dev' => ['http://freeapi-fake:8787', 'dev', false, 'http://freeapi-fake:8787'];
        yield 'http fake in test' => ['http://127.0.0.1:8787', 'test', true, 'http://127.0.0.1:8787'];
        yield 'userinfo is refused' => ['https://user:pw@web.synaplan.com', 'prod', false, null];
        yield 'query is refused' => ['https://web.synaplan.com?x=1', 'prod', false, null];
        yield 'fragment is refused' => ['https://web.synaplan.com#x', 'prod', false, null];
        yield 'other scheme is refused' => ['ftp://web.synaplan.com', 'dev', false, null];
        yield 'garbage' => ['not a url', 'prod', false, null];
    }

    #[DataProvider('baseUrls')]
    public function testBaseUrlRules(string $raw, string $environment, bool $ci, ?string $expected): void
    {
        $this->assertSame($expected, $this->config(row: 'true', env: [], baseUrl: $raw, environment: $environment, ci: $ci)->baseUrl());
    }

    public function testServerHostNamesTheConfiguredServer(): void
    {
        $this->assertSame('web.synaplan.com', $this->config(row: 'true', env: [])->serverHost());
        $this->assertNull($this->config(row: 'true', env: [], baseUrl: '')->serverHost());
    }

    public function testPlatformAndVersion(): void
    {
        $this->assertSame('dev', $this->config(row: null, env: [], environment: 'dev', platform: 'helm')->platform());
        $this->assertSame('helm', $this->config(row: null, env: [], platform: 'HELM')->platform());
        $this->assertSame('selfhost', $this->config(row: null, env: [], platform: 'elestio')->platform());
        $this->assertSame('dev', $this->config(row: null, env: [], version: '')->appVersion());
        $this->assertSame(32, \strlen($this->config(row: null, env: [], version: str_repeat('9', 40))->appVersion()));
    }

    /**
     * @param array<string, string> $env
     */
    private function config(
        ?string $row,
        array $env,
        string $baseUrl = 'https://web.synaplan.com',
        string $environment = 'prod',
        bool $ci = false,
        string $version = '4.9.0',
        string $platform = 'selfhost',
    ): FreeApiConfig {
        $repository = $this->createStub(ConfigRepository::class);
        $repository->method('getValue')->willReturn($row);

        return new FreeApiConfig($repository, new FeatureFlagEnv($env), $baseUrl, $environment, $ci, $version, $platform);
    }
}
