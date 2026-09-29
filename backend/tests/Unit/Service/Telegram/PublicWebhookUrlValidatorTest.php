<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Telegram;

use App\Service\Telegram\PublicWebhookUrlValidator;
use App\Service\Telegram\TelegramChannelException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PublicWebhookUrlValidatorTest extends TestCase
{
    private PublicWebhookUrlValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new PublicWebhookUrlValidator(false);
    }

    public function testHttpsPublicHostPasses(): void
    {
        $this->validator->assertPublic('https://chat.example.com');
        $this->addToAssertionCount(1);
    }

    #[DataProvider('rejectedUrls')]
    public function testRejectsUnreachableAddresses(string $url): void
    {
        $this->expectException(TelegramChannelException::class);
        try {
            $this->validator->assertPublic($url);
        } catch (TelegramChannelException $e) {
            $this->assertSame(TelegramChannelException::PUBLIC_URL_REQUIRED, $e->errorCode);
            throw $e;
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function rejectedUrls(): iterable
    {
        yield 'http' => ['http://chat.example.com'];
        yield 'localhost' => ['https://localhost'];
        yield 'loopback' => ['https://127.0.0.1'];
        yield 'private' => ['https://10.0.0.8'];
        yield 'link local' => ['https://169.254.1.1'];
        yield 'empty' => [''];
        yield 'local tld' => ['https://synaplan.local'];
    }

    public function testAllowLocalAcceptsHttpLocalhost(): void
    {
        $validator = new PublicWebhookUrlValidator(true);
        $validator->assertPublic('http://localhost:8000');
        $this->addToAssertionCount(1);
    }
}
