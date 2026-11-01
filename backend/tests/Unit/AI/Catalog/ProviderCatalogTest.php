<?php

declare(strict_types=1);

namespace App\Tests\Unit\AI\Catalog;

use App\AI\Catalog\DescribesProviderInterface;
use App\AI\Catalog\ProviderBranding;
use App\AI\Catalog\ProviderCapability;
use App\AI\Catalog\ProviderCatalog;
use App\AI\Catalog\ProviderDescriptor;
use App\AI\Catalog\ProviderDialects;
use App\AI\Catalog\ProviderEndpoint;
use App\AI\Catalog\ProviderKeySpec;
use App\AI\Catalog\ProviderKeyValidation;
use App\AI\Catalog\ProviderPricing;
use App\AI\Catalog\UnknownProviderException;
use PHPUnit\Framework\TestCase;

final class ProviderCatalogTest extends TestCase
{
    public function testKeyedProvidersComeFirstInListOrderThenTheRestByName(): void
    {
        $catalog = new ProviderCatalog([ZetaLocalProvider::class, SecondKeyedProvider::class, AlphaLocalProvider::class, FirstKeyedProvider::class]);

        self::assertSame(['first', 'second', 'alpha', 'zeta'], $catalog->names());
        self::assertSame(['first', 'second'], array_map(static fn (ProviderDescriptor $d): string => $d->name, $catalog->withKey()));
        self::assertSame(['second'], array_map(static fn (ProviderDescriptor $d): string => $d->name, $catalog->withUserKey()));
    }

    public function testLookupIsCaseInsensitiveAndAnUnknownNameNamesTheKnownOnes(): void
    {
        $catalog = new ProviderCatalog([FirstKeyedProvider::class, AlphaLocalProvider::class]);

        self::assertSame('first', $catalog->get(' First ')->name);
        self::assertTrue($catalog->has('ALPHA'));
        self::assertNull($catalog->find('nobody'));

        $this->expectException(UnknownProviderException::class);
        $this->expectExceptionMessage('Unknown AI provider "nobody". Known: first, alpha.');
        $catalog->get('nobody');
    }

    public function testCapabilityFilterAndEndpointHostsIncludeValidationHosts(): void
    {
        $catalog = new ProviderCatalog([FirstKeyedProvider::class, SecondKeyedProvider::class, AlphaLocalProvider::class]);

        self::assertSame(['first', 'alpha'], array_map(static fn (ProviderDescriptor $d): string => $d->name, $catalog->withCapability(ProviderCapability::Chat)));
        self::assertSame(['api.first.example', 'auth.first.example', 'api.second.example'], $catalog->endpoints());
    }

    public function testAClassWithoutADescriptorFailsLoudly(): void
    {
        $catalog = new ProviderCatalog([\stdClass::class]);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('stdClass is registered as an AI provider but does not implement');
        $catalog->all();
    }

    public function testTwoClassesDescribingTheSameNameFailLoudly(): void
    {
        $catalog = new ProviderCatalog([FirstKeyedProvider::class, FirstKeyedTwinProvider::class]);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Two provider classes describe "first"');
        $catalog->all();
    }

    public function testADescriptorNameMustBeLowercase(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ProviderDescriptor(
            name: 'Mixed',
            displayName: 'Mixed',
            capabilities: [],
            endpoints: [],
            key: null,
            dialects: new ProviderDialects(),
            defaults: null,
            pricing: ProviderPricing::none(),
            liveVoiceSessions: null,
            branding: new ProviderBranding('mdi:robot'),
        );
    }
}

/** @internal */
abstract class FixtureProvider implements DescribesProviderInterface
{
    /**
     * @param list<ProviderCapability> $capabilities
     * @param list<ProviderEndpoint>   $endpoints
     */
    protected static function describe(string $name, array $capabilities, array $endpoints, ?ProviderKeySpec $key): ProviderDescriptor
    {
        return new ProviderDescriptor(
            name: $name,
            displayName: ucfirst($name),
            capabilities: $capabilities,
            endpoints: $endpoints,
            key: $key,
            dialects: new ProviderDialects(),
            defaults: null,
            pricing: ProviderPricing::none(),
            liveVoiceSessions: null,
            branding: new ProviderBranding('mdi:robot'),
        );
    }
}

/** @internal */
final class FirstKeyedProvider extends FixtureProvider
{
    public static function descriptor(): ProviderDescriptor
    {
        return self::describe('first', [ProviderCapability::Chat], [ProviderEndpoint::api('https://api.first.example/v1')], ProviderKeySpec::platform('FIRST_API_KEY', 'https://first.example', ProviderKeyValidation::bearer('https://auth.first.example/models'), listOrder: 1));
    }
}

/** @internal */
final class FirstKeyedTwinProvider extends FixtureProvider
{
    public static function descriptor(): ProviderDescriptor
    {
        return FirstKeyedProvider::descriptor();
    }
}

/** @internal */
final class SecondKeyedProvider extends FixtureProvider
{
    public static function descriptor(): ProviderDescriptor
    {
        return self::describe('second', [ProviderCapability::Vision], [ProviderEndpoint::api('https://api.second.example')], ProviderKeySpec::platformAndUser('SECOND_API_KEY', 'https://second.example', null, listOrder: 2));
    }
}

/** @internal */
final class AlphaLocalProvider extends FixtureProvider
{
    public static function descriptor(): ProviderDescriptor
    {
        return self::describe('alpha', [ProviderCapability::Chat], [ProviderEndpoint::localChatCompletions()], null);
    }
}

/** @internal */
final class ZetaLocalProvider extends FixtureProvider
{
    public static function descriptor(): ProviderDescriptor
    {
        return self::describe('zeta', [], [], null);
    }
}
