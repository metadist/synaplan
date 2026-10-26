<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\Auth\TokenService;
use App\Service\Billing\PayFirst\PayFirstCheckout;
use App\Service\Billing\PayFirst\PayFirstClaim;
use App\Service\Billing\PayFirst\PayFirstClaimResult;
use App\Service\Billing\PayFirst\PayFirstConfig;
use App\Service\Billing\PayFirst\PayFirstException;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/v1/checkout', name: 'checkout_')]
#[OA\Tag(name: 'Checkout')]
final class CheckoutClaimController extends AbstractController
{
    public function __construct(
        private readonly PayFirstConfig $config,
        private readonly PayFirstClaim $claim,
        private readonly TokenService $tokenService,
        #[Autowire(service: 'limiter.checkout_claim_ip')]
        private readonly RateLimiterFactoryInterface $claimLimiter,
    ) {
    }

    #[Route('/claim', name: 'claim', methods: ['POST'])]
    #[OA\Post(
        path: '/api/v1/checkout/claim',
        operationId: 'claimPayFirstCheckout',
        summary: 'After a pay-first payment: create the account if the webhook has not yet, and sign in the browser that started the checkout',
        tags: ['Checkout'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['sessionId'],
            properties: [new OA\Property(property: 'sessionId', type: 'string', example: 'cs_test_a1b2c3')]
        )),
        responses: [
            new OA\Response(response: 200, description: 'Where the purchase stands; on a sign-in the response also sets the auth cookies', content: new OA\JsonContent(
                required: ['success', 'status', 'plan', 'email', 'signedIn'],
                properties: [
                    new OA\Property(property: 'success', type: 'boolean', example: true),
                    new OA\Property(property: 'status', type: 'string', enum: ['pending', 'ready', 'existing', 'conflict'], example: 'ready', description: 'pending: Stripe has not confirmed the payment yet, ask again shortly. ready: the account exists. existing: the address already had an account and the plan was added to it. conflict: that account already has another live subscription; nothing changed and the payment will be refunded.'),
                    new OA\Property(property: 'plan', type: 'string', example: 'PRO'),
                    new OA\Property(property: 'email', type: 'string', example: 'ada@example.com'),
                    new OA\Property(property: 'signedIn', type: 'boolean', example: true, description: 'True only for the browser that started the checkout, once, for an account made from this payment'),
                ]
            )),
            new OA\Response(response: 404, description: 'No pay-first session with this id, or billing is not configured (PAY_FIRST_SESSION_NOT_FOUND)'),
            new OA\Response(response: 429, description: 'Too many requests from this connection (PAY_FIRST_RATE_LIMITED)'),
            new OA\Response(response: 503, description: 'The payment went through but the account could not be finished yet: Stripe could not be reached (PAY_FIRST_LOOKUP_FAILED), or the purchased price is not one this install sells (PAY_FIRST_NOT_FULFILLABLE)'),
        ]
    )]
    public function claim(Request $request): JsonResponse
    {
        if (!$this->config->canSettle()) {
            return $this->notFound();
        }
        if (!$this->claimLimiter->create($request->getClientIp() ?? 'unknown')->consume()->isAccepted()) {
            return $this->json(['success' => false, 'error' => 'Too many attempts. Please wait a few minutes and try again.', 'code' => 'PAY_FIRST_RATE_LIMITED'], Response::HTTP_TOO_MANY_REQUESTS);
        }

        $body = json_decode($request->getContent(), true);
        $sessionId = is_array($body) && is_string($body['sessionId'] ?? null) ? trim($body['sessionId']) : '';
        try {
            $result = $this->claim->claim($sessionId, $request->cookies->get(PayFirstCheckout::NONCE_COOKIE));
        } catch (PayFirstException $e) {
            return $this->json(['success' => false, 'error' => $e->getMessage(), 'code' => $e->getErrorCode()], $e->getHttpStatus());
        }
        if (PayFirstClaimResult::NOT_FOUND === $result->status) {
            return $this->notFound();
        }

        $response = $this->json([
            'success' => true,
            'status' => $result->status,
            'plan' => $result->plan,
            'email' => $result->email,
            'signedIn' => null !== $result->signIn,
        ]);
        if (null !== $result->signIn) {
            $this->tokenService->addAuthCookies($response, $this->tokenService->generateAccessToken($result->signIn), $this->tokenService->generateRefreshToken($result->signIn, $request->getClientIp()));
            $response->headers->clearCookie(PayFirstCheckout::NONCE_COOKIE, '/');
        }

        return $response;
    }

    private function notFound(): JsonResponse
    {
        return $this->json(['success' => false, 'error' => 'We could not find this payment.', 'code' => 'PAY_FIRST_SESSION_NOT_FOUND'], Response::HTTP_NOT_FOUND);
    }
}
