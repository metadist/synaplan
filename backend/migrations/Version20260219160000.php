<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260219160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add BGROUPKEY column and index to BFILES table for folder-based file management';
    }

    public function up(Schema $schema): void
    {
        $table = $schema->getTable('BFILES');

        if (!$table->hasColumn('BGROUPKEY')) {
            $table->addColumn('BGROUPKEY', 'string', [
                'length' => 128,
                'notnull' => false,
                'default' => null,
            ]);
        }

        if (!$table->hasIndex('idx_file_groupkey')) {
            $table->addIndex(['BGROUPKEY'], 'idx_file_groupkey');
        }
    }

    public function down(Schema $schema): void
    {
        $table = $schema->getTable('BFILES');

        if ($table->hasIndex('idx_file_groupkey')) {
            $table->dropIndex('idx_file_groupkey');
        }

        if ($table->hasColumn('BGROUPKEY')) {
            $table->dropColumn('BGROUPKEY');
        }
    }
}
