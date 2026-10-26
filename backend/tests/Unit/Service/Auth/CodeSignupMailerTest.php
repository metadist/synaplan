<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Auth;

use App\Service\Auth\CodeSignupMailer;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

final class CodeSignupMailerTest extends TestCase
{
    public function testATransportFailureIsRaisedSoTheWorkerCanRetry(): void
    {
        $mailer = $this->createStub(MailerInterface::class);
        $mailer->method('send')->willThrowException(new TransportException('smtp down'));
        $twig = $this->createStub(Environment::class);
        $twig->method('render')->willReturn('<p>link</p>');
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturn('text');

        $service = new CodeSignupMailer($mailer, $twig, $translator, new NullLogger(), 'https://example.test');

        $this->expectException(TransportException::class);
        $service->send('ada@example.com', str_repeat('a', 64), 'en');
    }
}
