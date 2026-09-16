<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Service\GuestChatConfig;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Uid\Uuid;

/**
 * Issue #1517: guest turns flow through the stream endpoint's guestSession
 * parameter, not only through the (separately gated) guest endpoints, so a
 * disabled trial must refuse here too - with the discriminating code instead
 * of the expired-session 401 a stale client would misread as "start over".
 */
final class StreamControllerGuestDisabledTest extends WebTestCase
{
    public function testGuestTurnReturns403WhenGuestChatIsDisabled(): void
    {
        $_ENV['GUEST_CHAT_ENABLED'] = 'false';

        try {
            self::ensureKernelShutdown();
            $client = static::createClient();

            $client->request(
                'POST',
                '/api/v1/messages/stream?guestSession='.Uuid::v4()->toRfc4122(),
                [],
                [],
                ['CONTENT_TYPE' => 'application/json'],
                '{}'
            );

            self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
            $data = json_decode($client->getResponse()->getContent(), true);
            self::assertSame(GuestChatConfig::DISABLED_CODE, $data['code'] ?? null);
        } finally {
            unset($_ENV['GUEST_CHAT_ENABLED']);
        }
    }
}
