<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Widget;

use App\Entity\Widget;
use App\Service\Widget\WidgetVisitorIdentity;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\MockClock;

final class WidgetVisitorIdentityTest extends TestCase
{
    private const NOW = 1_800_000_000;

    /** @var list<array{0: string, 1: array<string, mixed>}> */
    private array $logged = [];
    private int $flushes = 0;

    public function testASignedIdIsAccepted(): void
    {
        $widget = $this->widget();
        $signature = $this->identity()->sign($widget, 'user-1', self::NOW + 600);

        self::assertSame('user-1', $this->identity()->verified($widget, ['externalUserId' => ' user-1 ', 'externalUserIdSignature' => $signature]));
    }

    public function testTheSignatureIsTheDocumentedHmac(): void
    {
        $widget = $this->widget();
        $expiry = self::NOW + 600;
        $mac = hash_hmac('sha256', "wdg_1\nuser-1\n".$expiry, $this->identity()->signingKey($widget));

        self::assertSame('user-1', $this->identity()->verified($widget, ['externalUserId' => 'user-1', 'externalUserIdSignature' => $expiry.'.'.$mac]));
    }

    /**
     * @return iterable<string, array{0: mixed, 1: string}>
     */
    public static function refusedSignatures(): iterable
    {
        yield 'none' => [null, 'unsigned'];
        yield 'empty' => ['', 'unsigned'];
        yield 'not a string' => [['x'], 'unsigned'];
        yield 'no expiry' => [str_repeat('a', 64), 'malformed'];
        yield 'upper-case mac' => [(self::NOW + 600).'.'.str_repeat('A', 64), 'malformed'];
        yield 'wrong mac' => [(self::NOW + 600).'.'.str_repeat('a', 64), 'bad_signature'];
    }

    #[DataProvider('refusedSignatures')]
    public function testAnIdWithoutAValidSignatureIsRefused(mixed $signature, string $reason): void
    {
        $id = $this->identity()->verified($this->widget(), ['externalUserId' => 'user-1', 'externalUserIdSignature' => $signature]);

        self::assertSame(['', $reason], [$id, $this->logged[0][1]['reason'] ?? null]);
    }

    public function testASignatureCoversTheIdTheWidgetAndTheExpiry(): void
    {
        $identity = $this->identity();
        $widget = $this->widget();
        $signature = $identity->sign($widget, 'user-1', self::NOW + 600);
        [, $mac] = explode('.', $signature);

        self::assertSame(['', '', ''], [
            $identity->verified($widget, ['externalUserId' => 'user-2', 'externalUserIdSignature' => $signature]),
            $identity->verified($this->widget('wdg_2'), ['externalUserId' => 'user-1', 'externalUserIdSignature' => $signature]),
            $identity->verified($widget, ['externalUserId' => 'user-1', 'externalUserIdSignature' => (self::NOW + 900).'.'.$mac]),
        ]);
    }

    public function testAnExpiredOrFarFutureSignatureIsRefused(): void
    {
        $identity = $this->identity();
        $widget = $this->widget();
        $reasons = [];
        foreach ([self::NOW, self::NOW - 1, self::NOW + WidgetVisitorIdentity::MAX_LIFETIME_SECONDS + 301] as $expiry) {
            $identity->verified($widget, ['externalUserId' => 'user-1', 'externalUserIdSignature' => $identity->sign($widget, 'user-1', $expiry)]);
            $reasons[] = array_pop($this->logged)[1]['reason'];
        }

        self::assertSame(['expired', 'expired', 'lifetime_too_long'], $reasons);
        self::assertSame('user-1', $identity->verified($widget, ['externalUserId' => 'user-1', 'externalUserIdSignature' => $identity->sign($widget, 'user-1', self::NOW + WidgetVisitorIdentity::MAX_LIFETIME_SECONDS)]));
    }

    public function testNoIdNeedsNoSignature(): void
    {
        self::assertSame(['', '', []], [
            $this->identity()->verified($this->widget(), []),
            $this->identity()->verified($this->widget(), ['externalUserId' => ['user-1']]),
            $this->logged,
        ]);
    }

    public function testAnOverlongIdIsRefused(): void
    {
        $identity = $this->identity();
        $widget = $this->widget();
        $id = str_repeat('a', 256);

        self::assertSame('', $identity->verified($widget, ['externalUserId' => $id, 'externalUserIdSignature' => $identity->sign($widget, $id, self::NOW + 600)]));
    }

