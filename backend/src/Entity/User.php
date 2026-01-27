<?php

namespace App\Entity;

use App\Repository\UserRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: 'BUSER')]
#[ORM\Index(columns: ['BMAIL'], name: 'BMAIL')]
#[ORM\Index(columns: ['BINTYPE'], name: 'BINTYPE')]
#[ORM\Index(columns: ['BPROVIDERID'], name: 'BPROVIDERID')]
#[ORM\Index(columns: ['BUSERLEVEL'], name: 'BUSERLEVEL')]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(name: 'BID', type: 'bigint')]
    private ?int $id = null;

    #[ORM\Column(name: 'BCREATED', length: 20)]
    private string $created = '';

    #[ORM\Column(name: 'BINTYPE', length: 16, options: ['default' => 'WEB'])]
    private string $type = 'WEB';

    #[ORM\Column(name: 'BMAIL', length: 128)]
    private string $mail = '';

    #[ORM\Column(name: 'BPW', length: 64, nullable: true)]
    private ?string $pw = null;

    #[ORM\Column(name: 'BPROVIDERID', length: 32)]
    private string $providerId = '';

    #[ORM\Column(name: 'BUSERLEVEL', length: 32, options: ['default' => 'NEW'])]
    private string $userLevel = 'NEW';

    #[ORM\Column(name: 'BEMAILVERIFIED', type: 'boolean', options: ['default' => false])]
    private bool $emailVerified = false;

    #[ORM\Column(name: 'BUSERDETAILS', type: 'json')]
    private array $userDetails = [];

    #[ORM\Column(name: 'BPAYMENTDETAILS', type: 'json')]
    private array $paymentDetails = [];

    // Subscription wird via BUSERLEVEL + BPAYMENTDETAILS JSON gesteuert
    // BUSERLEVEL: NEW, PRO, TEAM, BUSINESS, ADMIN
    // BPAYMENTDETAILS: {subscription_id, status, starts, ends, period, stripe_customer_id, stripe_subscription_id}

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCreated(): string
    {
        return $this->created;
    }

    public function setCreated(string $created): self
    {
        $this->created = $created;

        return $this;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function setType(string $type): self
    {
        $this->type = $type;

        return $this;
    }

    public function getMail(): string
    {
        return $this->mail;
    }

    public function setMail(string $mail): self
    {
        $this->mail = $mail;

        return $this;
    }

    public function getPw(): ?string
    {
        return $this->pw;
    }

    public function setPw(?string $pw): self
    {
        $this->pw = $pw;

        return $this;
    }

    public function getProviderId(): string
    {
        return $this->providerId;
    }

    public function setProviderId(string $providerId): self
    {
        $this->providerId = $providerId;

        return $this;
    }

    public function getUserLevel(): string
    {
        return $this->userLevel;
    }

    public function setUserLevel(string $userLevel): self
    {
        $this->userLevel = $userLevel;

        return $this;
    }

    public function isEmailVerified(): bool
    {
        return $this->emailVerified;
    }

    public function setEmailVerified(bool $emailVerified): self
    {
        $this->emailVerified = $emailVerified;

        return $this;
    }

    public function getUserDetails(): array
    {
        return $this->userDetails;
    }

    public function setUserDetails(array $userDetails): self
    {
        $this->userDetails = $userDetails;

        return $this;
    }

    /**
     * Per-user toggle: if false, Synaplan will not use or auto-update memories for this user.
     * Stored in BUSERDETAILS JSON to avoid schema changes.
     */
    public function isMemoriesEnabled(): bool
    {
        $value = $this->userDetails['memories_enabled'] ?? true;

        return false !== $value;
    }

    public function setMemoriesEnabled(bool $enabled): self
    {
        $details = $this->getUserDetails();
        $details['memories_enabled'] = $enabled;
        $this->setUserDetails($details);

        return $this;
    }

    public function getPaymentDetails(): array
    {
        return $this->paymentDetails;
    }

    public function setPaymentDetails(array $paymentDetails): self
    {
        $this->paymentDetails = $paymentDetails;

        return $this;
    }

    // UserInterface implementation
    public function getUserIdentifier(): string
    {
        return $this->mail;
    }

    public function getRoles(): array
    {
        // Base role
        $roles = ['ROLE_USER'];

        // Map internal user level to roles
        if ('ADMIN' === $this->userLevel) {
            $roles[] = 'ROLE_ADMIN';
            $roles[] = 'ROLE_PRO';
            $roles[] = 'ROLE_BUSINESS';
        } elseif (in_array($this->userLevel, ['PRO', 'TEAM', 'BUSINESS'])) {
            $roles[] = 'ROLE_PRO';

            if ('BUSINESS' === $this->userLevel) {
                $roles[] = 'ROLE_BUSINESS';
            }
        }

        // Map OIDC roles to Symfony roles (for Keycloak users)
        $userDetails = $this->getUserDetails();
        if (isset($userDetails['oidc_roles']) && is_array($userDetails['oidc_roles'])) {
            $roles = array_merge($roles, $this->mapOidcRolesToSymfonyRoles($userDetails['oidc_roles']));
        }

        return array_unique($roles);
    }

    /**
     * Cached OIDC role mapping (parsed once per request lifecycle).
     *
     * @var array<string, string>|null
     */
    private static ?array $cachedRoleMapping = null;

    /**
     * Map OIDC provider roles to Symfony roles.
     *
     * Configurable via OIDC_ROLE_MAPPING environment variable.
     * Default mapping handles common Keycloak roles.
     * Mapping is cached as static property to avoid re-parsing on multiple getRoles() calls.
     *
     * @param array<string> $oidcRoles Roles from realm_access or resource_access
     *
     * @return array<string> Symfony roles (ROLE_*)
     */
    private function mapOidcRolesToSymfonyRoles(array $oidcRoles): array
    {
        // Cache the role mapping to avoid re-parsing env var on every call
        if (null === self::$cachedRoleMapping) {
            // Default mapping (can be overridden via env)
            self::$cachedRoleMapping = [
                'admin' => 'ROLE_ADMIN',
                'realm-admin' => 'ROLE_ADMIN',
                'synaplan-admin' => 'ROLE_ADMIN',
                'administrator' => 'ROLE_ADMIN',
                'pro-user' => 'ROLE_PRO',
                'pro' => 'ROLE_PRO',
                'business-user' => 'ROLE_BUSINESS',
                'business' => 'ROLE_BUSINESS',
            ];

            // Load custom mapping from env if provided
            // Format: OIDC_ROLE_MAPPING="keycloak_role:SYMFONY_ROLE,another:ROLE_OTHER"
            $envMapping = $_ENV['OIDC_ROLE_MAPPING'] ?? '';
            if (!empty($envMapping)) {
                $pairs = explode(',', $envMapping);
                foreach ($pairs as $pair) {
                    $parts = explode(':', trim($pair));
                    if (2 === count($parts)) {
                        self::$cachedRoleMapping[strtolower(trim($parts[0]))] = trim($parts[1]);
                    }
                }
            }
        }

        $mappedRoles = [];
        foreach ($oidcRoles as $oidcRole) {
            $roleLower = strtolower($oidcRole);
            if (isset(self::$cachedRoleMapping[$roleLower])) {
                $mappedRoles[] = self::$cachedRoleMapping[$roleLower];
            }
        }

        return $mappedRoles;
    }

    #[\Deprecated(
        message: 'eraseCredentials() is empty and will be removed in the future. Sensitive data is not stored in User entity.',
        since: 'symfony/security-http 7.3'
    )]
    public function eraseCredentials(): void
    {
        // Nothing to do - we don't store sensitive temp data
    }

    // PasswordAuthenticatedUserInterface
    public function getPassword(): string
    {
        // Return empty string for OAuth users (who have no password)
        // This is required by PasswordAuthenticatedUserInterface
        return $this->pw ?? '';
    }

    // Subscription helper methods (using BPAYMENTDETAILS JSON)
    public function getSubscriptionData(): array
    {
        return $this->paymentDetails['subscription'] ?? [];
    }

    public function setSubscriptionData(array $data): self
    {
        $this->paymentDetails['subscription'] = $data;

        return $this;
    }

    public function hasActiveSubscription(): bool
    {
        $sub = $this->getSubscriptionData();

        return isset($sub['status'])
            && 'active' === $sub['status']
            && isset($sub['ends'])
            && $sub['ends'] > time();
    }

    public function getSubscriptionEnds(): ?int
    {
        return $this->getSubscriptionData()['ends'] ?? null;
    }

    public function getStripeCustomerId(): ?string
    {
        // stripe_customer_id is stored in paymentDetails JSON
        return $this->paymentDetails['stripe_customer_id'] ?? null;
    }

    public function setStripeCustomerId(?string $stripeCustomerId): self
    {
        $this->paymentDetails['stripe_customer_id'] = $stripeCustomerId;

        return $this;
    }

    /**
     * Check if user is admin.
     */
    public function isAdmin(): bool
    {
        return 'ADMIN' === $this->userLevel;
    }

    /**
     * Get effective rate limiting level.
     *
     * Logic:
     * - ADMIN: Unlimited usage, no rate limits
     * - ANONYMOUS: Only for non-logged-in users (widget, unlinked WhatsApp/Email)
     * - NEW: Default for all logged-in users without active subscription
     * - PRO/TEAM/BUSINESS: Users with active paid subscription
     *
     * Note: Phone verification is NOT required for logged-in web users.
     *       Phone verification only affects WhatsApp/Email channel linking.
     */
    public function getRateLimitLevel(): string
    {
        // Admins have no rate limits
        if ('ADMIN' === $this->userLevel) {
            return 'ADMIN';
        }

        // If user is logged in via web (has email), they are at least NEW
        // ANONYMOUS is only for widget/API users without authentication

        // If userLevel is set to PRO, TEAM, or BUSINESS directly (e.g., via fixtures or admin panel)
        // use that level even without active subscription
        if (in_array($this->userLevel, ['PRO', 'TEAM', 'BUSINESS'])) {
            return $this->userLevel;
        }

        // Check if subscription is active
        if ($this->hasActiveSubscription()) {
            return $this->userLevel; // PRO, TEAM, BUSINESS from subscription
        }

        // If explicitly set to ANONYMOUS (e.g., for email-based anonymous users), return that
        if ('ANONYMOUS' === $this->userLevel) {
            return 'ANONYMOUS';
        }

        // Default to NEW for all logged-in users
        // (Phone verification is only for WhatsApp/Email linking, not for web users)
        return 'NEW';
    }

    /**
     * Check if user has verified phone number.
     */
    public function hasVerifiedPhone(): bool
    {
        $details = $this->getUserDetails();

        return !empty($details['phone_number']) && !empty($details['phone_verified_at']);
    }

    /**
     * Get verified phone number.
     */
    public function getPhoneNumber(): ?string
    {
        $details = $this->getUserDetails();

        return $details['phone_number'] ?? null;
    }

    /**
     * Check if user is using external authentication (OAuth, OIDC)
     * External users cannot change their password locally.
     *
     * Note: BINTYPE is always 'WEB' for web-based logins
     * BPROVIDERID determines the actual authentication provider
     */
    public function isExternalAuth(): bool
    {
        // Check provider ID instead of type
        return 'local' !== $this->providerId && '' !== $this->providerId;
    }

    /**
     * Check if user can change password
     * Only local users with passwords can change their password.
     */
    public function canChangePassword(): bool
    {
        // Only local provider users with a password can change it
        return 'local' === $this->providerId && !empty($this->pw);
    }

    /**
     * Get authentication provider name for display
     * Based on BPROVIDERID, not BINTYPE.
     */
    public function getAuthProviderName(): string
    {
        return match ($this->providerId) {
            'google' => 'Google',
            'github' => 'GitHub',
            'keycloak' => 'Keycloak/OIDC',
            'local' => 'Email/Password',
            default => ucfirst($this->providerId) ?: 'Unknown',
        };
    }

    /**
     * Get user's preferred language/locale
     * Returns 'de', 'en', etc. for email translations.
     */
    public function getLocale(): string
    {
        $details = $this->getUserDetails();
        $language = $details['language'] ?? 'en';

        // Ensure we return a valid locale code
        return in_array($language, ['de', 'en', 'fr', 'es'], true) ? $language : 'en';
    }
}
