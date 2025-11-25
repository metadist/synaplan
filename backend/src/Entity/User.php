<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use App\Repository\UserRepository;
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

    #[ORM\Column(name: 'BINTYPE', length: 4, options: ['default' => 'WEB'])]
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
    // BUSERLEVEL: NEW, PRO, TEAM, BUSINESS
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
        // Map user level to roles
        $roles = ['ROLE_USER'];
        
        if (in_array($this->userLevel, ['PRO', 'TEAM', 'BUSINESS'])) {
            $roles[] = 'ROLE_PRO';
        }
        
        if ($this->userLevel === 'BUSINESS') {
            $roles[] = 'ROLE_BUSINESS';
        }

        return array_unique($roles);
    }

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
            && $sub['status'] === 'active' 
            && isset($sub['ends']) 
            && $sub['ends'] > time();
    }

    public function getSubscriptionEnds(): ?int
    {
        return $this->getSubscriptionData()['ends'] ?? null;
    }

    public function getStripeCustomerId(): ?string
    {
        return $this->getSubscriptionData()['stripe_customer_id'] ?? null;
    }

    /**
     * Get effective rate limiting level
     * 
     * Logic:
     * - ANONYMOUS: Only for non-logged-in users (widget, unlinked WhatsApp/Email)
     * - NEW: Default for all logged-in users without active subscription
     * - PRO/TEAM/BUSINESS: Users with active paid subscription
     * 
     * Note: Phone verification is NOT required for logged-in web users.
     *       Phone verification only affects WhatsApp/Email channel linking.
     */
    public function getRateLimitLevel(): string
    {
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
        if ($this->userLevel === 'ANONYMOUS') {
            return 'ANONYMOUS';
        }
        
        // Default to NEW for all logged-in users
        // (Phone verification is only for WhatsApp/Email linking, not for web users)
        return 'NEW';
    }

    /**
     * Check if user has verified phone number
     */
    public function hasVerifiedPhone(): bool
    {
        $details = $this->getUserDetails();
        return !empty($details['phone_number']) && !empty($details['phone_verified_at']);
    }

    /**
     * Get verified phone number
     */
    public function getPhoneNumber(): ?string
    {
        $details = $this->getUserDetails();
        return $details['phone_number'] ?? null;
    }

    /**
     * Check if user is using external authentication (OAuth, OIDC)
     * External users cannot change their password locally
     */
    public function isExternalAuth(): bool
    {
        return in_array($this->type, ['GOOGLE', 'GITHUB', 'OIDC', 'MAIL'], true);
    }

    /**
     * Check if user can change password
     * Only local/WEB users with passwords can change their password
     */
    public function canChangePassword(): bool
    {
        // Only WEB users with a local password can change it
        return $this->type === 'WEB' && !empty($this->pw);
    }

    /**
     * Get authentication provider name for display
     */
    public function getAuthProviderName(): string
    {
        return match($this->type) {
            'GOOGLE' => 'Google',
            'GITHUB' => 'GitHub',
            'OIDC' => 'Keycloak/OIDC',
            'MAIL' => 'Email',
            'WEB' => 'Email/Password',
            default => 'Unknown'
        };
    }

    /**
     * Get user's preferred language/locale
     * Returns 'de', 'en', etc. for email translations
     */
    public function getLocale(): string
    {
        $details = $this->getUserDetails();
        $language = $details['language'] ?? 'en';
        
        // Ensure we return a valid locale code
        return in_array($language, ['de', 'en', 'fr', 'es'], true) ? $language : 'en';
    }
}

