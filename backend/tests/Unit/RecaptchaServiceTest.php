<?php

namespace App\Tests\Unit;

use App\Service\RecaptchaService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class RecaptchaServiceTest extends TestCase
{
    private LoggerInterface $logger;

    protected function setUp(): void
    {
        $this->logger = $this->createMock(LoggerInterface::class);
    }

    public function testServiceIsDisabledWhenEnabledIsFalse(): void
    {
        $service = new RecaptchaService(
            $this->logger,
            'test_secret_key',
            'false', // disabled
            '0.5'
        );

        $this->assertFalse($service->isEnabled());

        // Verify should return true when disabled (dev mode)
        $result = $service->verify('fake_token', 'login', '127.0.0.1');
        $this->assertTrue($result);
    }

    public function testServiceIsDisabledWhenSecretKeyIsDefault(): void
    {
        $service = new RecaptchaService(
            $this->logger,
            'your_secret_key_here', // default placeholder
            'true',
            '0.5'
        );

        $this->assertFalse($service->isEnabled());

        // Should still pass verification when disabled
        $result = $service->verify('fake_token', 'register', '127.0.0.1');
        $this->assertTrue($result);
    }

    public function testServiceIsDisabledWhenSecretKeyIsEmpty(): void
    {
        $service = new RecaptchaService(
            $this->logger,
            '',
            'true',
            '0.5'
        );

        $this->assertFalse($service->isEnabled());
    }

    public function testServiceIsEnabledWithValidConfiguration(): void
    {
        $service = new RecaptchaService(
            $this->logger,
            'valid_secret_key_123',
            'true',
            '0.5'
        );

        $this->assertTrue($service->isEnabled());
    }

    public function testMinScoreIsCorrectlyParsed(): void
    {
        // Test with different score values
        $service1 = new RecaptchaService($this->logger, 'key', 'false', '0.3');
        $service2 = new RecaptchaService($this->logger, 'key', 'false', '0.7');
        $service3 = new RecaptchaService($this->logger, 'key', 'false', '1.0');

        // Since service is disabled, verify should always return true
        $this->assertTrue($service1->verify('token', 'test', null));
        $this->assertTrue($service2->verify('token', 'test', null));
        $this->assertTrue($service3->verify('token', 'test', null));
    }

    public function testVerifyReturnsTrueWhenDisabled(): void
    {
        $service = new RecaptchaService(
            $this->logger,
            'test_key',
            'false',
            '0.5'
        );

        $this->logger->expects($this->once())
            ->method('debug')
            ->with('reCAPTCHA verification skipped (disabled)');

        $result = $service->verify('any_token', 'any_action', '1.2.3.4');

        $this->assertTrue($result);
    }

    public function testServiceLogsWhenEnabled(): void
    {
        $this->logger->expects($this->once())
            ->method('info')
            ->with(
                'reCAPTCHA v3 enabled',
                ['min_score' => 0.5]
            );

        new RecaptchaService(
            $this->logger,
            'valid_secret_key',
            'true',
            '0.5'
        );
    }

    public function testServiceLogsWhenDisabled(): void
    {
        $this->logger->expects($this->once())
            ->method('info')
            ->with('reCAPTCHA v3 disabled (dev mode or not configured)');

        new RecaptchaService(
            $this->logger,
            'test_key',
            'false',
            '0.5'
        );
    }

    public function testEnabledParsesStringTrue(): void
    {
        $service = new RecaptchaService(
            $this->logger,
            'valid_key',
            'true',
            '0.5'
        );

        $this->assertTrue($service->isEnabled());
    }

    public function testEnabledParsesString1(): void
    {
        $service = new RecaptchaService(
            $this->logger,
            'valid_key',
            '1',
            '0.5'
        );

        $this->assertTrue($service->isEnabled());
    }

    public function testEnabledParsesStringFalse(): void
    {
        $service = new RecaptchaService(
            $this->logger,
            'valid_key',
            'false',
            '0.5'
        );

        $this->assertFalse($service->isEnabled());
    }

    public function testEnabledParsesString0(): void
    {
        $service = new RecaptchaService(
            $this->logger,
            'valid_key',
            '0',
            '0.5'
        );

        $this->assertFalse($service->isEnabled());
    }
}
