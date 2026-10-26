<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Widget;

use App\Entity\ApiKey;
use App\Entity\User;
use App\Exception\ApiKeyPlanRequiredException;
use App\Repository\ApiKeyRepository;
use App\Service\Billing\BillingService;
use App\Service\Billing\PremiumFeatureGate;
use App\Service\Widget\WordPressApiKeyIssuer;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class WordPressApiKeyIssuerTest extends TestCase
{
    private ApiKeyRepository&MockObject $apiKeyRepository;

    protected function setUp(): void
    {
        $this->apiKeyRepository = $this->createMock(ApiKeyRepository::class);
    }

    public function testFreeAccountIsRefusedWhileTheInstallSellsSubscriptions(): void
    {
        $this->apiKeyRepository->expects(self::never())->method('save');

        $this->expectException(ApiKeyPlanRequiredException::class);
        $this->issuer(billing: true)->issueFor(self::user(7, 'NEW'));
    }

    public function testFreeAccountGetsAKeyWhenTheInstallHasNoBilling(): void
    {
        $this->apiKeyRepository->method('findBy')->willReturn([]);
        $this->apiKeyRepository->expects(self::once())->method('save');

        $issued = $this->issuer(billing: false)->issueFor(self::user(7, 'NEW'));

        self::assertStringStartsWith('sk_live_', $issued['api_key']);
        self::assertFalse($issued['existing']);
    }

    public function testPaidAccountReusesItsActivePluginKey(): void
    {
        $existing = new ApiKey();
        $existing->setName(WordPressApiKeyIssuer::KEY_NAME);
        $existing->setKey('sk_live_existing');
        $this->apiKeyRepository->method('findBy')->willReturn([$existing]);
        $this->apiKeyRepository->expects(self::never())->method('save');

        $issued = $this->issuer(billing: true)->issueFor(self::user(7, 'PRO'));

        self::assertSame(['api_key' => 'sk_live_existing', 'existing' => true], $issued);
    }

    private function issuer(bool $billing): WordPressApiKeyIssuer
    {
        $billingService = $billing
            ? new BillingService('sk_test_valid_key', 'price_1RealProId')
            : new BillingService('', '');

        return new WordPressApiKeyIssuer($this->apiKeyRepository, new PremiumFeatureGate($billingService), new NullLogger());
    }

    private static function user(int $id, string $level): User
    {
        $user = new User();
        (new \ReflectionProperty(User::class, 'id'))->setValue($user, $id);
        $user->setUserLevel($level);

        return $user;
    }
}
