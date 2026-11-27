<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Setup;

use App\Entity\Config;
use App\Repository\ConfigRepository;
use App\Service\Setup\SetupTokenService;
use PHPUnit\Framework\TestCase;

final class SetupTokenServiceTest extends TestCase
{
    private ?string $stored = null;

    public function testAnIssuedTokenMatchesHoweverItIsTyped(): void
    {
        $service = $this->service();

        $token = $service->issue();

        self::assertMatchesRegularExpression('/^([A-HJKMNP-Z2-9]{4}-){4}[A-HJKMNP-Z2-9]{4}$/', $token);
        self::assertTrue($service->matches($token));
        self::assertTrue($service->matches(' '.strtolower(str_replace('-', ' ', $token)).' '));
        self::assertNotSame($token, $this->stored, 'only a hash is stored');
    }

    public function testAWrongOrEmptyTokenDoesNotMatch(): void
    {
        $service = $this->service();
        $service->issue();

        self::assertFalse($service->matches(''));
        self::assertFalse($service->matches('AAAA-BBBB-CCCC-DDDD-EEEE'));
    }

    public function testNothingMatchesBeforeATokenWasIssued(): void
    {
        $service = $this->service();

        self::assertFalse($service->matches(''));
        self::assertFalse($service->matches('AAAA-BBBB-CCCC-DDDD-EEEE'));
    }

    public function testANewTokenRetiresTheEarlierOne(): void
    {
        $service = $this->service();
        $first = $service->issue();
        $second = $service->issue();

        self::assertFalse($service->matches($first));
        self::assertTrue($service->matches($second));

        $service->clear();

        self::assertFalse($service->matches($second));
    }

    private function service(): SetupTokenService
    {
        $repository = $this->createStub(ConfigRepository::class);
        $repository->method('getValue')->willReturnCallback(fn (): ?string => $this->stored);
        $repository->method('setValue')->willReturnCallback(function (int $owner, string $group, string $setting, string $value): Config {
            $this->stored = $value;

            return new Config();
        });
        $repository->method('deleteValue')->willReturnCallback(function (): bool {
            $this->stored = null;

            return true;
        });

        return new SetupTokenService($repository);
    }
}
