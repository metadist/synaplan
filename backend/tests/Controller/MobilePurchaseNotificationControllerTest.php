<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Service\Iap\Exception\IapNotConfiguredException;
use App\Service\MobilePurchaseService;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The Apple ASSN V2 webhook is the only channel through which a cancellation,
 * refund or renewal reaches us — the app is closed when those happen. Whether
 * the endpoint acknowledges therefore decides whether such an event is applied,
 * retried, or lost, and only a request-level test pins that down.
 */
#[CoversClass(\App\Controller\MobilePurchaseController::class)]
final class MobilePurchaseNotificationControllerTest extends WebTestCase
{
    public function testRejectsAPayloadWithoutASignature(): void
    {
        self::ensureKernelShutdown();
        $client = static::createClient();

        $client->request(
            'POST',
            '/api/v1/iap/apple/notifications',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: '{}'
        );

        self::assertSame(400, $client->getResponse()->getStatusCode());
    }

    /**
     * A misconfigured server must NOT acknowledge. Apple treats a 2xx as
     * delivered and never resends, so acknowledging would silently discard the
     * cancellation of a subscription that the user has really cancelled — the
     * failure mode a wiped root-certificate mount produced in production.
     */
    public function testDoesNotAcknowledgeWhenIapIsNotConfigured(): void
    {
        self::ensureKernelShutdown();
        $client = static::createClient();

        $service = $this->createStub(MobilePurchaseService::class);
        $service->method('verifyAppleNotification')
            ->willThrowException(new IapNotConfiguredException('no root certificates'));
        $client->getContainer()->set(MobilePurchaseService::class, $service);

        $client->request(
            'POST',
            '/api/v1/iap/apple/notifications',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['signedPayload' => 'irrelevant.but.present'], JSON_THROW_ON_ERROR)
        );

        self::assertSame(
            503,
            $client->getResponse()->getStatusCode(),
            'Acknowledging a notification the server could not process loses it permanently.'
        );
    }
}
