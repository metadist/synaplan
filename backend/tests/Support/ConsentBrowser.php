<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Service\Auth\AuthCookieFactory;
use App\Service\OAuth\BrowserBoundState;
use App\Service\OAuth\OAuthStateService;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\ParameterBag;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * One browser for a consent test: it keeps the cookie a started flow sets, so
 * the callback arrives the way it does from the browser that started it.
 */
final class ConsentBrowser
{
    public readonly BrowserBoundState $state;
    private readonly OAuthStateService $signer;
    private readonly Request $request;
    private readonly TokenStorage $tokens;

    public function __construct(string $appSecret = 'app-secret')
    {
        $this->signer = new OAuthStateService(new NullLogger(), $appSecret);
        $this->request = new Request();
        $this->request->attributes = new class($this->request) extends ParameterBag {
            public function __construct(private readonly Request $request)
            {
                parent::__construct();
            }

            public function set(string $key, mixed $value): void
            {
                parent::set($key, $value);
                if ($value instanceof Cookie) {
                    $this->request->cookies->set($value->getName(), (string) $value->getValue());
                }
            }
        };

        $stack = new RequestStack();
        $stack->push($this->request);
        $this->tokens = new TokenStorage();
        $this->state = new BrowserBoundState($this->signer, $stack, new AuthCookieFactory('test', 'http://localhost'), new NullLogger(), $this->tokens);
    }

    /**
     * A state this browser asked for, with the payload a test chooses.
     *
     * @param array<string, mixed> $extraData
     */
    public function generateState(string $provider, array $extraData = []): string
    {
        return $this->state->generateState($provider, $extraData);
    }

    /**
     * A correctly signed state this browser never asked for.
     *
     * @param array<string, mixed> $extraData
     */
    public function foreignState(string $provider, array $extraData = []): string
    {
        return $this->signer->generateState($provider, $extraData);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function readState(string $state, string $provider): ?array
    {
        return $this->signer->validateState($state, $provider);
    }

    /** The callback now arrives from somewhere else: no cookie. */
    public function switchBrowser(): void
    {
        $this->request->cookies->replace([]);
    }

    public function attribute(string $name): mixed
    {
        return $this->request->attributes->get($name);
    }

    /** The next flow is started by the native app: no cookie, bound to the account. */
    public function startInApp(): void
    {
        $this->request->query->set('native', '1');
    }

    /** The request now carries this account's token, as the app's does. */
    public function signIn(?UserInterface $user): void
    {
        $this->tokens->setToken(null === $user ? null : new UsernamePasswordToken($user, 'api', $user->getRoles()));
    }
}
