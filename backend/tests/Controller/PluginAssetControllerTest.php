<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Repository\ConfigRepository;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Plugin files served without a user in the address, and the transcriber
 * routes that authenticate with a shared secret instead of an account.
 */
final class PluginAssetControllerTest extends WebTestCase
{
    private const LOADER = '/api/v1/plugins/synascriber/assets/jitsi/loader.js';
    private const SESSION = '/api/v1/plugins/synascriber/public/transcriber/sessions/Ab3dEf9hJkLmN0pQrStUvW';
    private const GROUP = 'P_synascriber';
    private const SETTING = 'transcriber_secret';

    public function testABundledPluginFileIsServedWithoutSignIn(): void
    {
        $client = static::createClient();

        $client->request('GET', self::LOADER);
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/javascript');

        $client->request('HEAD', self::LOADER);
        self::assertResponseIsSuccessful('HEAD is public like GET, so a header-only check needs no account');
        self::assertResponseHeaderSame('Content-Type', 'application/javascript');
    }

    public function testNothingOutsideTheFrontendDirectoryIsServed(): void
    {
        $client = static::createClient();

        foreach ([
            '/api/v1/plugins/synascriber/assets/../manifest.json',
            '/api/v1/plugins/synascriber/assets/%2e%2e/manifest.json',
            '/api/v1/plugins/synascriber/assets/missing.js',
            '/api/v1/plugins/no_such_plugin/assets/index.js',
        ] as $path) {
            $client->request('GET', $path);
            self::assertResponseStatusCodeSame(404, $path);
        }
    }

    public function testTheTranscriberRouteAnswersItselfInsteadOfTheFirewall(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $config = static::getContainer()->get(ConfigRepository::class);
        $previous = $config->getValue(0, self::GROUP, self::SETTING);

        try {
            $config->deleteValue(0, self::GROUP, self::SETTING);
            self::assertSame(['not_configured', 503], $this->call($client, 'anything'), 'no secret configured: nothing is accepted');

            $config->setValue(0, self::GROUP, self::SETTING, 'the-test-secret');
            self::assertSame(['not_allowed', 403], $this->call($client, 'not-the-secret'));
            self::assertSame(['not_allowed', 403], $this->call($client, ''));
            self::assertSame(['unknown_session', 404], $this->call($client, 'the-test-secret'), 'the right secret reaches the session lookup');
        } finally {
            if (null === $previous) {
                $config->deleteValue(0, self::GROUP, self::SETTING);
            } else {
                $config->setValue(0, self::GROUP, self::SETTING, $previous);
            }
        }
    }

    /**
     * @return array{string, int} error code and HTTP status
     */
    private function call(KernelBrowser $client, string $secret): array
    {
        $client->request('GET', self::SESSION, [], [], ['HTTP_X_TRANSCRIBER_SECRET' => $secret]);
        $response = $client->getResponse();
        $body = json_decode((string) $response->getContent(), true);
        self::assertIsArray($body, (string) $response->getContent());

        return [(string) ($body['error'] ?? ''), $response->getStatusCode()];
    }
}
