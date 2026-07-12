<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Service\Iap\Exception\IapConflictException;
use App\Service\Iap\Exception\IapNotConfiguredException;
use App\Service\Iap\Exception\IapVerificationException;
use App\Service\Iap\IapPlatform;
use App\Service\MobilePurchaseService;
use OpenApi\Attributes as OA;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * MOBILE-APP SEAM (Epic 5.4): native in-app-purchase endpoints.
 *
 * - `POST /verify` redeems a fresh purchase for the signed-in user (Bearer).
 * - `POST /apple/notifications` consumes App Store Server Notifications V2.
 * - `POST /google/notifications` consumes Play Real-time Developer Notifications.
 *
 * The server is the single source of truth: it always re-verifies the receipt
 * (Apple JWS chain / Google API query) rather than trusting any client or
 * notification payload, so a forged request can never grant a tier.
 */
#[Route('/api/v1/iap')]
#[OA\Tag(name: 'IAP')]
final class MobilePurchaseController extends AbstractController
{
    public function __construct(
        private readonly MobilePurchaseService $mobilePurchaseService,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route('/verify', name: 'iap_verify', methods: ['POST'])]
    #[OA\Post(
        path: '/api/v1/iap/verify',
        summary: 'Verify a native in-app purchase and grant entitlement',
        description: 'Server-side validation of an Apple StoreKit 2 transaction or a Google Play '
            .'purchase token. Grants the mapped tier only after verification, bound to the '
            .'authenticated user. Refuses cross-channel purchases (409) and receipts already '
            .'owned by another account (409). PENDING purchases are accepted but not unlocked.',
        security: [['Bearer' => []]],
        tags: ['IAP'],
    )]
    #[OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            required: ['platform', 'receipt'],
            properties: [
                new OA\Property(property: 'platform', type: 'string', enum: ['apple', 'google']),
                new OA\Property(
                    property: 'receipt',
                    type: 'string',
                    description: 'Apple: the signed StoreKit 2 transaction JWS. Google: the purchase token.',
                ),
                new OA\Property(
                    property: 'productId',
                    type: 'string',
                    nullable: true,
                    description: 'Store product id. Required for Google; ignored for Apple (read from the JWS).',
                ),
            ],
        ),
    )]
    #[OA\Response(
        response: 200,
        description: 'Purchase processed',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'granted', type: 'boolean'),
                new OA\Property(property: 'pending', type: 'boolean'),
                new OA\Property(property: 'tier', type: 'string'),
                new OA\Property(property: 'source', type: 'string', enum: ['apple', 'google']),
                new OA\Property(property: 'status', type: 'string'),
            ],
        ),
    )]
    #[OA\Response(response: 400, description: 'Invalid receipt or unknown product')]
    #[OA\Response(response: 401, description: 'Authentication required')]
    #[OA\Response(response: 409, description: 'Owned by another channel or already redeemed by another user')]
    #[OA\Response(response: 503, description: 'IAP not configured on this server')]
    public function verify(Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user instanceof User) {
            return $this->json(['error' => 'Authentication required'], Response::HTTP_UNAUTHORIZED);
        }

        $data = json_decode($request->getContent(), true);
        $platform = IapPlatform::tryFrom(is_array($data) ? (string) ($data['platform'] ?? '') : '');
        $receipt = is_array($data) ? trim((string) ($data['receipt'] ?? '')) : '';
        $productId = is_array($data) ? trim((string) ($data['productId'] ?? '')) : '';

        if (null === $platform || '' === $receipt) {
            return $this->json(['error' => 'platform and receipt are required'], Response::HTTP_BAD_REQUEST);
        }

        try {
            $result = $this->mobilePurchaseService->redeem($user, $platform, $receipt, $productId);

            return $this->json([
                'granted' => $result->granted,
                'pending' => $result->pending,
                'tier' => $result->tier,
                'source' => $result->source,
                'status' => $result->status,
            ]);
        } catch (IapNotConfiguredException $e) {
            $this->logger->warning('IAP verify rejected: store verifier not configured', [
                'user_id' => $user->getId(),
                'platform' => $platform->value,
                'error' => $e->getMessage(),
            ]);

            return $this->json([
                'error' => 'In-app purchases are not available on this server.',
                'code' => 'IAP_NOT_CONFIGURED',
            ], Response::HTTP_SERVICE_UNAVAILABLE);
        } catch (IapConflictException $e) {
            return $this->json([
                'error' => $e->getMessage(),
                'code' => 'IAP_OWNERSHIP_CONFLICT',
            ], Response::HTTP_CONFLICT);
        } catch (IapVerificationException $e) {
            $this->logger->warning('IAP verification failed', [
                'user_id' => $user->getId(),
                'platform' => $platform->value,
                'error' => $e->getMessage(),
            ]);

            return $this->json([
                'error' => 'Could not verify this purchase.',
                'code' => 'IAP_VERIFICATION_FAILED',
            ], Response::HTTP_BAD_REQUEST);
        }
    }

    #[Route('/apple/notifications', name: 'iap_apple_notifications', methods: ['POST'])]
    #[OA\Post(
        path: '/api/v1/iap/apple/notifications',
        summary: 'App Store Server Notifications V2 webhook (Apple)',
        description: 'Apple posts a signed JWS payload on renew/cancel/refund/grace. The signature '
            .'+ cert chain are verified before the entitlement is applied to the owning user.',
        tags: ['IAP'],
    )]
    #[OA\Response(response: 200, description: 'Notification processed (or safely ignored)')]
    #[OA\Response(response: 400, description: 'Invalid / untrusted payload')]
    public function appleNotifications(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $signedPayload = is_array($data) ? (string) ($data['signedPayload'] ?? '') : '';

        if ('' === $signedPayload) {
            return $this->json(['error' => 'signedPayload required'], Response::HTTP_BAD_REQUEST);
        }

        try {
            $entitlement = $this->mobilePurchaseService->verifyAppleNotification($signedPayload);
            $this->mobilePurchaseService->applyNotification($entitlement);

            return $this->json(['success' => true]);
        } catch (IapNotConfiguredException) {
            // Nothing to do here, but ACK so Apple doesn't keep retrying.
            return $this->json(['success' => true, 'status' => 'not_configured']);
        } catch (IapVerificationException $e) {
            $this->logger->warning('Apple ASSN V2 verification failed', ['error' => $e->getMessage()]);

            return $this->json(['error' => 'Invalid payload'], Response::HTTP_BAD_REQUEST);
        }
    }

    #[Route('/google/notifications', name: 'iap_google_notifications', methods: ['POST'])]
    #[OA\Post(
        path: '/api/v1/iap/google/notifications',
        summary: 'Real-time Developer Notifications webhook (Google Play)',
        description: 'Google Pub/Sub pushes a base64 message on renew/cancel/refund/hold. The '
            .'purchase state is re-queried from the Play Developer API (truth from Google, not '
            .'the message) before the entitlement is applied to the owning user.',
        tags: ['IAP'],
    )]
    #[OA\Response(response: 200, description: 'Notification processed (or safely ignored)')]
    #[OA\Response(response: 400, description: 'Malformed envelope')]
    public function googleNotifications(Request $request): JsonResponse
    {
        try {
            $entitlement = $this->mobilePurchaseService->decodeGoogleNotification($request->getContent());
            if (null !== $entitlement) {
                $this->mobilePurchaseService->applyNotification($entitlement);
            }

            // Always 200 so Pub/Sub considers the message delivered (avoids
            // redelivery storms); unactionable messages are a no-op.
            return $this->json(['success' => true]);
        } catch (IapNotConfiguredException) {
            return $this->json(['success' => true, 'status' => 'not_configured']);
        } catch (IapVerificationException $e) {
            $this->logger->warning('Google RTDN decode failed', ['error' => $e->getMessage()]);

            return $this->json(['error' => 'Malformed envelope'], Response::HTTP_BAD_REQUEST);
        }
    }
}
