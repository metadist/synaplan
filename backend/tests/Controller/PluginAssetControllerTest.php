<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Plugin files served without a user in the address, and the transcriber
 * routes that authenticate with a shared secret instead of an account.
 */
final class PluginAssetControllerTest extends WebTestCase
{
    public function testABundledPluginFileIsServedWithoutSignIn(): void
    {
        $client = static::createClient();

        $client->request('GET', '/api/v1/plugins/synascriber/assets/jitsi/loader.js');

        self::assertResponseIsSuccessful();
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

    public function testTheTranscriberRouteIsReachedWithoutAnAccountAndRefusesAWrongSecret(): void
    {
        $client = static::createClient();

        $client->request('GET', '/api/v1/plugins/synascriber/public/transcriber/sessions/Ab3dEf9hJkLmN0pQrStUvW', [], [], [
            'HTTP_X_TRANSCRIBER_SECRET' => 'not-the-secret',
        ]);

        $status = $client->getResponse()->getStatusCode();
        self::assertContains($status, [403, 503], 'The controller answers (wrong or unset secret), not the firewall with 401.');
    }
}
