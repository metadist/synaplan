<?php

declare(strict_types=1);

namespace App\Doctrine;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Tools\Event\GenerateSchemaTableEventArgs;
use Doctrine\ORM\Tools\ToolEvents;

/**
 * Declares columns that the entities no longer map but the database still
 * holds, so `doctrine:schema:validate` stays in sync while they wait for
 * their DROP.
 *
 * A column the previous release still maps cannot be dropped in the release
 * that unmaps it: during a rolling deploy the old nodes still SELECT it. So
 * the unmapping release lists the column here (the ORM stops selecting it,
 * and its NOT NULL DEFAULT keeps INSERTs from the new code working), and the
 * next release drops the column and its entry together. See
 * docs/MIGRATIONS.md, "Dropping a column".
 */
#[AsDoctrineListener(event: ToolEvents::postGenerateSchemaTable)]
final class RetiredColumnsSchemaListener
{
    /**
     * Table name => column name => [DBAL type, column options].
     *
     * @var array<string, array<string, array{string, array<string, mixed>}>>
     */
    private const RETIRED_COLUMNS = [
        // Per-connection MCP write switches, replaced by BTOOLPOLICY.
        // Unmapped after release 5.8.8; drop both in the following release.
        'BMCPSERVERS' => [
            'BALLOWWRITE' => [Types::BOOLEAN, ['default' => false]],
            'BWRITEAUTO' => [Types::BOOLEAN, ['default' => false]],
        ],
    ];

    public function postGenerateSchemaTable(GenerateSchemaTableEventArgs $args): void
    {
        $table = $args->getClassTable();
        $columns = self::RETIRED_COLUMNS[$args->getClassMetadata()->getTableName()] ?? [];

        foreach ($columns as $name => [$type, $options]) {
            if (!$table->hasColumn($name)) {
                $table->addColumn($name, $type, $options);
            }
        }
    }
}
