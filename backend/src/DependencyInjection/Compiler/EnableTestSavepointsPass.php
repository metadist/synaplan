<?php

declare(strict_types=1);

namespace App\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Enable nested-transaction savepoints on every Doctrine DBAL connection in the
 * `test` environment.
 *
 * Why this exists
 * ---------------
 * `dama/doctrine-test-bundle` is registered as a PHPUnit extension and wraps every
 * test in a nested transaction so changes are rolled back automatically between
 * tests. On MariaDB / MySQL that nesting requires SAVEPOINTs to be enabled —
 * otherwise the bundle aborts every {@see Symfony\Bundle\FrameworkBundle\Test\KernelTestCase}-based
 * test at boot with:
 *
 *     LogicException: This bundle relies on savepoints for nested database
 *     transactions. You need to enable "use_savepoints" on the Doctrine DBAL
 *     config for connection "default".
 *
 * Why not the YAML key
 * --------------------
 * `doctrine/doctrine-bundle` only exposes the `use_savepoints` config key when
 * paired with `doctrine/dbal` 4.x. We currently run on DBAL 3.x, where the same
 * runtime knob is reachable via {@see \Doctrine\DBAL\Connection::setNestTransactionsWithSavepoints()}
 * but the YAML schema rejects the key with "Unrecognized option ... Available
 * options are ...". Putting the call into a compiler pass keeps the test setup
 * working across both DBAL lines and is a no-op outside the `test` env.
 *
 * Why "every connection"
 * ----------------------
 * The bundle wraps every connection it sees, not just `default`. In this app
 * the `read` connection points at the same database via a separate connection
 * instance (see config/packages/doctrine.yaml), so it would hit the same boot
 * error. Iterating service IDs covers both without hard-coding names.
 */
final class EnableTestSavepointsPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        foreach ($container->getDefinitions() as $id => $definition) {
            // Match `doctrine.dbal.<name>_connection` services. The Connection
            // class is the one carrying setNestTransactionsWithSavepoints();
            // the wrapper-class hierarchy on the service definition is enough
            // to filter out unrelated services without resolving them.
            if (!\str_starts_with($id, 'doctrine.dbal.') || !\str_ends_with($id, '_connection')) {
                continue;
            }

            $definition->addMethodCall('setNestTransactionsWithSavepoints', [true]);
        }
    }
}