    public function testEachWidgetHasItsOwnKey(): void
    {
        $identity = $this->identity();

        self::assertNotSame($identity->signingKey($this->widget()), $identity->signingKey($this->widget('wdg_2')));
        self::assertNotSame($identity->signingKey($this->widget()), $this->identity('another-app-secret')->signingKey($this->widget()));
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $identity->signingKey($this->widget()));
    }

    public function testRotatingTheKeyEndsOldSignatures(): void
    {
        $identity = $this->identity();
        $widget = $this->widget();
        $oldKey = $identity->signingKey($widget);
        $oldSignature = $identity->sign($widget, 'user-1', self::NOW + 600);

        $newKey = $identity->rotate($widget);

        self::assertNotSame($oldKey, $newKey);
        self::assertSame([$newKey, 2, 1], [$identity->signingKey($widget), $widget->getConfigValue(WidgetVisitorIdentity::KEY_VERSION), $this->flushes]);
        self::assertSame('', $identity->verified($widget, ['externalUserId' => 'user-1', 'externalUserIdSignature' => $oldSignature]));
        self::assertSame('user-1', $identity->verified($widget, ['externalUserId' => 'user-1', 'externalUserIdSignature' => $identity->sign($widget, 'user-1', self::NOW + 600)]));
    }

    public function testTheLogNeverHoldsTheIdOrTheKey(): void
    {
        $identity = $this->identity();
        $widget = $this->widget();
        $identity->verified($widget, ['externalUserId' => 'customer-4711', 'externalUserIdSignature' => 'nope']);
        $identity->verified($widget, ['externalUserId' => 'customer-4711', 'externalUserIdSignature' => $identity->sign($widget, 'customer-4711', self::NOW + 600)]);
        $identity->rotate($widget);

        $written = json_encode($this->logged, JSON_THROW_ON_ERROR);
        self::assertCount(3, $this->logged);
        self::assertStringNotContainsString('customer-4711', $written);
        self::assertStringNotContainsString($identity->signingKey($widget), $written);
        self::assertSame($this->logged[0][1]['visitor'], $this->logged[1][1]['visitor']);
    }

    public function testWithoutAnAppSecretNothingIsAccepted(): void
    {
        $identity = $this->identity('');

        self::assertSame('', $identity->verified($this->widget(), ['externalUserId' => 'user-1', 'externalUserIdSignature' => (self::NOW + 600).'.'.hash_hmac('sha256', "wdg_1\nuser-1\n".(self::NOW + 600), '')]));
        $this->expectException(\LogicException::class);
        $identity->signingKey($this->widget());
    }

    public function testAWidgetFromBeforeSigningKeepsItsUnsignedIds(): void
    {
        $identity = $this->identity();
        $legacy = $this->widget(config: [WidgetVisitorIdentity::UNSIGNED_ALLOWED => true]);

        self::assertFalse($identity->signatureRequired($legacy));
        self::assertSame('user-1', $identity->verified($legacy, ['externalUserId' => 'user-1']));
        self::assertSame('Widget visitor id used without a signature', $this->logged[0][0]);
        self::assertSame('user-1', $identity->verified($legacy, ['externalUserId' => 'user-1', 'externalUserIdSignature' => $identity->sign($legacy, 'user-1', self::NOW + 600)]));
        self::assertSame('', $identity->verified($legacy, ['externalUserId' => 'user-1', 'externalUserIdSignature' => (self::NOW + 600).'.'.str_repeat('a', 64)]), 'a wrong signature is still refused');
        self::assertSame('user-1', $this->identity('')->verified($legacy, ['externalUserId' => 'user-1']), 'an unsigned id needs no key');
    }

    public function testOnlyTheExactMarkExemptsAWidget(): void
    {
        $identity = $this->identity();

        foreach ([1, 'true', 'yes', null, false] as $value) {
            $widget = $this->widget(config: [WidgetVisitorIdentity::UNSIGNED_ALLOWED => $value]);
            self::assertTrue($identity->signatureRequired($widget));
            self::assertSame('', $identity->verified($widget, ['externalUserId' => 'user-1']));
        }
    }

    public function testTurningSigningOnEndsUnsignedIdsAndKeepsTheKey(): void
    {
        $identity = $this->identity();
        $legacy = $this->widget(config: [WidgetVisitorIdentity::UNSIGNED_ALLOWED => true, 'primaryColor' => '#123456']);
        $key = $identity->signingKey($legacy);

        $identity->requireSignature($legacy);
        $identity->requireSignature($legacy);

        self::assertSame(1, $this->flushes);
        self::assertTrue($identity->signatureRequired($legacy));
        self::assertSame('#123456', $legacy->getConfigValue('primaryColor'));
        self::assertArrayNotHasKey(WidgetVisitorIdentity::UNSIGNED_ALLOWED, $legacy->getConfig());
        self::assertSame($key, $identity->signingKey($legacy));
        self::assertSame('', $identity->verified($legacy, ['externalUserId' => 'user-1']));
    }

    /**
     * @param array<string, mixed> $config
     */
    private function widget(string $widgetId = 'wdg_1', array $config = []): Widget
    {
        return (new Widget())->setWidgetId($widgetId)->setOwnerId(7)->setConfig($config);
    }

    private function identity(string $appSecret = 'test-app-secret'): WidgetVisitorIdentity
    {
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('flush')->willReturnCallback(function (): void {
            ++$this->flushes;
        });
        $logger = $this->createStub(LoggerInterface::class);
        $record = function (string|\Stringable $message, array $context = []): void {
            $this->logged[] = [(string) $message, $context];
        };
        foreach (['info', 'notice', 'warning'] as $level) {
            $logger->method($level)->willReturnCallback($record);
        }

        return new WidgetVisitorIdentity($appSecret, $em, $logger, new MockClock('@'.self::NOW));
    }
}
