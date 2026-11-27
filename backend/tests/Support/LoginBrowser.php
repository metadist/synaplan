<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Service\OAuth\BrowserBoundState;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;

/**
 * Lets a test request behave like one browser across a sign-in: the cookie a
 * started sign-in asks for is the cookie the next request sends.
 */
final class LoginBrowser
{
    public const COOKIE_ATTRIBUTE = BrowserBoundState::COOKIE_ATTRIBUTE;

    public static function keepCookie(Request $request): Cookie
    {
        $cookie = $request->attributes->get(self::COOKIE_ATTRIBUTE);
        if (!$cookie instanceof Cookie) {
            throw new \LogicException('No sign-in was started on this request.');
        }
        $request->cookies->set($cookie->getName(), (string) $cookie->getValue());

        return $cookie;
    }
}
