<?php

declare(strict_types=1);

namespace App\Tests\Unit\Seed;

use App\Model\ModelCatalog;
use App\Seed\DefaultModelConfigSeeder;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;

/**
 * Ensures the production DEFAULTMODEL bindings resolve to real catalog entries.
 *
 * Without this guard, a typo in PROD_MODEL_DEFAULTS only surfaces at container
 * boot when DefaultModelConfigSeeder::seed() throws — well after a deploy is
 * already in flight.
 */
final class DefaultModelConfigSeederTest extends TestCase
{
    public function testEveryProdModelKeyResolvesToExactlyOneCatalogEntry(): void
    {
        $reflection = new \ReflectionClass(DefaultModelConfigSeeder::class);
        $defaults = $reflection->getReflectionConstant('PROD_MODEL_DEFAULTS');
        $this->assertNotFalse($defaults, 'PROD_MODEL_DEFAULTS constant missing');

        /** @var list<array{group: string, setting: string, modelKey: string}> $rows */
        $rows = $defaults->getValue();
        $this->assertNotEmpty($rows, 'Expected at least one PROD_MODEL_DEFAULTS row');

        foreach ($rows as $row) {
            $bid = ModelCatalog::findBidByKey($row['modelKey']);
            $this->assertNotNull(
                $bid,
                sprintf(
                    "DEFAULTMODEL.%s references modelKey '%s', but ModelCatalog::findBidByKey() did not return a unique match. Add or rename the catalog entry, or adjust the modelKey to include the tag suffix.",
                    $row['setting'],
                    $row['modelKey'],
                ),
            );
        }
    }

    public function testResolveProdDefaultsIsPrivateStaticContract(): void
    {
        // Documents the contract on resolveProdDefaults() — it must be a private
        // static helper called from seed(). If this test breaks, the seeder API
        // changed and the throwing-on-missing-key behaviour needs to be re-verified.
        $reflection = new \ReflectionClass(DefaultModelConfigSeeder::class);
        $resolver = $reflection->getMethod('resolveProdDefaults');

        $this->assertTrue($resolver->isPrivate());
        $this->assertTrue($resolver->isStatic());
    }

    public function testTestEnvSeedDoesNotTouchModelCatalogResolution(): void
    {
        // In the test environment the seeder must NOT call ModelCatalog at all —
        // it uses literal negative IDs from ModelSeeder::TEST_MODELS. We assert this
        // indirectly by ensuring the seeder runs without exceptions when only the
        // (mocked) Connection is provided.
        $connection = $this->createMock(Connection::class);
        // @phpstan-ignore-next-line
        $connection->method('executeStatement')->willReturn(0);

        $seeder = new DefaultModelConfigSeeder($connection, 'test');
        $result = $seeder->seed();

        $this->assertSame('default_model_config', $result->label);
    }
}
