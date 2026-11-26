<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * No route under /api/test exists. Such routes called the AI providers for
 * anyone who asked, without sign-in and in every environment.
 */
final class NoPublicTestEndpointsTest extends WebTestCase
{
    public function testNoRouteIsRegisteredUnderApiTest(): void
    {
        static::createClient();
        $paths = [];
        foreach (static::getContainer()->get('router')->getRouteCollection() as $route) {
            if (str_starts_with($route->getPath(), '/api/test')) {
                $paths[] = $route->getPath();
            }
        }

        self::assertSame([], $paths);
    }

    public function testTheOldUrlsAnswerNothingWithoutSignIn(): void
    {
        $client = static::createClient();

        foreach ([['GET', '/api/test/embed'], ['GET', '/api/test/chat'], ['POST', '/api/test/chat']] as [$method, $path]) {
            $client->request($method, $path);
            self::assertContains(
                $client->getResponse()->getStatusCode(),
                [Response::HTTP_UNAUTHORIZED, Response::HTTP_NOT_FOUND],
                $method.' '.$path,
            );
        }
    }
}
