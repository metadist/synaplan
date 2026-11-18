<?php

declare(strict_types=1);

namespace App\Tests\Unit\Doctrine;

use App\Doctrine\RetiredColumnsSchemaListener;
use App\Entity\McpServerConfig;
use App\Entity\User;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\BooleanType;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Tools\Event\GenerateSchemaTableEventArgs;
use PHPUnit\Framework\TestCase;

final class RetiredColumnsSchemaListenerTest extends TestCase
{
    public function testDeclaresTheRetiredMcpWriteSwitchColumns(): void
    {
        $schema = new Schema();
        $table = $schema->createTable('BMCPSERVERS');

        (new RetiredColumnsSchemaListener())->postGenerateSchemaTable(
            new GenerateSchemaTableEventArgs($this->metadata(McpServerConfig::class, 'BMCPSERVERS'), $schema, $table),
        );

        foreach (['BALLOWWRITE', 'BWRITEAUTO'] as $name) {
            self::assertTrue($table->hasColumn($name), $name);
            $column = $table->getColumn($name);
            self::assertInstanceOf(BooleanType::class, $column->getType());
            self::assertTrue($column->getNotnull());
            self::assertFalse($column->getDefault());
        }
    }

    public function testLeavesOtherTablesAlone(): void
    {
        $schema = new Schema();
        $table = $schema->createTable('BUSER');

        (new RetiredColumnsSchemaListener())->postGenerateSchemaTable(
            new GenerateSchemaTableEventArgs($this->metadata(User::class, 'BUSER'), $schema, $table),
        );

        self::assertSame([], $table->getColumns());
    }

    /**
     * @param class-string $class
     *
     * @return ClassMetadata<object>
     */
    private function metadata(string $class, string $tableName): ClassMetadata
    {
        $metadata = new ClassMetadata($class);
        $metadata->setPrimaryTable(['name' => $tableName]);

        return $metadata;
    }
}
