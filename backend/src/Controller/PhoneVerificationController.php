<?php

namespace App\Controller;

use App\Entity\Message;
use App\Entity\User;
use App\Service\WhatsAppService;
use Doctrine\ORM\EntityManagerInterface;
use OpenApi\Attributes as OA;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

#[Route('/api/v1/user/verify-phone', name: 'api_phone_verify_')]
#[OA\Tag(name: 'Phone Verification')]
class PhoneVerificationController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $em,
        private WhatsAppService $whatsAppService,
        private LoggerInterface $logger,
    ) {
    }

    #[Route('/request', name: 'request', methods: ['POST'])]
    #[OA\Post(
        path: '/api/v1/user/verify-phone/request',
        summary: 'Request phone verification code',
        description: 'Send verification code via WhatsApp to the provided phone number',
        security: [['Bearer' => []]],
        tags: ['Phone Verification']
    )]
    #[OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            required: ['phone_number'],
            properties: [
                new OA\Property(property: 'phone_number', type: 'string', example: '+4915112345678'),
            ]
        )
    )]
    #[OA\Response(
        response: 200,
        description: 'Verification code sent successfully',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'message', type: 'string', example: 'Verification code sent'),
            ]
        )
    )]
    #[OA\Response(response: 401, description: 'Not authenticated')]
    #[OA\Response(response: 400, description: 'Invalid phone number')]
    #[OA\Response(response: 503, description: 'WhatsApp service unavailable')]
    public function requestVerification(
        Request $request,
        #[CurrentUser] ?User $user,
    ): JsonResponse {
        if (!$user) {
            return $this->json(['error' => 'Not authenticated'], Response::HTTP_UNAUTHORIZED);
        }

        if (!$this->whatsAppService->isAvailable()) {
            return $this->json([
                'success' => false,
                'error' => 'WhatsApp service is not available',
            ], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        $data = json_decode($request->getContent(), true);
        $phoneNumber = $data['phone_number'] ?? '';

        if (empty($phoneNumber)) {
            return $this->json([
                'success' => false,
                'error' => 'Phone number is required',
            ], Response::HTTP_BAD_REQUEST);
        }

        // Format phone number (remove spaces, dashes, etc.)
        $phoneNumber = preg_replace('/[^0-9+]/', '', $phoneNumber);

        // Generate unique verification code (5 characters: uppercase letters + numbers)
        $verificationCode = $this->generateUniqueVerificationCode();

        // Store in user details JSON
        $userDetails = $user->getUserDetails();
        $userDetails['phone_verification'] = [
            'phone_number' => $phoneNumber,
            'code' => $verificationCode,
            'expires_at' => time() + 300, // 5 minutes
            'attempts' => 0,
            'verified' => false,
            'created_at' => time(),
        ];
        $user->setUserDetails($userDetails);

        $this->em->flush();

        // Log code generation
        $this->logger->info('Verification code generated - user must send it to WhatsApp', [
            'user_id' => $user->getId(),
            'phone' => $phoneNumber,
            'code' => $verificationCode,
            'expires_in_seconds' => 300,
        ]);

        return $this->json([
            'success' => true,
            'message' => 'Please send this verification code to one of our WhatsApp numbers.',
            'phone_number' => $phoneNumber,
            'verification_code' => $verificationCode,
            'expires_at' => time() + 300,
        ]);
    }

    #[Route('/confirm', name: 'confirm', methods: ['POST'])]
    #[OA\Post(
        path: '/api/v1/user/verify-phone/confirm',
        summary: 'Confirm phone verification code',
        description: 'Verify the phone number using the code received via WhatsApp',
        security: [['Bearer' => []]],
        tags: ['Phone Verification']
    )]
    #[OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            required: ['code'],
            properties: [
                new OA\Property(property: 'code', type: 'string', example: '123456'),
            ]
        )
    )]
    #[OA\Response(
        response: 200,
        description: 'Phone verified successfully',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'message', type: 'string', example: 'Phone verified successfully'),
                new OA\Property(property: 'phone_number', type: 'string', example: '+4915112345678'),
            ]
        )
    )]
    #[OA\Response(response: 401, description: 'Not authenticated')]
    #[OA\Response(response: 400, description: 'Invalid or expired code')]
    public function confirmVerification(
        Request $request,
        #[CurrentUser] ?User $user,
    ): JsonResponse {
        if (!$user) {
            return $this->json(['error' => 'Not authenticated'], Response::HTTP_UNAUTHORIZED);
        }

        $data = json_decode($request->getContent(), true);
        $code = $data['code'] ?? '';

        if (empty($code)) {
            return $this->json([
                'success' => false,
                'error' => 'Verification code is required',
            ], Response::HTTP_BAD_REQUEST);
        }

        $userDetails = $user->getUserDetails();
        $verification = $userDetails['phone_verification'] ?? null;

        if (!$verification) {
            return $this->json([
                'success' => false,
                'error' => 'No verification pending. Please request a new code.',
            ], Response::HTTP_BAD_REQUEST);
        }

        // Check expiration
        if (time() > $verification['expires_at']) {
            return $this->json([
                'success' => false,
                'error' => 'Verification code expired. Please request a new one.',
                'expired' => true,
            ], Response::HTTP_BAD_REQUEST);
        }

        // Check attempts
        if ($verification['attempts'] >= 3) {
            return $this->json([
                'success' => false,
                'error' => 'Too many failed attempts. Please request a new code.',
                'max_attempts' => true,
            ], Response::HTTP_BAD_REQUEST);
        }

        // Verify code
        if ($code !== $verification['code']) {
            ++$verification['attempts'];
            $userDetails['phone_verification'] = $verification;
            $user->setUserDetails($userDetails);
            $this->em->flush();

            return $this->json([
                'success' => false,
                'error' => 'Invalid verification code',
                'attempts_remaining' => 3 - $verification['attempts'],
            ], Response::HTTP_BAD_REQUEST);
        }

        // Success - mark as verified
        $verification['verified'] = true;
        $userDetails['phone_number'] = $verification['phone_number'];
        $userDetails['phone_verified_at'] = time();
        unset($userDetails['phone_verification']); // Remove temp data

        $user->setUserDetails($userDetails);
        $this->em->flush();

        $this->logger->info('Phone verified successfully', [
            'user_id' => $user->getId(),
            'phone' => $verification['phone_number'],
        ]);

        return $this->json([
            'success' => true,
            'message' => 'Phone number verified successfully',
            'phone_number' => $verification['phone_number'],
        ]);
    }

    /**
     * Remove phone verification.
     *
     * DELETE /api/v1/user/verify-phone
     */
    #[Route('', name: 'remove', methods: ['DELETE'])]
    public function removeVerification(
        #[CurrentUser] ?User $user,
    ): JsonResponse {
        if (!$user) {
            return $this->json(['error' => 'Not authenticated'], Response::HTTP_UNAUTHORIZED);
        }

        $userDetails = $user->getUserDetails();

        if (empty($userDetails['phone_number'])) {
            return $this->json([
                'success' => false,
                'error' => 'No phone number linked to this account',
            ], Response::HTTP_BAD_REQUEST);
        }

        // Remove phone data
        unset($userDetails['phone_number']);
        unset($userDetails['phone_verified_at']);
        unset($userDetails['phone_verification']);

        $user->setUserDetails($userDetails);
        $this->em->flush();

        $this->logger->info('Phone verification removed', [
            'user_id' => $user->getId(),
        ]);

        return $this->json([
            'success' => true,
            'message' => 'Phone number removed successfully',
        ]);
    }

    /**
     * Get current phone verification status.
     *
     * GET /api/v1/user/verify-phone/status
     */
    #[Route('/status', name: 'status', methods: ['GET'])]
    public function getStatus(
        #[CurrentUser] ?User $user,
    ): JsonResponse {
        if (!$user) {
            return $this->json(['error' => 'Not authenticated'], Response::HTTP_UNAUTHORIZED);
        }

        $userDetails = $user->getUserDetails();
        $phoneNumber = $userDetails['phone_number'] ?? null;
        $verifiedAt = $userDetails['phone_verified_at'] ?? null;
        $pendingVerification = $userDetails['phone_verification'] ?? null;

        // Return code and expiration if pending
        $response = [
            'success' => true,
            'phone_number' => $phoneNumber,
            'verified' => !empty($phoneNumber) && !empty($verifiedAt),
            'verified_at' => $verifiedAt,
            'pending_verification' => !empty($pendingVerification),
            'whatsapp_available' => $this->whatsAppService->isAvailable(),
        ];

        if ($pendingVerification) {
            $response['verification_code'] = $pendingVerification['code'] ?? null;
            $response['expires_at'] = $pendingVerification['expires_at'] ?? null;
        }

        return $this->json($response);
    }

    /**
     * Generate unique verification code (5 chars: A-Z and 0-9).
     * Ensures no other pending verification has the same code.
     */
    private function generateUniqueVerificationCode(): string
    {
        $maxAttempts = 10;
        $attempts = 0;

        while ($attempts < $maxAttempts) {
            // Generate 5 character code: uppercase letters + numbers
            $characters = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
            $code = '';
            for ($i = 0; $i < 5; ++$i) {
                $code .= $characters[random_int(0, strlen($characters) - 1)];
            }

            // Check if this code already exists in any pending verification
            if (!$this->isCodeAlreadyInUse($code)) {
                return $code;
            }

            ++$attempts;
        }

        throw new \RuntimeException('Failed to generate unique verification code after '.$maxAttempts.' attempts');
    }

    /**
     * Check if a verification code is already in use by another pending verification.
     */
    private function isCodeAlreadyInUse(string $code): bool
    {
        $qb = $this->em->createQueryBuilder();
        $qb->select('COUNT(u.id)')
            ->from(User::class, 'u')
            ->where($qb->expr()->like('u.userDetails', ':codePattern'))
            ->setParameter('codePattern', '%"code":"'.$code.'"%');

        $count = (int) $qb->getQuery()->getSingleScalarResult();

        return $count > 0;
    }
}
