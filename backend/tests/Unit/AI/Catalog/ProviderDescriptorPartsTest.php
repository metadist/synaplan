<?php

declare(strict_types=1);

namespace App\Tests\Unit\AI\Catalog;

use App\AI\Catalog\EndpointPurpose;
use App\AI\Catalog\ModelListing;
use App\AI\Catalog\ProviderEndpoint;
use App\AI\Catalog\ProviderKeySpec;
use App\AI\Catalog\ProviderKeyStoreKind;
use App\AI\Catalog\ProviderKeyValidation;
use App\AI\Catalog\ProviderPricing;
use App\AI\Catalog\UserCredential;
use PHPUnit\Framework\TestCase;

final class ProviderDescriptorPartsTest extends TestCase
{
    public function testEndpointsKnowTheirHostAndALocalEndpointHasNone(): void
    {
        $fixed = ProviderEndpoint::chatCompletions('https://API.Example.com/v1/chat/completions');
        self::assertSame(EndpointPurpose::ChatCompletions, $fixed->purpose);
        self::assertSame('api.example.com', $fixed->host());
        self::assertFalse($fixed->isLocal());

        $wildcard = ProviderEndpoint::api('https://*.aiplatform.googleapis.com/v1');
        self::assertSame('*.aiplatform.googleapis.com', $wildcard->host());

        $local = ProviderEndpoint::localChatCompletions();
        self::assertTrue($local->isLocal());
        self::assertNull($local->host());
    }

    public function testKeySpecsNameEveryEnvVarAndTheirStoreKind(): void
    {
        $pair = ProviderKeySpec::platform('X_API_KEY', 'https://x.example', ProviderKeyValidation::header('https://x.example/', 'Authorization', 'Key {key}:{secret}'), listOrder: 3, envAliases: ['X_KEY'], secretEnvVar: 'X_API_SECRET');
        self::assertSame(['X_API_KEY', 'X_KEY', 'X_API_SECRET'], $pair->envVars());
        self::assertTrue($pair->requiresSecret());
        self::assertTrue($pair->isTestable());
        self::assertSame(ProviderKeyStoreKind::Platform, $pair->store);

        $untested = ProviderKeySpec::platform('Y_API_KEY', 'https://y.example', null, listOrder: 4);
        self::assertFalse($untested->isTestable());
        self::assertFalse($untested->requiresSecret());

        $byok = ProviderKeySpec::platformAndUser('Z_API_KEY', 'https://z.example', null, listOrder: 5);
        self::assertSame(ProviderKeyStoreKind::Platform, $byok->store);
        self::assertSame(UserCredential::Gateway, $byok->userCredential);
        self::assertSame(UserCredential::None, $pair->userCredential);
        self::assertSame([], ProviderKeySpec::endpointRegistry()->envVars());
        self::assertSame(ProviderKeyStoreKind::None, ProviderKeySpec::envOnly('TOKEN', 'A token')->store);
        self::assertSame('A token', ProviderKeySpec::envOnly('TOKEN', 'A token')->presentation->fieldDescription);
        self::assertSame(ModelListing::None, $pair->modelListing);
        self::assertSame(3, $pair->presentation->listOrder);
    }

    public function testValidationShapesAndPricingPrefixes(): void
    {
        self::assertSame(['method' => 'GET', 'url' => 'https://a.example/models', 'headers' => ['Authorization' => 'Bearer {key}']], ProviderKeyValidation::bearer('https://a.example/models')->toArray());
        self::assertSame('a.example', ProviderKeyValidation::bearer('https://a.example/models')->host());
        self::assertSame(['x-api-key' => '{key}', 'anthropic-version' => '1'], ProviderKeyValidation::header('https://a.example', 'x-api-key', '{key}', ['anthropic-version' => '1'])->headers);

        $google = ProviderPricing::litellm(['gemini', 'google'], ['gemini', 'google', 'vertex_ai']);
        self::assertSame(['gemini', 'google'], $google->prefixesFor('google'));
        self::assertSame(['gemini', 'google', 'vertex_ai'], $google->bareMatchVendors);
        self::assertSame([], ProviderPricing::litellm(['groq'])->bareMatchVendors);
        self::assertSame(['xai'], ProviderPricing::byServiceName()->prefixesFor('xAI'));
        self::assertFalse(ProviderPricing::none()->synced);
    }
}
