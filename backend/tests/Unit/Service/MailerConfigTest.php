<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\MailerConfig;
use PHPUnit\Framework\TestCase;

final class MailerConfigTest extends TestCase
{
    private bool $envWasSet;

    private ?string $originalEnv;

    protected function setUp(): void
    {
        $this->envWasSet = \array_key_exists('MAILER_DSN', $_ENV);
        $this->originalEnv = $this->envWasSet ? (string) $_ENV['MAILER_DSN'] : null;
    }

    protected function tearDown(): void
    {
        if ($this->envWasSet) {
            $_ENV['MAILER_DSN'] = $this->originalEnv;
        } else {
            unset($_ENV['MAILER_DSN']);
        }
    }

    public function testUnsetDsnMeansNothingIsDelivered(): void
    {
        unset($_ENV['MAILER_DSN']);

        $this->assertFalse((new MailerConfig())->isConfigured());
    }

    public function testNullTransportMeansNothingIsDelivered(): void
    {
        $_ENV['MAILER_DSN'] = 'null://null';

        $this->assertFalse((new MailerConfig())->isConfigured());
    }

    public function testARealDsnCountsAsConfigured(): void
    {
        $_ENV['MAILER_DSN'] = 'smtp://localhost:1025';

        $this->assertTrue((new MailerConfig())->isConfigured());
    }
}
