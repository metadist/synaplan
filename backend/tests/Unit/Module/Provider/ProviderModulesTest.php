<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\Provider;

use App\AI\Credential\HiggsfieldCredentialResolver;
use App\AI\Credential\ProviderKeyStore;
use App\Module\Provider\GoogleAiModule;
use App\Module\Provider\HiggsfieldModule;
use App\Module\Provider\TheHiveModule;
use App\Repository\ConfigRepository;
use App\Service\EncryptionService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class ProviderModulesTest extends TestCase
{
    public function testTheHiveIsConfiguredByANonEmptyKey(): void
    {
        $absent = new TheHiveModule('');
        $this->assertFalse($absent->isConfigured());
        $this->assertSame('absent', $absent->status()->state());
        $this->assertStringNotContainsString('sk-', $absent->status()->message);

        $present = new TheHiveModule('sk-live-secret');
        $this->assertTrue($present->isConfigured());
        $this->assertSame('available', $present->status()->state());
        $this->assertStringNotContainsString('sk-live-secret', $present->status()->message, 'never echo the key');
    }

    public function testHiggsfieldNeedsBothPlatformCredentials(): void
    {
        $this->assertFalse($this->higgsfield('', '')->isConfigured());
        $this->assertFalse($this->higgsfield('key', '')->isConfigured());
        $this->assertFalse($this->higgsfield('', 'secret')->isConfigured());

        $module = $this->higgsfield('key', 'secret');
        $this->assertTrue($module->isConfigured());
        $this->assertSame('available', $module->status()->state());
        $this->assertStringNotContainsString('secret', $module->status()->message);
    }

    public function testGoogleAiFollowsTheProviderKeyStore(): void
    {
        $absent = new GoogleAiModule($this->keyStore(['configured' => false]));
        $this->assertFalse($absent->isConfigured());
        $this->assertSame('absent', $absent->status()->state());

        $fromEnv = new GoogleAiModule($this->keyStore(['configured' => true, 'source' => 'env']));
        $this->assertTrue($fromEnv->isConfigured());
        $this->assertSame('available', $fromEnv->status()->state());
        $this->assertSame(['source' => 'env'], $fromEnv->status()->details);

        $fromDb = new GoogleAiModule($this->keyStore(['configured' => true, 'source' => 'db', 'maskedKey' => 'AI…xyz']));
        $this->assertTrue($fromDb->isConfigured(), 'a key entered at runtime counts without a restart');
        $this->assertSame(['source' => 'db'], $fromDb->status()->details, 'the masked key is not repeated in module status');
    }

    public function testGoogleAiOwnsAllThreeEnvAliases(): void
    {
        $keys = (new GoogleAiModule($this->keyStore(['configured' => false])))->configuredBy()->envKeys;

        foreach (['GOOGLE_GEMINI_API_KEY', 'GEMINI_API_KEY', 'GOOGLE_API_KEY'] as $alias) {
            $this->assertContains($alias, $keys);
        }
    }

    private function higgsfield(string $key, string $secret): HiggsfieldModule
    {
        $resolver = new HiggsfieldCredentialResolver(
            $this->createStub(ConfigRepository::class),
            $this->createStub(EncryptionService::class),
            new NullLogger(),
            $key,
            $secret,
        );

        return new HiggsfieldModule($resolver);
    }

    /**
     * @param array<string, mixed> $status
     */
    private function keyStore(array $status): ProviderKeyStore
    {
        $store = $this->createStub(ProviderKeyStore::class);
        $store->method('getStatus')->willReturn($status);

        return $store;
    }
}
