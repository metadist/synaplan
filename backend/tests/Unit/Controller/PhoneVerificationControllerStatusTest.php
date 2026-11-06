<?php

declare(strict_types=1);

namespace App\Tests\Unit\Controller;

use App\Controller\PhoneVerificationController;
use App\Entity\User;
use App\Service\WhatsApp\WhatsAppDisplayNumbers;
use App\Service\WhatsApp\WhatsAppService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\Container;

/**
 * Status (and the issued-code payload) must carry the install WhatsApp
 * numbers so the verification page can list wa.me links without hardcoding.
 */
final class PhoneVerificationControllerStatusTest extends TestCase
{
    public function testStatusIncludesInboundNumbersFromTheInstall(): void
    {
        $display = new WhatsAppDisplayNumbers('+4915116038214,+16282253244');
        $whatsApp = $this->createStub(WhatsAppService::class);
        $whatsApp->method('isAvailable')->willReturn(true);

        $controller = new PhoneVerificationController(
            $this->createStub(EntityManagerInterface::class),
            $whatsApp,
            $display,
            new NullLogger(),
        );
        $controller->setContainer(new Container());

        $user = $this->createStub(User::class);
        $user->method('getUserDetails')->willReturn([]);

        $response = $controller->getStatus($user);
        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getContent(), true, 512, \JSON_THROW_ON_ERROR);

        self::assertTrue($body['success']);
        self::assertSame(
            [
                [
                    'e164' => '+4915116038214',
                    'wa_me' => 'https://wa.me/4915116038214',
                ],
                [
                    'e164' => '+16282253244',
                    'wa_me' => 'https://wa.me/16282253244',
                ],
            ],
            $body['inbound_numbers'],
        );
    }

    public function testStatusInboundNumbersStayEmptyWhenUnset(): void
    {
        $whatsApp = $this->createStub(WhatsAppService::class);
        $whatsApp->method('isAvailable')->willReturn(false);

        $controller = new PhoneVerificationController(
            $this->createStub(EntityManagerInterface::class),
            $whatsApp,
            new WhatsAppDisplayNumbers(''),
            new NullLogger(),
        );
        $controller->setContainer(new Container());

        $user = $this->createStub(User::class);
        $user->method('getUserDetails')->willReturn([]);

        $response = $controller->getStatus($user);
        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getContent(), true, 512, \JSON_THROW_ON_ERROR);

        self::assertSame([], $body['inbound_numbers']);
    }
}
