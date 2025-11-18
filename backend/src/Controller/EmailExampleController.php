<?php

namespace App\Controller;

use App\Entity\User;
use App\Service\EmailService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Example: How to use the multilingual EmailService
 * 
 * This is a demonstration file showing different use cases
 */
class EmailExampleController extends AbstractController
{
    public function __construct(
        private EmailService $emailService
    ) {}

    /**
     * Example 1: Send verification email based on user's language preference
     */
    #[Route('/api/v1/auth/send-verification', methods: ['POST'])]
    public function sendVerification(
        #[CurrentUser] ?User $user
    ): JsonResponse {
        if (!$user) {
            return $this->json(['error' => 'Not authenticated'], 401);
        }

        // Get user's preferred language (fallback to 'en')
        $locale = $user->getLocale() ?? 'en';

        // Generate verification URL
        $verificationToken = bin2hex(random_bytes(32));
        $verificationUrl = $this->generateUrl(
            'api_auth_verify_email',
            ['token' => $verificationToken],
            true // absolute URL
        );

        // Send email in user's language
        $this->emailService->sendVerificationEmail(
            to: $user->getEmail(),
            verificationUrl: $verificationUrl,
            locale: $locale
        );

        return $this->json([
            'success' => true,
            'message' => 'Verification email sent',
            'locale' => $locale
        ]);
    }

    /**
     * Example 2: Send password reset in requested language
     */
    #[Route('/api/v1/auth/forgot-password', methods: ['POST'])]
    public function forgotPassword(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $email = $data['email'] ?? '';
        
        // Get requested locale from request (or use default)
        $locale = $data['locale'] ?? $request->getLocale() ?? 'en';

        // Find user and generate reset token
        // ... (user lookup logic here)
        
        $resetToken = bin2hex(random_bytes(32));
        $resetUrl = $this->generateUrl(
            'api_auth_reset_password',
            ['token' => $resetToken],
            true
        );

        // Send email in requested language
        $this->emailService->sendPasswordResetEmail(
            to: $email,
            resetUrl: $resetUrl,
            locale: $locale
        );

        return $this->json([
            'success' => true,
            'message' => 'Password reset email sent'
        ]);
    }

    /**
     * Example 3: Send welcome email after registration
     */
    #[Route('/api/v1/auth/register', methods: ['POST'])]
    public function register(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        
        // Get user's preferred language from registration data
        $locale = $data['locale'] ?? 'en';
        $name = $data['name'] ?? 'User';
        $email = $data['email'];

        // ... (create user logic here)

        // Send welcome email
        $this->emailService->sendWelcomeEmail(
            to: $email,
            name: $name,
            locale: $locale,
            appUrl: $_ENV['FRONTEND_URL'] ?? 'http://localhost:3000'
        );

        return $this->json([
            'success' => true,
            'message' => 'User registered successfully'
        ]);
    }

    /**
     * Example 4: Send custom email with dynamic content
     */
    #[Route('/api/v1/notifications/custom', methods: ['POST'])]
    public function sendCustomNotification(
        Request $request,
        #[CurrentUser] ?User $user
    ): JsonResponse {
        if (!$user) {
            return $this->json(['error' => 'Not authenticated'], 401);
        }

        $locale = $user->getLocale() ?? 'en';

        // Send custom templated email
        $this->emailService->sendTemplatedEmail(
            to: $user->getEmail(),
            subject: 'Your Custom Notification',
            template: 'emails/notification.html.twig',
            context: [
                'username' => $user->getName(),
                'notification_type' => 'important',
                'custom_data' => ['key' => 'value']
            ],
            locale: $locale
        );

        return $this->json(['success' => true]);
    }

    /**
     * Example 5: Language detection from multiple sources
     */
    private function detectUserLanguage(Request $request, ?User $user): string
    {
        // Priority:
        // 1. User's saved preference
        if ($user && $user->getLocale()) {
            return $user->getLocale();
        }

        // 2. Request parameter
        if ($locale = $request->query->get('locale')) {
            return $locale;
        }

        // 3. Request body
        $data = json_decode($request->getContent(), true);
        if (isset($data['locale'])) {
            return $data['locale'];
        }

        // 4. Browser Accept-Language header
        if ($request->headers->has('Accept-Language')) {
            $acceptLanguage = $request->headers->get('Accept-Language');
            // Extract first language code (e.g., "de-DE,de;q=0.9,en;q=0.8" -> "de")
            $locale = strtok($acceptLanguage, ',');
            $locale = strtok($locale, '-');
            
            // Validate against supported languages
            if (in_array($locale, ['en', 'de', 'fr'])) {
                return $locale;
            }
        }

        // 5. Default fallback
        return 'en';
    }
}

