<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\Http;

use App\Entity\Config;
use App\Module\Commerce\StripeBillingModule;
use App\Module\Contract\FeatureModuleInterface;
use App\Module\Gate\ModuleGateConfig;
use App\Module\Http\ModuleGateListener;
use App\Module\ModuleRegistry;
use App\Repository\ConfigRepository;
use App\Service\BillingService;
use App\Tests\Unit\Module\Fixture\BuildsAllModules;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * Gate matrix: module configured × gate flag × route ownership, plus the
 * routes that must never be gated even when their module is absent and gated.
 */
final class ModuleGateListenerTest extends TestCase
{
    use BuildsAllModules;

    private const GATED_ROUTE = 'subscription_checkout';

    public function testAbsentAndGatedModuleAnswersUniform404OnItsRoute(): void
    {
        $event = $this->dispatch(configured: false, gated: true, route: self::GATED_ROUTE);

        $response = $this->responseOf($event);
        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
        $this->assertSame([
            'error' => 'feature_not_configured',
            'module' => 'stripe_billing',
            'docs' => 'modules/stripe-billing',
        ], json_decode((string) $response->getContent(), true, 512, \JSON_THROW_ON_ERROR));
    }

    public function testConfiguredModuleIsNeverGated(): void
    {
        $event = $this->dispatch(configured: true, gated: true, route: self::GATED_ROUTE);

        $this->assertNull($this->responseOf($event));
    }

    public function testAbsentModuleWithGateOffIsUntouched(): void
    {
        $event = $this->dispatch(configured: false, gated: false, route: self::GATED_ROUTE);

        $this->assertNull($this->responseOf($event));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function neverGatedRoutes(): iterable
    {
        yield 'Stripe webhook keeps its own status codes' => ['stripe_webhook'];
        yield 'public plan listing' => ['subscription_plans'];
        yield 'subscription status is how the UI learns billing is off' => ['subscription_status'];
        yield 'IAP store notifications must keep 503 so stores retry' => ['iap_apple_notifications'];
        yield 'WhatsApp verify handshake' => ['api_webhooks_whatsapp_verify'];
        yield 'phone status exposes whatsapp_available' => ['api_phone_verify_status'];
        yield 'credential route is how a module becomes configured' => ['api_ai_higgsfield_credentials_put'];
        yield 'health' => ['api_health'];
        yield 'runtime config' => ['api_config_runtime_config'];
        yield 'API docs' => ['app.swagger_ui'];
        yield 'a route no module owns' => ['api_messages_send'];
    }

    #[DataProvider('neverGatedRoutes')]
    public function testRoutesOutsideAnyModuleAreUntouchedEvenWhenEveryGateIsOn(string $route): void
    {
        $event = $this->dispatch(configured: false, gated: true, route: $route, gateEveryModule: true);

        $this->assertNull($this->responseOf($event));
    }

    public function testSubRequestsAndRoutelessRequestsAreIgnored(): void
    {
        $listener = $this->listener(configured: false, gated: true);

        $sub = $this->event(self::GATED_ROUTE, HttpKernelInterface::SUB_REQUEST);
        $listener($sub);
        $this->assertNull($this->responseOf($sub));

        $request = Request::create('/whatever');
        $routeless = new ControllerEvent($this->createStub(HttpKernelInterface::class), static fn (): Response => new Response('controller'), $request, HttpKernelInterface::MAIN_REQUEST);
        $listener($routeless);
        $this->assertNull($this->responseOf($routeless));
    }

    private function dispatch(bool $configured, bool $gated, string $route, bool $gateEveryModule = false): ControllerEvent
    {
        $event = $this->event($route, HttpKernelInterface::MAIN_REQUEST);
        ($this->listener($configured, $gated, $gateEveryModule))($event);

        return $event;
    }

    private function listener(bool $configured, bool $gated, bool $gateEveryModule = false): ModuleGateListener
    {
        $modules = $this->allModules();
        if ($configured) {
            $modules[StripeBillingModule::ID] = new StripeBillingModule(
                new BillingService('sk_live_x', 'price_1Real'),
                'sk_live_x',
                'price_1Real',
                'whsec_real',
            );
        }

        $factories = [];
        foreach ($modules as $id => $module) {
            $factories[$id] = static fn (): FeatureModuleInterface => $module;
        }
        $registry = new ModuleRegistry(new ServiceLocator($factories));

        $rows = [];
        $gatedIds = $gateEveryModule ? array_keys($modules) : [StripeBillingModule::ID];
        foreach ($gatedIds as $id) {
            $rows[] = (new Config())
                ->setOwnerId(0)
                ->setGroup(ModuleGateConfig::GROUP)
                ->setSetting(ModuleGateConfig::settingFor($id))
                ->setValue($gated ? '1' : '0');
        }
        $repository = $this->createStub(ConfigRepository::class);
        $repository->method('getByGroup')->willReturn($rows);

        return new ModuleGateListener($registry, new ModuleGateConfig($repository));
    }

    private function event(string $route, int $requestType): ControllerEvent
    {
        $request = Request::create('/api/v1/anything');
        $request->attributes->set('_route', $route);

        return new ControllerEvent(
            $this->createStub(HttpKernelInterface::class),
            static fn (): Response => new Response('controller'),
            $request,
            $requestType,
        );
    }

    /** The short-circuit response, or null when the original controller is still in place. */
    private function responseOf(ControllerEvent $event): ?Response
    {
        $result = ($event->getController())();
        \assert($result instanceof Response);

        return 'controller' === $result->getContent() ? null : $result;
    }
}
