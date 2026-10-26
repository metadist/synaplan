<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\Auth\AuthCookieFactory;
use App\Service\Auth\SignupBlockedException;
use App\Service\Auth\SignupPauseSwitch;
use App\Service\Auth\SignupProtection;
use App\Service\Billing\PayFirst\PayFirstCheckout;
use App\Service\Billing\PayFirst\PayFirstConfig;
use App\Service\Billing\PayFirst\PayFirstException;
use App\Service\Client\ClientContextResolver;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Pay first (PF1): an email address and a plan open Stripe Checkout; the
 * account is created only after the payment (PF2). 404 unless pay-first is
 * active on this installation.
 */
#[Route('/api/v1/checkout', name: 'checkout_')]
#[OA\Tag(name: 'Checkout')]
final class CheckoutStartController extends AbstractController
{
    public function __construct(
        private readonly PayFirstConfig $config,
        private readonly PayFirstCheckout $checkout,
        private readonly SignupProtection $signupProtection,
        private readonly ClientContextResolver $clientContextResolver,
        private readonly AuthCookieFactory $cookies,
        private readonly SignupPauseSwitch $pause,
    ) {
    }

    #[Route('/start', name: 'start', methods: ['POST'])]
    #[OA\Post(
        path: '/api/v1/checkout/start',
        operationId: 'startPayFirstCheckout',
        summary: 'Open Stripe Checkout for a plan with only an email address; no account is created before the payment',
        tags: ['Checkout'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['email', 'plan'],
            properties: [
                new OA\Property(property: 'email', type: 'string', format: 'email', maxLength: 128, example: 'ada@example.com'),
                new OA\Property(property: 'plan', type: 'string', enum: ['PRO', 'TEAM', 'BUSINESS'], example: 'PRO'),
                new OA\Property(property: 'challengeToken', type: 'string', description: 'Turnstile / reCAPTCHA token when a human check is configured'),
            ]
        )),
        responses: [
            new OA\Response(response: 200, description: 'Checkout opened; the browser also receives an HttpOnly nonce cookie', content: new OA\JsonContent(
                required: ['success', 'checkoutUrl'],
                properties: [
                    new OA\Property(property: 'success', type: 'boolean', example: true),
                    new OA\Property(property: 'checkoutUrl', type: 'string', example: 'https://checkout.stripe.com/c/pay/cs_test_…'),
                ]
            )),
            new OA\Response(response: 400, description: 'Invalid email or plan (PAY_FIRST_EMAIL_INVALID, PAY_FIRST_PLAN_INVALID), or the human check failed (SIGNUP_CHALLENGE_FAILED)'),
            new OA\Response(response: 403, description: 'Native app: purchases go through the app store (NATIVE_CHANNEL_REQUIRES_IAP)'),
            new OA\Response(response: 404, description: 'Pay-first is not active on this installation'),
            new OA\Response(response: 429, description: 'Too many starts from this connection or for this address (PAY_FIRST_RATE_LIMITED)'),
            new OA\Response(response: 502, description: 'Stripe could not open the payment page (PAY_FIRST_UNAVAILABLE)'),
            new OA\Response(response: 503, description: 'Sign-up is paused by the operator (SIGNUP_PAUSED)'),
        ]
    )]
    public function start(Request $request): JsonResponse
    {
        if (!$this->config->isActive()) {
            return $this->json(['success' => false, 'error' => 'Not found.', 'code' => 'PAY_FIRST_OFF'], Response::HTTP_NOT_FOUND);
        }
        if ($this->pause->isPaused()) {
            $paused = SignupBlockedException::paused();

            return $this->json(['success' => false, 'error' => $paused->getMessage(), 'code' => $paused->getReasonCode()], $paused->getHttpStatus());
        }
        if ($this->clientContextResolver->fromRequest($request)->isMobileApp) {
            return $this->json([
                'success' => false,
                'error' => 'In-app purchases are handled through the app store on this device.',
                'code' => 'NATIVE_CHANNEL_REQUIRES_IAP',
            ], Response::HTTP_FORBIDDEN);
        }

        $body = json_decode($request->getContent(), true);
        $body = is_array($body) ? $body : [];
        if (!$this->signupProtection->verifyHuman(SignupProtection::tokenFromPayload($body), 'checkout_start', $request->getClientIp())) {
            return $this->json(['success' => false, 'error' => 'Verification failed. Please try again.', 'code' => 'SIGNUP_CHALLENGE_FAILED'], Response::HTTP_BAD_REQUEST);
        }

        try {
            $started = $this->checkout->start(
                is_string($body['email'] ?? null) ? $body['email'] : '',
                is_string($body['plan'] ?? null) ? $body['plan'] : '',
                $request->getClientIp(),
            );
        } catch (PayFirstException $e) {
            $headers = $e->getRetryAfterSeconds() > 0 ? ['Retry-After' => (string) $e->getRetryAfterSeconds()] : [];

            return $this->json(['success' => false, 'error' => $e->getMessage(), 'code' => $e->getErrorCode()], $e->getHttpStatus(), $headers);
        }

        $response = $this->json(['success' => true, 'checkoutUrl' => $started->checkoutUrl]);
        $response->headers->setCookie($this->cookies->create(PayFirstCheckout::NONCE_COOKIE, $started->nonce, time() + PayFirstCheckout::NONCE_TTL_SECONDS));

        return $response;
    }
}
