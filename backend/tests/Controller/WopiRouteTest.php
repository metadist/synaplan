<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The WOPI routes exist and do not accept a bare request. The test environment
 * leaves the editor unconfigured, so a session without a login is still a 401
 * from the firewall (the route is not public).
 */
final class WopiRouteTest extends WebTestCase
{
    public function testCheckFileInfoWithoutATokenIsRejected(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/v1/wopi/files/1');

        self::assertSame(401, $client->getResponse()->getStatusCode());
    }

    public function testEditSessionRequiresALogin(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/v1/files/1/edit-session');

        self::assertSame(401, $client->getResponse()->getStatusCode());
    }
}
