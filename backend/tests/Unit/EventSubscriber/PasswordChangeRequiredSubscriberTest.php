<?php

declare(strict_types=1);

namespace App\Tests\Unit\EventSubscriber;

use App\Entity\User;
use App\EventSubscriber\PasswordChangeRequiredSubscriber;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Yaml\Yaml;

#[AllowMockObjectsWithoutExpectations]
final class PasswordChangeRequiredSubscriberTest extends TestCase
{
    public function testBlocksApiRoutesWhileTheInitialPasswordIsStillInUse(): void
    {
        $event = $this->dispatch($this->user(mustChange: true), '/api/v1/messages', 'api_messages_send');

        $response = $event->getResponse();
        $this->assertNotNull($response);
        $this->assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
        $this->assertStringContainsString('PASSWORD_CHANGE_REQUIRED', (string) $response->getContent());
    }

    public function testLetsTheUserThroughToTheChangeItself(): void
    {
        $event = $this->dispatch(
            $this->user(mustChange: true),
            '/api/v1/profile/password',
            'api_profile_change_password',
        );

        $this->assertNull($event->getResponse());
    }

    public function testLetsTheFrontendLoadTheSessionAndSignOut(): void
    {
        foreach (['api_auth_me' => '/api/v1/auth/me', 'api_auth_logout' => '/api/v1/auth/logout'] as $route => $path) {
            $event = $this->dispatch($this->user(mustChange: true), $path, $route);
            $this->assertNull($event->getResponse(), $route.' must stay reachable');
        }
    }

    public function testIgnoresUsersWhoChoseTheirOwnPassword(): void
    {
        $event = $this->dispatch($this->user(mustChange: false), '/api/v1/messages', 'api_messages_send');

        $this->assertNull($event->getResponse());
    }

    public function testIgnoresAnonymousRequests(): void
    {
        $event = $this->dispatch(null, '/api/v1/messages', 'api_messages_send');

        $this->assertNull($event->getResponse());
    }

    /**
     * An API key opens the MCP endpoint and the OpenAI-compatible gateway
     * without ever touching /api, so a lock that only covered the JSON API would
     * be one the account can walk around.
     */
    public function testBlocksTheApiKeyEndpointsAsWell(): void
    {
        foreach (['/mcp' => 'mcp_endpoint', '/v1/chat/completions' => 'openai_chat_completions'] as $path => $route) {
            $event = $this->dispatch($this->user(mustChange: true), $path, $route);

            $this->assertNotNull($event->getResponse(), $path.' must be locked as well');
        }
    }

    /**
     * CONTRACT with security.yaml: the subscriber guards a list of path
     * prefixes, and a firewall added there without its prefix would be a hole
     * nothing else notices. So the firewalls are read back out of the
     * configuration and each one is asserted to be locked.
     */
    public function testGuardsEveryFirewallThatCanAuthenticateAUser(): void
    {
        $firewalls = $this->authenticatingFirewalls();

        foreach ($firewalls as $name => $path) {
            $event = $this->dispatch($this->user(mustChange: true), $path, 'any_route');

            $this->assertNotNull($event->getResponse(), sprintf(
                'The "%s" firewall authenticates users, so %s must be locked while the initial password '
                .'is still in use. Add its prefix to PasswordChangeRequiredSubscriber::GUARDED_PATH_PREFIXES.',
                $name,
                $path,
            ));
        }
    }

    public function testIgnoresRequestsOutsideTheAuthenticatedFirewalls(): void
    {
        $event = $this->dispatch($this->user(mustChange: true), '/shared/some-token', 'shared_chat_page');

        $this->assertNull($event->getResponse());
    }

    /**
     * The firewalls that can authenticate somebody, as `name => sample path`.
     *
     * @return non-empty-array<string, string>
     */
    private function authenticatingFirewalls(): array
    {
        $configuration = Yaml::parseFile(__DIR__.'/../../../config/packages/security.yaml');
        $firewalls = $configuration['security']['firewalls'] ?? null;
        $this->assertIsArray($firewalls, 'security.yaml declares no firewalls; this test can no longer verify anything');

        // Everything Symfony accepts as a way to prove who you are. A firewall
        // with none of these cannot produce an authenticated user, so it needs
        // no lock.
        $authenticators = [
            'custom_authenticators', 'form_login', 'json_login', 'http_basic',
            'login_link', 'access_token', 'remote_user', 'x509',
        ];

        $paths = [];
        foreach ($firewalls as $name => $firewall) {
            if (!\is_array($firewall) || false === ($firewall['security'] ?? true)) {
                continue;
            }
            if ([] === array_intersect($authenticators, array_keys($firewall))) {
                continue;
            }

            $pattern = $firewall['pattern'] ?? null;
            $this->assertIsString($pattern, sprintf(
                'The "%s" firewall authenticates users but matches every path, so a prefix list cannot '
                .'cover it; PasswordChangeRequiredSubscriber has to guard all requests instead.',
                $name,
            ));
            $this->assertMatchesRegularExpression('#^\^/[\w./-]+$#', $pattern, sprintf(
                'This test derives a sample path by stripping the leading ^ off a firewall pattern, which '
                .'the "%s" firewall no longer allows. Extend it rather than dropping the case.',
                $name,
            ));

            $paths[(string) $name] = substr($pattern, 1);
        }

        $this->assertNotEmpty($paths, 'No authenticating firewall was found, so this test proves nothing');

        return $paths;
    }

    private function dispatch(?UserInterface $user, string $path, string $route): RequestEvent
    {
        $security = $this->createMock(Security::class);
        $security->method('getUser')->willReturn($user);

        $request = Request::create($path);
        $request->attributes->set('_route', $route);

        $event = new RequestEvent(
            $this->createMock(HttpKernelInterface::class),
            $request,
            HttpKernelInterface::MAIN_REQUEST,
        );

        (new PasswordChangeRequiredSubscriber($security))($event);

        return $event;
    }

    private function user(bool $mustChange): User
    {
        return (new User())
            ->setMail('admin@example.com')
            ->setMustChangePassword($mustChange);
    }
}
