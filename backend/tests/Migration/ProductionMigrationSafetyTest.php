<?php

declare(strict_types=1);

namespace App\Tests\Migration;

use PHPUnit\Framework\TestCase;

final class ProductionMigrationSafetyTest extends TestCase
{
    public function testMigrationsNeverIntrospectTheDoctrineSchemaObject(): void
    {
        $migrationFiles = glob(dirname(__DIR__, 2).'/migrations/Version*.php') ?: [];

        self::assertNotEmpty($migrationFiles);

        foreach ($migrationFiles as $migrationFile) {
            $contents = file_get_contents($migrationFile);
            self::assertNotFalse($contents, sprintf('Unable to read migration %s', $migrationFile));

            $tokens = token_get_all($contents);
            $tokenCount = count($tokens);

            for ($index = 0; $index < $tokenCount; ++$index) {
                $token = $tokens[$index];
                if (!is_array($token) || T_VARIABLE !== $token[0] || '$schema' !== $token[1]) {
                    continue;
                }

                for ($nextIndex = $index + 1; $nextIndex < $tokenCount; ++$nextIndex) {
                    $nextToken = $tokens[$nextIndex];
                    if (is_array($nextToken) && in_array($nextToken[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                        continue;
                    }

                    self::assertNotSame(
                        T_OBJECT_OPERATOR,
                        is_array($nextToken) ? $nextToken[0] : null,
                        sprintf(
                            'Migration %s calls a method on Doctrine DBAL Schema; use idempotent SQL or information_schema instead.',
                            basename($migrationFile),
                        ),
                    );
                    break;
                }
            }
        }
    }
}
