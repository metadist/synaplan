<?php

declare(strict_types=1);

namespace App\AI\Catalog;

/**
 * How a provider is authenticated: which store holds the platform
 * credential, which environment variable bootstraps it, where a person
 * creates one, how it is validated, what that validation says about served
 * models, and whether a person may bring their own. A provider without any
 * credential has no spec (`key: null` on the descriptor).
 */
final readonly class ProviderKeySpec
{
    /**
     * @param ?string                $envVar         null for the endpoint registry, where every endpoint carries its own key
     * @param list<string>           $envAliases     further env names the store accepts; the first usable value wins
     * @param ?string                $secretEnvVar   the secret half of a key + secret pair
     * @param ?string                $consoleUrl     where a person creates the key
     * @param ?ProviderKeyValidation $validation     null = the key is stored untested
     * @param ModelListing           $modelListing   what the validation endpoint says about served models
     * @param bool                   $servesChat     false for media and speech keys: never the default chat provider
     * @param UserCredential         $userCredential whether and how a person may bring their own key
     */
    public function __construct(
        public ProviderKeyStoreKind $store,
        public ?string $envVar,
        public array $envAliases = [],
        public ?string $secretEnvVar = null,
        public ?string $consoleUrl = null,
        public ?ProviderKeyValidation $validation = null,
        public ModelListing $modelListing = ModelListing::None,
        public bool $servesChat = true,
        public UserCredential $userCredential = UserCredential::None,
        public ProviderKeyPresentation $presentation = new ProviderKeyPresentation(),
    ) {
    }

    /**
     * A key in the platform store (BCONFIG `provider_keys`).
     *
     * @param list<string> $envAliases
     */
    public static function platform(
        string $envVar,
        string $consoleUrl,
        ?ProviderKeyValidation $validation,
        int $listOrder,
        ModelListing $modelListing = ModelListing::None,
        bool $freeTier = false,
        bool $recommended = false,
        bool $servesChat = true,
        UserCredential $userCredential = UserCredential::None,
        array $envAliases = [],
        ?string $secretEnvVar = null,
        ?string $displayName = null,
        ?string $fieldDescription = null,
        ?string $secretFieldDescription = null,
    ): self {
        return new self(
            store: ProviderKeyStoreKind::Platform,
            envVar: $envVar,
            envAliases: $envAliases,
            secretEnvVar: $secretEnvVar,
            consoleUrl: $consoleUrl,
            validation: $validation,
            modelListing: $modelListing,
            servesChat: $servesChat,
            userCredential: $userCredential,
            presentation: new ProviderKeyPresentation($listOrder, $displayName, $freeTier, $recommended, $fieldDescription, $secretFieldDescription),
        );
    }

    /**
     * A platform key that a person may replace with their own on the
     * Messages gateway ({@see UserCredential::Gateway}).
     *
     * @param list<string> $envAliases
     */
    public static function platformAndUser(
        string $envVar,
        string $consoleUrl,
        ?ProviderKeyValidation $validation,
        int $listOrder,
        ModelListing $modelListing = ModelListing::None,
        bool $freeTier = false,
        bool $recommended = false,
        array $envAliases = [],
        ?string $displayName = null,
        ?string $fieldDescription = null,
    ): self {
        return self::platform(
            envVar: $envVar,
            consoleUrl: $consoleUrl,
            validation: $validation,
            listOrder: $listOrder,
            modelListing: $modelListing,
            freeTier: $freeTier,
            recommended: $recommended,
            userCredential: UserCredential::Gateway,
            envAliases: $envAliases,
            displayName: $displayName,
            fieldDescription: $fieldDescription,
        );
    }

    /** One credential per admin-registered endpoint; nothing in the environment. */
    public static function endpointRegistry(): self
    {
        return new self(store: ProviderKeyStoreKind::EndpointRegistry, envVar: null);
    }

    /** A credential read from the environment only, outside every store. */
    public static function envOnly(string $envVar, ?string $fieldDescription = null): self
    {
        return new self(
            store: ProviderKeyStoreKind::None,
            envVar: $envVar,
            presentation: new ProviderKeyPresentation(fieldDescription: $fieldDescription),
        );
    }

    public function requiresSecret(): bool
    {
        return null !== $this->secretEnvVar;
    }

    public function isTestable(): bool
    {
        return null !== $this->validation;
    }

    /**
     * Every env var this spec names, key first, then aliases, then the secret.
     *
     * @return list<string>
     */
    public function envVars(): array
    {
        $vars = null === $this->envVar ? [] : [$this->envVar];
        foreach ($this->envAliases as $alias) {
            $vars[] = $alias;
        }
        if (null !== $this->secretEnvVar) {
            $vars[] = $this->secretEnvVar;
        }

        return $vars;
    }
}
